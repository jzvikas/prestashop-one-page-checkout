<?php

declare(strict_types=1);

class Cart
{
    public int $id = 42;

    public function __construct(private bool $virtual = false)
    {
    }

    public function isVirtualCart(): bool
    {
        return $this->virtual;
    }
}

class Context
{
    public Cart $cart;

    public function __construct(bool $virtual = false)
    {
        $this->cart = new Cart($virtual);
    }
}

require dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\Carrier\CheckoutCarrierSelectionException;
use Jzvikas\OnePageCheckout\Checkout\Carrier\CheckoutCarrierSelectionParser;
use Jzvikas\OnePageCheckout\Checkout\Carrier\CheckoutCarrierSelectionService;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSessionProviderInterface;

final class CarrierSession
{
    public array $options = ['2,' => [], '4,7,' => []];
    public ?string $selected = '2,';
    public array $writes = [];

    public function getDeliveryOptions(): array
    {
        return $this->options;
    }

    public function getSelectedDeliveryOption(): ?string
    {
        return $this->selected;
    }

    public function setDeliveryOption(string $option): bool
    {
        $this->writes[] = $option;
        $this->selected = $option;

        return true;
    }
}

$parser = new CheckoutCarrierSelectionParser();
assert($parser->parse(['deliveryOption' => '4,7,'])->deliveryOption === '4,7,');

foreach (['', '2<script>', '  ', str_repeat('1', 256)] as $invalid) {
    try {
        $parser->parse(['deliveryOption' => $invalid]);
        assert(false, 'Malformed delivery option must be rejected.');
    } catch (CheckoutCarrierSelectionException) {
    }
}

$session = new CarrierSession();
$provider = new class($session) implements CheckoutSessionProviderInterface {
    public function __construct(private object $session)
    {
    }

    public function get(\Context $context): object
    {
        return $this->session;
    }
};
$service = new CheckoutCarrierSelectionService($provider);

assert($service->apply(new Context(), $parser->parse(['deliveryOption' => '2,'])) === false);
assert($session->writes === [], 'idempotent selection must not rewrite Core cart state');
assert($service->apply(new Context(), $parser->parse(['deliveryOption' => '4,7,'])) === true);
assert($session->writes === ['4,7,'], 'fresh Core option must be persisted through CheckoutSession');

try {
    $service->apply(new Context(), $parser->parse(['deliveryOption' => '99,']));
    assert(false, 'forged delivery option must be rejected');
} catch (CheckoutCarrierSelectionException) {
}

try {
    $service->apply(new Context(true), $parser->parse(['deliveryOption' => '2,']));
    assert(false, 'virtual carts must reject carrier mutation');
} catch (CheckoutCarrierSelectionException) {
}

echo "CheckoutCarrierSelectionSmokeTest OK\n";
