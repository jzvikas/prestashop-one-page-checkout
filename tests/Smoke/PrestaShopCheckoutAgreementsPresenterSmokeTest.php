<?php

declare(strict_types=1);

class Context
{
    public function getTranslator(): object
    {
        return (object) ['translator' => true];
    }
}

class ConditionsToApproveFinder
{
    public function __construct(public Context $context, public object $translator) {}

    public function getConditionsToApproveForTemplate(): array
    {
        return ['terms-and-conditions' => '<a href="/terms">Terms</a>'];
    }
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\Rendering\PrestaShopCheckoutAgreementsPresenter;

$result = (new PrestaShopCheckoutAgreementsPresenter())->present(new Context());
assert($result['conditions']['terms-and-conditions'] === '<a href="/terms">Terms</a>');

echo "PrestaShopCheckoutAgreementsPresenterSmokeTest OK\n";
