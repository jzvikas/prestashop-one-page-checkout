<?php

declare(strict_types=1);

$root = rtrim((string) getenv('JZOPC_PRESTASHOP_ROOT'), DIRECTORY_SEPARATOR);
if ($root === '' || !is_file($root . '/index.php')) {
    http_response_code(500);
    fwrite(STDERR, "JZOPC_PRESTASHOP_ROOT must point to the installed PrestaShop root.\n");

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

require $root . '/index.php';

return true;
