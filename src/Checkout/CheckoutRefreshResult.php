<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout;

use InvalidArgumentException;

final readonly class CheckoutRefreshResult
{
    /**
     * @param array<string, string> $sections
     * @param list<CheckoutError> $errors
     */
    private function __construct(
        public bool $success,
        public string $stateVersion,
        public array $sections,
        public array $errors,
        public ?string $redirect,
    ) {
        if ($stateVersion === '') {
            throw new InvalidArgumentException('stateVersion must be non-empty.');
        }

        if (!$success && $errors === []) {
            throw new InvalidArgumentException('A failed checkout refresh must contain at least one error.');
        }

        foreach ($errors as $error) {
            if (!$error instanceof CheckoutError) {
                throw new InvalidArgumentException('errors must contain only CheckoutError instances.');
            }
        }
    }

    /** @param array<string, string> $sections */
    public static function success(string $stateVersion, array $sections, ?string $redirect = null): self
    {
        return new self(true, $stateVersion, $sections, [], $redirect);
    }

    /** @param list<CheckoutError> $errors */
    public static function failure(string $stateVersion, array $errors, array $sections = []): self
    {
        return new self(false, $stateVersion, $sections, $errors, null);
    }

    /**
     * @return array{
     *   success:bool,
     *   stateVersion:string,
     *   sections:array<string,string>,
     *   errors:list<array{code:string,message:string,field:?string}>,
     *   redirect:?string
     * }
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'stateVersion' => $this->stateVersion,
            'sections' => $this->sections,
            'errors' => array_map(
                static fn (CheckoutError $error): array => $error->toArray(),
                $this->errors
            ),
            'redirect' => $this->redirect,
        ];
    }
}
