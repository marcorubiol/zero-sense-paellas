<?php

declare(strict_types=1);

namespace ZeroSense\Features\Integrations\Bricks;

use ZeroSense\Core\FeatureInterface;
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
        'phone' => 'Shipping Phone',
    ];

    private const META_BOX_FIELDS = [
        'total_guests' => 'Total Guests',
        'adults' => 'Adults',
        'children_5_to_8' => 'Children 5-8',
        'children_0_to_4' => 'Children 0-4',
        'event_service_location' => 'Event Service Location',
        'event_city' => 'Event City',
        'location_link' => 'Location Link',
        'event_date' => 'Event Date',
        'serving_time' => 'Serving Time',
        'event_start_time' => 'Event Start Time',
        'event_type' => 'Event Type',
        'promo_code' => 'Promo Code',
        'how_found_us' => 'How Found Us',
        'intolerances' => 'Intolerances',
        'event_address' => 'Event Address',
    ];

    private const SELECT_FIELDS = [
        'event_type',
        'how_found_us',
    ];

    // Map MetaBox field names to ZeroSense meta keys (same as in MetaBoxMigrator)
    private const FIELD_MAPPING = [
        'total_guests' => '_event_total_guests',
        'adults' => '_event_adults',
        'children_5_to_8' => '_event_children_5_to_8',
        'children_0_to_4' => '_event_children_0_to_4',
        'event_service_location' => '_event_service_location',
        'event_address' => '_event_address',
        'event_city' => '_event_city',
        'location_link' => '_event_location_link',
        'event_date' => '_event_date',
        'serving_time' => '_event_serving_time',
        'event_start_time' => '_event_start_time',
        'event_type' => '_event_type',
        'how_found_us' => '_event_how_found_us',
        'promo_code' => '_event_promo_code',
        'intolerances' => '_event_intolerances',
        'location' => '_event_location',
    ];

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
            'name' => '{woo_order_note}',
            'label' => 'Customer Note',
            'group' => 'WooCommerce',
        ];

        foreach (self::META_BOX_FIELDS as $field => $label) {
            $tags[] = [
                'name' => '{woo_mb_' . $field . '}',
                'label' => $label,
                'group' => 'WooCommerce',
            ];
        }

        return $tags;
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

        if (strpos($tag, '{woo_mb_') === 0) {
            $field = $this->stripTag($tag, 'woo_mb_');
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

        $content = $this->replaceTagsInContent($content, $post, 'woo_billing_', function (string $field) use ($post): string {
            return $this->getBillingFieldValue($field, $post);
        });

        $content = $this->replaceTagsInContent($content, $post, 'woo_shipping_', function (string $field) use ($post): string {
            return $this->getShippingFieldValue($field, $post);
        });

        $content = str_replace('{woo_order_note}', $this->getOrderNote($post), $content);

        return $this->replaceTagsInContent($content, $post, 'woo_mb_', function (string $field) use ($post): string {
            return $this->getMetaBoxFieldValue($field, $post);
        });
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

        $value = get_post_meta($orderId, '_billing_' . $field, true);

        if ($field === 'country') {
            return $this->formatCountry($value);
        }

        if ($field === 'state') {
            $country = get_post_meta($orderId, '_billing_country', true);
            return $this->formatState($value, $country);
        }

        return is_string($value) ? $value : '';
    }

    private function getShippingFieldValue(string $field, $post): string
    {
        $orderId = $this->resolveOrderId($post);
        if (!$orderId) {
            return $this->builderPlaceholder('Shipping ' . $field);
        }

        if ($field === 'phone') {
            $value = get_post_meta($orderId, '_shipping_phone', true);
            return is_string($value) ? $value : '';
        }

        $value = get_post_meta($orderId, '_shipping_' . $field, true);

        if ($field === 'country') {
            return $this->formatCountry($value);
        }

        if ($field === 'state') {
            $country = get_post_meta($orderId, '_shipping_country', true);
            return $this->formatState($value, $country);
        }

        return is_string($value) ? $value : '';
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
            if (isset($wp->query_vars['order-pay'])) {
                return absint($wp->query_vars['order-pay']);
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
        $metaKey = self::FIELD_MAPPING[$field] ?? $field;

        // Get value using HPOS-compatible method
        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order) {
            $raw = $order->get_meta($metaKey, true);
        } else {
            // Fallback to post meta for non-HPOS orders
            $raw = get_post_meta($orderId, $metaKey, true);
        }
        
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
        if (is_numeric($value) && (int) $value > 0) {
            return date('d/m/Y', (int) $value);
        }

        if (!is_string($value)) {
            return '';
        }

        $formatted = $value;

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $formatted, $matches)) {
            return $matches[3] . '/' . $matches[2] . '/' . $matches[1];
        }

        if (preg_match('/^(\d{2})(\d{2})\/(\d{2})(\d{2})\/(\d{2})(\d{2})$/', $formatted, $matches)) {
            return $matches[1] . '/' . $matches[3] . '/20' . $matches[5];
        }

        if (preg_match('/(\d{2}\/\d{2}\/\d{4})(\d{2}\/\d{2}\/\d{4})/', $formatted, $matches)) {
            return $matches[1];
        }

        if (strpos($formatted, ',') !== false) {
            $parts = explode(',', $formatted);
            return trim($parts[0]);
        }

        if (strlen($formatted) > 10 && preg_match('/(\d{2}\/\d{2}\/\d{4})/', $formatted, $matches)) {
            return $matches[1];
        }

        return $formatted;
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
