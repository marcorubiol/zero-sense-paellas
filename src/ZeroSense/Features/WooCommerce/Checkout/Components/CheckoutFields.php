<?php
namespace ZeroSense\Features\WooCommerce\Checkout\Components;

use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\EventManagement\Support\FieldOptions;

class CheckoutFields
{
    public function __construct()
    {
        // Basic field customizations
        add_filter('woocommerce_checkout_fields', [$this, 'customize_checkout_fields']);

        // Event fields
        add_action('woocommerce_after_checkout_billing_form', [$this, 'display_event_fields']);
        add_action('woocommerce_checkout_process', [$this, 'validate_event_fields']);
        add_action('woocommerce_checkout_create_order', [$this, 'save_event_fields'], 20, 2);

        // Capture location from URL into WC session
        add_action('wp', [$this, 'captureLocationFromUrl']);

        // AJAX endpoint for localStorage → WC session sync
        add_action('wp_ajax_zs_set_location_session', [$this, 'ajaxSetLocationSession']);
        add_action('wp_ajax_nopriv_zs_set_location_session', [$this, 'ajaxSetLocationSession']);

        // Pre-fill billing_city from session
        add_filter('woocommerce_checkout_get_value', [$this, 'prefillCityFromSession'], 10, 2);

        // WPML compatibility fixes
        add_action('init', [$this, 'register_checkout_strings_for_translation']);
        $this->prevent_wpml_array_processing();

        // Preserve and translate service area filter across languages
        add_filter('icl_ls_languages', [$this, 'filterLanguageSwitcherUrls']);
        
        // Force shipping fields to always show
        add_filter('woocommerce_ship_to_different_address_checked', '__return_true');
        
        // Ensure shipping city and address 2 are always present
        add_filter('woocommerce_checkout_fields', function($fields) {
            // Ensure shipping fields exist
            if (!isset($fields['shipping'])) {
                $fields['shipping'] = [];
            }
            
            // Ensure shipping_city exists
            if (!isset($fields['shipping']['shipping_city'])) {
                $fields['shipping']['shipping_city'] = [
                    'label'        => __('City', 'woocommerce'),
                    'required'     => false,
                    'class'        => ['form-row-wide'],
                    'autocomplete' => 'address-level2',
                    'priority'     => 70,
                ];
            }
            
            // Ensure shipping_address_2 exists
            if (!isset($fields['shipping']['shipping_address_2'])) {
                $fields['shipping']['shipping_address_2'] = [
                    'label'        => __('Apartment, suite, unit, etc. (optional)', 'woocommerce'),
                    'required'     => false,
                    'class'        => ['form-row-wide'],
                    'autocomplete' => 'address-line2',
                    'priority'     => 60,
                ];
            }
            
            return $fields;
        }, 999);
    }

    public function captureLocationFromUrl(): void
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        if (isset($_GET['b_service-area'])) {
            $termId = absint($_GET['b_service-area']);
            if ($termId > 0) {
                WC()->session->set('zs_service_area', $this->normalizeToCanonicalTermId($termId));
            }
        }

