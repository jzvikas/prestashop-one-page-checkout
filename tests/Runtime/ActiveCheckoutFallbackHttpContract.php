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

$fixtureRoot = dirname($modulePath);
if ($fixtureRoot !== '/tmp/jzopc-active-fixture'
    && !str_starts_with($fixtureRoot, '/tmp/jzopc-active-fixture-')) {
    fwrite(STDERR, "Active HTTP fallback markers must live inside the temporary fixture root.\n");
    exit(2);
}

$failureMarkers = [
    'service' => $fixtureRoot . '/.jzopc-runtime-failure-service',
    'template' => $fixtureRoot . '/.jzopc-runtime-failure-template',
    'assets' => $fixtureRoot . '/.jzopc-runtime-failure-assets',
];
foreach ($failureMarkers as $mode => $markerPath) {
    if (file_exists($markerPath)) {
        fwrite(STDERR, sprintf("Runtime failure marker is unexpectedly active before the test: %s\n", $mode));
        exit(2);
    }
}

$cookieJar = tempnam(sys_get_temp_dir(), 'jzopc-http-cookie-');
if (!is_string($cookieJar) || $cookieJar === '') {
    fwrite(STDERR, "Unable to create runtime HTTP cookie jar.\n");
    exit(2);
}

final class ActiveCheckoutHttpSession
{
    /** @var CurlHandle */
    private $handle;

    public function __construct(string $cookieJar)
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL session.');
        }

        $this->handle = $handle;
        curl_setopt_array($this->handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_COOKIEJAR => $cookieJar,
            // Empty COOKIEFILE activates libcurl's in-memory cookie engine. The same CurlHandle is
            // intentionally reused for the entire contract, so cart/session identity cannot depend
            // on cookie-jar flush timing between separate handles.
            CURLOPT_COOKIEFILE => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 JzOpcRuntime/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
            ],
        ]);
    }

    /**
     * @return array{status:int,body:string,effective_url:string,content_type:string}
     */
    public function request(string $url): array
    {
        if (!curl_setopt($this->handle, CURLOPT_URL, $url)) {
            throw new RuntimeException('Unable to configure runtime HTTP request URL.');
        }

        $body = curl_exec($this->handle);
        if (!is_string($body)) {
            throw new RuntimeException('HTTP request failed: ' . curl_error($this->handle));
        }

        return [
            'status' => (int) curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE),
            'body' => $body,
            'effective_url' => (string) curl_getinfo($this->handle, CURLINFO_EFFECTIVE_URL),
            'content_type' => (string) curl_getinfo($this->handle, CURLINFO_CONTENT_TYPE),
        ];
    }

    /** @return list<string> */
    public function cookies(): array
    {
        $cookies = curl_getinfo($this->handle, CURLINFO_COOKIELIST);

        return is_array($cookies)
            ? array_values(array_filter($cookies, static fn ($cookie): bool => is_string($cookie) && $cookie !== ''))
            : [];
    }

    public function close(): void
    {
        if ($this->handle instanceof CurlHandle) {
            curl_close($this->handle);
        }
    }
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

function activateFailureMarker(string $markerPath, string $fixtureRoot, string $mode): void
{
    $expectedPath = $fixtureRoot . '/.jzopc-runtime-failure-' . $mode;
    expectActiveHttp(
        hash_equals($expectedPath, $markerPath),
        sprintf('Refusing unexpected runtime failure marker path for %s.', $mode),
    );
    expectActiveHttp(!file_exists($markerPath), sprintf('%s failure marker is already active.', $mode));
    expectActiveHttp(
        file_put_contents($markerPath, $mode . "\n", LOCK_EX) !== false,
        sprintf('Unable to activate %s failure marker.', $mode),
    );
    expectActiveHttp(is_file($markerPath), sprintf('%s failure marker was not created.', $mode));
}

function deactivateFailureMarker(string $markerPath, string $mode): void
{
    if (!file_exists($markerPath)) {
        return;
    }

    expectActiveHttp(@unlink($markerPath), sprintf('Unable to remove %s failure marker.', $mode));
    expectActiveHttp(!file_exists($markerPath), sprintf('%s failure marker remained after cleanup.', $mode));
}

$schema = new CheckoutServerSelectionsSchema();
$schemaDropped = false;
$product = new Product($productId);
$session = null;
$failure = null;
$successMessage = null;
$stageStatuses = [];

