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
                        
                        if (elem.find('.zs-subtitle').length) {
                            return; // Ya tiene subtítulo
                        }
                        
                        var html = elem.html();
                        var firstWord = elem.contents().first().text().trim().split(/\s+/)[0];
                        
                        // Encontrar y extraer el botón Edit
                        var editLink = elem.find('a.edit_address');
                        var editHtml = editLink.length ? editLink[0].outerHTML : '';
                        
                        if (firstWord === 'Billing' || firstWord === 'Facturación') {
                            // Remover el botón Edit del HTML original
                            if (editLink.length) editLink.remove();
                            
                            // Insertar subtítulo con botón Edit dentro
                            var subtitle = '<br><span class="zs-subtitle">👤 Client' + (editHtml ? ' ' + editHtml : '') + '</span>';
                            var newHtml = elem.html().replace(/^(\s*)Billing(\s*)/, '$1Billing' + subtitle + '$2');
                            newHtml = newHtml.replace(/^(\s*)Facturación(\s*)/, '$1Facturación' + subtitle + '$2');
                            elem.html(newHtml);
                        } else if (firstWord === 'Shipping' || firstWord === 'Envío') {
                            // Remover el botón Edit del HTML original
                            if (editLink.length) editLink.remove();
                            
                            // Insertar subtítulo con botón Edit dentro
                            var subtitle = '<br><span class="zs-subtitle zs-subtitle-venue">📍 Venue/Wedding Planner' + (editHtml ? ' ' + editHtml : '') + '</span>';
                            var newHtml = elem.html().replace(/^(\s*)Shipping(\s*)/, '$1Shipping' + subtitle + '$2');
                            newHtml = newHtml.replace(/^(\s*)Envío(\s*)/, '$1Envío' + subtitle + '$2');
                            elem.html(newHtml);
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
            /* Hide titles initially to avoid visual jump */
            #order_data h3 {
                visibility: hidden;
            }

            /* Log-style left border card — same pattern as action items */
            .zs-subtitle {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                font-size: 12px;
                font-weight: 600;
                color: var(--zs-mb-label-color, #1d2327);
                background: var(--zs-bg-auto, #dbeafe);
                padding: 4px 10px;
                border-radius: 0 var(--zs-mb-radius, 4px) var(--zs-mb-radius, 4px) 0;
                margin-top: 5px;
                line-height: 1.4;
                border-left: 3px solid var(--zs-color-auto, #2271b1);
            }

            .zs-subtitle-venue {
                background: var(--zs-bg-status, #ede9fe);
                border-left-color: var(--zs-color-status, #7C3AED);
            }

            .zs-subtitle a.edit_address {
                color: var(--zs-color-auto, #2271b1);
                text-decoration: none;
                font-size: 11px;
                opacity: 0.75;
                margin-left: 4px;
            }

            .zs-subtitle a.edit_address:hover {
                opacity: 1;
                text-decoration: underline;
            }

            #order_data h3 br {
                line-height: 0.5;
            }
        </style>
        <?php
    }
}
