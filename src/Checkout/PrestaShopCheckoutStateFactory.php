<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout;

use JsonException;
use UnexpectedValueException;

final readonly class PrestaShopCheckoutStateFactory
{
    /** @throws JsonException */
    public function create(\Context $context, ?CheckoutServerSelections $selections = null): CheckoutState
    {
        $cart = $context->cart ?? null;
        if (!$cart instanceof \Cart) {
            throw new UnexpectedValueException('A loaded PrestaShop cart is required to build checkout state.');
        }

        $selections ??= new CheckoutServerSelections();

        return new CheckoutState(
            shopId: $this->positiveId($cart->id_shop ?? null, 'cart.id_shop'),
            cartId: $this->positiveId($cart->id ?? null, 'cart.id'),
            customerId: $this->nullablePositiveId($cart->id_customer ?? null),
            languageId: $this->positiveId($cart->id_lang ?? null, 'cart.id_lang'),
            currencyId: $this->positiveId($cart->id_currency ?? null, 'cart.id_currency'),
            deliveryAddressId: $this->nullablePositiveId($cart->id_address_delivery ?? null),
            invoiceAddressId: $this->nullablePositiveId($cart->id_address_invoice ?? null),
            carrierId: $this->nullablePositiveId($cart->id_carrier ?? null),
            selectedPaymentOption: $selections->selectedPaymentOption,
            approvedAgreementKeys: $selections->approvedAgreementKeys,
            cartFingerprint: $this->cartFingerprint($cart),
            totalsFingerprint: $this->totalsFingerprint($cart),
        );
    }

    /** @throws JsonException */
    private function cartFingerprint(\Cart $cart): string
    {
        if (!class_exists('CartChecksum') || !class_exists('AddressChecksum')) {
            throw new UnexpectedValueException('PrestaShop CartChecksum and AddressChecksum are required.');
        }

        $cartRuleIds = [];
        foreach ($cart->getCartRules() as $cartRule) {
            $id = is_array($cartRule) ? (int) ($cartRule['id_cart_rule'] ?? 0) : 0;
            if ($id > 0) {
                $cartRuleIds[$id] = true;
            }
        }
        $cartRuleIds = array_map('intval', array_keys($cartRuleIds));
        sort($cartRuleIds, SORT_NUMERIC);

        $payload = [
            'core' => (new \CartChecksum(new \AddressChecksum()))->generateChecksum($cart),
            'carrierId' => max(0, (int) ($cart->id_carrier ?? 0)),
            'deliveryOption' => (string) ($cart->delivery_option ?? ''),
            'recyclable' => (bool) ($cart->recyclable ?? false),
            'gift' => (bool) ($cart->gift ?? false),
            'giftMessage' => (string) ($cart->gift_message ?? ''),
            'virtualCart' => (bool) $cart->isVirtualCart(),
            'cartRuleIds' => $cartRuleIds,
        ];

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    /** @throws JsonException */
    private function totalsFingerprint(\Cart $cart): string
    {
        $payload = [
            'totalTaxIncl' => $this->money($cart->getOrderTotal(true, \Cart::BOTH)),
            'totalTaxExcl' => $this->money($cart->getOrderTotal(false, \Cart::BOTH)),
            'productsTaxIncl' => $this->money($cart->getOrderTotal(true, \Cart::ONLY_PRODUCTS)),
            'discountsTaxIncl' => $this->money($cart->getOrderTotal(true, \Cart::ONLY_DISCOUNTS)),
            'shippingTaxIncl' => $this->money($cart->getOrderTotal(true, \Cart::ONLY_SHIPPING)),
            'wrappingTaxIncl' => $this->money($cart->getOrderTotal(true, \Cart::ONLY_WRAPPING)),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function money(mixed $value): string
    {
        if (!is_int($value) && !is_float($value) && !is_numeric($value)) {
            throw new UnexpectedValueException('PrestaShop cart total must be numeric.');
        }

        $amount = (float) $value;
        if (!is_finite($amount)) {
            throw new UnexpectedValueException('PrestaShop cart total must be finite.');
        }

        return number_format($amount, 6, '.', '');
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = (int) $value;
        if ($id <= 0) {
            throw new UnexpectedValueException(sprintf('%s must be a positive integer.', $field));
        }

        return $id;
    }

    private function nullablePositiveId(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
