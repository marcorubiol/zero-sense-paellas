<?php
namespace ZeroSense\Features\WooCommerce\EventManagement;

use ZeroSense\Features\WooCommerce\EventManagement\Components\EventDetailsMetabox;
use ZeroSense\Features\WooCommerce\EventManagement\Components\DataExposer;
use ZeroSense\Features\WooCommerce\EventManagement\Components\ServiceAreaAdminColumns;

/**
 * Bootstrap for Event Management module
 */
class Bootstrap
{
    public function boot(): void
    {
        (new EventDetailsMetabox())->register();
        (new DataExposer())->register();
        (new ServiceAreaAdminColumns())->register();
    }
}
