<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

final class PrestaShopCheckoutAddressBookPresenter implements CheckoutAddressBookPresenterInterface
{
    public function present(\Context $context): array
    {
        $cart = $context->cart;
        $customer = $context->customer;
        $language = $context->language;

        $cartCustomerId = (int) $cart->id_customer;
        $contextCustomerId = (int) $customer->id;
        if ($cartCustomerId > 0 && $contextCustomerId !== $cartCustomerId) {
            throw new \LogicException('Checkout cart/customer mismatch while presenting addresses.');
        }

        $deliveryAddressId = $this->normalizeId((int) $cart->id_address_delivery);
        $invoiceAddressId = $this->normalizeId((int) $cart->id_address_invoice);
        $addresses = [];

        if ($cartCustomerId > 0) {
            foreach ($customer->getAddresses((int) $language->id) as $row) {
                $addressId = isset($row['id_address']) ? (int) $row['id_address'] : 0;
                if ($addressId <= 0 || !\Customer::customerHasAddress($cartCustomerId, $addressId)) {
                    continue;
                }

                $address = new \Address($addressId, (int) $language->id);
                if (!\Validate::isLoadedObject($address)) {
                    continue;
                }

                $lines = preg_split('/\R/u', trim((string) \AddressFormat::generateAddress($address))) ?: [];
                $lines = array_values(array_filter(
                    array_map(static fn (string $line): string => trim($line), $lines),
                    static fn (string $line): bool => $line !== '',
                ));

                $addresses[] = [
                    'id' => $addressId,
                    'alias' => (string) $address->alias,
                    'lines' => $lines,
                ];
            }
        }

        return [
            'addresses' => $addresses,
            'deliveryAddressId' => $deliveryAddressId,
            'invoiceAddressId' => $invoiceAddressId,
            'useSameAddress' => $deliveryAddressId !== null && $deliveryAddressId === $invoiceAddressId,
        ];
    }

    private function normalizeId(int $id): ?int
    {
        return $id > 0 ? $id : null;
    }
}
