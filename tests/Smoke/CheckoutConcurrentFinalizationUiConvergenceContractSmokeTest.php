<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$guard = file_get_contents($root . '/views/js/payment-handoff-ambiguity-guard.js');
$binary = file_get_contents($root . '/views/js/binary-payment-controller.js');
$ordinary = file_get_contents($root . '/views/js/final-submit-controller.js');
$mutations = file_get_contents($root . '/views/js/checkout-mutation-client.js');
$finalization = file_get_contents($root . '/src/Checkout/Mutation/CheckoutFinalizationMutation.php');
$blockReason = file_get_contents($root . '/src/Security/CheckoutMutationBlockReason.php');
$responseMapper = file_get_contents($root . '/src/Http/CheckoutMutationResponseMapper.php');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertConcurrentFinalizationUiContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$guard, $binary, $ordinary, $mutations, $finalization, $blockReason, $responseMapper, $module] as $source) {
    assertConcurrentFinalizationUiContract(is_string($source), 'concurrent finalization UI contract source must be readable');
}

assertConcurrentFinalizationUiContract(
    str_contains($blockReason, "case FinalizationInProgress = 'finalization_in_progress';"),
    'mutation guard must expose a stable finalization_in_progress machine code'
);
assertConcurrentFinalizationUiContract(
    str_contains($responseMapper, 'CheckoutMutationBlockReason::FinalizationInProgress')
        && str_contains($responseMapper, "'Order submission is already in progress for this cart. Please wait before changing checkout details.'"),
    'ordinary checkout mutations must map active reservations to a non-retryable server rejection'
);
assertConcurrentFinalizationUiContract(
    str_contains($finalization, "'finalization_in_progress'")
        && str_contains($finalization, 'CheckoutFinalizationReservationAlreadyActive'),
    'competing finalization attempts must return the same stable machine code'
);

assertConcurrentFinalizationUiContract(
    str_contains($mutations, "this.dispatch('jzopc:checkout:validation-failed'"),
    'generic mutation client must publish guarded server validation failures'
);
assertConcurrentFinalizationUiContract(
    str_contains($ordinary, "this.dispatch('jzopc:checkout:validation-failed'"),
    'ordinary final submit must publish finalization reservation conflicts'
);
assertConcurrentFinalizationUiContract(
    str_contains($binary, "this.dispatch('jzopc:checkout:validation-failed'")
        && str_contains($binary, 'errors: payload.errors'),
    'binary final submit must publish the same validation lifecycle before its failure cleanup'
);

assertConcurrentFinalizationUiContract(
    str_contains($guard, "const FINALIZATION_IN_PROGRESS_CODE = 'finalization_in_progress';"),
    'browser guard must key convergence to the exact server machine code'
);
assertConcurrentFinalizationUiContract(
    str_contains($guard, "document.addEventListener('jzopc:checkout:validation-failed'")
        && str_contains($guard, 'hasErrorCode(event, FINALIZATION_IN_PROGRESS_CODE)'),
    'browser guard must consume finalization reservation conflicts from every validation lifecycle publisher'
);
assertConcurrentFinalizationUiContract(
    str_contains($guard, "root.dataset.jzopcFinalizationReserved = '1';"),
    'a live server reservation conflict must converge the current page to its reserved boolean state'
);
assertConcurrentFinalizationUiContract(
    str_contains($guard, 'Promise.resolve().then(function ()')
        && str_contains($guard, 'lockAmbiguousCheckout(root);'),
    'reservation-conflict locking must happen after synchronous controller failure cleanup'
);
assertConcurrentFinalizationUiContract(
    str_contains($guard, "control.disabled = true")
        && str_contains($guard, "root.setAttribute('aria-busy', 'true')"),
    'converged tabs must remain visibly and accessibly fail closed'
);
assertConcurrentFinalizationUiContract(
    !str_contains($guard, 'fetch(')
        && !str_contains($guard, 'finalizationAction')
        && !str_contains($guard, 'validateOrder('),
    'browser convergence guard must not release reservations, submit payment or create orders'
);
assertConcurrentFinalizationUiContract(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'),
    'production readiness gate must remain closed while concurrent-tab browser evidence is pending'
);

fwrite(STDOUT, "Checkout concurrent finalization UI convergence contract smoke tests passed.\n");
