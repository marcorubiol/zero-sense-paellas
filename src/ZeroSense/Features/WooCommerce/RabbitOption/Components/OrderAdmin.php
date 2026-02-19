<?php
namespace ZeroSense\Features\WooCommerce\RabbitOption\Components;

use WC_Order_Item_Product;
use ZeroSense\Features\WooCommerce\RabbitOption\Support\MetaKeys;

class OrderAdmin
{
    public function register(): void
    {
        // Readable label for the rabbit choice in admin order item meta
        add_filter('woocommerce_order_item_display_meta_key', [$this, 'formatMetaKey'], 10, 3);
        add_filter('woocommerce_order_item_display_meta_value', [$this, 'formatMetaValue'], 10, 3);

        // Editable toggle after each line item in admin
        add_action('woocommerce_after_order_itemmeta', [$this, 'renderEditableChoice'], 10, 3);
        // Classic editor + HPOS: both fire on "Update Order"
        add_action('woocommerce_process_shop_order_meta', [$this, 'saveEditableChoice'], 20);
        add_action('save_post_shop_order', [$this, 'saveEditableChoice'], 20);
    }

    public function formatMetaKey(string $displayKey, $meta, $item): string
    {
        if (isset($meta->key) && $meta->key === MetaKeys::RABBIT_CHOICE) {
            return __('Rabbit', 'zero-sense');
        }
        return $displayKey;
    }

    public function formatMetaValue(string $displayValue, $meta, $item): string
    {
        if (isset($meta->key) && $meta->key === MetaKeys::RABBIT_CHOICE) {
            return $displayValue === 'with'
                ? '✓ ' . __('With rabbit', 'zero-sense')
                : '✗ ' . __('Without rabbit', 'zero-sense');
        }
        return $displayValue;
    }

    public function renderEditableChoice(int $itemId, $item, $product): void
    {
        if (!$item instanceof WC_Order_Item_Product) {
            return;
        }

        $productObj = $item->get_product();
        if (!$productObj) {
            return;
        }

        $productId = $productObj->get_id();

        // WPML: resolve to original
        if (defined('ICL_SITEPRESS_VERSION')) {
            $defaultLang = apply_filters('wpml_default_language', null);
            $originalId  = apply_filters('wpml_object_id', $productId, 'product', true, $defaultLang);
            if ($originalId) {
                $productId = (int) $originalId;
            }
        }

        if (get_post_meta($productId, MetaKeys::PRODUCT_HAS_RABBIT_OPTION, true) !== 'yes') {
            return;
        }

        $current   = $item->get_meta(MetaKeys::RABBIT_CHOICE, true) ?: 'with';
        $checked   = $current === 'without' ? ' checked' : '';
        $fieldName = 'zs_rabbit_choice[' . $itemId . ']';
        $label     = esc_html__('Sin conejo', 'zero-sense');
        echo '<div style="margin-top:8px;">'
            . '<label class="zs-rabbit-toggle" aria-label="' . esc_attr__('Sin conejo', 'zero-sense') . '">'
            . '<input type="checkbox" name="' . esc_attr($fieldName) . '" value="without" class="zs-rabbit-toggle__input"' . $checked . '>'
            . '<span class="zs-rabbit-toggle__track">'
            . '<span class="zs-rabbit-toggle__thumb"></span>'
            . '</span>'
            . '<span class="zs-rabbit-toggle__label">' . $label . '</span>'
            . '</label>'
            . '</div>';
        static $cssOnce = false;
        if (!$cssOnce) {
            $cssOnce = true;
            echo '<style>'
                . '.zs-rabbit-toggle{display:inline-flex;align-items:center;gap:8px;cursor:pointer;user-select:none;font-size:13px;}'
                . '.zs-rabbit-toggle__input{position:absolute;opacity:0;width:0;height:0;}'
                . '.zs-rabbit-toggle__track{position:relative;display:inline-block;width:40px;height:22px;background:#ccc;border-radius:11px;transition:background .2s;flex-shrink:0;}'
                . '.zs-rabbit-toggle__input:checked+.zs-rabbit-toggle__track{background:#2271b1;}'
                . '.zs-rabbit-toggle__thumb{position:absolute;top:3px;left:3px;width:16px;height:16px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.3);}'
                . '.zs-rabbit-toggle__input:checked+.zs-rabbit-toggle__track .zs-rabbit-toggle__thumb{transform:translateX(18px);}'
                . '</style>';
        }
    }

    public function saveEditableChoice(int $orderId): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        static $ran = false;
        if ($ran) {
            return;
        }
        $ran = true;

        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        // Checkbox only posts when checked — iterate all items to handle unchecked (= 'with')
        $posted = isset($_POST['zs_rabbit_choice']) && is_array($_POST['zs_rabbit_choice'])
            ? $_POST['zs_rabbit_choice']
            : [];

        foreach ($order->get_items() as $itemId => $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $productObj = $item->get_product();
            if (!$productObj) {
                continue;
            }

            $productId = $productObj->get_id();
            if (defined('ICL_SITEPRESS_VERSION')) {
                $defaultLang = apply_filters('wpml_default_language', null);
                $originalId  = apply_filters('wpml_object_id', $productId, 'product', true, $defaultLang);
                if ($originalId) {
                    $productId = (int) $originalId;
                }
            }

            if (get_post_meta($productId, MetaKeys::PRODUCT_HAS_RABBIT_OPTION, true) !== 'yes') {
                continue;
            }

            $choice = isset($posted[$itemId]) && $posted[$itemId] === 'without' ? 'without' : 'with';
            $item->update_meta_data(MetaKeys::RABBIT_CHOICE, $choice);
            $item->save();
        }
    }
}
