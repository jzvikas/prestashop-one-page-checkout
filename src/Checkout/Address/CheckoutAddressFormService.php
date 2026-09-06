<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Address;

use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSessionProviderInterface;

final readonly class CheckoutAddressFormService
{
    public function __construct(
        private CheckoutSessionProviderInterface $checkoutSessionProvider,
    ) {
    }

    /**
     * Build a native Core address form for a new/edit intent without persisting anything.
     * Submitted values are fed back through CustomerAddressForm::fillWith(), so country changes
     * regenerate country/state-dependent Core fields before the editor is rendered again.
     *
     * @param array<string,mixed> $request
     */
    public function present(\Context $context, array $request = []): string
    {
        $this->assertCustomerContext($context);
        $addressId = $this->parseOptionalAddressId($request['id_address'] ?? null);
        $form = $this->createForm($context);

        if ($addressId !== null) {
            $this->assertOwnedAddress($context, $addressId);
            $form->loadAddressById($addressId);
        } else {
            $form->fillWith([]);
        }

        if ($request !== []) {
            $payload = $request;
            $payload['id_address'] = $addressId ?? 0;
            $payload['token'] = \Tools::getToken(true, $context);
            $form->fillWith($payload);
        }

        return $form->render();
    }

    /** @param array<string,mixed> $request */
    public function submit(\Context $context, array $request): CheckoutAddressFormSubmission
    {
        $this->assertCustomerContext($context);

        $addressId = $this->parseOptionalAddressId($request['id_address'] ?? null);
        if ($addressId !== null) {
            $this->assertOwnedAddress($context, $addressId);
        }

        $role = $this->parseRole($request['addressRole'] ?? null);
        $useSameAddress = $this->parseBoolean($request['useSameAddress'] ?? '0');
        if ($role === 'invoice' && $useSameAddress) {
            throw new CheckoutAddressFormException('address_role_invalid', 'A separate invoice address cannot also be marked as the delivery address.', 'addressRole');
        }

        $form = $this->createForm($context);
        if ($addressId !== null) {
            $form->loadAddressById($addressId);
        }

        // The module mutation endpoint already validates its own checkout CSRF token. Core's
        // address persister has a distinct token contract, so browser input is never trusted for
        // that token: inject the current server-generated value directly into the native form.
        $payload = $request;
        $payload['id_address'] = $addressId ?? 0;
        $payload['token'] = \Tools::getToken(true, $context);
        $form->fillWith($payload);

        if (!$form->submit()) {
            return CheckoutAddressFormSubmission::invalid($form->render());
        }

        $address = $form->getAddress();
        $savedAddressId = $address instanceof \Address ? (int) $address->id : 0;
        if ($savedAddressId <= 0 || !\Customer::customerHasAddress((int) $context->customer->id, $savedAddressId)) {
            throw new CheckoutAddressFormException('address_save_failed', 'The address could not be saved safely.');
        }

        $session = $this->checkoutSessionProvider->get($context);
        if (!$session instanceof \CheckoutSession) {
            throw new CheckoutAddressFormException('address_session_unavailable', 'Checkout address state is unavailable.');
        }

        if ($role === 'delivery') {
            $session->setIdAddressDelivery($savedAddressId);
            if ($useSameAddress) {
                $session->setIdAddressInvoice($savedAddressId);
            }
        } else {
            $session->setIdAddressInvoice($savedAddressId);
        }

        // Address persistence can invalidate Cart package/delivery calculations already evaluated
        // earlier in the same OPC request. Dependent carrier/payment rendering must therefore use
        // a fresh Core Cart loaded from the just-committed database state, never a pre-mutation
        // in-memory object. We still let Core CheckoutSession own every address write above.
        $this->refreshAuthoritativeCart($context, $role, $savedAddressId, $useSameAddress);

        return CheckoutAddressFormSubmission::saved($savedAddressId, $form->render());
    }

    public function role(mixed $value): string
    {
        return $this->parseRole($value);
    }

    private function createForm(\Context $context): \CustomerAddressForm
    {
        $availableCountries = \Configuration::get('PS_RESTRICT_DELIVERED_COUNTRIES')
            ? \Carrier::getDeliveredCountries((int) $context->language->id, true, true)
            : \Country::getCountries((int) $context->language->id, true);

        $persister = new \CustomerAddressPersister(
            $context->customer,
            $context->cart,
            \Tools::getToken(true, $context),
        );
        $form = new \CustomerAddressForm(
            $context->smarty,
            $context->language,
            $context->getTranslator(),
            $persister,
            new \CustomerAddressFormatter(
                $context->country,
                $context->getTranslator(),
                $availableCountries,
            ),
        );
        $form->setAction('');

        return $form;
    }

    private function assertCustomerContext(\Context $context): void
    {
        $cart = $context->cart ?? null;
        $customer = $context->customer ?? null;
        if (!$cart instanceof \Cart || !$customer instanceof \Customer) {
            throw new CheckoutAddressFormException('address_context_invalid', 'Checkout customer state is unavailable.');
        }

        $customerId = (int) $customer->id;
        if ($customerId <= 0 || (int) $cart->id_customer !== $customerId) {
            throw new CheckoutAddressFormException('address_customer_required', 'Customer information must be completed before saving an address.');
        }
    }

    private function refreshAuthoritativeCart(\Context $context, string $role, int $savedAddressId, bool $useSameAddress): void
    {
        $currentCart = $context->cart ?? null;
        $customer = $context->customer ?? null;
        if (!$currentCart instanceof \Cart || !$customer instanceof \Customer) {
            throw new CheckoutAddressFormException('address_cart_refresh_failed', 'Checkout cart state could not be refreshed safely.');
        }

        $cartId = (int) $currentCart->id;
        $customerId = (int) $customer->id;
        $shopId = (int) ($context->shop->id ?? 0);
        if ($cartId <= 0 || $customerId <= 0 || $shopId <= 0) {
            throw new CheckoutAddressFormException('address_cart_refresh_failed', 'Checkout cart state could not be refreshed safely.');
        }

        $freshCart = new \Cart($cartId);
        if (!\Validate::isLoadedObject($freshCart)
            || (int) $freshCart->id_customer !== $customerId
            || (int) $freshCart->id_shop !== $shopId) {
            throw new CheckoutAddressFormException('address_cart_refresh_failed', 'Checkout cart binding changed while saving the address.');
        }

        if ($role === 'delivery') {
            if ((int) $freshCart->id_address_delivery !== $savedAddressId
                || ($useSameAddress && (int) $freshCart->id_address_invoice !== $savedAddressId)) {
                throw new CheckoutAddressFormException('address_cart_refresh_failed', 'The saved delivery address was not committed to the checkout cart.');
            }
        } elseif ((int) $freshCart->id_address_invoice !== $savedAddressId) {
            throw new CheckoutAddressFormException('address_cart_refresh_failed', 'The saved invoice address was not committed to the checkout cart.');
        }

        $context->cart = $freshCart;
    }

    private function assertOwnedAddress(\Context $context, int $addressId): void
    {
        if ($addressId <= 0 || !\Customer::customerHasAddress((int) $context->customer->id, $addressId)) {
            throw new CheckoutAddressFormException('address_forbidden', 'The selected address is unavailable.', 'id_address');
        }
    }

    private function parseOptionalAddressId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value)) {
            throw new CheckoutAddressFormException('address_id_invalid', 'The address identifier is invalid.', 'id_address');
        }

        return (int) $value;
    }

    private function parseRole(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['delivery', 'invoice'], true)) {
            throw new CheckoutAddressFormException('address_role_invalid', 'Choose whether this is a delivery or invoice address.', 'addressRole');
        }

        return $value;
    }

    private function parseBoolean(mixed $value): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0') {
            return false;
        }

        throw new CheckoutAddressFormException('address_mode_invalid', 'The address mode is invalid.', 'useSameAddress');
    }
}