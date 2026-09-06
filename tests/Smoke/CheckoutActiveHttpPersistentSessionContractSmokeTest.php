<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$http = file_get_contents($root . '/tests/Runtime/ActiveCheckoutFallbackHttpContract.php');

if (!is_string($http) || $http === '') {
    fwrite(STDERR, "FAIL: active fallback HTTP runtime source must be readable\n");
    exit(1);
}

function assertActiveHttpPersistentSession(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertActiveHttpPersistentSession(
    str_contains($http, 'final class ActiveCheckoutHttpSession')
        && substr_count($http, 'curl_init(') === 1
        && str_contains($http, "CURLOPT_COOKIEFILE => ''")
        && str_contains($http, 'CURLOPT_COOKIEJAR => $cookieJar')
        && str_contains($http, '$session = new ActiveCheckoutHttpSession($cookieJar);'),
    'fallback HTTP contract must reuse one libcurl cookie engine instead of rebuilding a session per request',
);

foreach ([
    '$add = $session->request($addUrl);',
    "$healthy = \$session->request(\$baseUrl . '/order');",
    "$fallback = \$session->request(\$baseUrl . '/order');",
    "$recovered = \$session->request(\$baseUrl . '/order');",
    "$modeFallback = \$session->request(\$baseUrl . '/order');",
    "$modeRecovered = \$session->request(\$baseUrl . '/order');",
] as $requestBoundary) {
    assertActiveHttpPersistentSession(
        str_contains($http, $requestBoundary),
        'cart seed, healthy checkout, injected failures and recovery must share the persistent HTTP session',
    );
}

assertActiveHttpPersistentSession(
    str_contains($http, '$session->cookies() !== []')
        && str_contains($http, 'did not establish any HTTP cookie in the persistent session'),
    'Core cart seeding must prove that the persistent HTTP session actually received cookie state before /order',
);

assertActiveHttpPersistentSession(
    str_contains($http, "'add' => 1")
        && str_contains($http, "'ajax' => 1")
        && str_contains($http, "'id_product' => \$productId")
        && str_contains($http, 'CURLOPT_USERAGENT'),
    'persistent session must still seed through Core AJAX cart mutation with browser-like traffic',
);

assertActiveHttpPersistentSession(
    !str_contains($http, 'validateOrder(')
        && !str_contains($http, 'INSERT INTO')
        && !str_contains($http, 'finalizationAction'),
    'persistent HTTP harness must remain outside order creation and finalization ownership',
);

fwrite(STDOUT, "Active HTTP persistent-session source contract OK.\n");
