<?php

declare(strict_types=1);

namespace ZeroSense\Features\Integrations\Bricks;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Core\MetaFieldRegistry;
use WC_Order;
use WP_Post;

class BricksDynamicTags implements FeatureInterface
{
    private const BILLING_FIELDS = [
        'first_name' => 'Billing First Name',
        'last_name' => 'Billing Last Name',
        'company' => 'Billing Company',
        'address_1' => 'Billing Address 1',
        'address_2' => 'Billing Address 2',
        'city' => 'Billing City',
        'postcode' => 'Billing Postcode',
        'country' => 'Billing Country',
        'state' => 'Billing State',
        'email' => 'Billing Email',
        'phone' => 'Billing Phone',
    ];

    private const SHIPPING_FIELDS = [
        'first_name' => 'Shipping First Name',
        'last_name' => 'Shipping Last Name',
        'company' => 'Shipping Company',
        'address_1' => 'Shipping Address 1',
        'address_2' => 'Shipping Address 2',
        'city' => 'Shipping City',
        'postcode' => 'Shipping Postcode',
        'country' => 'Shipping Country',
        'state' => 'Shipping State',
        'email' => 'Shipping Email',
        'phone' => 'Shipping Phone',
    ];

    private const OPS_FIELDS = [
        'notes' => 'Ops Notes',
    ];

    private const OPTION_MATERIAL_SCHEMA = 'zs_ops_material_schema';


    /**
     * Get MetaBox fields from registry
     */
    private function getMetaBoxFields(): array
    {
        $registry = MetaFieldRegistry::getInstance();
        $fields = [];
        
        foreach ($registry->getAllFields() as $key => $metadata) {
            $legacyKeys = $metadata['legacy_keys'] ?? [];
            if (!empty($legacyKeys)) {
                foreach ($legacyKeys as $legacyKey) {
                    if (!str_starts_with($legacyKey, '_') && !str_starts_with($legacyKey, 'zs_')) {
                        $fields[$legacyKey] = $metadata['label'] ?? $legacyKey;
                    }
                }
            }
        }
        
        return $fields;
    }

    /**
     * Get field mapping from registry
     */
    private function getFieldMapping(): array
    {
        $registry = MetaFieldRegistry::getInstance();
        $mapping = [];
        
        foreach ($registry->getAllFields() as $key => $metadata) {
            $legacyKeys = $metadata['legacy_keys'] ?? [];
            if (!empty($legacyKeys)) {
                foreach ($legacyKeys as $legacyKey) {
                    if (!str_starts_with($legacyKey, '_')) {
                        $mapping[$legacyKey] = $key;
                    }
                }
            }
        }
        
        return $mapping;
    }

    public function getName(): string
    {
        return __('Bricks Dynamic Tags', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Exposes WooCommerce billing, shipping, and Meta Box order data as dynamic tags in Bricks Builder for use in templates and content.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'Integrations';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getConditions(): array
    {
        return [];
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
        if (!$this->isEnabled() || !class_exists('WooCommerce')) {
            return;
        }

        add_filter('bricks/dynamic_tags_list', [$this, 'registerDynamicTags']);
        add_filter('bricks/dynamic_data/render_tag', [$this, 'renderTag'], 10, 3);
        add_filter('bricks/dynamic_data/render_content', [$this, 'renderContent'], 10, 3);
        add_filter('bricks/frontend/render_data', [$this, 'renderContent'], 10, 2);
        add_action('wp', [$this, 'maybeResetMetaBoxTranslations']);
        
        // Track order modifications (multiple hooks to cover all cases)
        add_action('woocommerce_process_shop_order_meta', [$this, 'trackOrderModification'], 999);
        add_action('woocommerce_update_order', [$this, 'trackOrderModification'], 999);
        add_action('woocommerce_new_order', [$this, 'trackOrderModification'], 999);
        add_action('save_post_shop_order', [$this, 'trackOrderModificationPost'], 999, 2);
    }
    
    public function trackOrderModificationPost(int $postId, \WP_Post $post): void
    {
        // Avoid autosaves and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($postId)) {
            return;
        }
        
        $this->trackOrderModification($postId);
    }
    
    public function trackOrderModification(int $orderId): void
    {
        $timestamp = current_time('mysql');
        
        // Use WooCommerce method to ensure compatibility with HPOS
        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order) {
            $order->update_meta_data('_zs_last_modified', $timestamp);
            $order->save_meta_data();
        }
        
        // Also save as post meta for fallback
        update_post_meta($orderId, '_zs_last_modified', $timestamp);
    }

