<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Order lifecycle trace fixture is CLI-only.\n");
    exit(2);
}

if (getenv('JZOPC_RUNTIME_ACTIVE_FIXTURE') !== '1') {
    fwrite(STDERR, "Refusing order lifecycle trace instrumentation without JZOPC_RUNTIME_ACTIVE_FIXTURE=1.\n");
    exit(2);
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: InstrumentOrderLifecycleTraceFixture.php <active-fixture-root> <repository-root>\n");
    exit(2);
}

$targetRoot = realpath($argv[1]);
if (!is_string($targetRoot)
    || preg_match('#\A/tmp/jzopc-active-fixture(?:-[A-Za-z0-9._-]+)?\z#D', $targetRoot) !== 1) {
    fwrite(STDERR, "Order lifecycle trace target must be an existing /tmp/jzopc-active-fixture path.\n");
    exit(2);
}

$sourceRoot = realpath($argv[2]);
if (!is_string($sourceRoot) || $sourceRoot === $targetRoot
    || str_starts_with($sourceRoot, '/tmp/jzopc-active-fixture')) {
    fwrite(STDERR, "Order lifecycle trace source/target isolation is invalid.\n");
    exit(2);
}

$sourceModule = $sourceRoot . '/jzonepagecheckout.php';
$targetModule = $targetRoot . '/jzonepagecheckout.php';
$sourceText = is_file($sourceModule) ? file_get_contents($sourceModule) : false;
$sourceHashBefore = is_file($sourceModule) ? hash_file('sha256', $sourceModule) : false;
$targetSource = is_file($targetModule) ? file_get_contents($targetModule) : false;
if (!is_string($sourceText) || !is_string($sourceHashBefore)
    || !is_string($targetSource) || $targetSource === '') {
    fwrite(STDERR, "Order lifecycle trace module source is unavailable.\n");
    exit(3);
}

if (!str_contains($sourceText, 'private const INTEGRATION_SHELL_READY = false;')
    || str_contains($sourceText, 'JZOPC_RUNTIME_ORDER_HOOK')) {
    fwrite(STDERR, "Repository module source is not the expected closed, uninstrumented production source.\n");
    exit(3);
}

if (!str_contains($targetSource, 'private const INTEGRATION_SHELL_READY = true;')) {
    fwrite(STDERR, "Order lifecycle trace may instrument only the explicitly opened disposable fixture.\n");
    exit(3);
}

$replacements = [
    "    public function hookActionValidateOrderAfter(array \$params = []): void\n    {\n        \$cart = \$params['cart'] ?? null;" =>
        "    public function hookActionValidateOrderAfter(array \$params = []): void\n    {\n        error_log('JZOPC_RUNTIME_ORDER_HOOK phase=enter');\n\n        \$cart = \$params['cart'] ?? null;",
    "        if (!\$this->hasCreatedOrderForCart(\$params, \$cart)) {\n            return;\n        }\n\n        try {" =>
        "        if (!\$this->hasCreatedOrderForCart(\$params, \$cart)) {\n            error_log('JZOPC_RUNTIME_ORDER_HOOK phase=order_unproven');\n\n            return;\n        }\n\n        error_log('JZOPC_RUNTIME_ORDER_HOOK phase=order_proven');\n\n        try {\n            error_log('JZOPC_RUNTIME_ORDER_HOOK phase=cleanup_resolve');",
    "            if (!\$cleanup instanceof \\Jzvikas\\OnePageCheckout\\Checkout\\Finalization\\CheckoutOrderLifecycleCleanup) {\n                return;\n            }\n\n            \$cleanup->cleanupForCart(\$cart);" =>
        "            if (!\$cleanup instanceof \\Jzvikas\\OnePageCheckout\\Checkout\\Finalization\\CheckoutOrderLifecycleCleanup) {\n                error_log('JZOPC_RUNTIME_ORDER_HOOK phase=cleanup_unavailable');\n\n                return;\n            }\n\n            error_log('JZOPC_RUNTIME_ORDER_HOOK phase=cleanup_begin');\n            \$cleanup->cleanupForCart(\$cart);\n            error_log('JZOPC_RUNTIME_ORDER_HOOK phase=cleanup_end');",
    "        } catch (Throwable \$exception) {\n            \$this->logOrderCleanupFailure(\$exception, \$cart);\n        }\n    }\n\n    public function isCustomCheckoutActive(): bool" =>
        "        } catch (Throwable \$exception) {\n            error_log('JZOPC_RUNTIME_ORDER_HOOK phase=cleanup_exception');\n            \$this->logOrderCleanupFailure(\$exception, \$cart);\n        }\n\n        error_log('JZOPC_RUNTIME_ORDER_HOOK phase=exit');\n    }\n\n    public function isCustomCheckoutActive(): bool",
];

$updated = $targetSource;
foreach ($replacements as $needle => $replacement) {
    if (substr_count($updated, $needle) !== 1) {
        fwrite(STDERR, "Order lifecycle trace expected a unique fixture hook boundary.\n");
        exit(3);
    }

    $updated = str_replace($needle, $replacement, $updated, $count);
    if ($count !== 1) {
        fwrite(STDERR, "Order lifecycle trace could not instrument the expected fixture hook boundary.\n");
        exit(3);
    }
}

if (file_put_contents($targetModule, $updated) === false) {
    fwrite(STDERR, "Unable to write disposable order lifecycle trace fixture.\n");
    exit(3);
}

$sourceHashAfter = hash_file('sha256', $sourceModule);
if (!is_string($sourceHashAfter) || !hash_equals($sourceHashBefore, $sourceHashAfter)) {
    fwrite(STDERR, "Production module source changed while instrumenting disposable order lifecycle trace.\n");
    exit(3);
}

foreach ([
    'phase=enter',
    'phase=order_proven',
    'phase=cleanup_resolve',
    'phase=cleanup_begin',
    'phase=cleanup_end',
    'phase=cleanup_exception',
    'phase=exit',
] as $marker) {
    if (!str_contains($updated, 'JZOPC_RUNTIME_ORDER_HOOK ' . $marker)) {
        fwrite(STDERR, "Disposable order lifecycle trace is incomplete.\n");
        exit(3);
    }
}

fwrite(STDOUT, "Disposable order lifecycle hook trace instrumentation installed.\n");
