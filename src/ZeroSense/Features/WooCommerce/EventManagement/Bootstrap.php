<?php
namespace ZeroSense\Features\WooCommerce\EventManagement;

use ZeroSense\Features\WooCommerce\EventManagement\Components\EventDetailsMetabox;
use ZeroSense\Features\WooCommerce\EventManagement\Components\DataExposer;

/**
 * Bootstrap for Event Management module
 */
class Bootstrap
{
    public function boot(): void
    {
        (new EventDetailsMetabox())->register();
        (new DataExposer())->register();
    }
}
