<?php

declare(strict_types=1);

class Context
{
    public $cart;
}

class Cart
{
    public const BOTH = 3;

    public int $id = 42;
    public float $total = 12.5;

    public function getOrderTotal(bool $withTaxes, int $type): float
    {
        assert($withTaxes === true);
        assert($type === self::BOTH);

        return $this->total;
    }
}

class Hook
{
    /** @var list<string> */
    public static array $calls = [];

    public static function exec(string $hookName, array $params = []): string
    {
        self::$calls[] = $hookName;

        return $hookName === 'displayPaymentTop' ? '<payment-top>' : '';
    }
}

class PaymentOptionsFinder
{
    public static ?bool $free = null;

    public function present(bool $free = false): array
    {
        self::$free = $free;
        Hook::$calls[] = 'actionPresentPaymentOptions';

        if ($free) {
            return [
                'free_order' => [[
                    'id' => 'payment-option-1',
                    'module_name' => 'free_order',
                    'binary' => false,
                    'action' => '/order-confirmation?free_order=1',
                    'form' => null,
                    'inputs' => [],
                    'logo' => null,
                    'additionalInformation' => null,
                    'call_to_action_text' => 'Free order',
                ]],
            ];
        }

        return [
            'demo' => [[
                'id' => 'payment-option-1',
                'module_name' => 'demo',
                'binary' => false,
                'action' => '/pay',
                'form' => null,
                'inputs' => [['type' => 'hidden', 'name' => 'token', 'value' => 'server-value']],
                'logo' => null,
                'additionalInformation' => '<strong>Trusted module HTML</strong>',
                'call_to_action_text' => 'Demo payment',
            ]],
            'invalid' => null,
        ];
    }
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\Rendering\PrestaShopCheckoutPaymentOptionsPresenter;

$context = new Context();
$context->cart = new Cart();
$presenter = new PrestaShopCheckoutPaymentOptionsPresenter();

$result = $presenter->present($context);
assert($result['isFree'] === false);
assert(PaymentOptionsFinder::$free === false);
assert(array_keys($result['paymentOptions']) === ['demo']);
assert($result['paymentOptions']['demo'][0]['action'] === '/pay');
assert($result['paymentOptions']['demo'][0]['additionalInformation'] === '<strong>Trusted module HTML</strong>');
assert($result['hookDisplayPaymentTop'] === '<payment-top>');
assert(Hook::$calls === ['actionPresentPaymentOptions', 'displayPaymentTop']);

Hook::$calls = [];
$context->cart->total = 0.0;
$result = $presenter->present($context);
assert($result['isFree'] === true);
assert(PaymentOptionsFinder::$free === true);
assert(array_keys($result['paymentOptions']) === ['free_order']);
assert($result['paymentOptions']['free_order'][0]['module_name'] === 'free_order');
assert($result['paymentOptions']['free_order'][0]['action'] === '/order-confirmation?free_order=1');
assert(Hook::$calls === ['actionPresentPaymentOptions', 'displayPaymentTop']);

echo "PrestaShopCheckoutPaymentOptionsPresenterSmokeTest OK\n";