    /**
     * @param array<int, array<string, string>> $tags
     * @return array<int, array<string, string>>
     */
    public function registerDynamicTags(array $tags): array
    {
        foreach (self::BILLING_FIELDS as $field => $label) {
            $tags[] = [
                'name' => '{woo_billing_' . $field . '}',
                'label' => $label,
                'group' => 'WooCommerce',
            ];
        }

        foreach (self::SHIPPING_FIELDS as $field => $label) {
            $tags[] = [
                'name' => '{woo_shipping_' . $field . '}',
                'label' => $label,
                'group' => 'WooCommerce',
            ];
        }

        $tags[] = [
            'name' => '{woo_zs_order_id}',
            'label' => 'Order ID',
            'group' => 'WooCommerce',
        ];

        $tags[] = [
            'name' => '{woo_zs_order_number}',
            'label' => 'Order Number',
            'group' => 'WooCommerce',
        ];

        $tags[] = [
            'name' => '{woo_order_note}',
            'label' => 'Customer Note',
            'group' => 'WooCommerce',
        ];

        foreach ($this->getMetaBoxFields() as $field => $label) {
            $tags[] = [
                'name' => '{woo_mb_' . $field . '}',
                'label' => $label,
                'group' => 'WooCommerce',
            ];
        }

        $tags[] = [
            'name' => '{woo_zs_event_service_location_name}',
            'label' => 'Event Service Location (Name)',
            'group' => 'WooCommerce',
        ];

        foreach (self::OPS_FIELDS as $field => $label) {
            $tags[] = [
                'name' => '{woo_ops_' . $field . '}',
                'label' => $label,
                'group' => 'WooCommerce',
            ];
        }

        foreach ($this->getOpsMaterialFields() as $field => $label) {
            $tags[] = [
                'name' => '{woo_ops_material_' . $field . '}',
                'label' => $label,
                'group' => 'WooCommerce',
            ];
        }

        $tags[] = [
            'name' => '{woo_zs_material_list}',
            'label' => 'Material & Logistics (Complete List)',
            'group' => 'WooCommerce',
        ];

        $tags[] = [
            'name' => '{woo_zs_event_media}',
            'label' => 'Event Media Gallery',
            'group' => 'WooCommerce',
        ];

        $tags[] = [
            'name' => '{woo_zs_event_media_urls}',
            'label' => 'Event Media URLs (comma-separated)',
            'group' => 'WooCommerce',
        ];

        // Order Products (Menu)
        $tags[] = [
            'name' => '{woo_zs_order_products}',
            'label' => 'Order Products (Menu)',
            'group' => 'WooCommerce',
        ];

        $tags[] = [
            'name' => '{woo_zs_order_products_simple}',
            'label' => 'Order Products (Simple List)',
            'group' => 'WooCommerce',
        ];

        $tags[] = [
            'name' => '{woo_zs_order_products_count}',
            'label' => 'Order Products Count',
            'group' => 'WooCommerce',
        ];

        $tags[] = [
            'name' => '{woo_zs_order_products_by_category}',
            'label' => 'Order Products (Grouped by Category)',
            'group' => 'WooCommerce',
        ];

        $tags[] = [
            'name' => '{woo_zs_order_last_modified}',
            'label' => 'Order Last Modified (Date & Time)',
            'group' => 'WooCommerce',
        ];

        $tags[] = [
            'name' => '{woo_zs_order_last_modified_date}',
            'label' => 'Order Last Modified (Date Only)',
            'group' => 'WooCommerce',
        ];

        $tags[] = [
            'name' => '{woo_zs_order_last_modified_time}',
            'label' => 'Order Last Modified (Time Only)',
            'group' => 'WooCommerce',
        ];

        $tags[] = [
            'name' => '{woo_zs_order_language}',
            'label' => 'Order Language',
            'group' => 'WooCommerce',
        ];

        $tags[] = [
            'name' => '{woo_zs_order_language_name}',
            'label' => 'Order Language (Full Name)',
            'group' => 'WooCommerce',
        ];

        $tags[] = [
            'name' => '{woo_zs_intolerances}',
            'label' => 'Intolerances & Allergies',
            'group' => 'WooCommerce',
        ];

        return $tags;
    }

    private function getOpsMaterialFields(): array
    {
        $schema = get_option(self::OPTION_MATERIAL_SCHEMA, null);
        if (!is_array($schema) || $schema === []) {
            return [];
        }

        $fields = [];
        foreach ($schema as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = isset($row['key']) ? sanitize_key((string) $row['key']) : '';
            $label = isset($row['label']) ? sanitize_text_field((string) $row['label']) : '';
            if ($key === '' || $label === '') {
                continue;
            }

            $name = 'ops_material_label_' . $key;
            $translated = apply_filters('wpml_translate_single_string', $label, 'zero-sense', $name);
            $fields[$key] = is_string($translated) && $translated !== '' ? $translated : $label;
        }

        return $fields;
    }

