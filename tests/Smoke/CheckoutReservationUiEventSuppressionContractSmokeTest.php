<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$guard = file_get_contents($root . '/views/js/payment-handoff-ambiguity-guard.js');
$binary = file_get_contents($root . '/views/js/binary-payment-controller.js');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertReservationUiEventSuppressionContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$guard, $binary, $module] as $source) {
    assertReservationUiEventSuppressionContract(is_string($source), 'reservation UI event suppression source must be readable');
}

assertReservationUiEventSuppressionContract(
    str_contains($binary, "const ACTIVATION_SELECTOR = 'button, input[type=\"submit\"], input[type=\"button\"], a[href]';"),
    'binary payment compatibility surface must include link-style native activators'
);
assertReservationUiEventSuppressionContract(
    str_contains($guard, "const NON_FORM_ACTIVATION_SELECTOR = 'a[href], [role=\"button\"]';"),
    'locked checkout must identify non-form activation surfaces that cannot use the native disabled property'
);
assertReservationUiEventSuppressionContract(
    str_contains($guard, "activation.setAttribute('aria-disabled', 'true')")
        && str_contains($guard, "activation.setAttribute('tabindex', '-1')"),
    'locked non-form activators must be removed from normal keyboard activation and exposed as disabled'
);
assertReservationUiEventSuppressionContract(
    str_contains($guard, "document.addEventListener('click', suppressLockedActivation, true)")
        && str_contains($guard, "document.addEventListener('submit', suppressLockedActivation, true)"),
    'locked checkout must suppress click and form-submit activation in capture phase before payment-module handlers'
);
assertReservationUiEventSuppressionContract(
    str_contains($guard, "root.hasAttribute('data-jzopc-payment-handoff-ambiguous')")
        && str_contains($guard, 'event.preventDefault();')
        && str_contains($guard, 'event.stopImmediatePropagation();'),
    'event suppression must apply only to the explicit locked checkout state and stop handler/default activation'
);
assertReservationUiEventSuppressionContract(
    !str_contains($guard, 'fetch(')
        && !str_contains($guard, 'finalizationAction')
        && !str_contains($guard, 'validateOrder('),
    'event suppression guard must remain local-only and must not release, submit payment or create orders'
);
assertReservationUiEventSuppressionContract(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'),
    'production readiness gate must remain closed while locked-surface browser verification is pending'
);

fwrite(STDOUT, "Checkout reservation UI event suppression contract smoke tests passed.\n");
