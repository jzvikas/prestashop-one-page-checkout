<?php

declare(strict_types=1);

final class TestCheckoutModule
{
    public int $id = 1;
    public bool $checkoutActive = true;

    public function isCustomCheckoutActive(): bool
    {
        return $this->checkoutActive;
    }
}

class ModuleFrontController
{
    public object $context;
    public TestCheckoutModule $module;

    public function __construct()
    {
        $this->context = (object) [
            'cart' => (object) ['id' => 42],
            'shop' => (object) ['id' => 2],
        ];
        $this->module = new TestCheckoutModule();
    }

    public function initContent() {}

    public function trans(string $message, array $parameters = [], string $domain = ''): string
    {
        return 'T:' . $message;
    }

    public function ajaxRender(string $json): void {}
}

final class PrestaShopLogger
{
    public static function addLog(...$arguments): bool
    {
        return true;
    }
}

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/controllers/front/AbstractJzOpcMutationFrontController.php';

use Jzvikas\OnePageCheckout\Http\CheckoutJsonResponse;

final class TestMutationController extends JzOnePageCheckoutAbstractMutationModuleFrontController
{
    public int $executions = 0;

    protected function executeCheckoutMutationRequest(): CheckoutJsonResponse
    {
        ++$this->executions;

        return new CheckoutJsonResponse(200, ['success' => true]);
    }
}

function assertControllerContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$reflection = new ReflectionMethod(JzOnePageCheckoutAbstractMutationModuleFrontController::class, 'handleCheckoutJsonRequest');
assertControllerContract($reflection->isFinal(), 'mutation request gate must be final so concrete controllers cannot bypass transport/activation enforcement');

$controller = new TestMutationController();
$method = new ReflectionMethod(TestMutationController::class, 'handleCheckoutJsonRequest');
$method->setAccessible(true);

$_SERVER['REQUEST_METHOD'] = 'GET';
$getResponse = $method->invoke($controller);
assertControllerContract($getResponse instanceof CheckoutJsonResponse, 'GET gate must return JSON response object');
assertControllerContract($getResponse->statusCode === 405, 'GET mutation request must return HTTP 405');
assertControllerContract(($getResponse->headers['Allow'] ?? null) === 'POST', '405 response must advertise POST');
assertControllerContract($controller->executions === 0, 'GET must not reach mutation implementation');

$_SERVER['REQUEST_METHOD'] = 'POST';
$postResponse = $method->invoke($controller);
assertControllerContract($postResponse->statusCode === 200, 'active checkout POST must reach mutation implementation');
assertControllerContract($controller->executions === 1, 'active checkout POST must execute mutation implementation exactly once');

$controller->module->checkoutActive = false;
$inactiveResponse = $method->invoke($controller);
assertControllerContract($inactiveResponse->statusCode === 404, 'inactive checkout POST must fail closed');
assertControllerContract(($inactiveResponse->body['errors'][0]['code'] ?? null) === 'checkout_unavailable', 'inactive checkout must expose stable unavailable error code');
assertControllerContract($controller->executions === 1, 'inactive checkout must not execute mutation implementation');

fwrite(STDOUT, "Checkout front controller contract smoke tests passed.\n");
