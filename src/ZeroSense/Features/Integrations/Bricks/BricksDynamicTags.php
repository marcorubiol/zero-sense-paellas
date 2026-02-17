<?php

declare(strict_types=1);

namespace ZeroSense\Features\Integrations\Bricks;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Core\MetaFieldRegistry;
use ZeroSense\Features\WooCommerce\Schema\SchemaRegistry;
use ZeroSense\Features\WooCommerce\EventManagement\Components\FieldChangeTracker;
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

    private const META_PRODUCT_RECIPE_ID = 'zs_recipe_id';
    private const META_RECIPE_INGREDIENTS = 'zs_recipe_ingredients';
    private const META_RECIPE_UTENSILS = 'zs_recipe_utensils';
    private const TAX_INGREDIENT = 'zs_ingredient';
    private const TAX_UTENSIL = 'zs_utensil';
    private const CPT_RECIPE = 'zs_recipe';
    private const META_NEEDS_PAELLA = 'zs_recipe_needs_paella';
    
    private const META_EVENT_ADULTS = 'zs_event_adults';
    private const META_EVENT_CHILDREN = 'zs_event_children_5_to_8';
    private const META_EVENT_BABIES = 'zs_event_children_0_to_4';
    
    private const ADULT_WEIGHT = 1.0;
    private const CHILD_WEIGHT = 0.4;
    private const BABY_WEIGHT = 0.0;

    /**
     * Get MetaBox fields from registry
     */
    private function getMetaBoxFields(): array
    {
        $registry = MetaFieldRegistry::getInstance();
        $fields = [];
        
        foreach ($registry->getAllFields() as $key => $metadata) {
            // Use the main key (zs_) and strip the prefix for the tag name
            if (str_starts_with($key, 'zs_')) {
                $tagName = substr($key, 3); // Remove 'zs_' prefix
                $fields[$tagName] = $metadata['label'] ?? $tagName;
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
        add_action('woocommerce_process_shop_order_meta', [$this, 'trackBillingShippingChangesOnSave'], 999);
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
    
    public function trackBillingShippingChangesOnSave(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        $billingFields = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'country', 'state', 'email', 'phone'];
        $shippingFields = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'country', 'state', 'phone', 'email'];

        foreach ($billingFields as $field) {
            $postKey = '_billing_' . $field;
            if (isset($_POST[$postKey])) {
                $oldValue = $order->{"get_billing_$field"}();
                $newValue = sanitize_text_field((string) $_POST[$postKey]);
                FieldChangeTracker::compareAndTrack($orderId, $postKey, $oldValue, $newValue);
            }
        }

        foreach ($shippingFields as $field) {
            $postKey = '_shipping_' . $field;
            if (isset($_POST[$postKey])) {
                if ($field === 'phone' || $field === 'email') {
                    $oldValue = $order->get_meta($postKey, true);
                } else {
                    $oldValue = $order->{"get_shipping_$field"}();
                }
                $newValue = sanitize_text_field((string) $_POST[$postKey]);
                FieldChangeTracker::compareAndTrack($orderId, $postKey, $oldValue, $newValue);
            }
        }
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

    private function wrapIfRecentlyChanged(string $value, string $fieldKey, $post): string
    {
        if ($value === '' || $value === null) {
            return $value;
        }

        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $value;
        }

        if (!FieldChangeTracker::isFieldRecentlyChanged($orderId, $fieldKey)) {
            return $value;
        }

        $timestamp = FieldChangeTracker::getFieldChangeTimestamp($orderId, $fieldKey);
        $timestampAttr = $timestamp ? ' data-changed="' . esc_attr($timestamp) . '"' : '';
        
        return '<span class="zs-recent-change" data-field="' . esc_attr($fieldKey) . '"' . $timestampAttr . '>' . $value . '</span>';
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

        // Dynamic schema tags - auto-generated from SchemaRegistry
        $schemaRegistry = SchemaRegistry::getInstance();
        foreach ($schemaRegistry->getAll() as $schemaKey => $schema) {
            $schemaTitle = $schema->getSchemaTitle();
            
            // Individual field tags
            foreach ($this->getSchemaFields($schemaKey) as $field => $label) {
                $tags[] = [
                    'name' => '{woo_' . $schemaKey . '_' . $field . '}',
                    'label' => $label . ' (' . $schemaTitle . ')',
                    'group' => 'WooCommerce',
                ];
            }
            
            // Complete list tag
            $tags[] = [
                'name' => '{woo_' . $schemaKey . '_list}',
                'label' => $schemaTitle . ' (Complete List)',
                'group' => 'WooCommerce',
            ];
        }

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

        $tags[] = [
            'name' => '{woo_zs_order_recipes}',
            'label' => 'Order Recipes (Detailed with Products)',
            'group' => 'WooCommerce',
        ];

        $tags[] = [
            'name' => '{woo_zs_order_recipes_simple}',
            'label' => 'Order Recipes (Simple List)',
            'group' => 'WooCommerce',
        ];

        $tags[] = [
            'name' => '{woo_zs_order_has_recipes}',
            'label' => 'Order Has Recipes (1/0)',
            'group' => 'WooCommerce',
        ];

        $tags[] = [
            'name' => '{woo_zs_order_ingredients_total}',
            'label' => 'Order Ingredients (Total Calculated)',
            'group' => 'WooCommerce',
        ];

        $tags[] = [
            'name' => '{woo_zs_order_ingredients_simple}',
            'label' => 'Order Ingredients (Simple - Only Total)',
            'group' => 'WooCommerce',
        ];

        return $tags;
    }

    private function getSchemaFields(string $schemaKey): array
    {
        $schemaRegistry = SchemaRegistry::getInstance();
        $schema = $schemaRegistry->get($schemaKey);
        
        if ($schema === null) {
            return [];
        }
        
        $schemaData = $schema->getSchema();
        if (!is_array($schemaData) || $schemaData === []) {
            return [];
        }

        $fields = [];
        foreach ($schemaData as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = isset($row['key']) ? sanitize_key((string) $row['key']) : '';
            $label = isset($row['label']) ? sanitize_text_field((string) $row['label']) : '';
            if ($key === '' || $label === '') {
                continue;
            }

            $name = 'ops_' . $schemaKey . '_label_' . $key;
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

        if ($tag === '{woo_mb_event_staff_all}') {
            return $this->getEventStaffFormatted($post);
        }

        if (strpos($tag, '{woo_mb_') === 0) {
            $field = $this->stripTag($tag, 'woo_mb_');
            return $this->getMetaBoxFieldValue($field, $post);
        }

        if ($tag === '{woo_ops_notes}') {
            return $this->getOpsNotesValue($post);
        }

        // Dynamic schema tag handling
        $schemaRegistry = SchemaRegistry::getInstance();
        foreach ($schemaRegistry->getKeys() as $schemaKey) {
            // List tag: {woo_material_list}, {woo_workspace_list}
            if ($tag === '{woo_' . $schemaKey . '_list}') {
                return $this->getSchemaList($schemaKey, $post);
            }
            
            // Individual field tags: {woo_material_field_name}, {woo_workspace_field_name}
            if (strpos($tag, '{woo_' . $schemaKey . '_') === 0) {
                $field = $this->stripTag($tag, 'woo_' . $schemaKey . '_');
                return $this->getSchemaFieldValue($schemaKey, $field, $post);
            }
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

        if ($tag === '{woo_zs_order_recipes}') {
            return $this->getOrderRecipes($post);
        }

        if ($tag === '{woo_zs_order_recipes_simple}') {
            return $this->getOrderRecipesSimple($post);
        }

        if ($tag === '{woo_zs_order_has_recipes}') {
            return $this->getOrderHasRecipes($post);
        }

        if ($tag === '{woo_zs_order_ingredients_total}') {
            return $this->getOrderIngredientsTotal($post);
        }

        if ($tag === '{woo_zs_order_ingredients_simple}') {
            return $this->getOrderIngredientsSimple($post);
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

        $content = str_replace('{woo_mb_event_staff_all}', $this->getEventStaffFormatted($post), $content);

        $content = $this->replaceTagsInContent($content, $post, 'woo_mb_', function (string $field) use ($post): string {
            return $this->getMetaBoxFieldValue($field, $post);
        });

        $content = str_replace('{woo_ops_notes}', $this->getOpsNotesValue($post), $content);

        // Dynamic schema content replacement
        $schemaRegistry = SchemaRegistry::getInstance();
        foreach ($schemaRegistry->getKeys() as $schemaKey) {
            // Replace list tags
            $content = str_replace('{woo_' . $schemaKey . '_list}', $this->getSchemaList($schemaKey, $post), $content);
            
            // Replace individual field tags
            $content = $this->replaceTagsInContent($content, $post, 'woo_' . $schemaKey . '_', function (string $field) use ($post, $schemaKey): string {
                return $this->getSchemaFieldValue($schemaKey, $field, $post);
            });
        }

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

        $content = str_replace('{woo_zs_order_recipes}', $this->getOrderRecipes($post), $content);
        $content = str_replace('{woo_zs_order_recipes_simple}', $this->getOrderRecipesSimple($post), $content);
        $content = str_replace('{woo_zs_order_has_recipes}', $this->getOrderHasRecipes($post), $content);
        $content = str_replace('{woo_zs_order_ingredients_total}', $this->getOrderIngredientsTotal($post), $content);
        $content = str_replace('{woo_zs_order_ingredients_simple}', $this->getOrderIngredientsSimple($post), $content);

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
            $value = $this->formatCountry($value);
        } elseif ($field === 'state') {
            $country = $order->get_billing_country();
            $value = $this->formatState($value, $country);
        }

        return $this->wrapIfRecentlyChanged($value, '_billing_' . $field, $post);
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
        $value = is_string($raw) ? $raw : '';

        return $this->wrapIfRecentlyChanged($value, 'zs_ops_notes', $post);
    }

    private function getSchemaList(string $schemaKey, $post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            $schemaRegistry = SchemaRegistry::getInstance();
            $schema = $schemaRegistry->get($schemaKey);
            $title = $schema ? $schema->getSchemaTitle() : ucfirst($schemaKey);
            return $this->builderPlaceholder($title . ' List');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $schemaRegistry = SchemaRegistry::getInstance();
        $schema = $schemaRegistry->get($schemaKey);
        
        if ($schema === null) {
            return '';
        }
        
        $schemaData = $schema->getSchema();
        if (!is_array($schemaData) || $schemaData === []) {
            return '';
        }

        $metaKey = $schema->getMetaKey();
        $savedData = $order->get_meta($metaKey, true);
        if (!is_array($savedData)) {
            $savedData = [];
        }

        $html = '<div class="zs-schema-list zs-' . esc_attr($schemaKey) . '-list">';
        
        foreach ($schemaData as $row) {
            if (!is_array($row)) {
                continue;
            }
            
            $key = isset($row['key']) ? sanitize_key((string) $row['key']) : '';
            $label = isset($row['label']) ? sanitize_text_field((string) $row['label']) : '';
            $type = isset($row['type']) ? sanitize_key((string) $row['type']) : 'text';
            
            if ($key === '' || $label === '') {
                continue;
            }

            $name = 'ops_' . $schemaKey . '_label_' . $key;
            $translatedLabel = apply_filters('wpml_translate_single_string', $label, 'zero-sense', $name);
            $finalLabel = is_string($translatedLabel) && $translatedLabel !== '' ? $translatedLabel : $label;

            $entry = $savedData[$key] ?? null;
            if (is_array($entry) && array_key_exists('value', $entry)) {
                $value = $entry['value'];
            } else {
                $value = $entry;
            }

            if ($type === 'bool') {
                $yesText = apply_filters('wpml_translate_single_string', 'Yes', 'zero-sense', $schemaKey . '_yes');
                $noText = apply_filters('wpml_translate_single_string', 'No', 'zero-sense', $schemaKey . '_no');
                $displayValue = ($value === '1' || $value === 1) ? $yesText : $noText;
                $isEmpty = false;
            } elseif ($type === 'qty_int') {
                $displayValue = is_numeric($value) && $value > 0 ? (string) $value : '-';
                $isEmpty = ($displayValue === '-');
            } else {
                $displayValue = is_scalar($value) && $value !== '' ? (string) $value : '-';
                $isEmpty = ($displayValue === '-');
            }

            if (!$isEmpty || $type === 'bool') {
                $html .= '<div class="zs-schema-item">';
                $html .= '<strong>' . esc_html($finalLabel) . ':</strong> ';
                $html .= '<span>' . esc_html($displayValue) . '</span>';
                $html .= '</div>';
            }
        }
        
        $html .= '</div>';
        
        return $html;
    }

    private function getSchemaFieldValue(string $schemaKey, string $field, $post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder(ucfirst($schemaKey) . ' ' . $field);
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $schemaRegistry = SchemaRegistry::getInstance();
        $schema = $schemaRegistry->get($schemaKey);
        
        if ($schema === null) {
            return '';
        }
        
        $metaKey = $schema->getMetaKey();
        $raw = $order->get_meta($metaKey, true);

        if (!is_array($raw)) {
            return '';
        }

        $entry = $raw[$field] ?? null;
        if (is_array($entry) && array_key_exists('value', $entry)) {
            $entry = $entry['value'];
        }

        $value = '';
        if (is_scalar($entry)) {
            $value = (string) $entry;
        }

        $fieldKey = $metaKey . '_' . $field;
        return $this->wrapIfRecentlyChanged($value, $fieldKey, $post);
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
            $value = is_string($value) ? $value : '';
            return $this->wrapIfRecentlyChanged($value, '_shipping_phone', $post);
        }

        if ($field === 'email') {
            $raw = $order->get_meta('_shipping_email', true);
            $value = is_string($raw) ? $raw : '';
            return $this->wrapIfRecentlyChanged($value, '_shipping_email', $post);
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
            $value = $this->formatCountry($value);
        } elseif ($field === 'state') {
            $country = $order->get_shipping_country();
            $value = $this->formatState($value, $country);
        }

        return $this->wrapIfRecentlyChanged($value, '_shipping_' . $field, $post);
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

        $value = $this->getTranslatedMetaValue($orderId, $field);
        
        $fieldKey = 'zs_' . $field;
        return $this->wrapIfRecentlyChanged($value, $fieldKey, $post);
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

        if (isset($_GET['zs_event_token']) && is_string($_GET['zs_event_token'])) {
            $token = sanitize_text_field($_GET['zs_event_token']);
            $orders = wc_get_orders([
                'limit' => 1,
                'meta_key' => 'zs_event_token',
                'meta_value' => $token,
                'return' => 'ids',
            ]);
            if (!empty($orders) && is_array($orders)) {
                return (int) $orders[0];
            }
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
        $registry = MetaFieldRegistry::getInstance();
        $metadata = $registry->getFieldMetadata($metaKey);
        if ($metadata && ($metadata['type'] ?? '') === 'select') {
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
        // Use Zero Sense FieldOptions for event fields
        if ($fieldId === 'event_type') {
            if (class_exists('ZeroSense\\Features\\WooCommerce\\EventManagement\\Support\\FieldOptions')) {
                return \ZeroSense\Features\WooCommerce\EventManagement\Support\FieldOptions::getEventTypeOptions();
            }
        }
        
        if ($fieldId === 'how_found_us') {
            if (class_exists('ZeroSense\\Features\\WooCommerce\\EventManagement\\Support\\FieldOptions')) {
                return \ZeroSense\Features\WooCommerce\EventManagement\Support\FieldOptions::getHowFoundUsOptions();
            }
        }
        
        // Fallback to MetaBox for legacy fields
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
                'title' => sprintf(__('Meta Box dynamic tags (%d)', 'zero-sense'), count($this->getMetaBoxFields())),
                'items' => $this->generateDynamicTagsList('woo_mb_', $this->getMetaBoxFields()),
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
                'content' => __('All tags are automatically generated from code constants and MetaFieldRegistry. Add new fields to BILLING_FIELDS, SHIPPING_FIELDS, or register them in MetaFieldRegistry and they will appear here automatically.', 'zero-sense'),
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

        $galleryId = 'zs-gallery-' . uniqid();
        
        $html = '<style>
            .zs-event-media-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; max-width: 650px; }
            .zs-gallery-item { border: 1px solid #ddd; border-radius: 4px; overflow: hidden; background: #f9f9f9; aspect-ratio: 1; display: flex; align-items: center; justify-content: center; }
            .zs-gallery-item img, .zs-gallery-item video { width: 100%; height: 100%; object-fit: cover; display: block; cursor: pointer; }
            .zs-gallery-item a { display: block; width: 100%; height: 100%; }
            .zs-lightbox { display: none; position: fixed; z-index: 999999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); align-items: center; justify-content: center; }
            .zs-lightbox.active { display: flex; }
            .zs-lightbox img { max-width: 90%; max-height: 90%; object-fit: contain; background: #fff; padding: 10px; box-shadow: 0 0 30px rgba(0,0,0,0.7); position: relative; z-index: 2; }
            .zs-lightbox-close { position: absolute; top: 20px; right: 30px; color: #fff; font-size: 40px; font-weight: bold; text-decoration: none; cursor: pointer; z-index: 3; line-height: 1; }
            .zs-lightbox-close:hover { color: #ccc; }
            .zs-lightbox-nav { position: absolute; top: 50%; transform: translateY(-50%); color: #fff; font-size: 50px; font-weight: bold; cursor: pointer; z-index: 3; padding: 20px; user-select: none; }
            .zs-lightbox-nav:hover { color: #ccc; }
            .zs-lightbox-prev { left: 20px; }
            .zs-lightbox-next { right: 20px; }
            .zs-lightbox-counter { position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); color: #fff; font-size: 14px; z-index: 3; }
        </style>';
        
        $html .= '<script>
            (function() {
                var galleryId = "' . esc_js($galleryId) . '";
                var currentIndex = 0;
                var lightboxes = [];
                var touchStartX = 0;
                var touchEndX = 0;
                
                function closeLightbox(e) {
                    if (e) e.preventDefault();
                    lightboxes.forEach(function(lb) { lb.classList.remove("active"); });
                }
                
                function showLightbox(index) {
                    lightboxes.forEach(function(lb) { lb.classList.remove("active"); });
                    if (lightboxes[index]) {
                        lightboxes[index].classList.add("active");
                        currentIndex = index;
                        updateCounter();
                    }
                }
                
                function nextImage(e) {
                    if (e) e.preventDefault();
                    var next = (currentIndex + 1) % lightboxes.length;
                    showLightbox(next);
                }
                
                function prevImage(e) {
                    if (e) e.preventDefault();
                    var prev = (currentIndex - 1 + lightboxes.length) % lightboxes.length;
                    showLightbox(prev);
                }
                
                function updateCounter() {
                    var counters = document.querySelectorAll("." + galleryId + " .zs-lightbox-counter");
                    counters.forEach(function(counter, idx) {
                        if (idx === currentIndex) {
                            counter.textContent = (currentIndex + 1) + " / " + lightboxes.length;
                        }
                    });
                }
                
                function handleTouchStart(e) {
                    touchStartX = e.changedTouches[0].screenX;
                }
                
                function handleTouchEnd(e) {
                    touchEndX = e.changedTouches[0].screenX;
                    handleSwipe();
                }
                
                function handleSwipe() {
                    if (touchEndX < touchStartX - 50) nextImage();
                    if (touchEndX > touchStartX + 50) prevImage();
                }
                
                document.addEventListener("DOMContentLoaded", function() {
                    lightboxes = Array.from(document.querySelectorAll(".zs-lightbox." + galleryId));
                    
                    var gallery = document.querySelector(".zs-event-media-gallery." + galleryId);
                    if (!gallery) return;
                    
                    gallery.querySelectorAll(".zs-gallery-open").forEach(function(opener, idx) {
                        opener.addEventListener("click", function(e) {
                            e.preventDefault();
                            showLightbox(idx);
                        });
                    });
                    
                    lightboxes.forEach(function(lightbox) {
                        lightbox.addEventListener("click", function(e) {
                            if (e.target === this) closeLightbox(e);
                        });
                        
                        lightbox.addEventListener("touchstart", handleTouchStart, false);
                        lightbox.addEventListener("touchend", handleTouchEnd, false);
                    });
                    
                    document.querySelectorAll(".zs-lightbox." + galleryId + " .zs-lightbox-close").forEach(function(closeBtn) {
                        closeBtn.addEventListener("click", closeLightbox);
                    });
                    
                    document.querySelectorAll(".zs-lightbox." + galleryId + " .zs-lightbox-prev").forEach(function(prevBtn) {
                        prevBtn.addEventListener("click", prevImage);
                    });
                    
                    document.querySelectorAll(".zs-lightbox." + galleryId + " .zs-lightbox-next").forEach(function(nextBtn) {
                        nextBtn.addEventListener("click", nextImage);
                    });
                    
                    document.addEventListener("keydown", function(e) {
                        var isActive = lightboxes.some(function(lb) { return lb.classList.contains("active"); });
                        if (!isActive) return;
                        
                        if (e.key === "Escape") closeLightbox(e);
                        if (e.key === "ArrowLeft") prevImage(e);
                        if (e.key === "ArrowRight") nextImage(e);
                    });
                });
            })();
        </script>';
        
        $html .= '<div class="zs-event-media-gallery ' . esc_attr($galleryId) . '">';
        $index = 0;
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
                $html .= '<div class="zs-gallery-item zs-gallery-image">';
                $html .= '<a href="#" class="zs-gallery-open"><img src="' . esc_url($thumb ?: $url) . '" alt=""></a>';
                $html .= '</div>';
                $index++;
            }
        }
        $html .= '</div>';
        
        foreach ($ids as $id) {
            $url = wp_get_attachment_url((int) $id);
            $type = get_post_mime_type((int) $id);
            if (!$url || (is_string($type) && strpos($type, 'video') !== false)) {
                continue;
            }
            
            $html .= '<div class="zs-lightbox ' . esc_attr($galleryId) . '">';
            $html .= '<a href="#" class="zs-lightbox-close">&times;</a>';
            $html .= '<div class="zs-lightbox-prev zs-lightbox-nav">&#8249;</div>';
            $html .= '<div class="zs-lightbox-next zs-lightbox-nav">&#8250;</div>';
            $html .= '<img src="' . esc_url($url) . '" alt="">';
            $html .= '<div class="zs-lightbox-counter"></div>';
            $html .= '</div>';
        }

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
        return count(self::BILLING_FIELDS) + count(self::SHIPPING_FIELDS) + count($this->getMetaBoxFields()) + 1; // +1 for order_note
    }

    /**
     * Calculate equivalent pax for an order
     */
    private function getEquivalentPax(WC_Order $order): float
    {
        $adults = (int) $order->get_meta(self::META_EVENT_ADULTS, true);
        $children = (int) $order->get_meta(self::META_EVENT_CHILDREN, true);
        $babies = (int) $order->get_meta(self::META_EVENT_BABIES, true);

        $eq = ($adults * self::ADULT_WEIGHT) + ($children * self::CHILD_WEIGHT) + ($babies * self::BABY_WEIGHT);
        return $eq > 0 ? (float) $eq : 0.0;
    }

    /**
     * Format number removing trailing zeros
     */
    private function formatNumber(float $n): string
    {
        $s = number_format($n, 3, '.', '');
        $s = rtrim($s, '0');
        $s = rtrim($s, '.');
        return $s === '' ? '0' : $s;
    }

    /**
     * Normalize units: convert g/kg and ml/l automatically based on quantity
     * Returns array with normalized quantity and unit
     */
    private function normalizeUnit(float $qty, string $unit): array
    {
        // Convert g to kg if >= 1000g
        if ($unit === 'g' && $qty >= 1000) {
            return [
                'qty' => $qty / 1000,
                'unit' => 'kg'
            ];
        }

        // Convert kg to g if < 1kg (for consistency)
        if ($unit === 'kg' && $qty < 1) {
            return [
                'qty' => $qty * 1000,
                'unit' => 'g'
            ];
        }

        // Convert ml to l if >= 1000ml
        if ($unit === 'ml' && $qty >= 1000) {
            return [
                'qty' => $qty / 1000,
                'unit' => 'l'
            ];
        }

        // Convert l to ml if < 1l (for consistency)
        if ($unit === 'l' && $qty < 1) {
            return [
                'qty' => $qty * 1000,
                'unit' => 'ml'
            ];
        }

        // Keep as is for other units (u, etc.)
        return [
            'qty' => $qty,
            'unit' => $unit
        ];
    }

    /**
     * Get translated recipe title
     */
    private function getTranslatedRecipeTitle(int $recipeId, string $orderLanguage): string
    {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            $recipe = get_post($recipeId);
            return $recipe instanceof \WP_Post ? $recipe->post_title : '';
        }

        $translatedId = apply_filters('wpml_object_id', $recipeId, self::CPT_RECIPE, false, $orderLanguage);
        if (!$translatedId) {
            $translatedId = $recipeId;
        }

        $recipe = get_post($translatedId);
        return $recipe instanceof \WP_Post ? $recipe->post_title : '';
    }

    /**
     * Get translated ingredient name
     */
    private function getTranslatedIngredientName(int $termId, string $orderLanguage): string
    {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            $term = get_term($termId, self::TAX_INGREDIENT);
            return $term instanceof \WP_Term ? $term->name : '';
        }

        $translatedId = apply_filters('wpml_object_id', $termId, self::TAX_INGREDIENT, false, $orderLanguage);
        if (!$translatedId) {
            $translatedId = $termId;
        }

        $term = get_term($translatedId, self::TAX_INGREDIENT);
        return $term instanceof \WP_Term ? $term->name : '';
    }

    /**
     * Get order language code
     */
    private function getOrderLanguageCode(WC_Order $order): string
    {
        $language = $order->get_meta('wpml_language', true);
        if (!is_string($language) || $language === '') {
            $language = defined('ICL_SITEPRESS_VERSION') ? apply_filters('wpml_default_language', 'es') : 'es';
        }
        return $language;
    }

    /**
     * Check if order has any products with recipes
     */
    private function getOrderHasRecipes($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return '0';
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '0';
        }

        $lineItems = $order->get_items('line_item');
        if (!$lineItems) {
            return '0';
        }

        foreach ($lineItems as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $product = $item->get_product();
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $recipeId = (int) $product->get_meta(self::META_PRODUCT_RECIPE_ID, true);
            if ($recipeId > 0) {
                return '1';
            }
        }

        return '0';
    }

    /**
     * Get simple list of recipe names (comma-separated, no duplicates)
     */
    private function getOrderRecipesSimple($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Order Recipes');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $orderLanguage = $this->getOrderLanguageCode($order);
        $lineItems = $order->get_items('line_item');
        if (!$lineItems) {
            return '';
        }

        $recipeNames = [];
        $seenRecipes = [];

        foreach ($lineItems as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $product = $item->get_product();
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $recipeId = (int) $product->get_meta(self::META_PRODUCT_RECIPE_ID, true);
            if ($recipeId <= 0 || isset($seenRecipes[$recipeId])) {
                continue;
            }

            $seenRecipes[$recipeId] = true;
            $recipeName = $this->getTranslatedRecipeTitle($recipeId, $orderLanguage);
            if ($recipeName !== '') {
                $recipeNames[] = $recipeName;
            }
        }

        return implode(', ', $recipeNames);
    }

    /**
     * Get detailed recipes with products and calculated ingredients
     */
    private function getOrderRecipes($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Order Recipes');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $orderLanguage = $this->getOrderLanguageCode($order);
        $eqTotal = $this->getEquivalentPax($order);
        if ($eqTotal <= 0) {
            return '';
        }

        $lineItems = $order->get_items('line_item');
        if (!$lineItems) {
            return '';
        }

        // Group products by recipe
        $recipeGroups = [];
        $sumQty = 0.0;

        foreach ($lineItems as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $qty = (float) $item->get_quantity();
            if ($qty <= 0) {
                continue;
            }

            $product = $item->get_product();
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $recipeId = (int) $product->get_meta(self::META_PRODUCT_RECIPE_ID, true);
            if ($recipeId <= 0) {
                continue;
            }

            if (!isset($recipeGroups[$recipeId])) {
                $recipeGroups[$recipeId] = [
                    'recipe_title' => $this->getTranslatedRecipeTitle($recipeId, $orderLanguage),
                    'products' => [],
                    'total_qty' => 0.0,
                    'recipe_id' => $recipeId,
                ];
            }

            $recipeGroups[$recipeId]['products'][] = [
                'name' => $item->get_name(),
                'qty' => $qty,
            ];
            $recipeGroups[$recipeId]['total_qty'] += $qty;
            $sumQty += $qty;
        }

        if (empty($recipeGroups) || $sumQty <= 0) {
            return '';
        }

        // Calculate ingredients for each recipe
        foreach ($recipeGroups as $recipeId => &$group) {
            $eqItem = $eqTotal * ($group['total_qty'] / $sumQty);
            
            $recipeIngredients = get_post_meta($recipeId, self::META_RECIPE_INGREDIENTS, true);
            if (!is_array($recipeIngredients)) {
                $group['ingredients'] = [];
                continue;
            }

            $ingredients = [];
            foreach ($recipeIngredients as $ingRow) {
                if (!is_array($ingRow)) {
                    continue;
                }

                $termId = isset($ingRow['ingredient']) ? (int) $ingRow['ingredient'] : 0;
                $perPax = isset($ingRow['qty']) ? (float) $ingRow['qty'] : 0.0;
                $unit = isset($ingRow['unit']) ? sanitize_key((string) $ingRow['unit']) : '';

                if ($termId <= 0 || $perPax <= 0 || $unit === '') {
                    continue;
                }

                $amount = $eqItem * $perPax;
                if ($amount <= 0) {
                    continue;
                }

                $ingredients[] = [
                    'term_id' => $termId,
                    'name' => $this->getTranslatedIngredientName($termId, $orderLanguage),
                    'qty' => $amount,
                    'unit' => $unit,
                ];
            }

            $group['ingredients'] = $ingredients;
        }

        // Generate HTML
        $html = '<div class="zs-order-recipes">';

        foreach ($recipeGroups as $group) {
            $html .= '<div class="zs-recipe-item">';
            
            // Recipe title
            $html .= '<h4 class="zs-recipe-name">' . esc_html($group['recipe_title']) . '</h4>';
            
            // Products list
            $productsList = [];
            foreach ($group['products'] as $prod) {
                $productsList[] = esc_html($prod['name']) . ' (' . esc_html($this->formatNumber($prod['qty'])) . 'x)';
            }
            $html .= '<div class="zs-recipe-products"><strong>' . esc_html__('Products:', 'zero-sense') . '</strong> ' . implode(', ', $productsList) . '</div>';
            
            // Ingredients
            if (!empty($group['ingredients'])) {
                $html .= '<div class="zs-recipe-ingredients">';
                foreach ($group['ingredients'] as $ing) {
                    // Normalize units
                    $normalized = $this->normalizeUnit($ing['qty'], $ing['unit']);
                    
                    $html .= '<div class="zs-ingredient-row">';
                    $html .= '<span class="zs-ingredient-name">' . esc_html($ing['name']) . '</span> ';
                    $html .= '<span class="zs-ingredient-qty">' . esc_html($this->formatNumber($normalized['qty'])) . '</span>';
                    $html .= '<span class="zs-ingredient-unit">' . esc_html($normalized['unit']) . '</span>';
                    $html .= '</div>';
                }
                $html .= '</div>';
            }
            
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Get total ingredients table (sum of all recipes)
     */
    private function getOrderIngredientsTotal($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Order Ingredients Total');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $orderLanguage = $this->getOrderLanguageCode($order);
        $eqTotal = $this->getEquivalentPax($order);
        if ($eqTotal <= 0) {
            return '';
        }

        $lineItems = $order->get_items('line_item');
        if (!$lineItems) {
            return '';
        }

        $eligible = [];
        $sumQty = 0.0;

        foreach ($lineItems as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $qty = (float) $item->get_quantity();
            if ($qty <= 0) {
                continue;
            }

            $product = $item->get_product();
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $recipeId = (int) $product->get_meta(self::META_PRODUCT_RECIPE_ID, true);
            if ($recipeId <= 0) {
                continue;
            }

            $eligible[] = ['recipe_id' => $recipeId, 'qty' => $qty];
            $sumQty += $qty;
        }

        if (empty($eligible) || $sumQty <= 0) {
            return '';
        }

        $totals = [];
        foreach ($eligible as $row) {
            $recipeId = (int) $row['recipe_id'];
            $qty = (float) $row['qty'];

            $eqItem = $eqTotal * ($qty / $sumQty);
            if ($eqItem <= 0) {
                continue;
            }

            $recipeIngredients = get_post_meta($recipeId, self::META_RECIPE_INGREDIENTS, true);
            if (!is_array($recipeIngredients)) {
                continue;
            }

            foreach ($recipeIngredients as $ingRow) {
                if (!is_array($ingRow)) {
                    continue;
                }

                $termId = isset($ingRow['ingredient']) ? (int) $ingRow['ingredient'] : 0;
                $perPax = isset($ingRow['qty']) ? (float) $ingRow['qty'] : 0.0;
                $unit = isset($ingRow['unit']) ? sanitize_key((string) $ingRow['unit']) : '';

                if ($termId <= 0 || $perPax <= 0 || $unit === '') {
                    continue;
                }

                $amount = $eqItem * $perPax;
                if ($amount <= 0) {
                    continue;
                }

                $k = $termId . '|' . $unit;
                if (!isset($totals[$k])) {
                    $totals[$k] = ['term_id' => $termId, 'unit' => $unit, 'qty' => 0.0];
                }
                $totals[$k]['qty'] += $amount;
            }
        }

        if (empty($totals)) {
            return '';
        }

        usort($totals, function(array $a, array $b): int {
            $ta = $a['term_id'] ?? 0;
            $tb = $b['term_id'] ?? 0;
            return $ta <=> $tb;
        });

        // Get guest counts for header
        $adults = (int) $order->get_meta(self::META_EVENT_ADULTS, true);
        $children = (int) $order->get_meta(self::META_EVENT_CHILDREN, true);
        $babies = (int) $order->get_meta(self::META_EVENT_BABIES, true);

        $html = '<style>
            :where(.zs-ingredients-wrapper) { margin: 20px 0; }
            :where(.zs-ingredients-info) { margin-bottom: 15px; padding: 10px; background: #f5f5f5; border-radius: 4px; }
            :where(.zs-event-ingredients) { width: 100%; border-collapse: collapse; }
            :where(.zs-event-ingredients thead) { background: #333; color: white; }
            :where(.zs-event-ingredients th) { padding: 10px; text-align: left; border: 1px solid #ddd; }
            :where(.zs-event-ingredients td) { padding: 8px; border: 1px solid #ddd; }
            :where(.zs-event-ingredients .zs-col-total) { text-align: center; font-weight: bold; background: #fff3e0; color: #88614c; }
            :where(.zs-event-ingredients .zs-col-adults),
            :where(.zs-event-ingredients .zs-col-children),
            :where(.zs-event-ingredients .zs-col-babies) { text-align: center; }
            :where(.zs-event-ingredients thead .zs-col-adults),
            :where(.zs-event-ingredients thead .zs-col-children),
            :where(.zs-event-ingredients thead .zs-col-babies) { background: #555; font-size: 0.9em; }
        </style>';
        
        $html .= '<div class="zs-ingredients-wrapper">';
        
        // Info header with guest breakdown
        $html .= '<div class="zs-ingredients-info">';
        $html .= '<strong>' . esc_html__('Guests:', 'zero-sense') . '</strong> ';
        $html .= esc_html($adults) . ' ' . esc_html__('adults', 'zero-sense');
        if ($children > 0) {
            $html .= ' + ' . esc_html($children) . ' ' . esc_html__('children (5-8 years)', 'zero-sense');
        }
        if ($babies > 0) {
            $html .= ' + ' . esc_html($babies) . ' ' . esc_html__('babies (0-4 years)', 'zero-sense');
        }
        $html .= ' = <strong>' . esc_html($this->formatNumber($eqTotal)) . ' ' . esc_html__('equivalent pax', 'zero-sense') . '</strong>';
        $html .= '<br><small style="color: #666; margin-top: 5px; display: block;">';
        $html .= esc_html__('Multipliers:', 'zero-sense') . ' ';
        $html .= esc_html__('Adults', 'zero-sense') . ' ×' . esc_html($this->formatNumber(self::ADULT_WEIGHT));
        $html .= ', ' . esc_html__('Children', 'zero-sense') . ' ×' . esc_html($this->formatNumber(self::CHILD_WEIGHT));
        $html .= ', ' . esc_html__('Babies', 'zero-sense') . ' ×' . esc_html($this->formatNumber(self::BABY_WEIGHT));
        $html .= '</small>';
        $html .= '</div>';

        // Ingredients table
        $html .= '<table class="zs-event-ingredients">';
        $html .= '<thead>';
        $html .= '<tr>';
        $html .= '<th class="zs-col-ingredient">' . esc_html__('Ingredient', 'zero-sense') . '</th>';
        $html .= '<th class="zs-col-total">' . esc_html__('TOTAL', 'zero-sense') . '</th>';
        $html .= '<th class="zs-col-adults">' . esc_html__('Adults', 'zero-sense') . ' (' . esc_html($adults) . ') ×' . esc_html($this->formatNumber(self::ADULT_WEIGHT)) . '</th>';
        if ($children > 0) {
            $html .= '<th class="zs-col-children">' . esc_html__('Children', 'zero-sense') . ' (' . esc_html($children) . ') ×' . esc_html($this->formatNumber(self::CHILD_WEIGHT)) . '</th>';
        }
        if ($babies > 0) {
            $html .= '<th class="zs-col-babies">' . esc_html__('Babies', 'zero-sense') . ' (' . esc_html($babies) . ') ×' . esc_html($this->formatNumber(self::BABY_WEIGHT)) . '</th>';
        }
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody>';

        foreach ($totals as $t) {
            $termId = (int) ($t['term_id'] ?? 0);
            $unit = (string) ($t['unit'] ?? '');
            $qty = (float) ($t['qty'] ?? 0);

            if ($termId <= 0 || $qty <= 0 || $unit === '') {
                continue;
            }

            $termName = $this->getTranslatedIngredientName($termId, $orderLanguage);
            if ($termName === '') {
                continue;
            }

            // Normalize units (g/kg, ml/l)
            $normalized = $this->normalizeUnit($qty, $unit);
            $normalizedQty = $normalized['qty'];
            $normalizedUnit = $normalized['unit'];

            // Calculate per-person amounts for reference
            $perAdult = $adults > 0 ? $normalizedQty / $eqTotal : 0;
            $perChild = $children > 0 ? ($normalizedQty / $eqTotal) * self::CHILD_WEIGHT : 0;
            $perBaby = $babies > 0 ? ($normalizedQty / $eqTotal) * self::BABY_WEIGHT : 0;

            $html .= '<tr>';
            $html .= '<td class="zs-col-ingredient">' . esc_html($termName) . '</td>';
            $html .= '<td class="zs-col-total">' . esc_html($this->formatNumber($normalizedQty)) . ' ' . esc_html($normalizedUnit) . '</td>';
            $html .= '<td class="zs-col-adults">' . esc_html($this->formatNumber($perAdult * $adults)) . ' ' . esc_html($normalizedUnit) . '</td>';
            if ($children > 0) {
                $html .= '<td class="zs-col-children">' . esc_html($this->formatNumber($perChild * $children)) . ' ' . esc_html($normalizedUnit) . '</td>';
            }
            if ($babies > 0) {
                $html .= '<td class="zs-col-babies">' . esc_html($this->formatNumber($perBaby * $babies)) . ' ' . esc_html($normalizedUnit) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get simplified ingredients total (only Ingredient and TOTAL columns)
     */
    private function getOrderIngredientsSimple($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Order Ingredients Simple');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $orderLanguage = $this->getOrderLanguageCode($order);
        $eqTotal = $this->getEquivalentPax($order);
        if ($eqTotal <= 0) {
            return '';
        }

        $lineItems = $order->get_items('line_item');
        if (!$lineItems) {
            return '';
        }

        $eligible = [];
        $sumQty = 0.0;

        foreach ($lineItems as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $qty = (float) $item->get_quantity();
            if ($qty <= 0) {
                continue;
            }

            $product = $item->get_product();
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $recipeId = (int) $product->get_meta(self::META_PRODUCT_RECIPE_ID, true);
            if ($recipeId <= 0) {
                continue;
            }

            $eligible[] = ['recipe_id' => $recipeId, 'qty' => $qty];
            $sumQty += $qty;
        }

        if (empty($eligible) || $sumQty <= 0) {
            return '';
        }

        $totals = [];
        foreach ($eligible as $row) {
            $recipeId = (int) $row['recipe_id'];
            $qty = (float) $row['qty'];

            $eqItem = $eqTotal * ($qty / $sumQty);
            if ($eqItem <= 0) {
                continue;
            }

            $recipeIngredients = get_post_meta($recipeId, self::META_RECIPE_INGREDIENTS, true);
            if (!is_array($recipeIngredients)) {
                continue;
            }

            foreach ($recipeIngredients as $ingRow) {
                if (!is_array($ingRow)) {
                    continue;
                }

                $termId = isset($ingRow['ingredient']) ? (int) $ingRow['ingredient'] : 0;
                $perPax = isset($ingRow['qty']) ? (float) $ingRow['qty'] : 0.0;
                $unit = isset($ingRow['unit']) ? sanitize_key((string) $ingRow['unit']) : '';

                if ($termId <= 0 || $perPax <= 0 || $unit === '') {
                    continue;
                }

                $amount = $eqItem * $perPax;
                if ($amount <= 0) {
                    continue;
                }

                $k = $termId . '|' . $unit;
                if (!isset($totals[$k])) {
                    $totals[$k] = ['term_id' => $termId, 'unit' => $unit, 'qty' => 0.0];
                }
                $totals[$k]['qty'] += $amount;
            }
        }

        if (empty($totals)) {
            return '';
        }

        usort($totals, function(array $a, array $b): int {
            $ta = $a['term_id'] ?? 0;
            $tb = $b['term_id'] ?? 0;
            return $ta <=> $tb;
        });

        // Get guest counts for header
        $adults = (int) $order->get_meta(self::META_EVENT_ADULTS, true);
        $children = (int) $order->get_meta(self::META_EVENT_CHILDREN, true);
        $babies = (int) $order->get_meta(self::META_EVENT_BABIES, true);

        $html = '<style>
            :where(.zs-ingredients-wrapper) { margin: 20px 0; }
            :where(.zs-ingredients-info) { margin-bottom: 15px; padding: 10px; background: #f5f5f5; border-radius: 4px; }
            :where(.zs-event-ingredients) { width: 100%; border-collapse: collapse; }
            :where(.zs-event-ingredients thead) { background: #333; color: white; }
            :where(.zs-event-ingredients th) { padding: 10px; text-align: left; border: 1px solid #ddd; }
            :where(.zs-event-ingredients td) { padding: 8px; border: 1px solid #ddd; }
            :where(.zs-event-ingredients .zs-col-total) { text-align: center; font-weight: bold; background: #fff3e0; color: #88614c; }
        </style>';
        
        $html .= '<div class="zs-ingredients-wrapper">';
        
        // Info header with guest breakdown (without babies)
        $html .= '<div class="zs-ingredients-info">';
        $html .= '<strong>' . esc_html__('Guests:', 'zero-sense') . '</strong> ';
        $html .= esc_html($adults) . ' ' . esc_html__('adults', 'zero-sense');
        if ($children > 0) {
            $html .= ' + ' . esc_html($children) . ' ' . esc_html__('children (5-8 years)', 'zero-sense');
        }
        $html .= ' = <strong>' . esc_html($this->formatNumber($eqTotal)) . ' ' . esc_html__('equivalent pax', 'zero-sense') . '</strong>';
        $html .= '</div>';

        // Ingredients table (simplified - only Ingredient and TOTAL)
        $html .= '<table class="zs-event-ingredients">';
        $html .= '<thead>';
        $html .= '<tr>';
        $html .= '<th class="zs-col-ingredient">' . esc_html__('Ingredient', 'zero-sense') . '</th>';
        $html .= '<th class="zs-col-total">' . esc_html__('TOTAL', 'zero-sense') . '</th>';
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody>';

        foreach ($totals as $t) {
            $termId = (int) ($t['term_id'] ?? 0);
            $unit = (string) ($t['unit'] ?? '');
            $qty = (float) ($t['qty'] ?? 0);

            if ($termId <= 0 || $qty <= 0 || $unit === '') {
                continue;
            }

            $termName = $this->getTranslatedIngredientName($termId, $orderLanguage);
            if ($termName === '') {
                continue;
            }

            // Normalize units (g/kg, ml/l)
            $normalized = $this->normalizeUnit($qty, $unit);
            $normalizedQty = $normalized['qty'];
            $normalizedUnit = $normalized['unit'];

            $html .= '<tr>';
            $html .= '<td class="zs-col-ingredient">' . esc_html($termName) . '</td>';
            $html .= '<td class="zs-col-total">' . esc_html($this->formatNumber($normalizedQty)) . ' ' . esc_html($normalizedUnit) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get event staff formatted for display
     */
    private function getEventStaffFormatted($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return '';
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return '';
        }

        $staffAssignments = $order->get_meta('zs_event_staff', true);
        if (!is_array($staffAssignments) || empty($staffAssignments)) {
            return '';
        }

        // Group staff by role
        $staffByRole = [];
        foreach ($staffAssignments as $assignment) {
            if (!is_array($assignment) || !isset($assignment['role'], $assignment['staff_id'])) {
                continue;
            }

            $role = $assignment['role'];
            $staffId = (int) $assignment['staff_id'];
            
            if (!isset($staffByRole[$role])) {
                $staffByRole[$role] = [];
            }
            
            $staffByRole[$role][] = $staffId;
        }

        if (empty($staffByRole)) {
            return '';
        }

        // Get role names
        $roleTerms = get_terms([
            'taxonomy' => 'zs_staff_role',
            'hide_empty' => false,
            'orderby' => 'meta_value_num',
            'meta_key' => 'role_order',
            'order' => 'ASC',
        ]);

        $roleNames = [];
        foreach ($roleTerms as $term) {
            $roleNames[$term->slug] = $term->name;
        }

        // Build HTML output
        $html = '<div class="zs-event-staff-list">';
        
        foreach ($staffByRole as $roleSlug => $staffIds) {
            $roleName = $roleNames[$roleSlug] ?? ucfirst(str_replace('-', ' ', $roleSlug));
            
            $html .= '<div class="zs-staff-role-group">';
            $html .= '<h4 class="zs-staff-role-title">' . esc_html($roleName) . '</h4>';
            $html .= '<ul class="zs-staff-members">';
            
            foreach ($staffIds as $staffId) {
                $staffPost = get_post($staffId);
                if (!$staffPost) {
                    continue;
                }
                
                $name = $staffPost->post_title;
                $phone = get_post_meta($staffId, 'zs_staff_phone', true);
                $email = get_post_meta($staffId, 'zs_staff_email', true);
                
                $html .= '<li class="zs-staff-member">';
                $html .= '<span class="zs-staff-name">' . esc_html($name) . '</span>';
                
                if ($phone || $email) {
                    $html .= ' <span class="zs-staff-contact">(';
                    if ($phone) {
                        $html .= '<a href="tel:' . esc_attr($phone) . '" class="zs-staff-phone">' . esc_html($phone) . '</a>';
                    }
                    if ($phone && $email) {
                        $html .= ', ';
                    }
                    if ($email) {
                        $html .= '<a href="mailto:' . esc_attr($email) . '" class="zs-staff-email">' . esc_html($email) . '</a>';
                    }
                    $html .= ')</span>';
                }
                
                $html .= '</li>';
            }
            
            $html .= '</ul>';
            $html .= '</div>';
        }
        
        $html .= '</div>';

        return $html;
    }
}
