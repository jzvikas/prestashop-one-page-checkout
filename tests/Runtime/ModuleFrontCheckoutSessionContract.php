<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Checkout\Address\CheckoutAddressSelectionService;
use Jzvikas\OnePageCheckout\Checkout\Mutation\CheckoutAddressSelectionMutation;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSessionProviderInterface;

$shopRoot = $argv[1] ?? '';
$expectedFamily = $argv[2] ?? '';

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if ($shopRoot === '' || !is_file($shopRoot . '/config/config.inc.php')) {
    $fail('Installed PrestaShop root is missing or invalid.');
}
if (!in_array($expectedFamily, ['9.0', '9.1', '9.2'], true)) {
    $fail('Expected runtime family must be 9.0, 9.1 or 9.2.');
}

require_once $shopRoot . '/config/config.inc.php';
require_once $shopRoot . '/modules/jzonepagecheckout/jzonepagecheckout.php';

if (!str_starts_with((string) _PS_VERSION_, $expectedFamily . '.')) {
    $fail(sprintf('Installed PrestaShop version %s does not match expected family %s.', _PS_VERSION_, $expectedFamily));
}

$module = Module::getInstanceByName('jzonepagecheckout');
if (!$module instanceof JzOnePageCheckout) {
    $fail('Unable to load JzOnePageCheckout.');
}

$context = Context::getContext();
$shopId = (int) Configuration::get('PS_SHOP_DEFAULT');
$languageId = (int) Configuration::get('PS_LANG_DEFAULT');
$currencyId = (int) Configuration::get('PS_CURRENCY_DEFAULT');
if ($shopId <= 0 || $languageId <= 0 || $currencyId <= 0) {
    $fail('Default shop/language/currency configuration is invalid.');
}

$cart = new Cart();
$cart->id_shop = $shopId;
$cart->id_shop_group = (int) Shop::getGroupFromShop($shopId);
$cart->id_lang = $languageId;
$cart->id_currency = $currencyId;
$cart->id_customer = 0;
$cart->id_guest = 0;
if (!$cart->add()) {
    $fail('Unable to create runtime checkout cart.');
}

$context->cart = $cart;
$context->shop = new Shop($shopId);
$context->language = new Language($languageId);
$context->currency = new Currency($currencyId);

// A real module front request initializes Controller::$container from buildContainer(). Use that
// exact front-container bootstrap without running full init()/routing hooks, while deliberately
// keeping a controller that has no getCheckoutSession() capability. This proves that the private
// provider fallback is resolved through the actual legacy front service graph.
$moduleFrontController = new class extends ModuleFrontController {
    public function initializeRuntimeContainer(): void
    {
        if ($this->getContainer() === null) {
            $this->container = $this->buildContainer();
        }
    }
};
$moduleFrontController->initializeRuntimeContainer();
$context->controller = $moduleFrontController;

$entry = $module->get(CheckoutAddressSelectionMutation::class);
if (!$entry instanceof CheckoutAddressSelectionMutation) {
    $fail('CheckoutAddressSelectionMutation entry service is unavailable in the module front container.');
}

$readPrivateProperty = static function (object $object, string $property) use ($fail): mixed {
    $reflection = new ReflectionObject($object);
    if (!$reflection->hasProperty($property)) {
        $fail(sprintf('Runtime service graph is missing expected dependency property %s::%s.', $object::class, $property));
    }

    $reflectionProperty = $reflection->getProperty($property);
    $reflectionProperty->setAccessible(true);

    return $reflectionProperty->getValue($object);
};

$addressSelectionService = $readPrivateProperty($entry, 'addressSelectionService');
if (!$addressSelectionService instanceof CheckoutAddressSelectionService) {
    $fail('Public address mutation entry did not receive CheckoutAddressSelectionService.');
}

$provider = $readPrivateProperty($addressSelectionService, 'checkoutSessionProvider');
if (!$provider instanceof CheckoutSessionProviderInterface) {
    $fail('Private CheckoutSessionProviderInterface dependency was not autowired into the module-front entry graph.');
}

$session = $provider->get($context);
if (!$session instanceof CheckoutSession) {
    $fail('Module-front fallback did not create a Core CheckoutSession.');
}
if ($session->getCart() !== $cart) {
    $fail('Module-front CheckoutSession is not bound to the current server cart.');
}

try {
    $deliveryOptions = $session->getDeliveryOptions();
} catch (Throwable $exception) {
    $fail('Module-front CheckoutSession delivery provider failed: ' . $exception->getMessage());
}
if (!is_array($deliveryOptions)) {
    $fail('Module-front CheckoutSession returned an invalid delivery option set.');
}

$cartId = (int) $cart->id;
if (!$cart->delete()) {
    $fail('Runtime checkout cart cleanup failed.');
}

fwrite(STDOUT, sprintf(
    "Module-front CheckoutSession contract OK: PrestaShop %s, cart=%d\n",
    _PS_VERSION_,
    $cartId,
));
