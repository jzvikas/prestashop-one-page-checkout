<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout;

use InvalidArgumentException;

final readonly class CheckoutMutationOutcome
{
    /**
     * @param array<string,string> $sections
     * @param list<CheckoutError> $errors
     */
    private function __construct(
        public CheckoutServerSelections $serverSelections,
        public array $sections,
        public array $errors,
        public ?string $redirect,
    ) {
        foreach ($sections as $section => $html) {
            if (!is_string($section) || $section === '' || !is_string($html)) {
                throw new InvalidArgumentException('Checkout mutation sections must be string keys with string HTML values.');
            }
        }

        foreach ($errors as $error) {
            if (!$error instanceof CheckoutError) {
                throw new InvalidArgumentException('Checkout mutation errors must contain only CheckoutError instances.');
            }
        }

        if ($errors !== [] && $redirect !== null) {
            throw new InvalidArgumentException('Failed checkout mutation outcome cannot redirect.');
        }
    }

    /** @param array<string,string> $sections */
    public static function success(
        CheckoutServerSelections $serverSelections,
        array $sections,
        ?string $redirect = null,
    ): self {
        return new self($serverSelections, $sections, [], $redirect);
    }

    /**
     * @param list<CheckoutError> $errors
     * @param array<string,string> $sections
     */
    public static function failure(
        CheckoutServerSelections $serverSelections,
        array $errors,
        array $sections = [],
    ): self {
        if ($errors === []) {
            throw new InvalidArgumentException('Failed checkout mutation outcome requires at least one error.');
        }

        return new self($serverSelections, $sections, $errors, null);
    }

    public function succeeded(): bool
    {
        return $this->errors === [];
    }
}
