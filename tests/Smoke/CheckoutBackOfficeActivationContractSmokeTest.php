<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$page = file_get_contents($root . '/src/BackOffice/CheckoutActivationConfigurationPage.php');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertBackOfficeActivationContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertBackOfficeActivationContract(is_string($page), 'Back Office activation page source must be readable');
assertBackOfficeActivationContract(is_string($module), 'module source must be readable');

assertBackOfficeActivationContract(str_contains($module, 'public function getContent()'), 'module must expose the standard configuration page entry point');
assertBackOfficeActivationContract(str_contains($module, 'CheckoutActivationConfigurationPage('), 'module configuration entry point must delegate to the focused Back Office page');
assertBackOfficeActivationContract(str_contains($module, 'integrationShellReady: self::INTEGRATION_SHELL_READY'), 'Back Office activation must use the same internal readiness gate as checkout hooks');
assertBackOfficeActivationContract(str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'), 'production checkout takeover gate must remain closed');
assertBackOfficeActivationContract(str_contains($module, '$this->version = \'0.4.0\';'), 'Back Office UI alone must not cause an unrelated module version bump');

assertBackOfficeActivationContract(str_contains($page, '\\Shop::getContext() === \\Shop::CONTEXT_SHOP'), 'configuration writes must require one concrete shop context');
assertBackOfficeActivationContract(str_contains($page, '!in_array((string) $requested, [\'0\', \'1\'], true)'), 'activation input must accept only strict boolean 0/1 values');
assertBackOfficeActivationContract(str_contains($page, 'featureEnabled: true'), 'enable attempts must be checked as enabled against the shared activation policy');
assertBackOfficeActivationContract(str_contains($page, 'integrationShellReady: $this->integrationShellReady'), 'enable attempts must recheck readiness server-side');
assertBackOfficeActivationContract(str_contains($page, 'CheckoutActivationBlockReason::NativeProviderConflict'), 'native One Page Checkout conflicts must be surfaced and block activation');
assertBackOfficeActivationContract(str_contains($page, 'CheckoutActivationBlockReason::UnsupportedRuntime'), 'unsupported runtime must fail closed');
assertBackOfficeActivationContract(str_contains($page, 'CheckoutActivationBlockReason::IntegrationShellNotReady'), 'internal readiness must fail closed');

assertBackOfficeActivationContract(str_contains($page, '$shopGroupId,'), 'configuration persistence must use the explicit shop-group scope');
assertBackOfficeActivationContract(str_contains($page, '$shopId,'), 'configuration persistence must use the explicit shop scope');
assertBackOfficeActivationContract(str_contains($page, '\\Configuration::updateValue('), 'configuration page must persist through PrestaShop Configuration');
assertBackOfficeActivationContract(str_contains($page, 'new \\HelperForm()'), 'configuration page must use PrestaShop HelperForm');
assertBackOfficeActivationContract(str_contains($page, "getAdminLink('AdminModules', false)"), 'configuration form must post through AdminModules');
assertBackOfficeActivationContract(str_contains($page, "\\Tools::getAdminTokenLite('AdminModules')"), 'configuration form must use the AdminModules token');
assertBackOfficeActivationContract(str_contains($page, "private const SUBMIT_ACTION = 'submitJzOpcCheckoutConfiguration';"), 'configuration submission action must be explicit and stable');

fwrite(STDOUT, "Checkout Back Office activation contract smoke tests passed.\n");
