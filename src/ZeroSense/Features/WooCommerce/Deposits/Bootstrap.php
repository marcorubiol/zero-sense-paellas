<?php
namespace ZeroSense\Features\WooCommerce\Deposits;

use ZeroSense\Core\MetaFieldRegistry;
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
use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys;

/**
 * Bootstrapper for the Deposits module.
 *
 * Wires all subsystems: meta field registration, checkout handlers,
 * payment integrations, admin UI, persistence, and status sync.
 */
class Bootstrap
{
    public function boot(): void
    {
        $this->registerMetaFields();
        
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

        // Legacy S2S aliases: if Redsys back-office still points to the old official plugin URLs,
        // proxy those callbacks to our deposit-aware handlers. Priority 5 = before standalone gateways (10).
        add_action('woocommerce_api_wc_redsys_bizum', [$this, 'legacyBizumCallback'], 5);
        add_action('woocommerce_api_wc_redsys', [$this, 'legacyRedsysCallback'], 5);
    }

    public function legacyBizumCallback(): void
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        $gw = $gateways[BizumGateway::GATEWAY_ID] ?? null;
        if ($gw instanceof BizumGateway) {
            $gw->handleCallback();
        }
    }

    public function legacyRedsysCallback(): void
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        $gw = $gateways[RedsysGateway::GATEWAY_ID] ?? null;
        if ($gw && method_exists($gw, 'handleCallback')) {
            $gw->handleCallback();
        }
    }

    private function registerMetaFields(): void
    {
        $registry = MetaFieldRegistry::getInstance();

        $registry->register(MetaKeys::HAS_DEPOSIT, [
            'label' => 'Has Deposit',
            'type' => 'bool',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'Deposits',
        ]);

        $registry->register(MetaKeys::DEPOSIT_AMOUNT, [
            'label' => 'Deposit Amount',
            'type' => 'number',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'Deposits',
        ]);

        $registry->register(MetaKeys::DEPOSIT_PERCENTAGE, [
            'label' => 'Deposit Percentage',
            'type' => 'number',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'Deposits',
        ]);

        $registry->register(MetaKeys::REMAINING_AMOUNT, [
            'label' => 'Remaining Amount',
            'type' => 'number',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'Deposits',
        ]);

        $registry->register(MetaKeys::BALANCE_AMOUNT, [
            'label' => 'Balance Amount',
            'type' => 'number',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'Deposits',
        ]);

        $registry->register(MetaKeys::IS_MANUAL_OVERRIDE, [
            'label' => 'Is Manual Override',
            'type' => 'bool',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'Deposits',
        ]);

        $registry->register(MetaKeys::IS_DEPOSIT_PAID, [
            'label' => 'Is Deposit Paid',
            'type' => 'bool',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'Deposits',
        ]);

        $registry->register(MetaKeys::FIRST_PAYMENT_DATE, [
            'label' => 'First Payment Date',
            'type' => 'date',
            'translatable' => false,
            'legacy_keys' => ['zs_deposits_deposit_payment_date'],
            'feature' => 'Deposits',
        ]);

        $registry->register(MetaKeys::IS_BALANCE_PAID, [
            'label' => 'Is Balance Paid',
            'type' => 'bool',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'Deposits',
        ]);

        $registry->register(MetaKeys::SECOND_PAYMENT_DATE, [
            'label' => 'Second Payment Date',
            'type' => 'date',
            'translatable' => false,
            'legacy_keys' => ['zs_deposits_balance_payment_date'],
            'feature' => 'Deposits',
        ]);

        $registry->register(MetaKeys::PAYMENT_FLOW, [
            'label' => 'Payment Flow',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'Deposits',
        ]);

        $registry->register(MetaKeys::IS_CANCELLED, [
            'label' => 'Is Cancelled',
            'type' => 'bool',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'Deposits',
        ]);

        $registry->register(MetaKeys::CANCELLED_DATE, [
            'label' => 'Cancelled Date',
            'type' => 'date',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'Deposits',
        ]);

        $registry->register(MetaKeys::IS_FAILED, [
            'label' => 'Is Failed',
            'type' => 'bool',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'Deposits',
        ]);

        $registry->register(MetaKeys::FAILED_CODE, [
            'label' => 'Failed Code',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'Deposits',
        ]);

        $registry->register(MetaKeys::FAILED_DATE, [
            'label' => 'Failed Date',
            'type' => 'date',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'Deposits',
        ]);
    }

    public function registerGateways(array $gateways): array
    {
        $gateways[] = RedsysGateway::class;
        $gateways[] = BizumGateway::class;
        return $gateways;
    }
}

