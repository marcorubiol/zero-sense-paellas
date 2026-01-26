<?php
/**
 * Order Items Display for Bricks Builder with WPML Support
 * Now with Deposit Payment Handling
 * Text domain: zero-sense
 */

// Try multiple methods to detect the order with WPML compatibility
$order = null;

// Method 1: Direct Bricks variable
if (isset($bricks_query_loop_object) && is_a($bricks_query_loop_object, 'WC_Order')) {
    $order = $bricks_query_loop_object;
}

// Method 2: Global variable
if (!$order && isset($GLOBALS['order']) && is_a($GLOBALS['order'], 'WC_Order')) {
    $order = $GLOBALS['order'];
}

// Method 3: Order pay endpoint
if (!$order && function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
    global $wp;
    if (isset($wp->query_vars['order-pay']) && absint($wp->query_vars['order-pay']) > 0) {
        $order_id = absint($wp->query_vars['order-pay']);
        $order = wc_get_order($order_id);
    }
}

// Method 4: View order endpoint
if (!$order && function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('view-order')) {
    global $wp;
    if (isset($wp->query_vars['view-order']) && absint($wp->query_vars['view-order']) > 0) {
        $order = wc_get_order(absint($wp->query_vars['view-order']));
    }
}

// Method 5: Get order from URL (original method)
$url_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path_parts = explode('/', trim($url_path, '/'));
$last_part = end($path_parts);

if (!$order && is_numeric($last_part)) {
    $order_id = absint($last_part);
    $order = wc_get_order($order_id);
    
    // Validate using order key if available
    if ($order && isset($_GET['key'])) {
        $order_key = wc_clean(wp_unslash($_GET['key']));
        // Check key but continue even if they don't match (to handle WPML quirks)
        if ($order->get_order_key() !== $order_key) {
            // Key mismatch is allowed in multilingual sites
        }
    }
}

// Method 6: Try direct database lookup if needed
if (!$order && isset($_GET['key'])) {
    global $wpdb;
    $order_key = wc_clean(wp_unslash($_GET['key']));
    
    // Look up order ID directly from database using the key
    $order_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_order_key' AND meta_value = %s LIMIT 1",
            $order_key
        )
    );
    
    if ($order_id) {
        $order = wc_get_order($order_id);
    }
}

