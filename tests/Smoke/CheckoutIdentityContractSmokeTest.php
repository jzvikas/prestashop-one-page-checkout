<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/src/Checkout/Identity/CheckoutIdentityService.php');
$submission = file_get_contents($root . '/src/Checkout/Identity/CheckoutIdentitySubmission.php');
$mutation = file_get_contents($root . '/src/Checkout/Mutation/CheckoutIdentityMutation.php');
$renderer = file_get_contents($root . '/src/Checkout/Rendering/IdentitySectionRenderer.php');
$template = file_get_contents($root . '/views/templates/front/sections/identity.tpl');
$controller = file_get_contents($root . '/controllers/front/identity.php');
$mapper = file_get_contents($root . '/src/Http/CheckoutMutationResponseMapper.php');
$config = file_get_contents($root . '/config/common/services.yml');

function assertIdentityContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$service, $submission, $mutation, $renderer, $template, $controller, $mapper, $config] as $source) {
    assertIdentityContract(is_string($source), 'identity contract source must be readable');
}

assertIdentityContract(str_contains($service, 'new \\CustomerForm('), 'guest/account creation must use Core CustomerForm');
assertIdentityContract(str_contains($service, 'new \\CustomerPersister('), 'guest/account persistence must use Core CustomerPersister');
assertIdentityContract(str_contains($service, 'new \\CustomerFormatter('), 'identity fields must use Core CustomerFormatter');
assertIdentityContract(str_contains($service, 'new \\CustomerLoginForm('), 'login must use Core CustomerLoginForm');
assertIdentityContract(str_contains($service, 'new \\CustomerLoginFormatter('), 'login fields must use Core CustomerLoginFormatter');
assertIdentityContract(str_contains($service, "Configuration::get('PS_GUEST_CHECKOUT_ENABLED')"), 'guest mode must follow shop Core configuration');
assertIdentityContract(str_contains($service, "\\Hook::exec('actionSubmitAccountBefore'"), 'account creation must preserve Core pre-submit hook');
assertIdentityContract(str_contains($service, '$loginForm->submit()'), 'login must delegate authentication to Core form');
assertIdentityContract(str_contains($service, '$registerForm->submit()'), 'customer persistence must delegate to Core form');
assertIdentityContract(str_contains($service, '$cartCustomerId !== $customerId'), 'successful identity transition must verify cart/customer binding');
assertIdentityContract(str_contains($service, 'presentWithRenderedForms'), 'invalid Core form HTML must be reused rather than recreating hook fields');
assertIdentityContract(!str_contains($service, 'password_hash('), 'module must not invent password hashing');
assertIdentityContract(!str_contains($service, '->add()'), 'module identity service must not bypass Core persister with direct ObjectModel creation');
assertIdentityContract(!str_contains($service, '->passwd ='), 'module must not write customer passwords directly');

assertIdentityContract(str_contains($mutation, 'CheckoutMutation::IdentityUpdated'), 'identity changes must use the full downstream dependency graph');
assertIdentityContract(str_contains($mutation, '$this->identityService->submit($context, $request)'), 'identity Core submission must execute inside the guarded orchestrator');
assertIdentityContract(str_contains($mutation, 'new CheckoutServerSelections()'), 'successful identity transition must invalidate prior payment/agreement authority');
assertIdentityContract(str_contains($mutation, "(int) (\$context->cart->id ?? 0) !== \$state->cartId"), 'Core cart restoration must be detected against the cart locked at request start');
assertIdentityContract(str_contains($mutation, "'identity_cart_reloaded'"), 'cart restoration must use a stable internal safety code');
assertIdentityContract(str_contains($mutation, 'CheckoutMutationOutcome::failure('), 'replacement-cart state must not be persisted as a successful old-cart mutation');

assertIdentityContract(str_contains($renderer, 'CheckoutSection::Identity'), 'identity renderer must own only the identity section');
assertIdentityContract(str_contains($template, 'data-jzopc-section="identity"'), 'identity template must expose a stable section root');
assertIdentityContract(str_contains($template, 'data-jzopc-identity-form="create"'), 'anonymous identity must expose the Core create/guest form wrapper');
assertIdentityContract(str_contains($template, 'data-jzopc-identity-form="login"'), 'anonymous identity must expose the Core login form wrapper');
assertIdentityContract(str_contains($template, '{$registerFormHtml nofilter}'), 'Core/theme customer form HTML is an explicit trusted boundary');
assertIdentityContract(str_contains($template, '{$loginFormHtml nofilter}'), 'Core/theme login form HTML is an explicit trusted boundary');
assertIdentityContract(str_contains($template, "|escape:'html':'UTF-8'"), 'bound customer presentation values must remain escaped');

assertIdentityContract(str_contains($controller, 'extends JzOnePageCheckoutAbstractMutationModuleFrontController'), 'identity endpoint must inherit shared POST/activation guard');
assertIdentityContract(str_contains($controller, 'CheckoutMutationExecutionStatus::Completed'), 'replacement CSRF token must only be generated after guarded completion');
assertIdentityContract(str_contains($controller, 'Tools::getToken(false)'), 'identity endpoint must regenerate Core front CSRF after auth transition');
assertIdentityContract(str_contains($controller, "getPageLink('order', true)"), 'Core-restored cart must force a fresh authoritative order-page bootstrap');
assertIdentityContract(str_contains($controller, "'sections' => []"), 'replacement-cart response must not apply old-cart section HTML');
assertIdentityContract(str_contains($controller, "'redirect' => \$redirect"), 'replacement-cart response must redirect instead of continuing AJAX on the old binding');
assertIdentityContract(str_contains($mapper, "\$body['csrfToken'] = \$freshCsrfToken"), 'completed response mapper must support explicit token rotation');
assertIdentityContract(str_contains($config, "Checkout\\Identity\\CheckoutIdentityService:"), 'identity service must be present in the shared service graph');
assertIdentityContract(str_contains($config, "Checkout\\Mutation\\CheckoutIdentityMutation:\n    public: true"), 'identity mutation must be an intentional module-front entry service');

fwrite(STDOUT, "Checkout identity contract smoke tests passed.\n");
