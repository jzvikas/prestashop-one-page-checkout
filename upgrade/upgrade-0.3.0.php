<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_3_0(Module $module): bool
{
    if ($module->isRegisteredInHook('actionFrontControllerSetMedia')) {
        return true;
    }

    return $module->registerHook('actionFrontControllerSetMedia');
}
