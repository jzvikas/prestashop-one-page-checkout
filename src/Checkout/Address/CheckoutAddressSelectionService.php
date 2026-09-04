<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Address;

final readonly class CheckoutAddressSelectionService
{
    public function apply(\Context $context, CheckoutAddressSelection $selection): bool
    {
        $cart = $context->cart ?? null;
        if (!$cart instanceof \Cart || (int) ($cart->id ?? 0) <= 0) {
            throw new CheckoutAddressSelectionException(
                'missing_cart',
                'Unable to resolve checkout cart.',
            );
        }

        $customerId = (int) ($cart->id_customer ?? 0);
        if ($customerId <= 0) {
            throw new CheckoutAddressSelectionException(
                'checkout_customer_required',
                'A checkout customer is required before selecting an address.',
            );
        }

        $initialDeliveryAddressId = (int) ($cart->id_address_delivery ?? 0);
        $initialInvoiceAddressId = (int) ($cart->id_address_invoice ?? 0);

        if ($selection->deliveryAddressId !== null) {
            $this->assertOwnedAddress(
                $customerId,
                $selection->deliveryAddressId,
                'delivery_address_not_owned',
                'The selected delivery address is not available for this customer.',
                'deliveryAddressId',
            );
            $cart->id_address_delivery = $selection->deliveryAddressId;
        }

        if ($selection->useSameAddress) {
            $deliveryAddressId = (int) ($cart->id_address_delivery ?? 0);
            if ($deliveryAddressId <= 0) {
                throw new CheckoutAddressSelectionException(
                    'delivery_address_required',
                    'Please select a delivery address before using it as the invoice address.',
                    'deliveryAddressId',
                );
            }

            // Recheck ownership even when the delivery id came from the cart rather than this request.
            $this->assertOwnedAddress(
                $customerId,
                $deliveryAddressId,
                'delivery_address_not_owned',
                'The selected delivery address is not available for this customer.',
                'deliveryAddressId',
            );
            $cart->id_address_invoice = $deliveryAddressId;
        } else {
            $invoiceAddressId = $selection->invoiceAddressId;
            if ($invoiceAddressId === null) {
                throw new CheckoutAddressSelectionException(
                    'invoice_address_required',
                    'Please select an invoice address.',
                    'invoiceAddressId',
                );
            }

            $this->assertOwnedAddress(
                $customerId,
                $invoiceAddressId,
                'invoice_address_not_owned',
                'The selected invoice address is not available for this customer.',
                'invoiceAddressId',
            );
            $cart->id_address_invoice = $invoiceAddressId;
        }

        if (
            (int) $cart->id_address_delivery === $initialDeliveryAddressId
            && (int) $cart->id_address_invoice === $initialInvoiceAddressId
        ) {
            return false;
        }

        if (!$cart->save()) {
            // Keep the in-memory object from falsely representing a persisted mutation.
            $cart->id_address_delivery = $initialDeliveryAddressId;
            $cart->id_address_invoice = $initialInvoiceAddressId;

            throw new CheckoutAddressSelectionException(
                'address_context_save_failed',
                'Unable to save checkout address selection.',
            );
        }

        return true;
    }

    private function assertOwnedAddress(
        int $customerId,
        int $addressId,
        string $errorCode,
        string $message,
        string $field,
    ): void {
        if (!\Customer::customerHasAddress($customerId, $addressId)) {
            throw new CheckoutAddressSelectionException($errorCode, $message, $field);
        }
    }
}
