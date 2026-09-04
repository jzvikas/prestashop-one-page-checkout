<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\CheckoutError;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationExecutionResult;
use Jzvikas\OnePageCheckout\Checkout\CheckoutRefreshResult;
use Jzvikas\OnePageCheckout\Checkout\CheckoutState;
use Jzvikas\OnePageCheckout\Checkout\CheckoutStateVersioner;
use Jzvikas\OnePageCheckout\Http\CheckoutMutationResponseMapper;
use Jzvikas\OnePageCheckout\Security\CheckoutMutationBlockReason;

function assertResponseMapper(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$versioner = new CheckoutStateVersioner();
$mapper = new CheckoutMutationResponseMapper($versioner);
$translate = static fn (string $message): string => 'T:' . $message;

$completed = $mapper->map(
    CheckoutMutationExecutionResult::completed(
        CheckoutRefreshResult::success('v1:ok', ['summary' => '<div>ok</div>'])
    ),
    $translate,
);
assertResponseMapper($completed->statusCode === 200, 'successful refresh must map to HTTP 200');
assertResponseMapper($completed->body['success'] === true, 'successful response body must remain successful');
assertResponseMapper($completed->body['retryable'] === false, 'completed response must not be marked retryable');

$validation = $mapper->map(
    CheckoutMutationExecutionResult::completed(
        CheckoutRefreshResult::failure(
            'v1:validation',
            [new CheckoutError('invalid_address', 'Translated address error.')],
        )
    ),
    $translate,
);
assertResponseMapper($validation->statusCode === 422, 'business validation failure must map to HTTP 422');
assertResponseMapper($validation->body['errors'][0]['code'] === 'invalid_address', 'handler error code must be preserved');

$currentState = new CheckoutState(
    shopId: 2,
    cartId: 42,
    customerId: 9,
    languageId: 1,
    currencyId: 3,
    deliveryAddressId: 11,
    invoiceAddressId: 12,
    carrierId: 7,
    selectedPaymentOption: null,
    approvedAgreementKeys: [],
    cartFingerprint: 'cart-fingerprint',
    totalsFingerprint: 'totals-fingerprint',
);
$stale = $mapper->map(
    CheckoutMutationExecutionResult::rejected(CheckoutMutationBlockReason::StaleState, $currentState),
    $translate,
);
assertResponseMapper($stale->statusCode === 409, 'stale state must map to HTTP 409');
assertResponseMapper($stale->body['retryable'] === true, 'stale state must be retryable after refresh/review');
assertResponseMapper($stale->body['stateVersion'] === $versioner->version($currentState), 'stale response must expose fresh opaque state version');
assertResponseMapper(str_starts_with($stale->body['errors'][0]['message'], 'T:'), 'generic guard message must pass through translator callback');

$csrf = $mapper->map(
    CheckoutMutationExecutionResult::rejected(CheckoutMutationBlockReason::InvalidCsrf),
    $translate,
);
assertResponseMapper($csrf->statusCode === 403, 'invalid CSRF must map to HTTP 403');
assertResponseMapper($csrf->body['stateVersion'] === null, 'invalid CSRF response must not invent a state version');
assertResponseMapper($csrf->body['retryable'] === false, 'invalid CSRF must require page/session recovery rather than blind retry');

$busy = $mapper->map(CheckoutMutationExecutionResult::busy(), $translate);
assertResponseMapper($busy->statusCode === 409, 'cart lock contention must map to HTTP 409');
assertResponseMapper($busy->body['errors'][0]['code'] === 'checkout_busy', 'busy response must have stable machine code');
assertResponseMapper($busy->body['retryable'] === true, 'busy checkout is retryable');

$json = $busy->toJson();
assertResponseMapper(str_contains($json, 'checkout_busy'), 'JSON response must encode stable error code');

fwrite(STDOUT, "Checkout mutation response mapper smoke tests passed.\n");
