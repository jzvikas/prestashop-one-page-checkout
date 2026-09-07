<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Core PDF trace fixture is CLI-only.\n");
    exit(2);
}

if (getenv('JZOPC_RUNTIME_ACTIVE_FIXTURE') !== '1') {
    fwrite(STDERR, "Refusing Core PDF trace instrumentation without JZOPC_RUNTIME_ACTIVE_FIXTURE=1.\n");
    exit(2);
}

if ($argc !== 2 || $argv[1] !== '/tmp/prestashop') {
    fwrite(STDERR, "Core PDF trace target must be exactly /tmp/prestashop.\n");
    exit(2);
}

$coreRoot = realpath($argv[1]);
if ($coreRoot !== '/tmp/prestashop') {
    fwrite(STDERR, "Core PDF trace target must resolve to /tmp/prestashop.\n");
    exit(2);
}

$coreFile = $coreRoot . '/classes/pdf/PDF.php';
$source = is_file($coreFile) ? file_get_contents($coreFile) : false;
if (!is_string($source) || $source === '') {
    fwrite(STDERR, "PrestaShop PDF.php is unavailable.\n");
    exit(3);
}

if (str_contains($source, 'JZOPC_RUNTIME_CORE_PDF')) {
    fwrite(STDERR, "PrestaShop PDF fixture is already instrumented.\n");
    exit(3);
}

$requiredSemantics = [
    '$this->pdf_renderer->setFontForLang(Context::getContext()->language->iso_code);',
    '$template = $this->getTemplateObject($object);',
    '$template->assignHookData($object);',
    '$this->pdf_renderer->createHeader($template->getHeader());',
    '$this->pdf_renderer->createPagination($template->getPagination());',
    '$this->pdf_renderer->createContent($template->getContent());',
    '$this->pdf_renderer->writePage();',
    '$this->pdf_renderer->createFooter($template->getFooter());',
    'return $this->pdf_renderer->render($this->getFilename(), $display);',
];
foreach ($requiredSemantics as $needle) {
    if (substr_count($source, $needle) !== 1) {
        fwrite(STDERR, "Unexpected PrestaShop 9.1.5 PDF render source boundary.\n");
        exit(3);
    }
}

$replacements = [
    '        $this->pdf_renderer->setFontForLang(Context::getContext()->language->iso_code);' =>
        "        error_log('JZOPC_RUNTIME_CORE_PDF phase=set_font_begin');\n        \$this->pdf_renderer->setFontForLang(Context::getContext()->language->iso_code);\n        error_log('JZOPC_RUNTIME_CORE_PDF phase=set_font_end');",
    '            $this->pdf_renderer->startPageGroup();' =>
        "            error_log('JZOPC_RUNTIME_CORE_PDF phase=page_group_begin');\n            \$this->pdf_renderer->startPageGroup();\n            error_log('JZOPC_RUNTIME_CORE_PDF phase=page_group_end');",
    '            $template = $this->getTemplateObject($object);' =>
        "            error_log('JZOPC_RUNTIME_CORE_PDF phase=template_object_begin');\n            \$template = \$this->getTemplateObject(\$object);\n            error_log('JZOPC_RUNTIME_CORE_PDF phase=template_object_end');",
    '            $template->assignHookData($object);' =>
        "            error_log('JZOPC_RUNTIME_CORE_PDF phase=template_hook_begin');\n            \$template->assignHookData(\$object);\n            error_log('JZOPC_RUNTIME_CORE_PDF phase=template_hook_end');",
    '            $this->pdf_renderer->createHeader($template->getHeader());' =>
        "            error_log('JZOPC_RUNTIME_CORE_PDF phase=header_begin');\n            \$this->pdf_renderer->createHeader(\$template->getHeader());\n            error_log('JZOPC_RUNTIME_CORE_PDF phase=header_end');",
    '            $this->pdf_renderer->createPagination($template->getPagination());' =>
        "            error_log('JZOPC_RUNTIME_CORE_PDF phase=pagination_begin');\n            \$this->pdf_renderer->createPagination(\$template->getPagination());\n            error_log('JZOPC_RUNTIME_CORE_PDF phase=pagination_end');",
    '            $this->pdf_renderer->createContent($template->getContent());' =>
        "            error_log('JZOPC_RUNTIME_CORE_PDF phase=content_begin');\n            \$this->pdf_renderer->createContent(\$template->getContent());\n            error_log('JZOPC_RUNTIME_CORE_PDF phase=content_end');",
    '            $this->pdf_renderer->writePage();' =>
        "            error_log('JZOPC_RUNTIME_CORE_PDF phase=write_page_begin');\n            \$this->pdf_renderer->writePage();\n            error_log('JZOPC_RUNTIME_CORE_PDF phase=write_page_end');",
    '            $this->pdf_renderer->createFooter($template->getFooter());' =>
        "            error_log('JZOPC_RUNTIME_CORE_PDF phase=footer_begin');\n            \$this->pdf_renderer->createFooter(\$template->getFooter());\n            error_log('JZOPC_RUNTIME_CORE_PDF phase=footer_end');",
    '            return $this->pdf_renderer->render($this->getFilename(), $display);' =>
        "            error_log('JZOPC_RUNTIME_CORE_PDF phase=renderer_output_begin');\n            \$result = \$this->pdf_renderer->render(\$this->getFilename(), \$display);\n            error_log('JZOPC_RUNTIME_CORE_PDF phase=renderer_output_end');\n\n            return \$result;",
];

$updated = $source;
foreach ($replacements as $needle => $replacement) {
    if (substr_count($updated, $needle) !== 1) {
        fwrite(STDERR, "PrestaShop PDF trace expected a unique source boundary.\n");
        exit(3);
    }
    $updated = str_replace($needle, $replacement, $updated, $count);
    if ($count !== 1) {
        fwrite(STDERR, "Unable to instrument PrestaShop PDF render boundary.\n");
        exit(3);
    }
}

foreach ([
    'phase=set_font_begin',
    'phase=set_font_end',
    'phase=page_group_begin',
    'phase=page_group_end',
    'phase=template_object_begin',
    'phase=template_object_end',
    'phase=template_hook_begin',
    'phase=template_hook_end',
    'phase=header_begin',
    'phase=header_end',
    'phase=pagination_begin',
    'phase=pagination_end',
    'phase=content_begin',
    'phase=content_end',
    'phase=write_page_begin',
    'phase=write_page_end',
    'phase=footer_begin',
    'phase=footer_end',
    'phase=renderer_output_begin',
    'phase=renderer_output_end',
] as $marker) {
    if (!str_contains($updated, 'JZOPC_RUNTIME_CORE_PDF ' . $marker)) {
        fwrite(STDERR, "PrestaShop PDF trace is incomplete.\n");
        exit(3);
    }
}

if (file_put_contents($coreFile, $updated) === false) {
    fwrite(STDERR, "Unable to write disposable PrestaShop PDF trace fixture.\n");
    exit(3);
}

fwrite(STDOUT, "Disposable PrestaShop Core PDF render trace installed.\n");
