<?php

declare(strict_types=1);

namespace S360\Viacash\Service;

use Barzahlen\Client;
use Barzahlen\Exception\ApiException;
use Barzahlen\Request\CreateRequest;
use Barzahlen\Request\InvalidateRequest;
use Barzahlen\Request\Request;
use Barzahlen\Request\ResendRequest;
use Barzahlen\Webhook;
use Composer\Repository\RepositoryInterface;
use DateTime;
use Doctrine\DBAL\Connection;
use Exception;
use Monolog\Logger;
use RuntimeException;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Framework\Routing\Router;

/**
 *
 * Viacash Client, the main entry point to interact with Viacash.
 * It builds and executes queries, and handles responses.
 * Class ViacashClient
 * @package S360\Viacash\Service
 */
class ViacashClient
{
    /**
     * @var SystemConfigService $systemConfigService
     */
    protected $systemConfigService;
    /**
     * @var Connection
     */
    protected $connection;
    /**
     * @var RepositoryInterface
     */
    protected $languageRepository;
    /**
     * @var EntityRepository
     */
    protected $orderRepository;
    /**
     * @var StateMachineRegistry
     */
    protected $stateMachineRegistry;
    /**
     * @var OrderTransactionStateHandler
     */
    protected $transactionStateHandler;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var Router
     */
    protected $router;

    public const ACTION_TO_TRANSITION_METHOD_MAPPING = [
        StateMachineTransitionActions::ACTION_PAID => StateMachineTransitionActions::ACTION_PAID,
        StateMachineTransitionActions::ACTION_CANCEL => StateMachineTransitionActions::ACTION_CANCEL,
        StateMachineTransitionActions::ACTION_REFUND => StateMachineTransitionActions::ACTION_REFUND,
        StateMachineTransitionActions::ACTION_REFUND_PARTIALLY => 'refundPartially'
    ];
    public const ACTION_TO_STATUS_MAPPING = [
        StateMachineTransitionActions::ACTION_PAID => OrderTransactionStates::STATE_PAID,
        StateMachineTransitionActions::ACTION_CANCEL => OrderTransactionStates::STATE_CANCELLED,
        StateMachineTransitionActions::ACTION_REFUND => OrderTransactionStates::STATE_REFUNDED,
        StateMachineTransitionActions::ACTION_REFUND_PARTIALLY => OrderTransactionStates::STATE_PARTIALLY_REFUNDED
    ];


    /**
     * ViacashClient constructor.
     * @param SystemConfigService $systemConfigService
     * @param Connection $connection
     * @param EntityRepository $languageRepository
     * @param EntityRepository $orderRepository
     * @param StateMachineRegistry $stateMachineRegistry
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param Logger $logger ,
     * @param Router $router
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        Connection $connection,
        EntityRepository $languageRepository,
        EntityRepository $orderRepository,
        StateMachineRegistry $stateMachineRegistry,
        OrderTransactionStateHandler $transactionStateHandler,
        Logger $logger,
        Router $router
    ) {
        require_once __DIR__ . "/../../vendor/autoload.php";

        $this->systemConfigService = $systemConfigService;
        $this->connection = $connection;
        $this->languageRepository = $languageRepository;
        $this->orderRepository = $orderRepository;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->logger = $logger;
        $this->router = $router;
    }

    /**
     * @param string $slipId
     * @param string $currencyCode
     * @param float $refundAmount Provided +/- sign ignored and enforced to be correct.
     * @return CreateRequest
     */
    protected function getCreateRefundslipRequest(
        string $slipId,
        string $currencyCode,
        float $refundAmount
    ): CreateRequest {
        $refundAmount = -1 * abs($refundAmount);

        $request = new CreateRequest();
        $request->setSlipType('refund');
        $request->setForSlipId($slipId);
        $request->setTransaction(
            number_format($refundAmount, 2, ".", ""),
            $currencyCode
        );

        return $request;
    }


