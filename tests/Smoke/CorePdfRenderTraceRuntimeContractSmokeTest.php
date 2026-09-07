<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$instrumenter = file_get_contents($root . '/tests/Runtime/InstrumentCorePdfRenderTraceFixture.php');
$builder = file_get_contents($root . '/tests/Runtime/build-active-checkout-fixture.sh');

if (!is_string($instrumenter) || !is_string($builder)) {
    fwrite(STDERR, "Unable to read Core PDF runtime trace sources.\n");
    exit(1);
}

$required = [
    "getenv('JZOPC_RUNTIME_ACTIVE_FIXTURE') !== '1'",
    '$argv[1] !== \'/tmp/prestashop\'',
    "'/classes/pdf/PDF.php'",
    '$renderStartNeedle = "    public function render(\\$display = true)\\n    {"',
    '$renderEndNeedle = "    public function getTemplateObject(\\$object)\\n    {"',
    'substr_count($source, $renderStartNeedle) !== 1',
    'substr_count($source, $renderEndNeedle) !== 1',
    '$renderSource = substr($source, $renderStart, $renderEnd - $renderStart);',
    'substr_count($renderSource, $needle) !== 1',
    '$instrumentedRender = $renderSource;',
    'substr_count($instrumentedRender, $needle) !== 1',
    '$updated = substr($source, 0, $renderStart) . $instrumentedRender . substr($source, $renderEnd);',
    "'phase=set_font_begin'",
    "'phase=set_font_end'",
    "'phase=template_object_begin'",
    "'phase=template_object_end'",
    "'phase=template_hook_begin'",
    "'phase=template_hook_end'",
    "'phase=header_begin'",
    "'phase=header_end'",
    "'phase=content_begin'",
    "'phase=content_end'",
    "'phase=write_page_begin'",
    "'phase=write_page_end'",
    "'phase=footer_begin'",
    "'phase=footer_end'",
    "'phase=renderer_output_begin'",
    "'phase=renderer_output_end'",
];
foreach ($required as $needle) {
    if (!str_contains($instrumenter, $needle)) {
        fwrite(STDERR, "Core PDF trace is missing required fail-closed contract: {$needle}\n");
        exit(1);
    }
}

if (str_contains($instrumenter, 'substr_count($source, $needle) !== 1')) {
    fwrite(STDERR, "Core PDF trace must not require render semantics to be globally unique across PDF.php.\n");
    exit(1);
}

foreach ([
    'validateOrder(',
    'PaymentFree',
    'INSERT INTO',
    'UPDATE ',
    'DELETE FROM',
    '$_COOKIE',
    'secure_key',
    'csrf',
    'customer_email',
] as $forbidden) {
    if (str_contains($instrumenter, $forbidden)) {
        fwrite(STDERR, "Core PDF trace contains forbidden behavior/data surface: {$forbidden}\n");
        exit(1);
    }
}

$wire = <<<'SH'
JZOPC_RUNTIME_ACTIVE_FIXTURE=1 php \
  "$target_root/tests/Runtime/InstrumentCorePdfRenderTraceFixture.php" \
  /tmp/prestashop
SH;
if (!str_contains($builder, $wire)) {
    fwrite(STDERR, "Active fixture does not install the Core PDF trace.\n");
    exit(1);
}

fwrite(STDOUT, "Core PDF render trace runtime contract smoke test passed.\n");
