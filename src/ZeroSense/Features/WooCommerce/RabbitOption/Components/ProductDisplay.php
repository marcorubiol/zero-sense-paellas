<?php
namespace ZeroSense\Features\WooCommerce\RabbitOption\Components;

use ZeroSense\Features\WooCommerce\RabbitOption\Support\MetaKeys;

class ProductDisplay
{
    private static bool $cssRendered = false;

    public function register(): void
    {
        if (is_admin()) {
            return;
        }

        // Inline CSS once
        add_action('wp_head', [$this, 'renderCss']);

        // Radio buttons on single product page
        add_action('woocommerce_before_add_to_cart_button', [$this, 'renderRadio']);

        // Badge on shop loop
        add_action('woocommerce_after_shop_loop_item_title', [$this, 'renderBadge'], 6);
    }

    public function renderCss(): void
    {
        if (self::$cssRendered) {
            return;
        }
        self::$cssRendered = true;
        ?>
        <style>
            .zs-rabbit-choice { margin: 1em 0; padding: 12px 16px; border: 1px solid #ddd; border-radius: 6px; background: #fafafa; }
            .zs-rabbit-choice__label { font-weight: 600; margin: 0 0 8px; font-size: 14px; }
            .zs-rabbit-choice__option { display: block; margin: 4px 0; cursor: pointer; font-size: 14px; }
            .zs-rabbit-choice__option input { margin-right: 6px; }
            .zs-rabbit-badge { display: inline-block; font-size: 12px; background: #fff3e0; color: #e65100; padding: 2px 8px; border-radius: 4px; margin-top: 4px; }
        </style>
        <?php
    }

    public function renderRadio(): void
    {
        global $product;
        if (!$product || !self::productHasRabbitOption($product->get_id())) {
            return;
        }
        ?>
        <div class="zs-rabbit-choice">
            <p class="zs-rabbit-choice__label"><?php esc_html_e('Rabbit', 'zero-sense'); ?></p>
            <label class="zs-rabbit-choice__option">
                <input type="radio" name="zs_rabbit_choice" value="with" checked>
                <?php esc_html_e('With rabbit', 'zero-sense'); ?>
            </label>
            <label class="zs-rabbit-choice__option">
                <input type="radio" name="zs_rabbit_choice" value="without">
                <?php esc_html_e('Without rabbit', 'zero-sense'); ?>
            </label>
        </div>
        <?php
    }

    public function renderBadge(): void
    {
        global $product;
        if (!$product || !self::productHasRabbitOption($product->get_id())) {
            return;
        }
        echo '<span class="zs-rabbit-badge">' . esc_html__('🐇 Rabbit option', 'zero-sense') . '</span>';
    }

    public static function productHasRabbitOption(int $productId): bool
    {
        // Resolve variation to parent
        $parentId = wp_get_post_parent_id($productId);
        $checkId  = $parentId ? $parentId : $productId;

        // WPML: resolve to original (default language) product
        if (function_exists('apply_filters') && defined('ICL_SITEPRESS_VERSION')) {
            $defaultLang = apply_filters('wpml_default_language', null);
            $originalId  = apply_filters('wpml_object_id', $checkId, 'product', true, $defaultLang);
            if ($originalId) {
                $checkId = (int) $originalId;
            }
        }

        return get_post_meta($checkId, MetaKeys::PRODUCT_HAS_RABBIT_OPTION, true) === 'yes';
    }
}
