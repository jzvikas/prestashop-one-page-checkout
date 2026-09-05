<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutFinalizationReservationSchema;

function upgrade_module_0_4_0(Module $module): bool
{
    return (new CheckoutFinalizationReservationSchema())->install();
}
