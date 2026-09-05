<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Checkout\Mutation\CheckoutCarrierSelectionMutation;
use Jzvikas\OnePageCheckout\Http\CheckoutJsonResponse;
use Jzvikas\OnePageCheckout\Http\CheckoutMutationResponseMapper;

require_once __DIR__ . '/AbstractJzOpcMutationFrontController.php';

final class JzOnePageCheckoutCarrierselectionModuleFrontController extends JzOnePageCheckoutAbstractMutationModuleFrontController
{
    protected function executeCheckoutMutationRequest(): CheckoutJsonResponse
    {
        $mutation = $this->module->get(CheckoutCarrierSelectionMutation::class);
        $mapper = $this->module->get(CheckoutMutationResponseMapper::class);
        if (!$mutation instanceof CheckoutCarrierSelectionMutation || !$mapper instanceof CheckoutMutationResponseMapper) {
            throw new RuntimeException('Checkout carrier mutation services are unavailable.');
        }

        $request = Tools::getAllValues();
        $translate = fn (string $message): string => $this->checkoutTranslate($message);

        return $mapper->map(
            $mutation->execute($this->context, $request, $translate),
            $translate,
        );
    }
}
