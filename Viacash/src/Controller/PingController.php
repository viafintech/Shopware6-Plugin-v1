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

class PingController extends StorefrontController
{

    private const RESPONSE_KEY_SUCCESS = 'success';
    private const RESPONSE_KEY_SUCCESSFUL_IDS = 'successful_ids';

    /**
     * @var SystemConfigService
     */
    protected $systemConfigService;
    /**
     * @var ViacashClient
     */
    protected $viacashClient;


    public function __construct(
        SystemConfigService $systemConfigService,
        ViacashClient $viacashClient
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->viacashClient = $viacashClient;
    }

    /**
     * @RouteScope(scopes={"api"})
     * @Route("/api/v{version}/_action/viacash/ping",
     *         defaults={"auth_enabled"=true}, name="api.action.viacash.ping", methods={"GET","POST"})
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function ping(Request $request): JsonResponse
    {
        $successfulIDs = [];

        for ($i = 1; $i <= 6; $i++) {
            try {
                if ($this->viacashClient->ping($i)) {
                    $successfulIDs[] = $i;
                }
            } catch (\Exception $e) {
            }
        }


        return new JsonResponse([
            self::RESPONSE_KEY_SUCCESS => count($successfulIDs) > 0,
            self::RESPONSE_KEY_SUCCESSFUL_IDS => $successfulIDs,
        ]);
    }

}
