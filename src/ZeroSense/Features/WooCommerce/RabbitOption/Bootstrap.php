<?php
namespace ZeroSense\Features\WooCommerce\RabbitOption;

use ZeroSense\Features\WooCommerce\RabbitOption\Components\ProductAdmin;
use ZeroSense\Features\WooCommerce\RabbitOption\Components\ProductDisplay;
use ZeroSense\Features\WooCommerce\RabbitOption\Components\CartIntegration;
use ZeroSense\Features\WooCommerce\RabbitOption\Components\OrderAdmin;

class Bootstrap
{
    public function boot(): void
    {
        (new ProductAdmin())->register();
        (new ProductDisplay())->register();
        (new CartIntegration())->register();
        (new OrderAdmin())->register();
    }
}
