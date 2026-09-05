<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Agreements;

use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutAgreementsPresenterInterface;
use RuntimeException;

final readonly class CheckoutAgreementSelectionService
{
    public function __construct(
        private CheckoutAgreementsPresenterInterface $agreementsPresenter,
    ) {
    }

    /**
     * @param list<string> $requestedAgreementKeys
     * @return list<string>
     */
    public function validate(\Context $context, array $requestedAgreementKeys): array
    {
        $presented = $this->agreementsPresenter->present($context);
        $conditions = $presented['conditions'] ?? null;
        if (!is_array($conditions)) {
            throw new RuntimeException('Presented checkout agreements are missing or invalid.');
        }

        $required = [];
        foreach (array_keys($conditions) as $key) {
            if (!is_string($key) || trim($key) === '') {
                throw new RuntimeException('Core checkout agreement identifiers must be non-empty strings.');
            }
            $required[$key] = true;
        }

        $requested = [];
        foreach ($requestedAgreementKeys as $key) {
            if (!is_string($key) || trim($key) === '') {
                throw new CheckoutAgreementSelectionException('Agreement selection is malformed.');
            }
            $requested[$key] = true;
        }

        $requiredKeys = array_keys($required);
        $requestedKeys = array_keys($requested);
        sort($requiredKeys, SORT_STRING);
        sort($requestedKeys, SORT_STRING);

        if ($requiredKeys !== $requestedKeys) {
            throw new CheckoutAgreementSelectionException('All required checkout agreements must be accepted.');
        }

        return $requiredKeys;
    }

    /** @param list<string> $validatedAgreementKeys */
    public function mergeIntoServerSelections(
        array $validatedAgreementKeys,
        CheckoutServerSelections $currentSelections,
    ): CheckoutServerSelections {
        return new CheckoutServerSelections(
            $currentSelections->selectedPaymentOption,
            $validatedAgreementKeys,
        );
    }
}
