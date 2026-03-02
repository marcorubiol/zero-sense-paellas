<?php
declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce\EventManagement\Components;

use WC_Order;
use ZeroSense\Core\MetaFieldRegistry;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\EventManagement\Support\FieldOptions;
use ZeroSense\Features\Integrations\Flowmattic\ApiExtension;
use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys as DepositMetaKeys;
use ZeroSense\Features\WooCommerce\Deposits\Support\Utils as DepositUtils;

class DataExposureMetabox
{
    public function register(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('add_meta_boxes', [$this, 'addMetabox']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMetabox(): void
    {
        $screen = wc_get_page_screen_id('shop-order');

        add_meta_box(
            'zs_data_exposure',
            __('Exposed Fields', 'zero-sense'),
            [$this, 'render'],
            $screen,
            'side',
            'low'
        );
    }

    public function enqueueAssets($hook): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            return;
        }

        $css_path = plugin_dir_path(ZERO_SENSE_FILE) . 'assets/css/admin-data-exposure.css';
        if (file_exists($css_path)) {
            wp_enqueue_style(
                'zs-data-exposure',
                plugin_dir_url(ZERO_SENSE_FILE) . 'assets/css/admin-data-exposure.css',
                [],
                (string) filemtime($css_path)
            );
        }

        $js_path = plugin_dir_path(ZERO_SENSE_FILE) . 'assets/js/admin-data-exposure.js';
        if (file_exists($js_path)) {
            wp_enqueue_script(
                'zs-data-exposure',
                plugin_dir_url(ZERO_SENSE_FILE) . 'assets/js/admin-data-exposure.js',
                [],
                (string) filemtime($js_path),
                true
            );
        }
    }

    public function render($postOrOrder): void
    {
        $order = $postOrOrder instanceof \WP_Post
            ? wc_get_order($postOrOrder->ID)
            : $postOrOrder;

        if (!$order instanceof WC_Order) {
            echo '<p>' . esc_html__('Invalid order.', 'zero-sense') . '</p>';
            return;
        }

        $fieldsData = $this->getFieldsData($order);

        if (empty($fieldsData)) {
            echo '<p style="color:#666;font-size:12px;">' . esc_html__('No exposed fields found.', 'zero-sense') . '</p>';
            return;
        }

        echo '<div class="zs-exposure-fields">';
        
        foreach ($fieldsData as $field) {
            $this->renderField($field);
        }
        
        echo '</div>';
    }

    private function renderField(array $field): void
    {
        $key = $field['key'] ?? '';
        $value = $field['value'] ?? '';
        $tag = $field['tag'] ?? '';
        $truncated = $field['truncated'] ?? false;
        $displayValue = $field['display_value'] ?? $value;

        ?>
        <div class="zs-exposure-field">
            <div class="zs-exposure-key"><?php echo esc_html($key); ?></div>
            <div class="zs-exposure-value <?php echo $truncated ? 'is-truncated' : ''; ?>" 
                 data-full-value="<?php echo esc_attr($value); ?>">
                <?php echo esc_html($displayValue); ?>
                <?php if ($truncated): ?>
                    <button type="button" class="zs-exposure-expand" title="<?php esc_attr_e('Show full value', 'zero-sense'); ?>">
                        <span class="expand-text"><?php esc_html_e('Show more', 'zero-sense'); ?></span>
                        <span class="collapse-text" style="display:none;"><?php esc_html_e('Show less', 'zero-sense'); ?></span>
                    </button>
                <?php endif; ?>
            </div>
            <div class="zs-exposure-tag">
                <code><?php echo esc_html($tag); ?></code>
                <button type="button" 
                        class="zs-exposure-copy" 
                        data-copy="<?php echo esc_attr($tag); ?>"
                        title="<?php esc_attr_e('Copy tag', 'zero-sense'); ?>">
                    📋
                </button>
            </div>
        </div>
        <?php
    }

    private function getFieldsData(WC_Order $order): array
    {
        $fields = [];

        // 1. Registry Fields (Event data)
        $fields = array_merge($fields, $this->getRegistryFields($order));

        // 2. Deposit Fields
        $fields = array_merge($fields, $this->getDepositFields($order));

        // 3. Computed Fields
        $fields = array_merge($fields, $this->getComputedFields($order));

        // 4. Billing/Shipping Fields
        $fields = array_merge($fields, $this->getBillingShippingFields($order));

        // 5. Staff & Vehicles
        $fields = array_merge($fields, $this->getStaffVehicleFields($order));

        return $fields;
    }

