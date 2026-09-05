<?php

declare(strict_types=1);

$path = dirname(__DIR__, 2) . '/views/js/checkout-mutation-client.js';
$source = file_get_contents($path);

function assertMutationJavascript(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertMutationJavascript(is_string($source) && $source !== '', 'mutation client source must be readable');
assertMutationJavascript(str_contains($source, 'class JzOpcMutationClient'), 'mutation client class is required');
assertMutationJavascript(str_contains($source, "'[data-jzopc-checkout]'"), 'client must mount only inside module checkout root');
assertMutationJavascript(str_contains($source, 'jzopcAddressUrl'), 'trusted bootstrap must require address endpoint');
assertMutationJavascript(str_contains($source, "body.set('token', this.csrfToken)"), 'every mutation must send CSRF');
assertMutationJavascript(str_contains($source, "body.set('cartId', this.cartId)"), 'every mutation must send cart binding');
assertMutationJavascript(str_contains($source, "body.set('stateVersion', this.stateVersion)"), 'every mutation must send prior state version');
assertMutationJavascript(str_contains($source, "useSameAddress: sameAddressInput.checked ? '1' : '0'"), 'address request must send explicit invoice mode');
assertMutationJavascript(str_contains($source, 'payload.deliveryAddressId = deliveryInput.value'), 'address request must send selected delivery address');
assertMutationJavascript(str_contains($source, 'payload.invoiceAddressId = invoiceInput.value'), 'separate invoice mode must send selected invoice address');
assertMutationJavascript(str_contains($source, 'this.mutate(this.addressUrl, payload)'), 'all address inputs must use one atomic address endpoint');
assertMutationJavascript(str_contains($source, "'jzopc:address:selected'"), 'address lifecycle event must be published');
assertMutationJavascript(str_contains($source, "paymentOptionId: detail.optionId"), 'payment option id must remain in payment intent');
assertMutationJavascript(str_contains($source, "paymentModule: detail.moduleName"), 'payment module must remain in payment intent');
assertMutationJavascript(str_contains($source, "this.mutate(this.agreementsUrl, { agreements })"), 'agreement mutation contract must remain intact');
assertMutationJavascript(str_contains($source, 'AbortController'), 'request cancellation guard is required');
assertMutationJavascript(str_contains($source, 'sequence !== this.latestSequence'), 'latest-sequence race guard is required');
assertMutationJavascript(str_contains($source, "error.code === 'stale_state'"), 'bounded stale-state recovery is required');
assertMutationJavascript(str_contains($source, 'prepareSectionReplacements(payload.sections)'), 'all sections must be prevalidated before DOM writes');
assertMutationJavascript(str_contains($source, "'jzopc:section:updated'"), 'section reinitialization lifecycle is required');
assertMutationJavascript(str_contains($source, "'jzopc:checkout:validation-failed'"), 'server validation lifecycle is required');
assertMutationJavascript(str_contains($source, "'jzopc:checkout:error'"), 'transport error lifecycle is required');
assertMutationJavascript(!str_contains($source, 'innerHTML = payload'), 'unverified response payload must never be written directly to DOM');

echo "CheckoutMutationJavascriptContractSmokeTest OK\n";
