<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout;

use InvalidArgumentException;

final readonly class CheckoutServerSelections
{
    public ?string $selectedPaymentOption;

    /** @var list<string> */
    public array $approvedAgreementKeys;

    /** @param list<string> $approvedAgreementKeys */
    public function __construct(?string $selectedPaymentOption = null, array $approvedAgreementKeys = [])
    {
        if ($selectedPaymentOption !== null && trim($selectedPaymentOption) === '') {
            throw new InvalidArgumentException('selectedPaymentOption must be null or a non-empty string.');
        }

        $this->selectedPaymentOption = $selectedPaymentOption === null ? null : trim($selectedPaymentOption);

        $normalized = [];
        foreach ($approvedAgreementKeys as $agreementKey) {
            if (!is_string($agreementKey) || trim($agreementKey) === '') {
                throw new InvalidArgumentException('approvedAgreementKeys must contain only non-empty strings.');
            }
            $normalized[trim($agreementKey)] = true;
        }

        $keys = array_keys($normalized);
        sort($keys, SORT_STRING);
        $this->approvedAgreementKeys = $keys;
    }
}
