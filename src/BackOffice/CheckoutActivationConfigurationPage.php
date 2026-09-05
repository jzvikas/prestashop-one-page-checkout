<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\BackOffice;

use Jzvikas\OnePageCheckout\Integration\CheckoutActivationBlockReason;
use Jzvikas\OnePageCheckout\Integration\CheckoutActivationPolicy;
use Jzvikas\OnePageCheckout\Integration\CheckoutCapabilityDetector;
use Jzvikas\OnePageCheckout\Integration\CheckoutIntegrationStrategy;
use Jzvikas\OnePageCheckout\Integration\CheckoutRuntimeCapabilities;

final readonly class CheckoutActivationConfigurationPage
{
    private const SUBMIT_ACTION = 'submitJzOpcCheckoutConfiguration';
    private const TRANSLATION_DOMAIN = 'Modules.Jzonepagecheckout.Admin';

    public function __construct(
        private \Module $module,
        private CheckoutCapabilityDetector $capabilityDetector,
        private CheckoutActivationPolicy $activationPolicy,
        private string $configurationKey,
        private bool $integrationShellReady,
    ) {
        if ($this->configurationKey === '') {
            throw new \InvalidArgumentException('Checkout activation configuration key cannot be empty.');
        }
    }

    public function render(): string
    {
        $capabilities = $this->capabilityDetector->detect();
        $output = $this->processSubmission($capabilities);
        $output .= $this->renderSafetyNotice($capabilities);

        if (!$this->isSingleShopContext()) {
            return $output . $this->module->displayWarning($this->trans(
                'Select a single shop in the multistore selector before changing One Page Checkout settings. No group-wide or all-shops write is allowed from this page.'
            ));
        }

        return $output . $this->renderForm($capabilities);
    }

    private function processSubmission(CheckoutRuntimeCapabilities $capabilities): string
    {
        if (!\Tools::isSubmit(self::SUBMIT_ACTION)) {
            return '';
        }

        if (!$this->isSingleShopContext()) {
            return $this->module->displayError($this->trans(
                'One Page Checkout settings were not changed because a single shop is not selected.'
            ));
        }

        $requested = \Tools::getValue($this->configurationKey, null);
        if (!is_scalar($requested) || !in_array((string) $requested, ['0', '1'], true)) {
            return $this->module->displayError($this->trans(
                'Invalid One Page Checkout activation value.'
            ));
        }

        $enable = (string) $requested === '1';
        if ($enable) {
            $decision = $this->activationPolicy->decide(
                capabilities: $capabilities,
                featureEnabled: true,
                integrationShellReady: $this->integrationShellReady,
            );

            if (!$decision->allowed) {
                return $this->module->displayError($this->activationBlockMessage($decision->blockReason));
            }
        }

        [$shopGroupId, $shopId] = $this->currentShopScope();
        if (!\Configuration::updateValue(
            $this->configurationKey,
            $enable,
            false,
            $shopGroupId,
            $shopId,
        )) {
            return $this->module->displayError($this->trans(
                'The One Page Checkout setting could not be saved.'
            ));
        }

        return $this->module->displayConfirmation($this->trans(
            'The One Page Checkout setting has been updated for the selected shop.'
        ));
    }

    private function renderSafetyNotice(CheckoutRuntimeCapabilities $capabilities): string
    {
        if ($capabilities->strategy === CheckoutIntegrationStrategy::Unsupported) {
            return $this->module->displayWarning($this->activationBlockMessage(
                CheckoutActivationBlockReason::UnsupportedRuntime
            ));
        }

        if ($capabilities->hasNativeProviderConflict()) {
            return $this->module->displayWarning($this->activationBlockMessage(
                CheckoutActivationBlockReason::NativeProviderConflict
            ));
        }

        if (!$this->integrationShellReady) {
            return $this->module->displayWarning($this->activationBlockMessage(
                CheckoutActivationBlockReason::IntegrationShellNotReady
            ));
        }

        return '';
    }

    private function renderForm(CheckoutRuntimeCapabilities $capabilities): string
    {
        [$shopGroupId, $shopId] = $this->currentShopScope();
        $configuredEnabled = (bool) \Configuration::get(
            $this->configurationKey,
            null,
            $shopGroupId,
            $shopId,
        );

        $strategy = match ($capabilities->strategy) {
            CheckoutIntegrationStrategy::ProviderHook => 'actionCheckoutBuildProcess',
            CheckoutIntegrationStrategy::CheckoutRenderHook => 'actionCheckoutRender',
            CheckoutIntegrationStrategy::Unsupported => $this->trans('Unsupported'),
        };

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Checkout activation'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Use One Page Checkout'),
                        'name' => $this->configurationKey,
                        'is_bool' => true,
                        'desc' => $this->trans(
                            'This value is stored only for the selected shop. Enabling is rejected unless the detected checkout integration, native-provider conflict check and internal readiness gate all allow takeover. Detected integration: %strategy%.',
                            ['%strategy%' => $strategy],
                        ),
                        'values' => [
                            [
                                'id' => 'jzopc_checkout_enabled_on',
                                'value' => 1,
                                'label' => $this->trans('Enabled'),
                            ],
                            [
                                'id' => 'jzopc_checkout_enabled_off',
                                'value' => 0,
                                'label' => $this->trans('Disabled'),
                            ],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save'),
                ],
            ],
        ];

        $context = \Context::getContext();
        $languages = [];
        if (is_object($context->controller) && method_exists($context->controller, 'getLanguages')) {
            $languages = $context->controller->getLanguages();
        }

        $helper = new \HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this->module;
        $helper->default_form_language = (int) \Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) \Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->identifier = $this->module->identifier;
        $helper->submit_action = self::SUBMIT_ACTION;
        $helper->currentIndex = $context->link->getAdminLink('AdminModules', false)
            . '&configure=' . rawurlencode((string) $this->module->name)
            . '&tab_module=' . rawurlencode((string) $this->module->tab)
            . '&module_name=' . rawurlencode((string) $this->module->name);
        $helper->token = \Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => [
                $this->configurationKey => $configuredEnabled ? 1 : 0,
            ],
            'languages' => $languages,
            'id_language' => (int) ($context->language->id ?? 0),
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    /** @return array{0:int,1:int} */
    private function currentShopScope(): array
    {
        $shopGroupId = (int) \Shop::getContextShopGroupID();
        $shopId = (int) \Shop::getContextShopID();

        if ($shopGroupId <= 0 || $shopId <= 0) {
            throw new \RuntimeException('A concrete shop scope is required for checkout activation.');
        }

        return [$shopGroupId, $shopId];
    }

    private function isSingleShopContext(): bool
    {
        return \Shop::getContext() === \Shop::CONTEXT_SHOP
            && (int) \Shop::getContextShopID() > 0
            && (int) \Shop::getContextShopGroupID() > 0;
    }

    private function activationBlockMessage(?CheckoutActivationBlockReason $reason): string
    {
        return match ($reason) {
            CheckoutActivationBlockReason::UnsupportedRuntime => $this->trans(
                'One Page Checkout cannot be enabled because this PrestaShop runtime does not expose a supported checkout integration contract.'
            ),
            CheckoutActivationBlockReason::NativeProviderConflict => $this->trans(
                'One Page Checkout cannot be enabled while the native ps_onepagecheckout provider is enabled for this runtime. Disable the competing provider first.'
            ),
            CheckoutActivationBlockReason::IntegrationShellNotReady => $this->trans(
                'One Page Checkout activation is locked by the internal safety gate until the required installed-runtime and browser verification is complete.'
            ),
            CheckoutActivationBlockReason::FeatureDisabled => $this->trans(
                'One Page Checkout is disabled for this shop.'
            ),
            null => $this->trans('One Page Checkout activation is not available.'),
        };
    }

    /** @param array<string,mixed> $parameters */
    private function trans(string $message, array $parameters = []): string
    {
        return $this->module->trans($message, $parameters, self::TRANSLATION_DOMAIN);
    }
}