        if (isset($_GET['city']) && $_GET['city'] !== '') {
            WC()->session->set('zs_city', sanitize_text_field((string) $_GET['city']));
        }
    }

    public function ajaxSetLocationSession(): void
    {
        if (!function_exists('WC') || !WC()->session) {
            wp_send_json_error();
            return;
        }

        $termId = isset($_POST['service_area']) ? absint($_POST['service_area']) : 0;
        if ($termId > 0) {
            WC()->session->set('zs_service_area', $this->normalizeToCanonicalTermId($termId));
        } else {
            WC()->session->set('zs_service_area', null);
        }

        $city = isset($_POST['city']) ? sanitize_text_field((string) $_POST['city']) : '';
        if ($city !== '') {
            WC()->session->set('zs_city', $city);
        } else {
            WC()->session->set('zs_city', null);
        }

        wp_send_json_success();
    }

    public function prefillCityFromSession(mixed $value, string $input): mixed
    {
        if (!in_array($input, ['billing_city', 'shipping_city'], true) || $value !== null) {
            return $value;
        }

        if (function_exists('WC') && WC()->session) {
            $city = WC()->session->get('zs_city');
            if (is_string($city) && $city !== '') {
                return $city;
            }
        }

        return $value;
    }

    private function normalizeToCanonicalTermId(int $termId): int
    {
        if (!defined('ICL_SITEPRESS_VERSION') || !function_exists('apply_filters')) {
            return $termId;
        }
        $defaultLang = apply_filters('wpml_default_language', null);
        if (!is_string($defaultLang) || $defaultLang === '') {
            return $termId;
        }
        $canonical = apply_filters('wpml_object_id', $termId, 'service-area', true, $defaultLang);
        return $canonical ? (int) $canonical : $termId;
    }

    public function customize_checkout_fields($fields)
    {
        // 1. Make last name not required
        if (isset($fields['billing']['billing_last_name'])) {
            $fields['billing']['billing_last_name']['required'] = false;
        }

        // 2. Unset unnecessary fields
        unset($fields['billing']['billing_company']);
        unset($fields['shipping']['shipping_company']);

        return $fields;
    }

    /**
     * Render event fields on checkout page
     */
    public function display_event_fields($checkout): void
    {
        $selectPlaceholder = __('-- Select an option --', 'zero-sense');

        echo '<h3>' . esc_html__('About the event', 'zero-sense') . '</h3>';
        echo '<div class="woocommerce-billing-fields__field-wrapper">';

        // Total guests + Adults (two columns)
        woocommerce_form_field(MetaKeys::TOTAL_GUESTS, [
            'type'             => 'number',
            'label'            => __('Total number of guests', 'zero-sense'),
            'required'         => true,
            'class'            => ['form-row-first'],
            'custom_attributes' => ['min' => '1', 'required' => 'required'],
        ], '');

        woocommerce_form_field(MetaKeys::ADULTS, [
            'type'             => 'number',
            'label'            => __('Adults', 'zero-sense'),
            'required'         => true,
            'class'            => ['form-row-last'],
            'custom_attributes' => ['min' => '0', 'required' => 'required'],
        ], '');

        // Children 5-8 + Children 0-4
        woocommerce_form_field(MetaKeys::CHILDREN_5_TO_8, [
            'type'  => 'number',
            'label' => __('Children 5-8 years (40%)', 'zero-sense'),
            'class' => ['form-row-first'],
            'custom_attributes' => ['min' => '0'],
        ], '');

        woocommerce_form_field(MetaKeys::CHILDREN_0_TO_4, [
            'type'  => 'number',
            'label' => __('Children 0-4 years (free)', 'zero-sense'),
            'class' => ['form-row-last'],
            'custom_attributes' => ['min' => '0'],
        ], '');

        // Address + Location link
        $addressDefault = $this->getUrlPrefill('address', '');
        woocommerce_form_field('event_address_checkout', [
            'type'     => 'text',
            'label'    => __('Event address', 'zero-sense'),
            'required' => true,
            'class'    => ['form-row-first'],
            'custom_attributes' => ['required' => 'required'],
            'default'  => $addressDefault,
        ], '');

        woocommerce_form_field('event_location_link_checkout', [
            'type'  => 'text',
            'label' => __('Location link', 'zero-sense'),
            'class' => ['form-row-last'],
        ], '');

        // Event date + Serving time
        woocommerce_form_field(MetaKeys::EVENT_DATE, [
            'type'     => 'text',
            'label'    => __('Event date', 'zero-sense'),
            'required' => true,
            'class'    => ['form-row-first', 'zs-datepicker'],
            'custom_attributes' => ['required' => 'required', 'autocomplete' => 'off'],
        ], '');

        woocommerce_form_field(MetaKeys::SERVING_TIME, [
            'type'     => 'text',
            'label'    => __('Paellas service time', 'zero-sense'),
            'required' => true,
            'class'    => ['form-row-last', 'zs-timepicker'],
            'custom_attributes' => ['required' => 'required', 'autocomplete' => 'off'],
            'default'  => '18:00',
        ], '');

        // Start time + Event type
        woocommerce_form_field(MetaKeys::START_TIME, [
            'type'    => 'text',
            'label'   => __('Event start time', 'zero-sense'),
            'class'   => ['form-row-first', 'zs-timepicker'],
            'custom_attributes' => ['autocomplete' => 'off'],
            'default' => '18:00',
        ], '');

        $eventTypeOptions = array_merge(['' => $selectPlaceholder], FieldOptions::getEventTypeOptions());
        woocommerce_form_field(MetaKeys::EVENT_TYPE, [
            'type'     => 'select',
            'label'    => __('Event type', 'zero-sense'),
            'required' => true,
            'options'  => $eventTypeOptions,
            'class'    => ['form-row-last'],
            'custom_attributes' => ['required' => 'required'],
        ], '');

        // How found us
        $howFoundUsOptions = array_merge(['' => $selectPlaceholder], FieldOptions::getHowFoundUsOptions());
        woocommerce_form_field(MetaKeys::HOW_FOUND_US, [
            'type'     => 'select',
            'label'    => __('Y por último, ¿Cómo nos conociste?', 'zero-sense'),
            'required' => true,
            'options'  => $howFoundUsOptions,
            'class'    => ['form-row-wide'],
            'custom_attributes' => ['required' => 'required'],
        ], '');

        echo '</div>';

        // Intolerances section
        echo '<h3>' . esc_html__('Allergies / intolerances', 'zero-sense') . '</h3>';
        $intolerancesId = esc_attr(MetaKeys::INTOLERANCES);
        $intolerancesLabel = esc_html__('Should we consider any notable food allergies or intolerances?', 'zero-sense');
        echo '<p class="form-row form-row-wide" style="width:100%!important;float:none!important;clear:both;">';
        echo '<label for="' . $intolerancesId . '">' . $intolerancesLabel . '</label>';
        echo '<textarea id="' . $intolerancesId . '" name="' . $intolerancesId . '" rows="4" style="width:100%;"></textarea>';
        echo '</p>';
    }

    /**
     * Validate required event fields
     */
    public function validate_event_fields(): void
    {
        $required = [
            MetaKeys::TOTAL_GUESTS   => __('Número total de comensales', 'zero-sense'),
            MetaKeys::ADULTS         => __('Adultos', 'zero-sense'),
            'event_address_checkout' => __('Dirección del evento', 'zero-sense'),
            MetaKeys::EVENT_DATE     => __('Fecha del evento', 'zero-sense'),
            MetaKeys::SERVING_TIME   => __('Hora de servir la paella (aprox)', 'zero-sense'),
            MetaKeys::EVENT_TYPE     => __('Tipo de evento', 'zero-sense'),
            MetaKeys::HOW_FOUND_US   => __('¿Cómo nos conociste?', 'zero-sense'),
        ];

        foreach ($required as $fieldId => $label) {
            $value = sanitize_text_field((string) ($this->getSubmittedFieldValue($fieldId) ?? ''));
            if ($value === '' || $value === '0') {
                wc_add_notice(
                    sprintf(__('%s is a required field.', 'woocommerce'), '<strong>' . esc_html($label) . '</strong>'),
                    'error'
                );
            }
        }

    }

    /**
     * Save event fields to order meta
     */
    public function save_event_fields(WC_Order $order, $data): void
    {
        // Number fields
        foreach ([
            MetaKeys::TOTAL_GUESTS,
            MetaKeys::ADULTS,
            MetaKeys::CHILDREN_5_TO_8,
            MetaKeys::CHILDREN_0_TO_4,
        ] as $key) {
            $value = $this->getSubmittedFieldValue($key);
            if ($value !== null) {
                $order->update_meta_data($key, absint($value));
            }
        }

        // Address → native WC shipping field
        $address = $this->getSubmittedFieldValue('event_address_checkout');
        if ($address !== null) {
            $order->set_shipping_address_1(sanitize_text_field((string) $address));
        }

        // Location link
        $locationLink = $this->getSubmittedFieldValue('event_location_link_checkout');
        if ($locationLink !== null) {
            $order->update_meta_data('_shipping_location_link', sanitize_text_field((string) $locationLink));
        }

        // Event date → normalize to ISO 8601
        $dateRaw = $this->getSubmittedFieldValue(MetaKeys::EVENT_DATE);
        if ($dateRaw !== null && $dateRaw !== '') {
            $date = \DateTime::createFromFormat('d/m/Y', (string) $dateRaw);
            if ($date) {
                $order->update_meta_data(MetaKeys::EVENT_DATE, $date->format('Y-m-d'));
            } else {
                $ts = strtotime((string) $dateRaw);
                $order->update_meta_data(MetaKeys::EVENT_DATE, $ts ? date('Y-m-d', $ts) : sanitize_text_field((string) $dateRaw));
            }
        }

        // Text/time fields
        foreach ([
            MetaKeys::SERVING_TIME,
            MetaKeys::START_TIME,
        ] as $key) {
            $value = $this->getSubmittedFieldValue($key);
            if ($value !== null) {
                $order->update_meta_data($key, sanitize_text_field((string) $value));
            }
        }

        // Select fields
        foreach ([
            MetaKeys::EVENT_TYPE,
            MetaKeys::HOW_FOUND_US,
        ] as $key) {
            $value = $this->getSubmittedFieldValue($key);
            if ($value !== null && $value !== '') {
                $order->update_meta_data($key, sanitize_text_field((string) $value));
            }
        }

        // Intolerances
        $intolerances = $this->getSubmittedFieldValue(MetaKeys::INTOLERANCES);
        if ($intolerances !== null) {
            $order->update_meta_data(MetaKeys::INTOLERANCES, sanitize_textarea_field((string) $intolerances));
        }

        // Service location (from hidden field, falls back to WC session)
        $serviceAreaRaw = $this->getSubmittedFieldValue('zs_checkout_service_area');
        if ($serviceAreaRaw === null && function_exists('WC') && WC()->session) {
            $serviceAreaRaw = WC()->session->get('zs_service_area');
        }
        if ($serviceAreaRaw !== null && absint($serviceAreaRaw) > 0) {
            $canonical = $this->normalizeToCanonicalTermId(absint($serviceAreaRaw));
            $order->update_meta_data(MetaKeys::SERVICE_LOCATION, $canonical);
        }

        // City → native WC shipping city (from billing_city field or WC session)
        $city = $this->getSubmittedFieldValue('billing_city');
        if ($city === null || $city === '') {
            if (function_exists('WC') && WC()->session) {
                $city = WC()->session->get('zs_city');
            }
        }
        if (is_string($city) && $city !== '') {
            $order->set_shipping_city(sanitize_text_field($city));
        }

        // Clear location session data after saving to avoid leaking into future orders
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('zs_city', null);
            WC()->session->set('zs_service_area', null);
            
            // Clear all rabbit choice session keys to reset toggle state for next order
            $sessionData = WC()->session->get_session_data();
            foreach ($sessionData as $key => $value) {
                if (strpos($key, 'zs_rabbit_choice_') === 0) {
                    WC()->session->set($key, null);
                }
            }
        }
    }

    /**
     * Register translation strings with WPML
     */
    public function register_checkout_strings_for_translation()
    {
        if (function_exists('icl_register_string')) {
            icl_register_string('woocommerce', 'checkout_select_option', '-- Select an option --');
        }
    }

    /**
     * Prevent WPML from processing array post_meta values (from legacy implementation)
     */
    private function prevent_wpml_array_processing()
    {
        $init_wpml_fix = function () {
            if (defined('ICL_SITEPRESS_VERSION')) {
                // Prevent WPML from processing post_meta arrays
                add_filter('wpml_st_get_post_meta_original_value', function ($value, $post_id, $meta_key) {
                    if (is_array($value)) {
                        return false; // Tell WPML to ignore this meta completely
                    }
                    return $value;
                }, 10, 3);
            }
        };

        if (defined('ICL_SITEPRESS_VERSION')) {
            $init_wpml_fix();
        } else {
            add_action('plugins_loaded', $init_wpml_fix, 1);
        }
    }

    public function filterLanguageSwitcherUrls($languages)
    {
        if (!is_array($languages) || empty($_GET['b_service-area'])) {
            return $languages;
        }

        $sourceId = (int) $_GET['b_service-area'];
        $term = get_term($sourceId);
        $taxonomy = (!is_wp_error($term) && $term && isset($term->taxonomy)) ? $term->taxonomy : 'service_area';

        foreach ($languages as &$lang) {
            if (!isset($lang['url'], $lang['language_code'])) {
                continue;
            }
            $targetId = apply_filters('wpml_object_id', $sourceId, $taxonomy, true, $lang['language_code']);
            if (!$targetId) {
                $targetId = $sourceId;
            }
            $url = remove_query_arg(['b_service-area'], $lang['url']);
            $url = add_query_arg('b_service-area', $targetId, $url);
            if (isset($_GET['city'])) {
                $url = add_query_arg('city', sanitize_text_field((string) $_GET['city']), $url);
            }
            $lang['url'] = $url;
        }

        return $languages;
    }

    private function getUrlPrefill(string $type, $default)
    {
        if (!empty($_POST)) {
            return $default;
        }

        if ($type === 'city' && isset($_GET['city'])) {
            return sanitize_text_field((string) $_GET['city']);
        }

        return $default;
    }

    private function getSubmittedFieldValue(string $fieldId)
    {
        if (isset($_POST[$fieldId])) {
            return $_POST[$fieldId];
        }

        $candidates = [];

        if (function_exists('apply_filters')) {
            $currentLanguage = apply_filters('wpml_current_language', null);
            $defaultLanguage = apply_filters('wpml_default_language', null);

            if (is_string($currentLanguage) && $currentLanguage !== '' && $currentLanguage !== $defaultLanguage) {
                $normalized = str_replace('-', '_', $currentLanguage);
                $candidates[] = $fieldId . '_' . $currentLanguage;
                $candidates[] = $fieldId . '_' . $normalized;
                $candidates[] = $fieldId . '_' . str_replace('_', '', $normalized);
            }
        }

        foreach (array_keys($_POST) as $key) {
            if (strpos($key, $fieldId . '_') === 0) {
                $candidates[] = $key;
            }
        }

        foreach ($candidates as $candidate) {
            if (isset($_POST[$candidate])) {
                return $_POST[$candidate];
            }
        }

        return null;
    }
}
