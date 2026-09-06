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
    !str_contains($http, 'CURLOPT_RETURNTRANSFER')
        && str_contains($http, 'CURLOPT_WRITEFUNCTION')
        && str_contains($http, '$body .= $chunk;')
        && str_contains($http, 'return strlen($chunk);')
        && str_contains($http, '$executed !== true'),
    'fallback HTTP runtime must capture response bytes through an explicit write callback and fail on cURL execution errors',
);

assertExplicitBodyCapture(
    str_contains($http, 'CURLOPT_HTTPGET')
        && str_contains($http, 'CURLINFO_SIZE_DOWNLOAD')
        && str_contains($http, 'CURLINFO_CONTENT_LENGTH_DOWNLOAD')
        && str_contains($http, "'transfer_bytes' => \$transferBytes")
        && str_contains($http, "'content_length' => \$contentLength"),
    'each persistent-session request must restore GET semantics and retain structural transfer-size evidence',
);

assertExplicitBodyCapture(
    str_contains($http, 'captured_bytes=%d transfer_bytes=%d content_length=%d')
        && !str_contains($http, "fwrite(STDERR, \$response['body'])")
        && !str_contains($http, 'Set-Cookie:')
        && !str_contains($http, 'Authorization:'),
    'runtime diagnostics must compare captured and libcurl byte counts without logging response bodies or credential-bearing headers',
);

assertExplicitBodyCapture(
    !str_contains($http, 'validateOrder(')
        && !str_contains($http, 'INSERT INTO')
        && !str_contains($http, 'finalizationAction'),
    'body-capture diagnostics must stay outside payment-module/Core order creation and OPC finalization ownership',
);

fwrite(STDOUT, "Active HTTP explicit body-capture source contract OK.\n");