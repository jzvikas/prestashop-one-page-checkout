<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Address;

use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSessionProviderInterface;

final readonly class CheckoutAddressSelectionService
{
    public function __construct(
        private CheckoutSessionProviderInterface $checkoutSessionProvider,
    ) {
    }

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
        $targetDeliveryAddressId = $selection->deliveryAddressId ?? $initialDeliveryAddressId;

        if ($selection->deliveryAddressId !== null) {
            $this->assertOwnedAddress(
                $customerId,
                $selection->deliveryAddressId,
                'delivery_address_not_owned',
                'The selected delivery address is not available for this customer.',
                'deliveryAddressId',
            );
        }

        if ($selection->useSameAddress) {
            if ($targetDeliveryAddressId <= 0) {
                throw new CheckoutAddressSelectionException(
                    'delivery_address_required',
                    'Please select a delivery address before using it as the invoice address.',
                    'deliveryAddressId',
                );
            }

            // Recheck ownership even when the delivery id came from the cart rather than this request.
            $this->assertOwnedAddress(
                $customerId,
                $targetDeliveryAddressId,
                'delivery_address_not_owned',
                'The selected delivery address is not available for this customer.',
                'deliveryAddressId',
            );
            $targetInvoiceAddressId = $targetDeliveryAddressId;
        } else {
            $targetInvoiceAddressId = $selection->invoiceAddressId;
            if ($targetInvoiceAddressId === null) {
                throw new CheckoutAddressSelectionException(
                    'invoice_address_required',
                    'Please select an invoice address.',
                    'invoiceAddressId',
                );
            }

            $this->assertOwnedAddress(
                $customerId,
                $targetInvoiceAddressId,
                'invoice_address_not_owned',
                'The selected invoice address is not available for this customer.',
                'invoiceAddressId',
            );
        }

        if (
            $targetDeliveryAddressId === $initialDeliveryAddressId
            && $targetInvoiceAddressId === $initialInvoiceAddressId
        ) {
            return false;
        }

        $session = $this->checkoutSessionProvider->get($context);
        if (!method_exists($session, 'setIdAddressDelivery') || !method_exists($session, 'setIdAddressInvoice')) {
            throw new \RuntimeException('Core CheckoutSession address mutation methods are unavailable.');
        }

        // Core CheckoutSession::setIdAddressDelivery() deliberately calls Cart::updateAddressId()
        // before saving the cart. Using that path preserves cart_product/customization delivery
        // address associations instead of changing only the two cart header IDs.
        if ($targetDeliveryAddressId !== $initialDeliveryAddressId) {
            $session->setIdAddressDelivery($targetDeliveryAddressId);
        }

        // Updating delivery can also move the invoice address when Core considers both addresses
        // linked. Re-read the cart before deciding whether an explicit invoice mutation is needed.
        if ((int) ($cart->id_address_invoice ?? 0) !== $targetInvoiceAddressId) {
            $session->setIdAddressInvoice($targetInvoiceAddressId);
        }

        if (
            (int) ($cart->id_address_delivery ?? 0) !== $targetDeliveryAddressId
            || (int) ($cart->id_address_invoice ?? 0) !== $targetInvoiceAddressId
        ) {
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