try {
    expectActiveHttp(Validate::isLoadedObject($product), 'Runtime checkout product is not loaded.');

    $session = new ActiveCheckoutHttpSession($cookieJar);

    // Seed through the same real Core CartController AJAX add surface exercised by Chromium. The
    // persistent cURL handle is the HTTP-session boundary: Core cookies stay in libcurl memory for
    // cart add, healthy checkout, injected failures and recovery requests alike.
    $addUrl = $baseUrl . '/cart?' . http_build_query([
        'add' => 1,
        'ajax' => 1,
        'id_product' => $productId,
        'qty' => 1,
    ], '', '&', PHP_QUERY_RFC3986);
    $add = $session->request($addUrl);
    expectActiveHttp(
        $add['status'] >= 200 && $add['status'] < 400,
        sprintf('Core cart product-add request failed with HTTP %d.', $add['status']),
    );
    expectActiveHttp(
        $session->cookies() !== [],
        'Core cart product-add request did not establish any HTTP cookie in the persistent session.',
    );

    $healthy = $session->request($baseUrl . '/order');
    expectHealthyOpc($healthy, 'Initial');
    $stageStatuses['healthy'] = $healthy['status'];

    expectActiveHttp($schema->uninstall(), 'Unable to drop checkout-selection schema for failure injection.');
    $schemaDropped = true;

    $fallback = $session->request($baseUrl . '/order');
    expectNativeFallback($fallback, 'Persistence-failure');
    $stageStatuses['persistence'] = $fallback['status'];

    expectActiveHttp($schema->install(), 'Unable to restore checkout-selection schema after failure injection.');
    $schemaDropped = false;

    $recovered = $session->request($baseUrl . '/order');
    expectHealthyOpc($recovered, 'Persistence-recovered');
    $stageStatuses['persistence_recovered'] = $recovered['status'];

    foreach ($failureMarkers as $mode => $markerPath) {
        activateFailureMarker($markerPath, $fixtureRoot, $mode);

        try {
            $modeFallback = $session->request($baseUrl . '/order');
            expectNativeFallback($modeFallback, ucfirst($mode) . '-failure');
            $stageStatuses[$mode] = $modeFallback['status'];
        } finally {
            deactivateFailureMarker($markerPath, $mode);
        }

        $modeRecovered = $session->request($baseUrl . '/order');
        expectHealthyOpc($modeRecovered, ucfirst($mode) . '-recovered');
        $stageStatuses[$mode . '_recovered'] = $modeRecovered['status'];
    }

    $successMessage = sprintf(
        "Active checkout fallback HTTP contract OK: product=%d; %s\n",
        $productId,
        implode(', ', array_map(
            static fn (string $stage, int $status): string => sprintf('%s=%d', $stage, $status),
            array_keys($stageStatuses),
            array_values($stageStatuses),
        )),
    );
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    foreach ($failureMarkers as $mode => $markerPath) {
        try {
            if (file_exists($markerPath) && !@unlink($markerPath) && $failure === null) {
                $failure = new RuntimeException(sprintf('Cleanup could not remove %s failure marker.', $mode));
            }
        } catch (Throwable $cleanupException) {
            $failure ??= $cleanupException;
        }
    }

    if ($schemaDropped) {
        try {
            if (!$schema->install() && $failure === null) {
                $failure = new RuntimeException('Cleanup could not restore checkout-selection schema.');
            }
        } catch (Throwable $cleanupException) {
            $failure ??= $cleanupException;
        }
    }

    try {
        $shopId = (int) Configuration::get('PS_SHOP_DEFAULT');
        $shopGroupId = (int) Shop::getGroupFromShop($shopId);
        if ($shopId > 0 && $shopGroupId > 0) {
            $disabled = Configuration::updateValue(
                JzOnePageCheckout::CONFIG_CHECKOUT_ENABLED,
                false,
                false,
                $shopGroupId,
                $shopId,
            );
            if (!$disabled && $failure === null) {
                $failure = new RuntimeException('Cleanup could not disable the temporary active checkout fixture.');
            }
        }
    } catch (Throwable $cleanupException) {
        $failure ??= $cleanupException;
    }

    if (Validate::isLoadedObject($product)) {
        try {
            if (!$product->delete() && $failure === null) {
                $failure = new RuntimeException('Cleanup could not delete runtime checkout product.');
            }
        } catch (Throwable $cleanupException) {
            $failure ??= $cleanupException;
        }
    }

    if ($session instanceof ActiveCheckoutHttpSession) {
        $session->close();
    }
    @unlink($cookieJar);
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, $failure->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, $successMessage ?? "Active checkout fallback HTTP contract completed.\n");