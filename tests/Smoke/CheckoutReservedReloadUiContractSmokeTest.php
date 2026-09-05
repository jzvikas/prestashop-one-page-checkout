<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$renderer = file_get_contents($root . '/src/Integration/CheckoutShellRenderer.php');
$template = file_get_contents($root . '/views/templates/front/checkout-shell.tpl');
$guard = file_get_contents($root . '/views/js/payment-handoff-ambiguity-guard.js');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertReservedReloadUiContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$renderer, $template, $guard, $module] as $source) {
    assertReservedReloadUiContract(is_string($source), 'reserved reload UI contract source must be readable');
}

assertReservedReloadUiContract(
    str_contains($renderer, 'CheckoutFinalizationReservationStoreInterface $finalizationReservationStore'),
    'checkout shell renderer must receive the authoritative finalization reservation store'
);
assertReservedReloadUiContract(
    str_contains($renderer, "'jzopc_finalization_reserved' => $this->finalizationReservationStore->isActive($context)"),
    'checkout shell must derive reservation state from server persistence at render time'
);
assertReservedReloadUiContract(
    str_contains($template, 'data-jzopc-finalization-reserved="{if $jzopc_finalization_reserved}1{else}0{/if}"'),
    'trusted checkout shell must expose only a boolean reserved marker, not reservation secrets'
);
assertReservedReloadUiContract(
    str_contains($guard, "root.dataset.jzopcFinalizationReserved !== '1'"),
    'browser guard must lock only when the trusted server marker reports an active reservation'
);
assertReservedReloadUiContract(
    str_contains($guard, 'lockServerReservedCheckout(document);'),
    'active server reservation must lock the checkout immediately on initial browser load'
);
assertReservedReloadUiContract(
    str_contains($guard, "root.setAttribute('data-jzopc-payment-handoff-ambiguous', 'true')"),
    'server-reserved reload must reuse the same fail-closed payment ambiguity state'
);
assertReservedReloadUiContract(
    str_contains($guard, 'control.disabled = true'),
    'server-reserved reload must disable mutable checkout controls'
);
assertReservedReloadUiContract(
    !str_contains($guard, 'finalizationAction') && !str_contains($guard, 'validateOrder('),
    'reload guard must never release reservations or create orders'
);
assertReservedReloadUiContract(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'),
    'reserved reload hardening must not open the production readiness gate'
);

fwrite(STDOUT, "Checkout reserved reload UI contract smoke tests passed.\n");
