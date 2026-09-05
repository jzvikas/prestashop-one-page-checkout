<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Carrier;

use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSessionProviderInterface;
use RuntimeException;

final readonly class CheckoutCarrierSelectionService
{
    public function __construct(
        private CheckoutSessionProviderInterface $checkoutSessionProvider,
    ) {
    }

    /** @return bool true when the selected option changed */
    public function apply(\Context $context, CheckoutCarrierSelection $requestedSelection): bool
    {
        $cart = $context->cart ?? null;
        if (!$cart instanceof \Cart || (int) ($cart->id ?? 0) <= 0) {
            throw new RuntimeException('A loaded cart is required to select a delivery option.');
        }
        if ($cart->isVirtualCart()) {
            throw new CheckoutCarrierSelectionException('Virtual carts do not accept a delivery option.');
        }

        $customerId = (int) ($cart->id_customer ?? 0);
        $deliveryAddressId = (int) ($cart->id_address_delivery ?? 0);
        if ($customerId <= 0 || $deliveryAddressId <= 0) {
            throw new CheckoutCarrierSelectionException('A customer-owned delivery address is required before selecting a delivery option.');
        }
        if (!\Customer::customerHasAddress($customerId, $deliveryAddressId)) {
            throw new CheckoutCarrierSelectionException('The current delivery address is not available for this customer.');
        }

        $checkoutSession = $this->checkoutSessionProvider->get($context);
        if (!method_exists($checkoutSession, 'getDeliveryOptions') || !method_exists($checkoutSession, 'setDeliveryOption')) {
            throw new RuntimeException('The Core checkout session does not expose delivery selection methods.');
        }

        $deliveryOptions = $checkoutSession->getDeliveryOptions();
        if (!is_array($deliveryOptions)) {
            throw new RuntimeException('Core delivery options must be an array.');
        }

        $canonicalOption = null;
        foreach (array_keys($deliveryOptions) as $availableOption) {
            if (!is_string($availableOption) && !is_int($availableOption)) {
                continue;
            }
            $availableOption = (string) $availableOption;
            if (hash_equals($availableOption, $requestedSelection->deliveryOption)) {
                $canonicalOption = $availableOption;
                break;
            }
        }

        if ($canonicalOption === null) {
            throw new CheckoutCarrierSelectionException('The selected delivery option is no longer available.');
        }

        if ($this->persistedOptionForAddress($cart, $deliveryAddressId) === $canonicalOption) {
            return false;
        }

        // Native CheckoutDeliveryStep submits delivery_option as an address-keyed array.
        // Keep the address identifier server-authoritative and pass the exact Core payload shape.
        if ($checkoutSession->setDeliveryOption([
            $deliveryAddressId => $canonicalOption,
        ]) !== true) {
            throw new RuntimeException('PrestaShop could not persist the selected delivery option.');
        }

        if ($this->persistedOptionForAddress($cart, $deliveryAddressId) !== $canonicalOption) {
            throw new RuntimeException('PrestaShop did not retain the selected delivery option on the cart.');
        }

        return true;
    }

    private function persistedOptionForAddress(\Cart $cart, int $deliveryAddressId): ?string
    {
        $raw = $cart->delivery_option ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $value = $decoded[$deliveryAddressId] ?? null;

        return is_string($value) ? $value : null;
    }
}