    /**
     * Creates the Request needed to create a new payslip / viacash-transaction.
     * Called from within ViacashPaymentHandler during checkout.
     * The return value is to be passed to executeRequest()
     *
     * @param CustomerEntity $customer
     * @param CustomerAddressEntity $address
     * @param OrderEntity $order
     * @param SalesChannelContext $salesChannelContext
     * @return CreateRequest
     */
    public function getCreatePayslipRequest(
        CustomerEntity $customer,
        CustomerAddressEntity $address,
        OrderEntity $order,
        SalesChannelContext $salesChannelContext
    ): CreateRequest {
        $request = new CreateRequest();
        $request->setSlipType('payment');
        $request->setTransaction(
            number_format($order->getAmountTotal(), 2, ".", ""),
            $salesChannelContext->getCurrency()->getIsoCode()
        );

        $request->setCustomerKey($customer->getEmail());
        $request->setCustomerCellPhone($address->getPhoneNumber());
        $request->setCustomerEmail($customer->getEmail());
        $customerLocale = strtolower($this->getLanguageStringByLanguageId(
            $salesChannelContext->getCustomer()->getLanguageId()
        ));
        $customerLocale .= '-' . strtoupper($salesChannelContext->getCustomer()->getActiveBillingAddress()->getCountry()->getIso());

        if (in_array($customerLocale,
            [
                "de-DE",
                "de-CH",
                "el-GR",
                "en-CH",
                "es-ES",
                "fr-FR",
                "it-IT"
            ])) {
            $request->setCustomerLanguage($customerLocale);
        }

        $url = $this->router->generate("frontend.viacash.hook", [], $this->router::ABSOLUTE_URL);
        $url = str_replace('http://', 'https://', $url);
        $request->setHookUrl($url);

        // For local testing:
        // $request->setHookUrl('https://1f367e87e13b.ngrok.io/viacash/hook');

        if ($this->systemConfigService->get("ZerintBarzahlenViacashShopware6.config.ViacashSendCustomerAddress")) {
            $request->setAddress(array(
                'street_and_no' => $address->getStreet(),
                'zipcode' => $address->getZipcode(),
                'city' => $address->getCity(),
                'country' => $address->getCountry()->getIso()
            ));
        }

        $days = (int)$this->systemConfigService->get("ZerintBarzahlenViacashShopware6.config.ViacashPaymentExpiresInDays");
        $days = max(min($days, 21), 0);
        $expire = new DateTime();
        $expire->modify("+{$days} day");
        $request->setExpiresAt($expire);
        return $request;
    }

    /**
     * Executes a request (e.g. created by getCreatePayslipRequest() )
     *
     * Throws the specific Exception as provided by the BarzahlenAPI
     * ( i.e.
     * @param string $viacashDivisionIdentifier Either a country code (DE, AT...) or the numeric division ID as used in module settings
     * @param Request $request
     * @param int $iRetries
     * @return array
     * @throws \Barzahlen\Exception\AuthException
     * @throws \Barzahlen\Exception\CurlException
     * @throws \Barzahlen\Exception\IdempotencyException
     * @throws \Barzahlen\Exception\InvalidFormatException
     * @throws \Barzahlen\Exception\InvalidParameterException
     * @throws \Barzahlen\Exception\InvalidStateException
     * @throws \Barzahlen\Exception\NotAllowedException
     * @throws \Barzahlen\Exception\RateLimitException
     * @throws \Barzahlen\Exception\ServerException
     * @throws \Barzahlen\Exception\TransportException
     * ) or a generic
     * @throws \Barzahlen\Exception\ApiException
     * if anything goes wrong.
     *
     * Otherwise, it returns an assoc. array with the response body.     *
     *
     */
    public function executeRequest(string $viacashDivisionIdentifier, Request $request, $iRetries = 1): array
    {
        $client = $this->getClient($viacashDivisionIdentifier);

        try {
            $this->logging($request->getMethod() . ' (Division ' . $viacashDivisionIdentifier . '):' . $request->getPath());
            if ($request->getBody()) {
                $this->logging($request->getBody(), Logger::DEBUG);
            }

            // Handle response. Get string-encoded json or an exception
            $response = $client->handle($request);

            $this->logging('RESPONSE: ' . $response, Logger::DEBUG);

            return json_decode($response, true);
        } catch (ApiException $e) {
            if ($iRetries) {
                // If we have retries left, go again.
                sleep(1);
                $this->logging('Retrying last request');
                return $this->executeRequest($viacashDivisionIdentifier, $request, --$iRetries);
            }

            $this->logging($e->getMessage() . ' ' . $e->getTraceAsString(), Logger::ERROR);
            throw $e;
        }
    }

