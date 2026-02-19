<?php
namespace ZeroSense\Features\WooCommerce\RabbitOption\Components;

use WC_Order_Factory;
use WC_Order_Item_Product;
use ZeroSense\Features\WooCommerce\RabbitOption\Support\MetaKeys;

class OrderAdmin
{
    public function register(): void
    {
        // Readable label for the rabbit choice in admin order item meta
        add_filter('woocommerce_order_item_display_meta_key', [$this, 'formatMetaKey'], 10, 3);
        add_filter('woocommerce_order_item_display_meta_value', [$this, 'formatMetaValue'], 10, 3);

        // Editable select after each line item in admin
        add_action('woocommerce_after_order_itemmeta', [$this, 'renderEditableChoice'], 10, 3);
        // woocommerce_after_order_object_save fires for both HPOS and legacy post-based orders
        add_action('woocommerce_after_order_object_save', [$this, 'saveEditableChoiceFromOrder'], 10, 1);
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

        $current = $item->get_meta(MetaKeys::RABBIT_CHOICE, true) ?: 'with';
        $fieldName = 'zs_rabbit_choice[' . $itemId . ']';
        ?>
        <div class="zs-rabbit-choice-edit" style="margin-top:6px; display:flex; align-items:center; gap:8px; font-size:12px;">
            <label style="font-weight:600; color:#555;"><?php esc_html_e('Rabbit', 'zero-sense'); ?>:</label>
            <select name="<?php echo esc_attr($fieldName); ?>" style="font-size:12px; padding:2px 4px;">
                <option value="with"    <?php selected($current, 'with'); ?>><?php esc_html_e('With rabbit', 'zero-sense'); ?></option>
                <option value="without" <?php selected($current, 'without'); ?>><?php esc_html_e('Without rabbit', 'zero-sense'); ?></option>
            </select>
        </div>
        <?php
    }

    public function saveEditableChoiceFromOrder(\WC_Abstract_Order $order): void
    {
        if (!is_admin() || empty($_POST['zs_rabbit_choice']) || !is_array($_POST['zs_rabbit_choice'])) {
            return;
        }

        foreach ($_POST['zs_rabbit_choice'] as $itemId => $choice) {
            $itemId = (int) $itemId;
            $choice = in_array($choice, ['with', 'without'], true) ? $choice : 'with';

            $item = WC_Order_Factory::get_order_item($itemId);
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $item->update_meta_data(MetaKeys::RABBIT_CHOICE, $choice);
            $item->save();
        }
    }
}
