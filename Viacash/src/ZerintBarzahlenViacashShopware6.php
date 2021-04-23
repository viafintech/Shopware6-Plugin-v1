<?php

declare(strict_types=1);

namespace S360\Viacash;

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
use Shopware\Core\System\Currency\Rule\CurrencyRule;

class ZerintBarzahlenViacashShopware6 extends Plugin
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
            'CURRENCY' => 'EUR',
        ],
        [
            'COUNTRYCODE' => 'IT',
            'LIMIT' => 1000,
            'CURRENCY' => 'EUR',
        ],
        [
            'COUNTRYCODE' => 'AT',
            'LIMIT' => 1000,
            'CURRENCY' => 'EUR',
        ],
        [
            'COUNTRYCODE' => 'CH',
            'LIMIT' => 1500.01,
            'CURRENCY' => 'CHF',
        ],
        [
            'COUNTRYCODE' => 'GR',
            'LIMIT' => 500,
            'CURRENCY' => 'EUR',
        ],
        [
            'COUNTRYCODE' => 'ES',
            'LIMIT' => 1000,
            'CURRENCY' => 'EUR',
        ],
        [
            'COUNTRYCODE' => 'FR',
            'LIMIT' => 1000,
            'CURRENCY' => 'EUR',
        ],
    ];

    /**
     * @var ?string[]
     */
    protected $currencyIdCache = ['EUR' => null, 'CHF' => null];

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
            $currencyId = $this->getCurrencyId($rule['CURRENCY'], $context);

            if (!$countryId || !$countryLimit || !$currencyId) {
                continue;
            }

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
                        'id' => md5('viacashrulecondition' . 'CURRENCY' . $countryId),
                        'type' => (new CurrencyRule())->getName(),
                        'value' => [
                            'operator' => CurrencyRule::OPERATOR_EQ,
                            'currencyIds' => [
                                $currencyId
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

    protected function getCurrencyId($iso3, Context $context)
    {
        if (isset($this->currencyIdCache[$iso3]) && $this->currencyIdCache[$iso3]) {
            return $this->currencyIdCache[$iso3];
        }

        /** @var EntityRepositoryInterface $currencyRepository */
        $currencyRepository = $this->container->get('currency.repository');
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('isoCode', $iso3)
        );
        $this->currencyIdCache[$iso3] = $currencyRepository->searchIds($criteria, $context)->firstId();

        return $this->currencyIdCache[$iso3];
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
            'translations' => $this->injectFallbackLanguage(
                [
                    'de-DE' => [
                        'description' => 'Mit Abschluss der Bestellung bekommen Sie einen Zahlschein angezeigt, den Sie sich ausdrucken oder auf Ihr Handy schicken lassen können. Bezahlen Sie den Online-Einkauf mit Hilfe des Zahlscheins an der Kasse einer Barzahlen/viacash-Partnerfiliale: www.barzahlen.de/filialfinder',
                        'name' => 'Barzahlen',
                    ],
                    'en-GB' => [
                        'description' => 'After you complete your order, you will be shown a payment slip that you can print out or have sent to your mobile phone. Go to the nearest Barzahlen/viacash partner branch at viacash.com/storefinder and pay at the checkout in cash or your preferred payment method.',
                        'name' => 'viacash',
                    ],
                ]
            ),
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
                        "label" => $this->injectFallbackLanguage(["de-DE" => "Viacash", "en-GB" => "Viacash"]),
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
                        "label" => $this->injectFallbackLanguage(["de-DE" => "Barzahlen Checkout-Token", "en-GB" => "Viacash Checkout-Token"]),
                        "translated" => true,
                        "componentName" => "sw-field",
                        "customFieldType" => "text",
                        "customFieldPosition" => 1
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
                        "label" => $this->injectFallbackLanguage(["de-DE" => "Barzahlen Slip-ID", "en-GB" => "Viacash Slip-ID"]),
                        "translated" => true,
                        "componentName" => "sw-field",
                        "customFieldType" => "text",
                        "customFieldPosition" => 2
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
                        "label" => $this->injectFallbackLanguage(["de-DE" => "Interne Barzahlen Divisions-Nummer", "en-GB" => "Internal Viacash division number"]),
                        "translated" => true,
                        "componentName" => "sw-field",
                        "customFieldType" => "text",
                        "customFieldPosition" => 3
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
                        "label" => $this->injectFallbackLanguage(["de-DE" => "Barzahlen Sandbox-Transaktion", "en-GB" => "Viacash sandboxed transaction"]),
                        "translated" => true,
                        "componentName" => "sw-field",
                        "customFieldType" => "switch",
                        "customFieldPosition" => 4
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
                        "label" => $this->injectFallbackLanguage([
                            "de-DE" => "Verbleibender per Barzahlen erstattbarer Betrag",
                            "en-GB" => "Remaining amount that can be refunded with Viacash",
                        ]),
                        "translated" => true,
                        "componentName" => "sw-field",
                        "customFieldType" => "number",
                        "customFieldPosition" => 5
                    ],
                    'active' => true,
                ],
            ],
            Context::createDefaultContext()
        );

        $this->container->get('custom_field.repository')->upsert(
            [
                [
                    'id' => md5('custom_viacash_validity_days'),
                    'customFieldSetId' => $custom_field_set_id,
                    'name' => 'custom_viacash_validity_days',
                    'type' => 'number',
                    'config' => [
                        'type' => 'number',
                        "label" => $this->injectFallbackLanguage(["de-DE" => "Gültigkeitsdauer des Zahlscheins in Tagen", "en-GB" => "Days of payslip validity."]),
                        "translated" => true,
                        "componentName" => "sw-field",
                        "customFieldType" => "number",
                        "customFieldPosition" => 5
                    ],
                    'active' => true,
                ],
            ],
            Context::createDefaultContext()
        );
    }

    protected $cacheNeedLanguageFallback;
    protected $cacheDefaultLocaleCode;

    /**
     * Copies english to the default language locale, in case it's not one of de-DE and en-GB.
     * @param array $translations
     * @return array
     */
    protected function injectFallbackLanguage(array $translations): array
    {
        if (!isset($this->cacheNeedLanguageFallback)) {
            $repository = $this->container->get('language.repository');
            $context = new Criteria([Defaults::LANGUAGE_SYSTEM]);
            $context->addAssociation('locale');
            $language = $repository->search($context, Context::createDefaultContext())->get(Defaults::LANGUAGE_SYSTEM);
            $this->cacheDefaultLocaleCode = $language->getLocale()->getCode();
            $this->cacheNeedLanguageFallback = !in_array($this->cacheDefaultLocaleCode, ['de-DE', 'en-GB']);
        }

        if ($this->cacheNeedLanguageFallback) {
            $translations[$this->cacheDefaultLocaleCode] = $translations["en-GB"];
        }

        return $translations;
    }

}
