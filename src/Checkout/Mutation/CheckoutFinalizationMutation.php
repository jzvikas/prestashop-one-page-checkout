<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Mutation;

use Closure;
use Jzvikas\OnePageCheckout\Checkout\CheckoutError;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutation;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationExecutionResult;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationOrchestrator;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationOutcome;
use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\CheckoutStateVersioner;
use Jzvikas\OnePageCheckout\Checkout\Finalization\CheckoutFinalizationPreflightException;
use Jzvikas\OnePageCheckout\Checkout\Finalization\CheckoutFinalizationPreflightReason;
use Jzvikas\OnePageCheckout\Checkout\Finalization\CheckoutFinalizationPreflightService;
use Jzvikas\OnePageCheckout\Checkout\Finalization\CheckoutFinalizationReservationAlreadyActive;
use Jzvikas\OnePageCheckout\Checkout\Finalization\CheckoutFinalizationReservationStoreInterface;
use Jzvikas\OnePageCheckout\Checkout\Finalization\CheckoutFinalizationReservationUnavailable;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSectionRendererRegistry;

final readonly class CheckoutFinalizationMutation
{
    private const ACTION_BEGIN = 'begin';
    private const ACTION_RELEASE = 'release';

    public function __construct(
        private CheckoutMutationOrchestrator $orchestrator,
        private CheckoutFinalizationPreflightService $preflightService,
        private CheckoutFinalizationReservationStoreInterface $reservationStore,
        private CheckoutStateVersioner $stateVersioner,
        private CheckoutSectionRendererRegistry $rendererRegistry,
    ) {
    }

    /**
     * @param array<string,mixed> $request
     * @param Closure(string):string $translate
     */
    public function execute(\Context $context, array $request, Closure $translate): CheckoutMutationExecutionResult
    {
        return $this->orchestrator->execute(
            $context,
            $request,
            CheckoutMutation::FinalizationStarted,
            function ($state, array $requiredSections, CheckoutServerSelections $currentSelections) use ($context, $request, $translate): CheckoutMutationOutcome {
                $attemptId = $this->attemptId($request['submissionAttempt'] ?? null);
                $action = $this->action($request['finalizationAction'] ?? null);
                if ($attemptId === null || $action === null) {
                    return CheckoutMutationOutcome::failure(
                        $currentSelections,
                        [new CheckoutError(
                            'submission_attempt_invalid',
                            $translate('The order submission request is invalid. Please try again.'),
                        )],
                    );
                }

                if ($action === self::ACTION_RELEASE) {
                    // Recovery is attempt-scoped and still runs behind the normal CSRF/cart/customer/
                    // stale-state guard. A random/foreign attempt cannot clear another reservation.
                    try {
                        $this->reservationStore->releaseAttempt($context, $attemptId);
                    } catch (CheckoutFinalizationReservationUnavailable) {
                        // An uncertain release must never be presented as success: the reservation
                        // may still be the only barrier preventing a second native payment handoff.
                        return $this->reservationUnavailable($currentSelections, $translate);
                    }

                    return CheckoutMutationOutcome::success($currentSelections, []);
                }

                // Zero-total carts have no merchant payment choice. Core itself presents one synthetic
                // `free_order` option which ultimately reaches OrderConfirmationController::checkFreeOrder()
                // and PaymentFree. Normalize only that exact fresh Core-presented shape so preflight and
                // reservation logic remain identical to paid orders without this module creating an order.
                $finalizationSelections = $this->withCoreFreeOrderSelection($context, $currentSelections);

                try {
                    $this->preflightService->validate($context, $finalizationSelections);
                } catch (CheckoutFinalizationPreflightException $exception) {
                    return CheckoutMutationOutcome::failure(
                        $currentSelections,
                        [$this->errorForReason($exception->reason, $translate)],
                        $this->rendererRegistry->render(
                            $context,
                            $this->sectionsForReason($exception->reason, $context),
                            $currentSelections,
                        ),
                    );
                }

                $paymentSelection = $finalizationSelections->selectedPaymentOption;
                if (!is_string($paymentSelection) || $paymentSelection === '') {
                    return CheckoutMutationOutcome::failure(
                        $currentSelections,
                        [new CheckoutError(
                            'payment_invalid',
                            $translate('Please choose a valid payment method before placing the order.'),
                        )],
                    );
                }

                try {
                    $this->reservationStore->acquire(
                        $context,
                        $this->stateVersioner->version($state),
                        $paymentSelection,
                        $attemptId,
                    );
                } catch (CheckoutFinalizationReservationAlreadyActive) {
                    return CheckoutMutationOutcome::failure(
                        $currentSelections,
                        [new CheckoutError(
                            'finalization_in_progress',
                            $translate('Order submission is already in progress for this cart. Please wait.'),
                        )],
                    );
                } catch (CheckoutFinalizationReservationUnavailable) {
                    return $this->reservationUnavailable($currentSelections, $translate);
                }

                return CheckoutMutationOutcome::success($finalizationSelections, []);
            },
        );
    }

    private function withCoreFreeOrderSelection(
        \Context $context,
        CheckoutServerSelections $currentSelections,
    ): CheckoutServerSelections {
        $cart = $context->cart ?? null;
        if (!$cart instanceof \Cart
            || (int) ($cart->id ?? 0) <= 0
            || 0.0 !== (float) $cart->getOrderTotal(true, \Cart::BOTH)) {
            return $currentSelections;
        }

        if (!class_exists(\PaymentOptionsFinder::class)) {
            return $currentSelections;
        }

        try {
            $presented = (new \PaymentOptionsFinder())->present(true);
        } catch (\Throwable) {
            return $currentSelections;
        }

        $moduleOptions = is_array($presented) ? ($presented['free_order'] ?? null) : null;
        if (!is_array($moduleOptions) || count($moduleOptions) !== 1) {
            return $currentSelections;
        }

        $option = $moduleOptions[0] ?? null;
        if (!is_array($option)
            || !isset($option['id'])
            || !is_string($option['id'])
            || $option['id'] === ''
            || (($option['module_name'] ?? null) !== 'free_order')) {
            return $currentSelections;
        }

        return new CheckoutServerSelections(
            'free_order:' . $option['id'],
            $currentSelections->approvedAgreementKeys,
        );
    }

    private function attemptId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return preg_match('/\A[a-f0-9]{32}\z/D', $value) === 1 ? $value : null;
    }

    private function action(mixed $value): ?string
    {
        return $value === self::ACTION_BEGIN || $value === self::ACTION_RELEASE ? $value : null;
    }

    /**
     * @param Closure(string):string $translate
     */
    private function reservationUnavailable(
        CheckoutServerSelections $currentSelections,
        Closure $translate,
    ): CheckoutMutationOutcome {
        return CheckoutMutationOutcome::failure(
            $currentSelections,
            [new CheckoutError(
                'finalization_unavailable',
                $translate('Order submission safety could not be verified. Please wait and try again.'),
            )],
        );
    }

    /** @param Closure(string):string $translate */
    private function errorForReason(CheckoutFinalizationPreflightReason $reason, Closure $translate): CheckoutError
    {
        [$code, $message] = match ($reason) {
            CheckoutFinalizationPreflightReason::CartEmpty => [
                'cart_empty',
                'Your cart is empty. Please add a product before placing the order.',
            ],
            CheckoutFinalizationPreflightReason::CartNotOrderable => [
                'cart_not_orderable',
                'Your cart changed and can no longer be ordered as shown. Please review it before continuing.',
            ],
            CheckoutFinalizationPreflightReason::MinimumPurchaseRequired => [
                'minimum_purchase_required',
                'The cart does not currently meet the minimum purchase requirement.',
            ],
            CheckoutFinalizationPreflightReason::CustomerRequired => [
                'customer_required',
                'Please complete your customer information before placing the order.',
            ],
            CheckoutFinalizationPreflightReason::AddressInvalid => [
                'address_invalid',
                'Please review your delivery and invoice addresses before placing the order.',
            ],
            CheckoutFinalizationPreflightReason::CarrierInvalid => [
                'carrier_invalid',
                'The selected delivery method is no longer available. Please choose another delivery method.',
            ],
            CheckoutFinalizationPreflightReason::PaymentInvalid => [
                'payment_invalid',
                'The selected payment method is no longer available. Please choose another payment method.',
            ],
            CheckoutFinalizationPreflightReason::AgreementsInvalid => [
                'agreements_invalid',
                'Please review and accept all currently required checkout conditions.',
            ],
            CheckoutFinalizationPreflightReason::OrderAlreadyExists => [
                'order_already_exists',
                'An order has already been placed from this cart.',
            ],
        };

        return new CheckoutError($code, $translate($message));
    }

    /** @return list<CheckoutSection> */
    private function sectionsForReason(CheckoutFinalizationPreflightReason $reason, \Context $context): array
    {
        $sections = match ($reason) {
            CheckoutFinalizationPreflightReason::CartEmpty,
            CheckoutFinalizationPreflightReason::CartNotOrderable,
            CheckoutFinalizationPreflightReason::MinimumPurchaseRequired => [CheckoutSection::Summary],

            CheckoutFinalizationPreflightReason::CustomerRequired => [CheckoutSection::Identity],
            CheckoutFinalizationPreflightReason::AddressInvalid => [CheckoutSection::Addresses],

            CheckoutFinalizationPreflightReason::CarrierInvalid => [
                CheckoutSection::Delivery,
                CheckoutSection::Payment,
                CheckoutSection::Agreements,
                CheckoutSection::Summary,
            ],

            CheckoutFinalizationPreflightReason::PaymentInvalid => [
                CheckoutSection::Payment,
                CheckoutSection::Agreements,
                CheckoutSection::Summary,
            ],

            CheckoutFinalizationPreflightReason::AgreementsInvalid => [CheckoutSection::Agreements],
            CheckoutFinalizationPreflightReason::OrderAlreadyExists => [],
        };

        $cart = $context->cart ?? null;
        if (!$cart instanceof \Cart || !$cart->isVirtualCart()) {
            return $sections;
        }

        return array_values(array_filter(
            $sections,
            static fn (CheckoutSection $section): bool => $section !== CheckoutSection::Delivery,
        ));
    }
}
