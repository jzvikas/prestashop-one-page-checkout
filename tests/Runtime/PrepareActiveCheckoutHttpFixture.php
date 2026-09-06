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
$countryId = (int) Configuration::get('PS_COUNTRY_DEFAULT');
if ($shopId <= 0 || $shopGroupId <= 0 || $languageId <= 0 || $currencyId <= 0 || $countryId <= 0) {
    $fail('Default shop/group/language/currency/country configuration is invalid.');
}

Shop::setContext(Shop::CONTEXT_SHOP, $shopId);
$context->shop = new Shop($shopId);
$context->language = new Language($languageId);
$context->currency = new Currency($currencyId);
$context->country = new Country($countryId);

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

// Runtime installs deliberately use --fixtures=0, so they cannot rely on demo carriers. Build one
// through the Core Carrier model and persist the same shop/zone/group relations used by normal
// carrier discovery. The browser still has to obtain and select this carrier through Core checkout;
// no delivery option is injected into OPC state or DOM.
$country = new Country($countryId);
if (!Validate::isLoadedObject($country) || (int) $country->id_zone <= 0) {
    $fail('Default runtime country does not provide a valid Core delivery zone.');
}
$activeZones = Zone::getZones(true);
if (!is_array($activeZones) || $activeZones === []) {
    $fail('No active Core delivery zone exists for runtime carrier setup.');
}

$carrier = new Carrier();
$carrier->name = 'JZ OPC Runtime Carrier';
$carrier->active = true;
$carrier->deleted = false;
$carrier->is_free = true;
$carrier->shipping_handling = false;
$carrier->range_behavior = false;
$carrier->shipping_external = false;
$carrier->need_range = false;
$carrier->shipping_method = Carrier::SHIPPING_METHOD_FREE;
$carrier->grade = 0;
$carrier->delay = [];
foreach ($languages as $language) {
    $idLang = (int) ($language['id_lang'] ?? 0);
    if ($idLang > 0) {
        $carrier->delay[$idLang] = 'Runtime delivery';
    }
}
if ($carrier->delay === [] || !$carrier->add() || (int) $carrier->id <= 0) {
    $fail('Unable to create the runtime carrier through PrestaShop Carrier.');
}

foreach ($activeZones as $zoneRow) {
    $zoneId = (int) ($zoneRow['id_zone'] ?? 0);
    if ($zoneId <= 0) {
        continue;
    }
    if (!$carrier->addZone($zoneId)) {
        $carrier->delete();
        $fail(sprintf('Unable to associate the runtime carrier with Core delivery zone %d.', $zoneId));
    }
}
if (!Carrier::checkCarrierZone((int) $carrier->id, (int) $country->id_zone)) {
    $carrier->delete();
    $fail('Runtime carrier is unavailable in the default Core delivery zone after association.');
}

$db = Db::getInstance();
$groupRows = $db->executeS('SELECT `id_group` FROM `' . _DB_PREFIX_ . 'group`');
if (!is_array($groupRows) || $groupRows === []) {
    $carrier->delete();
    $fail('No Core customer group exists for runtime carrier association.');
}
foreach ($groupRows as $groupRow) {
    $groupId = (int) ($groupRow['id_group'] ?? 0);
    if ($groupId <= 0) {
        continue;
    }
    if (!$db->insert(
        'carrier_group',
        ['id_carrier' => (int) $carrier->id, 'id_group' => $groupId],
        false,
        true,
        Db::INSERT_IGNORE,
    )) {
        $carrier->delete();
        $fail(sprintf('Unable to associate runtime carrier with Core customer group %d.', $groupId));
    }
}
if (!$db->insert(
    'carrier_shop',
    ['id_carrier' => (int) $carrier->id, 'id_shop' => $shopId],
    false,
    true,
    Db::INSERT_IGNORE,
)) {
    $carrier->delete();
    $fail('Unable to associate runtime carrier with the runtime shop.');
}
if (!Configuration::updateValue('PS_CARRIER_DEFAULT', (int) $carrier->id, false, $shopGroupId, $shopId)) {
    $carrier->delete();
    $fail('Unable to make the runtime Core carrier the shop default.');
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
    $carrier->delete();
    $fail('Unable to build multilingual runtime product fields.');
}

if (!$product->add()) {
    $carrier->delete();
    $fail('Unable to create runtime checkout product through PrestaShop Product.');
}
if (!$product->addToCategories([$homeCategoryId])) {
    $product->delete();
    $carrier->delete();
    $fail('Unable to assign runtime checkout product to the home category.');
}

StockAvailable::setQuantity((int) $product->id, 0, 25, $shopId);
if ((int) Product::getQuantity((int) $product->id) < 1) {
    $product->delete();
    $carrier->delete();
    $fail('Runtime checkout product stock was not persisted.');
}

fwrite(STDOUT, (string) ((int) $product->id) . PHP_EOL);
