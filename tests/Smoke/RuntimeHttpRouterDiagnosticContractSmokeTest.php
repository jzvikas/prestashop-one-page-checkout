<?php

declare(strict_types=1);

$path = __DIR__ . '/../Runtime/prestashop-http-router.php';
$source = file_get_contents($path);

if (!is_string($source) || $source === '') {
    fwrite(STDERR, "Missing runtime HTTP router source.\n");
    exit(1);
}

$required = [
    "parse_url((string) (\$_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)",
    'register_shutdown_function',
    'http_response_code()',
    'JZOPC_RUNTIME_HTTP method=%s path=%s status=%d',
    "preg_replace('/[^A-Z]/', '', \$method)",
    "preg_replace('/[^A-Za-z0-9._~-]/', '_', \$segment)",
];

foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Runtime HTTP router is missing diagnostic contract: {$needle}\n");
        exit(1);
    }
}

$forbiddenLogInputs = [
    "\$_SERVER['HTTP_COOKIE']",
    "\$_COOKIE",
    "\$_POST",
    "\$_GET",
    "QUERY_STRING",
    'getallheaders(',
];

foreach ($forbiddenLogInputs as $needle) {
    if (str_contains($source, $needle)) {
        fwrite(STDERR, "Runtime HTTP diagnostics must not consume sensitive request material: {$needle}\n");
        exit(1);
    }
}

echo "Runtime HTTP router diagnostic contract smoke test OK.\n";
