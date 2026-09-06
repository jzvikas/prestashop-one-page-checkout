<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$http = file_get_contents($root . '/tests/Runtime/ActiveCheckoutFallbackHttpContract.php');

if (!is_string($http) || $http === '') {
    fwrite(STDERR, "FAIL: active fallback HTTP runtime source must be readable\n");
    exit(1);
}

function assertExplicitBodyCapture(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertExplicitBodyCapture(
    str_contains($http, '$handle = curl_init($url);')
        && str_contains($http, 'CURLOPT_NOBODY => false')
        && str_contains($http, 'CURLOPT_HEADER => false')
        && str_contains($http, 'CURLOPT_RETURNTRANSFER => true')
        && str_contains($http, 'CURLOPT_HTTPGET => true')
        && str_contains($http, '$body = curl_exec($handle);')
        && str_contains($http, '!is_string($body)')
        && !str_contains($http, 'CURLOPT_WRITEFUNCTION'),
    'each isolated fallback HTTP request must use libcurl standard returned-body GET semantics rather than a custom write callback',
);

assertExplicitBodyCapture(
    str_contains($http, "defined('CURLINFO_EFFECTIVE_METHOD')")
        && str_contains($http, 'CURLINFO_EFFECTIVE_METHOD')
        && str_contains($http, "strtoupper(\$effectiveMethod) !== 'GET'")
        && str_contains($http, "'effective_method' => \$effectiveMethod"),
    'runtime request diagnostics must verify the effective request method when supported by libcurl',
);

assertExplicitBodyCapture(
    str_contains($http, 'CURLINFO_SIZE_DOWNLOAD')
        && str_contains($http, 'CURLINFO_CONTENT_LENGTH_DOWNLOAD')
        && str_contains($http, "'transfer_bytes' => \$transferBytes")
        && str_contains($http, "'content_length' => \$contentLength"),
    'isolated requests must retain structural transfer-size evidence for diagnostics',
);

assertExplicitBodyCapture(
    str_contains($http, 'status=%d method=%s path=%s content_type=%s captured_bytes=%d transfer_bytes=%d content_length=%d')
        && !str_contains($http, "fwrite(STDERR, \$response['body'])")
        && !str_contains($http, 'Set-Cookie:')
        && !str_contains($http, 'Authorization:'),
    'runtime diagnostics must compare returned and libcurl byte counts without logging response bodies or credential-bearing headers',
);

assertExplicitBodyCapture(
    !str_contains($http, 'validateOrder(')
        && !str_contains($http, 'INSERT INTO')
        && !str_contains($http, 'finalizationAction'),
    'body-capture diagnostics must stay outside payment-module/Core order creation and OPC finalization ownership',
);

fwrite(STDOUT, "Active HTTP returned-body capture source contract OK.\n");