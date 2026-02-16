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
        return 'Adds descriptive subtitles to Billing (👤 Client) and Shipping (📍 Venue/Wedding Planner) sections in WooCommerce admin orders.';
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
        // Filtro gettext para añadir segunda línea descriptiva
        add_filter('gettext', [$this, 'addSubtitles'], 10, 3);
        
        // Register strings with WPML
        add_action('init', [$this, 'registerWpmlStrings'], 20);
        
        // Admin CSS para estilos de subtítulos
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
     * Añadir subtítulos descriptivos a Billing y Shipping
     */
    public function addSubtitles($translated, $text, $domain): string
    {
        // Solo en WooCommerce admin
        if ($domain !== 'woocommerce' || !is_admin()) {
            return $translated;
        }
        
        // Solo en páginas de pedidos
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            return $translated;
        }
        
        // Añadir segunda línea a Billing
        if ($text === 'Billing') {
            return 'Billing<br><span class="zs-subtitle">👤 Client</span>';
        }
        
        // Añadir segunda línea a Shipping
        if ($text === 'Shipping') {
            return 'Shipping<br><span class="zs-subtitle">📍 Venue/Wedding Planner</span>';
        }
        
        return $translated;
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
     * Add custom admin styles para subtítulos
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
            /* Estilos para los subtítulos descriptivos */
            .zs-subtitle {
                display: block;
                font-size: 0.85em;
                font-weight: normal;
                color: #666;
                margin-top: 2px;
                line-height: 1.4;
            }
        </style>
        <?php
    }
}
