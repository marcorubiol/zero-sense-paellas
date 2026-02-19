<?php
namespace ZeroSense\Features\WooCommerce;

use ZeroSense\Core\FeatureInterface;

if (!defined('ABSPATH')) { exit; }

/**
 * WooCommerce · Cart Timeout (Tab Detection)
 * Clears cart after all tabs have been closed for a specified time.
 *
 * Legacy compatibility preserved:
 * - Toggle option: zs_tab_detection_enabled (boolean)
 * - Timeout (minutes): zs_cart_timeout (int)
 * - AJAX actions: clear_cart_after_timeout (legacy) and zs_clear_cart_after_timeout (new)
 */
class CartTimeout implements FeatureInterface
{
    public function getName(): string
    {
        return __('Cart Timeout', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Clears the WooCommerce cart after all browser tabs are closed for a configurable time period.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isToggleable(): bool
    {
        return true;
    }

    // Use legacy option name for seamless migration
    public function getOptionName(): string
    {
        return 'zs_tab_detection_enabled';
    }

    public function isEnabled(): bool
    {
        // Default true as per legacy behavior
        return (bool) get_option($this->getOptionName(), true);
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function getConditions(): array
    {
        // Only load when WooCommerce exists; runtime checks will guard frontend context
        return ['class_exists:WooCommerce'];
    }

    public function hasConfiguration(): bool
    {
        return true;
    }

    public function getConfigurationFields(): array
    {
        $minutes = (int) get_option('zs_cart_timeout', 5);
        if ($minutes <= 0) { $minutes = 5; }

        return [
            [
                'name' => 'zs_cart_timeout',
                'label' => __('Cart Timeout (minutes)', 'zero-sense'),
                'type' => 'number',
                'description' => __('How many minutes after all tabs close before clearing the cart.', 'zero-sense'),
                'placeholder' => '5',
                'min' => 1,
                'step' => 1,
                'value' => $minutes,
            ],
        ];
    }

    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // Frontend only, on WooCommerce-related pages
        add_action('wp_enqueue_scripts', [$this, 'enqueueScript'], 20);

        // AJAX handlers (new + legacy)
        add_action('wp_ajax_zs_clear_cart_after_timeout', [$this, 'handleAjaxClearCart']);
        add_action('wp_ajax_nopriv_zs_clear_cart_after_timeout', [$this, 'handleAjaxClearCart']);
        // Legacy action name
        add_action('wp_ajax_clear_cart_after_timeout', [$this, 'handleAjaxClearCart']);
        add_action('wp_ajax_nopriv_clear_cart_after_timeout', [$this, 'handleAjaxClearCart']);
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
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/WooCommerce/CartTimeout.php', 'zero-sense'),
                    __('printInlineScript() → tab heartbeat + last-close tracking', 'zero-sense'),
                    __('handleAjaxClearCart() → validates nonce(s) and empties cart', 'zero-sense'),
                    __('getConfigurationFields() → option zs_cart_timeout (minutes)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('wp_footer (inject inline script on Woo pages)', 'zero-sense'),
                    __('wp_ajax_zs_clear_cart_after_timeout / nopriv (new AJAX)', 'zero-sense'),
                    __('wp_ajax_clear_cart_after_timeout / nopriv (legacy AJAX)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Set timeout in dashboard, open shop/cart/checkout, then close all tabs; after timeout, re-open site → cart should be empty.', 'zero-sense'),
                    __('Use browser DevTools → Application → Local Storage to observe wc_active_tabs and wc_last_tab_closed_time.', 'zero-sense'),
                    __('Verify AJAX request to zs_clear_cart_after_timeout returns success and cart empties without notices.', 'zero-sense'),
                ],
            ],
        ];
    }

    private function isWooPage(): bool
    {
        // Guard for environments where conditional tags may not be loaded
        $shop     = function_exists('is_shop') ? is_shop() : false;
        $product  = function_exists('is_product') ? is_product() : false;
        $cart     = function_exists('is_cart') ? is_cart() : false;
        $checkout = function_exists('is_checkout') ? is_checkout() : false;
        return $shop || $product || $cart || $checkout;
    }

    public function enqueueScript(): void
    {
        if (is_admin() || !$this->isWooPage()) {
            return;
        }

        $timeoutMinutes = (int) get_option('zs_cart_timeout', 5);
        if ($timeoutMinutes <= 0) { $timeoutMinutes = 5; }

        $jsRel = 'assets/js/cart-timeout.js';
        $jsPath = plugin_dir_path(ZERO_SENSE_FILE) . $jsRel;
        $jsUrl  = plugin_dir_url(ZERO_SENSE_FILE) . $jsRel;
        $ver    = file_exists($jsPath) ? (string) filemtime($jsPath) : ZERO_SENSE_VERSION;

        wp_enqueue_script('zs-cart-timeout', $jsUrl, [], $ver, true);
        wp_localize_script('zs-cart-timeout', 'zsCartTimeout', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('zs_clear_cart_nonce'),
            'timeout'    => $timeoutMinutes * 60,
            'cookieName' => 'zs_cart_last_seen',
        ]);
    }

    public function handleAjaxClearCart(): void
    {
        // Accept both nonces: new and legacy (for safety during migration)
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'zs_clear_cart_nonce') && !wp_verify_nonce($nonce, 'clear_cart_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        if (!$this->isEnabled()) {
            wp_send_json_error(['message' => 'Feature disabled']);
        }

        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
            wp_send_json_success();
        }

        wp_send_json_error(['message' => 'No cart']);
    }
}
