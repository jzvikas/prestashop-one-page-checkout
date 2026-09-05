<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutServerSelectionsSchema;

if ($argc < 4) {
    fwrite(STDERR, "Usage: php ActiveCheckoutFallbackHttpContract.php <base-url> <shop-root> <product-id>\n");
    exit(2);
}

$baseUrl = rtrim((string) $argv[1], '/');
$shopRoot = rtrim((string) $argv[2], '/');
$productId = (int) $argv[3];

if (!preg_match('#^https?://#i', $baseUrl)) {
    fwrite(STDERR, "Base URL must be absolute.\n");
    exit(2);
}
if ($shopRoot === '' || !is_file($shopRoot . '/config/config.inc.php')) {
    fwrite(STDERR, "Installed PrestaShop root is missing or invalid.\n");
    exit(2);
}
if ($productId <= 0) {
    fwrite(STDERR, "Runtime product ID must be positive.\n");
    exit(2);
}

require_once $shopRoot . '/config/config.inc.php';
require_once $shopRoot . '/modules/jzonepagecheckout/jzonepagecheckout.php';

$modulePath = realpath($shopRoot . '/modules/jzonepagecheckout/jzonepagecheckout.php');
if (!is_string($modulePath) || !str_starts_with($modulePath, '/tmp/jzopc-active-fixture')) {
    fwrite(STDERR, "Active HTTP fallback contract refuses to run against the production/source module tree.\n");
    exit(2);
}

$cookieJar = tempnam(sys_get_temp_dir(), 'jzopc-http-cookie-');
if (!is_string($cookieJar) || $cookieJar === '') {
    fwrite(STDERR, "Unable to create runtime HTTP cookie jar.\n");
    exit(2);
}

/**
 * @return array{status:int,body:string,effective_url:string,content_type:string}
 */
function activeCheckoutRequest(string $url, string $cookieJar): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 JzOpcRuntime/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
        ],
    ]);

    $body = curl_exec($handle);
    if (!is_string($body)) {
        $error = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('HTTP request failed: ' . $error);
    }

    $result = [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'body' => $body,
        'effective_url' => (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
        'content_type' => (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE),
    ];
    curl_close($handle);

    return $result;
}

function expectActiveHttp(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectHealthyOpc(array $response, string $stage): void
{
    expectActiveHttp(
        $response['status'] >= 200 && $response['status'] < 400,
        sprintf('%s healthy checkout must return/resolve successfully, got HTTP %d.', $stage, $response['status']),
    );
    expectActiveHttp(
        str_contains($response['body'], 'data-jzopc-checkout'),
        sprintf('%s healthy checkout did not render the active OPC root.', $stage),
    );
    expectActiveHttp(
        str_contains($response['body'], 'data-jzopc-finalization-url='),
        sprintf('%s healthy checkout did not render finalization bootstrap.', $stage),
    );
    expectActiveHttp(
        str_contains($response['body'], 'data-jzopc-finalization-reserved="0"'),
        sprintf('%s fresh checkout unexpectedly rendered an active finalization reservation.', $stage),
    );
}

function expectNativeFallback(array $response, string $stage): void
{
    expectActiveHttp(
        $response['status'] >= 200 && $response['status'] < 400,
        sprintf('%s native fallback must return/resolve successfully, got HTTP %d.', $stage, $response['status']),
    );
    expectActiveHttp(
        !str_contains($response['body'], 'data-jzopc-checkout'),
        sprintf('%s fallback unexpectedly rendered the OPC root.', $stage),
    );
    expectActiveHttp(
        str_contains($response['body'], 'id="checkout-personal-information-step"'),
        sprintf('%s fallback did not render Core personal-information checkout step.', $stage),
    );
}

$schema = new CheckoutServerSelectionsSchema();
$schemaDropped = false;
$product = new Product($productId);

try {
    expectActiveHttp(Validate::isLoadedObject($product), 'Runtime checkout product is not loaded.');

    // Seed the browser/cart through the real Core CartController. A normal browser-like user-agent
    // is intentional: Core refuses to create ghost carts for bot traffic.
    $addUrl = $baseUrl . '/cart?' . http_build_query([
        'add' => 1,
        'id_product' => $productId,
        'qty' => 1,
    ], '', '&', PHP_QUERY_RFC3986);
    $add = activeCheckoutRequest($addUrl, $cookieJar);
    expectActiveHttp(
        $add['status'] >= 200 && $add['status'] < 400,
        sprintf('Core cart product-add request failed with HTTP %d.', $add['status']),
    );

    $healthy = activeCheckoutRequest($baseUrl . '/order', $cookieJar);
    expectHealthyOpc($healthy, 'Initial');

    // Inject a real module persistence failure. Shell preparation starts by loading canonical
    // server selections, so a missing module-owned table must be contained before process takeover.
    expectActiveHttp($schema->uninstall(), 'Unable to drop checkout-selection schema for failure injection.');
    $schemaDropped = true;

    $fallback = activeCheckoutRequest($baseUrl . '/order', $cookieJar);
    expectNativeFallback($fallback, 'Persistence-failure');

    // Restore the module-owned table and use the same browser/cart cookie. A fresh request must be
    // eligible for OPC again, proving the circuit breaker is request-local rather than sticky state.
    expectActiveHttp($schema->install(), 'Unable to restore checkout-selection schema after failure injection.');
    $schemaDropped = false;

    $recovered = activeCheckoutRequest($baseUrl . '/order', $cookieJar);
    expectHealthyOpc($recovered, 'Recovered');

    fwrite(STDOUT, sprintf(
        "Active checkout persistence fallback HTTP contract OK: product=%d, healthy=%d, fallback=%d, recovered=%d\n",
        $productId,
        $healthy['status'],
        $fallback['status'],
        $recovered['status'],
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($schemaDropped) {
        try {
            $schema->install();
        } catch (Throwable) {
        }
    }

    if (Validate::isLoadedObject($product)) {
        try {
            $product->delete();
        } catch (Throwable) {
        }
    }

    @unlink($cookieJar);
}
