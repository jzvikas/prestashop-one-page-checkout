<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutServerSelectionsSchema;

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_2_0(Module $module): bool
{
    if (!class_exists(CheckoutServerSelectionsSchema::class)) {
        return false;
    }

    return (new CheckoutServerSelectionsSchema())->install();
}
