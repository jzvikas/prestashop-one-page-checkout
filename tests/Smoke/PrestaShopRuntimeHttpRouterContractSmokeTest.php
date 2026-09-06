<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$router = file_get_contents($root . '/tests/Runtime/prestashop-http-router.php');
$workflow = file_get_contents($root . '/.github/workflows/prestashop-runtime.yml');

function assertRuntimeHttpRouter(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertRuntimeHttpRouter(is_string($router) && $router !== '', 'runtime HTTP router must be readable');
assertRuntimeHttpRouter(is_string($workflow) && $workflow !== '', 'runtime workflow must be readable');

assertRuntimeHttpRouter(
    str_contains($router, "getenv('JZOPC_PRESTASHOP_ROOT')")
        && str_contains($router, "is_file(\$root . '/index.php')"),
    'runtime router must require an explicit installed PrestaShop root',
);
assertRuntimeHttpRouter(
    str_contains($router, "in_array('..', \$segments, true)")
        && str_contains($router, 'str_contains($decodedPath, "\\0")'),
    'runtime router must refuse traversal-like paths from the static-file branch',
);
assertRuntimeHttpRouter(
    str_contains($router, "in_array(\$method, ['GET', 'HEAD'], true)")
        && str_contains($router, 'if (is_file($candidate))')
        && str_contains($router, 'return false;'),
    'only existing GET/HEAD resources may bypass the PrestaShop front controller for direct static serving',
);
assertRuntimeHttpRouter(
    str_contains($router, "require \$root . '/index.php';")
        && str_contains($router, 'return true;'),
    'dynamic and missing resources must continue through the real PrestaShop Front Office entry point',
);
assertRuntimeHttpRouter(
    substr_count($workflow, 'php -S 127.0.0.1:8080 -t /tmp/prestashop') === 2
        && substr_count($workflow, 'tests/Runtime/prestashop-http-router.php') === 2
        && substr_count($workflow, 'JZOPC_PRESTASHOP_ROOT: /tmp/prestashop') >= 2,
    'both closed and active runtime HTTP servers must use the same static-aware router even when later browser fixture steps also receive the shop-root environment',
);
assertRuntimeHttpRouter(
    !str_contains($workflow, '-t /tmp/prestashop /tmp/prestashop/index.php'),
    'runtime workflow must not use the PrestaShop entry point as an unconditional PHP development-server router',
);

fwrite(STDOUT, "PrestaShop runtime HTTP router source contract OK.\n");
