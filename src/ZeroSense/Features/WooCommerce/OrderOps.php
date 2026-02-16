<?php
namespace ZeroSense\Features\WooCommerce;

use WC_Order;
use ZeroSense\Core\FeatureInterface;
use ZeroSense\Core\MetaFieldRegistry;
use ZeroSense\Features\WooCommerce\EventManagement\Components\FieldChangeTracker;

class OrderOps implements FeatureInterface
{
    private const META_OPS_NOTES = 'zs_ops_notes';
    private const META_OPS_MATERIAL = 'zs_ops_material';
    private const META_SHIPPING_EMAIL = '_shipping_email';

    public function getName(): string
    {
        return __('Order Ops', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Adds internal operational notes to orders.', 'zero-sense');
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

    public function shippingFieldsCss(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['shop_order', wc_get_page_screen_id('shop-order')], true)) {
            return;
        }
        echo '<style>#order_data .order_data_column ._shipping_phone_field{float:right;clear:right}#order_data .order_data_column ._shipping_email_field{margin-top:13px}#order_data .order_data_column ._shipping_location_link_field{width:100%;clear:both}</style>';
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
            $newValue = sanitize_textarea_field((string) $_POST['zs_ops_notes']);
            FieldChangeTracker::compareAndTrack($orderId, self::META_OPS_NOTES, $order->get_meta(self::META_OPS_NOTES, true), $newValue);
            $order->update_meta_data(self::META_OPS_NOTES, $newValue);
        }
    }

    private function registerMetaFields(): void
    {
        $registry = MetaFieldRegistry::getInstance();

        $registry->register(self::META_OPS_NOTES, [
            'label' => 'Operational notes',
            'type' => 'textarea',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'OrderOps',
        ]);

        $registry->register(self::META_OPS_MATERIAL, [
            'label' => 'Material & logistics',
            'type' => 'json',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'OrderOps',
        ]);

        $registry->register('wpml_language', [
            'label' => 'Order language',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'WPML',
        ]);

        $registry->register('zs_event_public_token', [
            'label' => 'Event public token',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'EventPublicAccess',
        ]);

        $registry->register('_zs_event_media', [
            'label' => 'Event media',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'MediaUpload',
        ]);

        $registry->register('_zs_last_modified', [
            'label' => 'Last modified',
            'type' => 'date',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'BricksDynamicTags',
        ]);

        $registry->register('promo_code', [
            'label' => 'Promo code',
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => ['zs_event_promo_code'],
            'feature' => 'EventManagement',
        ]);
    }
}
