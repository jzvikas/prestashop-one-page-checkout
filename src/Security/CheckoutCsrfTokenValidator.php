<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Security;

final readonly class CheckoutCsrfTokenValidator
{
    public function isValid(mixed $submittedToken): bool
    {
        if (!is_string($submittedToken) || $submittedToken === '') {
            return false;
        }

        if (!class_exists('Tools')) {
            return false;
        }

        $expectedToken = (string) \Tools::getToken(false);
        if ($expectedToken === '') {
            return false;
        }

        return hash_equals($expectedToken, $submittedToken);
    }
}
