<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__, 2) . '/src/Integration/PrestaShopRuntimeProbe.php');

assert(is_string($source) && $source !== '');
assert(str_contains($source, "class_exists('Hook')"));
assert(str_contains($source, "class_exists('Module')"));
assert(!str_contains($source, "class_exists('Hook', false)"));
assert(!str_contains($source, "class_exists('Module', false)"));
assert(str_contains($source, '\\Hook::getIdByName($hookName)'));

fwrite(STDOUT, "PrestaShopRuntimeProbeAutoloadSmokeTest OK\n");
