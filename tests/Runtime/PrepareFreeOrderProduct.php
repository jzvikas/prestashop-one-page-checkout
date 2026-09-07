<?php

declare(strict_types=1);

$shopRoot = $argv[1] ?? '';
$expectedFamily = $argv[2] ?? '';

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if (getenv('JZOPC_RUNTIME_ACTIVE_FIXTURE') !== '1') {
    $fail('Free-order runtime fixture requires the explicit active-fixture environment guard.');
}
if ($shopRoot !== '/tmp/prestashop' || !is_file($shopRoot . '/config/config.inc.php')) {
    $fail('Free-order fixture only runs against /tmp/prestashop.');
}
if ($expectedFamily !== '9.1') {
    $fail('Free-order runtime fixture currently requires PrestaShop 9.1.');
}

require_once $shopRoot . '/config/config.inc.php';

if (!str_starts_with((string) _PS_VERSION_, '9.1.')) {
    $fail(sprintf('Installed PrestaShop version %s does not match the expected 9.1 family.', _PS_VERSION_));
}

$modulePath = realpath($shopRoot . '/modules/jzonepagecheckout/jzonepagecheckout.php');
if (!is_string($modulePath) || !str_starts_with($modulePath, '/tmp/jzopc-active-fixture')) {
    $fail('Free-order fixture refuses to run against the production/source module tree.');
}

$shopId = (int) Configuration::get('PS_SHOP_DEFAULT');
$languageId = (int) Configuration::get('PS_LANG_DEFAULT');
$homeCategoryId = (int) Configuration::get('PS_HOME_CATEGORY');
if ($shopId <= 0 || $languageId <= 0 || $homeCategoryId <= 0) {
    $fail('Runtime shop/language/home-category configuration is invalid.');
}

Shop::setContext(Shop::CONTEXT_SHOP, $shopId);
$context = Context::getContext();
$context->shop = new Shop($shopId);
$context->language = new Language($languageId);

$languages = Language::getLanguages(true, $shopId);
if (!is_array($languages) || $languages === []) {
    $fail('No active shop language is available for the free-order product.');
}

$suffix = bin2hex(random_bytes(4));
$product = new Product();
$product->id_shop_default = $shopId;
$product->id_category_default = $homeCategoryId;
$product->id_tax_rules_group = 0;
$product->reference = 'JZOPC-FREE-' . strtoupper($suffix);
$product->price = 0.0;
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
    $product->name[$idLang] = 'JZ OPC Runtime Free Order Product';
    $product->link_rewrite[$idLang] = 'jz-opc-runtime-free-order-' . $suffix;
}

if ($product->name === [] || $product->link_rewrite === [] || !$product->add()) {
    $fail('Unable to create the zero-total runtime product through Core Product.');
}
if (!$product->addToCategories([$homeCategoryId])) {
    $fail('Unable to assign the zero-total runtime product to the home category.');
}

StockAvailable::setQuantity((int) $product->id, 0, 10, $shopId);
if ((int) Product::getQuantity((int) $product->id) < 1) {
    $fail('Zero-total runtime product does not have orderable stock.');
}

$loaded = new Product((int) $product->id, false, $languageId, $shopId);
if (!Validate::isLoadedObject($loaded)
    || 0.0 !== (float) $loaded->price
    || !$loaded->active
    || !$loaded->available_for_order) {
    $fail('Zero-total runtime product did not persist its Core orderability state.');
}

fwrite(STDOUT, (string) (int) $product->id . PHP_EOL);
