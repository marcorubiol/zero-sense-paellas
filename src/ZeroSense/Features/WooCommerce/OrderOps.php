<?php
namespace ZeroSense\Features\WooCommerce;

use WC_Order;
use ZeroSense\Core\FeatureInterface;

class OrderOps implements FeatureInterface
{
    private const META_OPS_NOTES = 'zs_ops_notes';
    private const META_OPS_MATERIAL = 'zs_ops_material';
    private const META_SHIPPING_EMAIL = 'zs_shipping_email';
    private const META_SHIPPING_EMAIL_WOO = '_shipping_email';

    private const MATERIAL_LEGACY_KEY_MAP = [
        'black_tablecloths' => 'teles_negres',
        'cart' => 'carreto',
        'work_tables' => 'taules_treball',
        'paella_pans' => 'paelles',
        'burners' => 'cremadors',
        'tripods_legs' => 'potes_tripodes',
        'butane' => 'buta',
        'hose' => 'manguera',
        'parasols' => 'para_sols',
        'tent' => 'carpa',
        'lighting' => 'llum',
        'water_fountain_8l' => 'font_aigua_8l',
        'trash_buckets' => 'poals_fems',
        'coolers' => 'neveres',
        'other' => 'altres',
    ];

    private const MATERIAL_ITEMS = [
        'vehicle' => ['label' => 'Vehicle', 'type' => 'text'],
        'black_tablecloths' => ['label' => 'Black tablecloths', 'type' => 'qty_int'],
        'cart' => ['label' => 'Cart', 'type' => 'bool'],
        'work_tables' => ['label' => 'Work tables', 'type' => 'qty_int'],
        'paella_pans' => ['label' => 'Paella pans', 'type' => 'qty_int'],
        'burners' => ['label' => 'Burners', 'type' => 'qty_int'],
        'tripods_legs' => ['label' => 'Tripods / legs', 'type' => 'qty_int'],
        'butane' => ['label' => 'Butane', 'type' => 'qty_int'],
        'hose' => ['label' => 'Hose', 'type' => 'bool'],
        'parasols' => ['label' => 'Parasols', 'type' => 'qty_int'],
        'tent' => ['label' => 'Tent', 'type' => 'bool'],
        'lighting' => ['label' => 'Lighting', 'type' => 'qty_int'],
        'water_fountain_8l' => ['label' => 'Water fountain (8L)', 'type' => 'qty_int'],
        'trash_buckets' => ['label' => 'Trash buckets', 'type' => 'qty_int'],
        'coolers' => ['label' => 'Coolers', 'type' => 'qty_int'],
        'other' => ['label' => 'Other', 'type' => 'textarea'],
    ];

