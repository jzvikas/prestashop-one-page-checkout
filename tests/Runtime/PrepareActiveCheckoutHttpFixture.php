<?php

declare(strict_types=1);

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

$modulePath = realpath($shopRoot . '/modules/jzonepagecheckout/jzonepagecheckout.php');
if (!is_string($modulePath) || !str_starts_with($modulePath, '/tmp/jzopc-active-fixture')) {
    $fail('Active HTTP setup refuses to run against the production/source module tree.');
}
$moduleSource = file_get_contents($modulePath);
if (!is_string($moduleSource)
    || !str_contains($moduleSource, 'private const INTEGRATION_SHELL_READY = true;')
    || str_contains($moduleSource, 'private const INTEGRATION_SHELL_READY = false;')) {
    $fail('Temporary active checkout fixture does not have the expected test-only readiness gate.');
}

$context = Context::getContext();
if (!$context instanceof Context) {
    $fail('PrestaShop Context is unavailable.');
}

$shopId = (int) Configuration::get('PS_SHOP_DEFAULT');
$shopGroupId = (int) Shop::getGroupFromShop($shopId);
$languageId = (int) Configuration::get('PS_LANG_DEFAULT');
$currencyId = (int) Configuration::get('PS_CURRENCY_DEFAULT');
if ($shopId <= 0 || $shopGroupId <= 0 || $languageId <= 0 || $currencyId <= 0) {
    $fail('Default shop/group/language/currency configuration is invalid.');
}

Shop::setContext(Shop::CONTEXT_SHOP, $shopId);
$context->shop = new Shop($shopId);
$context->language = new Language($languageId);
$context->currency = new Currency($currencyId);

$nativeOpc = Module::getInstanceByName('ps_onepagecheckout');
if ($nativeOpc instanceof Module && Module::isEnabled('ps_onepagecheckout')) {
    if (!$nativeOpc->disable()) {
        $fail('Unable to disable native ps_onepagecheckout in the temporary active runtime fixture.');
    }
}

if (!Configuration::updateValue(
    JzOnePageCheckout::CONFIG_CHECKOUT_ENABLED,
    true,
    false,
    $shopGroupId,
    $shopId,
)) {
    $fail('Unable to enable the temporary active checkout fixture for the runtime shop.');
}

$module = Module::getInstanceByName('jzonepagecheckout');
if (!$module instanceof JzOnePageCheckout) {
    $fail('Unable to load JzOnePageCheckout from the active runtime fixture.');
}
if (!$module->isCustomCheckoutActive()) {
    $fail('Temporary runtime checkout activation policy did not allow the active fixture.');
}

$homeCategoryId = (int) Configuration::get('PS_HOME_CATEGORY');
if ($homeCategoryId <= 0) {
    $fail('PrestaShop home category is unavailable for runtime product setup.');
}

$languages = Language::getLanguages(true, $shopId);
if (!is_array($languages) || $languages === []) {
    $fail('No active shop language is available for runtime product setup.');
}

$suffix = bin2hex(random_bytes(4));
$product = new Product();
$product->id_shop_default = $shopId;
$product->id_category_default = $homeCategoryId;
$product->id_tax_rules_group = 0;
$product->reference = 'JZOPC-RUNTIME-' . strtoupper($suffix);
$product->price = 12.34;
$product->wholesale_price = 0.0;
$product->minimal_quantity = 1;
$product->active = true;
$product->available_for_order = true;
$product->show_price = true;
$product->visibility = 'both';
$product->condition = 'new';
$product->state = Product::STATE_SAVED;
$product->name = [];
$product->link_rewrite = [];
foreach ($languages as $language) {
    $idLang = (int) ($language['id_lang'] ?? 0);
    if ($idLang <= 0) {
        continue;
    }
    $product->name[$idLang] = 'JZ OPC Runtime Checkout Product';
    $product->link_rewrite[$idLang] = 'jz-opc-runtime-checkout-' . $suffix;
}
if ($product->name === [] || $product->link_rewrite === []) {
    $fail('Unable to build multilingual runtime product fields.');
}

if (!$product->add()) {
    $fail('Unable to create runtime checkout product through PrestaShop Product.');
}
if (!$product->addToCategories([$homeCategoryId])) {
    $product->delete();
    $fail('Unable to assign runtime checkout product to the home category.');
}

StockAvailable::setQuantity((int) $product->id, 0, 25, $shopId);
if ((int) Product::getQuantity((int) $product->id) < 1) {
    $product->delete();
    $fail('Runtime checkout product stock was not persisted.');
}

fwrite(STDOUT, (string) ((int) $product->id) . PHP_EOL);
