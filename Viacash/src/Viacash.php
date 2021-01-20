<?php

declare(strict_types=1);

namespace S360\Viacash;

use Doctrine\DBAL\Connection;
use S360\Viacash\Service\ViacashPaymentHandler;
use Shopware\Core\Checkout\Cart\Rule\CartAmountRule;
use Shopware\Core\Checkout\Customer\Rule\BillingCountryRule;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\Rule\Container\AndRule;
use Shopware\Core\Framework\Rule\Container\OrRule;
use Shopware\Core\Framework\Uuid\Uuid;

class Viacash extends Plugin
{

    public const AVAILABILITY_RULE_NAME = 'Viacash';

    /**
     * Gross total must be SMALLER THAN limit.
     * LIMIT = 1000    => max allowance =  999.99
     * LIMIT = 1000.01 => max allowance = 1000
     */
    public const AVAILABILITY_RULES = [
        [
            'COUNTRYCODE' => 'DE',
            'LIMIT' => 1000,
        ],
        [
            'COUNTRYCODE' => 'IT',
            'LIMIT' => 1000,
        ],
        [
            'COUNTRYCODE' => 'AT',
            'LIMIT' => 1000,
        ],
        [
            'COUNTRYCODE' => 'CH',
            'LIMIT' => 1500.01,
        ],
        [
            'COUNTRYCODE' => 'GR',
            'LIMIT' => 500,
        ],
    ];

    public function install(InstallContext $context): void
    {
        // Ignore InstallContext and Choose defaultContext.
        // installContext in backend-based install has no system privileges,
        // so addPaymentMethod() fails.

        $systemContext = Context::createDefaultContext();
        $this->addRules($systemContext);
        $this->addPaymentMethod(true, $systemContext);
        $this->addViacashToSalesChannels($systemContext);
        $this->addOrderCustomFields();
    }

    public function uninstall(UninstallContext $context): void
    {
        // Only set the payment method to inactive when uninstalling. Removing the payment method would
        // cause data consistency issues, since the payment method might have been used in several orders
        $this->setPaymentMethodIsActive(false, $context->getContext());
        parent::uninstall($context);

        if ($context->keepUserData()) {
            return;
        }

        $this->removeConfiguration($context->getContext());
    }

    public function activate(ActivateContext $context): void
    {
        $this->setPaymentMethodIsActive(true, $context->getContext());
        parent::activate($context);
    }

    public function deactivate(DeactivateContext $context): void
    {
        $this->setPaymentMethodIsActive(false, $context->getContext());
        parent::deactivate($context);
    }

    //////////////////////////////////////////////////////////////////////////

    protected function addRules(Context $context): void
    {
        $conditions = [];

        foreach (self::AVAILABILITY_RULES as $rule) {
            $countryId = $this->getCountryId($rule['COUNTRYCODE'], $context);
            $countryLimit = (float)$rule['LIMIT'];

            $conditions[] = [
                'id' => md5('viacashrulecondition' . 'CONTAINER' . $countryId),
                'type' => (new AndRule())->getName(),
                'children' => [
                    [
                        'id' => md5('viacashrulecondition' . 'COUNTRY' . $countryId),
                        'type' => (new BillingCountryRule())->getName(),
                        'value' => [
                            'operator' => BillingCountryRule::OPERATOR_EQ,
                            'countryIds' => [
                                $countryId,
                            ],
                        ],
                    ],
                    [
                        'id' => md5('viacashrulecondition' . 'AMOUNT' . $countryId),
                        'type' => (new CartAmountRule())->getName(),
                        'value' => [
                            'operator' => CartAmountRule::OPERATOR_LT,
                            'amount' => $countryLimit,
                        ],
                    ],
                ],
            ];
        }

        $data = [
            'id' => $this->getRuleId(),
            'name' => $this->getRuleName(),
            'priority' => 1,
            'description' => 'Determines whether viacash is available.',
            'conditions' => [
                [
                    'id' => md5('viacashidempotencyruleid'),
                    'type' => (new OrRule())->getName(),
                    'children' => $conditions,
                ]
            ],
            'moduleTypes' => ["types" => ["payment"]],
            'payload' => null,
        ];

        /** @var EntityRepositoryInterface $pcountryRepository */
        $ruleRepository = $this->container->get('rule.repository');
        $ruleRepository->upsert([$data], $context);
    }

