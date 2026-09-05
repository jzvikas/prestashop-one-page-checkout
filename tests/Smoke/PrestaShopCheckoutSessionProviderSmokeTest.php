<?php

declare(strict_types=1);

class Context
{
    public object $controller;
}

class CheckoutSessionFake {}

class CheckoutControllerFake
{
    public function getCheckoutSession(): object
    {
        return new CheckoutSessionFake();
    }
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\Rendering\PrestaShopCheckoutSessionProvider;

$context = new Context();
$context->controller = new CheckoutControllerFake();
$provider = new PrestaShopCheckoutSessionProvider();

assert($provider->get($context) instanceof CheckoutSessionFake);

$context->controller = new stdClass();
try {
    $provider->get($context);
    assert(false, 'Missing Core checkout session access must fail closed.');
} catch (RuntimeException $exception) {
    assert(str_contains($exception->getMessage(), 'does not expose'));
}

echo "PrestaShopCheckoutSessionProviderSmokeTest OK\n";
