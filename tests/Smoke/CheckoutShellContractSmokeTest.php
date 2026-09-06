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
assertShellContract(str_contains($shell, 'data-jzopc-cart-id'), 'cart bootstrap is required');
assertShellContract(str_contains($shell, 'data-jzopc-state-version'), 'state version bootstrap is required');
assertShellContract(str_contains($shell, 'data-jzopc-csrf-token'), 'CSRF bootstrap is required');
assertShellContract(str_contains($shell, 'data-jzopc-identity-url'), 'identity endpoint bootstrap is required');
assertShellContract(str_contains($shell, 'data-jzopc-address-url'), 'address endpoint bootstrap is required');
assertShellContract(str_contains($shell, 'data-jzopc-address-save-url'), 'address-save endpoint bootstrap is required');
assertShellContract(str_contains($shell, 'data-jzopc-carrier-url'), 'carrier endpoint bootstrap is required');
assertShellContract(str_contains($shell, 'data-jzopc-payment-url'), 'payment endpoint bootstrap is required');
assertShellContract(str_contains($shell, 'data-jzopc-agreements-url'), 'agreements endpoint bootstrap is required');
assertShellContract(str_contains($shell, 'data-jzopc-finalization-url'), 'finalization endpoint bootstrap is required');
assertShellContract(
    str_contains($shell, 'data-jzopc-finalization-reserved="{if $jzopc_finalization_reserved}1{else}0{/if}"'),
    'trusted checkout root must expose only the server-derived finalization reservation state',
);
assertShellContract(str_contains($shell, 'data-jzopc-final-submit'), 'one clear module-owned final order action is required');
assertShellContract(str_contains($shell, 'data-jzopc-final-status'), 'final order action needs an accessible live status region');
assertShellContract(str_contains($shell, "data-jzopc-final-message=\"payment-required\""), 'final submit client messages must come from translated Smarty markup');
assertShellContract(str_contains($shell, "|escape:'htmlall':'UTF-8'"), 'bootstrap attributes must remain escaped');
assertShellContract(str_contains($shell, '{$jzopc_section_html nofilter}'), 'trusted section HTML boundary is required');
assertShellContract(str_contains($shell, '{foreach $jzopc_javascript_urls as $jzopc_javascript_url}'), 'custom shell must own required runtime asset delivery');
assertShellContract(str_contains($shell, 'data-jzopc-runtime-asset'), 'custom shell runtime scripts must be explicitly identifiable');
assertShellContract(str_contains($shell, 'src="{$jzopc_javascript_url|escape:'), 'runtime asset URLs must remain escaped');

assertShellContract(is_string($renderer) && str_contains($renderer, 'CheckoutServerSelectionsStoreInterface'), 'renderer must load canonical server selections');
assertShellContract(
    str_contains($renderer, 'CheckoutFinalizationReservationStoreInterface $finalizationReservationStore'),
    'renderer must load finalization reservation state from server persistence',
);
assertShellContract(
    str_contains($renderer, "'jzopc_finalization_reserved' => $" . "this->finalizationReservationStore->isActive($" . "context)"),
    'renderer must derive the finalization reservation marker at render time',
);
assertShellContract(
    str_contains($renderer, 'CheckoutFrontendAssetRegistrar $frontendAssets')
        && str_contains($renderer, "'jzopc_javascript_urls' => $" . "this->frontendAssets->shellJavascriptUrls()"),
    'renderer must resolve the required runtime manifest before rendering the custom shell',
);
assertShellContract(str_contains($renderer, 'CheckoutSection::Identity'), 'identity renderer is required');
assertShellContract(str_contains($renderer, 'CheckoutSection::Addresses'), 'addresses renderer is required');
assertShellContract(str_contains($renderer, 'CheckoutSection::Delivery'), 'delivery renderer is required');
assertShellContract(str_contains($renderer, 'CheckoutSection::Payment'), 'payment renderer is required');
assertShellContract(str_contains($renderer, 'CheckoutSection::Agreements'), 'agreements renderer is required');
assertShellContract(str_contains($renderer, 'CheckoutSection::Summary'), 'summary renderer is required');
assertShellContract(is_string($config) && str_contains($config, 'IdentitySectionRenderer'), 'identity renderer must be registered in the shared front service graph');

assertShellContract(is_string($factory) && str_contains($factory, '\\Tools::getToken(false)'), 'bootstrap factory must use Core front CSRF token');
assertShellContract(str_contains($factory, "'identity'"), 'identity URL must be generated server-side');
assertShellContract(str_contains($factory, "'addressselection'"), 'address URL must be generated server-side');
assertShellContract(str_contains($factory, "'addresssave'"), 'address-save URL must be generated server-side');
assertShellContract(str_contains($factory, "'carrierselection'"), 'carrier URL must be generated server-side');
assertShellContract(str_contains($factory, "'paymentselection'"), 'payment URL must be generated server-side');
assertShellContract(str_contains($factory, "'agreements'"), 'agreements URL must be generated server-side');
assertShellContract(str_contains($factory, "'finalize'"), 'finalization URL must be generated server-side');
assertShellContract(str_contains($factory, 'stateVersioner->version'), 'bootstrap must derive authoritative state version');
assertShellContract(str_contains($factory, 'stateFactory->create'), 'bootstrap must derive state from Core context');

assertShellContract(is_string($assets) && str_contains($assets, 'payment-controller.js'), 'payment controller asset must remain in the runtime manifest');
assertShellContract(str_contains($assets, 'checkout-mutation-client.js'), 'mutation transport asset must remain in the runtime manifest');
assertShellContract(str_contains($assets, 'final-submit-controller.js'), 'final-submit controller asset must remain in the runtime manifest');
assertShellContract(str_contains($assets, 'shellJavascriptUrls'), 'asset service must expose the shell-owned runtime manifest');
assertShellContract(str_contains($assets, "constant('_MODULE_DIR_')"), 'runtime URLs must derive from PrestaShop module base URI');
assertShellContract(
    substr_count($assets, '$controller->registerJavascript(') === 1
        && str_contains($assets, "private const CORE_JQUERY_ASSET_ID = 'jzopc-core-jquery';")
        && str_contains($assets, '$jqueryPath = \\Media::getJqueryPath();'),
    'modern Core page-level registration must be limited to the Core-owned jQuery compatibility dependency',
);
foreach ([
    'payment-controller.js',
    'checkout-mutation-client.js',
    'final-submit-controller.js',
    'ordinary-payment-submit-guard.js',
    'binary-payment-controller.js',
    'payment-handoff-ambiguity-guard.js',
] as $runtimeAsset) {
    assertShellContract(
        str_contains($assets, "'views/js/{$runtimeAsset}'"),
        sprintf('shell-owned runtime manifest must retain %s', $runtimeAsset),
    );
}
assertShellContract(is_string($module) && str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'), 'production readiness gate must remain closed');

echo "CheckoutShellContractSmokeTest OK\n";
