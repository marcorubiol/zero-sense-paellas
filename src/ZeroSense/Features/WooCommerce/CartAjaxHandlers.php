<?php

declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce;

use ZeroSense\Core\FeatureInterface;

/**
 * AJAX Cart Handlers for WooCommerce.
 * Provides robust cart operations and JavaScript integration.
 */
class CartAjaxHandlers implements FeatureInterface
{
    public function getName(): string
    {
        return __('Cart AJAX Handlers', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Robust AJAX cart operations for add/remove/update, session safety, and live UI fragments.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getConditions(): array
    {
        return [];
    }

    public function isToggleable(): bool
    {
        return false; // Always active - critical for cart functionality
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function init(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Enqueue scripts and localization
        add_action('wp_enqueue_scripts', [$this, 'enqueueCartScripts']);
        
        // AJAX handlers
        add_action('wp_ajax_zs_add_to_cart', [$this, 'handleAddToCart']);
        add_action('wp_ajax_nopriv_zs_add_to_cart', [$this, 'handleAddToCart']);
        
        add_action('wp_ajax_zs_remove_from_cart', [$this, 'handleRemoveFromCart']);
        add_action('wp_ajax_nopriv_zs_remove_from_cart', [$this, 'handleRemoveFromCart']);
        
        add_action('wp_ajax_zs_update_quantity', [$this, 'handleUpdateQuantity']);
        add_action('wp_ajax_nopriv_zs_update_quantity', [$this, 'handleUpdateQuantity']);
        
        add_action('wp_ajax_zs_get_cart_totals', [$this, 'handleGetCartTotals']);
        add_action('wp_ajax_nopriv_zs_get_cart_totals', [$this, 'handleGetCartTotals']);
        
        add_action('wp_ajax_zs_get_cart_item_key', [$this, 'handleGetCartItemKey']);
        add_action('wp_ajax_nopriv_zs_get_cart_item_key', [$this, 'handleGetCartItemKey']);

        // Cart enhancements
        add_filter('woocommerce_add_to_cart_fragments', [$this, 'addCartButtonFragments']);
        add_action('init', [$this, 'extendWcCookieLifetime'], 5);
        add_action('woocommerce_init', [$this, 'ensureCartCookies'], 1);
    }

    public function hasInformation(): bool
    {
        return true;
    }

    public function getInformationBlocks(): array
    {
        return [
            [
                'type' => 'list',
                'title' => __('What’s included', 'zero-sense'),
                'items' => [
                    __('Add to cart merges quantities if product already exists.', 'zero-sense'),
                    __('Remove from cart by cart_item_key.', 'zero-sense'),
                    __('Update quantity endpoint returns fresh totals and cart hash.', 'zero-sense'),
                    __('Endpoints to fetch cart totals and cart_item_key for a product.', 'zero-sense'),
                    __('Fragment updates for in-cart buttons with proper SVG states.', 'zero-sense'),
                    __('Extended WC session lifetime and automatic session bootstrap.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/WooCommerce/CartAjaxHandlers.php', 'zero-sense'),
                    __('enqueueCartScripts() → defines zsCartHandler inline (AJAX URL, nonce, i18n).', 'zero-sense'),
                    __('handleAddToCart(), handleRemoveFromCart(), handleUpdateQuantity(), handleGetCartTotals(), handleGetCartItemKey().', 'zero-sense'),
                    __('addCartButtonFragments() → renders ".cart-circle-btn" in-cart state with SVG.', 'zero-sense'),
                    __('extendWcCookieLifetime(), ensureCartCookies(), ensureWcSession(), saveCartSession(), findProductInCart(), getCartButtonSvgIcons().', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('wp_enqueue_scripts (inline zsCartHandler before jQuery).', 'zero-sense'),
                    __('wp_ajax_zs_add_to_cart, wp_ajax_nopriv_zs_add_to_cart', 'zero-sense'),
                    __('wp_ajax_zs_remove_from_cart, wp_ajax_nopriv_zs_remove_from_cart', 'zero-sense'),
                    __('wp_ajax_zs_update_quantity, wp_ajax_nopriv_zs_update_quantity', 'zero-sense'),
                    __('wp_ajax_zs_get_cart_totals, wp_ajax_nopriv_zs_get_cart_totals', 'zero-sense'),
                    __('wp_ajax_zs_get_cart_item_key, wp_ajax_nopriv_zs_get_cart_item_key', 'zero-sense'),
                    __('woocommerce_add_to_cart_fragments', 'zero-sense'),
                    __('init, woocommerce_init (session & cookies).', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Rapid add clicks on same product should increase quantity (no duplicates).', 'zero-sense'),
                    __('Removing an item should refresh fragments and restore the add button state.', 'zero-sense'),
                    __('Quantity updates should recalc totals and return cart_hash.', 'zero-sense'),
                    __('Without prior session, endpoints must create and persist a session automatically.', 'zero-sense'),
                    __('Ensure window.zsCartHandler exists with nonce and ajaxUrl.', 'zero-sense'),
                    __('Buttons with .cart-circle-btn should toggle to in-cart with SVG icons.', 'zero-sense'),
                    __('Scripts load on: is_woocommerce(), is_cart(), is_checkout(), is_shop(), is_product(), is_page("pedido").', 'zero-sense'),
                ],
            ],
        ];
    }

    public function enqueueCartScripts(): void
    {
        if (!$this->shouldLoadCartScripts()) {
            return;
        }

        wp_enqueue_script('jquery');

        $scriptData = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('zs_cart_nonce'),
            'i18n'    => [
                'added'      => __('Item added to cart', 'zero-sense'),
                'removed'    => __('Item removed from cart', 'zero-sense'),
                'error'      => __('Error occurred', 'zero-sense'),
                'processing' => __('Processing...', 'zero-sense'),
                'empty_cart' => __('Tu carrito está vacío', 'zero-sense'),
            ]
        ];

        // Define zsCartHandler object inline
        $inlineScript = sprintf('const zsCartHandler = %s;', wp_json_encode($scriptData));
        wp_add_inline_script('jquery', $inlineScript, 'before');
    }

    private function shouldLoadCartScripts(): bool
    {
        return is_woocommerce() || is_cart() || is_checkout() || 
               is_shop() || is_product() || is_page('pedido');
    }

    public function handleAddToCart(): void
    {
        check_ajax_referer('zs_cart_nonce', 'security');
        
        if (!isset($_POST['product_id'])) {
            wp_send_json_error(['message' => 'Missing product ID']);
            return;
        }
        
        $productId = absint($_POST['product_id']);
        $quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 1;
        
        if (!function_exists('WC') || !isset(WC()->cart)) {
            wp_send_json_error(['message' => 'WooCommerce not available']);
            return;
        }
        
        // Ensure session exists
        $this->ensureWcSession();
        
        // Check if product already exists in cart
        $existingCartItemKey = $this->findProductInCart($productId);
        
        if ($existingCartItemKey) {
            // Product exists, update quantity
            $currentQuantity = WC()->cart->cart_contents[$existingCartItemKey]['quantity'];
            $newQuantity = $currentQuantity + $quantity;
            WC()->cart->set_quantity($existingCartItemKey, $newQuantity);
            $cartItemKey = $existingCartItemKey;
        } else {
            // Product doesn't exist, add new
            $cartItemKey = WC()->cart->add_to_cart($productId, $quantity);
        }
        
        if ($cartItemKey) {
            $this->saveCartSession();
            
            $fragments = apply_filters('woocommerce_add_to_cart_fragments', []);
            wp_send_json_success([
                'fragments' => $fragments,
                'cart_hash' => WC()->cart->get_cart_hash(),
                'cart_item_key' => $cartItemKey,
                'message' => __('Product added to cart', 'zero-sense')
            ]);
        } else {
            wp_send_json_error(['message' => __('Could not add product to cart', 'zero-sense')]);
        }
    }

    public function handleRemoveFromCart(): void
    {
        check_ajax_referer('zs_cart_nonce', 'security');
        
        if (!isset($_POST['cart_item_key'])) {
            wp_send_json_error(['message' => 'Missing cart item key']);
            return;
        }
        
        $cartItemKey = sanitize_text_field($_POST['cart_item_key']);
        
        if (!function_exists('WC') || !isset(WC()->cart)) {
            wp_send_json_error(['message' => 'WooCommerce not available']);
            return;
        }
        
        $this->ensureWcSession();
        
        $removed = WC()->cart->remove_cart_item($cartItemKey);
        
        if ($removed) {
            $this->saveCartSession();
            
            $fragments = apply_filters('woocommerce_add_to_cart_fragments', []);
            wp_send_json_success([
                'fragments' => $fragments,
                'cart_hash' => WC()->cart->get_cart_hash(),
                'message' => __('Product removed from cart', 'zero-sense')
            ]);
        } else {
            wp_send_json_error(['message' => __('Could not remove product from cart', 'zero-sense')]);
        }
    }

    public function handleUpdateQuantity(): void
    {
        check_ajax_referer('zs_cart_nonce', 'security');
        
        if (!isset($_POST['cart_item_key']) || !isset($_POST['quantity'])) {
            wp_send_json_error(['message' => 'Missing required parameters']);
            return;
        }
        
        $cartItemKey = sanitize_text_field($_POST['cart_item_key']);
        $quantity = absint($_POST['quantity']);
        
        if (!function_exists('WC') || !isset(WC()->cart)) {
            wp_send_json_error(['message' => 'WooCommerce not available']);
            return;
        }
        
        $this->ensureWcSession();
        
        $updated = WC()->cart->set_quantity($cartItemKey, $quantity);
        
        if ($updated) {
            $this->saveCartSession();
            
            $fragments = apply_filters('woocommerce_add_to_cart_fragments', []);
            wp_send_json_success([
                'fragments' => $fragments,
                'cart_hash' => WC()->cart->get_cart_hash(),
                'cart_total' => WC()->cart->get_cart_total()
            ]);
        } else {
            wp_send_json_error(['message' => __('Could not update quantity', 'zero-sense')]);
        }
    }

    public function handleGetCartTotals(): void
    {
        check_ajax_referer('zs_cart_nonce', 'security');
        
        if (!function_exists('WC') || !isset(WC()->cart)) {
            wp_send_json_error(['message' => 'WooCommerce not available']);
            return;
        }
        
        WC()->cart->calculate_totals();
        
        wp_send_json_success([
            'subtotal' => WC()->cart->get_cart_subtotal(),
            'total' => WC()->cart->get_cart_total()
        ]);
    }

    public function handleGetCartItemKey(): void
    {
        check_ajax_referer('zs_cart_nonce', 'security');
        
        if (!isset($_POST['product_id'])) {
            wp_send_json_error(['message' => 'Missing product ID']);
            return;
        }
        
        $productId = absint($_POST['product_id']);
        
        if (!function_exists('WC') || !isset(WC()->cart)) {
            wp_send_json_error(['message' => 'WooCommerce not available']);
            return;
        }
        
        $cartItemKey = $this->findProductInCart($productId);
        
        if ($cartItemKey) {
            wp_send_json_success(['cart_item_key' => $cartItemKey]);
        } else {
            wp_send_json_error(['message' => 'Product not in cart']);
        }
    }

    public function addCartButtonFragments(array $fragments): array
    {
        if (!function_exists('WC') || !isset(WC()->cart)) {
            return $fragments;
        }
        
        $cartItems = WC()->cart->get_cart();
        $inCartProductIds = [];
        
        foreach ($cartItems as $cartItemKey => $item) {
            $productIdForButton = !empty($item['variation_id']) ? $item['variation_id'] : $item['product_id'];
            $inCartProductIds[$productIdForButton] = $cartItemKey;
        }
        
        foreach ($inCartProductIds as $productId => $cartItemKey) {
            $selector = ".cart-circle-btn[data-product-id='{$productId}']";
            $svgHtml = $this->getCartButtonSvgIcons();
            
            $buttonHtml = sprintf(
                '<button type="button" class="cart-circle-btn in-cart zs-remove-from-cart" data-product-id="%d" data-cart-item-key="%s" data-quantity="1" title="%s" aria-label="%s" role="button" aria-live="polite">%s</button>',
                esc_attr($productId),
                esc_attr($cartItemKey),
                esc_attr__('Remove from cart', 'zero-sense'),
                esc_attr__('Remove from cart', 'zero-sense'),
                $svgHtml
            );
            
            $fragments[$selector] = $buttonHtml;
        }
        
        return $fragments;
    }

    public function extendWcCookieLifetime(): void
    {
        add_filter('wc_session_expiring', function() {
            return 60 * 60 * 24; // 24 hours
        });
        
        add_filter('wc_session_expiration', function() {
            return 60 * 60 * 48; // 48 hours
        });
    }

    public function ensureCartCookies(): void
    {
        if (!is_admin() && function_exists('WC') && 
            isset(WC()->session) && !WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
    }

    private function ensureWcSession(): void
    {
        if (isset(WC()->session) && !WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
    }

    private function saveCartSession(): void
    {
        if (isset(WC()->session)) {
            WC()->session->save_data();
        }
        WC()->cart->calculate_totals();
    }

    private function findProductInCart(int $productId): string
    {
        foreach (WC()->cart->get_cart() as $cartItemKey => $cartItem) {
            if ($cartItem['product_id'] == $productId) {
                return $cartItemKey;
            }
        }
        return '';
    }

    private function getCartButtonSvgIcons(): string
    {
        return '<svg class="icon icon-plus" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>' .
               '<svg class="icon icon-loading" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 4V2A10 10 0 0 0 2 12h2a8 8 0 0 1 8-8z" class="loading-path"/></svg>' .
               '<svg class="icon icon-check" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>' .
               '<svg class="icon icon-remove" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>' .
               '<span class="visually-hidden">Remove from cart</span>';
    }
}