    protected function getCountryId(string $countryCode, Context $context): ?string
    {
        /** @var EntityRepositoryInterface $pcountryRepository */
        $countryRepository = $this->container->get('country.repository');

        $criteria = new Criteria();

        $criteria->addFilter(
            new EqualsFilter('iso', $countryCode)
        );

        $country = $countryRepository->search($criteria, $context)->first();

        if ($country === null) {
            return null;
        }

        return $country->getId();
    }

    protected function getRuleName(): string
    {
        return self::AVAILABILITY_RULE_NAME;
    }

    protected function getRuleId(): string
    {
        return md5($this->getRuleName());
    }

    protected function addPaymentMethod(bool $active, Context $context): void
    {
        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(get_class($this), $context);

        $viacashPaymentData = [
            'id' => $this->getPaymentMethodId(),
            // payment handler will be selected by the identifier
            'handlerIdentifier' => ViacashPaymentHandler::class,
            'availabilityRuleId' => $this->getRuleId(),
            'pluginId' => $pluginId,
            'afterOrderEnabled' => false,
            'active' => true,
            'translations' => [
                'de-DE' => [
                    'description' => 'Bezahlung per Barzahlen.',
                    'name' => 'Barzahlen',
                ],
                'en-GB' => [
                    'description' => 'Pay via cash with viacash.',
                    'name' => 'viacash',
                ],
            ],
        ];

        /** @var EntityRepositoryInterface $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentRepository->upsert([$viacashPaymentData], $context);
    }

    protected function setPaymentMethodIsActive(bool $active, Context $context): void
    {
        $paymentRepository = $this->container->get('payment_method.repository');

        $paymentMethodId = $this->getPaymentMethodId();
        $paymentMethodExists = $paymentRepository->search(new Criteria([$paymentMethodId]), $context)->getTotal();

        if (!$paymentMethodExists) {
            return;
        }

        // Payment does not even exist, so nothing to (de-)activate here
        if (!$paymentMethodId) {
            return;
        }

        $paymentMethod = [
            'id' => $paymentMethodId,
            'active' => $active,
        ];

        $paymentRepository->update([$paymentMethod], $context);
    }

    protected function getPaymentMethodId(): ?string
    {
        return md5('BarzahlenViacash');
    }

    protected function removeConfiguration(Context $context): void
    {
        /** @var EntityRepositoryInterface $systemConfigRepository */
        $systemConfigRepository = $this->container->get('system_config.repository');
        $criteria = (new Criteria())->addFilter(new ContainsFilter('configurationKey', $this->getName() . '.config.'));
        $idSearchResult = $systemConfigRepository->searchIds($criteria, $context);

        $ids = array_map(static function ($id) {
            return ['id' => $id];
        },
            $idSearchResult->getIds());

        if ($ids === []) {
            return;
        }

        $systemConfigRepository->delete($ids, $context);
    }

    protected function addViacashToSalesChannels(Context $context): void
    {
        $paymentId = $this->getPaymentMethodId();

        $salesChannelsToChange = $this->getSalesChannelsToChange($context);
        $updateData = [];

        foreach ($salesChannelsToChange as $salesChannel) {
            $salesChannelUpdateData = [
                'id' => $salesChannel->getId(),
            ];

            $paymentMethodCollection = $salesChannel->getPaymentMethods();
            if ($paymentMethodCollection === null || $paymentMethodCollection->get($paymentId) === null) {
                $salesChannelUpdateData['paymentMethods'][] = [
                    'id' => $paymentId,
                ];
            }

            $updateData[] = $salesChannelUpdateData;
        }

        $this->container->get('sales_channel.repository')->update($updateData, $context);
    }

