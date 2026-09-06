<?php

declare(strict_types=1);

$targetRoot = rtrim((string) ($argv[1] ?? ''), DIRECTORY_SEPARATOR);

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
};

if (getenv('JZOPC_RUNTIME_ACTIVE_FIXTURE') !== '1') {
    $fail('Refusing to instrument active checkout fixture without JZOPC_RUNTIME_ACTIVE_FIXTURE=1.');
}

$resolvedRoot = $targetRoot !== '' ? realpath($targetRoot) : false;
if (!is_string($resolvedRoot)
    || ($resolvedRoot !== '/tmp/jzopc-active-fixture'
        && !str_starts_with($resolvedRoot, '/tmp/jzopc-active-fixture-'))) {
    $fail('Active checkout failure instrumentation is restricted to /tmp/jzopc-active-fixture*.');
}

$markers = [
    '.jzopc-runtime-failure-service',
    '.jzopc-runtime-failure-template',
    '.jzopc-runtime-failure-assets',
];
foreach ($markers as $marker) {
    if (file_exists($resolvedRoot . '/' . $marker)) {
        $fail(sprintf('Failure marker must not exist while instrumenting: %s', $marker), 3);
    }
}

/**
 * @param array{path:string,anchor:string,replacement:string,marker:string} $patch
 */
$applyPatch = static function (array $patch) use ($resolvedRoot, $fail): void {
    $path = $resolvedRoot . '/' . $patch['path'];
    $resolvedPath = realpath($path);
    if (!is_string($resolvedPath)
        || !str_starts_with($resolvedPath, $resolvedRoot . DIRECTORY_SEPARATOR)
        || is_link($path)) {
        $fail(sprintf('Refusing unsafe runtime fixture patch path: %s', $patch['path']), 3);
    }

    $source = file_get_contents($resolvedPath);
    if (!is_string($source) || $source === '') {
        $fail(sprintf('Runtime fixture source is unreadable: %s', $patch['path']), 3);
    }
    if (str_contains($source, $patch['marker'])) {
        $fail(sprintf('Runtime fixture source is already instrumented: %s', $patch['path']), 3);
    }
    if (substr_count($source, $patch['anchor']) !== 1) {
        $fail(sprintf('Runtime fixture patch anchor drifted: %s', $patch['path']), 3);
    }

    $updated = str_replace($patch['anchor'], $patch['replacement'], $source, $count);
    if ($count !== 1 || file_put_contents($resolvedPath, $updated) === false) {
        $fail(sprintf('Unable to instrument runtime fixture source: %s', $patch['path']), 3);
    }

    $verified = file_get_contents($resolvedPath);
    if (!is_string($verified) || substr_count($verified, $patch['marker']) !== 1) {
        $fail(sprintf('Runtime failure instrumentation verification failed: %s', $patch['path']), 3);
    }
};

$applyPatch([
    'path' => 'src/Integration/CheckoutShellRenderer.php',
    'marker' => '.jzopc-runtime-failure-service',
    'anchor' => <<<'PHP'
    public function render(\Context $context): string
    {
        $selections = $this->selectionsStore->load($context);
PHP,
    'replacement' => <<<'PHP'
    public function render(\Context $context): string
    {
        if (is_file(dirname(__DIR__, 2) . '/.jzopc-runtime-failure-service')) {
            throw new \RuntimeException('Injected active checkout shell service failure.');
        }

        $selections = $this->selectionsStore->load($context);
PHP,
]);

$applyPatch([
    'path' => 'src/Checkout/Rendering/PrestaShopCheckoutTemplateRenderer.php',
    'marker' => '.jzopc-runtime-failure-template',
    'anchor' => <<<'PHP'
        $smarty->assign($variables);
        $html = $smarty->fetch(self::TEMPLATE_PREFIX . $template);
PHP,
    'replacement' => <<<'PHP'
        $smarty->assign($variables);
        if (is_file(dirname(__DIR__, 3) . '/.jzopc-runtime-failure-template')) {
            $template = '__jzopc_runtime_missing_template__.tpl';
        }
        $html = $smarty->fetch(self::TEMPLATE_PREFIX . $template);
PHP,
]);

$applyPatch([
    'path' => 'src/Integration/CheckoutFrontendAssetRegistrar.php',
    'marker' => '.jzopc-runtime-failure-assets',
    'anchor' => <<<'PHP'
    public function register(\Context $context): void
    {
        $controller = $context->controller ?? null;
        if (!is_object($controller) || !is_callable([$controller, 'registerJavascript'])) {
PHP,
    'replacement' => <<<'PHP'
    public function register(\Context $context): void
    {
        if (is_file(dirname(__DIR__, 2) . '/.jzopc-runtime-failure-assets')) {
            throw new RuntimeException('Injected active checkout asset compatibility validation failure.');
        }

        $controller = $context->controller ?? null;
        if (!is_object($controller) || !is_callable([$controller, 'registerJavascript'])) {
PHP,
]);

fwrite(STDOUT, sprintf(
    "Active checkout failure instrumentation installed in %s; no failure marker is active.\n",
    $resolvedRoot,
));
