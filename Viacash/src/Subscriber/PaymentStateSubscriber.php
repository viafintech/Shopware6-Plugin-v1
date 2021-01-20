<?php

namespace S360\Viacash\Subscriber;

use S360\Viacash\Service\ViacashClient;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PaymentStateSubscriber implements EventSubscriberInterface
{
    /**
     * @var OrderTransactionStateHandler
     */
    protected $transactionStateHandler;

    /**
     * @var EntityRepository
     */
    protected $transactionRepository;
    /**
     * @var EntityRepository
     */
    protected $orderRepository;

    /**
     * @var ViacashClient $apiClient
     */
    private $apiClient;

    /**
     * @return array The event names to listen to
     */
    public static function getSubscribedEvents()
    {
        return [
            OrderEvents::ORDER_TRANSACTION_WRITTEN_EVENT => 'onOrderTransactionWritten',
            'state_machine.order.state_changed' => 'onOrderStateWritten' // No constant avail.
        ];
    }

    /**
     * Creates a new instance of PaymentMethodSubscriber.
     *
     * @param ViacashClient $apiClient
     * @param EntityRepository $transactionRepository
     * @param EntityRepository $orderRepository
     * @param OrderTransactionStateHandler $transactionStateHandler
     */
    public function __construct(
        ViacashClient $apiClient,
        EntityRepository $transactionRepository,
        EntityRepository $orderRepository,
        OrderTransactionStateHandler $transactionStateHandler
    ) {
        $this->apiClient = $apiClient;
        $this->transactionRepository = $transactionRepository;
        $this->orderRepository = $orderRepository;
        $this->transactionStateHandler = $transactionStateHandler;
    }

    /**
     * Refunds the transaction at Viacash if the payment state is refunded.
     * @param EntityWrittenEvent $args
     * @throws \Barzahlen\Exception\ApiException
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
    public function onOrderTransactionWritten(EntityWrittenEvent $args): void
    {
        foreach ($args->getPayloads() as $payload) {
            $transactionId = $payload['id'];
            $transactionVersionId = $payload['versionId'];

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $transactionId));
            $criteria->addFilter(new EqualsFilter('versionId', $transactionVersionId));
            $criteria->addAssociation('order');

            /**
             * @var OrderTransactionEntity $transaction
             */
            $transaction = $this->transactionRepository->search($criteria, Context::createDefaultContext())->first();

            // Get the order from the transaction
            if (
                $transaction !== null
                && $transaction->getStateMachineState() !== null
                && $transaction->getStateMachineState()->getTechnicalName() === 'cancelled'
            ) {
                $this->apiClient->invalidatePayslipForOrder($transaction->getOrder());
            }
        }
    }

    /**
     * Refunds the transaction at Viacash if the order is cancelled.
     * @param StateMachineStateChangeEvent $args
     */
    public function onOrderStateWritten(StateMachineStateChangeEvent $args): void
    {
        if ('order' !== $args->getTransition()->getEntityName()) {
            return;
        }

        if ('cancel' !== $args->getTransition()->getTransitionName()) {
            return;
        }

        try {
            $orderId = $args->getTransition()->getEntityId();
            $order = $this->orderRepository->search(new Criteria([$orderId]), $args->getContext())->first();
            $this->apiClient->invalidatePayslipForOrder($order);
        } catch (\Exception $e) {
        }
    }
}
