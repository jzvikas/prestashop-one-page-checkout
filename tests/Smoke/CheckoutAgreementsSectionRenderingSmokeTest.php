<?php

declare(strict_types=1);

class Context {}

require_once dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;
use Jzvikas\OnePageCheckout\Checkout\Rendering\AgreementsSectionRenderer;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutAgreementsPresenterInterface;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutTemplateRendererInterface;

$presenter = new class implements CheckoutAgreementsPresenterInterface {
    public function present(Context $context): array
    {
        return ['conditions' => ['terms-and-conditions' => '<a>Terms</a>']];
    }
};
$template = new class implements CheckoutTemplateRendererInterface {
    public string $template = '';
    public array $variables = [];

    public function render(Context $context, string $template, array $variables): string
    {
        $this->template = $template;
        $this->variables = $variables;

        return '<agreements>ok</agreements>';
    }
};

$renderer = new AgreementsSectionRenderer($presenter, $template);
assert($renderer->section() === CheckoutSection::Agreements);
assert($renderer->render(new Context()) === '<agreements>ok</agreements>');
assert($template->template === 'sections/agreements.tpl');
assert(isset($template->variables['conditions']['terms-and-conditions']));

echo "CheckoutAgreementsSectionRenderingSmokeTest OK\n";
