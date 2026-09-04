<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Http\CheckoutJsonResponse;

abstract class JzOnePageCheckoutAbstractJsonModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    final public function initContent()
    {
        parent::initContent();

        try {
            $response = $this->handleCheckoutJsonRequest();
        } catch (Throwable $exception) {
            $this->logCheckoutRuntimeException($exception);
            $response = CheckoutJsonResponse::error(
                500,
                'technical_error',
                $this->checkoutTranslate('We could not update your checkout. Please try again.'),
                retryable: true,
            );
        }

        $this->renderCheckoutJsonResponse($response);
    }

    abstract protected function handleCheckoutJsonRequest(): CheckoutJsonResponse;

    protected function checkoutTranslate(string $message): string
    {
        return $this->trans($message, [], 'Modules.Jzonepagecheckout.Shop');
    }

    private function logCheckoutRuntimeException(Throwable $exception): void
    {
        try {
            $cartId = (int) ($this->context->cart->id ?? 0);
            $shopId = (int) ($this->context->shop->id ?? 0);
            $moduleId = (int) ($this->module->id ?? 0);

            PrestaShopLogger::addLog(
                sprintf(
                    'jzonepagecheckout: AJAX runtime failure [%s] [controller=%s] [shop=%d] [cart=%d]',
                    $exception::class,
                    static::class,
                    $shopId,
                    $cartId,
                ),
                3,
                null,
                'Module',
                $moduleId,
                true,
            );
        } catch (Throwable) {
            // Observability must never replace the safe customer response with another failure.
        }
    }

    private function renderCheckoutJsonResponse(CheckoutJsonResponse $response): never
    {
        http_response_code($response->statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');

        foreach ($response->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        try {
            $json = $response->toJson();
        } catch (Throwable) {
            http_response_code(500);
            $json = '{"success":false,"stateVersion":null,"sections":{},"errors":[{"code":"technical_error","message":"Checkout response encoding failed.","field":null}],"redirect":null,"retryable":true}';
        }

        $this->ajaxRender($json);
        exit;
    }
}
