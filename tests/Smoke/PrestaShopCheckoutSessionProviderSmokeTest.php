<?php

declare(strict_types=1);

$source = (string) file_get_contents(
    dirname(__DIR__, 2) . '/src/Checkout/Rendering/PrestaShopCheckoutSessionProvider.php'
);

function assertSessionProviderContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertSessionProviderContract(
    str_contains($source, "method_exists(\$controller, 'getCheckoutSession')"),
    'active OrderController-compatible sessions must still be reused',
);
assertSessionProviderContract(
    str_contains($source, 'new \\CheckoutSession('),
    'module front controllers must have a Core CheckoutSession construction fallback',
);
assertSessionProviderContract(
    str_contains($source, 'new \\DeliveryOptionsFinder('),
    'PrestaShop 9.0-compatible DeliveryOptionsFinder fallback must remain available',
);
assertSessionProviderContract(
    str_contains($source, "'PrestaShop\\\\PrestaShop\\\\Adapter\\\\Shipment\\\\DeliveryOptionsProvider'"),
    'improved shipment provider must be referenced dynamically for 9.0 compatibility',
);
assertSessionProviderContract(
    str_contains($source, 'class_exists($providerClass)') && str_contains($source, 'defined($flagConstant)'),
    '9.1+ improved shipment path must be capability guarded',
);
assertSessionProviderContract(
    str_contains($source, 'new $providerClass('),
    'guarded improved shipment provider must be constructed dynamically',
);
assertSessionProviderContract(
    !str_contains($source, 'new DeliveryOptionsProvider('),
    '9.1+ DeliveryOptionsProvider must never be instantiated through an unguarded hard reference',
);
assertSessionProviderContract(
    str_contains($source, 'FeatureFlagStateCheckerInterface'),
    'Core feature flag state must decide the improved shipment branch',
);

echo "PrestaShopCheckoutSessionProviderSmokeTest OK\n";
