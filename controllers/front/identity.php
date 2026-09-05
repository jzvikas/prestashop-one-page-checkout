<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationExecutionStatus;
use Jzvikas\OnePageCheckout\Checkout\Mutation\CheckoutIdentityMutation;
use Jzvikas\OnePageCheckout\Http\CheckoutJsonResponse;
use Jzvikas\OnePageCheckout\Http\CheckoutMutationResponseMapper;

require_once __DIR__ . '/AbstractJzOpcMutationFrontController.php';

final class JzOnePageCheckoutIdentityModuleFrontController extends JzOnePageCheckoutAbstractMutationModuleFrontController
{
    protected function executeCheckoutMutationRequest(): CheckoutJsonResponse
    {
        $mutation = $this->module->get(CheckoutIdentityMutation::class);
        $mapper = $this->module->get(CheckoutMutationResponseMapper::class);
        if (!$mutation instanceof CheckoutIdentityMutation || !$mapper instanceof CheckoutMutationResponseMapper) {
            throw new RuntimeException('Checkout identity mutation services are unavailable.');
        }

        $request = Tools::getAllValues();
        $translate = fn (string $message): string => $this->checkoutTranslate($message);
        $result = $mutation->execute($this->context, $request, $translate);

        // Customer creation/login updates the Core Context/cookie and can rotate the
        // front-office token. Only a request that already passed the mutation guard may
        // receive a replacement token; rejected CSRF requests never get token material.
        $freshCsrfToken = null;
        if ($result->status === CheckoutMutationExecutionStatus::Completed) {
            $freshCsrfToken = (string) Tools::getToken(false);
            if ($freshCsrfToken === '') {
                throw new RuntimeException('Checkout CSRF token is unavailable after identity mutation.');
            }
        }

        return $mapper->map($result, $translate, $freshCsrfToken);
    }
}
