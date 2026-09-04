<?php

declare(strict_types=1);

class Context {}

require_once dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;
use Jzvikas\OnePageCheckout\Checkout\Rendering\AddressesSectionRenderer;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutAddressBookPresenterInterface;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutTemplateRendererInterface;

$context = new Context();
$presenter = new class implements CheckoutAddressBookPresenterInterface {
    public function present(Context $context): array
    {
        return [
            'addresses' => [[
                'id' => 12,
                'alias' => 'Home',
                'lines' => ['Ada Lovelace', '1 Main Street'],
            ]],
            'deliveryAddressId' => 12,
            'invoiceAddressId' => 12,
            'useSameAddress' => true,
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

        return '<addresses>ok</addresses>';
    }
};

$renderer = new AddressesSectionRenderer($presenter, $template);
assert($renderer->section() === CheckoutSection::Addresses);
assert($renderer->render($context) === '<addresses>ok</addresses>');
assert($template->template === 'sections/addresses.tpl');
assert($template->variables['addresses'][0]['id'] === 12);
assert($template->variables['useSameAddress'] === true);

echo "CheckoutAddressSectionRenderingSmokeTest OK\n";
