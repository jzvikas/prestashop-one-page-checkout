<?php

declare(strict_types=1);

class Context {}

require_once dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutPaymentOptionsPresenterInterface;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutTemplateRendererInterface;
use Jzvikas\OnePageCheckout\Checkout\Rendering\PaymentSectionRenderer;

$context = new Context();
$presenter = new class implements CheckoutPaymentOptionsPresenterInterface {
    public function present(Context $context): array
    {
        return [
            'isFree' => false,
            'paymentOptions' => [
                'demo' => [[
                    'id' => 'payment-option-1',
                    'call_to_action_text' => 'Demo',
                ]],
            ],
            'hookDisplayPaymentTop' => '<top>',
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

        return '<payment>ok</payment>';
    }
};

$renderer = new PaymentSectionRenderer($presenter, $template);
assert($renderer->section() === CheckoutSection::Payment);
assert($renderer->render($context) === '<payment>ok</payment>');
assert($template->template === 'sections/payment.tpl');
assert($template->variables['paymentOptions']['demo'][0]['id'] === 'payment-option-1');

echo "CheckoutPaymentSectionRenderingSmokeTest OK\n";
