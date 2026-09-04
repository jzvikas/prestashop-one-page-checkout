<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

final readonly class CheckoutHookPlan
{
    private const PROVIDER_HOOK = 'actionCheckoutBuildProcess';
    private const LEGACY_RENDER_HOOK = 'actionCheckoutRender';

    /** @param list<string> $hooks */
    private function __construct(public array $hooks)
    {
    }

    public static function forPrestaShopVersion(string $version): self
    {
        if (version_compare($version, '10.0.0', '>=')) {
            return new self([]);
        }

        if (version_compare($version, '9.2.0', '>=')) {
            return new self([self::PROVIDER_HOOK]);
        }

        if (version_compare($version, '9.0.0', '>=')) {
            return new self([self::LEGACY_RENDER_HOOK]);
        }

        return new self([]);
    }
}
