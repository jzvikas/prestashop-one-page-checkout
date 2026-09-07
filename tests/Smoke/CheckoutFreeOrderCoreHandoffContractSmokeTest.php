<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mutation = file_get_contents($root . '/src/Checkout/Mutation/CheckoutFinalizationMutation.php');
$renderer = file_get_contents($root . '/src/Checkout/Rendering/PaymentSectionRenderer.php');
$presenter = file_get_contents($root . '/src/Checkout/Rendering/PrestaShopCheckoutPaymentOptionsPresenter.php');
$finalSubmit = file_get_contents($root . '/views/js/final-submit-controller.js');
$template = file_get_contents($root . '/views/templates/front/sections/payment.tpl');

foreach ([$mutation, $renderer, $presenter, $finalSubmit, $template] as $source) {
    if (!is_string($source) || $source === '') {
        fwrite(STDERR, "Unable to read free-order checkout contract source.\n");
        exit(1);
    }
}

$requiredPresenter = [
    '0.0 === (float) $cart->getOrderTotal(true, \\Cart::BOTH)',
    '(new \\PaymentOptionsFinder())->present($isFree)',
    "'isFree' => \$isFree",
];
foreach ($requiredPresenter as $needle) {
    if (!str_contains($presenter, $needle)) {
        fwrite(STDERR, "Core free-order presenter contract is missing: {$needle}\n");
        exit(1);
    }
}

$requiredMutation = [
    '$finalizationSelections = $this->withCoreFreeOrderSelection($context, $currentSelections);',
    '$this->preflightService->validate($context, $finalizationSelections);',
    '$paymentSelection = $finalizationSelections->selectedPaymentOption;',
    '(new \\PaymentOptionsFinder())->present(true)',
    "'free_order:' . \$option['id']",
    'count($moduleOptions) !== 1',
    "((\$option['module_name'] ?? null) !== 'free_order')",
    'CheckoutMutationOutcome::success($finalizationSelections, [])',
];
foreach ($requiredMutation as $needle) {
    if (!str_contains($mutation, $needle)) {
        fwrite(STDERR, "Free-order finalization contract is missing: {$needle}\n");
        exit(1);
    }
}

$requiredRenderer = [
    '$freeOrderStateKey = $this->freeOrderStateKey($variables, $paymentOptions);',
    "(\$variables['isFree'] ?? null) !== true",
    "\$paymentOptions['free_order'] ?? null",
    "return 'free_order:' . \$option['id'];",
];
foreach ($requiredRenderer as $needle) {
    if (!str_contains($renderer, $needle)) {
        fwrite(STDERR, "Free-order rendering contract is missing: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($template, '{if $isFree}')) {
    fwrite(STDERR, "Free-order status rendering is missing.\n");
    exit(1);
}

foreach (['validateOrder(', 'new \\PaymentFree', 'INSERT INTO'] as $needle) {
    if (str_contains($mutation, $needle) || str_contains($finalSubmit, $needle)) {
        fwrite(STDERR, "OPC must not own free-order creation: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($finalSubmit, "this.dispatch('jzopc:checkout:payment-handoff'")) {
    fwrite(STDERR, "Free order must continue through the normal reserved native handoff path.\n");
    exit(1);
}

fwrite(STDOUT, "Free-order Core handoff contract smoke test passed.\n");
