<?php
/**
 * Cart Display Handler Class
 * Manages the display of cart items grouped by category with proper ordering
 */

if (!class_exists('ZS_Cart_Display')) {
    class ZS_Cart_Display {
        /**
         * Gets cart items organized by product categories in proper menu order
         * 
         * @return array Categorized cart items
         */
        private static function get_categorized_items(): array {
            if (!WC()->cart || WC()->cart->is_empty()) {
                return [];
            }
            
            static $cached_result = null;
            static $cached_cart_hash = null;
            
            $current_cart_hash = md5(serialize(WC()->cart->get_cart()));
            if ($cached_result !== null && $cached_cart_hash === $current_cart_hash) {
                return $cached_result;
            }
            
            $cart_items = WC()->cart->get_cart();
            $categorized_items = [];
            
            $all_categories = get_terms([
                'taxonomy' => 'product_cat',
                'orderby' => 'menu_order',
                'order' => 'ASC',
                'hide_empty' => false
            ]);

            $category_order = array_column(
                array_map(function($cat) {
                    return ['id' => $cat->term_id, 'order' => $cat->term_order];
                }, $all_categories), 
                'order', 'id'
            );
            
            foreach ($cart_items as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                $product_id_for_terms = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
                $product_position = (int) get_post_field('menu_order', $product_id_for_terms);
                $categories = wp_get_post_terms($product_id_for_terms, 'product_cat');
                
                if (empty($categories) || is_wp_error($categories)) {
                    self::add_item_to_category($categorized_items, 0, 'Other Items', 'other-items', 99999, $cart_item_key, $product, $cart_item['quantity'], $cart_item, $product_position);
                    continue;
                }
                
                foreach ($categories as $category) {
                    self::add_item_to_category($categorized_items, $category->term_id, $category->name, $category->slug, $category_order[$category->term_id] ?? 999999, $cart_item_key, $product, $cart_item['quantity'], $cart_item, $product_position);
                }
            }
            
            uasort($categorized_items, function($a, $b) {
                return $a['order'] <=> $b['order'];
            });
            
            foreach ($categorized_items as &$category) {
                uasort($category['items'], function($a, $b) {
                    return $a['position'] <=> $b['position'];
                });
            }
            
            $cached_result = $categorized_items;
            $cached_cart_hash = $current_cart_hash;
            
            return $categorized_items;
        }
        
        private static function add_item_to_category(array &$categorized_items, int $category_id, string $category_name, string $category_slug, int $category_order, string $cart_item_key, object $product, int $quantity, array $cart_item, int $product_position): void {
            if (!isset($categorized_items[$category_id])) {
                $categorized_items[$category_id] = [
                    'name' => sanitize_text_field($category_name),
                    'slug' => $category_slug,
                    'items' => [],
                    'order' => $category_order
                ];
            }
            
            $categorized_items[$category_id]['items'][$cart_item_key] = [
                'key' => sanitize_text_field($cart_item_key),
                'product' => $product,
                'quantity' => absint($quantity),
                'full_cart_item' => $cart_item,
                'position' => $product_position
            ];
        }
        
        public static function render_cart() {
            $categorized_items = self::get_categorized_items();
            
            if (empty($categorized_items)) {
                echo '<p class="zs_empty_cart">' . esc_html__('Tu carrito está vacío', 'your-theme-domain') . '</p>';
                return;
            }
            
            self::render_cart_content($categorized_items);
        }
        
        private static function render_cart_content(array $categorized_items): void {
            ?>
            <div class="zs_minicart">
                <?php foreach ($categorized_items as $category_id => $category_data): ?>
                    <div id="<?php echo esc_attr($category_data['slug']); ?>" class="zs_category_section">
                        <h3 class="zs_category_title"><?php echo esc_html($category_data['name']); ?></h3>
                        <?php self::render_category_items($category_data['items']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php
        }
        
        private static function render_category_items(array $items): void {
            foreach ($items as $item) {
                self::render_cart_item($item);
            }
        }
        
        private static function render_cart_item(array $item): void {
            $product = $item['product'];
            $cart_item_key = $item['key'];
            $full_cart_item = $item['full_cart_item'] ?? [];
            
            $variation_id = $product->is_type('variation') ? $product->get_id() : 0;
            $product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
            
            $hide_quantity = false;
            if (function_exists('rwmb_meta')) {
                $hide_quantity = (bool) rwmb_meta('impedir_seleccionar_cantidad', '', $product_id);
            }
            
            $formatted_name = apply_filters('woocommerce_cart_item_name', $product->get_formatted_name(), $full_cart_item, $cart_item_key);
            ?>
            <div class="zs_product_wrapper" data-item-index="<?php echo esc_attr($cart_item_key); ?>">
                <div class="zs_cart_product">
                    <?php if (!$hide_quantity): ?>
                    <div class="zs_cart_item_qty" 
                         data-cart_item_key="<?php echo esc_attr($cart_item_key); ?>" 
                         data-product_id="<?php echo esc_attr($product_id); ?>"
                         <?php echo $variation_id ? 'data-variation-id="' . esc_attr($variation_id) . '"' : ''; ?>>
                        <div class="quantity">
                            <label class="screen-reader-text" for="quantity-<?php echo esc_attr($cart_item_key); ?>">
                                <?php echo esc_html($product->get_name()); ?> quantity
                            </label>
                            <input type="text" 
                                   id="quantity-<?php echo esc_attr($cart_item_key); ?>"
                                   class="input-text qty text" 
                                   pattern="[0-9]*" 
                                   inputmode="numeric"
                                   value="<?php echo esc_attr($item['quantity']); ?>" 
                                   title="Qty" 
                                   size="4">
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="zs_product_name_wrapper<?php echo $hide_quantity ? ' full-width' : ''; ?>">
                        <p class="zs_product_name"><?php echo wp_kses_post($formatted_name); ?></p>
                    </div>
                </div>
                
                <div class="zs_remove_product_wrapper">
                    <span class="product-remove">
                        <a href="#" 
                           class="zs_remove_link remove-product" 
                           data-product_id="<?php echo esc_attr($product_id); ?>" 
                           data-cart_item_key="<?php echo esc_attr($cart_item_key); ?>">
                            <svg class="remove-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 30">
                                <path d="M 7 4 C 6.744125 4 6.4879687 4.0974687 6.2929688 4.2929688 L 4.2929688 6.2929688 C 3.9019687 6.6839688 3.9019687 7.3170313 4.2929688 7.7070312 L 11.585938 15 L 4.2929688 22.292969 C 3.9019687 22.683969 3.9019687 23.317031 4.2929688 23.707031 L 6.2929688 25.707031 C 6.6839688 26.098031 7.3170313 26.098031 7.7070312 25.707031 L 15 18.414062 L 22.292969 25.707031 C 22.682969 26.098031 23.317031 26.098031 23.707031 25.707031 L 25.707031 23.707031 C 26.098031 23.316031 26.098031 22.682969 25.707031 22.292969 L 18.414062 15 L 25.707031 7.7070312 C 26.098031 7.3170312 26.098031 6.6829688 25.707031 6.2929688 L 23.707031 4.2929688 C 23.316031 3.9019687 22.682969 3.9019687 22.292969 4.2929688 L 15 11.585938 L 7.7070312 4.2929688 C 7.5115312 4.0974687 7.255875 4 7 4 z"></path>
                            </svg>
                        </a>
                    </span>
                </div>
            </div>
            <?php
        }
    }
}

// Initialize the display
ZS_Cart_Display::render_cart();
?>
