<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout;

use InvalidArgumentException;

final readonly class CheckoutError
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $field = null,
    ) {
        if (trim($code) === '' || trim($message) === '') {
            throw new InvalidArgumentException('Checkout error code and message must be non-empty.');
        }
    }

    /** @return array{code:string,message:string,field:?string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'field' => $this->field,
        ];
    }
}
