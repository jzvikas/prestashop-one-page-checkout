<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Integration\CheckoutProcessBuilder;
use Jzvikas\OnePageCheckout\Integration\CheckoutShellStep;
use Jzvikas\OnePageCheckout\Integration\LegacyCheckoutRenderAdapter;
use Jzvikas\OnePageCheckout\Integration\Provider\CheckoutProcessProvider;
use PrestaShopBundle\Translation\TranslatorComponent;
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
if (!$context instanceof Context) {
    $fail('PrestaShop Context is unavailable.');
}

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

// A normal front request initializes Controller::$container from buildContainer()
// inside Controller::init(). Calling full init() in this CLI contract would also run
// request hooks and redirect-sensitive front-controller behavior, so expose exactly
// that protected bootstrap step and nothing else.
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
    $fail('CheckoutProcessBuilder service is unavailable in installed module container.');
}

$moduleProcess = $builder->build($context, $session, $translator);
if (!$moduleProcess instanceof CheckoutProcess) {
    $fail('CheckoutProcessBuilder did not return a Core CheckoutProcess.');
}
if ($moduleProcess->getCheckoutSession() !== $session) {
    $fail('Module checkout process did not preserve the supplied Core CheckoutSession instance.');
}
$steps = $moduleProcess->getSteps();
if (count($steps) !== 1 || !$steps[0] instanceof CheckoutShellStep) {
    $fail('Module checkout process must contain exactly one CheckoutShellStep.');
}

if (in_array($expectedFamily, ['9.0', '9.1'], true)) {
    $adapter = $module->get(LegacyCheckoutRenderAdapter::class);
    if (!$adapter instanceof LegacyCheckoutRenderAdapter) {
        $fail('LegacyCheckoutRenderAdapter service is unavailable.');
    }

    $coreProcess = new CheckoutProcess($context, $session);
    $params = ['checkoutProcess' => $coreProcess];
    if (!$adapter->replaceProcess($params, $context, $translator)) {
        $fail(sprintf('PrestaShop %s legacy adapter rejected a real Core CheckoutProcess payload.', $expectedFamily));
    }
    $replacement = $params['checkoutProcess'] ?? null;
    if (!$replacement instanceof CheckoutProcess || $replacement === $coreProcess) {
        $fail(sprintf('PrestaShop %s legacy adapter did not replace the Core process.', $expectedFamily));
    }
    if ($replacement->getCheckoutSession() !== $session) {
        $fail(sprintf('PrestaShop %s legacy adapter did not preserve the exact Core CheckoutSession instance.', $expectedFamily));
    }
} else {
    if (!interface_exists('PrestaShop\\PrestaShop\\Adapter\\Order\\Checkout\\CheckoutProcessProviderInterface')) {
        $fail('PrestaShop 9.2 provider interface is unavailable.');
    }
    if (!$translator instanceof TranslatorComponent) {
        $fail('PrestaShop 9.2 Context translator is not the required TranslatorComponent.');
    }

    $provider = new CheckoutProcessProvider($context, $builder);
    if (!$provider->isEnabled()) {
        $fail('Direct provider contract must report enabled after activation has already selected it.');
    }
    $providerProcess = $provider->buildCheckoutProcess($session, $translator);
    if (!$providerProcess instanceof CheckoutProcess) {
        $fail('Provider did not return a Core CheckoutProcess.');
    }
    if ($providerProcess->getCheckoutSession() !== $session) {
        $fail('Provider did not preserve the supplied Core CheckoutSession instance.');
    }
    $providerSteps = $providerProcess->getSteps();
    if (count($providerSteps) !== 1 || !$providerSteps[0] instanceof CheckoutShellStep) {
        $fail('Provider checkout process must contain exactly one CheckoutShellStep.');
    }
}

$cartId = (int) $cart->id;
if (!$cart->delete()) {
    $fail('Runtime checkout cart cleanup failed.');
}

fwrite(STDOUT, sprintf(
    "Core process adapter contract OK: PrestaShop %s, cart=%d\n",
    _PS_VERSION_,
    $cartId,
));
