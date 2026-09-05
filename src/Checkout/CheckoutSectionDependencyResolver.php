<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout;

final readonly class CheckoutSectionDependencyResolver
{
    /** @return list<CheckoutSection> */
    public function affectedBy(CheckoutMutation $mutation, ?\Context $context = null): array
    {
        $affected = match ($mutation) {
            CheckoutMutation::IdentityUpdated,
            CheckoutMutation::FullRefresh => CheckoutSection::ordered(),

            CheckoutMutation::AddressSelectionUpdated,
            CheckoutMutation::DeliveryAddressUpdated,
            CheckoutMutation::InvoiceAddressUpdated,
            CheckoutMutation::InvoiceModeToggled,
            CheckoutMutation::CartChanged => [
                CheckoutSection::Addresses,
                CheckoutSection::Delivery,
                CheckoutSection::Payment,
                CheckoutSection::Agreements,
                CheckoutSection::Summary,
            ],

            CheckoutMutation::CarrierSelected => [
                CheckoutSection::Delivery,
                CheckoutSection::Payment,
                CheckoutSection::Agreements,
                CheckoutSection::Summary,
            ],

            CheckoutMutation::PaymentSelected => [
                CheckoutSection::Payment,
                CheckoutSection::Agreements,
                CheckoutSection::Summary,
            ],

            CheckoutMutation::AgreementsChanged => [
                CheckoutSection::Agreements,
            ],
        };

        $ordered = $this->inCanonicalOrder($affected);
        if ($context === null || !$this->isVirtualCart($context)) {
            return $ordered;
        }

        return array_values(array_filter(
            $ordered,
            static fn (CheckoutSection $section): bool => $section !== CheckoutSection::Delivery,
        ));
    }

    private function isVirtualCart(\Context $context): bool
    {
        $cart = $context->cart ?? null;

        return $cart instanceof \Cart && $cart->isVirtualCart() === true;
    }

    /**
     * @param list<CheckoutSection> $sections
     * @return list<CheckoutSection>
     */
    private function inCanonicalOrder(array $sections): array
    {
        $requested = [];
        foreach ($sections as $section) {
            $requested[$section->value] = true;
        }

        return array_values(array_filter(
            CheckoutSection::ordered(),
            static fn (CheckoutSection $section): bool => isset($requested[$section->value])
        ));
    }
}
