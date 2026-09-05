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

// Mirror the real Core checkout lifecycle before rendering. AbstractCheckoutStep starts
// unreachable and getTemplate() intentionally returns Core's unreachable.tpl until
// CheckoutProcess::handleRequest() delegates to the module step.
$process->handleRequest([]);

$steps = $process->getSteps();
if (count($steps) !== 1 || !$steps[0] instanceof CheckoutShellStep) {
    $fail('Module process did not expose exactly one CheckoutShellStep.');
}
if (!$steps[0]->isReachable() || !$steps[0]->isCurrent() || $steps[0]->isComplete()) {
    $fail('CheckoutShellStep did not establish the expected reachable/current/incomplete Core lifecycle state.');
}

$html = $steps[0]->render();
if (!is_string($html) || trim($html) === '') {
    $fail('CheckoutShellStep rendered empty HTML.');
}

$requiredFragments = [
    'data-jzopc-step="one-page-checkout"',
    'data-jzopc-checkout',
    'data-jzopc-section="identity"',
    'data-jzopc-identity-form="create"',
    'data-jzopc-identity-form="login"',
    'data-jzopc-section="addresses"',
    'data-jzopc-section="payment"',
    'data-jzopc-section="agreements"',
    'data-jzopc-section="summary"',
    'data-jzopc-csrf-token="',
    'data-jzopc-state-version="',
    'data-jzopc-identity-url="',
    'data-jzopc-address-url="',
    'data-jzopc-address-save-url="',
    'data-jzopc-carrier-url="',
    'data-jzopc-payment-url="',
    'data-jzopc-agreements-url="',
    'data-jzopc-finalization-url="',
    'data-jzopc-finalization-reserved="0"',
    'data-jzopc-final-submit',
    'data-jzopc-final-status',
];
foreach ($requiredFragments as $fragment) {
    if (!str_contains($html, $fragment)) {
        $fail(sprintf('Rendered checkout shell is missing required fragment: %s', $fragment));
    }
}

foreach ([
    'data-jzopc-step="one-page-checkout"',
    'data-jzopc-checkout',
    'data-jzopc-section="identity"',
    'data-jzopc-identity-form="create"',
    'data-jzopc-identity-form="login"',
    'data-jzopc-section="addresses"',
    'data-jzopc-section="payment"',
    'data-jzopc-section="agreements"',
    'data-jzopc-section="summary"',
    'data-jzopc-finalization-reserved="0"',
    'data-jzopc-final-submit',
    'data-jzopc-final-status',
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

foreach (['csrf-token', 'state-version', 'identity-url', 'address-url', 'address-save-url', 'carrier-url', 'payment-url', 'agreements-url', 'finalization-url'] as $attribute) {
    if (!preg_match('/data-jzopc-' . preg_quote($attribute, '/') . '="([^"]+)"/', $html, $matches)) {
        $fail(sprintf('Rendered checkout bootstrap attribute %s is unavailable.', $attribute));
    }
    if (html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') === '') {
        $fail(sprintf('Rendered checkout bootstrap attribute %s is empty.', $attribute));
    }
}

$controllerTargets = [
    'identity' => 'identity',
    'address' => 'addressselection',
    'address-save' => 'addresssave',
    'carrier' => 'carrierselection',
    'payment' => 'paymentselection',
    'agreements' => 'agreements',
    'finalization' => 'finalize',
];
foreach ($controllerTargets as $attribute => $controller) {
    if (!preg_match('/data-jzopc-' . preg_quote($attribute, '/') . '-url="([^"]+)"/', $html, $matches)) {
        $fail(sprintf('%s mutation URL is unavailable.', ucfirst($attribute)));
    }
    $url = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (!str_contains($url, 'jzonepagecheckout') || !str_contains($url, $controller)) {
        $fail(sprintf('%s mutation URL does not target the expected module controller.', ucfirst($attribute)));
    }
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
