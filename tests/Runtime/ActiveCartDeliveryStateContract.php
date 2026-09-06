<?php

declare(strict_types=1);

$shopRoot = $argv[1] ?? '';
$expectedFamily = $argv[2] ?? '';
$productId = isset($argv[3]) ? (int) $argv[3] : 0;

$fail = static function (string $message, array $diagnostics = []): never {
    if ($diagnostics !== []) {
        fwrite(STDERR, 'Live-cart delivery diagnostics: ' . json_encode($diagnostics, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if ($shopRoot === '' || !is_file($shopRoot . '/config/config.inc.php')) {
    $fail('Installed PrestaShop root is missing or invalid.');
}
if ($expectedFamily !== '9.1') {
    $fail('Live-cart delivery-state contract is currently limited to the PrestaShop 9.1 production milestone.');
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
$defaultCountryId = (int) Configuration::get('PS_COUNTRY_DEFAULT');
if ($shopId <= 0 || $shopGroupId <= 0 || $languageId <= 0 || $currencyId <= 0 || $defaultCountryId <= 0) {
    $fail('Runtime shop/group/language/currency/default-country configuration is invalid.');
}

Shop::setContext(Shop::CONTEXT_SHOP, $shopId);
$context = Context::getContext();
$context->shop = new Shop($shopId);
$context->language = new Language($languageId);
$context->currency = new Currency($currencyId);
$context->country = new Country($defaultCountryId);

$db = Db::getInstance();
$cartId = (int) $db->getValue(
    'SELECT c.`id_cart`'
    . ' FROM `' . _DB_PREFIX_ . 'cart` c'
    . ' INNER JOIN `' . _DB_PREFIX_ . 'cart_product` cp ON cp.`id_cart` = c.`id_cart`'
    . ' WHERE cp.`id_product` = ' . $productId
    . ' AND c.`id_shop` = ' . $shopId
    . ' ORDER BY c.`id_cart` DESC LIMIT 1'
);
if ($cartId <= 0) {
    $fail('No live Core cart contains the runtime product after the browser checkout preparation.');
}

$cart = new Cart($cartId);
if (!Validate::isLoadedObject($cart)) {
    $fail(sprintf('Live Core cart %d cannot be loaded.', $cartId));
}
$context->cart = $cart;

$customerId = (int) $cart->id_customer;
$deliveryAddressId = (int) $cart->id_address_delivery;
$invoiceAddressId = (int) $cart->id_address_invoice;
$diagnostics = [
    'prestashop' => (string) _PS_VERSION_,
    'shop_id' => (int) $cart->id_shop,
    'cart_id' => $cartId,
    'customer_id' => $customerId,
    'delivery_address_id' => $deliveryAddressId,
    'invoice_address_id' => $invoiceAddressId,
    'product_id' => $productId,
];

if ((int) $cart->id_shop !== $shopId) {
    $fail('Browser-prepared cart is not bound to the runtime shop.', $diagnostics);
}
if ($customerId <= 0) {
    $fail('Browser guest-identity mutation did not persist a positive Core cart customer.', $diagnostics);
}
$customer = new Customer($customerId);
if (!Validate::isLoadedObject($customer)) {
    $fail('Browser-prepared cart customer cannot be loaded through Core.', $diagnostics);
}
$diagnostics['customer_is_guest'] = (bool) $customer->is_guest;
$diagnostics['customer_default_group'] = (int) $customer->id_default_group;

$groups = array_values(array_unique(array_map('intval', Customer::getGroupsStatic($customerId))));
$groups = array_values(array_filter($groups, static fn (int $id): bool => $id > 0));
sort($groups);
$diagnostics['customer_groups'] = $groups;
if ($groups === []) {
    $fail('Browser-created customer has no Core customer-group membership.', $diagnostics);
}

if ($deliveryAddressId <= 0 || $invoiceAddressId <= 0) {
    $fail('Browser address mutation did not persist both Core cart address bindings.', $diagnostics);
}
if (!Customer::customerHasAddress($customerId, $deliveryAddressId)) {
    $fail('Persisted cart delivery address is not owned by the browser-created customer.', $diagnostics);
}
if (!Customer::customerHasAddress($customerId, $invoiceAddressId)) {
    $fail('Persisted cart invoice address is not owned by the browser-created customer.', $diagnostics);
}

$deliveryAddress = new Address($deliveryAddressId);
if (!Validate::isLoadedObject($deliveryAddress) || $deliveryAddress->deleted) {
    $fail('Persisted cart delivery address cannot be loaded as an active Core address.', $diagnostics);
}
$country = new Country((int) $deliveryAddress->id_country);
if (!Validate::isLoadedObject($country) || !$country->active || (int) $country->id_zone <= 0) {
    $diagnostics['delivery_country_id'] = (int) $deliveryAddress->id_country;
    $fail('Persisted delivery address does not resolve to an active Core delivery country/zone.', $diagnostics);
}
$diagnostics['delivery_country_id'] = (int) $country->id;
$diagnostics['delivery_zone_id'] = (int) $country->id_zone;
$diagnostics['delivery_state_id'] = (int) $deliveryAddress->id_state;

$carrierId = (int) Configuration::get('PS_CARRIER_DEFAULT', null, $shopGroupId, $shopId);
$diagnostics['default_carrier_id'] = $carrierId;
if ($carrierId <= 0) {
    $fail('Runtime shop lost its Core default carrier before live-cart delivery discovery.', $diagnostics);
}
$carrier = new Carrier($carrierId, $languageId);
if (!Validate::isLoadedObject($carrier) || !$carrier->active || $carrier->deleted) {
    $fail('Runtime Core default carrier is not active at live-cart delivery discovery.', $diagnostics);
}
$diagnostics['carrier_zone_match'] = Carrier::checkCarrierZone($carrierId, (int) $country->id_zone);
if (!$diagnostics['carrier_zone_match']) {
    $fail('Runtime Core carrier is unavailable in the browser-created delivery-address zone.', $diagnostics);
}

$carrierErrors = [];
$carriersForOrder = Carrier::getCarriersForOrder((int) $country->id_zone, $groups, $cart, $carrierErrors);
$forOrderIds = [];
if (is_array($carriersForOrder)) {
    foreach ($carriersForOrder as $row) {
        if (is_array($row) && (int) ($row['id_carrier'] ?? 0) > 0) {
            $forOrderIds[] = (int) $row['id_carrier'];
        }
    }
}
$forOrderIds = array_values(array_unique($forOrderIds));
sort($forOrderIds);
$diagnostics['carriers_for_order'] = $forOrderIds;
$diagnostics['carrier_order_errors'] = is_array($carrierErrors) ? array_values($carrierErrors) : [];
if (!in_array($carrierId, $forOrderIds, true)) {
    $fail('Core Carrier::getCarriersForOrder() filters the fixture carrier for the live customer/address cart.', $diagnostics);
}

$product = new Product($productId, false, $languageId, $shopId);
if (!Validate::isLoadedObject($product)) {
    $fail('Runtime product cannot be loaded for live-cart carrier discovery.', $diagnostics);
}
$availableForProduct = Carrier::getAvailableCarrierList($product, $deliveryAddressId, null, $shopId, $cart);
$productCarrierIds = [];
if (is_array($availableForProduct)) {
    foreach (array_keys($availableForProduct) as $id) {
        if ((int) $id > 0) {
            $productCarrierIds[] = (int) $id;
        }
    }
}
$productCarrierIds = array_values(array_unique($productCarrierIds));
sort($productCarrierIds);
$diagnostics['product_carriers'] = $productCarrierIds;
if (!in_array($carrierId, $productCarrierIds, true)) {
    $fail('Core Carrier::getAvailableCarrierList() filters the fixture carrier for the live cart product/address.', $diagnostics);
}

$products = $cart->getProducts(true);
$cartProductIds = [];
if (is_array($products)) {
    foreach ($products as $row) {
        if (is_array($row) && (int) ($row['id_product'] ?? 0) > 0) {
            $cartProductIds[] = (int) $row['id_product'];
        }
    }
}
$cartProductIds = array_values(array_unique($cartProductIds));
sort($cartProductIds);
$diagnostics['cart_products'] = $cartProductIds;
if (!in_array($productId, $cartProductIds, true)) {
    $fail('Live Core cart no longer contains the runtime product after address preparation.', $diagnostics);
}

$deliveryOptions = $cart->getDeliveryOptionList($country, true);
$addressOptionKeys = [];
if (is_array($deliveryOptions) && isset($deliveryOptions[$deliveryAddressId]) && is_array($deliveryOptions[$deliveryAddressId])) {
    $addressOptionKeys = array_values(array_map('strval', array_keys($deliveryOptions[$deliveryAddressId])));
}
$diagnostics['delivery_option_addresses'] = is_array($deliveryOptions)
    ? array_values(array_map('intval', array_keys($deliveryOptions)))
    : [];
$diagnostics['delivery_option_keys'] = $addressOptionKeys;
if ($addressOptionKeys === []) {
    $fail('Core Cart::getDeliveryOptionList() returns no option for the persisted live delivery address.', $diagnostics);
}

fwrite(STDOUT, 'Live-cart delivery diagnostics: ' . json_encode($diagnostics, JSON_UNESCAPED_SLASHES) . PHP_EOL);
fwrite(STDOUT, sprintf(
    "Active live-cart delivery-state contract passed for PrestaShop %s (cart=%d, customer=%d, delivery_address=%d, carrier=%d).\n",
    _PS_VERSION_,
    $cartId,
    $customerId,
    $deliveryAddressId,
    $carrierId,
));
