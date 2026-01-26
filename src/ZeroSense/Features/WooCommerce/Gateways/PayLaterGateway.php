<?php
namespace ZeroSense\Features\WooCommerce\Gateways;

use ZeroSense\Core\FeatureInterface;
use WC_Payment_Gateway;

class PayLaterGateway implements FeatureInterface
{
    public function getName(): string
    {
        return __('Pay Later Payment Gateway', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Adds a "Pay Later" option to WooCommerce checkout. Configure in WooCommerce > Settings > Payments.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce'];
    }

    public function isToggleable(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function init(): void
    {
        // Register the gateway class
        add_action('plugins_loaded', [$this, 'init_gateway_class']);
        add_filter('woocommerce_payment_gateways', [$this, 'add_to_gateways']);
    }

    public function init_gateway_class(): void
    {
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }

        // Include the gateway class
        require_once __DIR__ . '/WC_Gateway_Pay_Later.php';
    }

    public function add_to_gateways($gateways)
    {
        $gateways[] = 'ZeroSense\Features\WooCommerce\Gateways\WC_Gateway_Pay_Later';
        return $gateways;
    }

    public function hasInformation(): bool
    {
        return true;
    }

    public function getInformationBlocks(): array
    {
        return [
            [
                'type' => 'text',
                'title' => __('Gateway Configuration', 'zero-sense'),
                'content' => __('Configure this gateway in WooCommerce > Settings > Payments > Pay Later.', 'zero-sense'),
            ],
            [
                'type' => 'list',
                'title' => __('Features', 'zero-sense'),
                'items' => [
                    __('Allows customers to place orders without immediate payment.', 'zero-sense'),
                    __('Configurable order status (default: on-hold).', 'zero-sense'),
                    __('Custom instructions for thank you page and emails.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/WooCommerce/Gateways/PayLaterGateway.php', 'zero-sense'),
                    __('Gateway class: src/ZeroSense/Features/WooCommerce/Gateways/WC_Gateway_Pay_Later.php', 'zero-sense'),
                    __('init_gateway_class() → requires gateway file on plugins_loaded', 'zero-sense'),
                    __('add_to_gateways() → registers gateway with WooCommerce', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('plugins_loaded (gateway class include)', 'zero-sense'),
                    __('woocommerce_payment_gateways (register gateway)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Enable Pay Later in WooCommerce → Payments and place a test order.', 'zero-sense'),
                    __('Confirm order status and instructions display as configured.', 'zero-sense'),
                    __('Verify visibility rules with Order Pay and Checkout features if applicable.', 'zero-sense'),
                ],
            ],
        ];
    }
}
