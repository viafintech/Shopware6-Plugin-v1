<?php

declare(strict_types=1);

namespace S360\Viacash\Controller;

use S360\Viacash\Service\ViacashClient;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ResendController extends StorefrontController
{

    private const REQUEST_KEY_ORDER_ID = 'orderId';
    private const REQUEST_KEY_ORDER_VERSION_ID = 'versionId';
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

    public function __construct(
        SystemConfigService $systemConfigService,
        ViacashClient $viacashClient,
        EntityRepository $orderRepository,
        EntityRepository $orderLineItemRepository
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->viacashClient = $viacashClient;
        $this->orderRepository = $orderRepository;
        $this->orderLineItemRepository = $orderLineItemRepository;
    }

    /**
     * @RouteScope(scopes={"api"})
     * @Route("/api/v{version}/_action/viacash/resend",
     *         defaults={"auth_enabled"=true}, name="api.action.viacash.resend", methods={"GET","POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function resend(Request $request): JsonResponse
    {
        $sOrderId = (string)$request->get(self::REQUEST_KEY_ORDER_ID);
        $sOrderVersionId = (string)$request->get(self::REQUEST_KEY_ORDER_VERSION_ID);

        if (!$sOrderId || !$sOrderVersionId) {
            return new JsonResponse([self::RESPONSE_KEY_SUCCESS => false]);
        }

        $success = false;

        try {
            $success = $this->viacashClient->resend(
                $sOrderId,
                $sOrderVersionId
            );
        } catch (\Exception $e) {
        }

        return new JsonResponse([
            self::RESPONSE_KEY_SUCCESS => $success,
        ]);
    }

}