    /**
     * Processes a webhook callback:
     * 1. Verifies the signature of the callback
     * 2. calls appropriate resolution, e.g. marks order as paid.
     *
     * If ANYTHING goes wrong, it logs and returns false.
     * That "false" shall generate a HTTP >= 400 response status
     * Returns boolean $success
     *
     * Called from within ViacashHookController
     *
     * @param Context $context
     * @return bool
     *
     */
    public function processHook(Context $context): bool
    {
        try {
            // Retrieve Hook data

            $header = $_SERVER;

            $body = file_get_contents('php://input');
            $body = str_replace(["\r", '\r'], "", $body);
            $message = "Webhook\n" . json_encode($header, JSON_PRETTY_PRINT);
            $this->logging($message);
            $this->logging($body, Logger::DEBUG);

            $oBody = \json_decode($body, false);

            if (JSON_ERROR_NONE !== json_last_error()) {
                throw new RuntimeException('json_decode error: ' . \json_last_error_msg());
            }

            // Retrieve effected order data

            $sql = "select `id` as `id`, `order_number`, `custom_fields` from `order` where `custom_fields` like ?";
            $effectedOrder = (object)$this->connection->fetchAssoc($sql, ["%{$oBody->slip->id}%"]);
            $effectedOrder->id = Uuid::fromBytesToHex($effectedOrder->id);
            $effectedOrder->custom_fields = \GuzzleHttp\json_decode($effectedOrder->custom_fields);
            $this->logging('effected order: ' . $effectedOrder->order_number, Logger::DEBUG);

            /*
             * Let's talk about security.
             * To verify callback integrity, we need to know the ApiKey, so we need to know the used Division.
             * 1. Since we do not trust the callback at this point, we only utilize the slip_id.
             * 2. With the slip_id, we find the order.
             * 3. With the order, we find the division id.
             * 4. With the division id, we find the Api Key in the system settings
             */

            // Verify callback

            $apiKey = $this->systemConfigService->get(
                "ZerintBarzahlenViacashShopware6.config.ViacashDivision" . $effectedOrder->custom_fields->custom_viacash_division_id . "ApiKey"
            );

            $webhook1 = new Webhook($apiKey);

            if ($webhook1->verify($header, $body)) {
                $this->logging('Webhook authenticated.', Logger::DEBUG);

                // Determine what to do with that order

                if ('paid' === $oBody->event) {
                    $this->markAsPaid($effectedOrder->id, (float)$oBody->slip->transactions[0]->amount, $context);
                    return true;
                }
                if ('canceled' === $oBody->event || 'cancelled' === $oBody->event) {
                    $this->setOrderStatus(StateMachineTransitionActions::ACTION_CANCEL, $effectedOrder->id, $context);
                    return true;
                }
                if ('expired' === $oBody->event) {
                    $this->setOrderStatus(StateMachineTransitionActions::ACTION_CANCEL, $effectedOrder->id, $context);
                    return true;
                }

                $this->logging("WEBHOOK UNKNOWN CALL EVENT {$oBody->event}", Logger::ERROR);
                return false;
            }

            $this->logging("WEBHOOK FAILED VERIFICATION", Logger::ERROR);
            return false;
        } catch (Exception $e) {
            $this->logging("WEBHOOK EXCEPTION" . $e, Logger::ERROR);
            return false;
        }
    }

    /**
     * @param string $sOrderId
     * @param float $fAmount
     * @param Context|null $context
     *
     * @throws ApiException
     * @throws Exception
     */
    protected function markAsPaid(string $sOrderId, float $fAmount, ?Context $context = null): void
    {
        $success = $this->setOrderStatus(StateMachineTransitionActions::ACTION_PAID, $sOrderId, $context);
        if (!$success) {
            throw new RuntimeException('Cannot set order status');
        }

        $this->changeRemainingRefundableAmount($sOrderId, $fAmount, $context);
    }

