<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelectionsStoreInterface;
use Jzvikas\OnePageCheckout\Checkout\CheckoutStateVersioner;
use Jzvikas\OnePageCheckout\Checkout\Finalization\CheckoutFinalizationReservationStoreInterface;
use Jzvikas\OnePageCheckout\Checkout\PrestaShopCheckoutStateFactory;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSectionRendererRegistry;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutTemplateRendererInterface;
use Jzvikas\OnePageCheckout\Integration\CheckoutBrowserBootstrapFactory;
use Jzvikas\OnePageCheckout\Integration\CheckoutFrontendAssetRegistrar;
use Jzvikas\OnePageCheckout\Integration\CheckoutProcessBuilder;
use Jzvikas\OnePageCheckout\Integration\CheckoutShellRenderer;
use Jzvikas\OnePageCheckout\Integration\CheckoutShellStep;
use Jzvikas\OnePageCheckout\Integration\LegacyCheckoutRenderAdapter;
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

final class InjectedFailingSelectionsStore implements CheckoutServerSelectionsStoreInterface
{
    public int $loadCalls = 0;

    public function load(\Context $context): CheckoutServerSelections
    {
        ++$this->loadCalls;

        throw new RuntimeException('Injected checkout selection read failure.');
    }

    public function save(\Context $context, CheckoutServerSelections $selections): void
    {
        throw new LogicException('Failure fixture must never save checkout selections.');
    }

    public function delete(\Context $context): void
    {
        throw new LogicException('Failure fixture must never delete checkout selections.');
    }
}

final class UnreachableFinalizationReservationStore implements CheckoutFinalizationReservationStoreInterface
{
    public function acquire(\Context $context, string $stateVersion, string $paymentSelection, string $attemptId): void
    {
        throw new LogicException('Failure fixture must not reach finalization reservation acquisition.');
    }

    public function isActive(\Context $context): bool
    {
        throw new LogicException('Failure fixture must not reach finalization reservation lookup.');
    }

    public function releaseAttempt(\Context $context, string $attemptId): void
    {
        throw new LogicException('Failure fixture must not release finalization reservations.');
    }

    public function clear(\Context $context): void
    {
        throw new LogicException('Failure fixture must not clear finalization reservations.');
    }
}

final class UnreachableTemplateRenderer implements CheckoutTemplateRendererInterface
{
    public function render(\Context $context, string $template, array $variables): string
    {
        throw new LogicException('Failure fixture must not reach Smarty rendering.');
    }
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

$selectionsStore = new InjectedFailingSelectionsStore();
$shellRenderer = new CheckoutShellRenderer(
    $selectionsStore,
    new UnreachableFinalizationReservationStore(),
    new CheckoutBrowserBootstrapFactory(
        new PrestaShopCheckoutStateFactory(),
        new CheckoutStateVersioner(),
    ),
    new CheckoutSectionRendererRegistry([]),
    new UnreachableTemplateRenderer(),
    new CheckoutFrontendAssetRegistrar(),
);
$failingBuilder = new CheckoutProcessBuilder($shellRenderer);

if (in_array($expectedFamily, ['9.0', '9.1'], true)) {
    $coreProcess = new CheckoutProcess($context, $session);
    $params = ['checkoutProcess' => $coreProcess];
    $adapter = new LegacyCheckoutRenderAdapter($failingBuilder);

    $injectedFailureObserved = false;
    try {
        $adapter->replaceProcess($params, $context, $translator);
    } catch (RuntimeException $exception) {
        $injectedFailureObserved = $exception->getMessage() === 'Injected checkout selection read failure.';
    }

    if (!$injectedFailureObserved) {
        $fail(sprintf('PrestaShop %s legacy path did not expose the injected eager shell failure.', $expectedFamily));
    }
    if ($selectionsStore->loadCalls !== 1) {
        $fail(sprintf('PrestaShop %s legacy failure fixture expected exactly one selection read.', $expectedFamily));
    }
    if (($params['checkoutProcess'] ?? null) !== $coreProcess) {
        $fail(sprintf('PrestaShop %s legacy failure mutated the Core checkout process reference.', $expectedFamily));
    }
    if ($coreProcess->getCheckoutSession() !== $session) {
        $fail(sprintf('PrestaShop %s native Core process lost the original CheckoutSession after failure.', $expectedFamily));
    }
} else {
    if (!interface_exists('PrestaShop\\PrestaShop\\Adapter\\Order\\Checkout\\CheckoutProcessProviderInterface')) {
        $fail('PrestaShop 9.2 provider interface is unavailable.');
    }
    if (!$translator instanceof TranslatorComponent) {
        $fail('PrestaShop 9.2 Context translator is not the required TranslatorComponent.');
    }

    $injectedFailureObserved = false;
    try {
        $failingBuilder->prepareShell($context);
    } catch (RuntimeException $exception) {
        $injectedFailureObserved = $exception->getMessage() === 'Injected checkout selection read failure.';
    }

    if (!$injectedFailureObserved || $selectionsStore->loadCalls !== 1) {
        $fail('PrestaShop 9.2 eager shell preparation did not fail at the injected persistence boundary.');
    }

    // A provider may exist only after shell preparation has already succeeded. Its later Core call
    // must consume the prepared string and must not touch DB/template/hook shell dependencies again.
    $preparedShellHtml = '<div data-jzopc-runtime-prepared-fallback-shell="1"></div>';
    $provider = new Jzvikas\OnePageCheckout\Integration\Provider\CheckoutProcessProvider(
        $context,
        $failingBuilder,
        $preparedShellHtml,
    );
    $providerProcess = $provider->buildCheckoutProcess($session, $translator);
    if (!$providerProcess instanceof CheckoutProcess) {
        $fail('PrestaShop 9.2 provider did not build a Core process from already-prepared shell HTML.');
    }
    if ($providerProcess->getCheckoutSession() !== $session) {
        $fail('PrestaShop 9.2 provider did not preserve the exact Core CheckoutSession.');
    }
    if ($selectionsStore->loadCalls !== 1) {
        $fail('PrestaShop 9.2 provider performed risky shell rendering after provider selection.');
    }
    $steps = $providerProcess->getSteps();
    if (count($steps) !== 1 || !$steps[0] instanceof CheckoutShellStep) {
        $fail('PrestaShop 9.2 prepared provider process must contain exactly one CheckoutShellStep.');
    }
}

$cartId = (int) $cart->id;
if (!$cart->delete()) {
    $fail('Runtime checkout cart cleanup failed.');
}

fwrite(STDOUT, sprintf(
    "Integration failure isolation contract OK: PrestaShop %s, cart=%d, injected_reads=%d\n",
    _PS_VERSION_,
    $cartId,
    $selectionsStore->loadCalls,
));
