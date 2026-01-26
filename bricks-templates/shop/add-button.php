<?php
/**
 * Bricks Code Element: Add to Cart Button (Simple Products Only)
 */

// NOTE: Reference-only template for Bricks. Do NOT enqueue, include, or load from the Zerø Sense plugin runtime.

// Security check
if (!defined('ABSPATH')) {
    return; // Exit if accessed directly (changed from exit to return for Bricks)
}

// Get global product object
global $product;

// Validate environment
if (!($product instanceof WC_Product) || !function_exists('WC') || !WC()->cart) {
    return;
}

// Only work with simple products
if ($product->get_type() !== 'simple') {
    return;
}

$product_id = $product->get_id();

// Check cart status
$cart_item_key = '';
$in_cart = false;

if (WC()->cart) {
    foreach (WC()->cart->get_cart() as $key => $item) {
        if ((int)$item['product_id'] === (int)$product_id) {
            $cart_item_key = $key;
            $in_cart = true;
            break;
        }
    }
}

// Set button text based on cart status
$button_title = $in_cart 
    ? esc_html__('Remove from cart', 'zero-sense') 
    : esc_html__('Add to cart', 'zero-sense');

// Define primary button class based on cart state
$primary_action_class = $in_cart ? 'zs-remove-from-cart' : 'zs-add-to-cart';

// Prepare button classes
$button_classes = [
    'cart-circle-btn',
    $primary_action_class,
    $in_cart ? 'in-cart' : ''
];

// Clean empty values
$button_classes = array_filter($button_classes);

// Build button attributes
$button_attrs = [
    'type'               => 'button',
    'class'              => implode(' ', $button_classes),
    'data-product-id'    => absint($product_id),
    'data-cart-item-key' => esc_attr($cart_item_key),
    'data-quantity'      => 1,
    'title'              => esc_attr($button_title),
    'aria-label'         => esc_attr($button_title),
    'role'               => 'button',
    'aria-live'          => 'polite'
];

// Define SVG Icons with descriptive names
$icons = [
    'plus'    => '<path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>',
    'loading' => '<path d="M12 4V2A10 10 0 0 0 2 12h2a8 8 0 0 1 8-8z" class="loading-path"/>',
    'check'   => '<path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>',
    'remove'  => '<path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>'
];
?>
<!-- Add to Cart Button (Simple Products Only) -->
<button <?php echo implode(' ', array_map(function($key, $value) { 
    return esc_attr($key) . '="' . esc_attr($value) . '"'; 
}, array_keys($button_attrs), $button_attrs)); ?>>
    <?php foreach ($icons as $icon_name => $path) : ?>
        <svg class="icon icon-<?php echo esc_attr($icon_name); ?>" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <?php echo $path; ?>
        </svg>
    <?php endforeach; ?>
    <span class="visually-hidden"><?php echo esc_html($button_title); ?></span>
</button>
