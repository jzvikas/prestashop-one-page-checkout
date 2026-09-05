<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Checkout\Mutation\CheckoutPaymentSelectionMutation;
use Jzvikas\OnePageCheckout\Http\CheckoutJsonResponse;
use Jzvikas\OnePageCheckout\Http\CheckoutMutationResponseMapper;

require_once __DIR__ . '/AbstractJzOpcMutationFrontController.php';

final class JzOnePageCheckoutPaymentselectionModuleFrontController extends JzOnePageCheckoutAbstractMutationModuleFrontController
{
    protected function executeCheckoutMutationRequest(): CheckoutJsonResponse
    {
        $mutation = $this->module->get(CheckoutPaymentSelectionMutation::class);
        $mapper = $this->module->get(CheckoutMutationResponseMapper::class);
        if (!$mutation instanceof CheckoutPaymentSelectionMutation || !$mapper instanceof CheckoutMutationResponseMapper) {
            throw new RuntimeException('Checkout payment mutation services are unavailable.');
        }

        $request = Tools::getAllValues();
        $translate = fn (string $message): string => $this->checkoutTranslate($message);

        return $mapper->map(
            $mutation->execute($this->context, $request, $translate),
            $translate,
        );
    }
}
