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
        // JavaScript para añadir subtítulos (sin salto visual usando CSS)
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts'], 10, 1);
        
        // Register strings with WPML
        add_action('init', [$this, 'registerWpmlStrings'], 20);
        
        // Admin CSS para estilos de subtítulos y ocultar inicialmente
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
     * Enqueue admin scripts para añadir subtítulos
     */
    public function enqueueAdminScripts($hook): void
    {
        if (!HposCompatibility::isOrderEditScreen()) {
            return;
        }

        $script = <<<'JAVASCRIPT'
            jQuery(document).ready(function($) {
                function addSubtitles() {
                    $('#order_data h3').each(function() {
                        var elem = $(this);
                        var text = elem.text().trim();
                        var firstWord = text.split(/\s+/)[0];
                        
                        if ((firstWord === 'Billing' || firstWord === 'Facturación') && !elem.find('.zs-subtitle').length) {
                            elem.append('<br><span class="zs-subtitle">👤 Client</span>');
                        } else if ((firstWord === 'Shipping' || firstWord === 'Envío') && !elem.find('.zs-subtitle').length) {
                            elem.append('<br><span class="zs-subtitle">📍 Venue/Wedding Planner</span>');
                        }
                    });
                    
                    // Mostrar títulos después de modificar
                    $('#order_data h3').css('visibility', 'visible');
                }
                
                addSubtitles();
                setTimeout(addSubtitles, 100);
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
            /* Ocultar títulos inicialmente para evitar salto visual */
            #order_data h3 {
                visibility: hidden;
            }
            
            /* Estilos para los subtítulos descriptivos */
            .zs-subtitle {
                display: block;
                font-size: 0.92em;
                font-weight: 600;
                color: #2271b1;
                margin-top: 0;
                line-height: 1.3;
            }
            
            /* Reducir espacio entre título y subtítulo */
            #order_data h3 br {
                line-height: 0.3;
            }
        </style>
        <?php
    }
}