    /**
     * @param string $country
     * @return bool
     * @throws ApiException
     */
    public function isCountrySandboxed(string $country): bool
    {
        $divisionIndex = $this->getDivisionIndexByCountry($country);
        return (bool)$this->systemConfigService->get("ZerintBarzahlenViacashShopware6.config.ViacashDivision{$divisionIndex}IsSandbox");
    }

    /**
     * @return int
     */
    public function getValidityInDays(): int
    {
        return (int)$this->systemConfigService->get("ZerintBarzahlenViacashShopware6.config.ViacashPaymentExpiresInDays");
    }

    /////////////////////////////////////

    /**
     * @param string $viacashDivisionIdentifier Either a country code (DE, AT...) or the numeric division ID as used in module settings
     * @return Client
     * @throws ApiException
     */
    protected function getClient($viacashDivisionIdentifier): Client
    {
        if (is_numeric($viacashDivisionIdentifier)) {
            $divisionIndex = $viacashDivisionIdentifier;
        } else {
            $divisionIndex = $this->getDivisionIndexByCountry($viacashDivisionIdentifier);
        }

        $client = new Client(
            $this->systemConfigService->get("ZerintBarzahlenViacashShopware6.config.ViacashDivision{$divisionIndex}Id"),
            $this->systemConfigService->get("ZerintBarzahlenViacashShopware6.config.ViacashDivision{$divisionIndex}ApiKey"),
            $this->systemConfigService->get("ZerintBarzahlenViacashShopware6.config.ViacashDivision{$divisionIndex}IsSandbox")
        );

        $client->setUserAgent('Shopware 6');

        return $client;
    }

    /**
     * Get locale string, e.g. de-DE or en-GB
     *
     * @param string $languageId
     * @return string|null
     */
    protected function getLanguageStringByLanguageId(string $languageId): ?string
    {
        $languages = $this->languageRepository->search(
            (new Criteria([$languageId]))->addAssociation('locale'),
            Context::createDefaultContext()
        );

        /**
         * @var LanguageEntity $language
         */
        $language = $languages->first();

        $locale = $language->getLocale();

        return substr($locale->getCode(),0,2);
    }

    /**
     * @param $message
     * @param int $level Use a constant of Monolog/Logger
     */
    public function logging($message, $level = Logger::INFO): void
    {
        if (Logger::DEBUG === $level && !$this->systemConfigService->get("ZerintBarzahlenViacashShopware6.config.ViacashLogFullBodies")) {
            return;
        }

        if (!is_string($message)) {
            $message = json_encode($message);
        }

        $this->logger->addRecord($level, $message);
    }

    /**
     * @var array Caches the mapping between country code and index, e.g. 'DE' => '1'
     */
    protected $countryCodeCache = [];

    /**
     * @param string $country
     * @return int
     * @throws ApiException
     */
    public function getDivisionIndexByCountry(string $country): int
    {
        $country = strtolower($country);

        if (isset($this->countryCodeCache[$country])) {
            return $this->countryCodeCache[$country];
        }

        $divisionIndex = 0;

        for ($i = 1; $i <= 6; $i++) {
            $allValidCountries = $this->systemConfigService->get("ZerintBarzahlenViacashShopware6.config.ViacashDivision{$i}Countries");
            if ($allValidCountries) {
                foreach (explode(',', $allValidCountries) as $validCountry) {
                    if ($country === strtolower($validCountry)) {
                        $divisionIndex = $i;
                        break 2;
                    }
                }
            }
        }

        if (!$divisionIndex) {
            throw new ApiException("Country $country has no associated division");
        }

        $this->countryCodeCache[$country] = $divisionIndex;
        return $this->countryCodeCache[$country];
    }

