<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php FailClosedHttpContract.php <base-url>\n");
    exit(2);
}

$baseUrl = rtrim((string) $argv[1], '/');
if (!preg_match('#^https?://#i', $baseUrl)) {
    fwrite(STDERR, "Base URL must be absolute.\n");
    exit(2);
}

/**
 * @return array{status:int,content_type:string,body:string,effective_url:string}
 */
function request(string $url, string $method = 'GET', array $fields = []): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json,text/html;q=0.9,*/*;q=0.8',
            'X-Requested-With: XMLHttpRequest',
        ],
    ];

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded;charset=UTF-8';
    }

    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    if (!is_string($body)) {
        $error = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('HTTP request failed: ' . $error);
    }

    $result = [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'content_type' => (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE),
        'body' => $body,
        'effective_url' => (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
    ];
    curl_close($handle);

    return $result;
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $order = request($baseUrl . '/order');
    expect($order['status'] >= 200 && $order['status'] < 500, 'Core /order must remain reachable while OPC readiness is closed.');
    expect(!str_contains($order['body'], 'data-jzopc-checkout'), 'Fail-closed /order response unexpectedly contains the custom OPC root.');
    expect(!str_contains($order['body'], '/modules/jzonepagecheckout/views/js/'), 'Fail-closed /order response unexpectedly contains OPC JavaScript assets.');
    expect(!str_contains($order['body'], '/modules/jzonepagecheckout/views/css/'), 'Fail-closed /order response unexpectedly contains OPC CSS assets.');

    $finalize = request(
        $baseUrl . '/module/jzonepagecheckout/finalize',
        'POST',
        [
            'cartId' => '1',
            'token' => 'invalid-test-token',
            'stateVersion' => 'invalid-test-state',
            'submissionAttempt' => str_repeat('a', 32),
            'finalizationAction' => 'begin',
        ],
    );

    expect($finalize['status'] === 404, sprintf('Inactive finalization endpoint must return HTTP 404, got %d.', $finalize['status']));
    $payload = json_decode($finalize['body'], true, flags: JSON_THROW_ON_ERROR);
    expect(is_array($payload), 'Inactive finalization response must be JSON.');
    expect(($payload['success'] ?? null) === false, 'Inactive finalization response must be unsuccessful.');
    expect(is_array($payload['errors'] ?? null), 'Inactive finalization response must expose the stable errors array.');
    expect(($payload['errors'][0]['code'] ?? null) === 'checkout_unavailable', 'Inactive finalization endpoint must fail with checkout_unavailable.');
    expect(!str_contains($finalize['body'], 'invalid-test-token'), 'Inactive endpoint response leaked submitted token material.');
    expect(!str_contains($finalize['body'], 'invalid-test-state'), 'Inactive endpoint response leaked submitted state material.');

    fwrite(STDOUT, "Fail-closed HTTP contract completed successfully.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
