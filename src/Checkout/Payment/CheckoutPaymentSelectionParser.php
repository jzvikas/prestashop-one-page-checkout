<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Payment;

final class CheckoutPaymentSelectionParser
{
    private const MAX_OPTION_ID_LENGTH = 128;
    private const MAX_MODULE_NAME_LENGTH = 64;

    /** @param array<string,mixed> $request */
    public function parse(array $request): CheckoutPaymentSelection
    {
        $optionId = $this->parseIdentifier(
            $request['paymentOptionId'] ?? null,
            'paymentOptionId',
            self::MAX_OPTION_ID_LENGTH,
        );
        $moduleName = $this->parseIdentifier(
            $request['paymentModule'] ?? null,
            'paymentModule',
            self::MAX_MODULE_NAME_LENGTH,
        );

        return new CheckoutPaymentSelection($optionId, $moduleName);
    }

    private function parseIdentifier(mixed $value, string $field, int $maxLength): string
    {
        if (!is_string($value)) {
            throw new CheckoutPaymentSelectionException(sprintf('%s must be a string.', $field));
        }

        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new CheckoutPaymentSelectionException(sprintf('%s has an invalid format.', $field));
        }

        return $value;
    }
}
