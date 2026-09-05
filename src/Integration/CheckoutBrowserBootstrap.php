<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

use InvalidArgumentException;

final readonly class CheckoutBrowserBootstrap
{
    public function __construct(
        public int $cartId,
        public string $csrfToken,
        public string $stateVersion,
        public string $addressUrl,
        public string $carrierUrl,
        public string $paymentUrl,
        public string $agreementsUrl,
    ) {
        if ($this->cartId <= 0) {
            throw new InvalidArgumentException('Checkout bootstrap cart ID must be positive.');
        }

        foreach ([
            'csrfToken' => $this->csrfToken,
            'stateVersion' => $this->stateVersion,
            'addressUrl' => $this->addressUrl,
            'carrierUrl' => $this->carrierUrl,
            'paymentUrl' => $this->paymentUrl,
            'agreementsUrl' => $this->agreementsUrl,
        ] as $name => $value) {
            if ($value === '') {
                throw new InvalidArgumentException(sprintf('Checkout bootstrap %s must not be empty.', $name));
            }
        }
    }

    /** @return array{cartId:int,csrfToken:string,stateVersion:string,addressUrl:string,carrierUrl:string,paymentUrl:string,agreementsUrl:string} */
    public function toTemplateVariables(): array
    {
        return [
            'cartId' => $this->cartId,
            'csrfToken' => $this->csrfToken,
            'stateVersion' => $this->stateVersion,
            'addressUrl' => $this->addressUrl,
            'carrierUrl' => $this->carrierUrl,
            'paymentUrl' => $this->paymentUrl,
            'agreementsUrl' => $this->agreementsUrl,
        ];
    }
}