    private function getDepositFields(WC_Order $order): array
    {
        $fields = [];

        // Check if order has deposits enabled
        $hasDeposit = DepositMetaKeys::get($order, DepositMetaKeys::HAS_DEPOSIT);
        if ($hasDeposit !== 'yes' && $hasDeposit !== '1') {
            return $fields;
        }

        // Get deposit info
        $depositInfo = DepositUtils::getDepositInfo($order);
        
        // Has deposit flag
        $fields[] = $this->formatField('zs_deposits_has_deposit', 'yes');

        // Deposit percentage
        $depositPercentage = DepositMetaKeys::get($order, DepositMetaKeys::DEPOSIT_PERCENTAGE);
        if ($depositPercentage !== '' && $depositPercentage !== null) {
            $fields[] = $this->formatField('zs_deposits_deposit_percentage', $depositPercentage . '%');
        }

        // Deposit amount
        $depositAmount = DepositMetaKeys::get($order, DepositMetaKeys::DEPOSIT_AMOUNT);
        if ($depositAmount !== '' && $depositAmount !== null) {
            $formatted = wc_price($depositAmount, ['currency' => $order->get_currency()]);
            $fields[] = $this->formatField('zs_deposits_deposit_amount', strip_tags($formatted));
        }

        // Remaining amount
        $remainingAmount = DepositMetaKeys::get($order, DepositMetaKeys::REMAINING_AMOUNT);
        if ($remainingAmount !== '' && $remainingAmount !== null) {
            $formatted = wc_price($remainingAmount, ['currency' => $order->get_currency()]);
            $fields[] = $this->formatField('zs_deposits_remaining_amount', strip_tags($formatted));
        }

        // Balance amount
        $balanceAmount = DepositMetaKeys::get($order, DepositMetaKeys::BALANCE_AMOUNT);
        if ($balanceAmount !== '' && $balanceAmount !== null) {
            $formatted = wc_price($balanceAmount, ['currency' => $order->get_currency()]);
            $fields[] = $this->formatField('zs_deposits_balance_amount', strip_tags($formatted));
        }

        // Deposit paid status
        $isDepositPaid = DepositMetaKeys::get($order, DepositMetaKeys::IS_DEPOSIT_PAID);
        if ($isDepositPaid !== '' && $isDepositPaid !== null) {
            $value = ($isDepositPaid === 'yes' || $isDepositPaid === '1') ? 'yes' : 'no';
            $fields[] = $this->formatField('zs_deposits_is_deposit_paid', $value);
        }

        // Deposit payment date
        $depositPaymentDate = DepositMetaKeys::get($order, DepositMetaKeys::DEPOSIT_PAYMENT_DATE);
        if ($depositPaymentDate !== '' && $depositPaymentDate !== null) {
            $fields[] = $this->formatField('zs_deposits_deposit_payment_date', $depositPaymentDate);
        }

        // Balance paid status
        $isBalancePaid = DepositMetaKeys::get($order, DepositMetaKeys::IS_BALANCE_PAID);
        if ($isBalancePaid !== '' && $isBalancePaid !== null) {
            $value = ($isBalancePaid === 'yes' || $isBalancePaid === '1') ? 'yes' : 'no';
            $fields[] = $this->formatField('zs_deposits_is_balance_paid', $value);
        }

        // Balance payment date
        $balancePaymentDate = DepositMetaKeys::get($order, DepositMetaKeys::BALANCE_PAYMENT_DATE);
        if ($balancePaymentDate !== '' && $balancePaymentDate !== null) {
            $fields[] = $this->formatField('zs_deposits_balance_payment_date', $balancePaymentDate);
        }

        // Payment flow
        $paymentFlow = DepositMetaKeys::get($order, DepositMetaKeys::PAYMENT_FLOW);
        if ($paymentFlow !== '' && $paymentFlow !== null) {
            $fields[] = $this->formatField('zs_deposits_payment_flow', $paymentFlow);
        }

        // Manual override
        $isManualOverride = DepositMetaKeys::get($order, DepositMetaKeys::IS_MANUAL_OVERRIDE);
        if ($isManualOverride !== '' && $isManualOverride !== null) {
            $value = ($isManualOverride === 'yes' || $isManualOverride === '1') ? 'yes' : 'no';
            $fields[] = $this->formatField('zs_deposits_is_manual_override', $value);
        }

        return $fields;
    }

