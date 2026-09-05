<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Checkout\Mutation\CheckoutAddressFormMutation;
use Jzvikas\OnePageCheckout\Http\CheckoutJsonResponse;
use Jzvikas\OnePageCheckout\Http\CheckoutMutationResponseMapper;

require_once __DIR__ . '/AbstractJzOpcMutationFrontController.php';

final class JzOnePageCheckoutAddresssaveModuleFrontController extends JzOnePageCheckoutAbstractMutationModuleFrontController
{
    protected function executeCheckoutMutationRequest(): CheckoutJsonResponse
    {
        $mutation = $this->module->get(CheckoutAddressFormMutation::class);
        $mapper = $this->module->get(CheckoutMutationResponseMapper::class);
        if (!$mutation instanceof CheckoutAddressFormMutation || !$mapper instanceof CheckoutMutationResponseMapper) {
            throw new RuntimeException('Checkout address form mutation services are unavailable.');
        }

        $request = Tools::getAllValues();
        $translate = fn (string $message): string => $this->checkoutTranslate($message);

        return $mapper->map(
            $mutation->execute($this->context, $request, $translate),
            $translate,
        );
    }
}
