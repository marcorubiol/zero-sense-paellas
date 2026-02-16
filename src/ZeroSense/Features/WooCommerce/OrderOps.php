<?php
namespace ZeroSense\Features\WooCommerce;

use WC_Order;
use ZeroSense\Core\FeatureInterface;
use ZeroSense\Core\MetaFieldRegistry;

class OrderOps implements FeatureInterface
{
    private const META_OPS_NOTES = 'zs_ops_notes';
    private const META_OPS_MATERIAL = 'zs_ops_material';
    private const META_SHIPPING_EMAIL = '_shipping_email';

    private const OPTION_MATERIAL_SCHEMA = 'zs_ops_material_schema';


    private function getMaterialItems(bool $activeOnly = true): array
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
            $status = isset($row['status']) ? (string) $row['status'] : 'active';

            if ($key === '' || $label === '') {
                continue;
            }
            
            if ($activeOnly && $status !== 'active') {
                continue;
            }

            if (!in_array($type, $allowed, true)) {
                $type = 'text';
            }

            $name = 'ops_material_label_' . $key;
            $translated = apply_filters('wpml_translate_single_string', $label, 'zero-sense', $name);
            $finalLabel = is_string($translated) && $translated !== '' ? $translated : $label;

            $items[$key] = [
                'label' => $finalLabel,
                'type' => $type,
                'status' => $status,
            ];
        }

        return $items;
    }

    private function getMaterialItemsForOrder(int $orderId): array
    {
        $activeItems = $this->getMaterialItems(true);
        
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return $activeItems;
        }
        
        $material = $order->get_meta(self::META_OPS_MATERIAL, true);
        if (!is_array($material) || $material === []) {
            return $activeItems;
        }
        
        $allItems = $this->getMaterialItems(false);
        
        foreach ($allItems as $key => $item) {
            if (isset($activeItems[$key])) {
                continue;
            }
            
            if (isset($material[$key])) {
                $rawValue = $material[$key];
                $value = is_array($rawValue) ? ($rawValue['value'] ?? '') : $rawValue;
                
                $hasData = false;
                if ($item['type'] === 'bool') {
                    $hasData = true;
                } elseif ($item['type'] === 'qty_int') {
                    $hasData = is_numeric($value) && $value > 0;
                } else {
                    $hasData = is_scalar($value) && $value !== '';
                }
                
                if ($hasData) {
                    $activeItems[$key] = $item;
                }
            }
        }
        
        return $activeItems;
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
        $this->registerMetaFields();
        
        add_action('add_meta_boxes', [$this, 'addMetaboxes']);
        add_filter('woocommerce_admin_shipping_fields', [$this, 'registerShippingCustomFields'], 10, 3);
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

        $orderId = $order->get_id();
        $material = $order->get_meta(self::META_OPS_MATERIAL, true);
        $material = is_array($material) ? $material : [];

        $items = $this->getMaterialItemsForOrder($orderId);

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
                $status = isset($item['status']) ? $item['status'] : 'active';
                $isHidden = $status === 'hidden';
                $rowClass = $isHidden ? 'zs-ops-material-hidden-field' : '';
                ?>
                <tr class="<?php echo esc_attr($rowClass); ?>">
                    <th style="width:260px;">
                        <label for="zs_ops_material_<?php echo esc_attr($key); ?>">
                            <?php echo esc_html($item['label']); ?>
                            <?php if ($isHidden) : ?>
                                <span class="zs-ops-hidden-badge" title="<?php esc_attr_e('This field is hidden in schema but has data', 'zero-sense'); ?>"><?php esc_html_e('Hidden', 'zero-sense'); ?></span>
                            <?php endif; ?>
                        </label>
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
        echo '<style>#order_data .order_data_column ._shipping_phone_field{float:right;clear:right}#order_data .order_data_column ._shipping_email_field{margin-top:13px}#order_data .order_data_column ._shipping_location_link_field{width:100%;clear:both}.zs-ops-material-hidden-field{background-color:#fffbf0;border-left:3px solid #f0b849;}.zs-ops-hidden-badge{display:inline-block;background:#f0b849;color:#fff;font-size:10px;font-weight:600;padding:2px 6px;border-radius:3px;margin-left:6px;text-transform:uppercase;}</style>';
    }

    public function registerShippingCustomFields(array $fields, $order = false, string $context = 'edit'): array
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

        $location_link_field = [
            'label' => __('Location Link', 'zero-sense'),
        ];

        if ($context === 'view' && $order instanceof WC_Order) {
            $url = $order->get_meta('_shipping_location_link', true);
            if (!is_string($url) || $url === '') {
                $url = $order->get_meta('zs_event_location_link', true);
            }
            $url = is_string($url) ? $url : '';
            if ($url !== '') {
                $location_link_field['value'] = '<a href="' . esc_url($url) . '" target="_blank">' . esc_html($url) . '</a>';
            }
        } elseif ($context === 'edit' && $order instanceof WC_Order) {
            $url = $order->get_meta('_shipping_location_link', true);
            if (!is_string($url) || $url === '') {
                $url = $order->get_meta('zs_event_location_link', true);
            }
            $location_link_field['value'] = is_string($url) ? $url : '';
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

        $reordered['location_link'] = $location_link_field;

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

        $orderId = $order->get_id();
        
        error_log('[ZS OrderOps] save() called for order: ' . $orderId);
        
        // For saving, use all active fields (not just fields with data)
        // This ensures new fields are saved even if they don't have previous data
        $items = $this->getMaterialItems(true);
        
        error_log('[ZS OrderOps] Active items count: ' . count($items));
        error_log('[ZS OrderOps] Active items keys: ' . implode(', ', array_keys($items)));
        error_log('[ZS OrderOps] POST zs_ops_material isset: ' . (isset($_POST['zs_ops_material']) ? 'YES' : 'NO'));
        
        if ($items !== [] && isset($_POST['zs_ops_material']) && is_array($_POST['zs_ops_material'])) {
            $incoming = $_POST['zs_ops_material'];
            
            error_log('[ZS OrderOps] Incoming POST keys: ' . implode(', ', array_keys($incoming)));
            
            // Get existing data to preserve hidden fields with data
            $existingData = $order->get_meta(self::META_OPS_MATERIAL, true);
            $existingData = is_array($existingData) ? $existingData : [];
            
            $saved = [];

            // Save all active fields from the form
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
            
            // Preserve hidden fields that have data but weren't in the form
            foreach ($existingData as $key => $data) {
                if (!isset($saved[$key]) && isset($data['value']) && $data['value'] !== '') {
                    $saved[$key] = $data;
                }
            }

            error_log('[ZS OrderOps] Saving data with keys: ' . implode(', ', array_keys($saved)));
            $order->update_meta_data(self::META_OPS_MATERIAL, $saved);
        } else {
            error_log('[ZS OrderOps] NOT saving - items empty or POST data missing');
        }

        $order->save();
    }

    private function registerMetaFields(): void
    {
        $registry = MetaFieldRegistry::getInstance();

        $registry->register(self::META_OPS_NOTES, [
            'label' => 'Operational Notes',
            'type' => 'textarea',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'OrderOps',
        ]);

        $registry->register(self::META_OPS_MATERIAL, [
            'label' => 'Material & Logistics',
            'type' => 'json',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'OrderOps',
        ]);

        $registry->register('wpml_language', [
            'label' => 'Order Language',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'WPML',
        ]);

        $registry->register('zs_event_public_token', [
            'label' => 'Event Public Token',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'EventPublicAccess',
        ]);

        $registry->register('_zs_event_media', [
            'label' => 'Event Media',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'MediaUpload',
        ]);

        $registry->register('_zs_last_modified', [
            'label' => 'Last Modified',
            'type' => 'date',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'BricksDynamicTags',
        ]);

        $registry->register('promo_code', [
            'label' => 'Promo Code',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => ['zs_event_promo_code'],
            'feature' => 'EventManagement',
        ]);
    }
}
