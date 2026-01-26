<?php
namespace ZeroSense\Features\WooCommerce\Checkout\Components;

class TextModifications
{
    public function __construct()
    {
        // Checkout specific text modifications
        add_filter('woocommerce_checkout_fields', [$this, 'custom_checkout_fields']);
        add_filter('woocommerce_order_button_text', [$this, 'custom_place_order_button']);
        add_filter('gettext', [$this, 'targeted_string_replacements'], 20, 3);
    }

    /**
     * Change checkout field labels and placeholders
     */
    public function custom_checkout_fields($fields)
    {
        // Billing details section title
        add_filter('woocommerce_checkout_billing_title', function() {
            return __('Contact details', 'zero-sense');
        });
        
        // Order notes field label and placeholder
        if (isset($fields['order']['order_comments'])) {
            $fields['order']['order_comments']['label'] = __('We are not robots or artificial intelligences. If you have any questions, ideas, suggestions or any query, this is your space. We read you and will reply personally.', 'zero-sense');
            $fields['order']['order_comments']['placeholder'] = '';
        }
        
        return $fields;
    }

    /**
     * Change Place Order button text
     */
    public function custom_place_order_button()
    {
        $locale = determine_locale();
        
        if (strpos($locale, 'ca') === 0) {
            return 'Rebre pressupost';
        } elseif (strpos($locale, 'es') === 0) {
            return 'Recibir presupuesto';
        } else {
            return 'Get Quote';
        }
    }

    /**
     * Targeted string replacements for WooCommerce texts
     */
    public function targeted_string_replacements($translated_text, $text, $domain)
    {
        // Only apply to WooCommerce text domain to improve performance
        if ($domain !== 'woocommerce') {
            return $translated_text;
        }
        
        // Create an array of strings to replace
        $translations = [
            // English translations
            'Billing details' => 'Contact details',
            'Order notes' => 'We are not robots or artificial intelligences. If you have any questions, ideas, suggestions or any query, this is your space. We read you and will reply personally.',
            'Notes about your order, e.g. special notes for delivery.' => 'Notes about your order.',
            
            // Spanish translations
            'Detalles de facturación' => 'Detalles de contacto',
            'Realizar el pedido' => 'Recibir presupuesto',
            'Notas del pedido' => 'No somos robots ni inteligencias artificiales. Si tienes dudas, ideas, sugerencias o cualquier pregunta, este es tu espacio. Te leemos y te responderemos personalmente.',
            'Notas sobre tu pedido, por ejemplo, notas especiales para la entrega.' => 'Notas sobre tu pedido.',

            // Catalan translations
            'Detalls de facturació' => 'Detalls de contacte',
            'Realitza la comanda' => 'Rebre pressupost',
            'Notes de la comanda' => 'No som robots ni intel·ligències artificials. Si tens dubtes, idees, suggeriments o qualsevol pregunta, aquest és el teu espai. Et llegim i et respondrem personalment.',
            'Notes de la vostra comanda; per exemple, instruccions especials per al lliurament.' => 'Notes de la vostra comanda',
        ];

        // Check if this string is in our list
        if (isset($translations[$translated_text])) {
            return $translations[$translated_text];
        }

        return $translated_text;
    }
}
