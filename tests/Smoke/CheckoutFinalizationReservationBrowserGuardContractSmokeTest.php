<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$guard = file_get_contents($root . '/views/js/payment-handoff-ambiguity-guard.js');
$binary = file_get_contents($root . '/views/js/binary-payment-controller.js');
$shell = file_get_contents($root . '/views/templates/front/checkout-shell.tpl');
$assets = file_get_contents($root . '/src/Integration/CheckoutFrontendAssetRegistrar.php');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertFinalizationReservationBrowserGuard(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$guard, $binary, $shell, $assets, $module] as $source) {
    assertFinalizationReservationBrowserGuard(is_string($source) && $source !== '', 'reservation browser guard sources must be readable');
}

assertFinalizationReservationBrowserGuard(
    str_contains($shell, 'data-jzopc-finalization-reserved="{if $jzopc_finalization_reserved}1{else}0{/if}"'),
    'trusted shell must expose only the server-authoritative active-reservation boolean',
);
assertFinalizationReservationBrowserGuard(
    str_contains($shell, 'data-jzopc-final-message="handoff-ambiguous"'),
    'reserved or ambiguous checkout lock must use translated server markup',
);
assertFinalizationReservationBrowserGuard(
    str_contains($guard, "root.dataset.jzopcFinalizationReserved !== '1'")
        && str_contains($guard, 'lockServerReservedCheckout(document);'),
    'a page reload with a live server reservation must start fail-closed',
);
assertFinalizationReservationBrowserGuard(
    str_contains($guard, "const FINALIZATION_IN_PROGRESS_CODE = 'finalization_in_progress';")
        && str_contains($guard, "document.addEventListener('jzopc:checkout:validation-failed'")
        && str_contains($guard, "root.dataset.jzopcFinalizationReserved = '1';"),
    'same-tab finalization_in_progress rejection must become a remembered local lock',
);
assertFinalizationReservationBrowserGuard(
    str_contains($guard, "document.addEventListener('click', suppressLockedActivation, true)")
        && str_contains($guard, "document.addEventListener('submit', suppressLockedActivation, true)")
        && str_contains($guard, "const NON_FORM_ACTIVATION_SELECTOR = 'a[href], [role=\"button\"]';"),
    'locked reservation surface must suppress form and link-style activation before module handlers',
);
assertFinalizationReservationBrowserGuard(
    str_contains($guard, 'Promise.resolve().then(function ()')
        && str_contains($guard, "root.setAttribute('data-jzopc-payment-handoff-ambiguous', 'true')")
        && str_contains($guard, 'control.disabled = true'),
    'ambiguous lock must run after synchronous controller cleanup and freeze native controls',
);
assertFinalizationReservationBrowserGuard(
    str_contains($binary, 'let nativeActivationStarted = false;')
        && str_contains($binary, "if (!nativeActivationStarted && error && error.name === 'AbortError')")
        && substr_count($binary, 'nativeActivationStarted = true;') >= 2,
    'AbortError may bypass failure handling only before native binary activation starts',
);
assertFinalizationReservationBrowserGuard(
    str_contains($binary, "this.dispatch('jzopc:checkout:validation-failed'")
        && str_contains($binary, "this.dispatch('jzopc:checkout:payment-handoff-ambiguous'"),
    'binary finalization must publish server rejection and post-activation ambiguity lifecycles',
);
assertFinalizationReservationBrowserGuard(
    str_contains($assets, 'views/js/payment-handoff-ambiguity-guard.js'),
    'reservation ambiguity guard must be registered with checkout assets',
);
assertFinalizationReservationBrowserGuard(
    !str_contains($guard, 'validateOrder(') && !str_contains($binary, 'validateOrder('),
    'browser reservation hardening must never create PrestaShop orders',
);
assertFinalizationReservationBrowserGuard(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'),
    'production checkout readiness gate must remain closed',
);

fwrite(STDOUT, "Checkout finalization reservation browser guard contract smoke tests passed.\n");