    public function getName(): string
    {
        return __('Order Ops', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Adds internal ops notes and material & logistics fields to orders.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isToggleable(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function init(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaboxes']);
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'renderShippingEmailField']);
        add_action('woocommerce_process_shop_order_meta', [$this, 'save'], 20);
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce', 'is_admin'];
    }

    public function addMetaboxes(): void
    {
        $screen = wc_get_page_screen_id('shop-order');

        add_meta_box(
            'zs_ops_notes',
            __('Ops Notes', 'zero-sense'),
            [$this, 'renderOpsNotesMetabox'],
            $screen,
            'normal',
            'high'
        );

        add_meta_box(
            'zs_ops_material',
            __('Material & Logistics', 'zero-sense'),
            [$this, 'renderOpsMaterialMetabox'],
            $screen,
            'normal',
            'default'
        );
    }

    public function renderOpsNotesMetabox($postOrOrder): void
    {
        $order = $postOrOrder instanceof \WP_Post
            ? wc_get_order($postOrOrder->ID)
            : $postOrOrder;

        if (!$order instanceof WC_Order) {
            return;
        }

        $notes = $order->get_meta(self::META_OPS_NOTES, true);
        $notes = is_string($notes) ? $notes : '';

        wp_nonce_field('zs_order_ops_save', 'zs_order_ops_nonce');
        ?>
        <textarea name="zs_ops_notes" rows="6" style="width:100%;" class="widefat"><?php echo esc_textarea($notes); ?></textarea>
        <?php
    }

    public function renderOpsMaterialMetabox($postOrOrder): void
    {
        $order = $postOrOrder instanceof \WP_Post
            ? wc_get_order($postOrOrder->ID)
            : $postOrOrder;

        if (!$order instanceof WC_Order) {
            return;
        }

        $material = $order->get_meta(self::META_OPS_MATERIAL, true);
        $material = is_array($material) ? $material : [];

        wp_nonce_field('zs_order_ops_save', 'zs_order_ops_nonce');
        ?>
        <table class="widefat striped" style="margin-top:8px;">
            <tbody>
            <?php foreach (self::MATERIAL_ITEMS as $key => $item) :
                $legacyKey = self::MATERIAL_LEGACY_KEY_MAP[$key] ?? null;
                $raw = $material[$key] ?? ($legacyKey ? ($material[$legacyKey] ?? []) : []);
                $value = is_array($raw) ? ($raw['value'] ?? '') : $raw;
                $type = $item['type'];
                ?>
                <tr>
                    <th style="width:260px;">
                        <label for="zs_ops_material_<?php echo esc_attr($key); ?>"><?php echo esc_html($item['label']); ?></label>
                    </th>
                    <td>
                        <?php if ($type === 'bool') : ?>
                            <input type="hidden" name="zs_ops_material[<?php echo esc_attr($key); ?>]" value="0">
                            <label>
                                <input
                                    type="checkbox"
                                    id="zs_ops_material_<?php echo esc_attr($key); ?>"
                                    name="zs_ops_material[<?php echo esc_attr($key); ?>]"
                                    value="1"
                                    <?php checked((string) $value, '1'); ?>
                                >
                            </label>
                        <?php elseif ($type === 'qty_int') : ?>
                            <input
                                type="number"
                                id="zs_ops_material_<?php echo esc_attr($key); ?>"
                                name="zs_ops_material[<?php echo esc_attr($key); ?>]"
                                value="<?php echo esc_attr((string) $value); ?>"
                                min="0"
                                step="1"
                                class="small-text"
                            >
                        <?php elseif ($type === 'textarea') : ?>
                            <textarea
                                id="zs_ops_material_<?php echo esc_attr($key); ?>"
                                name="zs_ops_material[<?php echo esc_attr($key); ?>]"
                                rows="3"
                                style="width:100%;"
                                class="widefat"><?php echo esc_textarea(is_string($value) ? $value : ''); ?></textarea>
                        <?php else : ?>
                            <input
                                type="text"
                                id="zs_ops_material_<?php echo esc_attr($key); ?>"
                                name="zs_ops_material[<?php echo esc_attr($key); ?>]"
                                value="<?php echo esc_attr(is_string($value) ? $value : ''); ?>"
                                class="widefat"
                            >
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public function renderShippingEmailField($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        $raw = $order->get_meta(self::META_SHIPPING_EMAIL, true);
        if (!is_string($raw) || $raw === '') {
            $raw = $order->get_meta(self::META_SHIPPING_EMAIL_WOO, true);
        }
        $email = is_string($raw) ? $raw : '';

        wp_nonce_field('zs_order_ops_save', 'zs_order_ops_nonce');
        ?>
        <p class="form-field form-field-wide">
            <label for="zs_shipping_email"><?php esc_html_e('On-site contact email', 'zero-sense'); ?></label>
            <input type="email" class="short" style="width:100%;" name="zs_shipping_email" id="zs_shipping_email" value="<?php echo esc_attr($email); ?>">
        </p>
        <?php
    }

    public function save($orderId): void
    {
        if (!isset($_POST['zs_order_ops_nonce']) || !wp_verify_nonce($_POST['zs_order_ops_nonce'], 'zs_order_ops_save')) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        if (isset($_POST['zs_ops_notes'])) {
            $order->update_meta_data(self::META_OPS_NOTES, sanitize_textarea_field((string) $_POST['zs_ops_notes']));
        }

        if (isset($_POST['zs_ops_material']) && is_array($_POST['zs_ops_material'])) {
            $incoming = $_POST['zs_ops_material'];
            $saved = [];

            foreach (self::MATERIAL_ITEMS as $key => $item) {
                $type = $item['type'];
                $legacyKey = self::MATERIAL_LEGACY_KEY_MAP[$key] ?? null;
                $raw = $incoming[$key] ?? ($legacyKey ? ($incoming[$legacyKey] ?? null) : null);

                if ($type === 'bool') {
                    $value = $raw === '1' ? '1' : '0';
                } elseif ($type === 'qty_int') {
                    $value = (string) absint($raw);
                } elseif ($type === 'textarea') {
                    $value = sanitize_textarea_field(is_string($raw) ? $raw : '');
                } else {
                    $value = sanitize_text_field(is_string($raw) ? $raw : '');
                }

                $saved[$key] = ['value' => $value];
            }

            $order->update_meta_data(self::META_OPS_MATERIAL, $saved);
        }

        if (isset($_POST['zs_shipping_email'])) {
            $email = sanitize_email(wp_unslash((string) $_POST['zs_shipping_email']));
            $order->update_meta_data(self::META_SHIPPING_EMAIL, $email);
            $order->update_meta_data(self::META_SHIPPING_EMAIL_WOO, $email);
        }

        $order->save();
    }
}