    /**
     * @param mixed $tag
     * @param mixed $post
     */
    public function renderTag($tag, $post, string $context = 'text')
    {
        if (!is_string($tag)) {
            return $tag;
        }

        if ($tag === '{woo_zs_order_id}') {
            return $this->getOrderId($post);
        }

        if ($tag === '{woo_zs_order_number}') {
            return $this->getOrderNumber($post);
        }

        if ($tag === '{woo_order_note}') {
            return $this->getOrderNote($post);
        }

        if (strpos($tag, '{woo_billing_') === 0) {
            $field = $this->stripTag($tag, 'woo_billing_');
            return $this->getBillingFieldValue($field, $post);
        }

        if (strpos($tag, '{woo_shipping_') === 0) {
            $field = $this->stripTag($tag, 'woo_shipping_');
            return $this->getShippingFieldValue($field, $post);
        }

        if ($tag === '{woo_zs_event_service_location_name}') {
            return $this->getServiceLocationName($post);
        }

        if (strpos($tag, '{woo_mb_') === 0) {
            $field = $this->stripTag($tag, 'woo_mb_');
            return $this->getMetaBoxFieldValue($field, $post);
        }

        if ($tag === '{woo_ops_notes}') {
            return $this->getOpsNotesValue($post);
        }

        if ($tag === '{woo_zs_material_list}') {
            return $this->getOpsMaterialList($post);
        }

        if (strpos($tag, '{woo_ops_material_') === 0) {
            $field = $this->stripTag($tag, 'woo_ops_material_');
            return $this->getOpsMaterialFieldValue($field, $post);
        }

        if ($tag === '{woo_zs_event_media}') {
            return $this->getEventMediaGallery($post);
        }

        if ($tag === '{woo_zs_event_media_urls}') {
            return $this->getEventMediaUrls($post);
        }

        if ($tag === '{woo_zs_order_products}') {
            return $this->getOrderProducts($post);
        }

        if ($tag === '{woo_zs_order_products_simple}') {
            return $this->getOrderProductsSimple($post);
        }

        if ($tag === '{woo_zs_order_products_count}') {
            return $this->getOrderProductsCount($post);
        }

        if ($tag === '{woo_zs_order_products_by_category}') {
            return $this->getOrderProductsByCategory($post);
        }

        if ($tag === '{woo_zs_order_last_modified}') {
            return $this->getOrderLastModified($post);
        }

        if ($tag === '{woo_zs_order_last_modified_date}') {
            return $this->getOrderLastModified($post, 'date');
        }

        if ($tag === '{woo_zs_order_last_modified_time}') {
            return $this->getOrderLastModified($post, 'time');
        }

        if ($tag === '{woo_zs_order_language}') {
            return $this->getOrderLanguage($post);
        }

        if ($tag === '{woo_zs_order_language_name}') {
            return $this->getOrderLanguage($post, true);
        }

        if ($tag === '{woo_zs_intolerances}') {
            return $this->getMetaBoxFieldValue('intolerances', $post);
        }

        return $tag;
    }

    /**
     * @param mixed $content
     * @param mixed $post
     */
    public function renderContent($content, $post, string $context = 'text')
    {
        if (!is_string($content)) {
            return $content;
        }

        $content = $this->replaceTagsInContent($content, $post, 'woo_billing_', function (string $field) use ($post): string {
            return $this->getBillingFieldValue($field, $post);
        });

        $content = $this->replaceTagsInContent($content, $post, 'woo_shipping_', function (string $field) use ($post): string {
            return $this->getShippingFieldValue($field, $post);
        });

        $content = str_replace('{woo_zs_order_id}', $this->getOrderId($post), $content);
        $content = str_replace('{woo_zs_order_number}', $this->getOrderNumber($post), $content);
        $content = str_replace('{woo_order_note}', $this->getOrderNote($post), $content);

        $content = str_replace('{woo_zs_event_service_location_name}', $this->getServiceLocationName($post), $content);

        $content = $this->replaceTagsInContent($content, $post, 'woo_mb_', function (string $field) use ($post): string {
            return $this->getMetaBoxFieldValue($field, $post);
        });

        $content = str_replace('{woo_ops_notes}', $this->getOpsNotesValue($post), $content);

        $content = str_replace('{woo_zs_material_list}', $this->getOpsMaterialList($post), $content);

        $content = $this->replaceTagsInContent($content, $post, 'woo_ops_material_', function (string $field) use ($post): string {
            return $this->getOpsMaterialFieldValue($field, $post);
        });

        $content = str_replace('{woo_zs_event_media}', $this->getEventMediaGallery($post), $content);
        $content = str_replace('{woo_zs_event_media_urls}', $this->getEventMediaUrls($post), $content);

        $content = str_replace('{woo_zs_order_products}', $this->getOrderProducts($post), $content);
        $content = str_replace('{woo_zs_order_products_simple}', $this->getOrderProductsSimple($post), $content);
        $content = str_replace('{woo_zs_order_products_count}', $this->getOrderProductsCount($post), $content);
        $content = str_replace('{woo_zs_order_products_by_category}', $this->getOrderProductsByCategory($post), $content);

        $content = str_replace('{woo_zs_order_last_modified}', $this->getOrderLastModified($post), $content);
        $content = str_replace('{woo_zs_order_last_modified_date}', $this->getOrderLastModified($post, 'date'), $content);
        $content = str_replace('{woo_zs_order_last_modified_time}', $this->getOrderLastModified($post, 'time'), $content);

        $content = str_replace('{woo_zs_order_language}', $this->getOrderLanguage($post), $content);
        $content = str_replace('{woo_zs_order_language_name}', $this->getOrderLanguage($post, true), $content);

        $content = str_replace('{woo_zs_intolerances}', $this->getMetaBoxFieldValue('intolerances', $post), $content);

        return $content;
    }

