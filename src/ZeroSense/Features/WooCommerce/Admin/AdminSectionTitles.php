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
                        
                        if (elem.next('.zs-subtitle').length) {
                            return;
                        }
                        
                        var firstWord = elem.contents().first().text().trim().split(/\s+/)[0];
                        var editLink = elem.find('a.edit_address');
                        var editHtml = editLink.length ? editLink[0].outerHTML : '';
                        
                        if (firstWord === 'Billing' || firstWord === 'Facturación') {
                            if (editLink.length) editLink.remove();
                            elem.after('<div class="zs-subtitle zs-subtitle-client">Client' + (editHtml ? '<span class="zs-subtitle-edit">' + editHtml + '</span>' : '') + '</div>');
                        } else if (firstWord === 'Shipping' || firstWord === 'Envío') {
                            if (editLink.length) editLink.remove();
                            elem.after('<div class="zs-subtitle zs-subtitle-venue">Venue/Wedding Planner' + (editHtml ? '<span class="zs-subtitle-edit">' + editHtml + '</span>' : '') + '</div>');
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
            /* Hide initially to avoid visual jump */
            #order_data h3 {
                visibility: hidden;
                margin-top: 2px !important;
            }

            /* Card label below the heading */
            .zs-subtitle {
                display: flex;
                align-items: center;
                justify-content: space-between;
                font-size: 12px;
                font-weight: 600;
                color: var(--zs-mb-label-color, #1d2327);
                padding: 5px 10px;
                border-radius: 0 0 var(--zs-mb-radius, 4px) var(--zs-mb-radius, 4px);
                margin-bottom: 0;
                line-height: 1.4;
            }

            .zs-subtitle-client {
                background: #DCFCE7;
                border-left: 3px solid #16A34A;
                color: #14532D;
            }

            .zs-subtitle-venue {
                background: #E0E7FF;
                border-left: 3px solid #4F46E5;
                color: #1E1B4B;
            }

            .zs-subtitle-edit a {
                color: var(--zs-mb-description-color, #646970);
                text-decoration: none;
                opacity: 0.7;
            }

            .zs-subtitle-edit a:hover {
                opacity: 1;
            }
        </style>
        <?php
    }
}
