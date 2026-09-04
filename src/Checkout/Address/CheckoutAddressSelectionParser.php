<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Address;

final readonly class CheckoutAddressSelectionParser
{
    /** @param array<string,mixed> $request */
    public function parse(array $request): CheckoutAddressSelection
    {
        if (!array_key_exists('useSameAddress', $request)) {
            throw new CheckoutAddressSelectionException(
                'use_same_address_required',
                'Please specify whether the invoice address is the same as the delivery address.',
                'useSameAddress',
            );
        }

        $useSameAddress = $this->boolean($request['useSameAddress'], 'useSameAddress');
        $deliveryAddressId = $this->optionalPositiveId(
            $request['deliveryAddressId'] ?? null,
            'deliveryAddressId',
        );
        $invoiceAddressId = $this->optionalPositiveId(
            $request['invoiceAddressId'] ?? null,
            'invoiceAddressId',
        );

        if ($useSameAddress && $invoiceAddressId !== null) {
            throw new CheckoutAddressSelectionException(
                'invoice_address_must_be_omitted',
                'Invoice address must be omitted when using the delivery address for invoicing.',
                'invoiceAddressId',
            );
        }

        if (!$useSameAddress && $invoiceAddressId === null) {
            throw new CheckoutAddressSelectionException(
                'invoice_address_required',
                'Please select an invoice address.',
                'invoiceAddressId',
            );
        }

        return new CheckoutAddressSelection(
            $deliveryAddressId,
            $invoiceAddressId,
            $useSameAddress,
        );
    }

    private function boolean(mixed $value, string $field): bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1' => true,
            $value === false, $value === 0, $value === '0' => false,
            default => throw new CheckoutAddressSelectionException(
                'invalid_boolean',
                sprintf('%s must be a boolean value.', $field),
                $field,
            ),
        };
    }

    private function optionalPositiveId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $id = is_int($validated) ? $validated : 0;
        } else {
            $id = 0;
        }

        if ($id <= 0) {
            throw new CheckoutAddressSelectionException(
                'invalid_address_id',
                sprintf('%s must be a positive integer.', $field),
                $field,
            );
        }

        return $id;
    }
}
