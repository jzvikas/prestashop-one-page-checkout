<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout;

use InvalidArgumentException;

final readonly class CheckoutState
{
    public ?string $selectedPaymentOption;

    /** @var list<string> */
    public array $approvedAgreementKeys;

    /**
     * @param list<string> $approvedAgreementKeys
     */
    public function __construct(
        public int $shopId,
        public int $cartId,
        public ?int $customerId,
        public int $languageId,
        public int $currencyId,
        public ?int $deliveryAddressId,
        public ?int $invoiceAddressId,
        public ?int $carrierId,
        ?string $selectedPaymentOption,
        array $approvedAgreementKeys,
        public string $cartFingerprint,
        public string $totalsFingerprint,
    ) {
        self::assertPositive($shopId, 'shopId');
        self::assertPositive($cartId, 'cartId');
        self::assertPositive($languageId, 'languageId');
        self::assertPositive($currencyId, 'currencyId');
        self::assertNullablePositive($customerId, 'customerId');
        self::assertNullablePositive($deliveryAddressId, 'deliveryAddressId');
        self::assertNullablePositive($invoiceAddressId, 'invoiceAddressId');
        self::assertNullablePositive($carrierId, 'carrierId');
        self::assertNullableNonEmpty($selectedPaymentOption, 'selectedPaymentOption');
        $this->selectedPaymentOption = $selectedPaymentOption === null ? null : trim($selectedPaymentOption);
        self::assertFingerprint($cartFingerprint, 'cartFingerprint');
        self::assertFingerprint($totalsFingerprint, 'totalsFingerprint');

        $normalizedAgreements = [];
        foreach ($approvedAgreementKeys as $agreementKey) {
            if (!is_string($agreementKey) || trim($agreementKey) === '') {
                throw new InvalidArgumentException('approvedAgreementKeys must contain only non-empty strings.');
            }

            $normalizedAgreements[trim($agreementKey)] = true;
        }

        $keys = array_keys($normalizedAgreements);
        sort($keys, SORT_STRING);
        $this->approvedAgreementKeys = $keys;
    }

    /** @return array<string, int|string|list<string>|null> */
    public function versionPayload(): array
    {
        return [
            'shopId' => $this->shopId,
            'cartId' => $this->cartId,
            'customerId' => $this->customerId,
            'languageId' => $this->languageId,
            'currencyId' => $this->currencyId,
            'deliveryAddressId' => $this->deliveryAddressId,
            'invoiceAddressId' => $this->invoiceAddressId,
            'carrierId' => $this->carrierId,
            'selectedPaymentOption' => $this->selectedPaymentOption,
            'approvedAgreementKeys' => $this->approvedAgreementKeys,
            'cartFingerprint' => $this->cartFingerprint,
            'totalsFingerprint' => $this->totalsFingerprint,
        ];
    }

    private static function assertPositive(int $value, string $field): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer.', $field));
        }
    }

    private static function assertNullablePositive(?int $value, string $field): void
    {
        if ($value !== null) {
            self::assertPositive($value, $field);
        }
    }

    private static function assertNullableNonEmpty(?string $value, string $field): void
    {
        if ($value !== null && trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s must be null or a non-empty string.', $field));
        }
    }

    private static function assertFingerprint(string $value, string $field): void
    {
        if ($value === '' || strlen($value) > 256) {
            throw new InvalidArgumentException(sprintf('%s must contain between 1 and 256 bytes.', $field));
        }
    }
}
