<?php

declare(strict_types=1);

namespace S360\Viacash\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\SynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\SyncPaymentProcessException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Class ViacashPaymentHandler
 * @package S360\Viacash\Service
 */
class ViacashPaymentHandler implements SynchronousPaymentHandlerInterface
{
    /**
     * @var ViacashClient
     */
    protected $client;
    /**
     * @var Session
     */
    protected $session;
    /**
     * @var EntityRepository
     */
    protected $orderRepository;

    /**
     * @var OrderTransactionStateHandler
     */
    private $transactionStateHandler;

    public function __construct(OrderTransactionStateHandler $transactionStateHandler, ViacashClient $client, Session $session, EntityRepository $orderRepository)
    {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->client = $client;
        $this->session = $session;
        $this->orderRepository = $orderRepository;
    }

    /**
     * Attempt to get a payslip from Viacash.
     * On ANY exception it will throw SyncPaymentProcessException and alert the customer.
     *
     *
     * If a payslip is retrieved successfully,
     *  it wil persist the metadata as custom fields
     *  and proceed to checkout/finish, leaving the transaction in an open/unpaid state.
     *
     * @param SyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     */
    public function pay(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): void
    {
        $context = $salesChannelContext->getContext();

        try {
            $request = $this->client->getCreatePayslipRequest(
                $salesChannelContext->getCustomer(),
                $salesChannelContext->getShippingLocation()->getAddress(),
                $transaction->getOrder(),
                $salesChannelContext
            );

            $divisionCountryCode = $salesChannelContext->getShippingLocation()->getAddress()->getCountry()->getIso();
            $result = $this->client->executeRequest($divisionCountryCode, $request);

            $customFields = [];
            $customFields['custom_viacash_checkout_token'] = $result["checkout_token"];
            $customFields['custom_viacash_division_id'] = (string)$this->client->getDivisionIndexByCountry($divisionCountryCode);
            $customFields['custom_viacash_slip_id'] = $result["id"];
            $customFields['custom_viacash_refundable_amount'] = 0; // Filled upon payment callback
            $customFields['custom_viacash_is_sandboxed'] = $this->client->isCountrySandboxed($divisionCountryCode) ? "1" : "";
            $customFields['custom_viacash_validity_days'] = (int)$this->client->getValidityInDays();

            $this->orderRepository->update([
                [
                    'id' => $transaction->getOrder()->getId(),
                    'customFields' => $customFields
                ]
            ],
                Context::createDefaultContext());
        } catch (\Exception $e) {
            $this->transactionStateHandler->fail($transaction->getOrderTransaction()->getId(), $context);
            /*
             * https://docs.shopware.com/en/shopware-platform-dev-en/how-to/payment-plugin:
             * If we have to execute some logic which might fail, e.g. a call to an external API,
             * we should throw a SyncPaymentProcessException
             * Shopware will handle this exception and set the transaction to the cancelled state.
             */
            throw new SyncPaymentProcessException($transaction->getOrderTransaction()->getId(), $e->getMessage());
        }
    }
}
