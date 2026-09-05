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

            // Core Context::updateCustomer() can restore another non-ordered customer cart.
            // That replacement cart was not the cart mutex acquired for this request, so the
            // identity mutation deliberately does not persist/render module state for it.
            // A full order-page reload establishes a new authoritative bootstrap instead.
            $submittedCartId = isset($request['cartId']) && (is_int($request['cartId']) || is_string($request['cartId']))
                ? (int) $request['cartId']
                : 0;
            $currentCartId = (int) ($this->context->cart->id ?? 0);
            if ($submittedCartId > 0 && $currentCartId > 0 && $submittedCartId !== $currentCartId) {
                $refreshResult = $result->refreshResult;
                if ($refreshResult === null) {
                    throw new RuntimeException('Completed identity cart transition has no checkout state.');
                }

                $redirect = (string) $this->context->link->getPageLink('order', true);
                if ($redirect === '') {
                    throw new RuntimeException('Checkout reload URL is unavailable after identity cart transition.');
                }

                return new CheckoutJsonResponse(200, [
                    'success' => true,
                    'stateVersion' => $refreshResult->stateVersion,
                    'sections' => [],
                    'errors' => [],
                    'redirect' => $redirect,
                    'retryable' => false,
                    'csrfToken' => $freshCsrfToken,
                ]);
            }
        }

        return $mapper->map($result, $translate, $freshCsrfToken);
    }
}
