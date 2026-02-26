<?php
namespace ZeroSense\Features\Integrations\WPML;

use ZeroSense\Core\FeatureInterface;

class OrderLanguage implements FeatureInterface
{

    public function getName(): string
    {
        return __('WPML Order Language', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Manages language assignment for WooCommerce orders and provides admin interface for updating language-aware payment data.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'Integrations';
    }

    public function isToggleable(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getConditions(): array
    {
        return [];
    }

    public function init(): void
    {
        if (!$this->isWpmlActive()) {
            return;
        }

        add_action('woocommerce_checkout_order_created', [$this, 'assignLanguageToNewOrder'], 10, 1);

        if (is_admin()) {
            (new OrderLanguageAdmin())->register();
        }
    }

    public function assignLanguageToNewOrder(\WC_Order $order): void
    {
        if ($order->get_meta('wpml_language', true) !== '') {
            return;
        }

        $currentLanguage = apply_filters('wpml_current_language', null);
        if (!is_string($currentLanguage) || $currentLanguage === '') {
            return;
        }

        $order->update_meta_data('wpml_language', $currentLanguage);
        $order->save();
    }

    private function isWpmlActive(): bool
    {
        return defined('ICL_SITEPRESS_VERSION') && function_exists('icl_object_id');
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
                    __('Automatic language detection for new orders', 'zero-sense'),
                    __('Admin interface for language management', 'zero-sense'),
                    __('Integration with WPML multilingual system', 'zero-sense'),
                    __('Language-aware payment processing', 'zero-sense'),
                ],
            ],
            [
                'type' => 'text',
                'title' => __('Requirements', 'zero-sense'),
                'content' => __('Requires WPML plugin to be active. Automatically detects WPML availability and only loads when needed.', 'zero-sense'),
            ],
            [
                'type' => 'text',
                'title' => __('Admin features', 'zero-sense'),
                'content' => __('Provides admin interface through OrderLanguageAdmin class for managing order language assignments and payment data updates.', 'zero-sense'),
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/Integrations/WPML/OrderLanguage.php', 'zero-sense'),
                    __('Admin UI: src/ZeroSense/Features/Integrations/WPML/OrderLanguageAdmin.php', 'zero-sense'),
                    __('OrderLanguage::init() → admin-only registration of OrderLanguageAdmin', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('woocommerce_admin_order_data_after_order_details (admin field)', 'zero-sense'),
                    __('manage_edit-shop_order_columns / sortable / custom_column', 'zero-sense'),
                    __('restrict_manage_posts + request (admin list filter)', 'zero-sense'),
                    __('woocommerce_process_shop_order_meta (persist language meta)', 'zero-sense'),
                    __('wp_ajax_zs_wpml_update_order_language (AJAX update)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Open a shop order in admin: check current language tag and change via UI; save.', 'zero-sense'),
                    __('Verify list table shows Language column and is sortable/filterable.', 'zero-sense'),
                    __('Confirm meta key wpml_language is saved and affects language-dependent payment URLs.', 'zero-sense'),
                    __('Ensure WPML active; feature guards itself if WPML is missing.', 'zero-sense'),
                ],
            ],
        ];
    }
}
