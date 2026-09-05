<?php

declare(strict_types=1);

$path = dirname(__DIR__, 2) . '/views/js/checkout-mutation-client.js';
$source = file_get_contents($path);

assert(is_string($source) && $source !== '');
assert(str_contains($source, 'class JzOpcMutationClient'));
assert(str_contains($source, "'[data-jzopc-checkout]'"));
assert(str_contains($source, "body.set('token', this.csrfToken)"));
assert(str_contains($source, "body.set('cartId', this.cartId)"));
assert(str_contains($source, "body.set('stateVersion', this.stateVersion)"));
assert(str_contains($source, "paymentOptionId: detail.optionId"));
assert(str_contains($source, "paymentModule: detail.moduleName"));
assert(str_contains($source, "this.mutate(this.agreementsUrl, { agreements })"));
assert(str_contains($source, 'AbortController'));
assert(str_contains($source, 'sequence !== this.latestSequence'));
assert(str_contains($source, "error.code === 'stale_state'"));
assert(str_contains($source, 'prepareSectionReplacements(payload.sections)'));
assert(str_contains($source, "'jzopc:section:updated'"));
assert(str_contains($source, "'jzopc:checkout:validation-failed'"));
assert(str_contains($source, "'jzopc:checkout:error'"));
assert(!str_contains($source, 'innerHTML = payload'));

echo "CheckoutMutationJavascriptContractSmokeTest OK\n";
