<?php

declare(strict_types=1);

namespace S360\Viacash\Controller;

use S360\Viacash\Service\ViacashClient;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"storefront"})
 */
class ViacashHookController extends StorefrontController
{
    /**
     * @var ViacashClient
     */
    protected $client;
    /**
     * @var EntityRepository
     */
    protected $orderRepository;

    /** @var SystemConfigService $systemConfigService */
    private $systemConfigService;

    /**
     * Creates a new instance of the config service.
     *
     * @param SystemConfigService $systemConfigService
     * @param ViacashClient $client
     * @param EntityRepository $orderRepository
     */
    public function __construct(SystemConfigService $systemConfigService, ViacashClient $client, EntityRepository $orderRepository)
    {
        $this->systemConfigService = $systemConfigService;
        $this->client = $client;
        $this->orderRepository = $orderRepository;
    }

    /**
     * @RouteScope(scopes={"storefront"})
     * @Route("/viacash/hook", name="frontend.viacash.hook", options={"seo"="false"}, methods={"GET","POST"}, defaults={"csrf_protected"=false},)
     */
    public function hook(SalesChannelContext $salesChannelContext)
    {
        try {

            $success = $this->client->processHook($salesChannelContext->getContext());

            if ($success) {
                header('HTTP/1.1 200');
                die();
            }

            header('HTTP/1.1 400');
            die();

        } catch (\Exception $e) {
            header('HTTP/1.1 500');
            die();
        }
    }
}
