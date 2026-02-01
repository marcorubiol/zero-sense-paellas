<?php
namespace ZeroSense\Features\Integrations\Flowmattic;

use WC_Order;
use WC_Order_Item;
use WP_REST_Request;
use WP_REST_Response;

class ApiExtension
{
    /**
     * @var array<string>
     */
    private array $exposedMetaKeys = [
        'total_guests',
        'adults',
        'children_5_to_8',
        'children_0_to_4',
        'event_city',
        'location_link',
        'event_date',
        'event_address',
        'serving_time',
        'event_start_time',
        'event_type',
        'promo_code',
        'how_found_us',
        'intolerances',
        'event_service_location',
        'location',
        'zs_event_total_guests',
        'zs_event_adults',
        'zs_event_children_5_to_8',
        'zs_event_children_0_to_4',
        'zs_event_service_location',
        'zs_event_address',
        'zs_event_city',
        'zs_event_location_link',
        'zs_event_date',
        'zs_event_team_arrival_time',
        'zs_event_serving_time',
        'zs_event_start_time',
        'zs_event_type',
        'zs_event_how_found_us',
        'zs_event_intolerances',
        'zs_event_public_token',
        'wpml_language',
        'marketing_consent_checkbox',
        'budget_email_content',
        'final_details_email_content',
        'zs_shipping_email',
        'zs_ops_notes',
        'zs_ops_material',
        'zs_deposits_has_deposit',
        'zs_deposits_deposit_amount',
        'zs_deposits_deposit_percentage',
        'zs_deposits_remaining_amount',
        'zs_deposits_balance_amount',
        'zs_deposits_is_manual_override',
        'zs_deposits_is_deposit_paid',
        'zs_deposits_deposit_payment_date',
        'zs_deposits_is_balance_paid',
        'zs_deposits_balance_payment_date',
        'zs_deposits_payment_flow',
        'zs_deposits_is_cancelled',
        'zs_deposits_cancelled_date',
        'zs_deposits_is_failed',
        'zs_deposits_failed_code',
        'zs_deposits_failed_date',
    ];

    /**
     * @var array<string>
     */
    private array $translatableMetaBoxFields = [
        'event_type',
        'event_service_location',
        'how_found_us',
    ];

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

            if (isset($items[$index]['meta_data']) && is_array($items[$index]['meta_data'])) {
                $slim = [];
                foreach ($items[$index]['meta_data'] as $meta) {
                    if (is_array($meta) && isset($meta['id'])) {
                        $id = (int) $meta['id'];
                        if ($id > 0) {
                            $slim[] = ['id' => $id];
                        }
                    }
                }
                $items[$index]['meta_data'] = $slim;
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

        foreach ($this->exposedMetaKeys as $key) {
            $value = null;

            if (in_array($key, $this->translatableMetaBoxFields, true) && function_exists('zero_sense_get_translated_meta')) {
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
            $data['event_service_location_term_id_default'] = $canonicalId;

            $orderLangId = $canonicalId;
            if (defined('ICL_SITEPRESS_VERSION') && function_exists('apply_filters') && is_string($orderLanguage) && $orderLanguage !== '') {
                $translatedId = apply_filters('wpml_object_id', $canonicalId, 'service-area', true, $orderLanguage);
                if ($translatedId) {
                    $orderLangId = (int) $translatedId;
                }
            }

            $data['event_service_location_term_id_order_lang'] = $orderLangId;

            $term = get_term($orderLangId, 'service-area');
            if ($term instanceof \WP_Term) {
                $data['event_service_location_term_slug_order_lang'] = $term->slug;
                $data['event_service_location_term_name_order_lang'] = $term->name;
            }
        }

        if ($originalLanguage !== null && !empty($orderLanguage) && $orderLanguage !== $originalLanguage) {
            do_action('wpml_switch_language', $originalLanguage);
        }
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