    /**
     * Sets an order status to paid, refunded, refunded partially or cancelled.
     * Will return success withouth changing the status, if the correct status is already present.
     * Handy for multiple partial refunds (as no transition is possible from paid_partially to paid_partially),
     * or if multiple cancels arrive.
     *
     * @param string $action paid[sic! Should be "pay".], cancel, refund
     * @param string $sOrderId
     * @param Context $context
     * @param string $sOrderVersionId
     * @return bool $success
     * @throws ApiException
     */
    public function setOrderStatus(string $action, string $sOrderId, Context $context, string $sOrderVersionId = null): bool
    {
        if (!isset(self::ACTION_TO_TRANSITION_METHOD_MAPPING[$action])) {
            throw new ApiException("Unknown new payment status $action");
        }

        $transitionMethod = self::ACTION_TO_TRANSITION_METHOD_MAPPING[$action];

        $order = $this->getOrder($sOrderId, $sOrderVersionId);

        if (!$order || !$order->getId()) {
            throw new ApiException('Unknown order id ' . $sOrderId);
        }

        /** @var OrderTransactionEntity $transaction */
        $transactions = $order->getTransactions();

        $success = false;

        // Succeed if at least one transaction status can be set

        foreach ($transactions as $transaction) {
            try {
                if (self::ACTION_TO_STATUS_MAPPING[$action] !== $transactions->first()->getStateMachineState()->getTechnicalName()) {
                    $this->transactionStateHandler->$transitionMethod($transaction->getId(), $context ?: Context::createDefaultContext());
                }

                $success = true;
            } catch (Exception $e) {
            }
        }

        $this->logging(($success ? 'Success' : 'Failed') . " marking order {$order->getId()} with transitionMethod $action.", ($success ? Logger::INFO : Logger::ERROR));

        return $success;
    }

    /**
     * @param float $fRefundAmount
     * @param string $sOrderId
     * @param Context $context
     * @param string|null $sOrderVersionId
     * @return bool
     * @throws ApiException
     * @throws \Barzahlen\Exception\AuthException
     * @throws \Barzahlen\Exception\CurlException
     * @throws \Barzahlen\Exception\IdempotencyException
     * @throws \Barzahlen\Exception\InvalidFormatException
     * @throws \Barzahlen\Exception\InvalidParameterException
     * @throws \Barzahlen\Exception\InvalidStateException
     * @throws \Barzahlen\Exception\NotAllowedException
     * @throws \Barzahlen\Exception\RateLimitException
     * @throws \Barzahlen\Exception\ServerException
     * @throws \Barzahlen\Exception\TransportException
     */
    public function refund(float $fRefundAmount, string $sOrderId, Context $context, string $sOrderVersionId = null): bool
    {
        // Save reduced remaining refundable amount

        $remainingRefundableAmount = $this->changeRemainingRefundableAmount($sOrderId, -1 * abs($fRefundAmount), $context);
        $newStatus = $remainingRefundableAmount > 0 ? StateMachineTransitionActions::ACTION_REFUND_PARTIALLY : StateMachineTransitionActions::ACTION_REFUND;

        // Set status locally

        $success = $this->setOrderStatus(
            $newStatus,
            $sOrderId,
            $context,
            $sOrderVersionId
        );

        if (!$success) {
            $this->logging('Cannot internally mark order as refundended: ' . $sOrderId, Logger::ERROR);
            return false;
        }


        // Set status remotely

        $order = $this->getOrder($sOrderId, $sOrderVersionId, $context);
        $slipId = $order->getCustomFields()['custom_viacash_slip_id'];
        $refundRequest = $this->getCreateRefundslipRequest($slipId, $order->getCurrency()->getIsoCode(), $fRefundAmount);
        $this->executeRequest($order->getCustomFields()['custom_viacash_division_id'], $refundRequest);

        // executeRequest() didn't throw an exception: success.

        return true;
    }

