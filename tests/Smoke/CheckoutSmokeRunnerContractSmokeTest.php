<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runner = file_get_contents($root . '/scripts/run-smoke-tests.sh');
$workflow = file_get_contents($root . '/.github/workflows/ci.yml');

function assertSmokeRunnerContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertSmokeRunnerContract(is_string($runner) && $runner !== '', 'canonical smoke runner must be readable');
assertSmokeRunnerContract(is_string($workflow) && $workflow !== '', 'CI workflow must be readable');

assertSmokeRunnerContract(
    str_contains($runner, 'set -euo pipefail'),
    'smoke runner must fail immediately on command/test errors',
);
assertSmokeRunnerContract(
    str_contains($runner, 'shopt -s nullglob')
        && str_contains($runner, 'if ((${#smoke_tests[@]} == 0))')
        && str_contains($runner, 'exit 1'),
    'missing smoke tests must fail closed instead of producing a false green run',
);
assertSmokeRunnerContract(
    str_contains($runner, 'php -d zend.assertions=1 -d assert.exception=1 "$test"'),
    'legacy assert()-based smoke tests must execute with assertions and exceptions enabled',
);
assertSmokeRunnerContract(
    str_contains($workflow, 'run: bash scripts/run-smoke-tests.sh'),
    'CI must use the same canonical assertion-enabled smoke runner as local development',
);
assertSmokeRunnerContract(
    !str_contains($workflow, 'for test in tests/Smoke/*Test.php'),
    'CI must not duplicate a second smoke-loop implementation that can drift from the local runner',
);

fwrite(STDOUT, "Checkout smoke runner contract OK.\n");
