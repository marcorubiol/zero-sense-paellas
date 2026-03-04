<?php
namespace ZeroSense\Features\Integrations\Flowmattic;

use WC_Order;
use WC_Order_Item;
use WP_REST_Request;
use WP_REST_Response;
use ZeroSense\Core\MetaFieldRegistry;
use ZeroSense\Features\WooCommerce\EventManagement\Components\DataExposer;

class ApiExtension
{
    /**
     * Get exposed meta keys from registry
     *
     * @return array<int, string>
     */
    private function getExposedMetaKeys(): array
    {
        $registry = MetaFieldRegistry::getInstance();
        $keys = $registry->getAllKeys();
        
        // Add legacy aliases for backward compatibility
        foreach ($registry->getAllFields() as $key => $metadata) {
            $legacyKeys = $metadata['legacy_keys'] ?? [];
            if (is_array($legacyKeys)) {
                $keys = array_merge($keys, $legacyKeys);
            }
        }
        
        return array_unique($keys);
    }

    /**
     * Get translatable meta keys from registry
     *
     * @return array<int, string>
     */
    private function getTranslatableKeys(): array
    {
        $registry = MetaFieldRegistry::getInstance();
        $translatable = $registry->getTranslatableKeys();
        
        // Add legacy aliases for translatable fields
        $result = [];
        foreach ($translatable as $key) {
            $result[] = $key;
            $legacyKeys = $registry->getLegacyAliases($key);
            if (!empty($legacyKeys)) {
                $result = array_merge($result, $legacyKeys);
            }
        }
        
        return array_unique($result);
    }

    public function register(): void
    {
        add_filter('woocommerce_rest_prepare_shop_order_object', [$this, 'addCustomFieldsToApi'], 10, 3);
        add_filter('flowmattic/trigger/wc_order/data', [$this, 'addCustomFieldsToOrderData'], 10, 2);
        add_filter('flowmattic/trigger/wc_order/order_items', [$this, 'addMasterIdToOrderItems'], 10, 2);
        add_filter('woocommerce_webhook_payload', [$this, 'addCustomFieldsToWebhook'], 10, 4);
    }

