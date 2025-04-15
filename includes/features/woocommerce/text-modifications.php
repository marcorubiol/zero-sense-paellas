<?php
/**
 * WooCommerce Text Modifications
 * Uses specific WooCommerce filters to modify text strings
 */

if (!defined('ABSPATH')) exit; // Prevent direct access

/**
 * Change cart button text
 */
add_filter('woocommerce_product_single_add_to_cart_text', 'zs_custom_add_to_cart_text');
add_filter('woocommerce_product_add_to_cart_text', 'zs_custom_add_to_cart_text');
function zs_custom_add_to_cart_text() {
    return __('Add to Cart', 'zero-sense');
}

/**
 * Change Update Cart button text
 */
add_filter('woocommerce_cart_update_cart_button_text', 'zs_update_cart_button_text');
function zs_update_cart_button_text() {
    return __('Update', 'zero-sense');
}

/**
 * Change View Cart button text
 */
add_filter('woocommerce_continue_shopping_button_text', 'zs_continue_shopping_button_text');
function zs_continue_shopping_button_text() {
    return __('Continue Shopping', 'zero-sense');
}

add_filter('woocommerce_product_add_to_cart_text', 'zs_product_view_cart_text', 10, 2);
function zs_product_view_cart_text($text, $product = null) {
    if ($product && $product->is_in_stock() && $product->is_purchasable()) {
        if (WC()->cart && !empty(WC()->cart->get_cart()) && array_key_exists($product->get_id(), WC()->cart->get_cart_item_quantities())) {
            return __('See Cart', 'zero-sense');
        }
    }
    return $text;
}

/**
 * Change checkout field labels and placeholders
 */
add_filter('woocommerce_checkout_fields', 'zs_custom_checkout_fields');
function zs_custom_checkout_fields($fields) {
    // Billing details section title
    add_filter('woocommerce_checkout_billing_title', function() {
        return __('Contact details', 'zero-sense');
    });
    
    // Order notes field label and placeholder
    if (isset($fields['order']['order_comments'])) {
        $fields['order']['order_comments']['label'] = __('We are not robots or artificial intelligences. If you have any questions, ideas, suggestions or any query, this is your space. We read you and will reply personally.', 'zero-sense');
        $fields['order']['order_comments']['placeholder'] = __('Notes about your order.', 'zero-sense');
    }
    
    return $fields;
}

/**
 * Change Place Order button text
 */
add_filter('woocommerce_order_button_text', 'zs_custom_place_order_button');
function zs_custom_place_order_button() {
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
 * View Cart text in added to cart message
 */
add_filter('woocommerce_add_to_cart_message_html', 'zs_add_to_cart_message', 10, 2);
function zs_add_to_cart_message($message, $products) {
    $locale = determine_locale();
    
    if (strpos($locale, 'ca') === 0) {
        return str_replace('Veure la cistella', 'Següent pas', $message);
    } elseif (strpos($locale, 'es') === 0) {
        return str_replace('Ver carrito', 'Siguiente paso', $message);
    } else {
        return str_replace('View cart', 'See Cart', $message);
    }
}

/**
 * Comprehensive text replacement for cases where specific hooks aren't available
 * This is more targeted than using the global gettext filter
 */
add_filter('woocommerce_get_script_data', 'zs_modify_script_data', 10, 2);
function zs_modify_script_data($data, $handle) {
    if ('wc-checkout' === $handle) {
        if (isset($data['i18n_checkout_error'])) {
            // Modify any checkout JS strings if needed
        }
    }
    return $data;
}

/**
 * For strings that don't have specific filters, we use a more targeted gettext filter
 * that only runs on WooCommerce domain and only checks our specific strings
 */
add_filter('gettext', 'zs_targeted_string_replacements', 20, 3);
function zs_targeted_string_replacements($translated_text, $text, $domain) {
    // Only apply to WooCommerce text domain to improve performance
    if ($domain !== 'woocommerce') {
        return $translated_text;
    }
    
    // Create an array of strings to replace
    $translations = [
        // English translations
        'Update cart' => 'Update',
        'View cart' => 'See Cart',
        'Billing details' => 'Contact details',
        'Order notes' => 'We are not robots or artificial intelligences. If you have any questions, ideas, suggestions or any query, this is your space. We read you and will reply personally.',
        'Notes about your order, e.g. special notes for delivery.' => 'Notes about your order.',
        
        // Spanish translations
        'Actualizar carrito' => 'Actualizar',
        'Ver carrito' => 'Siguiente paso',
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