// If we found a valid order, proceed with displaying it
if ($order && is_a($order, 'WC_Order')) {
    // Get deposit info if this is a deposit order
    $has_deposit = false;
    $deposit_amount = 0;
    $remaining_amount = 0;
    $is_deposit_paid = false;
    
    // Check if we're using the deposits feature
    if (function_exists('zs_wd_get_deposit_info')) {
        $deposit_info = zs_wd_get_deposit_info($order);
        $has_deposit = $deposit_info['has_deposit'] ?? false;
        $deposit_amount = $deposit_info['deposit_amount'] ?? 0;
        $remaining_amount = $deposit_info['remaining_amount'] ?? 0;
        $is_deposit_paid = $order->has_status('deposit-paid');
    }
    
    // Get current WPML language
    $current_lang = function_exists('icl_object_id') ? ICL_LANGUAGE_CODE : 'default';
    
    // Function to get categorized order items
    function zs_get_categorized_order_items($order) {
        if (!$order || !$order->get_items()) {
            return [];
        }
        
        $order_items = $order->get_items();
        $categorized_items = [];
        
        // Get product categories with their ordering
        $all_categories = get_terms([
            'taxonomy' => 'product_cat',
            'orderby' => 'menu_order',
            'order' => 'ASC',
            'hide_empty' => false
        ]);

        // Create category order mapping
        $category_order = [];
        foreach ($all_categories as $cat) {
            $category_order[$cat->term_id] = $cat->term_order;
        }
        
        // Group items by category
        foreach ($order_items as $item_id => $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            
            // For variations, get parent product categories
            $product_id_for_terms = $variation_id ? $product_id : $product_id;
            
            // Get product's menu order
            $product_position = (int) get_post_field('menu_order', $product_id_for_terms);
            
            $categories = wp_get_post_terms($product_id_for_terms, 'product_cat');
            
            // Add to "Other" category if no categories found
            if (empty($categories) || is_wp_error($categories)) {
                $other_cat_name = __('Other Products', 'zero-sense');
                
                if (!isset($categorized_items[0])) {
                    $categorized_items[0] = [
                        'name' => $other_cat_name,
                        'slug' => 'other-items',
                        'items' => [],
                        'order' => 99999
                    ];
                }
                
                $categorized_items[0]['items'][$item_id] = [
                    'id' => $item_id,
                    'item' => $item,
                    'position' => $product_position
                ];
                
                continue;
            }
            
            // Add to each product category
            foreach ($categories as $category) {
                if (!isset($categorized_items[$category->term_id])) {
                    // Get name based on current language
                    $category_name = $category->name;
                    
                    // Use WPML translation if available
                    if (function_exists('icl_object_id')) {
                        $translated_cat_id = icl_object_id($category->term_id, 'product_cat', true);
                        if ($translated_cat_id) {
                            $translated_cat = get_term($translated_cat_id, 'product_cat');
                            if (!is_wp_error($translated_cat)) {
                                $category_name = $translated_cat->name;
                            }
                        }
                    }
                    
                    $categorized_items[$category->term_id] = [
                        'name' => $category_name,
                        'slug' => $category->slug,
                        'items' => [],
                        'order' => $category_order[$category->term_id] ?? 999999
                    ];
                }
                
                $categorized_items[$category->term_id]['items'][$item_id] = [
                    'id' => $item_id,
                    'item' => $item,
                    'position' => $product_position
                ];
            }
        }
        
        // Sort categories by their order
        uasort($categorized_items, function($a, $b) {
            return $a['order'] <=> $b['order'];
        });
        
        // Sort products within each category
        foreach ($categorized_items as &$category) {
            uasort($category['items'], function($a, $b) {
                return $a['position'] <=> $b['position'];
            });
        }
        
        return $categorized_items;
    }

    // Get the categorized order items
    $categorized_items = zs_get_categorized_order_items($order);

    if (!empty($categorized_items)) {
        ?>
        <div class="zs_products-wrapper">
            <?php foreach ($categorized_items as $category_id => $category_data): ?>
                <div id="<?php echo esc_attr($category_data['slug']); ?>" class="order-pay__inner-content">
                    <h3 class="zs_category_title"><?php echo esc_html($category_data['name']); ?></h3>
                    
                    <?php foreach ($category_data['items'] as $item_data): 
                        $item = $item_data['item'];
                        $product_id = $item->get_product_id();
                        $product_name = $item->get_name();
                        
                        // Get translated product name if WPML is active
                        if (function_exists('icl_object_id')) {
                            $translated_product_id = icl_object_id($product_id, 'product', true);
                            if ($translated_product_id) {
                                // Get the translated product title
                                $product = wc_get_product($translated_product_id);
                                if ($product) {
                                    $product_name = $product->get_name();
                                }
                            }
                        }
                        
                        $quantity = $item->get_quantity();
                        
                        // Get formatted meta data
                        $formatted_meta = [];
                        foreach ($item->get_formatted_meta_data() as $meta_id => $meta) {
                            if (isset($meta->display_key) && isset($meta->display_value)) {
                                $formatted_meta[] = [
                                    'key' => $meta->display_key,
                                    'value' => $meta->display_value
                                ];
                            }
                        }
                    ?>
                        <div class="zs_product_wrapper">
                            <div class="zs_cart_product">
                                <!-- Quantity Display -->
                                <div class="zs_cart_item_qty">
                                    <div class="quantity">
                                        <span><?php echo esc_html($quantity); ?></span>
                                    </div>
                                </div>
                                
                                <!-- Product Name -->
                                <div class="zs_product_name_wrapper">
                                    <p class="zs_product_name">
                                        <?php echo esc_html($product_name); ?>
                                        
                                        <?php if (!empty($formatted_meta)): ?>
                                        <span class="variation">
                                            <?php 
                                            $meta_list = [];
                                            foreach ($formatted_meta as $meta) {
                                                $meta_list[] = '<span class="meta-item">' . 
                                                    esc_html($meta['key']) . ': ' . 
                                                    wp_kses_post($meta['value']) . 
                                                '</span>';
                                            }
                                            echo implode(', ', $meta_list);
                                            ?>
                                        </span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            
                            <!-- Item Price Display -->
                            <div class="zs_remove_product_wrapper">
                                <span class="product-price">
                                    <?php 
                                    // Get the subtotal and total of this item
                                    $subtotal = $item->get_subtotal();
                                    $total = $item->get_total();
                                    
                                    // Check if there is a discount on this item
                                    if ($subtotal > $total && $subtotal > 0) {
                                        // Calculate the discount amount
                                        $discount = $subtotal - $total;
                                        $discount_percent = round(($discount / $subtotal) * 100);
                                        
                                        // Show original price with strikethrough, then the discounted price with compact discount info
                                        echo '<span class="zs_original_price"><del>' . wc_price($subtotal) . '</del></span>';
                                        echo '<span class="zs_discounted_price">' . wc_price($total) . '</span>';
                                        echo '<span class="zs_discount_badge">(-' . $discount_percent . '%) -' . wc_price($discount) . '</span>';
                                    } else {
                                        // No discount, just show the regular price
                                        echo wp_kses_post($subtotal ? wc_price($subtotal) : '-');
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        
        <!-- Cart Totals Section -->
        <div class="zs_category_section zs_cart_totals_section">
            <h3 class="zs_category_title">
                <?php esc_html_e('Order Totals', 'zero-sense'); ?>
            </h3>
            <div class="zs_cart_totals zs_product_wrapper">
                <!-- Subtotal row -->
                <div class="zs_cart_row zs_cart_subtotal_row">
                    <span class="zs_cart_row_label zs_subtotal_label">
                        <?php esc_html_e('Subtotal', 'zero-sense'); ?>
                    </span>
                    <span class="zs_cart_row_value zs_subtotal_value">
                        <?php echo wp_kses_post(wc_price($order->get_subtotal())); ?>
                    </span>
                </div>
                
                <!-- Discount row - only show if we have discounts -->
                <?php 
                $discount_total = $order->get_total_discount();
                $coupon_codes = method_exists($order, 'get_coupon_codes') ? $order->get_coupon_codes() : array();
                if ($discount_total > 0): ?>
                    <div class="zs_cart_row zs_discount_row">
                        <span class="zs_cart_row_label zs_discount_label">
                            <?php esc_html_e('Discount', 'zero-sense'); ?>
                            <?php if (!empty($coupon_codes)): ?>
                                <small>
                                    (<?php echo esc_html(implode(', ', $coupon_codes)); ?>)
                                </small>
                            <?php endif; ?>
                        </span>
                        <span class="zs_cart_row_value zs_discount_value">
                            <?php
                            $discount_percent_total = round(($discount_total / $order->get_subtotal()) * 100, 1);
                            echo '(-' . $discount_percent_total . '%) -' . wp_kses_post(wc_price($discount_total));
                            ?>
                        </span>
                    </div>
                <?php endif; ?>
                
                <!-- Tax row if applicable -->
                <?php if ($order->get_total_tax() > 0): ?>
                    <div class="zs_cart_row zs_tax_row">
                        <span class="zs_cart_row_label zs_tax_label">
                            <?php esc_html_e('Tax', 'zero-sense'); ?>
                        </span>
                        <span class="zs_cart_row_value zs_tax_value">
                            <?php echo wp_kses_post(wc_price($order->get_total_tax())); ?>
                        </span>
                    </div>
                <?php endif; ?>
                
                <!-- Shipping row if applicable -->
                <?php if ($order->get_shipping_total() > 0): ?>
                    <div class="zs_cart_row zs_shipping_row">
                        <span class="zs_cart_row_label zs_shipping_label">
                            <?php esc_html_e('Shipping', 'zero-sense'); ?>
                        </span>
                        <span class="zs_cart_row_value zs_shipping_value">
                            <?php echo wp_kses_post(wc_price($order->get_shipping_total())); ?>
                        </span>
                    </div>
                <?php endif; ?>
                
                <!-- Original Total -->
                <div class="zs_cart_row zs_total_row zs_cart_total_final">
                    <span class="zs_cart_row_label zs_total_label">
                        <?php esc_html_e('Total', 'zero-sense'); ?>
                    </span>
                    <span class="zs_cart_row_value zs_total_value">
                        <?php 
                        // Show correct total for deposit orders
                        if ($has_deposit && $is_deposit_paid) {
                            // Calculate original total (without deducting deposit)
                            $original_total = $order->get_subtotal() - $order->get_total_discount() + $order->get_total_tax() + $order->get_shipping_total();
                            echo wp_kses_post(wc_price($original_total));
                        } else {
                            echo wp_kses_post(wc_price($order->get_total()));
                        }
                        ?>
                    </span>
                </div>
            
            <?php if ($has_deposit && $is_deposit_paid): ?>
                <!-- Total row becomes regular weight when deposit is paid -->
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const totalRow = document.querySelector('.zs_total_row');
                        if (totalRow) {
                            totalRow.classList.remove('zs_cart_total_final');
                            totalRow.classList.add('zs_original_total');
                        }
                    });
                </script>
                
                <!-- Divider -->
                <div class="zs_cart_divider"></div>
                
                <!-- Deposit paid row -->
                <div class="zs_cart_row zs_deposit_row">
                    <span class="zs_cart_row_label zs_deposit_label">
                        <?php esc_html_e('Depósito pagado', 'zero-sense'); ?>
                    </span>
                    <span class="zs_cart_row_value zs_deposit_value">
                        -<?php echo wp_kses_post(wc_price($deposit_amount)); ?>
                    </span>
                </div>
                
                <!-- Remaining amount row -->
                <div class="zs_cart_row zs_remaining_row zs_cart_total_final">
                    <span class="zs_cart_row_label zs_remaining_label">
                        <strong><?php esc_html_e('Cantidad restante', 'zero-sense'); ?></strong>
                    </span>
                    <span class="zs_cart_row_value zs_remaining_value">
                        <strong><?php 
                        // Recalculate the remaining amount based on the original total
                        $original_total = $order->get_subtotal() - $order->get_total_discount() + $order->get_total_tax() + $order->get_shipping_total();
                        $recalculated_remaining = $original_total - $deposit_amount;
                        echo wp_kses_post(wc_price($recalculated_remaining));
                        ?></strong>
                    </span>
                </div>
            <?php endif; ?>
            </div>
        </div>
        </div>
        <?php
    } else {
        // No products in this order
        echo '<p class="zs_empty_cart">' . esc_html__('No products in this order', 'zero-sense') . '</p>';
    }
} else {
    // No order available
    echo '<p class="zs_empty_cart">' . esc_html__('Order information not available', 'zero-sense') . '</p>';
}
?>