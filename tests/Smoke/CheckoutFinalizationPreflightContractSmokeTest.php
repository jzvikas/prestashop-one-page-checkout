<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$preflight = file_get_contents($root . '/src/Checkout/Finalization/CheckoutFinalizationPreflightService.php');
$reservationUnavailable = file_get_contents($root . '/src/Checkout/Finalization/CheckoutFinalizationReservationUnavailable.php');
$reservationStore = file_get_contents($root . '/src/Infrastructure/Persistence/DbalCheckoutFinalizationReservationStore.php');
$reservationSchema = file_get_contents($root . '/src/Infrastructure/Persistence/CheckoutFinalizationReservationSchema.php');
$mutation = file_get_contents($root . '/src/Checkout/Mutation/CheckoutFinalizationMutation.php');
$orchestrator = file_get_contents($root . '/src/Checkout/CheckoutMutationOrchestrator.php');
$controller = file_get_contents($root . '/controllers/front/finalize.php');
$services = file_get_contents($root . '/config/common/services.yml');

function assertFinalizationContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$preflight, $reservationUnavailable, $reservationStore, $reservationSchema, $mutation, $orchestrator, $controller, $services] as $source) {
    assertFinalizationContract(is_string($source), 'finalization contract source must be readable');
}

assertFinalizationContract(str_contains($preflight, '$cart->OrderExists()'), 'preflight must reject carts that already produced an order');
assertFinalizationContract(str_contains($preflight, 'Order::getIdByCartId'), 'preflight must also check Core order lookup by cart');
assertFinalizationContract(str_contains($preflight, 'isAllProductsInStock()'), 'preflight must recheck stock eligibility');
assertFinalizationContract(str_contains($preflight, 'checkAllProductsAreStillAvailableInThisState()'), 'preflight must recheck current product availability');
assertFinalizationContract(str_contains($preflight, 'checkAllProductsHaveMinimalQuantities()'), 'preflight must recheck minimum quantities');
assertFinalizationContract(str_contains($preflight, 'checkCountriesAreEnabled()'), 'preflight must recheck country availability');
assertFinalizationContract(str_contains($preflight, "'minimalPurchaseRequired'"), 'preflight must enforce Core minimum-purchase presentation');
assertFinalizationContract(str_contains($preflight, 'Core cart presenter omitted required checkout field'), 'missing Core presenter fields must fail closed');
assertFinalizationContract(str_contains($preflight, 'Customer::customerHasAddress'), 'preflight must reauthorize delivery and invoice addresses');
assertFinalizationContract(str_contains($preflight, 'new \\AddressValidator()'), 'preflight must use Core address validation');
assertFinalizationContract(str_contains($preflight, '$this->carrierSelectionService->apply($context, $selection)'), 'physical checkout must revalidate persisted carrier against fresh Core options');
assertFinalizationContract(str_contains($preflight, '$this->paymentSelectionService->validate($context, $requested)'), 'payment eligibility must be regenerated immediately before handoff');
assertFinalizationContract(str_contains($preflight, '$this->agreementSelectionService->validate($context, $selections->approvedAgreementKeys)'), 'legal agreements must be regenerated immediately before handoff');

