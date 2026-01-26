<?php
namespace ZeroSense\Features\WooCommerce\Performance;

use ZeroSense\Core\FeatureInterface;

class CartFragmentsOff implements FeatureInterface
{
    public function getName(): string
    {
        return 'Cart Fragments Off (Empty Cart on Shop)';
    }

    public function getDescription(): string
    {
        return 'Improves first-load performance by disabling wc-cart-fragments on shop/archive when the cart is empty. Fragments remain enabled on product, cart, and checkout pages.';
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isToggleable(): bool
    {
        return true;
    }

    public function isEnabled(): bool
    {
        // Default enabled
        return (bool) get_option($this->getOptionName(), true);
    }

    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // Frontend only
        if (is_admin() || (defined('WP_CLI') && WP_CLI)) {
            return;
        }

        // Dequeue wc-cart-fragments on shop/archive when cart is empty
        add_action('wp_enqueue_scripts', [$this, 'maybeDisableFragments'], 100);
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce'];
    }

    public function getOptionName(): string
    {
        return 'zs_woocommerce_performance_cartfragmentsoff';
    }

    public function maybeDisableFragments(): void
    {
        // Safety checks
        if (!function_exists('is_shop') || !function_exists('WC')) {
            return;
        }

        // Only on shop/archive views
        $isShopArchive = is_shop() || is_product_taxonomy() || is_post_type_archive('product');
        if (!$isShopArchive) {
            return;
        }

        // Keep fragments on single product pages
        if (is_product()) {
            return;
        }

        // Require a cart instance
        $wc = WC();
        if (!$wc || !isset($wc->cart) || !is_object($wc->cart)) {
            return;
        }

        // Disable only when cart is empty
        if (method_exists($wc->cart, 'is_empty') && $wc->cart->is_empty()) {
            // Dequeue and deregister WooCommerce cart fragments
            wp_dequeue_script('wc-cart-fragments');
            wp_deregister_script('wc-cart-fragments');
        }
    }
}
