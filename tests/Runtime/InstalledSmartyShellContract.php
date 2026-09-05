<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Integration\CheckoutProcessBuilder;
use Jzvikas\OnePageCheckout\Integration\CheckoutShellStep;
use Symfony\Contracts\Translation\TranslatorInterface;

$shopRoot = $argv[1] ?? '';
$expectedFamily = $argv[2] ?? '';

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if ($shopRoot === '' || !is_file($shopRoot . '/config/config.inc.php')) {
    $fail('Installed PrestaShop root is missing or invalid.');
}
if (!in_array($expectedFamily, ['9.1', '9.2'], true)) {
    $fail('Expected runtime family must be 9.1 or 9.2.');
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

$orderController = new class extends OrderController {
    public function initializeRuntimeContainer(): void
    {
        if ($this->getContainer() === null) {
            $this->container = $this->buildContainer();
        }
    }
};
$orderController->initializeRuntimeContainer();
$context->controller = $orderController;

$session = $orderController->getCheckoutSession();
if (!$session instanceof CheckoutSession) {
    $fail('OrderController did not return a Core CheckoutSession.');
}

$translator = $context->getTranslator();
if (!$translator instanceof TranslatorInterface) {
    $fail('Context translator does not implement TranslatorInterface.');
}

$builder = $module->get(CheckoutProcessBuilder::class);
if (!$builder instanceof CheckoutProcessBuilder) {
    $fail('CheckoutProcessBuilder service is unavailable in the front container.');
}

$process = $builder->build($context, $session, $translator);
if (!$process instanceof CheckoutProcess || $process->getCheckoutSession() !== $session) {
    $fail('Checkout process did not preserve the real Core CheckoutSession.');
}

$steps = $process->getSteps();
if (count($steps) !== 1 || !$steps[0] instanceof CheckoutShellStep) {
    $fail('Module process did not expose exactly one CheckoutShellStep.');
}

$html = $steps[0]->render();
if (!is_string($html) || trim($html) === '') {
    $fail('CheckoutShellStep rendered empty HTML.');
}

$requiredFragments = [
    'data-jzopc-step="one-page-checkout"',
    'data-jzopc-checkout',
    'data-jzopc-section="addresses"',
    'data-jzopc-section="payment"',
    'data-jzopc-section="agreements"',
    'data-jzopc-section="summary"',
    'data-jzopc-csrf-token="',
    'data-jzopc-state-version="',
    'data-jzopc-payment-url="',
    'data-jzopc-agreements-url="',
];
foreach ($requiredFragments as $fragment) {
    if (!str_contains($html, $fragment)) {
        $fail(sprintf('Rendered checkout shell is missing required fragment: %s', $fragment));
    }
}

foreach ([
    'data-jzopc-step="one-page-checkout"',
    'data-jzopc-checkout',
    'data-jzopc-section="addresses"',
    'data-jzopc-section="payment"',
    'data-jzopc-section="agreements"',
    'data-jzopc-section="summary"',
] as $uniqueFragment) {
    if (substr_count($html, $uniqueFragment) !== 1) {
        $fail(sprintf('Rendered checkout shell must contain exactly one %s marker.', $uniqueFragment));
    }
}

$deliveryMarker = 'data-jzopc-section="delivery"';
if ($cart->isVirtualCart()) {
    if (str_contains($html, $deliveryMarker)) {
        $fail('Virtual runtime cart unexpectedly rendered a delivery section.');
    }
} elseif (substr_count($html, $deliveryMarker) !== 1) {
    $fail('Physical runtime cart must render exactly one delivery section.');
}

$cartBinding = sprintf('data-jzopc-cart-id="%d"', (int) $cart->id);
if (substr_count($html, $cartBinding) !== 1) {
    $fail('Rendered checkout bootstrap is not uniquely bound to the runtime cart.');
}

foreach (['csrf-token', 'state-version', 'payment-url', 'agreements-url'] as $attribute) {
    if (!preg_match('/data-jzopc-' . preg_quote($attribute, '/') . '="([^"]+)"/', $html, $matches)) {
        $fail(sprintf('Rendered checkout bootstrap attribute %s is unavailable.', $attribute));
    }
    if (html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') === '') {
        $fail(sprintf('Rendered checkout bootstrap attribute %s is empty.', $attribute));
    }
}

if (!preg_match('/data-jzopc-payment-url="([^"]+)"/', $html, $paymentMatches)) {
    $fail('Payment mutation URL is unavailable.');
}
if (!preg_match('/data-jzopc-agreements-url="([^"]+)"/', $html, $agreementMatches)) {
    $fail('Agreement mutation URL is unavailable.');
}
$paymentUrl = html_entity_decode($paymentMatches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
$agreementsUrl = html_entity_decode($agreementMatches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
if (!str_contains($paymentUrl, 'jzonepagecheckout') || !str_contains($paymentUrl, 'paymentselection')) {
    $fail('Payment mutation URL does not target the module paymentselection controller.');
}
if (!str_contains($agreementsUrl, 'jzonepagecheckout') || !str_contains($agreementsUrl, 'agreements')) {
    $fail('Agreement mutation URL does not target the module agreements controller.');
}

$cartId = (int) $cart->id;
if (!$cart->delete()) {
    $fail('Runtime checkout cart cleanup failed.');
}

fwrite(STDOUT, sprintf(
    "Installed Smarty shell contract OK: PrestaShop %s, cart=%d, bytes=%d\n",
    _PS_VERSION_,
    $cartId,
    strlen($html),
));