    /**
     * @param string $sOrderId
     * @param string|null $sOrderVersionId
     * @param Context $context
     * @return OrderEntity
     * @throws Exception
     */
    protected function getOrder(string $sOrderId, ?string $sOrderVersionId = null, ?Context $context = null): OrderEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $sOrderId));
        if ($sOrderVersionId) {
            $criteria->addFilter(new EqualsFilter('versionId', $sOrderVersionId));
        }
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('currency');

        /**
         * @var $order OrderEntity
         */
        $orders = $this->orderRepository->search($criteria, $context ?: Context::createDefaultContext());

        if (!$orders || !$orders->first()) {
            throw new RuntimeException("Order $sOrderId not found.");
        }

        return $orders->first();
    }

    /**
     * @param string $sOrderId
     * @param float $fAmount POSITIVE to add, NEGATIVE to reduce
     * @param Context|null $context
     * @param string|null $sOrderVersionId
     *
     * @return float Remaining refundable amount
     *
     * @throws Exception
     */
    protected function changeRemainingRefundableAmount(
        string $sOrderId,
        float $fAmount,
        ?Context $context = null,
        ?string $sOrderVersionId = null
    ): float {
        $order = $this->getOrder($sOrderId, $sOrderVersionId, $context);
        $customFields = $order->getCustomFields();

        if (!isset($customFields['custom_viacash_refundable_amount'])) {
            throw new RuntimeException('No refundable_amount set.');
        }

        $remainingRefundableAmount = $customFields['custom_viacash_refundable_amount'] + $fAmount;

        if ($remainingRefundableAmount < 0) {
            throw new RuntimeException("Remaining refundable amount must not be negative - this would result in an over-refund.");
        }

        $customFields['custom_viacash_refundable_amount'] = $remainingRefundableAmount;

        $this->orderRepository->update([
            [
                'id' => $order->getId(),
                'customFields' => $customFields
            ]
        ],
            Context::createDefaultContext());

        return $remainingRefundableAmount;
    }

    /**
     * Invalidates Payslip for an order
     *
     * @param OrderEntity $order
     * @throws ApiException
     * @throws \Barzahlen\Exception\AuthException
     * @throws \Barzahlen\Exception\CurlException
     * @throws \Barzahlen\Exception\IdempotencyException
     * @throws \Barzahlen\Exception\InvalidFormatException
     * @throws \Barzahlen\Exception\InvalidParameterException
     * @throws \Barzahlen\Exception\InvalidStateException
     * @throws \Barzahlen\Exception\NotAllowedException
     * @throws \Barzahlen\Exception\RateLimitException
     * @throws \Barzahlen\Exception\ServerException
     * @throws \Barzahlen\Exception\TransportException
     */
    public function invalidatePayslipForOrder(OrderEntity $order): void
    {
        if (!isset($order->getCustomFields()['custom_viacash_slip_id'])) {
            // Not a viacash payment
            return;
        }

        $request = new InvalidateRequest($order->getCustomFields()['custom_viacash_slip_id']);
        $divisionCountryCode = $order->getCustomFields()['custom_viacash_division_id'];
        $this->executeRequest($divisionCountryCode, $request);
    }

    /**
     *
     * Resends payment slip
     *
     * @param $sOrderId
     * @param $sOrderVersionId
     * @return bool
     * @throws ApiException
     * @throws \Barzahlen\Exception\AuthException
     * @throws \Barzahlen\Exception\CurlException
     * @throws \Barzahlen\Exception\IdempotencyException
     * @throws \Barzahlen\Exception\InvalidFormatException
     * @throws \Barzahlen\Exception\InvalidParameterException
     * @throws \Barzahlen\Exception\InvalidStateException
     * @throws \Barzahlen\Exception\NotAllowedException
     * @throws \Barzahlen\Exception\RateLimitException
     * @throws \Barzahlen\Exception\ServerException
     * @throws \Barzahlen\Exception\TransportException
     */
    public function resend($sOrderId, $sOrderVersionId): bool
    {
        // Set status remotely

        $order = $this->getOrder($sOrderId, $sOrderVersionId, Context::createDefaultContext());
        $sSlipId = $order->getCustomFields()['custom_viacash_slip_id'];
        $request = new ResendRequest($sSlipId, 'email');
        $this->executeRequest($order->getCustomFields()['custom_viacash_division_id'], $request);

        // executeRequest() didn't throw an exception: success.

        return true;
    }

    /**
     * @param $divisionId
     * @return bool
     */
    public function ping($divisionId): bool
    {
        // Set status remotely

        try {
            $request = new \Barzahlen\Request\PingRequest();
            $this->executeRequest((string)$divisionId, $request, 0);
        } catch (\Exception $e) {
            return false;
        }

        // executeRequest() didn't throw an exception: success.

        return true;
    }

}
