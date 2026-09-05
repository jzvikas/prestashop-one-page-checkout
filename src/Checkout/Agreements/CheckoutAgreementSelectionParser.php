<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Agreements;

final class CheckoutAgreementSelectionParser
{
    private const MAX_AGREEMENTS = 32;
    private const KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D';

    /** @param array<string, mixed> $request */
    public function parse(array $request): array
    {
        $raw = $request['agreements'] ?? [];
        if (!is_array($raw) || count($raw) > self::MAX_AGREEMENTS) {
            throw new CheckoutAgreementSelectionException('Agreement selection is malformed.');
        }

        $approved = [];
        foreach ($raw as $key) {
            if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new CheckoutAgreementSelectionException('Agreement selection contains an invalid identifier.');
            }
            $approved[$key] = true;
        }

        $keys = array_keys($approved);
        sort($keys, SORT_STRING);

        return $keys;
    }
}
