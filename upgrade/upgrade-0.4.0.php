<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutFinalizationReservationSchema;

function upgrade_module_0_4_0(Module $module): bool
{
    if (!(new CheckoutFinalizationReservationSchema())->install()) {
        return false;
    }

    if ($module->isRegisteredInHook('actionValidateOrderAfter')) {
        return true;
    }

    return $module->registerHook('actionValidateOrderAfter');
}