    private function getRegistryFields(WC_Order $order): array
    {
        $fields = [];
        $orderId = $order->get_id();
        $registry = MetaFieldRegistry::getInstance();

        $priorityKeys = [
            MetaKeys::TOTAL_GUESTS,
            MetaKeys::ADULTS,
            MetaKeys::CHILDREN_5_TO_8,
            MetaKeys::CHILDREN_0_TO_4,
            MetaKeys::EVENT_DATE,
            MetaKeys::START_TIME,
            MetaKeys::SERVING_TIME,
            MetaKeys::SERVICE_LOCATION,
            MetaKeys::EVENT_TYPE,
            MetaKeys::ADDRESS,
            MetaKeys::CITY,
        ];

        foreach ($priorityKeys as $key) {
            $metadata = $registry->getFieldMetadata($key);
            if (!$metadata) {
                continue;
            }

            $value = '';
            $isTranslatable = $metadata['translatable'] ?? false;

            if ($isTranslatable && function_exists('zero_sense_get_translated_meta')) {
                $value = zero_sense_get_translated_meta($orderId, $key);
            } else {
                $value = $order->get_meta($key, true);
            }

            // Special handling for service_location
            if ($key === MetaKeys::SERVICE_LOCATION && is_numeric($value)) {
                $termId = (int) $value;
                $orderLang = $order->get_meta('wpml_language', true);
                
                if (defined('ICL_SITEPRESS_VERSION') && function_exists('apply_filters') && is_string($orderLang) && $orderLang !== '') {
                    $translatedId = apply_filters('wpml_object_id', $termId, 'service-area', true, $orderLang);
                    if ($translatedId) {
                        $termId = (int) $translatedId;
                    }
                }
                
                $term = get_term($termId, 'service-area');
                if ($term instanceof \WP_Term) {
                    $value = $term->name . ' (ID: ' . $termId . ')';
                }
            }

            // Special handling for event_type
            if ($key === MetaKeys::EVENT_TYPE && is_string($value) && $value !== '') {
                $options = FieldOptions::getEventTypeOptions();
                $label = $options[$value] ?? $value;
                $value = $label . ' (' . $value . ')';
            }

            if ($value === '' || $value === null) {
                continue;
            }

            $fields[] = $this->formatField($key, $value);
        }

        return $fields;
    }

    private function getComputedFields(WC_Order $order): array
    {
        $fields = [];

        // Use ApiExtension to get computed fields
        $apiExtension = new ApiExtension();
        $data = [];
        $data = $apiExtension->addCustomFieldsToOrderData($data, $order);

        $computedKeys = [
            'zs_order_products_simple',
            'zs_order_products_count',
            'zs_order_last_modified',
            'zs_order_language_name',
            'zs_order_deposit_percentage',
        ];

        foreach ($computedKeys as $key) {
            if (isset($data[$key]) && $data[$key] !== '' && $data[$key] !== null) {
                $fields[] = $this->formatField($key, $data[$key]);
            }
        }

        return $fields;
    }

    private function getBillingShippingFields(WC_Order $order): array
    {
        $fields = [];

        // Billing
        $billingName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        if ($billingName !== '') {
            $fields[] = $this->formatField('zs_billing_name', $billingName);
        }

        $billingEmail = $order->get_billing_email();
        if ($billingEmail !== '') {
            $fields[] = $this->formatField('zs_billing_email', $billingEmail);
        }

        $billingPhone = $order->get_billing_phone();
        if ($billingPhone !== '') {
            $fields[] = $this->formatField('zs_billing_phone', $billingPhone);
        }

        // Shipping
        $shippingAddress = $order->get_shipping_address_1();
        if ($shippingAddress !== '') {
            $fields[] = $this->formatField('zs_shipping_address_1', $shippingAddress);
        }

        $shippingCity = $order->get_shipping_city();
        if ($shippingCity !== '') {
            $fields[] = $this->formatField('zs_shipping_city', $shippingCity);
        }

        return $fields;
    }

    private function getStaffVehicleFields(WC_Order $order): array
    {
        $fields = [];

        // Staff
        $staffData = $order->get_meta(MetaKeys::EVENT_STAFF, true);
        if (is_array($staffData) && !empty($staffData)) {
            $staffCount = count($staffData);
            $fields[] = $this->formatField('zs_event_staff', $staffCount . ' staff assigned');
        }

        // Vehicles
        $vehicleIds = $order->get_meta(MetaKeys::EVENT_VEHICLES, true);
        if (is_array($vehicleIds) && !empty($vehicleIds)) {
            $vehicleCount = count($vehicleIds);
            $fields[] = $this->formatField('zs_event_vehicles', $vehicleCount . ' vehicles assigned');
        }

        return $fields;
    }

    private function formatField(string $key, $value): array
    {
        $stringValue = is_array($value) || is_object($value) 
            ? wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) 
            : (string) $value;

        $truncated = false;
        $displayValue = $stringValue;

        if (strlen($stringValue) > 50) {
            $truncated = true;
            $displayValue = substr($stringValue, 0, 50) . '...';
        }

        // Generate Bricks tag
        $tag = $this->getBricksTag($key);

        return [
            'key' => $key,
            'value' => $stringValue,
            'display_value' => $displayValue,
            'tag' => $tag,
            'truncated' => $truncated,
        ];
    }

    private function getBricksTag(string $key): string
    {
        // Remove zs_ prefix if present for the tag
        $tagName = $key;
        if (strpos($key, 'zs_') === 0) {
            $tagName = $key; // Keep zs_ prefix for canonical tags
        }

        return '{' . $tagName . '}';
    }
}
