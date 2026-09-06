<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$shell = file_get_contents($root . '/views/templates/front/checkout-shell.tpl');
$renderer = file_get_contents($root . '/src/Integration/CheckoutShellRenderer.php');
$factory = file_get_contents($root . '/src/Integration/CheckoutBrowserBootstrapFactory.php');
$assets = file_get_contents($root . '/src/Integration/CheckoutFrontendAssetRegistrar.php');
$config = file_get_contents($root . '/config/common/services.yml');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertShellContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertShellContract(is_string($shell) && str_contains($shell, 'data-jzopc-checkout'), 'trusted checkout root is required');
foreach ([
    'data-jzopc-cart-id',
    'data-jzopc-state-version',
    'data-jzopc-csrf-token',
    'data-jzopc-identity-url',
    'data-jzopc-address-url',
    'data-jzopc-address-save-url',
    'data-jzopc-carrier-url',
    'data-jzopc-payment-url',
    'data-jzopc-agreements-url',
    'data-jzopc-finalization-url',
] as $binding) {
    assertShellContract(str_contains($shell, $binding), sprintf('trusted shell binding %s is required', $binding));
}
assertShellContract(str_contains($shell, 'data-jzopc-finalization-reserved="{if $jzopc_finalization_reserved}1{else}0{/if}"'), 'server-derived reservation state is required');
assertShellContract(str_contains($shell, 'data-jzopc-final-submit'), 'module-owned final order action is required');
assertShellContract(str_contains($shell, 'data-jzopc-final-status'), 'accessible final status region is required');
assertShellContract(str_contains($shell, '{$jzopc_section_html nofilter}'), 'trusted section HTML boundary is required');
assertShellContract(str_contains($shell, '{foreach $jzopc_javascript_urls as $jzopc_javascript_url}'), 'custom shell must own required OPC runtime delivery');
assertShellContract(str_contains($shell, 'data-jzopc-runtime-asset'), 'OPC runtime scripts must be explicitly identifiable');
assertShellContract(str_contains($shell, 'src="{$jzopc_javascript_url|escape:'), 'runtime asset URLs must remain escaped');
assertShellContract(!str_contains($shell, 'data-jzopc-core-compatibility-asset'), 'custom shell must not inject PrestaShop Core compatibility JavaScript');

assertShellContract(is_string($renderer) && str_contains($renderer, 'CheckoutServerSelectionsStoreInterface'), 'renderer must load canonical server selections');
assertShellContract(str_contains($renderer, 'CheckoutFinalizationReservationStoreInterface $finalizationReservationStore'), 'renderer must load finalization reservation state from server persistence');
assertShellContract(str_contains($renderer, "'jzopc_finalization_reserved' => $" . "this->finalizationReservationStore->isActive($" . "context)"), 'renderer must derive reservation marker at render time');
assertShellContract(
    str_contains($renderer, "'jzopc_javascript_urls' => $" . "this->frontendAssets->shellJavascriptUrls()")
        && !str_contains($renderer, 'jzopc_compatibility_javascript_urls'),
    'renderer must bind only OPC-owned runtime assets',
);
foreach (['Identity', 'Addresses', 'Delivery', 'Payment', 'Agreements', 'Summary'] as $section) {
    assertShellContract(str_contains($renderer, 'CheckoutSection::' . $section), sprintf('%s renderer is required', $section));
}
assertShellContract(is_string($config) && str_contains($config, 'IdentitySectionRenderer'), 'identity renderer must be registered in shared front services');

assertShellContract(is_string($factory) && str_contains($factory, '\\Tools::getToken(false)'), 'bootstrap factory must use Core front CSRF token');
foreach (['identity', 'addressselection', 'addresssave', 'carrierselection', 'paymentselection', 'agreements', 'finalize'] as $route) {
    assertShellContract(str_contains($factory, "'{$route}'"), sprintf('%s endpoint must be generated server-side', $route));
}
assertShellContract(str_contains($factory, 'stateVersioner->version'), 'bootstrap must derive authoritative state version');
assertShellContract(str_contains($factory, 'stateFactory->create'), 'bootstrap must derive state from Core context');

assertShellContract(is_string($assets) && str_contains($assets, 'shellJavascriptUrls'), 'asset service must expose the shell-owned runtime manifest');
assertShellContract(str_contains($assets, "constant('_MODULE_DIR_')"), 'runtime URLs must derive from PrestaShop module base URI');
assertShellContract(
    !str_contains($assets, 'addJquery(')
        && !str_contains($assets, 'registerJavascript(')
        && !str_contains($assets, 'getJqueryPath(')
        && !str_contains($assets, 'shellCompatibilityJavascriptUrls'),
    'OPC asset service must not duplicate Core/theme compatibility JavaScript',
);
foreach ([
    'payment-controller.js',
    'checkout-mutation-client.js',
    'final-submit-controller.js',
    'ordinary-payment-submit-guard.js',
    'binary-payment-controller.js',
    'payment-handoff-ambiguity-guard.js',
] as $runtimeAsset) {
    assertShellContract(str_contains($assets, "'views/js/{$runtimeAsset}'"), sprintf('shell runtime manifest must retain %s', $runtimeAsset));
}
assertShellContract(is_string($module) && str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'), 'production readiness gate must remain closed');

echo "CheckoutShellContractSmokeTest OK\n";