assertFinalizationContract(str_contains($reservationUnavailable, 'final class CheckoutFinalizationReservationUnavailable extends RuntimeException'), 'reservation storage uncertainty must have a dedicated domain error');
assertFinalizationContract(str_contains($reservationSchema, '`attempt_id` CHAR(32) NOT NULL'), 'reservation schema must bind an idempotent browser attempt');
assertFinalizationContract(str_contains($reservationStore, 'matchesAttempt('), 'same finalization attempt must be retryable idempotently');
assertFinalizationContract(str_contains($reservationStore, 'hash_equals(strtolower($storedAttempt), $attemptId)'), 'reservation attempt comparison must be constant-time');
assertFinalizationContract(str_contains($reservationStore, '(expires_at > UNIX_TIMESTAMP()) AS is_active'), 'reservation expiry checks must use the same DB clock as acquisition');
assertFinalizationContract(str_contains($reservationStore, 'EXPIRED_PURGE_LIMIT = 100'), 'abandoned finalization cleanup must remain bounded');
assertFinalizationContract(str_contains($reservationStore, "'DELETE FROM `%s` WHERE expires_at <= UNIX_TIMESTAMP() LIMIT %d'"), 'expired reservations must be opportunistically purged without a mandatory cron');
assertFinalizationContract(str_contains($reservationStore, 'releaseAttempt('), 'local payment handoff recovery must support attempt-scoped release');
assertFinalizationContract(str_contains($reservationStore, 'AND reservation.id_customer = ? AND reservation.attempt_id = ?'), 'release must never clear another customer/attempt reservation');
assertFinalizationContract(str_contains($reservationStore, 'AND NOT EXISTS (SELECT 1 FROM `%2$s` orders WHERE orders.id_cart = ?)'), 'release must atomically refuse deletion once Core has an order for the cart');
assertFinalizationContract(str_contains($reservationStore, "'INSERT INTO `%s`"), 'reservation acquisition must be database-backed');
assertFinalizationContract(str_contains($reservationStore, 'CheckoutFinalizationReservationAlreadyActive'), 'competing finalization attempts must fail closed');
assertFinalizationContract(str_contains($reservationStore, "'Checkout finalization reservation outcome is uncertain after a database write failure.'"), 'ambiguous reservation writes must be promoted to explicit storage uncertainty');
assertFinalizationContract(str_contains($reservationStore, "'Unable to prove checkout finalization reservation release safely.'"), 'release database failures must be explicit fail-closed storage uncertainty');
assertFinalizationContract(str_contains($reservationStore, 'throw $this->unavailable('), 'reservation storage failures must not leak as an unclassified database handoff outcome');

assertFinalizationContract(str_contains($mutation, 'CheckoutMutation::FinalizationStarted'), 'final preflight must run through the serialized checkout orchestrator');
assertFinalizationContract(str_contains($mutation, "'submissionAttempt'"), 'finalization request must carry an idempotency attempt identifier');
assertFinalizationContract(str_contains($mutation, "ACTION_BEGIN = 'begin'"), 'finalization begin action must be explicit');
assertFinalizationContract(str_contains($mutation, "ACTION_RELEASE = 'release'"), 'finalization recovery action must be explicit');
assertFinalizationContract(str_contains($mutation, '$this->reservationStore->releaseAttempt($context, $attemptId)'), 'release action must clear only the matching attempt inside the guarded critical section');
assertFinalizationContract(str_contains($mutation, 'catch (CheckoutFinalizationReservationUnavailable)'), 'reservation storage uncertainty must be handled at the finalization application boundary');
assertFinalizationContract(str_contains($mutation, "'finalization_unavailable'"), 'reservation uncertainty must produce a stable fail-closed checkout error code');
assertFinalizationContract(str_contains($mutation, 'Order submission safety could not be verified. Please wait and try again.'), 'reservation uncertainty must not be described to the shopper as a successful release or handoff');
assertFinalizationContract(str_contains($mutation, '$this->preflightService->validate($context, $currentSelections)'), 'preflight must execute inside the cart critical section');
assertFinalizationContract(str_contains($mutation, '$this->reservationStore->acquire('), 'successful preflight must acquire finalization reservation before returning');
assertFinalizationContract(str_contains($orchestrator, 'CheckoutMutationBlockReason::FinalizationInProgress'), 'normal OPC writes must be frozen while native payment handoff is reserved');
assertFinalizationContract(str_contains($orchestrator, '$mutation !== CheckoutMutation::FinalizationStarted'), 'idempotent finalization begin/release requests must be exempt from the generic mutation freeze');
assertFinalizationContract(str_contains($controller, 'extends JzOnePageCheckoutAbstractMutationModuleFrontController'), 'finalization endpoint must inherit shared POST and activation gates');
assertFinalizationContract(str_contains($services, "Checkout\\Mutation\\CheckoutFinalizationMutation:\n    public: true"), 'finalization mutation must be an intentional front-controller entry service');

fwrite(STDOUT, "Checkout finalization preflight contract smoke tests passed.\n");