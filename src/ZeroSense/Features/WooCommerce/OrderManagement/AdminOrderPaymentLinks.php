<?php
namespace ZeroSense\Features\WooCommerce\OrderManagement;

use ZeroSense\Core\FeatureInterface;

if (!defined('ABSPATH')) {
    exit;
}

class AdminOrderPaymentLinks implements FeatureInterface
{
    public function getName(): string
    {
        return __('Admin: Customer payment link in order view', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Adds a single "Customer payment page" link in the admin order screen for pending, deposit-paid and fully-paid orders. Appends the admin language (WPML/user locale) to the URL.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isToggleable(): bool
    {
        return true;
    }

    // Optional explicit option name used by the dashboard when present
    public function getOptionName(): string
    {
        return 'zs_admin_order_payment_links_enabled';
    }

    public function isEnabled(): bool
    {
        // Boolean cast per project convention
        return (bool) get_option($this->getOptionName(), true);
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getConditions(): array
    {
        return ['is_admin', 'class_exists:WooCommerce'];
    }

    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // Server-side rendering in admin order view details panel (no JS injections)
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'renderPaymentLink']);

        // Ensure the native checkout payment URL carries the admin language when used in admin
        add_filter('woocommerce_get_checkout_payment_url', [$this, 'appendAdminLangToPaymentUrl'], 10, 2);
    }

    /**
     * List of statuses where the payment link should be visible in admin.
     * Default: pending, deposit-paid, fully-paid. Filterable.
     */
    private function getValidStatuses(): array
    {
        $defaults = ['pending', 'deposit-paid', 'fully-paid'];
        /**
         * Filter the list of statuses for which the admin payment link is shown.
         *
         * @param string[] $statuses
         */
        $statuses = apply_filters('zero_sense_admin_order_payment_link_statuses', $defaults);
        if (!is_array($statuses)) {
            return $defaults;
        }
        // Normalize values
        $statuses = array_values(array_filter(array_map('strval', $statuses)));
        return !empty($statuses) ? $statuses : $defaults;
    }

    /**
     * Output a small script that hides WooCommerce's native link (for pending)
     * and inserts our unified link for the target statuses.
     */
    public function renderPaymentLink($order): void
    {
        if (!is_object($order) || !method_exists($order, 'get_status')) {
            return;
        }

        $status = $order->get_status();
        // Let WooCommerce show its native link (it appears whenever needs_payment() is true).
        if (method_exists($order, 'needs_payment') && $order->needs_payment()) {
            return; // Native link present; avoid duplicate
        }
        // Only add for extra statuses configured (defaults include deposit-paid, fully-paid)
        $all_statuses   = $this->getValidStatuses();
        $extra_statuses = array_values(array_diff($all_statuses, ['pending']));
        if (!in_array($status, $extra_statuses, true)) {
            return;
        }

        $payment_url = $order->get_checkout_payment_url();
        if (!$payment_url) {
            return;
        }

        // Link label (filterable)
        $label = apply_filters('zero_sense_admin_order_payment_link_label', __('Customer payment page', 'zero-sense'), $order);
        // Output a link similar to the native one in the first admin column
        echo '<p class="form-field"><a class="customer-payment-page" href="' . esc_url($payment_url) . '">' . esc_html($label) . ' &rarr;</a></p>';
    }

    /**
     * Append admin language to the checkout payment URL when generating it in admin.
     */
    public function appendAdminLangToPaymentUrl(string $url, $order): string
    {
        if (!is_admin()) {
            return $url;
        }
        // Determine admin language (WPML first, fallback to user locale)
        $admin_language = '';
        if (function_exists('wpml_get_admin_language')) {
            $admin_language = apply_filters('wpml_current_language', null, ['admin' => true]);
        }
        if (!$admin_language && function_exists('get_user_locale')) {
            $admin_language = str_replace('_', '-', get_user_locale());
        }
        if (!empty($admin_language)) {
            $url = add_query_arg('lang', $admin_language, $url);
        }
        return $url;
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
                    __('Adds a unified "Customer payment page" link in admin order view', 'zero-sense'),
                    __('Shows for statuses: pending, deposit-paid, fully-paid (filterable)', 'zero-sense'),
                    __('Appends admin language to payment URL (WPML/user locale)', 'zero-sense'),
                    __('Avoids duplicating Woo native link when needs_payment() is true', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/WooCommerce/OrderManagement/AdminOrderPaymentLinks.php', 'zero-sense'),
                    __('renderPaymentLink() → outputs the admin link when applicable', 'zero-sense'),
                    __('appendAdminLangToPaymentUrl() → ensures ?lang on URLs generated from admin', 'zero-sense'),
                    __('getValidStatuses() → filterable statuses list', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('woocommerce_admin_order_data_after_order_details (link output)', 'zero-sense'),
                    __('woocommerce_get_checkout_payment_url (append admin language)', 'zero-sense'),
                    __('zero_sense_admin_order_payment_link_statuses (filter statuses)', 'zero-sense'),
                    __('zero_sense_admin_order_payment_link_label (filter label)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Open an order with status deposit-paid or fully-paid: the link should appear (pending may be handled by Woo native link).', 'zero-sense'),
                    __('Click the link and verify the payment page opens with the admin language (?lang=xx).', 'zero-sense'),
                    __('Switch admin language (WPML or user locale) and confirm URL parameter changes.', 'zero-sense'),
                ],
            ],
        ];
    }
}
