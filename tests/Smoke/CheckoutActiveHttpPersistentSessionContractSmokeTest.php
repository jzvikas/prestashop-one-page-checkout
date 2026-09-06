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
        && str_contains($http, 'private array $cookies = [];')
        && str_contains($http, '$handle = curl_init($url);')
        && str_contains($http, "CURLOPT_COOKIEFILE => ''")
        && str_contains($http, 'CURLOPT_COOKIELIST, $cookie')
        && str_contains($http, 'CURLINFO_COOKIELIST')
        && str_contains($http, 'curl_close($handle);')
        && str_contains($http, '$session = new ActiveCheckoutHttpSession();'),
    'fallback HTTP contract must isolate every transfer while explicitly carrying only libcurl cookie/session state between requests',
);

assertActiveHttpPersistentSession(
    substr_count($http, 'curl_init($url);') === 1
        && !str_contains($http, 'private $handle;')
        && !str_contains($http, 'CURLOPT_COOKIEJAR')
        && !str_contains($http, 'tempnam('),
    'fallback session must not reuse a CurlHandle or depend on a disk cookie jar across request boundaries',
);

foreach ([
    '$add = $session->request($addUrl);',
    "\$healthy = \$session->request(\$baseUrl . '/order');",
    "\$fallback = \$session->request(\$baseUrl . '/order');",
    "\$recovered = \$session->request(\$baseUrl . '/order');",
    "\$modeFallback = \$session->request(\$baseUrl . '/order');",
    "\$modeRecovered = \$session->request(\$baseUrl . '/order');",
] as $requestBoundary) {
    assertActiveHttpPersistentSession(
        str_contains($http, $requestBoundary),
        'cart seed, healthy checkout, injected failures and recovery must share the carried Core HTTP session',
    );
}

assertActiveHttpPersistentSession(
    str_contains($http, '$session->cookies() !== []')
        && str_contains($http, 'did not establish any HTTP cookie in the carried session'),
    'Core cart seeding must prove that cookie state exists before the first /order request',
);

assertActiveHttpPersistentSession(
    str_contains($http, "'add' => 1")
        && str_contains($http, "'ajax' => 1")
        && str_contains($http, "'id_product' => \$productId")
        && str_contains($http, 'CURLOPT_USERAGENT'),
    'session must still seed through Core AJAX cart mutation with browser-like traffic',
);

assertActiveHttpPersistentSession(
    !str_contains($http, 'validateOrder(')
        && !str_contains($http, 'INSERT INTO')
        && !str_contains($http, 'finalizationAction'),
    'fallback HTTP harness must remain outside order creation and finalization ownership',
);

fwrite(STDOUT, "Active HTTP isolated-session source contract OK.\n");