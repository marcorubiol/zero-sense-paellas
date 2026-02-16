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
        // JavaScript para modificar títulos (funciona tanto en HPOS como Classic)
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts'], 10, 1);
        
        // Register strings with WPML
        add_action('init', [$this, 'registerWpmlStrings'], 20);
        
        // Admin CSS
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
     * Enqueue admin scripts para modificar títulos
     */
    public function enqueueAdminScripts($hook): void
    {
        // Solo cargar en páginas de pedidos
        if (!HposCompatibility::isOrderEditScreen()) {
            return;
        }

        // JavaScript para modificar títulos de metaboxes
        $script = <<<'JAVASCRIPT'
            jQuery(document).ready(function($) {
                console.log('Zero Sense: Admin Section Titles script loaded');
                
                // Función para cambiar títulos
                function changeMetaboxTitles() {
                    var changed = 0;
                    
                    // Buscar TODOS los h2 y h3 en la página
                    $('h2, h3').each(function() {
                        var elem = $(this);
                        var text = elem.text().trim();
                        
                        console.log('Found heading:', text, 'Classes:', elem.attr('class'), 'ID:', elem.attr('id'));
                        
                        if (text === 'Billing' || text === 'Billing details' || text === 'Facturación' || text === 'Detalles de facturación') {
                            elem.html('<span>� Client</span>');
                            changed++;
                            console.log('Changed Billing to Client');
                        }
                        if (text === 'Shipping' || text === 'Shipping details' || text === 'Envío' || text === 'Detalles de envío') {
                            elem.html('<span>� Venue/Wedding Planner</span>');
                            changed++;
                            console.log('Changed Shipping to Venue/Wedding Planner');
                        }
                    });
                    
                    console.log('Zero Sense: Changed ' + changed + ' titles');
                }
                
                // Ejecutar al cargar
                changeMetaboxTitles();
                
                // Ejecutar después de delays
                setTimeout(changeMetaboxTitles, 500);
                setTimeout(changeMetaboxTitles, 1000);
                setTimeout(changeMetaboxTitles, 2000);
            });
JAVASCRIPT;

        wp_add_inline_script('jquery', $script);
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
     * Add custom admin styles
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
            /* Asegurar que los títulos de metabox sean visibles */
            #woocommerce-order-data h2.hndle span,
            #woocommerce-order-data h3.hndle span,
            .postbox h2 span,
            .postbox h3 span {
                display: inline-block;
            }
        </style>
        <?php
    }
}
