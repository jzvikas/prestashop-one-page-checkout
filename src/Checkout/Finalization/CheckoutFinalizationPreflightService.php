<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Finalization;

use Jzvikas\OnePageCheckout\Checkout\Agreements\CheckoutAgreementSelectionException;
use Jzvikas\OnePageCheckout\Checkout\Agreements\CheckoutAgreementSelectionService;
use Jzvikas\OnePageCheckout\Checkout\Carrier\CheckoutCarrierSelectionException;
use Jzvikas\OnePageCheckout\Checkout\Carrier\CheckoutCarrierSelectionParser;
use Jzvikas\OnePageCheckout\Checkout\Carrier\CheckoutCarrierSelectionService;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\Payment\CheckoutPaymentSelectionException;
use Jzvikas\OnePageCheckout\Checkout\Payment\CheckoutPaymentSelectionParser;
use Jzvikas\OnePageCheckout\Checkout\Payment\CheckoutPaymentSelectionService;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutCartPresenterInterface;
use RuntimeException;
use Throwable;

final readonly class CheckoutFinalizationPreflightService
{
    public function __construct(
        private CheckoutCartPresenterInterface $cartPresenter,
        private CheckoutCarrierSelectionParser $carrierSelectionParser,
        private CheckoutCarrierSelectionService $carrierSelectionService,
        private CheckoutPaymentSelectionParser $paymentSelectionParser,
        private CheckoutPaymentSelectionService $paymentSelectionService,
        private CheckoutAgreementSelectionService $agreementSelectionService,
    ) {
    }

    public function validate(\Context $context, CheckoutServerSelections $selections): void
    {
        $cart = $context->cart ?? null;
        if (!$cart instanceof \Cart || (int) ($cart->id ?? 0) <= 0) {
            throw new RuntimeException('Checkout finalization requires a loaded cart.');
        }

        if ($cart->OrderExists() || (int) \Order::getIdByCartId((int) $cart->id) > 0) {
            throw new CheckoutFinalizationPreflightException(CheckoutFinalizationPreflightReason::OrderAlreadyExists);
        }

        $customer = $context->customer ?? null;
        $customerId = (int) ($cart->id_customer ?? 0);
        if (!$customer instanceof \Customer
            || $customerId <= 0
            || !\Validate::isLoadedObject($customer)
            || (int) $customer->id !== $customerId) {
            throw new CheckoutFinalizationPreflightException(CheckoutFinalizationPreflightReason::CustomerRequired);
        }

        $presentedCart = $this->cartPresenter->present($context);
        $products = $this->presentedValue($presentedCart, 'products');
        if (!is_countable($products) || count($products) === 0) {
            throw new CheckoutFinalizationPreflightException(CheckoutFinalizationPreflightReason::CartEmpty);
        }

        $minimumPurchaseRequired = $this->presentedValue($presentedCart, 'minimalPurchaseRequired');
        if ($minimumPurchaseRequired !== null
            && $minimumPurchaseRequired !== false
            && $minimumPurchaseRequired !== ''
            && $minimumPurchaseRequired !== 0
            && $minimumPurchaseRequired !== '0') {
            throw new CheckoutFinalizationPreflightException(CheckoutFinalizationPreflightReason::MinimumPurchaseRequired);
        }

        if ($cart->isAllProductsInStock() !== true
            || $cart->checkAllProductsAreStillAvailableInThisState() !== true
            || $cart->checkAllProductsHaveMinimalQuantities() !== true
            || $cart->checkCountriesAreEnabled() !== true) {
            throw new CheckoutFinalizationPreflightException(CheckoutFinalizationPreflightReason::CartNotOrderable);
        }

        $this->validateAddresses($cart, $customerId);
        $this->validateCarrier($context, $cart);
        $this->validatePayment($context, $selections);
        $this->validateAgreements($context, $selections);
    }

    private function validateAddresses(\Cart $cart, int $customerId): void
    {
        $deliveryAddressId = (int) ($cart->id_address_delivery ?? 0);
        $invoiceAddressId = (int) ($cart->id_address_invoice ?? 0);
        if ($deliveryAddressId <= 0 || $invoiceAddressId <= 0
            || !\Customer::customerHasAddress($customerId, $deliveryAddressId)
            || !\Customer::customerHasAddress($customerId, $invoiceAddressId)) {
            throw new CheckoutFinalizationPreflightException(CheckoutFinalizationPreflightReason::AddressInvalid);
        }

        if (!class_exists(\AddressValidator::class)) {
            throw new RuntimeException('PrestaShop AddressValidator is required for checkout finalization.');
        }

        try {
            $invalidAddressIds = (new \AddressValidator())->validateCartAddresses($cart);
        } catch (Throwable $exception) {
            throw new CheckoutFinalizationPreflightException(
                CheckoutFinalizationPreflightReason::AddressInvalid,
                $exception,
            );
        }

        if (!is_array($invalidAddressIds) || $invalidAddressIds !== []) {
            throw new CheckoutFinalizationPreflightException(CheckoutFinalizationPreflightReason::AddressInvalid);
        }
    }

    private function validateCarrier(\Context $context, \Cart $cart): void
    {
        if ($cart->isVirtualCart()) {
            return;
        }

        $deliveryAddressId = (int) ($cart->id_address_delivery ?? 0);
        $rawDeliveryOption = $cart->delivery_option ?? null;
        if (!is_string($rawDeliveryOption) || $rawDeliveryOption === '') {
            throw new CheckoutFinalizationPreflightException(CheckoutFinalizationPreflightReason::CarrierInvalid);
        }

        $decoded = json_decode($rawDeliveryOption, true);
        $deliveryOption = is_array($decoded) ? ($decoded[$deliveryAddressId] ?? null) : null;
        if (!is_string($deliveryOption) || $deliveryOption === '') {
            throw new CheckoutFinalizationPreflightException(CheckoutFinalizationPreflightReason::CarrierInvalid);
        }

        try {
            $selection = $this->carrierSelectionParser->parse(['deliveryOption' => $deliveryOption]);
            if ($this->carrierSelectionService->apply($context, $selection)) {
                throw new RuntimeException('Checkout finalization unexpectedly changed the persisted carrier selection.');
            }
        } catch (CheckoutCarrierSelectionException $exception) {
            throw new CheckoutFinalizationPreflightException(
                CheckoutFinalizationPreflightReason::CarrierInvalid,
                $exception,
            );
        }
    }

    private function validatePayment(\Context $context, CheckoutServerSelections $selections): void
    {
        $stateKey = $selections->selectedPaymentOption;
        if (!is_string($stateKey) || $stateKey === '') {
            throw new CheckoutFinalizationPreflightException(CheckoutFinalizationPreflightReason::PaymentInvalid);
        }

        $separator = strpos($stateKey, ':');
        if ($separator === false || $separator === 0 || $separator === strlen($stateKey) - 1) {
            throw new CheckoutFinalizationPreflightException(CheckoutFinalizationPreflightReason::PaymentInvalid);
        }

        $moduleName = substr($stateKey, 0, $separator);
        $optionId = substr($stateKey, $separator + 1);

        try {
            $requested = $this->paymentSelectionParser->parse([
                'paymentOptionId' => $optionId,
                'paymentModule' => $moduleName,
            ]);
            $validated = $this->paymentSelectionService->validate($context, $requested);
        } catch (CheckoutPaymentSelectionException $exception) {
            throw new CheckoutFinalizationPreflightException(
                CheckoutFinalizationPreflightReason::PaymentInvalid,
                $exception,
            );
        }

        if (!hash_equals($stateKey, $validated->stateKey())) {
            throw new CheckoutFinalizationPreflightException(CheckoutFinalizationPreflightReason::PaymentInvalid);
        }
    }

    private function validateAgreements(\Context $context, CheckoutServerSelections $selections): void
    {
        try {
            $this->agreementSelectionService->validate($context, $selections->approvedAgreementKeys);
        } catch (CheckoutAgreementSelectionException $exception) {
            throw new CheckoutFinalizationPreflightException(
                CheckoutFinalizationPreflightReason::AgreementsInvalid,
                $exception,
            );
        }
    }

    private function presentedValue(mixed $presented, string $key): mixed
    {
        if (is_array($presented)) {
            return $presented[$key] ?? null;
        }

        if ($presented instanceof \ArrayAccess) {
            try {
                return $presented->offsetGet($key);
            } catch (Throwable) {
                return null;
            }
        }

        throw new RuntimeException('Core cart presenter returned an unsupported checkout payload.');
    }
}