    public function addCustomFieldsToApi(WP_REST_Response $response, WC_Order $order, WP_REST_Request $request): WP_REST_Response
    {
        $data = $response->get_data();
        $this->addExposedMetaFields($order, $data);
        $data = array_merge($data, $this->addMultilingualPaymentUrls($order));
        $response->set_data($data);

        return $response;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function addCustomFieldsToOrderData(array $data, WC_Order $order): array
    {
        $this->addExposedMetaFields($order, $data);
        $data = array_merge($data, $this->addMultilingualPaymentUrls($order));

        return $data;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    public function addMasterIdToOrderItems(array $items, WC_Order $order): array
    {
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $masterId = $this->extractMasterId($item);
            if ($masterId !== null) {
                $items[$index]['master_id'] = $masterId;
                $items[$index]['master_id_key'] = 'master_id';
                $items[$index]['master_id_value'] = $masterId;
            }

        }

        return $items;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function addCustomFieldsToWebhook(array $payload, string $resource, int $resourceId, int $webhookId): array
    {
        if ($resource !== 'order' || !isset($payload['id'])) {
            return $payload;
        }

        $order = wc_get_order($resourceId);
        if (!$order instanceof WC_Order) {
            return $payload;
        }

        $this->addExposedMetaFields($order, $payload);
        $payload = array_merge($payload, $this->addMultilingualPaymentUrls($order));

        return $payload;
    }

    /**
     * @param array<string,mixed> $item
     */
    private function extractMasterId(array $item)
    {
        if (isset($item['meta_data']) && is_array($item['meta_data'])) {
            foreach ($item['meta_data'] as $meta) {
                if (!is_array($meta)) {
                    continue;
                }

                $key = $meta['key'] ?? ($meta['display_key'] ?? '');
                if ($key === 'master_id') {
                    $value = $meta['value'] ?? ($meta['display_value'] ?? null);
                    if ($value === null) {
                        continue;
                    }

                    return is_numeric($value) ? (int) $value : $value;
                }
            }
        }

        $productId = isset($item['variation_id']) && (int) $item['variation_id'] > 0
            ? (int) $item['variation_id']
            : (int) ($item['product_id'] ?? 0);

        return $productId > 0 ? $productId : null;
    }

    /**
     * @param array<string,mixed> $data Reference array updated with meta values.
     */
    private function addExposedMetaFields(WC_Order $order, array &$data): void
    {
        $orderId = $order->get_id();
        $orderLanguage = $order->get_meta('wpml_language', true);
        $originalLanguage = null;

        if (defined('ICL_SITEPRESS_VERSION') && function_exists('apply_filters')) {
            $originalLanguage = apply_filters('wpml_current_language', null);
            if (!empty($orderLanguage) && $orderLanguage !== $originalLanguage) {
                do_action('wpml_switch_language', $orderLanguage);
            }
        }

        $exposedKeys = $this->getExposedMetaKeys();
        $translatableKeys = $this->getTranslatableKeys();
        
        foreach ($exposedKeys as $key) {
            $value = null;

            if (in_array($key, $translatableKeys, true) && function_exists('zero_sense_get_translated_meta')) {
                $value = zero_sense_get_translated_meta($orderId, $key);
            } else {
                $value = $order->get_meta($key, true);
            }

            if ($value !== '' && $value !== null) {
                $data[$key] = $value;
            }
        }

        $canonicalIdRaw = $order->get_meta('zs_event_service_location', true);
        $canonicalId = is_numeric($canonicalIdRaw) ? (int) $canonicalIdRaw : 0;
        if ($canonicalId > 0) {
            $data['zs_event_service_location_term_id_default'] = $canonicalId;

            $orderLangId = $canonicalId;
            if (defined('ICL_SITEPRESS_VERSION') && function_exists('apply_filters') && is_string($orderLanguage) && $orderLanguage !== '') {
                $translatedId = apply_filters('wpml_object_id', $canonicalId, 'service-area', true, $orderLanguage);
                if ($translatedId) {
                    $orderLangId = (int) $translatedId;
                }
            }

            $data['zs_event_service_location_term_id_order_lang'] = $orderLangId;

            $term = get_term($orderLangId, 'service-area');
            if ($term instanceof \WP_Term) {
                $data['zs_event_service_location_term_slug_order_lang'] = $term->slug;
                $data['zs_event_service_location_term_name_order_lang'] = $term->name;
            }
        }

        $this->addComputedFields($order, $data, is_string($orderLanguage) ? $orderLanguage : '');

        if ($originalLanguage !== null && !empty($orderLanguage) && $orderLanguage !== $originalLanguage) {
            do_action('wpml_switch_language', $originalLanguage);
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function addComputedFields(WC_Order $order, array &$data, string $orderLanguage): void
    {
        // Order-level computed fields
        $data['zs_order_products_simple'] = $this->buildOrderProductsSimple($order);
        $data['zs_order_products_count'] = count($order->get_items());
        $data['zs_order_products_by_category_json'] = wp_json_encode($this->buildOrderProductsByCategory($order));
        $data['zs_event_media_urls'] = $this->buildEventMediaUrls($order);

        $lastModifiedRaw = $this->resolveOrderLastModifiedRaw($order);
        if ($lastModifiedRaw !== '') {
            $timestamp = strtotime($lastModifiedRaw);
            if ($timestamp !== false) {
                $dateFormat = get_option('date_format', 'Y-m-d');
                $timeFormat = get_option('time_format', 'H:i:s');
                $data['zs_order_last_modified'] = date_i18n($dateFormat . ' ' . $timeFormat, $timestamp);
                $data['zs_order_last_modified_date'] = date_i18n($dateFormat, $timestamp);
                $data['zs_order_last_modified_time'] = date_i18n($timeFormat, $timestamp);
            }
        }

        if ($orderLanguage !== '') {
            $data['zs_order_language_name'] = $this->resolveOrderLanguageName($orderLanguage);
            $data['zs_order_language_code'] = strtoupper($orderLanguage);
        }

        // Get all event data from DataExposer (automatically includes all staff fields and future additions)
        // Add zs_ prefix to all fields that don't already have it
        $eventData = DataExposer::getOrderEventData($order);
        foreach ($eventData as $key => $value) {
            $prefixedKey = strpos($key, 'zs_') === 0 ? $key : 'zs_' . $key;
            $data[$prefixedKey] = $value;
        }
    }

    private function buildOrderProductsSimple(WC_Order $order): string
    {
        $products = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item) {
                continue;
            }

            $name = $item->get_name();
            if ($name === '') {
                continue;
            }

            $quantity = (int) $item->get_quantity();
            $products[] = $quantity > 1 ? $name . ' (' . $quantity . 'x)' : $name;
        }

        return implode(', ', $products);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildOrderProductsByCategory(WC_Order $order): array
    {
        $categorized = [];

        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item) {
                continue;
            }

            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $productId = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();
            $terms = get_the_terms($productId, 'product_cat');

            if (!$terms || is_wp_error($terms)) {
                $terms = [];
            }

            if ($terms === []) {
                $terms = [(object) ['term_id' => 0, 'name' => __('Other', 'zero-sense'), 'slug' => 'other']];
            }

            foreach ($terms as $term) {
                if (!isset($term->term_id, $term->name, $term->slug)) {
                    continue;
                }

                if ((string) $term->slug === 'uncategorized') {
                    continue;
                }

                $termId = (int) $term->term_id;
                if (!isset($categorized[$termId])) {
                    $categorized[$termId] = [
                        'category_id' => $termId,
                        'category_name' => (string) $term->name,
                        'category_slug' => (string) $term->slug,
                        'products' => [],
                    ];
                }

                $categorized[$termId]['products'][] = [
                    'name' => $item->get_name(),
                    'quantity' => (int) $item->get_quantity(),
                ];
            }
        }

        return array_values($categorized);
    }

    /**
     * @return array<int,string>
     */
    private function buildEventMediaUrls(WC_Order $order): array
    {
        $raw = $order->get_meta('_zs_event_media', true);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $ids = array_filter(array_map('trim', explode(',', $raw)));
        $urls = [];

        foreach ($ids as $id) {
            $url = wp_get_attachment_url((int) $id);
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function resolveOrderLastModifiedRaw(WC_Order $order): string
    {
        $tracked = $order->get_meta('_zs_last_modified', true);
        if (is_string($tracked) && $tracked !== '') {
            return $tracked;
        }

        $postData = get_post($order->get_id());
        if ($postData && isset($postData->post_modified) && is_string($postData->post_modified)) {
            return $postData->post_modified;
        }

        return '';
    }

    private function resolveOrderLanguageName(string $langCode): string
    {
        $map = [
            'es' => 'Español',
            'en' => 'English',
            'ca' => 'Català',
        ];

        return $map[$langCode] ?? strtoupper($langCode);
    }

    /**
     * @return array<string,string>
     */
    private function addMultilingualPaymentUrls(WC_Order $order): array
    {
        $urls = [];

        if (!function_exists('icl_object_id') || !defined('ICL_LANGUAGE_CODE')) {
            $urls['payment_url'] = $order->get_checkout_payment_url();

            return $urls;
        }

        $orderLanguage = $order->get_meta('wpml_language', true);
        $activeLanguages = apply_filters('wpml_active_languages', null, 'orderby=id&order=desc');

        if (empty($activeLanguages)) {
            $urls['payment_url'] = $order->get_checkout_payment_url();

            return $urls;
        }

        $originalLanguage = apply_filters('wpml_current_language', null);
        $checkoutPageId = wc_get_page_id('checkout');

        foreach ($activeLanguages as $code => $languageData) {
            do_action('wpml_switch_language', $code);

            $endpoint = WC()->query->get_query_vars()['order-pay'] ?? 'order-pay';
            $translatedCheckoutId = apply_filters('wpml_object_id', $checkoutPageId, 'page', true, $code);
            $baseCheckoutUrl = get_permalink($translatedCheckoutId);

            $paymentUrl = trailingslashit($baseCheckoutUrl) . $endpoint . '/' . $order->get_id() . '/?pay_for_order=true&key=' . $order->get_order_key();
            $urls['payment_url_' . $code] = esc_url_raw($paymentUrl);
        }

        if ($originalLanguage) {
            do_action('wpml_switch_language', $originalLanguage);
        }

        $primaryCode = !empty($orderLanguage) && isset($urls['payment_url_' . $orderLanguage])
            ? $orderLanguage
            : apply_filters('wpml_default_language', null);

        $urls['payment_url'] = $urls['payment_url_' . $primaryCode] ?? $order->get_checkout_payment_url();

        return $urls;
    }
}
