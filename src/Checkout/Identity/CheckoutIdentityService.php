<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Identity;

use PrestaShop\PrestaShop\Core\Crypto\Hashing;

final readonly class CheckoutIdentityService
{
    public function __construct(
        private Hashing $hashing,
    ) {
    }

    /**
     * @return array{
     *   bound:bool,
     *   guestAllowed:bool,
     *   isGuest:bool,
     *   firstName:string,
     *   lastName:string,
     *   email:string,
     *   registerFormHtml:string,
     *   loginFormHtml:string
     * }
     */
    public function present(\Context $context): array
    {
        $this->assertContext($context);
        $bound = $this->boundIdentityVariables($context);
        if ($bound !== null) {
            return $bound;
        }

        [$registerForm, $loginForm] = $this->createForms($context);

        return $this->anonymousIdentityVariables(
            $registerForm->render(),
            $loginForm->render(),
        );
    }

    /**
     * Reuses already-submitted Core form instances rendered by submit(). This avoids
     * recreating forms (and their hook-provided fields) just to display validation errors.
     *
     * @return array{
     *   bound:bool,
     *   guestAllowed:bool,
     *   isGuest:bool,
     *   firstName:string,
     *   lastName:string,
     *   email:string,
     *   registerFormHtml:string,
     *   loginFormHtml:string
     * }
     */
    public function presentWithRenderedForms(
        \Context $context,
        string $registerFormHtml,
        string $loginFormHtml,
    ): array {
        $this->assertContext($context);
        $bound = $this->boundIdentityVariables($context);
        if ($bound !== null) {
            return $bound;
        }

        return $this->anonymousIdentityVariables($registerFormHtml, $loginFormHtml);
    }

    /** @param array<string,mixed> $request */
    public function submit(\Context $context, array $request): CheckoutIdentitySubmission
    {
        $this->assertAnonymousCheckout($context);
        $action = $request['identityAction'] ?? null;
        if (!is_string($action) || !in_array($action, ['create', 'login'], true)) {
            throw new CheckoutIdentityException(
                'identity_action_invalid',
                'The customer information action is invalid.',
                'identityAction',
            );
        }

        [$registerForm, $loginForm] = $this->createForms($context);

        if ($action === 'login') {
            $loginForm->fillWith($request);
            if (!$loginForm->submit()) {
                return CheckoutIdentitySubmission::invalid(
                    $registerForm->render(),
                    $loginForm->render(),
                );
            }
        } else {
            $registerForm->fillWith($request);
            $hookResults = \Hook::exec('actionSubmitAccountBefore', [], null, true);
            $hookAccepted = is_array($hookResults) && array_reduce(
                $hookResults,
                static fn (bool $accepted, mixed $result): bool => $accepted && (bool) $result,
                true,
            );
            if (!$hookAccepted || !$registerForm->submit()) {
                return CheckoutIdentitySubmission::invalid(
                    $registerForm->render(),
                    $loginForm->render(),
                );
            }
        }

        $this->assertBoundAfterSubmit($context);

        return CheckoutIdentitySubmission::completed();
    }

    /**
     * @return array{0:\CustomerForm,1:\CustomerLoginForm}
     */
    private function createForms(\Context $context): array
    {
        $translator = $context->getTranslator();
        $urls = $this->templateUrls($context);
        $guestAllowed = $this->guestAllowed();

        $customerForRequirements = new \Customer();
        $formatter = (new \CustomerFormatter($translator, $context->language))
            ->setAskForPartnerOptin(\Configuration::get('PS_CUSTOMER_OPTIN'))
            ->setAskForBirthdate(\Configuration::get('PS_CUSTOMER_BIRTHDATE'))
            ->setPartnerOptinRequired($customerForRequirements->isFieldRequired('optin'));

        $registerForm = new \CustomerForm(
            $context->smarty,
            $context,
            $translator,
            $formatter,
            new \CustomerPersister(
                $context,
                $this->hashing,
                $translator,
                $guestAllowed,
            ),
            $urls,
        );
        $registerForm
            ->setGuestAllowed($guestAllowed)
            ->setAction('')
            ->fillFromCustomer($context->customer);

        $loginForm = new \CustomerLoginForm(
            $context->smarty,
            $context,
            $translator,
            new \CustomerLoginFormatter($translator),
            $urls,
        );
        $loginForm->setAction('');

        return [$registerForm, $loginForm];
    }

    /**
     * @return array{
     *   bound:true,
     *   guestAllowed:bool,
     *   isGuest:bool,
     *   firstName:string,
     *   lastName:string,
     *   email:string,
     *   registerFormHtml:'',
     *   loginFormHtml:''
     * }|null
     */
    private function boundIdentityVariables(\Context $context): ?array
    {
        $cartCustomerId = max(0, (int) $context->cart->id_customer);
        $contextCustomerId = max(0, (int) $context->customer->id);
        if ($cartCustomerId <= 0 && $contextCustomerId <= 0) {
            return null;
        }
        if ($cartCustomerId <= 0 || $contextCustomerId !== $cartCustomerId) {
            throw new CheckoutIdentityException('identity_customer_mismatch', 'Checkout customer state is inconsistent.');
        }

        return [
            'bound' => true,
            'guestAllowed' => $this->guestAllowed(),
            'isGuest' => (bool) $context->customer->is_guest,
            'firstName' => (string) $context->customer->firstname,
            'lastName' => (string) $context->customer->lastname,
            'email' => (string) $context->customer->email,
            'registerFormHtml' => '',
            'loginFormHtml' => '',
        ];
    }

    /**
     * @return array{
     *   bound:false,
     *   guestAllowed:bool,
     *   isGuest:false,
     *   firstName:'',
     *   lastName:'',
     *   email:'',
     *   registerFormHtml:string,
     *   loginFormHtml:string
     * }
     */
    private function anonymousIdentityVariables(string $registerFormHtml, string $loginFormHtml): array
    {
        return [
            'bound' => false,
            'guestAllowed' => $this->guestAllowed(),
            'isGuest' => false,
            'firstName' => '',
            'lastName' => '',
            'email' => '',
            'registerFormHtml' => $registerFormHtml,
            'loginFormHtml' => $loginFormHtml,
        ];
    }

    /** @return array<string,mixed> */
    private function templateUrls(\Context $context): array
    {
        $controller = $context->controller ?? null;
        if (!is_object($controller) || !method_exists($controller, 'getTemplateVarUrls')) {
            throw new CheckoutIdentityException('identity_urls_unavailable', 'Customer form URLs are unavailable.');
        }

        $urls = $controller->getTemplateVarUrls();
        if (!is_array($urls)) {
            throw new CheckoutIdentityException('identity_urls_unavailable', 'Customer form URLs are unavailable.');
        }

        return $urls;
    }

    private function assertAnonymousCheckout(\Context $context): void
    {
        $this->assertContext($context);
        if ((int) $context->cart->id_customer > 0 || (int) $context->customer->id > 0) {
            throw new CheckoutIdentityException(
                'identity_already_bound',
                'Checkout customer information is already bound to this cart.',
            );
        }
    }

    private function assertBoundAfterSubmit(\Context $context): void
    {
        $customerId = max(0, (int) ($context->customer->id ?? 0));
        $cartCustomerId = max(0, (int) ($context->cart->id_customer ?? 0));
        if ($customerId <= 0 || $cartCustomerId !== $customerId) {
            throw new CheckoutIdentityException(
                'identity_binding_failed',
                'PrestaShop did not bind the customer safely to the checkout cart.',
            );
        }
    }

    private function assertContext(\Context $context): void
    {
        if (!$context->cart instanceof \Cart || (int) ($context->cart->id ?? 0) <= 0) {
            throw new CheckoutIdentityException('identity_cart_required', 'A loaded checkout cart is required.');
        }
        if (!$context->customer instanceof \Customer) {
            throw new CheckoutIdentityException('identity_context_invalid', 'Checkout customer state is unavailable.');
        }
        if (!$context->language instanceof \Language || !$context->smarty instanceof \Smarty) {
            throw new CheckoutIdentityException('identity_context_invalid', 'Checkout customer form context is unavailable.');
        }
    }

    private function guestAllowed(): bool
    {
        return (bool) \Configuration::get('PS_GUEST_CHECKOUT_ENABLED');
    }
}