    private function replaceTagsInContent(string $content, $post, string $prefix, callable $callback): string
    {
        $pattern = '/{' . preg_quote($prefix, '/') . '[^}]*}/';
        preg_match_all($pattern, $content, $matches);

        if (empty($matches[0])) {
            return $content;
        }

        foreach ($matches[0] as $tagWithBraces) {
            $field = $this->stripTag($tagWithBraces, $prefix);
            $value = $callback($field);
            $content = str_replace($tagWithBraces, $value, $content);
        }

        return $content;
    }

    private function stripTag(string $tag, string $prefix): string
    {
        return str_replace(['{' . $prefix, '}'], '', $tag);
    }

    private function getBillingFieldValue(string $field, $post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Billing ' . $field);
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $method = 'get_billing_' . $field;
        $value = '';

        if (is_callable([$order, $method])) {
            $raw = $order->{$method}();
            if (is_string($raw)) {
                $value = $raw;
            }
        }

        if ($field === 'country') {
            return $this->formatCountry($value);
        }

        if ($field === 'state') {
            $country = $order->get_billing_country();
            return $this->formatState($value, $country);
        }

        return $value;
    }

    private function getOpsNotesValue($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Ops notes');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $raw = $order->get_meta('zs_ops_notes', true);

        return is_string($raw) ? $raw : '';
    }

    private function getOpsMaterialList($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Material & Logistics List');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        // Get schema to get labels
        $schema = get_option(self::OPTION_MATERIAL_SCHEMA, null);
        if (!is_array($schema) || $schema === []) {
            return '';
        }

        // Get saved material data
        $materialData = $order->get_meta('zs_ops_material', true);
        if (!is_array($materialData)) {
            $materialData = [];
        }

        $html = '<div class="zs-material-list">';
        
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

            // Get translated label
            $name = 'ops_material_label_' . $key;
            $translatedLabel = apply_filters('wpml_translate_single_string', $label, 'zero-sense', $name);
            $finalLabel = is_string($translatedLabel) && $translatedLabel !== '' ? $translatedLabel : $label;

            // Get value
            $entry = $materialData[$key] ?? null;
            if (is_array($entry) && array_key_exists('value', $entry)) {
                $value = $entry['value'];
            } else {
                $value = $entry;
            }

            // Format value based on type
            if ($type === 'bool') {
                $yesText = apply_filters('wpml_translate_single_string', 'Yes', 'zero-sense', 'material_yes');
                $noText = apply_filters('wpml_translate_single_string', 'No', 'zero-sense', 'material_no');
                $displayValue = ($value === '1' || $value === 1) ? $yesText : $noText;
                $isEmpty = false; // Checkboxes always show
            } elseif ($type === 'qty_int') {
                $displayValue = is_numeric($value) && $value > 0 ? (string) $value : '-';
                $isEmpty = ($displayValue === '-');
            } else {
                $displayValue = is_scalar($value) && $value !== '' ? (string) $value : '-';
                $isEmpty = ($displayValue === '-');
            }

            // Show field if not empty OR if it's a checkbox (always show checkboxes)
            if (!$isEmpty || $type === 'bool') {
                $html .= '<div class="zs-material-item">';
                $html .= '<strong>' . esc_html($finalLabel) . ':</strong> ';
                $html .= '<span>' . esc_html($displayValue) . '</span>';
                $html .= '</div>';
            }
        }
        
        $html .= '</div>';
        
