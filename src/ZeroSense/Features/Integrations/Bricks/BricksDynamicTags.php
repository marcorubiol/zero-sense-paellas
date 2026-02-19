<?php

declare(strict_types=1);

namespace ZeroSense\Features\Integrations\Bricks;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Core\MetaFieldRegistry;
use ZeroSense\Features\WooCommerce\Schema\SchemaRegistry;
use ZeroSense\Features\WooCommerce\EventManagement\Components\FieldChangeTracker;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\MaterialCalculator;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\MaterialDefinitions;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\ManualOverride;
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
    private const META_PRODUCT_RECIPE_NO_RABBIT = 'zs_recipe_id_no_rabbit';
    private const META_RABBIT_CHOICE = '_zs_rabbit_choice';
    private const META_RECIPE_INGREDIENTS = 'zs_recipe_ingredients';
    private const META_RECIPE_UTENSILS = 'zs_recipe_utensils';
    private const META_RECIPE_LIQUIDS = 'zs_recipe_liquids';
    private const TAX_INGREDIENT = 'zs_ingredient';
    private const TAX_UTENSIL = 'zs_utensil';
    private const TAX_LIQUID = 'zs_liquid';
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
        add_action('wp_enqueue_scripts', [$this, 'enqueueRabbitToggleAssets']);
        add_action('init', [$this, 'registerRabbitStrings']);
        
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
        // --- NEW {zs_*} tags (canonical) ---

        foreach (self::BILLING_FIELDS as $field => $label) {
            $tags[] = ['name' => '{zs_billing_' . $field . '}', 'label' => $label, 'group' => 'ZeroSense'];
        }

        foreach (self::SHIPPING_FIELDS as $field => $label) {
            $tags[] = ['name' => '{zs_shipping_' . $field . '}', 'label' => $label, 'group' => 'ZeroSense'];
        }

        $tags[] = ['name' => '{zs_order_id}',           'label' => 'Order ID',       'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_order_number}',       'label' => 'Order Number',   'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_order_status}',       'label' => 'Order Status',   'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_order_note}',         'label' => 'Customer Note',  'group' => 'ZeroSense'];

        foreach ($this->getMetaBoxFields() as $field => $label) {
            $tags[] = ['name' => '{zs_' . $field . '}', 'label' => $label, 'group' => 'ZeroSense'];
        }

        $tags[] = ['name' => '{zs_event_service_location_name}', 'label' => 'Event Service Location (Name)', 'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_event_ops_notes}',             'label' => 'Ops Notes',                    'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_event_media}',                 'label' => 'Event Media Gallery',           'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_event_media_urls}',            'label' => 'Event Media URLs (comma-separated)', 'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_order_products}',              'label' => 'Order Products (Menu)',         'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_order_products_simple}',       'label' => 'Order Products (Simple List)',  'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_order_products_count}',        'label' => 'Order Products Count',          'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_order_products_by_category}',  'label' => 'Order Products (Grouped by Category)', 'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_order_last_modified}',         'label' => 'Order Last Modified (Date & Time)', 'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_order_last_modified_date}',    'label' => 'Order Last Modified (Date Only)', 'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_order_last_modified_time}',    'label' => 'Order Last Modified (Time Only)', 'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_order_language}',              'label' => 'Order Language',                'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_order_language_name}',         'label' => 'Order Language (Full Name)',    'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_event_intolerances}',          'label' => 'Intolerances & Allergies',      'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_recipe_card}',                 'label' => 'Recipe Card (label/value per recipe)',           'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_recipe_simple}',               'label' => 'Recipe Names (Simple List)',                     'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_recipe_exists}',               'label' => 'Has Recipes (1/0)',                              'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_recipe_total_ingredients_list}',   'label' => 'Recipe Ingredients Total (List)',                'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_recipe_total_ingredients_simple}', 'label' => 'Recipe Ingredients Total (Inline — one field per ingredient)', 'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_recipe_ingredients_simple}',      'label' => 'Recipe Ingredients (Inline — one field per ingredient)',       'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_recipe_utensils_total}',       'label' => 'Recipe Utensils (Total Calculated)',             'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_recipe_utensils_simple}',      'label' => 'Recipe Utensils (Inline — one field per utensil)', 'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_recipe_utensils_list}',        'label' => 'Recipe Utensils (List with header)',             'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_recipe_liquids_simple}',       'label' => 'Recipe Liquids (Inline — one field per liquid)',  'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_recipe_full_simple}',          'label' => 'Recipe Ingredients + Liquids (Inline — combined)', 'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_inventory_list}',              'label' => 'Inventory & Materials (one field per item)',     'group' => 'ZeroSense'];
        $tags[] = ['name' => '{zs_rabbit_toggle}',               'label' => 'Rabbit Toggle (shop switch)',                    'group' => 'ZeroSense'];

        // Dynamic schema tags
        $schemaRegistry = SchemaRegistry::getInstance();
        foreach ($schemaRegistry->getAll() as $schemaKey => $schema) {
            $schemaTitle = $schema->getSchemaTitle();
            foreach ($this->getSchemaFields($schemaKey) as $field => $label) {
                $tags[] = ['name' => '{zs_' . $schemaKey . '_' . $field . '}', 'label' => $label . ' (' . $schemaTitle . ')', 'group' => 'ZeroSense'];
            }
            $tags[] = ['name' => '{zs_' . $schemaKey . '_list}', 'label' => $schemaTitle . ' (Complete List)', 'group' => 'ZeroSense'];
        }

        // --- LEGACY aliases (kept for backward compatibility — remove after migration) ---

        foreach (self::BILLING_FIELDS as $field => $label) {
            $tags[] = ['name' => '{woo_billing_' . $field . '}', 'label' => '[legacy] ' . $label, 'group' => 'ZeroSense (legacy)'];
        }
        foreach (self::SHIPPING_FIELDS as $field => $label) {
            $tags[] = ['name' => '{woo_shipping_' . $field . '}', 'label' => '[legacy] ' . $label, 'group' => 'ZeroSense (legacy)'];
        }
        $tags[] = ['name' => '{woo_zs_order_id}',     'label' => '[legacy] Order ID',     'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_order_number}', 'label' => '[legacy] Order Number', 'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_order_note}',      'label' => '[legacy] Customer Note','group' => 'ZeroSense (legacy)'];
        // MetaBox fields now only generate {zs_*} tags to avoid duplication
        // Legacy {woo_mb_*} tags are maintained only for existing hardcoded mappings
        $tags[] = ['name' => '{woo_zs_event_service_location_name}', 'label' => '[legacy] Event Service Location (Name)', 'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_ops_notes}',                      'label' => '[legacy] Ops Notes',                    'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_event_media}',                 'label' => '[legacy] Event Media Gallery',           'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_event_media_urls}',            'label' => '[legacy] Event Media URLs',              'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_order_products}',              'label' => '[legacy] Order Products (Menu)',         'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_order_products_simple}',       'label' => '[legacy] Order Products (Simple List)',  'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_order_products_count}',        'label' => '[legacy] Order Products Count',          'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_order_products_by_category}',  'label' => '[legacy] Order Products (Grouped by Category)', 'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_order_last_modified}',         'label' => '[legacy] Order Last Modified',           'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_order_last_modified_date}',    'label' => '[legacy] Order Last Modified (Date)',    'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_order_last_modified_time}',    'label' => '[legacy] Order Last Modified (Time)',    'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_order_language}',              'label' => '[legacy] Order Language',                'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_order_language_name}',         'label' => '[legacy] Order Language (Full Name)',    'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_intolerances}',                'label' => '[legacy] Intolerances & Allergies',      'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_order_recipes}',               'label' => '[legacy → zs_recipe_card] Order Recipes',                 'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_order_recipes_simple}',        'label' => '[legacy → zs_recipe_simple] Order Recipes (Simple)',        'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_order_has_recipes}',           'label' => '[legacy → zs_recipe_exists] Order Has Recipes',             'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_order_ingredients_total}',     'label' => '[legacy → zs_recipe_total_ingredients_list] Ingredients Total',  'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_order_ingredients_simple}',    'label' => '[legacy → zs_recipe_total_ingredients_list] Ingredients Simple', 'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_order_utensils_total}',        'label' => '[legacy → zs_recipe_utensils_total] Utensils Total',        'group' => 'ZeroSense (legacy)'];
        $tags[] = ['name' => '{woo_zs_order_utensils_simple}',       'label' => '[legacy → zs_recipe_utensils_simple] Utensils Simple',      'group' => 'ZeroSense (legacy)'];
        foreach ($schemaRegistry->getAll() as $schemaKey => $schema) {
            $schemaTitle = $schema->getSchemaTitle();
            foreach ($this->getSchemaFields($schemaKey) as $field => $label) {
                $tags[] = ['name' => '{woo_' . $schemaKey . '_' . $field . '}', 'label' => '[legacy] ' . $label . ' (' . $schemaTitle . ')', 'group' => 'ZeroSense (legacy)'];
            }
            $tags[] = ['name' => '{woo_' . $schemaKey . '_list}', 'label' => '[legacy] ' . $schemaTitle . ' (Complete List)', 'group' => 'ZeroSense (legacy)'];
        }

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

        // Normalize legacy tag aliases to canonical {zs_*} before dispatching
        $tag = $this->normalizeLegacyTag($tag);

        // --- Canonical {zs_*} dispatch ---

        if ($tag === '{zs_order_id}') {
            return $this->getOrderId($post);
        }
        if ($tag === '{zs_order_number}') {
            return $this->getOrderNumber($post);
        }
        if ($tag === '{zs_order_status}') {
            return $this->getOrderStatus($post);
        }
        if ($tag === '{zs_order_note}') {
            return $this->getOrderNote($post);
        }
        if (strpos($tag, '{zs_billing_') === 0) {
            return $this->getBillingFieldValue($this->stripTag($tag, 'zs_billing_'), $post);
        }
        if (strpos($tag, '{zs_shipping_') === 0) {
            return $this->getShippingFieldValue($this->stripTag($tag, 'zs_shipping_'), $post);
        }
        if ($tag === '{zs_event_service_location_name}') {
            return $this->getEventServiceLocationName($post);
        }
        if ($tag === '{zs_event_staff_all}') {
            return $this->getEventStaffFormatted($post);
        }
        if ($tag === '{zs_event_ops_notes}') {
            return $this->getEventOpsNotes($post);
        }
        if ($tag === '{zs_event_media}') {
            return $this->getEventMediaGallery($post);
        }
        if ($tag === '{zs_event_media_urls}') {
            return $this->getEventMediaUrls($post);
        }
        if ($tag === '{zs_order_products}') {
            return $this->getOrderProducts($post);
        }
        if ($tag === '{zs_order_products_simple}') {
            return $this->getOrderProductsSimple($post);
        }
        if ($tag === '{zs_order_products_count}') {
            return $this->getOrderProductsCount($post);
        }
        if ($tag === '{zs_order_products_by_category}') {
            return $this->getOrderProductsByCategory($post);
        }
        if ($tag === '{zs_order_last_modified}') {
            return $this->getOrderLastModified($post);
        }
        if ($tag === '{zs_order_last_modified_date}') {
            return $this->getOrderLastModified($post, 'date');
        }
        if ($tag === '{zs_order_last_modified_time}') {
            return $this->getOrderLastModified($post, 'time');
        }
        if ($tag === '{zs_order_language}') {
            return $this->getOrderLanguage($post);
        }
        if ($tag === '{zs_order_language_name}') {
            return $this->getOrderLanguage($post, true);
        }
        if ($tag === '{zs_event_intolerances}') {
            return $this->getMetaBoxFieldValue('intolerances', $post);
        }
        if ($tag === '{zs_recipe_card}') {
            return $this->getRecipeCard($post);
        }
        if ($tag === '{zs_recipe_simple}') {
            return $this->getRecipeSimple($post);
        }
        if ($tag === '{zs_recipe_exists}') {
            return $this->getRecipeExists($post);
        }
        if ($tag === '{zs_recipe_total_ingredients_list}') {
            return $this->getRecipeTotalIngredientsList($post);
        }
        if ($tag === '{zs_recipe_total_ingredients_simple}' || $tag === '{zs_recipe_ingredients_simple}') {
            return $this->getRecipeTotalIngredientsSimple($post);
        }
        if ($tag === '{zs_recipe_utensils_total}') {
            return $this->getRecipeUtensilsTotal($post);
        }
        if ($tag === '{zs_recipe_utensils_simple}') {
            return $this->getRecipeUtensilsSimple($post);
        }
        if ($tag === '{zs_recipe_utensils_list}') {
            return $this->getRecipeUtensilsList($post);
        }
        if ($tag === '{zs_recipe_liquids_simple}') {
            return $this->getRecipeLiquidsSimple($post);
        }
        if ($tag === '{zs_recipe_full_simple}') {
            return $this->getRecipeFullSimple($post);
        }
        if ($tag === '{zs_inventory_list}') {
            return $this->getInventoryList($post);
        }
        if ($tag === '{zs_rabbit_toggle}') {
            return $this->getRabbitToggle($post);
        }

        // Dynamic schema tags: {zs_material_field}, {zs_workspace_list}, etc.
        $schemaRegistry = SchemaRegistry::getInstance();
        foreach ($schemaRegistry->getKeys() as $schemaKey) {
            if ($tag === '{zs_' . $schemaKey . '_list}') {
                return $this->getSchemaList($schemaKey, $post);
            }
            if (strpos($tag, '{zs_' . $schemaKey . '_') === 0) {
                return $this->getSchemaFieldValue($schemaKey, $this->stripTag($tag, 'zs_' . $schemaKey . '_'), $post);
            }
        }

        // MetaBox fields: {zs_event_start_time}, {zs_event_total_guests}, etc.
        if (strpos($tag, '{zs_') === 0) {
            $field = $this->stripTag($tag, 'zs_');
            return $this->getMetaBoxFieldValue($field, $post);
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

        // Normalize all legacy tags to canonical {zs_*} first
        $content = $this->normalizeLegacyContent($content);

        // --- Canonical {zs_*} replacements ---

        $content = $this->replaceTagsInContent($content, $post, 'zs_billing_', function (string $field) use ($post): string {
            return $this->getBillingFieldValue($field, $post);
        });
        $content = $this->replaceTagsInContent($content, $post, 'zs_shipping_', function (string $field) use ($post): string {
            return $this->getShippingFieldValue($field, $post);
        });

        $content = str_replace('{zs_order_id}',     $this->getOrderId($post),     $content);
        $content = str_replace('{zs_order_number}', $this->getOrderNumber($post), $content);
        $content = str_replace('{zs_order_status}', $this->getOrderStatus($post), $content);
        $content = str_replace('{zs_order_note}',   $this->getOrderNote($post),   $content);

        $content = str_replace('{zs_event_service_location_name}', $this->getEventServiceLocationName($post), $content);
        $content = str_replace('{zs_event_staff_all}',             $this->getEventStaffFormatted($post), $content);
        $content = str_replace('{zs_event_ops_notes}',             $this->getEventOpsNotes($post),       $content);
        $content = str_replace('{zs_event_media}',                 $this->getEventMediaGallery($post),   $content);
        $content = str_replace('{zs_event_media_urls}',            $this->getEventMediaUrls($post),      $content);
        $content = str_replace('{zs_order_products}',              $this->getOrderProducts($post),       $content);
        $content = str_replace('{zs_order_products_simple}',       $this->getOrderProductsSimple($post), $content);
        $content = str_replace('{zs_order_products_count}',        $this->getOrderProductsCount($post),  $content);
        $content = str_replace('{zs_order_products_by_category}',  $this->getOrderProductsByCategory($post), $content);
        $content = str_replace('{zs_order_last_modified}',         $this->getOrderLastModified($post),        $content);
        $content = str_replace('{zs_order_last_modified_date}',    $this->getOrderLastModified($post, 'date'), $content);
        $content = str_replace('{zs_order_last_modified_time}',    $this->getOrderLastModified($post, 'time'), $content);
        $content = str_replace('{zs_order_language}',              $this->getOrderLanguage($post),        $content);
        $content = str_replace('{zs_order_language_name}',         $this->getOrderLanguage($post, true),  $content);
        $content = str_replace('{zs_event_intolerances}',          $this->getMetaBoxFieldValue('intolerances', $post), $content);
        $content = str_replace('{zs_recipe_card}',                $this->getRecipeCard($post),       $content);
        $content = str_replace('{zs_recipe_simple}',              $this->getRecipeSimple($post),     $content);
        $content = str_replace('{zs_recipe_exists}',              $this->getRecipeExists($post),        $content);
        $content = str_replace('{zs_recipe_total_ingredients_list}',   $this->getRecipeTotalIngredientsList($post),   $content);
        $content = str_replace('{zs_recipe_total_ingredients_simple}', $this->getRecipeTotalIngredientsSimple($post), $content);
        $content = str_replace('{zs_recipe_ingredients_simple}',      $this->getRecipeTotalIngredientsSimple($post), $content);
        $content = str_replace('{zs_recipe_utensils_total}',      $this->getRecipeUtensilsTotal($post),     $content);
        $content = str_replace('{zs_recipe_utensils_simple}',     $this->getRecipeUtensilsSimple($post),    $content);
        $content = str_replace('{zs_recipe_utensils_list}',       $this->getRecipeUtensilsList($post),      $content);
        $content = str_replace('{zs_recipe_liquids_simple}',      $this->getRecipeLiquidsSimple($post),     $content);
        $content = str_replace('{zs_recipe_full_simple}',         $this->getRecipeFullSimple($post),        $content);
        $content = str_replace('{zs_inventory_list}',              $this->getInventoryList($post),           $content);
        $content = str_replace('{zs_rabbit_toggle}',              $this->getRabbitToggle($post), $content);

        // Dynamic schema tags
        $schemaRegistry = SchemaRegistry::getInstance();
        foreach ($schemaRegistry->getKeys() as $schemaKey) {
            $content = str_replace('{zs_' . $schemaKey . '_list}', $this->getSchemaList($schemaKey, $post), $content);
            $content = $this->replaceTagsInContent($content, $post, 'zs_' . $schemaKey . '_', function (string $field) use ($post, $schemaKey): string {
                return $this->getSchemaFieldValue($schemaKey, $field, $post);
            });
        }

        // MetaBox fields: {zs_event_start_time}, {zs_event_total_guests}, etc.
        $content = $this->replaceTagsInContent($content, $post, 'zs_', function (string $field) use ($post): string {
            return $this->getMetaBoxFieldValue($field, $post);
        });

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

    /**
     * Map a single legacy tag to its canonical {zs_*} equivalent.
     * Called in renderTag() before dispatch.
     */
    private function normalizeLegacyTag(string $tag): string
    {
        // Static 1-to-1 mappings
        $map = [
            '{woo_zs_order_id}'                      => '{zs_order_id}',
            '{woo_zs_order_number}'                  => '{zs_order_number}',
            '{woo_order_note}'                       => '{zs_order_note}',
            '{woo_zs_event_service_location_name}'   => '{zs_event_service_location_name}',
            '{woo_mb_event_staff_all}'               => '{zs_event_staff_all}',
            '{woo_ops_notes}'                        => '{zs_event_ops_notes}',
            '{woo_zs_event_media}'                   => '{zs_event_media}',
            '{woo_zs_event_media_urls}'              => '{zs_event_media_urls}',
            '{woo_zs_order_products}'                => '{zs_order_products}',
            '{woo_zs_order_products_simple}'         => '{zs_order_products_simple}',
            '{woo_zs_order_products_count}'          => '{zs_order_products_count}',
            '{woo_zs_order_products_by_category}'    => '{zs_order_products_by_category}',
            '{woo_zs_order_last_modified}'           => '{zs_order_last_modified}',
            '{woo_zs_order_last_modified_date}'      => '{zs_order_last_modified_date}',
            '{woo_zs_order_last_modified_time}'      => '{zs_order_last_modified_time}',
            '{woo_zs_order_language}'                => '{zs_order_language}',
            '{woo_zs_order_language_name}'           => '{zs_order_language_name}',
            '{woo_zs_intolerances}'                  => '{zs_event_intolerances}',
            '{woo_zs_order_recipes}'                 => '{zs_recipe_card}',
            '{woo_zs_order_recipes_simple}'          => '{zs_recipe_simple}',
            '{woo_zs_order_has_recipes}'             => '{zs_recipe_exists}',
            '{woo_zs_order_ingredients_total}'       => '{zs_recipe_total_ingredients_list}',
            '{woo_zs_order_ingredients_simple}'      => '{zs_recipe_total_ingredients_list}',
            '{woo_zs_order_utensils_total}'          => '{zs_recipe_utensils_total}',
            '{woo_zs_order_utensils_simple}'         => '{zs_recipe_utensils_simple}',
        ];

        if (isset($map[$tag])) {
            return $map[$tag];
        }

        // {woo_billing_*} → {zs_billing_*}
        if (strpos($tag, '{woo_billing_') === 0) {
            return '{zs_billing_' . substr($tag, strlen('{woo_billing_'));
        }

        // {woo_shipping_*} → {zs_shipping_*}
        if (strpos($tag, '{woo_shipping_') === 0) {
            return '{zs_shipping_' . substr($tag, strlen('{woo_shipping_'));
        }

        // {woo_mb_*} → {zs_*}
        if (strpos($tag, '{woo_mb_') === 0) {
            return '{zs_' . substr($tag, strlen('{woo_mb_'));
        }

        // {woo_<schemaKey>_*} → {zs_<schemaKey>_*}
        $schemaRegistry = SchemaRegistry::getInstance();
        foreach ($schemaRegistry->getKeys() as $schemaKey) {
            if (strpos($tag, '{woo_' . $schemaKey . '_') === 0) {
                return '{zs_' . substr($tag, strlen('{woo_'));
            }
        }

        return $tag;
    }

    /**
     * Normalize all legacy tags inside a content string to canonical {zs_*}.
     * Called in renderContent() before processing.
     */
    private function normalizeLegacyContent(string $content): string
    {
        // Prefix-based bulk replacements (order matters: most specific first)
        $prefixMap = [
            'woo_billing_'  => 'zs_billing_',
            'woo_shipping_' => 'zs_shipping_',
            'woo_mb_'       => 'zs_',
        ];

        foreach ($prefixMap as $old => $new) {
            $content = preg_replace_callback(
                '/\{' . preg_quote($old, '/') . '([^}]*)\}/',
                static function (array $m) use ($new): string {
                    return '{' . $new . $m[1] . '}';
                },
                $content
            ) ?? $content;
        }

        // Static 1-to-1 replacements
        $map = [
            '{woo_zs_order_id}'                      => '{zs_order_id}',
            '{woo_zs_order_number}'                  => '{zs_order_number}',
            '{woo_order_note}'                       => '{zs_order_note}',
            '{woo_zs_event_service_location_name}'   => '{zs_event_service_location_name}',
            '{woo_ops_notes}'                        => '{zs_event_ops_notes}',
            '{woo_zs_event_media}'                   => '{zs_event_media}',
            '{woo_zs_event_media_urls}'              => '{zs_event_media_urls}',
            '{woo_zs_order_products}'                => '{zs_order_products}',
            '{woo_zs_order_products_simple}'         => '{zs_order_products_simple}',
            '{woo_zs_order_products_count}'          => '{zs_order_products_count}',
            '{woo_zs_order_products_by_category}'    => '{zs_order_products_by_category}',
            '{woo_zs_order_last_modified}'           => '{zs_order_last_modified}',
            '{woo_zs_order_last_modified_date}'      => '{zs_order_last_modified_date}',
            '{woo_zs_order_last_modified_time}'      => '{zs_order_last_modified_time}',
            '{woo_zs_order_language}'                => '{zs_order_language}',
            '{woo_zs_order_language_name}'           => '{zs_order_language_name}',
            '{woo_zs_intolerances}'                  => '{zs_event_intolerances}',
            '{woo_zs_order_recipes}'                 => '{zs_recipe_card}',
            '{woo_zs_order_recipes_simple}'          => '{zs_recipe_simple}',
            '{woo_zs_order_has_recipes}'             => '{zs_recipe_exists}',
            '{woo_zs_order_ingredients_total}'       => '{zs_recipe_total_ingredients_list}',
            '{woo_zs_order_ingredients_simple}'      => '{zs_recipe_total_ingredients_list}',
            '{woo_zs_order_utensils_total}'          => '{zs_recipe_utensils_total}',
            '{woo_zs_order_utensils_simple}'         => '{zs_recipe_utensils_simple}',
        ];

        $content = str_replace(array_keys($map), array_values($map), $content);

        // Schema keys: {woo_<schemaKey>_*} → {zs_<schemaKey>_*}
        $schemaRegistry = SchemaRegistry::getInstance();
        foreach ($schemaRegistry->getKeys() as $schemaKey) {
            $content = preg_replace_callback(
                '/\{woo_' . preg_quote($schemaKey, '/') . '_([^}]*)\}/',
                static function (array $m) use ($schemaKey): string {
                    return '{zs_' . $schemaKey . '_' . $m[1] . '}';
                },
                $content
            ) ?? $content;
        }

        return $content;
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

    private function getEventOpsNotes($post): string
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

        $html = '';
        
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
                $html .= '<div class="brxe-div fdr-card__field">';
                $html .= '<span class="brxe-text-basic fdr-card__field-label">' . esc_html($finalLabel) . '</span>';
                $formattedValue = $type === 'textarea' ? nl2br(esc_html($displayValue)) : esc_html($displayValue);
                $html .= '<span class="brxe-text-basic fdr-card__field-value">' . $formattedValue . '</span>';
                $html .= '</div>';
            }
        }
        
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

    private function getEventServiceLocationName($post): string
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

    private function getOrderStatus($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Order Status');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        return wc_get_order_status_name($order->get_status());
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

        $items = [];
        
        foreach ($order->get_items() as $item) {
            if (!method_exists($item, 'get_product') || !$item->get_product()) {
                continue;
            }
            
            $product = $item->get_product();
            $quantity = $item->get_quantity();
            $name = $item->get_name();
            
            $itemHtml = '<li class="zs-menu-product">' . esc_html($name);
            
            if ($quantity > 1) {
                $itemHtml .= ' <span class="zs-menu-quantity">(' . esc_html($quantity) . 'x)</span>';
            }
            
            // Show product attributes if it's a variable product
            if ($product->is_type('variation')) {
                $attributes = $product->get_variation_attributes();
                if (!empty($attributes)) {
                    $attrItems = [];
                    foreach ($attributes as $attr_name => $attr_value) {
                        if ($attr_value) {
                            $label = wc_attribute_label(str_replace('attribute_', '', $attr_name));
                            $attrItems[] = '<li><small>' . esc_html($label) . ': ' . esc_html($attr_value) . '</small></li>';
                        }
                    }
                    if (!empty($attrItems)) {
                        $itemHtml .= '<ul class="zs-attributes">' . implode('', $attrItems) . '</ul>';
                    }
                }
            }
            
            $itemHtml .= '</li>';
            $items[] = $itemHtml;
        }
        
        return '<ul class="zs-order-products">' . implode('', $items) . '</ul>';
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

        // Pre-populate categories in their configured order
        $orderedTerms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'term_order',
            'order'      => 'ASC',
        ]);

        $categorizedProducts = [];

        if (!is_wp_error($orderedTerms)) {
            foreach ($orderedTerms as $term) {
                if ($term->slug === 'uncategorized') {
                    continue;
                }
                $categorizedProducts[$term->term_id] = [
                    'name'     => $term->name,
                    'slug'     => $term->slug,
                    'products' => [],
                ];
            }
        }

        foreach ($order->get_items() as $item) {
            if (!method_exists($item, 'get_product') || !$item->get_product()) {
                continue;
            }

            $product   = $item->get_product();
            $productId = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
            $terms     = get_the_terms($productId, 'product_cat');

            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    if ($term->slug === 'uncategorized') {
                        continue;
                    }
                    if (!isset($categorizedProducts[$term->term_id])) {
                        $categorizedProducts[$term->term_id] = [
                            'name'     => $term->name,
                            'slug'     => $term->slug,
                            'products' => [],
                        ];
                    }
                    $categorizedProducts[$term->term_id]['products'][] = [
                        'name'     => $item->get_name(),
                        'quantity' => $item->get_quantity(),
                    ];
                }
            } else {
                if (!isset($categorizedProducts[0])) {
                    $categorizedProducts[0] = [
                        'name'     => __('Other', 'zero-sense'),
                        'slug'     => 'other',
                        'products' => [],
                    ];
                }
                $categorizedProducts[0]['products'][] = [
                    'name'     => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                ];
            }
        }

        // Remove categories with no products from this order
        $categorizedProducts = array_filter($categorizedProducts, fn($cat) => !empty($cat['products']));

        $html = '';

        foreach ($categorizedProducts as $category) {
            $items = [];
            foreach ($category['products'] as $product) {
                $qty = $product['quantity'] > 1 ? ' <span class="zs-menu-quantity">(' . esc_html($product['quantity']) . 'x)</span>' : '';
                $items[] = '<li class="zs-menu-product">' . esc_html($product['name']) . $qty . '</li>';
            }

            $html .= '<div class="brxe-div fdr-card__field">';
            $html .= '<span class="brxe-text-basic fdr-card__field-label">' . esc_html($category['name']) . '</span>';
            $html .= '<ul class="brxe-text-basic fdr-card__field-value">' . implode('', $items) . '</ul>';
            $html .= '</div>';
        }

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

        // Primary: resolve directly as zs_ + field (e.g. zs_event_team_arrival_time)
        $directMetaKey = 'zs_' . $field;
        
        // Fallback: legacy mapping for fields that have legacy keys
        $mapping = $this->getFieldMapping();
        $legacyMetaKey = $mapping[$field] ?? null;
        
        // Use legacy key only if it exists, otherwise use direct key
        $metaKey = $legacyMetaKey ?? $directMetaKey;

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
                'items' => $this->generateDynamicTagsList('zs_', $this->getMetaBoxFields()),
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
                'content' => __('MetaBox fields now generate only canonical {zs_*} tags. Legacy {woo_mb_*} tags are no longer generated for new fields. Add new fields to BILLING_FIELDS, SHIPPING_FIELDS, or register them in MetaFieldRegistry and they will appear as {zs_*} tags automatically.', 'zero-sense'),
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
    private function getRecipeExists($post): string
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

            $recipeId = $this->resolveRecipeIdForItem($item, $product);
            if ($recipeId > 0) {
                return '1';
            }
        }

        return '0';
    }

    /**
     * Get simple list of recipe names (comma-separated, no duplicates)
     */
    private function getRecipeSimple($post): string
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

            $recipeId = $this->resolveRecipeIdForItem($item, $product);
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
     * Get recipes as fdr-card__field blocks (label = recipe name, value = ingredients inline)
     */
    private function getRecipeCard($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Order Recipes Card');
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
            $recipeId = $this->resolveRecipeIdForItem($item, $product);
            if ($recipeId <= 0) {
                continue;
            }
            if (!isset($recipeGroups[$recipeId])) {
                $recipeGroups[$recipeId] = ['title' => $this->getTranslatedRecipeTitle($recipeId, $orderLanguage), 'total_qty' => 0.0];
            }
            $recipeGroups[$recipeId]['total_qty'] += $qty;
            $sumQty += $qty;
        }

        if (empty($recipeGroups) || $sumQty <= 0) {
            return '';
        }

        $html = '';

        foreach ($recipeGroups as $recipeId => $group) {
            $eqItem = $eqTotal * ($group['total_qty'] / $sumQty);

            $recipeIngredients = get_post_meta($recipeId, self::META_RECIPE_INGREDIENTS, true);
            $parts = [];

            if (is_array($recipeIngredients)) {
                foreach ($recipeIngredients as $ingRow) {
                    if (!is_array($ingRow)) {
                        continue;
                    }
                    $termId = isset($ingRow['ingredient']) ? (int) $ingRow['ingredient'] : 0;
                    $perPax = isset($ingRow['qty']) ? (float) $ingRow['qty'] : 0.0;
                    $unit   = isset($ingRow['unit']) ? sanitize_key((string) $ingRow['unit']) : '';

                    if ($termId <= 0 || $perPax <= 0 || $unit === '') {
                        continue;
                    }

                    $amount = $eqItem * $perPax;
                    if ($amount <= 0) {
                        continue;
                    }

                    $normalized = $this->normalizeUnit($amount, $unit);
                    $ingName = $this->getTranslatedIngredientName($termId, $orderLanguage);
                    $parts[] = '<li class="zs-recipe-ingredient">' . esc_html($ingName) . ' <span class="zs-recipe-ingredient-qty">' . esc_html($this->formatNumber($normalized['qty'])) . esc_html($normalized['unit']) . '</span></li>';
                }
            }

            $value = !empty($parts) ? '<ul class="brxe-text-basic fdr-card__field-value">' . implode('', $parts) . '</ul>' : '<p>—</p>';

            $html .= '<div class="brxe-div fdr-card__field">';
            $html .= '<span class="brxe-text-basic fdr-card__field-label">' . esc_html($group['title']) . '</span>';
            $html .= $value;
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Get detailed recipes with products and calculated ingredients
     */
    private function getRecipes($post): string
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

            $recipeId = $this->resolveRecipeIdForItem($item, $product);
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

    private function getRecipeTotalIngredientsList($post): string
    {
        return $this->getRecipeIngredientsSimple($post);
    }

    private function getRecipeTotalIngredientsSimple($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Recipe Ingredients Simple');
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
            $recipeId = $this->resolveRecipeIdForItem($item, $product);
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
            return ($a['term_id'] ?? 0) <=> ($b['term_id'] ?? 0);
        });

        $html = '';
        foreach ($totals as $t) {
            $termId = (int) ($t['term_id'] ?? 0);
            $unit   = (string) ($t['unit'] ?? '');
            $qty    = (float) ($t['qty'] ?? 0);
            if ($termId <= 0 || $qty <= 0 || $unit === '') {
                continue;
            }
            $termName = $this->getTranslatedIngredientName($termId, $orderLanguage);
            if ($termName === '') {
                continue;
            }
            $normalized = $this->normalizeUnit($qty, $unit);
            $html .= '<div class="brxe-div fdr-card__field">';
            $html .= '<span class="brxe-text-basic fdr-card__field-label">' . esc_html($termName) . '</span>';
            $html .= '<span class="brxe-text-basic fdr-card__field-value">' . esc_html($this->formatNumber($normalized['qty'])) . esc_html($normalized['unit']) . '</span>';
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Get simplified ingredients total (only Ingredient and TOTAL columns)
     */
    private function getRecipeIngredientsSimple($post): string
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

            $recipeId = $this->resolveRecipeIdForItem($item, $product);
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

        $items = [];
        foreach ($totals as $t) {
            $termId = (int) ($t['term_id'] ?? 0);
            $unit   = (string) ($t['unit'] ?? '');
            $qty    = (float) ($t['qty'] ?? 0);

            if ($termId <= 0 || $qty <= 0 || $unit === '') {
                continue;
            }

            $termName = $this->getTranslatedIngredientName($termId, $orderLanguage);
            if ($termName === '') {
                continue;
            }

            $normalized = $this->normalizeUnit($qty, $unit);
            $items[] = '<li class="zs-recipe-ingredient">' . esc_html($termName) . ' <span class="zs-recipe-ingredient-qty">' . esc_html($this->formatNumber($normalized['qty'])) . esc_html($normalized['unit']) . '</span></li>';
        }

        if (empty($items)) {
            return '';
        }

        return '<ul class="brxe-text-basic fdr-card__field-value">' . implode('', $items) . '</ul>';
    }

    private function getRecipeIngredientsList($post): string
    {
        return $this->getRecipeIngredientsSimple($post);
    }

    /**
     * Get total utensils table (sum of all recipes)
     */
    private function getRecipeUtensilsTotal($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Order Utensils Total');
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

            $recipeId = $this->resolveRecipeIdForItem($item, $product);
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

            $recipeUtensils = get_post_meta($recipeId, self::META_RECIPE_UTENSILS, true);
            if (!is_array($recipeUtensils)) {
                continue;
            }

            foreach ($recipeUtensils as $utensilRow) {
                if (!is_array($utensilRow)) {
                    continue;
                }

                $termId = isset($utensilRow['utensil']) ? (int) $utensilRow['utensil'] : 0;
                $perRatio = isset($utensilRow['pax_ratio']) ? (float) $utensilRow['pax_ratio'] : 0.0;
                $baseQty = isset($utensilRow['qty']) ? (float) $utensilRow['qty'] : 0.0;
                $unit = isset($utensilRow['unit']) ? sanitize_key((string) $utensilRow['unit']) : '';

                if ($termId <= 0 || $perRatio <= 0 || $baseQty <= 0 || $unit === '') {
                    continue;
                }

                // Formula: ceil(eqItem / perRatio) * baseQty
                $amount = ceil($eqItem / $perRatio) * $baseQty;
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
            :where(.zs-utensils-wrapper) { margin: 20px 0; }
            :where(.zs-utensils-info) { margin-bottom: 15px; padding: 10px; background: #f5f5f5; border-radius: 4px; }
            :where(.zs-event-utensils) { width: 100%; border-collapse: collapse; }
            :where(.zs-event-utensils thead) { background: #333; color: white; }
            :where(.zs-event-utensils th) { padding: 10px; text-align: left; border: 1px solid #ddd; }
            :where(.zs-event-utensils td) { padding: 8px; border: 1px solid #ddd; }
            :where(.zs-event-utensils .zs-col-total) { text-align: center; font-weight: bold; background: #fff3e0; color: #88614c; }
            :where(.zs-event-utensils .zs-col-adults),
            :where(.zs-event-utensils .zs-col-children),
            :where(.zs-event-utensils .zs-col-babies) { text-align: center; }
            :where(.zs-event-utensils thead .zs-col-adults),
            :where(.zs-event-utensils thead .zs-col-children),
            :where(.zs-event-utensils thead .zs-col-babies) { background: #555; font-size: 0.9em; }
        </style>';
        
        $html .= '<div class="zs-utensils-wrapper">';
        
        // Info header with guest breakdown
        $html .= '<div class="zs-utensils-info">';
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

        // Utensils table
        $html .= '<table class="zs-event-utensils">';
        $html .= '<thead>';
        $html .= '<tr>';
        $html .= '<th class="zs-col-utensil">' . esc_html__('Utensil', 'zero-sense') . '</th>';
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

            $termName = $this->getTranslatedUtensilName($termId, $orderLanguage);
            if ($termName === '') {
                continue;
            }

            // Calculate per-person amounts for reference
            $perAdult = $adults > 0 ? $qty / $eqTotal : 0;
            $perChild = $children > 0 ? ($qty / $eqTotal) * self::CHILD_WEIGHT : 0;
            $perBaby = $babies > 0 ? ($qty / $eqTotal) * self::BABY_WEIGHT : 0;

            $html .= '<tr>';
            $html .= '<td class="zs-col-utensil">' . esc_html($termName) . '</td>';
            $html .= '<td class="zs-col-total">' . esc_html($this->formatNumber($qty)) . ' ' . esc_html($unit) . '</td>';
            $html .= '<td class="zs-col-adults">' . esc_html($this->formatNumber($perAdult * $adults)) . ' ' . esc_html($unit) . '</td>';
            if ($children > 0) {
                $html .= '<td class="zs-col-children">' . esc_html($this->formatNumber($perChild * $children)) . ' ' . esc_html($unit) . '</td>';
            }
            if ($babies > 0) {
                $html .= '<td class="zs-col-babies">' . esc_html($this->formatNumber($perBaby * $babies)) . ' ' . esc_html($unit) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get simplified utensils total (only Utensil and TOTAL columns)
     */
    private function getRecipeUtensilsSimple($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Order Utensils Simple');
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

            $recipeId = $this->resolveRecipeIdForItem($item, $product);
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

            $recipeUtensils = get_post_meta($recipeId, self::META_RECIPE_UTENSILS, true);
            if (!is_array($recipeUtensils)) {
                continue;
            }

            foreach ($recipeUtensils as $utensilRow) {
                if (!is_array($utensilRow)) {
                    continue;
                }

                $termId = isset($utensilRow['utensil']) ? (int) $utensilRow['utensil'] : 0;
                $perRatio = isset($utensilRow['pax_ratio']) ? (float) $utensilRow['pax_ratio'] : 0.0;
                $baseQty = isset($utensilRow['qty']) ? (float) $utensilRow['qty'] : 0.0;
                $unit = isset($utensilRow['unit']) && $utensilRow['unit'] !== '' ? sanitize_key((string) $utensilRow['unit']) : 'u';

                if ($termId <= 0 || $perRatio <= 0 || $baseQty <= 0) {
                    continue;
                }

                // Formula: ceil(eqItem / perRatio) * baseQty
                $amount = ceil($eqItem / $perRatio) * $baseQty;
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

        $html = '';
        foreach ($totals as $t) {
            $termId = (int) ($t['term_id'] ?? 0);
            $unit   = (string) ($t['unit'] ?? 'u');
            $qty    = (float) ($t['qty'] ?? 0);

            if ($termId <= 0 || $qty <= 0) {
                continue;
            }

            $termName = $this->getTranslatedUtensilName($termId, $orderLanguage);
            if ($termName === '') {
                continue;
            }

            $html .= '<div class="brxe-div fdr-card__field">';
            $html .= '<span class="brxe-text-basic fdr-card__field-label">' . esc_html($termName) . '</span>';
            $html .= '<span class="brxe-text-basic fdr-card__field-value">' . esc_html($this->formatNumber($qty)) . esc_html($unit) . '</span>';
            $html .= '</div>';
        }

        return $html;
    }

    private function getRecipeUtensilsList($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Order Utensils List');
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
            $recipeId = $this->resolveRecipeIdForItem($item, $product);
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
            $recipeUtensils = get_post_meta($recipeId, self::META_RECIPE_UTENSILS, true);
            if (!is_array($recipeUtensils)) {
                continue;
            }
            foreach ($recipeUtensils as $utensilRow) {
                if (!is_array($utensilRow)) {
                    continue;
                }
                $termId = isset($utensilRow['utensil']) ? (int) $utensilRow['utensil'] : 0;
                $perRatio = isset($utensilRow['pax_ratio']) ? (float) $utensilRow['pax_ratio'] : 0.0;
                $baseQty = isset($utensilRow['qty']) ? (float) $utensilRow['qty'] : 0.0;
                $unit = isset($utensilRow['unit']) && $utensilRow['unit'] !== '' ? sanitize_key((string) $utensilRow['unit']) : 'u';
                if ($termId <= 0 || $perRatio <= 0 || $baseQty <= 0) {
                    continue;
                }
                $amount = ceil($eqItem / $perRatio) * $baseQty;
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
            return ($a['term_id'] ?? 0) <=> ($b['term_id'] ?? 0);
        });

        $items = [];
        foreach ($totals as $t) {
            $termId = (int) ($t['term_id'] ?? 0);
            $unit   = (string) ($t['unit'] ?? 'u');
            $qty    = (float) ($t['qty'] ?? 0);
            if ($termId <= 0 || $qty <= 0) {
                continue;
            }
            $termName = $this->getTranslatedUtensilName($termId, $orderLanguage);
            if ($termName === '') {
                continue;
            }
            $items[] = '<li class="zs-recipe-ingredient">' . esc_html($termName) . ' <span class="zs-recipe-ingredient-qty">' . esc_html($this->formatNumber($qty)) . esc_html($unit) . '</span></li>';
        }

        if (empty($items)) {
            return '';
        }

        $html  = '<div class="brxe-div fdr-card__field">';
        $html .= '<span class="brxe-text-basic fdr-card__field-label">' . esc_html__('Utensils', 'zero-sense') . '</span>';
        $html .= '<ul class="brxe-text-basic fdr-card__field-value">' . implode('', $items) . '</ul>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get translated utensil name
     */
    private function getTranslatedUtensilName(int $termId, string $orderLanguage): string
    {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            $term = get_term($termId, self::TAX_UTENSIL);
            return $term instanceof \WP_Term ? $term->name : '';
        }

        $translatedId = apply_filters('wpml_object_id', $termId, self::TAX_UTENSIL, false, $orderLanguage);
        if (!$translatedId) {
            $translatedId = $termId;
        }

        $term = get_term($translatedId, self::TAX_UTENSIL);
        return $term instanceof \WP_Term ? $term->name : '';
    }

    /**
     * Get simplified liquids total (one fdr-card__field per liquid)
     */
    private function getRecipeLiquidsSimple($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Order Liquids Simple');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
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
            $recipeId = $this->resolveRecipeIdForItem($item, $product);
            if ($recipeId <= 0) {
                continue;
            }
            // Only include paella recipes
            $needsPaella = get_post_meta($recipeId, self::META_NEEDS_PAELLA, true);
            if ($needsPaella !== '1') {
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

            $recipeLiquids = get_post_meta($recipeId, self::META_RECIPE_LIQUIDS, true);
            if (!is_array($recipeLiquids)) {
                continue;
            }

            foreach ($recipeLiquids as $liquidRow) {
                if (!is_array($liquidRow)) {
                    continue;
                }
                $termId = isset($liquidRow['liquid']) ? (int) $liquidRow['liquid'] : 0;
                $litresPerPax = isset($liquidRow['qty']) ? (float) $liquidRow['qty'] : 0.0;

                if ($termId <= 0 || $litresPerPax <= 0) {
                    continue;
                }

                $amount = $litresPerPax * $eqItem;
                if ($amount <= 0) {
                    continue;
                }

                $k = (string) $termId;
                if (!isset($totals[$k])) {
                    $totals[$k] = ['term_id' => $termId, 'qty' => 0.0];
                }
                $totals[$k]['qty'] += $amount;
            }
        }

        if (empty($totals)) {
            return '';
        }

        $html = '';
        foreach ($totals as $t) {
            $termId = (int) ($t['term_id'] ?? 0);
            $qty    = (float) ($t['qty'] ?? 0);

            if ($termId <= 0 || $qty <= 0) {
                continue;
            }

            $termName = $this->getTranslatedLiquidName($termId, $orderLanguage);
            if ($termName === '') {
                continue;
            }

            $html .= '<div class="brxe-div fdr-card__field">';
            $html .= '<span class="brxe-text-basic fdr-card__field-label">' . esc_html($termName) . '</span>';
            $html .= '<span class="brxe-text-basic fdr-card__field-value">' . esc_html($this->formatNumber($qty)) . 'L</span>';
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Get combined ingredients + liquids (one fdr-card__field per item)
     */
    private function getRecipeFullSimple($post): string
    {
        return $this->getRecipeTotalIngredientsSimple($post) . $this->getRecipeLiquidsSimple($post);
    }

    /**
     * Get translated liquid name
     */
    private function getTranslatedLiquidName(int $termId, string $orderLanguage): string
    {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            $term = get_term($termId, self::TAX_LIQUID);
            return $term instanceof \WP_Term ? $term->name : '';
        }

        $translatedId = apply_filters('wpml_object_id', $termId, self::TAX_LIQUID, false, $orderLanguage);
        if (!$translatedId) {
            $translatedId = $termId;
        }

        $term = get_term($translatedId, self::TAX_LIQUID);
        return $term instanceof \WP_Term ? $term->name : '';
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
        if (!is_wp_error($roleTerms)) {
            foreach ($roleTerms as $term) {
                if ($term instanceof \WP_Term) {
                    $roleNames[$term->slug] = $term->name;
                }
            }
        }

        // Build HTML output — one fdr-card__field per role
        $html = '';

        foreach ($staffByRole as $roleSlug => $staffIds) {
            $roleName = $roleNames[$roleSlug] ?? ucfirst(str_replace('-', ' ', $roleSlug));

            $members = [];
            foreach ($staffIds as $staffId) {
                $staffPost = get_post($staffId);
                if (!$staffPost) {
                    continue;
                }

                $name  = esc_html($staffPost->post_title);
                $phone = get_post_meta($staffId, 'zs_staff_phone', true);
                $email = get_post_meta($staffId, 'zs_staff_email', true);

                $contactHtml = '';
                if ($phone || $email) {
                    $contactInner = '';
                    if ($phone) {
                        $contactInner .= '<p><a href="tel:' . esc_attr($phone) . '" class="zs-staff-phone">' . esc_html($phone) . '</a></p>';
                    }
                    if ($email) {
                        $contactInner .= '<p><a href="mailto:' . esc_attr($email) . '" class="zs-staff-email">' . esc_html($email) . '</a></p>';
                    }
                    $contactHtml = '<span class="zs-staff-contact">' . $contactInner . '</span>';
                }

                $memberHtml = '<span class="zs-staff-member">';
                $memberHtml .= '<p class="zs-staff-name">' . $name . '</p>';
                $memberHtml .= $contactHtml;
                $memberHtml .= '</span>';
                $members[] = $memberHtml;
            }

            if (empty($members)) {
                continue;
            }

            $html .= '<div class="brxe-div fdr-card__field">';
            $html .= '<span class="brxe-text-basic fdr-card__field-label">' . esc_html($roleName) . '</span>';
            $html .= '<div class="brxe-text-basic fdr-card__field-value">' . implode('', $members) . '</div>';
            $html .= '</div>';
        }

        return $html;
    }

    private function getInventoryList($post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Inventory & Materials');
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return '';
        }

        $calculated = MaterialCalculator::calculate($order);
        $overrides  = ManualOverride::get($orderId);
        $final      = ManualOverride::apply($calculated, $overrides);

        if (empty($final)) {
            return '';
        }

        $definitions = [];
        foreach (MaterialDefinitions::getAll() as $mat) {
            $definitions[$mat['key']] = $mat['label'];
        }

        $html = '';
        foreach ($final as $key => $qty) {
            if ($qty <= 0) {
                continue;
            }
            $label = $definitions[$key] ?? ucwords(str_replace('_', ' ', $key));
            $html .= '<div class="brxe-div fdr-card__field">';
            $html .= '<span class="brxe-text-basic fdr-card__field-label">' . esc_html($label) . '</span>';
            $html .= '<span class="brxe-text-basic fdr-card__field-value">' . esc_html((string) $qty) . '</span>';
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Render the rabbit toggle switch for the shop loop.
     * Returns HTML only if the current product has _zs_has_rabbit_option = yes.
     * Returns empty string otherwise (no output for products without rabbit option).
     */
    private function getRabbitToggle($post): string
    {
        $productId = 0;

        if ($post instanceof WP_Post) {
            $productId = $post->ID;
        } elseif (is_numeric($post)) {
            $productId = (int) $post;
        }

        if ($productId <= 0) {
            global $product;
            if (is_object($product) && method_exists($product, 'get_id')) {
                $productId = $product->get_id();
            }
        }

        if ($productId <= 0) {
            return '';
        }

        // Capture title in current (translated) language before resolving to original
        $productName = esc_html(get_the_title($productId));

        // Keep the current-language ID for data-pid (JS sends this to the session endpoint)
        $pidForJs = $productId;

        // WPML: resolve to canonical (default lang) product for meta reads
        if (defined('ICL_SITEPRESS_VERSION')) {
            $defaultLang = apply_filters('wpml_default_language', null);
            $originalId  = apply_filters('wpml_object_id', $productId, 'product', true, $defaultLang);
            if ($originalId) {
                $productId = (int) $originalId;
            }
        }

        if (get_post_meta($productId, '_zs_has_rabbit_option', true) !== 'yes') {
            return '';
        }

        // Read current session choice to restore toggle state
        $isWithout = false;
        if (function_exists('WC') && WC()->session) {
            $stored = WC()->session->get('zs_rabbit_choice_' . $pidForJs);
            if ($stored === 'without') {
                $isWithout = true;
            }
        }

        $labelWithout = function_exists('icl_t')
            ? esc_html(icl_t('zero-sense', 'rabbit_toggle_label', 'Sin conejo'))
            : esc_html__('Sin conejo', 'zero-sense');

        $infoTemplate = function_exists('icl_t')
            ? icl_t('zero-sense', 'rabbit_toggle_info', 'En la paella "%s", el conejo es un ingrediente típico, pero sabemos que en algunas culturas no es común; por eso, ofrecemos la opción de hacer la paella con o sin conejo.')
            : /* translators: %s: product name */ __('En la paella "%s", el conejo es un ingrediente típico, pero sabemos que en algunas culturas no es común; por eso, ofrecemos la opción de hacer la paella con o sin conejo.', 'zero-sense');
        $infoText = esc_html(sprintf($infoTemplate, $productName));

        $infoIconSvg = '<svg class="fill stroke brxe-bwhjmy brxe-icon info-box__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><title>48 c info</title><g fill="currentColor" class="nc-icon-wrapper"><path d="M24,1C11.297,1,1,11.297,1,24s10.297,23,23,23,23-10.297,23-23S36.703,1,24,1Zm2,36c0,.552-.448,1-1,1h-2c-.552,0-1-.448-1-1V19c0-.552,.448-1,1-1h2c.552,0,1,.448,1,1v18Zm-2-23c-1.381,0-2.5-1.119-2.5-2.5s1.119-2.5,2.5-2.5,2.5,1.119,2.5,2.5-1.119,2.5-2.5,2.5Z" fill="currentColor" class="nc-icon-wrapper"></path></g></svg>';

        return '<label class="zs-rabbit-toggle" aria-label="' . esc_attr__('Sin conejo', 'zero-sense') . '">'
            . '<input type="checkbox" name="zs_rabbit_choice" value="without" class="zs-rabbit-toggle__input" data-pid="' . (int) $pidForJs . '"' . ($isWithout ? ' checked' : '') . '>'
            . '<span class="zs-rabbit-toggle__track">'
            . '<span class="zs-rabbit-toggle__thumb"></span>'
            . '</span>'
            . '<span class="zs-rabbit-toggle__label">' . $labelWithout . '</span>'
            . '</label>'
            . '<div class="brxe-block info-box" style="margin-top:12px;">'
            . '<p class="brxe-text-basic info-box__text">' . $infoText . '</p>'
            . $infoIconSvg
            . '</div>'
            . '<style>'
            . '.zs-rabbit-toggle{display:inline-flex;align-items:center;gap:8px;cursor:pointer;user-select:none;font-size:14px;}'
            . '.zs-rabbit-toggle__input{position:absolute;opacity:0;width:0;height:0;}'
            . '.zs-rabbit-toggle__track{position:relative;display:inline-block;width:40px;height:22px;background:#ccc;border-radius:11px;transition:background .2s;flex-shrink:0;}'
            . '.zs-rabbit-toggle__input:checked+.zs-rabbit-toggle__track{background:#2271b1;}'
            . '.zs-rabbit-toggle__thumb{position:absolute;top:3px;left:3px;width:16px;height:16px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.3);}'
            . '.zs-rabbit-toggle__input:checked+.zs-rabbit-toggle__track .zs-rabbit-toggle__thumb{transform:translateX(18px);}'
            . '.zs-rabbit-toggle__label{font-size:var(--text-m;}'
            . '</style>';

    }

    public function registerRabbitStrings(): void
    {
        if (!function_exists('icl_register_string')) {
            return;
        }
        icl_register_string('zero-sense', 'rabbit_toggle_label', 'Sin conejo');
        icl_register_string('zero-sense', 'rabbit_toggle_info', 'En la paella "%s", el conejo es un ingrediente típico, pero sabemos que en algunas culturas no es común; por eso, ofrecemos la opción de hacer la paella con o sin conejo.');
    }

    public function enqueueRabbitToggleAssets(): void
    {
        $ajaxUrl = esc_js(admin_url('admin-ajax.php'));
        $js = '(function(){
            document.addEventListener("change", function(e) {
                var toggle = e.target;
                if (!toggle.classList || !toggle.classList.contains("zs-rabbit-toggle__input")) return;
                var pid = toggle.dataset.pid;
                if (!pid) return;
                var choice = toggle.checked ? "without" : "with";
                var fd = new FormData();
                fd.append("action", "zs_set_rabbit_choice");
                fd.append("product_id", pid);
                fd.append("choice", choice);
                fetch("' . $ajaxUrl . '", {method:"POST", body:fd, credentials:"same-origin"});
            });
        })();';
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', $js);
    }

    /**
     * Resolve the effective recipe ID for an order item, taking rabbit choice into account.
     * If the item has _zs_rabbit_choice = 'without' and the product has zs_recipe_id_no_rabbit set,
     * returns the no-rabbit recipe ID. Otherwise returns the standard recipe ID.
     */
    private function resolveRecipeIdForItem(\WC_Order_Item_Product $item, \WC_Product $product): int
    {
        $recipeId = (int) $product->get_meta(self::META_PRODUCT_RECIPE_ID, true);
        if ($recipeId <= 0) {
            return 0;
        }

        $rabbitChoice = $item->get_meta(self::META_RABBIT_CHOICE, true);
        if ($rabbitChoice !== 'without') {
            return $recipeId;
        }

        $noRabbitId = (int) $product->get_meta(self::META_PRODUCT_RECIPE_NO_RABBIT, true);
        return $noRabbitId > 0 ? $noRabbitId : $recipeId;
    }
}
