<?php
namespace ZeroSense\Features\WooCommerce\Deposits;

use ZeroSense\Core\FeatureInterface;

/**
 * WooCommerce Deposits (toggleable).
 *
 * This feature orchestrates all deposit-related enhancements including checkout
 * UI, order metadata, payment gateway helpers and thank you page flows. Legacy
 * implementation lived under `includes/features/woocommerce/deposits/*`.
 */
class WooCommerceDeposits implements FeatureInterface
{
    /**
     * Option name used to toggle the feature from the dashboard.
     */
    private const OPTION_NAME = 'zs_woo_deposits_enabled';

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return __('WooCommerce Deposits', 'zero-sense');
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return __('Comprehensive deposit payment system for WooCommerce with checkout enhancements, payment gateway integrations, and automated workflows.', 'zero-sense');
    }

    /**
     * {@inheritdoc}
     */
    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    /**
     * {@inheritdoc}
     */
    public function isToggleable(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        return (bool) get_option($this->getOptionName(), true);
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority(): int
    {
        return 25;
    }

    /**
     * {@inheritdoc}
     */
    public function getConditions(): array
    {
        return ['defined:WC_VERSION'];
    }

    /**
     * {@inheritdoc}
     */
    public function init(): void
    {
        // Ensure the toggle option exists with a sensible default.
        add_action('admin_init', static function (): void {
            if (get_option(self::OPTION_NAME, null) === null) {
                add_option(self::OPTION_NAME, true);
            }
        });

        // Settings must be available even when the feature is disabled.
        if (is_admin()) {
            Settings::bootstrap();
        }

        // Delay bootstrapping until WooCommerce (and gateways) are loaded.
        add_action('plugins_loaded', function (): void {
            if (!$this->isEnabled()) {
                return;
            }

            (new Bootstrap())->boot();

        }, 20);
    }

    /**
     * Instance method for feature interface consistency
     */
    public function getOptionName(): string
    {
        return self::OPTION_NAME;
    }

    /**
     * Static convenience accessor for other components.
     */
    public static function getOptionNameStatic(): string
    {
        return self::OPTION_NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function hasConfiguration(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigurationFields(): array
    {
        return [
            [
                'name' => Settings::OPTION_DEPOSIT_PERCENTAGE,
                'label' => __('Deposit Percentage', 'zero-sense'),
                'type' => 'text',
                'description' => __('Enter the deposit percentage to charge (1-100).', 'zero-sense'),
                'placeholder' => '30',
                'value' => Settings::getDepositPercentage(),
            ],
        ];
    }

    /**
     * Check if feature has information
     */
    public function hasInformation(): bool
    {
        return true;
    }

    /**
     * Get information blocks for dashboard
     */
    public function getInformationBlocks(): array
    {
        return [
            [
                'type' => 'list',
                'title' => __('Key features', 'zero-sense'),
                'items' => [
                    __('Configurable deposit percentage (1-100%)', 'zero-sense'),
                    __('Checkout UI enhancements for deposit selection', 'zero-sense'),
                    __('Redsys and Bizum payment gateway integration', 'zero-sense'),
                    __('Automated order status transitions', 'zero-sense'),
                    __('Custom thank you page flows', 'zero-sense'),
                    __('Second payment handling and notifications', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Payment workflow', 'zero-sense'),
                'items' => [
                    __('Customer selects deposit option at checkout', 'zero-sense'),
                    __('First payment: deposit amount (configurable %)', 'zero-sense'),
                    __('Order status: "Deposit Paid"', 'zero-sense'),
                    __('Second payment: remaining balance', 'zero-sense'),
                    __('Order status: "Fully Paid" → "Completed"', 'zero-sense'),
                ],
            ],
            [
                'type' => 'text',
                'title' => __('Integration', 'zero-sense'),
                'content' => __('Works with custom order statuses, WPML multilingual support, and integrates with existing payment gateways. Includes comprehensive settings panel for configuration.', 'zero-sense'),
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/WooCommerce/Deposits/WooCommerceDeposits.php', 'zero-sense'),
                    __('Settings::bootstrap() / OPTION_DEPOSIT_PERCENTAGE', 'zero-sense'),
                    __('Bootstrap()->boot() → wires checkout UI, status changes, flows (see Deposits sub-namespace)', 'zero-sense'),
                    __('Toggle option: ' . WooCommerceDeposits::getOptionNameStatic(), 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('plugins_loaded (delayed boot after WooCommerce)', 'zero-sense'),
                    __('Admin settings registered via Settings::bootstrap()', 'zero-sense'),
                    __('Integration with gateways (Redsys/Bizum) via Deposits integrations', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Set deposit percentage in dashboard and place a checkout selecting deposit.', 'zero-sense'),
                    __('Confirm first payment marks order as Deposit Paid and thank-you flow.', 'zero-sense'),
                    __('Trigger second payment from Order Pay URL → order to Fully Paid and then Completed.', 'zero-sense'),
                ],
            ],
        ];
    }
}
