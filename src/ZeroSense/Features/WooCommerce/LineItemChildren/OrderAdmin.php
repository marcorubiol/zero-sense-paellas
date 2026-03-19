<?php
declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce\LineItemChildren;

use WC_Abstract_Order;
use WC_Order;
use WC_Order_Factory;
use WC_Order_Item_Product;
use ZeroSense\Features\WooCommerce\Recipes\RecipeCalculator;

class OrderAdmin
{
    private static bool $isRecalculating = false;
    private static bool $scriptPrinted   = false;

    public function register(): void
    {
        add_action('woocommerce_after_order_itemmeta', [$this, 'renderChildrenInput'], 10, 3);
        add_action('woocommerce_after_order_object_save', [$this, 'saveChildrenFromOrder'], 10, 1);
    }

    public function renderChildrenInput(int $itemId, $item, $product): void
    {
        if (!$item instanceof WC_Order_Item_Product) {
            return;
        }

        $productObj = $item->get_product();
        if (!$productObj) {
            return;
        }

        $recipeId = RecipeCalculator::resolveRecipeId($item, $productObj);
        if ($recipeId <= 0) {
            return;
        }

        if (get_post_meta($recipeId, RecipeCalculator::META_NEEDS_PAELLA, true) !== '1') {
            return;
        }

        $qty       = (int) $item->get_quantity();
        $current   = (int) ($item->get_meta(RecipeCalculator::META_ITEM_CHILDREN, true) ?: 0);
        $unitPrice = $qty > 0 ? ((float) $item->get_subtotal() / $qty) : 0.0;
        $adults    = max(0, $qty - $current);
        ?>
        <div class="zs-item-children-edit" style="margin-top:6px; display:flex; align-items:center; gap:8px; font-size:12px; flex-wrap:wrap;">
            <label style="font-weight:600; color:#555;" for="zs_ic_<?php echo esc_attr((string) $itemId); ?>">
                <?php esc_html_e('Children (5-8)', 'zero-sense'); ?>:
            </label>
            <input
                type="number"
                id="zs_ic_<?php echo esc_attr((string) $itemId); ?>"
                name="zs_item_children[<?php echo esc_attr((string) $itemId); ?>]"
                value="<?php echo esc_attr((string) $current); ?>"
                min="0"
                max="<?php echo esc_attr((string) $qty); ?>"
                style="width:60px; padding:2px 4px; font-size:12px;"
            />
            <?php if ($unitPrice > 0.0) : ?>
            <span style="color:#888;">
                <?php echo esc_html((string) $adults); ?> adult
                <?php if ($current > 0) : ?>
                    + <?php echo esc_html((string) $current); ?> child
                    (<?php echo wp_kses_post(wc_price($unitPrice * 0.6)); ?>/u)
                    = <?php echo wp_kses_post(wc_price($adults * $unitPrice + $current * $unitPrice * 0.6)); ?>
                <?php endif; ?>
            </span>
            <?php endif; ?>
            <button type="button" class="button zs-apply-children-btn" data-item="<?php echo esc_attr((string) $itemId); ?>" style="padding:2px 6px; font-size:11px;" title="<?php esc_attr_e('Re-apply children discount', 'zero-sense'); ?>">&#8635;</button>
        </div>
        <?php
        if (!self::$scriptPrinted) {
            self::$scriptPrinted = true;
            ?>
            <script>
            (function($){
                function applyChildrenDiscount(itemId, children) {
                    var $sub   = $('input[name="line_subtotal[' + itemId + ']"]');
                    var $total = $('input[name="line_total['    + itemId + ']"]');
                    var $qty   = $('input[name="order_item_qty[' + itemId + ']"]');
                    if (!$sub.length || !$total.length) return;
                    var subtotal = parseFloat($sub.val()) || 0;
                    var qty      = parseFloat($qty.val())  || 1;
                    if (subtotal <= 0 || qty <= 0) return;
                    $total.val( ((subtotal / qty) * (qty - 0.4 * children)).toFixed(2) );
                }
                function itemIdFrom($input) {
                    var m = ($input.attr('name') || '').match(/zs_item_children\[(\d+)\]/);
                    return m ? m[1] : null;
                }
                $(document)
                    .on('change input', 'input[name^="zs_item_children["]', function(){
                        var id = itemIdFrom($(this));
                        if (id) applyChildrenDiscount(id, Math.max(0, parseInt($(this).val()) || 0));
                    })
                    .on('click', 'button.calculate-action', function(){
                        $('input[name^="zs_item_children["]').each(function(){
                            var id = itemIdFrom($(this));
                            if (id) applyChildrenDiscount(id, Math.max(0, parseInt($(this).val()) || 0));
                        });
                    })
                    .on('click', '.zs-apply-children-btn', function(){
                        var id = $(this).data('item');
                        var children = Math.max(0, parseInt($('input[name="zs_item_children[' + id + ']"]').val()) || 0);
                        applyChildrenDiscount(id, children);
                    });
            })(jQuery);
            </script>
            <?php
        }
    }

    public function saveChildrenFromOrder(WC_Abstract_Order $order): void
    {
        if (self::$isRecalculating) {
            return;
        }

        if (!is_admin()) {
            return;
        }

        $childrenData = [];
        if (!empty($_POST['zs_item_children']) && is_array($_POST['zs_item_children'])) {
            $childrenData = $_POST['zs_item_children'];
        } elseif (!empty($_POST['items']) && is_string($_POST['items'])) {
            parse_str(wp_unslash($_POST['items']), $parsed);
            if (!empty($parsed['zs_item_children']) && is_array($parsed['zs_item_children'])) {
                $childrenData = $parsed['zs_item_children'];
            }
        }

        if (empty($childrenData)) {
            return;
        }

        if (!$order instanceof WC_Order) {
            return;
        }

        $hasChanges = false;

        foreach ($childrenData as $itemId => $rawChildren) {
            $itemId   = (int) $itemId;
            $children = max(0, (int) $rawChildren);

            $item = WC_Order_Factory::get_order_item($itemId);
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $qty      = (int) $item->get_quantity();
            $children = min($children, $qty);

            $previousChildren = (int) ($item->get_meta(RecipeCalculator::META_ITEM_CHILDREN, true) ?: 0);
            $childrenChanged  = ($children !== $previousChildren);

            $item->update_meta_data(RecipeCalculator::META_ITEM_CHILDREN, $children);

            if ($childrenChanged) {
                $subtotal  = (float) $item->get_subtotal();
                $unitPrice = $qty > 0 ? $subtotal / $qty : 0.0;
                $newTotal  = $unitPrice * ($qty - 0.4 * $children);
                $item->set_total($newTotal);
            }

            $item->save();

            $hasChanges = true;
        }

        if ($hasChanges) {
            self::$isRecalculating = true;
            $order->calculate_totals();
            $order->save();
            self::$isRecalculating = false;
        }
    }
}
