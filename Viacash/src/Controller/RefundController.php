<?php

declare(strict_types=1);

namespace S360\Viacash\Controller;

use S360\Viacash\Service\ViacashClient;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class RefundController extends StorefrontController
{

    private const REQUEST_KEY_ORDER_ID = 'orderId';
    private const REQUEST_KEY_ORDER_VERSION_ID = 'versionId';
    private const REQUEST_KEY_REFUND_AMOUNT = 'refundAmount';
    private const RESPONSE_KEY_SUCCESS = 'success';

    /**
     * @var SystemConfigService
     */
    protected $systemConfigService;
    /**
     * @var ViacashClient
     */
    protected $viacashClient;
    /**
     * @var EntityRepository
     */
    protected $orderRepository;
    /**
     * @var EntityRepository
     */
    protected $orderLineItemRepository;
    /**
     * @var OrderTransactionStateHandler
     */
    protected $orderTransactionStateHandler;

    public function __construct(
        SystemConfigService $systemConfigService,
        ViacashClient $viacashClient,
        EntityRepository $orderRepository,
        EntityRepository $orderLineItemRepository,
        OrderTransactionStateHandler $orderTransactionStateHandler
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->viacashClient = $viacashClient;
        $this->orderRepository = $orderRepository;
        $this->orderLineItemRepository = $orderLineItemRepository;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
    }

    /**
     * @RouteScope(scopes={"api"})
     * @Route("/api/v{version}/_action/viacash/refund",
     *         defaults={"auth_enabled"=true}, name="api.action.viacash.refund", methods={"GET","POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function refund(Request $request): JsonResponse
    {
        $sOrderId = (string)$request->get(self::REQUEST_KEY_ORDER_ID);
        $sOrderVersionId = (string)$request->get(self::REQUEST_KEY_ORDER_VERSION_ID);
        $refundAmount = (float)$request->get(self::REQUEST_KEY_REFUND_AMOUNT);

        if (!$sOrderId || !$sOrderVersionId || !$refundAmount) {
            return new JsonResponse([self::RESPONSE_KEY_SUCCESS => false]);
        }

        $success = false;

        try {
            $success = $this->viacashClient->refund(
                $refundAmount,
                $sOrderId,
                Context::createDefaultContext(),
                $sOrderVersionId
            );
        } catch (\Exception $e) {
        }

        return new JsonResponse([
            self::RESPONSE_KEY_SUCCESS => $success,
        ]);
    }

}
