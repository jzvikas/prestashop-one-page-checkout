<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Http\CheckoutJsonResponse;

require_once __DIR__ . '/AbstractJzOpcJsonFrontController.php';

abstract class JzOnePageCheckoutAbstractMutationModuleFrontController extends JzOnePageCheckoutAbstractJsonModuleFrontController
{
    final protected function handleCheckoutJsonRequest(): CheckoutJsonResponse
    {
        if (!method_exists($this->module, 'isCustomCheckoutActive') || !$this->module->isCustomCheckoutActive()) {
            return CheckoutJsonResponse::error(
                404,
                'checkout_unavailable',
                $this->checkoutTranslate('This checkout action is not available.'),
            );
        }

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            return CheckoutJsonResponse::error(
                405,
                'method_not_allowed',
                $this->checkoutTranslate('This checkout action requires a POST request.'),
                headers: ['Allow' => 'POST'],
            );
        }

        return $this->executeCheckoutMutationRequest();
    }

    abstract protected function executeCheckoutMutationRequest(): CheckoutJsonResponse;
}