        return $html;
    }

    private function getOpsMaterialFieldValue(string $field, $post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Ops material ' . $field);
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $raw = $order->get_meta('zs_ops_material', true);

        if (!is_array($raw)) {
            return '';
        }

        $entry = $raw[$field] ?? null;
        if (is_array($entry) && array_key_exists('value', $entry)) {
            $entry = $entry['value'];
        }

        if (is_scalar($entry)) {
            return (string) $entry;
        }

        return '';
    }

    private function getShippingFieldValue(string $field, $post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Shipping ' . $field);
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        if ($field === 'phone') {
            $value = $order->get_meta('_shipping_phone', true);
            return is_string($value) ? $value : '';
        }

        if ($field === 'email') {
            $raw = $order->get_meta('_shipping_email', true);
            return is_string($raw) ? $raw : '';
        }

        $method = 'get_shipping_' . $field;
        $value = '';

        if (is_callable([$order, $method])) {
            $raw = $order->{$method}();
            if (is_string($raw)) {
                $value = $raw;
            }
        }

        if ($field === 'country') {
            return $this->formatCountry($value);
        }

        if ($field === 'state') {
            $country = $order->get_shipping_country();
            return $this->formatState($value, $country);
        }

        return $value;
    }

    private function getOrderNote($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Customer note');
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return '';
        }

        $note = $order->get_customer_note();
        return is_string($note) ? $note : '';
    }

    private function getOrderId($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Order ID');
        }

        return (string) $orderId;
    }

    private function getOrderNumber($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Order Number');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        return (string) $order->get_order_number();
    }

    private function getServiceLocationName($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Service Location Name');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $termId = $order->get_meta('zs_event_service_location', true);
        if (!is_numeric($termId) || $termId <= 0) {
            return '';
        }

        $term = get_term((int) $termId, 'service-area');
        if (!$term instanceof \WP_Term) {
            return '';
        }

        // Get translated term name if WPML is active
        if (defined('ICL_SITEPRESS_VERSION')) {
            $currentLang = apply_filters('wpml_current_language', null);
            $translatedTermId = apply_filters('wpml_object_id', $term->term_id, 'service-area', false, $currentLang);
            if ($translatedTermId && $translatedTermId !== $term->term_id) {
                $translatedTerm = get_term($translatedTermId, 'service-area');
                if ($translatedTerm instanceof \WP_Term) {
                    return $translatedTerm->name;
                }
            }
        }

        return $term->name;
    }

    private function getOrderProducts($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Order Products');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $html = '<div class="zs-order-products">';
        
        foreach ($order->get_items() as $item) {
            if (!method_exists($item, 'get_product') || !$item->get_product()) {
                continue;
            }
            
            $product = $item->get_product();
            $quantity = $item->get_quantity();
            $name = $item->get_name();
            
            $html .= '<div class="zs-product-item">';
            $html .= '<strong>' . esc_html($name) . '</strong>';
            
            if ($quantity > 1) {
                $html .= ' <span class="zs-quantity">(' . esc_html($quantity) . 'x)</span>';
            }
            
            // Show product attributes if it's a variable product
            if ($product->is_type('variation')) {
                $attributes = $product->get_variation_attributes();
                if (!empty($attributes)) {
                    $html .= '<div class="zs-attributes">';
                    foreach ($attributes as $attr_name => $attr_value) {
                        if ($attr_value) {
                            $label = wc_attribute_label(str_replace('attribute_', '', $attr_name));
                            $html .= '<small>' . esc_html($label) . ': ' . esc_html($attr_value) . '</small>';
                        }
                    }
                    $html .= '</div>';
                }
            }
            
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }

    private function getOrderProductsSimple($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Order Products');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $products = [];
        
        foreach ($order->get_items() as $item) {
            $name = $item->get_name();
            $quantity = $item->get_quantity();
            
            if ($quantity > 1) {
                $products[] = $name . ' (' . $quantity . 'x)';
            } else {
                $products[] = $name;
            }
        }
        
        return implode(', ', $products);
    }

    private function getOrderProductsCount($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Products Count');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '0';
        }

        return (string) count($order->get_items());
    }

    private function getOrderLanguage($post, bool $fullName = false): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Order Language');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $language = $order->get_meta('wpml_language', true);
        if (!$language || $language === '') {
            return '';
        }

        if (!$fullName) {
            return strtoupper((string) $language);
        }

        // Return full language name
        $languageNames = [
            'es' => 'Español',
            'en' => 'English',
            'ca' => 'Català',
        ];

        return $languageNames[$language] ?? strtoupper((string) $language);
    }

    private function getOrderLastModified($post, string $format = 'full'): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Order Last Modified');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        // Get our custom tracked modification date
        $modified = $order->get_meta('_zs_last_modified', true);
        
        // Fallback to post_modified if our custom field doesn't exist yet
        if (!$modified || $modified === '') {
            $postData = get_post($orderId);
            if (!$postData || !isset($postData->post_modified)) {
                return '';
            }
            $modified = $postData->post_modified;
        }

        if (!$modified || $modified === '0000-00-00 00:00:00') {
            return '';
        }

        // Get WordPress date and time formats
        $dateFormat = get_option('date_format', 'Y-m-d');
        $timeFormat = get_option('time_format', 'H:i:s');

        // Convert to timestamp and format
        $timestamp = strtotime($modified);
        
        if ($format === 'date') {
            return date_i18n($dateFormat, $timestamp);
        }

        if ($format === 'time') {
            return date_i18n($timeFormat, $timestamp);
        }

        // Full format: date + time
        return date_i18n($dateFormat . ' ' . $timeFormat, $timestamp);
    }

    private function getOrderProductsByCategory($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Order Products by Category');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        // Group products by category
        $categorizedProducts = [];
        
        foreach ($order->get_items() as $item) {
            if (!method_exists($item, 'get_product') || !$item->get_product()) {
                continue;
            }
            
            $product = $item->get_product();
            $productId = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
            
            // Get product categories
            $terms = get_the_terms($productId, 'product_cat');
            
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    // Skip uncategorized
                    if ($term->slug === 'uncategorized') {
                        continue;
                    }
                    
                    if (!isset($categorizedProducts[$term->term_id])) {
                        $categorizedProducts[$term->term_id] = [
                            'name' => $term->name,
                            'slug' => $term->slug,
                            'products' => [],
                        ];
                    }
                    
                    $categorizedProducts[$term->term_id]['products'][] = [
                        'name' => $item->get_name(),
                        'quantity' => $item->get_quantity(),
                    ];
                }
            } else {
                // Products without category
                if (!isset($categorizedProducts[0])) {
                    $categorizedProducts[0] = [
                        'name' => __('Other', 'zero-sense'),
                        'slug' => 'other',
                        'products' => [],
                    ];
                }
                
                $categorizedProducts[0]['products'][] = [
                    'name' => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                ];
            }
        }

        // Generate HTML
        $html = '<div class="zs-menu-table">';
        
        foreach ($categorizedProducts as $category) {
            $html .= '<div class="zs-menu-category">';
            $html .= '<div class="zs-menu-category-name">' . esc_html(strtoupper($category['name'])) . '</div>';
            $html .= '<div class="zs-menu-products">';
            
            foreach ($category['products'] as $product) {
                $html .= '<div class="zs-menu-product">';
                $html .= esc_html($product['name']);
                if ($product['quantity'] > 1) {
                    $html .= ' <span class="zs-menu-quantity">(' . esc_html($product['quantity']) . 'x)</span>';
                }
                $html .= '</div>';
            }
            
            $html .= '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }

    private function getMetaBoxFieldValue(string $field, $post): string
    {
        $orderId = $this->resolveOrderId($post);

        if (!$orderId) {
            return $this->builderPlaceholder($field);
        }

        return $this->getTranslatedMetaValue($orderId, $field);
    }

    private function resolveOrderId($contextPost = null): ?int
    {
        if ($contextPost instanceof WP_Post && get_post_type($contextPost->ID) === 'shop_order') {
            return (int) $contextPost->ID;
        }

        global $post;
        if ($post instanceof WP_Post && get_post_type($post->ID) === 'shop_order') {
            return (int) $post->ID;
        }

        if (isset($_GET['order']) && is_numeric($_GET['order'])) {
            return (int) $_GET['order'];
        }

        if (function_exists('is_checkout_pay_page') && is_checkout_pay_page()) {
            global $wp;
            if (isset($_GET['key']) && is_string($_GET['key']) && function_exists('wc_get_order_id_by_order_key')) {
                $id = absint(wc_get_order_id_by_order_key(wp_unslash($_GET['key'])));
                if ($id > 0) {
                    return $id;
                }
            }
            if (isset($wp->query_vars['order-pay'])) {
                $orderId = absint($wp->query_vars['order-pay']);
                return $orderId;
            }
        }

        return null;
    }

    private function formatCountry($value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        if (!function_exists('WC')) {
            return $value;
        }

        $countries = WC()->countries->get_countries();
        return $countries[$value] ?? $value;
    }

    private function formatState($value, $country): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        if (!function_exists('WC')) {
            return $value;
        }

        $states = WC()->countries->get_states(is_string($country) ? $country : '');
        return $states[$value] ?? $value;
    }

    private function builderPlaceholder(string $label): string
    {
        if (function_exists('bricks_is_builder') && bricks_is_builder()) {
            return '[' . $label . ']';
        }

        return '';
    }

    private function getTranslatedMetaValue(int $orderId, string $field): string
    {
        $value = '';

        // Map MetaBox field name to ZeroSense meta key
        $mapping = $this->getFieldMapping();
        $metaKey = $mapping[$field] ?? $field;

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        if ($field === 'event_address') {
            $v = $order->get_shipping_address_1();
            return $v !== '' ? $v : (string) $order->get_meta($metaKey, true);
        }
        if ($field === 'event_city') {
            $v = $order->get_shipping_city();
            return $v !== '' ? $v : (string) $order->get_meta($metaKey, true);
        }
        if ($field === 'location_link') {
            $v = $order->get_meta('_shipping_location_link', true);
            if (is_string($v) && $v !== '') { return $v; }
            return (string) $order->get_meta($metaKey, true);
        }

        $raw = $order->get_meta($metaKey, true);
        
        if (is_scalar($raw)) {
            $value = (string) $raw;
        }

        if ($value === '') {
            return '';
        }

        if ($field === 'event_date') {
            return $this->normalizeEventDate($value);
        }

        // For select fields, get the label from Meta Box options and translate it
        if (in_array($field, self::SELECT_FIELDS, true)) {
            return $this->getTranslatedSelectLabel($field, $value);
        }

        // For non-select fields, try WPML translation
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return $value;
        }

        $currentLang = apply_filters('wpml_current_language', null);
        $contexts = [
            'meta-box-field-group-sobre-el-evento',
            'admin_texts_plugin_metabox',
            'Meta Box',
            'zero-sense-event-fields',
            'zero-sense',
        ];

        foreach ($contexts as $context) {
            $translated = apply_filters('wpml_translate_single_string', $value, $context, $value, $currentLang);
            if (is_string($translated) && $translated !== '' && $translated !== $value) {
                return $translated;
            }
        }

        return $value;
    }

    private function getTranslatedSelectLabel(string $field, string $savedValue): string
    {
        // Get Meta Box field options
        $options = $this->getMetaBoxFieldOptions($field);
        
        if (empty($options)) {
            return $savedValue;
        }

        // The saved value is the key, find its label
        $label = $options[$savedValue] ?? $savedValue;

        // If no WPML, return the label as-is
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return $label;
        }

        $currentLang = apply_filters('wpml_current_language', null);
        $defaultLang = apply_filters('wpml_default_language', null);

        // If we're on the default language, return label directly
        if ($currentLang === $defaultLang) {
            return $label;
        }

        // Try to translate the label
        $contexts = [
            'meta-box-field-group-sobre-el-evento',
            'admin_texts_plugin_metabox',
            'Meta Box',
        ];

        foreach ($contexts as $context) {
            $translated = apply_filters('wpml_translate_single_string', $label, $context, $label, $currentLang);
            if (is_string($translated) && $translated !== '' && $translated !== $label) {
                return $translated;
            }
        }

        return $label;
    }

    private function getMetaBoxFieldOptions(string $fieldId): array
    {
        $metaBoxes = apply_filters('rwmb_meta_boxes', []);
        
        foreach ($metaBoxes as $metaBox) {
            if (empty($metaBox['fields'])) {
                continue;
            }
            
            foreach ($metaBox['fields'] as $field) {
                if (isset($field['id']) && $field['id'] === $fieldId && isset($field['options'])) {
                    return is_array($field['options']) ? $field['options'] : [];
                }
            }
        }
        
        return [];
    }

    private function normalizeEventDate($value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        // YYYY-MM-DD → dd/mm/YYYY
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return $m[3] . '/' . $m[2] . '/' . $m[1];
        }

        return $value;
    }

    public function maybeResetMetaBoxTranslations(): void
    {
        if (!isset($_GET['reset_metabox_translations']) || !current_user_can('manage_options')) {
            return;
        }

        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'zs_reset_metabox_translations')) {
            return;
        }

        global $wpdb;
        $fieldsToReset = ['event_type'];
        $stringsTable = $wpdb->prefix . 'icl_strings';
        $translationsTable = $wpdb->prefix . 'icl_string_translations';

        foreach ($fieldsToReset as $field) {
            $stringIds = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$stringsTable} WHERE context = %s AND name = %s",
                    'admin_texts_plugin_metabox',
                    $field
                )
            );

            if (empty($stringIds)) {
                continue;
            }

            $ids = array_map('intval', $stringIds);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));

            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$translationsTable} WHERE string_id IN ({$placeholders})",
                    ...$ids
                )
            );

            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$stringsTable} WHERE id IN ({$placeholders})",
                    ...$ids
                )
            );
        }

        if (function_exists('wpml_load_core_tm')) {
            do_action('wpml_clear_string_translation_cache');
        }

        wp_die('MetaBox translations reset for fields: ' . implode(', ', $fieldsToReset) . '. Please refresh and check your pages.');
    }

    /**
     * Check if feature has information
     */
    public function hasInformation(): bool
    {
        return true;
    }

    /**
     * Get information blocks for dashboard
     */
    public function getInformationBlocks(): array
    {
        return [
            [
                'type' => 'list',
                'title' => sprintf(__('Billing dynamic tags (%d)', 'zero-sense'), count(self::BILLING_FIELDS)),
                'items' => $this->generateDynamicTagsList('woo_billing_', self::BILLING_FIELDS),
            ],
            [
                'type' => 'list',
                'title' => sprintf(__('Shipping dynamic tags (%d)', 'zero-sense'), count(self::SHIPPING_FIELDS)),
                'items' => $this->generateDynamicTagsList('woo_shipping_', self::SHIPPING_FIELDS),
            ],
            [
                'type' => 'list',
                'title' => sprintf(__('Meta Box dynamic tags (%d)', 'zero-sense'), count(self::META_BOX_FIELDS)),
                'items' => $this->generateDynamicTagsList('woo_mb_', self::META_BOX_FIELDS),
            ],
            [
                'type' => 'list',
                'title' => __('Order dynamic tags (1)', 'zero-sense'),
                'items' => [
                    '{woo_order_note} - Customer Note',
                ],
            ],
            [
                'type' => 'text',
                'title' => sprintf(__('Total tags available: %d', 'zero-sense'), $this->getTotalTagsCount()),
                'content' => __('All tags are automatically generated from code constants. Add new fields to BILLING_FIELDS, SHIPPING_FIELDS, or META_BOX_FIELDS and they will appear here automatically.', 'zero-sense'),
            ],
            [
                'type' => 'text',
                'title' => __('Integration', 'zero-sense'),
                'content' => __('Tags appear in Bricks Builder dynamic data panel under "WooCommerce" group. Works with WPML for multilingual content and Meta Box custom fields.', 'zero-sense'),
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/Integrations/Bricks/BricksDynamicTags.php', 'zero-sense'),
                    __('registerDynamicTags() → adds tags to bricks/dynamic_tags_list', 'zero-sense'),
                    __('renderTag() / renderContent() → resolves values for tags', 'zero-sense'),
                    __('getTranslatedMetaValue() → WPML-aware Meta Box values', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('bricks/dynamic_tags_list', 'zero-sense'),
                    __('bricks/dynamic_data/render_tag', 'zero-sense'),
                    __('bricks/dynamic_data/render_content', 'zero-sense'),
                    __('bricks/frontend/render_data', 'zero-sense'),
                    __('wp (maybeResetMetaBoxTranslations)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('In Bricks Builder, insert text with tags like {woo_billing_first_name} on a single order template.', 'zero-sense'),
                    __('Preview an order to confirm dynamic values resolve; switch language to validate WPML.', 'zero-sense'),
                    __('Check Meta Box fields appear via {woo_mb_*} and formatted dates for event_date.', 'zero-sense'),
                    __('Optionally run ?reset_metabox_translations=1 as admin to reset stale WPML strings (see method).', 'zero-sense'),
                ],
            ],
        ];
    }

    private function getEventMediaGallery($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Event Media Gallery');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $raw = $order->get_meta('_zs_event_media', true);
        if (!is_string($raw) || $raw === '') {
            return '';
        }

        $ids = array_filter(array_map('trim', explode(',', $raw)));
        if (empty($ids)) {
            return '';
        }

        $html = '<div class="zs-event-media-gallery">';
        foreach ($ids as $id) {
            $url = wp_get_attachment_url((int) $id);
            $type = get_post_mime_type((int) $id);
            if (!$url) {
                continue;
            }

            if (is_string($type) && strpos($type, 'video') !== false) {
                $html .= '<div class="zs-gallery-item zs-gallery-video"><video src="' . esc_url($url) . '" controls></video></div>';
            } else {
                $thumb = wp_get_attachment_image_url((int) $id, 'medium');
                $html .= '<div class="zs-gallery-item zs-gallery-image"><a href="' . esc_url($url) . '" target="_blank" rel="noopener"><img src="' . esc_url($thumb ?: $url) . '" alt=""></a></div>';
            }
        }
        $html .= '</div>';

        return $html;
    }

    private function getEventMediaUrls($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Event Media URLs');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $raw = $order->get_meta('_zs_event_media', true);
        if (!is_string($raw) || $raw === '') {
            return '';
        }

        $ids = array_filter(array_map('trim', explode(',', $raw)));
        $urls = [];
        foreach ($ids as $id) {
            $url = wp_get_attachment_url((int) $id);
            if ($url) {
                $urls[] = $url;
            }
        }

        return implode(',', $urls);
    }

    /**
     * Generate dynamic tags list from field constants
     */
    private function generateDynamicTagsList(string $prefix, array $fields): array
    {
        $tags = [];
        foreach ($fields as $field => $label) {
            $tags[] = sprintf('{%s%s} - %s', $prefix, $field, $label);
        }
        return $tags;
    }

    /**
     * Get total count of all available tags
     */
    private function getTotalTagsCount(): int
    {
        return count(self::BILLING_FIELDS) + count(self::SHIPPING_FIELDS) + count(self::META_BOX_FIELDS) + 1; // +1 for order_note
    }
}
