<?php

declare(strict_types=1);

$shopRoot = $argv[1] ?? '';
$manifestPath = $argv[2] ?? '';

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if ($manifestPath === '') {
    $fail('Browser identity cleanup manifest path is required.');
}

// The step is wired with `if: always()` so it is also reached when setup/browser execution failed
// before a manifest could be written. In that case there is deliberately nothing to clean.
if (!is_file($manifestPath)) {
    fwrite(STDOUT, "Browser identity cleanup: no manifest present; nothing to clean.\n");
    exit(0);
}

$realManifest = realpath($manifestPath);
if (!is_string($realManifest) || !str_starts_with($realManifest, '/tmp/jzopc-browser-identity-cleanup')) {
    $fail('Browser identity cleanup refuses a manifest outside /tmp/jzopc-browser-identity-cleanup*.');
}
if ($shopRoot === '' || !is_file($shopRoot . '/config/config.inc.php')) {
    $fail('Installed PrestaShop root is missing or invalid for browser identity cleanup.');
}

try {
    $manifest = json_decode((string) file_get_contents($realManifest), true, 16, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    $fail('Browser identity cleanup manifest is invalid JSON: ' . $exception->getMessage());
}
if (!is_array($manifest)) {
    $fail('Browser identity cleanup manifest must contain an object.');
}

$cartId = isset($manifest['cartId']) && (is_int($manifest['cartId']) || is_string($manifest['cartId']))
    ? (int) $manifest['cartId']
    : 0;
$email = isset($manifest['email']) && is_string($manifest['email']) ? $manifest['email'] : '';
$firstName = isset($manifest['firstName']) && is_string($manifest['firstName']) ? $manifest['firstName'] : '';
$lastName = isset($manifest['lastName']) && is_string($manifest['lastName']) ? $manifest['lastName'] : '';

if ($cartId <= 0
    || $firstName !== 'RuntimeBrowser'
    || $lastName !== 'Guest'
    || preg_match('/^jzopc-runtime-browser-[a-f0-9-]+@example\.invalid$/D', $email) !== 1) {
    $fail('Browser identity cleanup manifest does not match the dedicated runtime namespace.');
}

require_once $shopRoot . '/config/config.inc.php';

$shopId = (int) Configuration::get('PS_SHOP_DEFAULT');
$languageId = (int) Configuration::get('PS_LANG_DEFAULT');
if ($shopId <= 0 || $languageId <= 0) {
    $fail('Default shop/language configuration is unavailable during browser identity cleanup.');
}

$customer = new Customer();
try {
    $resolved = $customer->getByEmail($email, null, false);
} catch (Throwable $exception) {
    $fail('Unable to resolve browser runtime guest for cleanup: ' . $exception->getMessage());
}
if ($resolved instanceof Customer) {
    $customer = $resolved;
}

$customerId = (int) ($customer->id ?? 0);
if ($customerId > 0) {
    if (!(bool) $customer->is_guest
        || (string) $customer->firstname !== $firstName
        || (string) $customer->lastname !== $lastName
        || (string) $customer->email !== $email) {
        $fail('Resolved browser identity does not match the dedicated runtime guest boundary.');
    }
}

$cart = new Cart($cartId);
if ((int) ($cart->id ?? 0) > 0) {
    $cartCustomerId = (int) ($cart->id_customer ?? 0);
    if ($cartCustomerId !== 0 && ($customerId <= 0 || $cartCustomerId !== $customerId)) {
        $fail('Browser cleanup refuses a cart bound to an unexpected customer.');
    }
}

if ($customerId > 0) {
    $addresses = $customer->getAddresses($languageId);
    if (!is_array($addresses)) {
        $fail('Unable to enumerate runtime guest addresses for cleanup.');
    }
    foreach ($addresses as $addressData) {
        $addressId = isset($addressData['id_address']) ? (int) $addressData['id_address'] : 0;
        if ($addressId <= 0) {
            continue;
        }

        $address = new Address($addressId);
        if ((int) ($address->id_customer ?? 0) !== $customerId) {
            $fail('Browser cleanup encountered an address outside the runtime guest boundary.');
        }
        if (!$address->delete()) {
            $fail(sprintf('Unable to delete runtime guest address %d.', $addressId));
        }
    }
}

if ((int) ($cart->id ?? 0) > 0 && !$cart->delete()) {
    $fail('Unable to delete browser runtime cart.');
}

$db = Db::getInstance();
$selectionTable = _DB_PREFIX_ . 'jzopc_checkout_selection';
$reservationTable = _DB_PREFIX_ . 'jzopc_checkout_finalization';
foreach ([$selectionTable, $reservationTable] as $table) {
    $where = sprintf('id_shop = %d AND id_cart = %d', $shopId, $cartId);
    if (!$db->delete($table, $where, 0, false)) {
        $fail(sprintf('Unable to clear module runtime state from %s.', $table));
    }
}

if ($customerId > 0 && !$customer->delete()) {
    $fail('Unable to delete browser runtime guest.');
}

if (!@unlink($realManifest) && is_file($realManifest)) {
    $fail('Unable to remove browser identity cleanup manifest.');
}

fwrite(STDOUT, sprintf(
    "Browser identity cleanup OK: cart=%d, customer=%d, email=%s\n",
    $cartId,
    $customerId,
    $email,
));
