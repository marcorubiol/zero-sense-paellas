<?php
namespace ZeroSense\Features\WooCommerce\Deposits;

use ZeroSense\Features\WooCommerce\Deposits\Components\Checkout;
use ZeroSense\Features\WooCommerce\Deposits\Components\EndpointSupport;
use ZeroSense\Features\WooCommerce\Deposits\Components\OrderTotals;
use ZeroSense\Features\WooCommerce\Deposits\Components\PaymentNotice;
use ZeroSense\Features\WooCommerce\Deposits\Components\PaymentOptions;
use ZeroSense\Features\WooCommerce\Deposits\Components\DirectPaymentHandler;
use ZeroSense\Features\WooCommerce\Deposits\Components\BricksCompatibility;
use ZeroSense\Features\WooCommerce\Deposits\Components\AdminOrder;
use ZeroSense\Features\WooCommerce\Deposits\Components\DepositsLogMetabox;
use ZeroSense\Features\WooCommerce\Deposits\Components\DepositsCalculatorMetabox;
use ZeroSense\Features\WooCommerce\Deposits\Components\Persistence;
use ZeroSense\Features\WooCommerce\Deposits\Components\StatusSync;
use ZeroSense\Features\WooCommerce\Deposits\Integrations\Redsys\Gateway as RedsysGateway;
use ZeroSense\Features\WooCommerce\Deposits\Integrations\Redsys\BizumGateway;
use ZeroSense\Features\WooCommerce\Deposits\Integrations\Redsys\ReturnHandler;
use ZeroSense\Features\WooCommerce\Deposits\Integrations\OfflinePaymentHandler;

/**
 * Temporary bootstrapper for the Deposits module.
 *
 * This placeholder keeps the feature from triggering fatal errors while the
 * legacy logic is migrated. Each subsystem (utils, checkout handlers, payment
 * integrations, etc.) will be wired here in upcoming commits.
 */
class Bootstrap
{
    public function boot(): void
    {
        (new OrderTotals())->register();
        (new PaymentNotice())->register();
        (new Checkout())->register();
        (new PaymentOptions())->register();
        (new EndpointSupport())->register();
        (new DirectPaymentHandler())->register();
        (new ReturnHandler())->register();
        (new OfflinePaymentHandler())->register();
        (new BricksCompatibility())->register();
        (new AdminOrder())->register();
        (new DepositsLogMetabox())->register();
        (new DepositsCalculatorMetabox())->register();
        (new Persistence())->register();
        (new StatusSync())->register();

        add_filter('woocommerce_payment_gateways', [$this, 'registerGateways']);
    }

    public function registerGateways(array $gateways): array
    {
        $gateways[] = RedsysGateway::class;
        $gateways[] = BizumGateway::class;
        return $gateways;
    }
}

