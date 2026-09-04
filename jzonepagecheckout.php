<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

final class JzOnePageCheckout extends Module
{
    public function __construct()
    {
        $this->name = 'jzonepagecheckout';
        $this->tab = 'checkout';
        $this->version = '0.1.0';
        $this->author = 'Justinas Zvikas';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = [
            'min' => '9.0.0',
            'max' => '9.99.99',
        ];

        parent::__construct();

        $this->displayName = $this->trans(
            'One Page Checkout',
            [],
            'Modules.Jzonepagecheckout.Admin'
        );
        $this->description = $this->trans(
            'Fast, safe and compatible one-page checkout for PrestaShop 9.',
            [],
            'Modules.Jzonepagecheckout.Admin'
        );
    }
}
