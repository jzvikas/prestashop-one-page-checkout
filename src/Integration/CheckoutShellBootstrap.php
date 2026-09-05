<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

use InvalidArgumentException;

final readonly class CheckoutShellBootstrap
{
    public function __construct(
        public int $cartId,
        public string $csrfToken,
        public string $stateVersion,
        public string $paymentSelectionUrl,
        public string $agreementsUrl,
    ) {
        if ($cartId <= 0) {
            throw new InvalidArgumentException('Checkout bootstrap cart ID must be positive.');
        }

        $this->assertBoundedSecret($csrfToken, 'CSRF token', 512);
        $this->assertBoundedSecret($stateVersion, 'state version', 256);
        $this->assertHttpUrl($paymentSelectionUrl, 'payment selection URL');
        $this->assertHttpUrl($agreementsUrl, 'agreements URL');
    }

    /** @return array{cartId:int,csrfToken:string,stateVersion:string,paymentSelectionUrl:string,agreementsUrl:string} */
    public function toTemplateData(): array
    {
        return [
            'cartId' => $this->cartId,
            'csrfToken' => $this->csrfToken,
            'stateVersion' => $this->stateVersion,
            'paymentSelectionUrl' => $this->paymentSelectionUrl,
            'agreementsUrl' => $this->agreementsUrl,
        ];
    }

    private function assertBoundedSecret(string $value, string $field, int $maxLength): void
    {
        $length = strlen($value);
        if ($length === 0 || $length > $maxLength || str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new InvalidArgumentException(sprintf('Checkout bootstrap %s is invalid.', $field));
        }
    }

    private function assertHttpUrl(string $url, string $field): void
    {
        if ($url === '' || strlen($url) > 2048 || str_contains($url, "\r") || str_contains($url, "\n")) {
            throw new InvalidArgumentException(sprintf('Checkout bootstrap %s is invalid.', $field));
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException(sprintf('Checkout bootstrap %s must be an absolute HTTP(S) URL.', $field));
        }
    }
}