    protected function getSalesChannelsToChange(Context $context): EntityCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsAnyFilter('typeId', [
                Defaults::SALES_CHANNEL_TYPE_STOREFRONT,
                Defaults::SALES_CHANNEL_TYPE_API,
            ])
        );

        $criteria->addAssociation('paymentMethods');

        return $this->container->get('sales_channel.repository')->search($criteria, $context)->getEntities();
    }

    /**
     * Create custom fields.
     * All IDs fixed for idempotency on reinstallations.
     */
    protected function addOrderCustomFields(): void
    {
        $custom_field_set_id = md5('Viacash custom_field_set');

        $this->container->get('custom_field_set.repository')->upsert(
            [
                [
                    'id' => $custom_field_set_id,
                    'name' => 'custom_viacash',
                    'config' => [
                        "label" => ["de-DE" => "Viacash", "en-GB" => "Viacash"],
                        "translated" => true
                    ],
                    'active' => true,
                    'global' => false,
                ],
            ],
            Context::createDefaultContext()
        );

        $this->container->get('custom_field_set_relation.repository')->upsert(
            [
                [
                    'id' => md5('Viacash custom_field_set_relation'),
                    'customFieldSetId' => $custom_field_set_id,
                    'entityName' => 'order',
                ],
            ],
            Context::createDefaultContext()
        );


        $this->container->get('custom_field.repository')->upsert(
            [
                [
                    'id' => md5('custom_viacash_checkout_token'),
                    'customFieldSetId' => $custom_field_set_id,
                    'name' => 'custom_viacash_checkout_token',
                    'type' => 'text',
                    'config' => [
                        'type' => 'text',
                        "label" => ["de-DE" => "Barzahlen Checkout-Token", "en-GB" => "Viacash Checkout-Token"],
                        "translated" => true,
                        "componentName"=> "sw-field",
                        "customFieldType"=> "text",
                        "customFieldPosition"=>1
                    ],
                    'active' => true,
                ],
            ],
            Context::createDefaultContext()
        );

        $this->container->get('custom_field.repository')->upsert(
            [
                [
                    'id' => md5('custom_viacash_slip_id'),
                    'customFieldSetId' => $custom_field_set_id,
                    'name' => 'custom_viacash_slip_id',
                    'type' => 'text',
                    'config' => [
                        'type' => 'text',
                        "label" => ["de-DE" => "Barzahlen Slip-ID", "en-GB" => "Viacash Slip-ID"],
                        "translated" => true,
                        "componentName"=> "sw-field",
                        "customFieldType"=> "text",
                        "customFieldPosition"=>2
                    ],
                    'active' => true,
                ],
            ],
            Context::createDefaultContext()
        );

        $this->container->get('custom_field.repository')->upsert(
            [
                [
                    'id' => md5('custom_viacash_division_id'),
                    'customFieldSetId' => $custom_field_set_id,
                    'name' => 'custom_viacash_division_id',
                    'type' => 'text',
                    'config' => [
                        'type' => 'text',
                        "label" => ["de-DE" => "Interne Barzahlen Divisions-Nummer", "en-GB" => "Internal Viacash division number"],
                        "translated" => true,
                        "componentName"=> "sw-field",
                        "customFieldType"=> "text",
                        "customFieldPosition"=>3
                    ],
                    'active' => true,
                ],
            ],
            Context::createDefaultContext()
        );

        $this->container->get('custom_field.repository')->upsert(
            [
                [
                    'id' => md5('custom_viacash_is_sandboxed'),
                    'customFieldSetId' => $custom_field_set_id,
                    'name' => 'custom_viacash_is_sandboxed',
                    'type' => 'switch',
                    'config' => [
                        'type' => 'switch',
                        "label" => ["de-DE" => "Barzahlen Sandbox-Transaktion", "en-GB" => "Viacash sandboxed transaction"],
                        "translated" => true,
                        "componentName"=> "sw-field",
                        "customFieldType"=> "switch",
                        "customFieldPosition"=>4
                    ],
                    'active' => true,
                ],
            ],
            Context::createDefaultContext()
        );

        $this->container->get('custom_field.repository')->upsert(
            [
                [
                    'id' => md5('custom_viacash_refundable_amount'),
                    'customFieldSetId' => $custom_field_set_id,
                    'name' => 'custom_viacash_refundable_amount',
                    'type' => 'number',
                    'config' => [
                        'type' => 'number',
                        "label" => ["de-DE" => "Verbleibender per Barzahlen erstattbarer Betrag", "en-GB" => "Remaining amount that can be refunded with Viacash"],
                        "translated" => true,
                        "componentName"=> "sw-field",
                        "customFieldType"=> "number",
                        "customFieldPosition"=>5
                    ],
                    'active' => true,
                ],
            ],
            Context::createDefaultContext()
        );

    }

}
