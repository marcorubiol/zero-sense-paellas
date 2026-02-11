<?php
namespace ZeroSense\Features\WooCommerce;

use WC_Order;
use ZeroSense\Core\FeatureInterface;

class OrderOps implements FeatureInterface
{
    private const META_OPS_NOTES = 'zs_ops_notes';
    private const META_OPS_MATERIAL = 'zs_ops_material';
    private const META_SHIPPING_EMAIL = '_shipping_email';

    private const OPTION_MATERIAL_SCHEMA = 'zs_ops_material_schema';


    private function getMaterialItems(): array
    {
        $schema = get_option(self::OPTION_MATERIAL_SCHEMA, null);
        if (!is_array($schema) || $schema === []) {
            return [];
        }

        $allowed = ['text', 'qty_int', 'bool', 'textarea'];
        $items = [];

        foreach ($schema as $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = isset($row['key']) ? sanitize_key((string) $row['key']) : '';
            $label = isset($row['label']) ? sanitize_text_field((string) $row['label']) : '';
            $type = isset($row['type']) ? sanitize_key((string) $row['type']) : 'text';

            if ($key === '' || $label === '') {
                continue;
            }

            if (!in_array($type, $allowed, true)) {
                $type = 'text';
            }

            $name = 'ops_material_label_' . $key;
            $translated = apply_filters('wpml_translate_single_string', $label, 'zero-sense', $name);
            $finalLabel = is_string($translated) && $translated !== '' ? $translated : $label;

            $items[$key] = ['label' => $finalLabel, 'type' => $type];
        }

        return $items;
    }

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
        add_filter('woocommerce_admin_shipping_fields', [$this, 'registerShippingEmailField'], 10, 3);
        add_action('admin_head', [$this, 'shippingFieldsCss']);
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
            __('Operational Notes', 'zero-sense'),
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

        $items = $this->getMaterialItems();

        wp_nonce_field('zs_order_ops_save', 'zs_order_ops_nonce');
        ?>
        <table class="widefat striped" style="margin-top:8px;">
            <tbody>
            <?php if ($items === []) : 
                $url = admin_url('admin.php?page=zs_ops_material_schema');
            ?>
                <tr>
                    <td colspan="2" style="padding:12px;">
                        <?php echo esc_html__('Material & Logistics fields are not configured yet.', 'zero-sense'); ?> 
                        <a href="<?php echo esc_url($url); ?>"><?php echo esc_html__('Configure schema', 'zero-sense'); ?></a>
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($items as $key => $item) :
                $raw = $material[$key] ?? [];
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
                            <label class="zs-switch">
                                <input
                                    type="checkbox"
                                    id="zs_ops_material_<?php echo esc_attr($key); ?>"
                                    name="zs_ops_material[<?php echo esc_attr($key); ?>]"
                                    value="1"
                                    <?php checked((string) $value, '1'); ?>
                                >
                                <span class="zs-slider"></span>
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

    public function shippingFieldsCss(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['shop_order', wc_get_page_screen_id('shop-order')], true)) {
            return;
        }
        echo '<style>#order_data ._shipping_phone_field{float:right;clear:right}#order_data ._shipping_email_field{margin-top:13px}</style>';
    }

    public function registerShippingEmailField(array $fields, $order = false, string $context = 'edit'): array
    {
        $email_field = [
            'label' => __('Email address', 'woocommerce'),
        ];

        if ($context === 'view' && $order instanceof WC_Order) {
            $raw = $order->get_meta('_shipping_email', true);
            $raw = is_string($raw) ? $raw : '';
            if ($raw !== '') {
                $email_field['value'] = '<a href="' . esc_url('mailto:' . $raw) . '">' . esc_html($raw) . '</a>';
            }
        }

        $reordered = [];
        foreach ($fields as $key => $field) {
            if ($key === 'phone') {
                $reordered['email'] = $email_field;
            }
            $reordered[$key] = $field;
        }

        if (!isset($reordered['email'])) {
            $reordered['email'] = $email_field;
        }

        return $reordered;
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

        $items = $this->getMaterialItems();
        if ($items !== [] && isset($_POST['zs_ops_material']) && is_array($_POST['zs_ops_material'])) {
            $incoming = $_POST['zs_ops_material'];
            $saved = [];

            foreach ($items as $key => $item) {
                $type = $item['type'];
                $raw = $incoming[$key] ?? null;

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

        $order->save();
    }
}
