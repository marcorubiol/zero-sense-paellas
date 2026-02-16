<?php
namespace ZeroSense\Features\WooCommerce\Admin;

use ZeroSense\Core\FeatureInterface;

class AdminSectionTitles implements FeatureInterface
{
    public function getName(): string
    {
        return 'Admin Section Titles';
    }

    public function getDescription(): string
    {
        return 'Changes "Billing" to "Client" and "Shipping" to "Venue/Wedding Planner" in WooCommerce admin with WPML support.';
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isToggleable(): bool
    {
        return false; // Always-on feature
    }

    public function isEnabled(): bool
    {
        return true; // Always enabled
    }

    public function init(): void
    {
        // Backend order page section titles
        add_filter('woocommerce_admin_billing_fields', [$this, 'modifyBillingSectionTitle'], 10, 3);
        add_filter('woocommerce_admin_shipping_fields', [$this, 'modifyShippingSectionTitle'], 10, 3);
        
        // Order data tabs
        add_filter('woocommerce_admin_order_data_tabs', [$this, 'modifyOrderDataTabs'], 10, 1);
        
        // Register strings with WPML
        add_action('init', [$this, 'registerWpmlStrings'], 20);
        
        // Admin CSS for additional styling if needed
        add_action('admin_head', [$this, 'addAdminStyles']);
    }

    public function getPriority(): int
    {
        return 15; // High priority to ensure our changes apply first
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce', 'is_admin'];
    }

    /**
     * Modify billing section title in order admin
     */
    public function modifyBillingSectionTitle($fields, $order, $context): array
    {
        // We don't modify the fields themselves, just the section title
        // This is handled by the woocommerce_admin_order_data_tabs filter
        return $fields;
    }

    /**
     * Modify shipping section title in order admin
     */
    public function modifyShippingSectionTitle($fields, $order, $context): array
    {
        // We don't modify the fields themselves, just the section title
        // This is handled by the woocommerce_admin_order_data_tabs filter
        return $fields;
    }

    /**
     * Modify order data tabs to change section titles
     */
    public function modifyOrderDataTabs($tabs): array
    {
        // Change Billing to Client
        if (isset($tabs['billing'])) {
            $tabs['billing']['label'] = $this->getTranslatedString('Client', 'billing_section_title', 'Client');
        }
        
        // Change Shipping to Venue/Wedding Planner
        if (isset($tabs['shipping'])) {
            $tabs['shipping']['label'] = $this->getTranslatedString('Venue/Wedding Planner', 'shipping_section_title', 'Venue/Wedding Planner');
        }
        
        return $tabs;
    }

    /**
     * Register strings with WPML for translation
     */
    public function registerWpmlStrings(): void
    {
        if (!function_exists('wpml_register_string')) {
            return;
        }

        // Register billing section title
        wpml_register_string(
            'zero-sense',
            'billing_section_title',
            'Client',
            [
                'name' => 'Billing Section Title',
                'context' => 'WooCommerce Admin Order Page'
            ]
        );

        // Register shipping section title
        wpml_register_string(
            'zero-sense',
            'shipping_section_title',
            'Venue/Wedding Planner',
            [
                'name' => 'Shipping Section Title',
                'context' => 'WooCommerce Admin Order Page'
            ]
        );
    }

    /**
     * Get translated string using WPML or fallback
     */
    private function getTranslatedString(string $default, string $key, string $name): string
    {
        if (function_exists('wpml_translate_string')) {
            return wpml_translate_string('zero-sense', $key, $default);
        }
        
        // Fallback translations for common languages
        $current_lang = function_exists('get_current_language') ? get_current_language() : get_locale();
        
        $translations = [
            'billing_section_title' => [
                'en' => 'Client',
                'es' => 'Cliente',
                'ca' => 'Client',
                'default' => 'Client'
            ],
            'shipping_section_title' => [
                'en' => 'Venue/Wedding Planner',
                'es' => 'Recinto/Organizador de Bodas',
                'ca' => 'Recinte/Organitzador de Bodas',
                'default' => 'Venue/Wedding Planner'
            ]
        ];

        $lang_key = substr($current_lang, 0, 2);
        
        if (isset($translations[$key][$lang_key])) {
            return $translations[$key][$lang_key];
        }
        
        return $translations[$key]['default'] ?? $default;
    }

    /**
     * Add custom admin styles if needed
     */
    public function addAdminStyles(): void
    {
        $screen = get_current_screen();
        
        // Only load on order pages
        if (!$screen || $screen->id !== 'shop_order') {
            return;
        }
        ?>
        <style>
            /* Ensure proper display of modified section titles */
            .woocommerce-order-data-tabs .tabs li a {
                font-weight: 600;
            }
            
            /* Optional: Add icons to distinguish sections */
            .woocommerce-order-data-tabs .tabs li.billing_tab a:before {
                content: "👤 ";
                margin-right: 5px;
            }
            
            .woocommerce-order-data-tabs .tabs li.shipping_tab a:before {
                content: "📍 ";
                margin-right: 5px;
            }
        </style>
        <?php
    }
}
