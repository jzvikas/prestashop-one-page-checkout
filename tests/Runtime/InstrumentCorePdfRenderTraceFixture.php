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

$renderStartNeedle = "    public function render(\$display = true)\n    {";
$renderEndNeedle = "    public function getTemplateObject(\$object)\n    {";
if (substr_count($source, $renderStartNeedle) !== 1 || substr_count($source, $renderEndNeedle) !== 1) {
    fwrite(STDERR, "Unexpected PrestaShop 9.1.5 PDF render method boundary.\n");
    exit(3);
}

$renderStart = strpos($source, $renderStartNeedle);
$renderEnd = strpos($source, $renderEndNeedle, is_int($renderStart) ? $renderStart + strlen($renderStartNeedle) : 0);
if (!is_int($renderStart) || !is_int($renderEnd) || $renderEnd <= $renderStart) {
    fwrite(STDERR, "Unable to isolate PrestaShop 9.1.5 PDF render method.\n");
    exit(3);
}

$renderSource = substr($source, $renderStart, $renderEnd - $renderStart);
if ($renderSource === '') {
    fwrite(STDERR, "PrestaShop 9.1.5 PDF render method is empty.\n");
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
    if (substr_count($renderSource, $needle) !== 1) {
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

$instrumentedRender = $renderSource;
foreach ($replacements as $needle => $replacement) {
    if (substr_count($instrumentedRender, $needle) !== 1) {
        fwrite(STDERR, "PrestaShop PDF trace expected a unique render-method boundary.\n");
        exit(3);
    }
    $instrumentedRender = str_replace($needle, $replacement, $instrumentedRender, $count);
    if ($count !== 1) {
        fwrite(STDERR, "Unable to instrument PrestaShop PDF render boundary.\n");
        exit(3);
    }
}

$updated = substr($source, 0, $renderStart) . $instrumentedRender . substr($source, $renderEnd);

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

$generatorFile = $coreRoot . '/classes/pdf/PDFGenerator.php';
$generatorSource = is_file($generatorFile) ? file_get_contents($generatorFile) : false;
if (!is_string($generatorSource) || $generatorSource === '') {
    fwrite(STDERR, "PrestaShop PDFGenerator.php is unavailable.\n");
    exit(3);
}

if (str_contains($generatorSource, 'JZOPC_RUNTIME_CORE_PDF_GENERATOR')) {
    fwrite(STDERR, "PrestaShop PDFGenerator fixture is already instrumented.\n");
    exit(3);
}

$generatorMethods = [
    'header' => [
        'start' => "    public function Header()\n    {",
        'end' => "    public function Footer()\n    {",
        'semantics' => [
            '        $this->writeHTML($this->header);' => "        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=header_html_begin');\n        \$this->writeHTML(\$this->header);\n        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=header_html_end');",
        ],
    ],
    'footer' => [
        'start' => "    public function Footer()\n    {",
        'end' => "    public function render(\$filename, \$display = true)\n    {",
        'semantics' => [
            '        $this->writeHTML($this->footer);' => "        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=footer_html_begin');\n        \$this->writeHTML(\$this->footer);\n        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=footer_html_end');",
            '        $this->FontFamily = self::DEFAULT_FONT;' => "        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=footer_font_reset_begin');\n        \$this->FontFamily = self::DEFAULT_FONT;\n        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=footer_font_reset_end');",
            '        $this->writeHTML($this->pagination);' => "        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=pagination_html_begin');\n        \$this->writeHTML(\$this->pagination);\n        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=pagination_html_end');",
        ],
    ],
    'write_page' => [
        'start' => "    public function writePage()\n    {",
        'end' => "    protected function getRandomSeed(\$seed = '')\n    {",
        'semantics' => [
            '        $this->SetHeaderMargin(5);' => "        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=header_margin_begin');\n        \$this->SetHeaderMargin(5);\n        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=header_margin_end');",
            '        $this->SetFooterMargin(21);' => "        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=footer_margin_begin');\n        \$this->SetFooterMargin(21);\n        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=footer_margin_end');",
            '        $this->setMargins(10, 40, 10);' => "        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=body_margins_begin');\n        \$this->setMargins(10, 40, 10);\n        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=body_margins_end');",
            '        $this->AddPage();' => "        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=add_page_begin');\n        \$this->AddPage();\n        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=add_page_end');",
            "        \$this->writeHTML(\$this->content, true, false, true, false, '');" => "        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=content_html_begin');\n        \$this->writeHTML(\$this->content, true, false, true, false, '');\n        error_log('JZOPC_RUNTIME_CORE_PDF_GENERATOR phase=content_html_end');",
        ],
    ],
];

$instrumentedGenerator = $generatorSource;
foreach ($generatorMethods as $methodName => $definition) {
    $startNeedle = $definition['start'];
    $endNeedle = $definition['end'];
    if (substr_count($instrumentedGenerator, $startNeedle) !== 1 || substr_count($instrumentedGenerator, $endNeedle) !== 1) {
        fwrite(STDERR, "Unexpected PrestaShop 9.1.5 PDFGenerator method boundary.\n");
        exit(3);
    }

    $methodStart = strpos($instrumentedGenerator, $startNeedle);
    $methodEnd = strpos($instrumentedGenerator, $endNeedle, is_int($methodStart) ? $methodStart + strlen($startNeedle) : 0);
    if (!is_int($methodStart) || !is_int($methodEnd) || $methodEnd <= $methodStart) {
        fwrite(STDERR, "Unable to isolate PrestaShop 9.1.5 PDFGenerator method.\n");
        exit(3);
    }

    $methodSource = substr($instrumentedGenerator, $methodStart, $methodEnd - $methodStart);
    if ($methodSource === '') {
        fwrite(STDERR, "PrestaShop 9.1.5 PDFGenerator method is empty.\n");
        exit(3);
    }

    foreach ($definition['semantics'] as $needle => $replacement) {
        if (substr_count($methodSource, $needle) !== 1) {
            fwrite(STDERR, "Unexpected PrestaShop 9.1.5 PDFGenerator source boundary.\n");
            exit(3);
        }
        $methodSource = str_replace($needle, $replacement, $methodSource, $count);
        if ($count !== 1) {
            fwrite(STDERR, "Unable to instrument PrestaShop PDFGenerator boundary.\n");
            exit(3);
        }
    }

    $instrumentedGenerator = substr($instrumentedGenerator, 0, $methodStart) . $methodSource . substr($instrumentedGenerator, $methodEnd);
}

foreach ([
    'phase=header_html_begin',
    'phase=header_html_end',
    'phase=footer_html_begin',
    'phase=footer_html_end',
    'phase=footer_font_reset_begin',
    'phase=footer_font_reset_end',
    'phase=pagination_html_begin',
    'phase=pagination_html_end',
    'phase=header_margin_begin',
    'phase=header_margin_end',
    'phase=footer_margin_begin',
    'phase=footer_margin_end',
    'phase=body_margins_begin',
    'phase=body_margins_end',
    'phase=add_page_begin',
    'phase=add_page_end',
    'phase=content_html_begin',
    'phase=content_html_end',
] as $marker) {
    if (!str_contains($instrumentedGenerator, 'JZOPC_RUNTIME_CORE_PDF_GENERATOR ' . $marker)) {
        fwrite(STDERR, "PrestaShop PDFGenerator trace is incomplete.\n");
        exit(3);
    }
}

if (file_put_contents($coreFile, $updated) === false) {
    fwrite(STDERR, "Unable to write disposable PrestaShop PDF trace fixture.\n");
    exit(3);
}

if (file_put_contents($generatorFile, $instrumentedGenerator) === false) {
    fwrite(STDERR, "Unable to write disposable PrestaShop PDFGenerator trace fixture.\n");
    exit(3);
}

fwrite(STDOUT, "Disposable PrestaShop Core PDF render and generator traces installed.\n");
