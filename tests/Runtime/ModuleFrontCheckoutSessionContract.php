<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSessionProviderInterface;
use Jzvikas\OnePageCheckout\Integration\CheckoutProcessBuilder;

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
$context->customer = new Customer();
$context->shop = new Shop($shopId);
$context->language = new Language($languageId);
$context->currency = new Currency($currencyId);

// Deliberately remove the OrderController capability. Real module mutation controllers do not
// expose getCheckoutSession(), so the private provider dependency must construct the same Core
// session shape itself. ADR-0011 deliberately keeps such dependencies private; only the actual
// hook/controller entry services may be fetched through Module::get().
$context->controller = new stdClass();

$builder = $module->get(CheckoutProcessBuilder::class);
if (!$builder instanceof CheckoutProcessBuilder) {
    $fail('CheckoutProcessBuilder public front entry service is unavailable.');
}

/**
 * Walk only the module-owned DI object graph rooted at a public entry service. This verifies the
 * real front container compiled the private session-provider dependency without making that
 * dependency public merely for a test.
 *
 * @param array<int,true> $seen
 * @param array<int,CheckoutSessionProviderInterface> $providers
 */
$collectProviders = static function (mixed $value, array &$seen, array &$providers, int $depth = 0) use (&$collectProviders): void {
    if ($depth > 12) {
        return;
    }

    if (is_array($value)) {
        foreach ($value as $item) {
            $collectProviders($item, $seen, $providers, $depth + 1);
        }

        return;
    }

    if (!is_object($value)) {
        return;
    }

    $objectId = spl_object_id($value);
    if (isset($seen[$objectId])) {
        return;
    }
    $seen[$objectId] = true;

    if ($value instanceof CheckoutSessionProviderInterface) {
        $providers[$objectId] = $value;

        return;
    }

    $className = get_class($value);
    if (!str_starts_with($className, 'Jzvikas\\OnePageCheckout\\')) {
        return;
    }

    $reflection = new ReflectionObject($value);
    foreach ($reflection->getProperties() as $property) {
        if ($property->isStatic() || !$property->isInitialized($value)) {
            continue;
        }

        $collectProviders($property->getValue($value), $seen, $providers, $depth + 1);
    }
};

$seen = [];
$providers = [];
$collectProviders($builder, $seen, $providers);
if (count($providers) !== 1) {
    $fail(sprintf(
        'Public checkout entry graph must contain exactly one private CheckoutSessionProviderInterface dependency; found %d.',
        count($providers),
    ));
}

$provider = reset($providers);
if (!$provider instanceof CheckoutSessionProviderInterface) {
    $fail('CheckoutSessionProviderInterface dependency could not be resolved from the public entry graph.');
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
    "Module-front CheckoutSession contract OK: PrestaShop %s, cart=%d, provider=%s\n",
    _PS_VERSION_,
    $cartId,
    get_class($provider),
));
