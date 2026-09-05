<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

use RuntimeException;

final class PrestaShopCheckoutAgreementsPresenter implements CheckoutAgreementsPresenterInterface
{
    public function present(\Context $context): array
    {
        if (!class_exists(\ConditionsToApproveFinder::class)) {
            throw new RuntimeException('PrestaShop ConditionsToApproveFinder is not available.');
        }
        if (!method_exists($context, 'getTranslator')) {
            throw new RuntimeException('PrestaShop checkout translator is not available from Context.');
        }

        $conditions = (new \ConditionsToApproveFinder($context, $context->getTranslator()))
            ->getConditionsToApproveForTemplate();
        if (!is_array($conditions)) {
            throw new RuntimeException('Core checkout conditions must be an array.');
        }

        $normalized = [];
        foreach ($conditions as $identifier => $html) {
            if (!is_string($identifier) || $identifier === '' || !is_string($html)) {
                throw new RuntimeException('Core checkout conditions contain an invalid entry.');
            }
            $normalized[$identifier] = $html;
        }

        return ['conditions' => $normalized];
    }
}
