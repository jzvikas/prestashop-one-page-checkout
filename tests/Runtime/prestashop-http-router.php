<?php

declare(strict_types=1);

$root = rtrim((string) getenv('JZOPC_PRESTASHOP_ROOT'), DIRECTORY_SEPARATOR);
if ($root === '' || !is_file($root . '/index.php')) {
    http_response_code(500);
    error_log('JZOPC_PRESTASHOP_ROOT must point to the installed PrestaShop root.');

    return true;
}

$uriPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$decodedPath = is_string($uriPath) ? rawurldecode($uriPath) : '/';
$segments = array_values(array_filter(explode('/', str_replace('\\', '/', $decodedPath)), static fn (string $segment): bool => $segment !== ''));
$hasTraversal = in_array('..', $segments, true) || str_contains($decodedPath, "\0");
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if (!$hasTraversal && in_array($method, ['GET', 'HEAD'], true)) {
    $candidate = $root . '/' . ltrim($decodedPath, '/');
    if (is_file($candidate)) {
        // Existing static resources must be served by PHP's development server itself.
        // Dynamic and missing paths still flow through the real PrestaShop Front Office entry point.
        return false;
    }
}

// Keep failure diagnostics structural only. Query strings may contain Core secure keys or module
// parameters, while headers/cookies may contain authentication state. Recording only request phase,
// method, normalized path, numeric content length and a coarse content-type class is enough to prove
// whether a native handoff reached Core without exposing request payloads or authentication material.
$diagnosticPath = '/' . implode('/', array_map(
    static fn (string $segment): string => preg_replace('/[^A-Za-z0-9._~-]/', '_', $segment) ?? '_',
    $segments
));
if ($diagnosticPath === '/') {
    $diagnosticPath = '/';
}
$contentLengthRaw = (string) ($_SERVER['CONTENT_LENGTH'] ?? '');
$contentLength = ctype_digit($contentLengthRaw) ? (int) $contentLengthRaw : -1;
$contentTypeRaw = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
$contentType = match (true) {
    str_starts_with($contentTypeRaw, 'application/x-www-form-urlencoded') => 'form-urlencoded',
    str_starts_with($contentTypeRaw, 'multipart/form-data') => 'multipart-form',
    $contentTypeRaw === '' => 'none',
    default => 'other',
};
$safeMethod = preg_replace('/[^A-Z]/', '', $method) ?: 'UNKNOWN';

error_log(sprintf(
    'JZOPC_RUNTIME_HTTP phase=enter method=%s path=%s content_length=%d content_type=%s',
    $safeMethod,
    $diagnosticPath,
    $contentLength,
    $contentType
));

register_shutdown_function(static function () use ($safeMethod, $diagnosticPath): void {
    $status = http_response_code();
    if (!is_int($status) || $status < 100 || $status > 599) {
        $status = 0;
    }
    error_log(sprintf(
        'JZOPC_RUNTIME_HTTP phase=exit method=%s path=%s status=%d',
        $safeMethod,
        $diagnosticPath,
        $status
    ));
});

require $root . '/index.php';

return true;
