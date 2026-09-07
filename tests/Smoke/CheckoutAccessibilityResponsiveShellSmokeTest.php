<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$shell = file_get_contents($root . '/views/templates/front/checkout-shell.tpl');
$css = file_get_contents($root . '/views/css/checkout.css');

function assertAccessibleShell(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertAccessibleShell(is_string($shell), 'checkout shell template must be readable');
assertAccessibleShell(is_string($css) && $css !== '', 'checkout stylesheet must be readable');

foreach ([
    'class="jzopc-checkout__main"',
    'class="jzopc-checkout__rail"',
    'id="jzopc-final-status"',
    'role="status"',
    'aria-live="polite"',
    'aria-atomic="true"',
    'aria-describedby="jzopc-final-status"',
] as $needle) {
    assertAccessibleShell(str_contains($shell, $needle), sprintf('checkout shell accessibility contract missing %s', $needle));
}

foreach ([
    '.jzopc-checkout :where(button, input, select, textarea, a):focus-visible',
    'outline: 3px solid var(--jzopc-primary)',
    'min-height: 2.75rem',
    '@media (min-width: 62rem)',
    'grid-template-columns: minmax(0, 1.65fr) minmax(18rem, 0.85fr)',
    '@media (max-width: 38rem)',
    '@media (prefers-reduced-motion: reduce)',
    'position: sticky',
    'max-width: 90rem',
] as $needle) {
    assertAccessibleShell(str_contains($css, $needle), sprintf('checkout responsive/accessibility stylesheet contract missing %s', $needle));
}

assertAccessibleShell(!str_contains($css, 'display: none;') || str_contains($css, '[hidden]'), 'stylesheet must not hide checkout controls outside the explicit hidden contract');
assertAccessibleShell(!str_contains($css, 'outline: none'), 'stylesheet must never remove keyboard focus indication');
assertAccessibleShell(!str_contains($css, 'pointer-events: none'), 'stylesheet must not silently disable Core/module-owned interactive payment controls');

fwrite(STDOUT, "Checkout accessibility/responsive shell smoke test passed.\n");
