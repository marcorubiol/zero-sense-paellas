<?php
namespace ZeroSense\Features\WooCommerce\Admin;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Utilities\HposCompatibility;

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
        // Verificar si estamos en HPOS o Classic
        if (HposCompatibility::isHposEnabled()) {
            // Filtros para HPOS
            add_action('admin_enqueue_scripts', [$this, 'addHposStyles'], 10, 1);
            add_filter('woocommerce_order_admin_tabs', [$this, 'modifyHposOrderTabs'], 10, 1);
            add_action('woocommerce_order_admin_header', [$this, 'addHposTabLabels'], 10, 1);
        } else {
            // Filtros para WooCommerce Classic
            add_filter('woocommerce_admin_billing_fields', [$this, 'modifyBillingSectionTitle'], 10, 3);
            add_filter('woocommerce_admin_shipping_fields', [$this, 'modifyShippingSectionTitle'], 10, 3);
            add_filter('woocommerce_admin_order_data_tabs', [$this, 'modifyOrderDataTabs'], 10, 1);
        }
        
        // Register strings with WPML (común para ambos)
        add_action('init', [$this, 'registerWpmlStrings'], 20);
        
        // Admin CSS (común para ambos)
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
     * Modificar tabs para HPOS
     */
    public function modifyHposOrderTabs($tabs): array
    {
        if (isset($tabs['billing'])) {
            $tabs['billing']['label'] = 'Client';
        }
        
        if (isset($tabs['shipping'])) {
            $tabs['shipping']['label'] = 'Venue/Wedding Planner';
        }
        
        return $tabs;
    }

    /**
     * Estilos específicos para HPOS
     */
    public function addHposStyles($hook): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'woocommerce_page_wc-orders') {
            return;
        }

        // Usar valores simples sin WPML para evitar errores
        $clientLabel = 'Client';
        $venueLabel = 'Venue/Wedding Planner';

        // JavaScript para modificar dinámicamente los tabs en HPOS
        wp_add_inline_script('wc-orders', "
            jQuery(document).ready(function($) {
                // Modificar tabs de billing/shipping en HPOS
                $('.wc-orders-tabs a').each(function() {
                    var $this = $(this);
                    if ($this.text().trim() === 'Billing') {
                        $this.text('" . esc_js($clientLabel) . "');
                    }
                    if ($this.text().trim() === 'Shipping') {
                        $this.text('" . esc_js($venueLabel) . "');
                    }
                });
                
                // Modificar títulos de sección
                $('.woocommerce-order-panel .panel-header h2').each(function() {
                    var $this = $(this);
                    if ($this.text().trim() === 'Billing') {
                        $this.text('" . esc_js($clientLabel) . "');
                    }
                    if ($this.text().trim() === 'Shipping') {
                        $this.text('" . esc_js($venueLabel) . "');
                    }
                });
            });
        ");
    }

    /**
     * Añadir etiquetas de tabs para HPOS
     */
    public function addHposTabLabels($order): void
    {
        // Este método puede ser usado para modificaciones adicionales en el header de HPOS
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
            $tabs['billing']['label'] = 'Client';
        }
        
        // Change Shipping to Venue/Wedding Planner
        if (isset($tabs['shipping'])) {
            $tabs['shipping']['label'] = 'Venue/Wedding Planner';
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
        
        // Verificar si estamos en página de pedidos (Classic o HPOS)
        if (!$screen || !HposCompatibility::isOrderEditScreen()) {
            return;
        }
        ?>
        <style>
            /* Estilos para ambos Classic y HPOS */
            .woocommerce-order-data-tabs .tabs li a,
            .wc-orders-tabs a {
                font-weight: 600;
            }
            
            /* Iconos para Classic */
            .woocommerce-order-data-tabs .tabs li.billing_tab a:before {
                content: "👤 ";
                margin-right: 5px;
            }
            
            .woocommerce-order-data-tabs .tabs li.shipping_tab a:before {
                content: "📍 ";
                margin-right: 5px;
            }
            
            /* Iconos para HPOS */
            .wc-orders-tabs li.billing a:before {
                content: "👤 ";
                margin-right: 5px;
            }
            
            .wc-orders-tabs li.shipping a:before {
                content: "📍 ";
                margin-right: 5px;
            }
        </style>
        <?php
    }
}
