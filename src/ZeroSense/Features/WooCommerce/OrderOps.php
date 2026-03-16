<?php
namespace ZeroSense\Features\WooCommerce;

use WC_Order;
use ZeroSense\Core\FeatureInterface;
use ZeroSense\Core\MetaFieldRegistry;
use ZeroSense\Features\WooCommerce\EventManagement\Components\FieldChangeTracker;

class OrderOps implements FeatureInterface
{
    private const META_OPS_NOTES = 'zs_ops_notes';
    private const META_OPS_INFRASTRUCTURE = 'zs_ops_infrastructure';
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
        add_action('woocommerce_process_shop_order_meta', [$this, 'save'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueueUxAssets'], 20);
    }

    public function enqueueUxAssets(): void
    {
        if (!function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();
        if ($screen && in_array($screen->id, ['shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders'], true)) {
            $css_rel = 'assets/css/admin-order-data-ux.css';
            $css_path = defined('ZERO_SENSE_PATH') ? constant('ZERO_SENSE_PATH') . $css_rel : '';
            if ($css_path && file_exists($css_path)) {
                $css_url = defined('ZERO_SENSE_URL') ? constant('ZERO_SENSE_URL') . $css_rel : '';
                $css_ver = (string) filemtime($css_path);
                wp_enqueue_style('zs-admin-order-data-ux', $css_url, [], $css_ver);
            }

            $js_rel = 'assets/js/admin-order-data-ux.js';
            $js_path = defined('ZERO_SENSE_PATH') ? constant('ZERO_SENSE_PATH') . $js_rel : '';
            if ($js_path && file_exists($js_path)) {
                $js_url = defined('ZERO_SENSE_URL') ? constant('ZERO_SENSE_URL') . $js_rel : '';
                $js_ver = (string) filemtime($js_path);
                wp_enqueue_script('zs-admin-order-data-ux', $js_url, ['jquery'], $js_ver, true);
            }
        }
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

    public function registerShippingCustomFields(array $fields, $order = false, string $context = 'edit'): array
    {
        if (isset($fields['first_name'])) {
            $fields['first_name']['label'] = __('Contact First Name', 'zero-sense');
            $fields['first_name']['wrapper_class'] = ($fields['first_name']['wrapper_class'] ?? '') . ' zs-contact-block-start';
        }
        if (isset($fields['last_name'])) {
            $fields['last_name']['label'] = __('Contact Last Name', 'zero-sense');
        }
        if (isset($fields['company'])) {
            $fields['company']['label'] = __('Agency / Company', 'zero-sense');
        }
        if (isset($fields['phone'])) {
            $fields['phone']['label'] = __('Contact Phone', 'zero-sense');
        }

        $email_field = [
            'label' => __('Contact Email', 'zero-sense'),
        ];

        if ($context === 'view' && $order instanceof WC_Order) {
            $raw = $order->get_meta('_shipping_email', true);
            $raw = is_string($raw) ? $raw : '';
            if ($raw !== '') {
                $email_field['value'] = '<a href="' . esc_url('mailto:' . $raw) . '">' . esc_html($raw) . '</a>';
            }
        } elseif ($context === 'edit' && $order instanceof WC_Order) {
            $raw = $order->get_meta('_shipping_email', true);
            $email_field['value'] = is_string($raw) ? $raw : '';
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

        // Add Venue Name field
        $venue_name_field = [
            'label' => __('Venue Name', 'zero-sense'),
            'class' => 'short',
            'wrapper_class' => 'zs-venue-block-start',
        ];
        
        if ($context === 'view' && $order instanceof WC_Order) {
            $venue_name = $order->get_meta('_shipping_venue_name', true);
            $venue_name = is_string($venue_name) ? $venue_name : '';
            if ($venue_name !== '') {
                $venue_name_field['value'] = esc_html($venue_name);
            }
        } elseif ($context === 'edit' && $order instanceof WC_Order) {
            $venue_name = $order->get_meta('_shipping_venue_name', true);
            $venue_name_field['value'] = is_string($venue_name) ? $venue_name : '';
        }
        
        // Add Venue Phone field
        $venue_phone_field = [
            'label' => __('Venue Phone', 'zero-sense'),
            'class' => 'short',
        ];
        
        if ($context === 'view' && $order instanceof WC_Order) {
            $venue_phone = $order->get_meta('_shipping_venue_phone', true);
            $venue_phone = is_string($venue_phone) ? $venue_phone : '';
            if ($venue_phone !== '') {
                $venue_phone_field['value'] = '<a href="' . esc_url('tel:' . $venue_phone) . '">' . esc_html($venue_phone) . '</a>';
            }
        } elseif ($context === 'edit' && $order instanceof WC_Order) {
            $venue_phone = $order->get_meta('_shipping_venue_phone', true);
            $venue_phone_field['value'] = is_string($venue_phone) ? $venue_phone : '';
        }
        
        $fields['email'] = $email_field;
        $fields['venue_name'] = $venue_name_field;
        $fields['venue_phone'] = $venue_phone_field;
        $fields['location_link'] = $location_link_field;

        // Desired order
        $order_keys = [
            'first_name',
            'last_name',
            'company',
            'email',
            'phone',
            'venue_name',
            'venue_phone',
            'address_1',
            'address_2',
            'city',
            'postcode',
            'country',
            'state',
            'location_link',
        ];

        $reordered = [];
        foreach ($order_keys as $key) {
            if (isset($fields[$key])) {
                $reordered[$key] = $fields[$key];
                unset($fields[$key]);
            }
        }

        // Append any remaining fields that might be added by other plugins
        foreach ($fields as $key => $field) {
            $reordered[$key] = $field;
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
            $newValue = wp_kses_post(wp_unslash($_POST['zs_ops_notes']));
            FieldChangeTracker::compareAndTrack($orderId, self::META_OPS_NOTES, $order->get_meta(self::META_OPS_NOTES, true), $newValue);
            $order->update_meta_data(self::META_OPS_NOTES, $newValue);
        }

        // Save Contact Email
        if (isset($_POST['_shipping_email'])) {
            $newValue = sanitize_email((string) $_POST['_shipping_email']);
            $order->update_meta_data('_shipping_email', $newValue);
        }

        // Save Venue Name
        if (isset($_POST['_shipping_venue_name'])) {
            $newValue = sanitize_text_field((string) $_POST['_shipping_venue_name']);
            $order->update_meta_data('_shipping_venue_name', $newValue);
        }

        // Save Venue Phone
        if (isset($_POST['_shipping_venue_phone'])) {
            $newValue = sanitize_text_field((string) $_POST['_shipping_venue_phone']);
            $order->update_meta_data('_shipping_venue_phone', $newValue);
        }

        // Save Location Link
        if (isset($_POST['_shipping_location_link'])) {
            $newValue = esc_url_raw((string) $_POST['_shipping_location_link']);
            $order->update_meta_data('_shipping_location_link', $newValue);
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

        $registry->register(self::META_OPS_INFRASTRUCTURE, [
            'label' => 'Complementary infrastructure',
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
