<?php

declare(strict_types=1);

class Context {}

require_once dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutCartPresenterInterface;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSectionRendererInterface;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSectionRendererRegistry;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutTemplateRendererInterface;
use Jzvikas\OnePageCheckout\Checkout\Rendering\SummarySectionRenderer;

$context = new Context();
$presenter = new class implements CheckoutCartPresenterInterface {
    public function present(Context $context): mixed
    {
        return ['totals' => ['total' => ['label' => 'Total', 'value' => '€12.00']]];
    }
};
$template = new class implements CheckoutTemplateRendererInterface {
    public string $template = '';
    public array $variables = [];
    public function render(Context $context, string $template, array $variables): string
    {
        $this->template = $template;
        $this->variables = $variables;
        return '<summary>ok</summary>';
    }
};
$summary = new SummarySectionRenderer($presenter, $template);
$registry = new CheckoutSectionRendererRegistry([$summary]);
$rendered = $registry->render($context, [CheckoutSection::Summary]);
assert($rendered === ['summary' => '<summary>ok</summary>']);
assert($template->template === 'sections/summary.tpl');
assert($template->variables['cart']['totals']['total']['value'] === '€12.00');

$summaryTemplate = file_get_contents(dirname(__DIR__, 2) . '/views/templates/front/sections/summary.tpl');
assert(is_string($summaryTemplate) && $summaryTemplate !== '');
assert(str_contains($summaryTemplate, 'data-jzopc-section="summary"'));
assert(!str_contains($summaryTemplate, 'data-checkout-section="summary"'));

try {
    $registry->render($context, [CheckoutSection::Payment]);
    assert(false, 'Missing renderer must fail closed.');
} catch (LogicException) {
}

$duplicate = new class implements CheckoutSectionRendererInterface {
    public function section(): CheckoutSection { return CheckoutSection::Summary; }
    public function render(Context $context): string { return ''; }
};
try {
    new CheckoutSectionRendererRegistry([$summary, $duplicate]);
    assert(false, 'Duplicate renderer must be rejected.');
} catch (LogicException) {
}

echo "CheckoutSectionRenderingSmokeTest OK\n";
