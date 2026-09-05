<?php

declare(strict_types=1);

$path = dirname(__DIR__, 2) . '/views/js/payment-controller.js';
$source = file_get_contents($path);

assert(is_string($source) && $source !== '');
assert(str_contains($source, 'class JzOpcPaymentController'));
assert(str_contains($source, "'jzopc:section:updated'"));
assert(str_contains($source, "'jzopc:payment:selected'"));
assert(str_contains($source, "'jzopc:payment:initialized'"));
assert(str_contains($source, 'AbortController'));
assert(str_contains($source, 'existing.destroy()'));
assert(str_contains($source, 'getSelectedPaymentForm()'));
assert(!str_contains($source, '.submit()'));
assert(!str_contains($source, '.requestSubmit()'));

echo "CheckoutPaymentJavascriptContractSmokeTest OK\n";
