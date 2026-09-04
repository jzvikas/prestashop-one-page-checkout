<?php

declare(strict_types=1);

class Context {}

require_once dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutDeliveryOptionsPresenterInterface;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutTemplateRendererInterface;
use Jzvikas\OnePageCheckout\Checkout\Rendering\DeliverySectionRenderer;

$context = new Context();
$presenter = new class implements CheckoutDeliveryOptionsPresenterInterface {
    public bool $virtual = false;

    public function present(Context $context): array
    {
        return [
            'isVirtual' => $this->virtual,
            'deliveryOptions' => ['7,' => ['name' => 'Fast carrier']],
            'selectedDeliveryOption' => '7,',
            'hookDisplayBeforeCarrier' => '<before>',
            'hookDisplayAfterCarrier' => '<after>',
        ];
    }
};
$template = new class implements CheckoutTemplateRendererInterface {
    public string $template = '';
    public array $variables = [];

    public function render(Context $context, string $template, array $variables): string
    {
        $this->template = $template;
        $this->variables = $variables;

        return '<delivery>ok</delivery>';
    }
};

$renderer = new DeliverySectionRenderer($presenter, $template);
assert($renderer->section() === CheckoutSection::Delivery);
assert($renderer->render($context) === '<delivery>ok</delivery>');
assert($template->template === 'sections/delivery.tpl');
assert($template->variables['selectedDeliveryOption'] === '7,');

$presenter->virtual = true;
assert($renderer->render($context) === '');

echo "CheckoutDeliverySectionRenderingSmokeTest OK\n";
