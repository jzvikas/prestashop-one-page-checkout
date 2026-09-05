<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Identity;

final readonly class CheckoutIdentitySubmission
{
    private function __construct(
        public bool $completed,
        public string $registerFormHtml,
        public string $loginFormHtml,
    ) {
    }

    public static function completed(): self
    {
        return new self(true, '', '');
    }

    public static function invalid(string $registerFormHtml, string $loginFormHtml): self
    {
        return new self(false, $registerFormHtml, $loginFormHtml);
    }
}
