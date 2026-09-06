<?php

declare(strict_types=1);

$shopRoot = $argv[1] ?? '';
$expectedFamily = $argv[2] ?? '';
$productId = isset($argv[3]) ? (int) $argv[3] : 0;

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if ($shopRoot === '' || !is_file($shopRoot . '/config/config.inc.php')) {
    $fail('Installed PrestaShop root is missing or invalid.');
}
if (!in_array($expectedFamily, ['9.0', '9.1'], true)) {
    $fail('Active Core carrier contract is limited to PrestaShop 9.0/9.1 runtime families.');
}
if ($productId <= 0) {
    $fail('Runtime product identifier is missing or invalid.');
}

require_once $shopRoot . '/config/config.inc.php';

if (!str_starts_with((string) _PS_VERSION_, $expectedFamily . '.')) {
    $fail(sprintf('Installed PrestaShop version %s does not match expected family %s.', _PS_VERSION_, $expectedFamily));
}

$shopId = (int) Configuration::get('PS_SHOP_DEFAULT');
$shopGroupId = (int) Shop::getGroupFromShop($shopId);
$languageId = (int) Configuration::get('PS_LANG_DEFAULT');
$currencyId = (int) Configuration::get('PS_CURRENCY_DEFAULT');
$countryId = (int) Configuration::get('PS_COUNTRY_DEFAULT');
$guestGroupId = (int) Configuration::get('PS_GUEST_GROUP');
if ($shopId <= 0 || $shopGroupId <= 0 || $languageId <= 0 || $currencyId <= 0 || $countryId <= 0 || $guestGroupId <= 0) {
    $fail('Runtime shop/language/currency/country/guest-group configuration is invalid.');
}

Shop::setContext(Shop::CONTEXT_SHOP, $shopId);
$context = Context::getContext();
$context->shop = new Shop($shopId);
$context->language = new Language($languageId);
$context->currency = new Currency($currencyId);
$context->country = new Country($countryId);

$product = new Product($productId, false, $languageId, $shopId);
if (!Validate::isLoadedObject($product) || !$product->active || !$product->available_for_order) {
    $fail('Runtime product is not a loaded, active Core orderable product.');
}
if ((int) Product::getQuantity($productId) < 1) {
    $fail('Runtime product has no Core stock for carrier discovery.');
}

$carrierId = (int) Configuration::get('PS_CARRIER_DEFAULT', null, $shopGroupId, $shopId);
if ($carrierId <= 0) {
    $fail('Runtime shop does not expose a positive Core default carrier.');
}
$carrier = new Carrier($carrierId, $languageId);
if (!Validate::isLoadedObject($carrier)) {
    $fail(sprintf('Runtime Core default carrier %d cannot be loaded.', $carrierId));
}
if ((string) $carrier->name !== 'JZ OPC Runtime Carrier') {
    $fail(sprintf('Runtime Core default carrier %d is not the deterministic OPC fixture carrier.', $carrierId));
}
if (!$carrier->active || $carrier->deleted || !$carrier->is_free || (bool) $carrier->is_module || (bool) $carrier->need_range) {
    $fail('Runtime Core carrier flags are incompatible with deterministic free non-module discovery.');
}
if ((int) $carrier->shipping_method !== Carrier::SHIPPING_METHOD_FREE) {
    $fail('Runtime Core carrier is not configured with the Core free shipping method.');
}

$country = new Country($countryId);
if (!Validate::isLoadedObject($country) || !$country->active || (int) $country->id_zone <= 0) {
    $fail('Runtime default country is not an active country with a Core delivery zone.');
}
if (!Carrier::checkCarrierZone($carrierId, (int) $country->id_zone)) {
    $fail('Runtime Core carrier is not associated with the default country delivery zone.');
}

$db = Db::getInstance();
$shopAssociation = (int) $db->getValue(
    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'carrier_shop` WHERE `id_carrier` = ' . $carrierId . ' AND `id_shop` = ' . $shopId
);
$groupAssociation = (int) $db->getValue(
    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'carrier_group` WHERE `id_carrier` = ' . $carrierId . ' AND `id_group` = ' . $guestGroupId
);
if ($shopAssociation !== 1) {
    $fail('Runtime Core carrier does not have exactly one current-shop association.');
}
if ($groupAssociation !== 1) {
    $fail('Runtime Core carrier is not available to the configured guest customer group.');
}

if ($expectedFamily === '9.1') {
    $checkPayment = Module::getInstanceByName('ps_checkpayment');
    if (!$checkPayment instanceof PaymentModule || !Module::isEnabled('ps_checkpayment') || (int) $checkPayment->id <= 0) {
        $fail('PrestaShop 9.1 ps_checkpayment runtime fixture is not installed and enabled.');
    }
    $carrierReference = (int) $carrier->id_reference;
    if ($carrierReference <= 0) {
        $fail('Runtime carrier does not expose a positive carrier reference.');
    }
    $paymentCarrierAssociation = (int) $db->getValue(
        'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'module_carrier`'
        . ' WHERE `id_module` = ' . (int) $checkPayment->id
        . ' AND `id_shop` = ' . $shopId
        . ' AND `id_reference` = ' . $carrierReference
    );
    if ($paymentCarrierAssociation !== 1) {
        $fail('ps_checkpayment is not associated with the deterministic Core runtime carrier restriction.');
    }
}

$probeCart = new Cart();
$probeCart->id_shop = $shopId;
$probeCart->id_shop_group = $shopGroupId;
$probeCart->id_lang = $languageId;
$probeCart->id_currency = $currencyId;
$probeCart->id_customer = 0;
$probeCart->id_address_delivery = 0;

$carrierError = [];
$forOrder = Carrier::getCarriersForOrder((int) $country->id_zone, [$guestGroupId], $probeCart, $carrierError);
if (!is_array($forOrder)) {
    $fail('Carrier::getCarriersForOrder() did not return a Core carrier list.');
}
$foundForOrder = false;
foreach ($forOrder as $row) {
    if (is_array($row) && (int) ($row['id_carrier'] ?? 0) === $carrierId) {
        $foundForOrder = true;
        break;
    }
}
if (!$foundForOrder) {
    $errorCodes = array_values(array_filter(array_map('strval', is_array($carrierError) ? $carrierError : [])));
    $fail(sprintf(
        'Runtime Core carrier is filtered out by Carrier::getCarriersForOrder() for the default zone/guest group%s.',
        $errorCodes === [] ? '' : ' [' . implode(',', $errorCodes) . ']'
    ));
}

$availableForProduct = Carrier::getAvailableCarrierList($product, 0, null, $shopId, $probeCart);
if (!is_array($availableForProduct) || !array_key_exists($carrierId, $availableForProduct)) {
    $fail('Runtime Core carrier is filtered out by Carrier::getAvailableCarrierList() for the fixture product.');
}

fwrite(STDOUT, sprintf(
    "Active Core carrier availability contract passed for PrestaShop %s (carrier=%d, product=%d, zone=%d, guest_group=%d).\n",
    _PS_VERSION_,
    $carrierId,
    $productId,
    (int) $country->id_zone,
    $guestGroupId,
));
