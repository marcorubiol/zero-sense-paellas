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

        // WPML compatibility fixes
        add_action('init', [$this, 'register_checkout_strings_for_translation']);
        $this->prevent_wpml_array_processing();

        // Preserve and translate service area filter across languages
        add_filter('icl_ls_languages', [$this, 'filterLanguageSwitcherUrls']);
    }

    public function customize_checkout_fields($fields)
    {
        // 1. Make last name not required
        if (isset($fields['billing']['billing_last_name'])) {
            $fields['billing']['billing_last_name']['required'] = false;
        }

        // 2. Unset unnecessary fields
        unset($fields['billing']['billing_company']);
        unset($fields['billing']['billing_address_2']);

        return $fields;
    }

    /**
     * Render event fields on checkout page
     */
    public function display_event_fields($checkout): void
    {
        $selectPlaceholder = __('-- Selecciona una opción --', 'zero-sense');

        echo '<h3>' . esc_html__('Sobre el evento', 'zero-sense') . '</h3>';
        echo '<div class="woocommerce-billing-fields__field-wrapper">';

        // Total guests + Adults (two columns)
        woocommerce_form_field(MetaKeys::TOTAL_GUESTS, [
            'type'             => 'number',
            'label'            => __('Número total de comensales', 'zero-sense'),
            'required'         => true,
            'class'            => ['form-row-first'],
            'custom_attributes' => ['min' => '1', 'required' => 'required'],
        ], '');

        woocommerce_form_field(MetaKeys::ADULTS, [
            'type'             => 'number',
            'label'            => __('Adultos', 'zero-sense'),
            'required'         => true,
            'class'            => ['form-row-last'],
            'custom_attributes' => ['min' => '0', 'required' => 'required'],
        ], '');

        // Children 5-8 + Children 0-4
        woocommerce_form_field(MetaKeys::CHILDREN_5_TO_8, [
            'type'  => 'number',
            'label' => __('Niños de 5 a 8 años (40% de descuento)', 'zero-sense'),
            'class' => ['form-row-first'],
            'custom_attributes' => ['min' => '0'],
        ], '');

        woocommerce_form_field(MetaKeys::CHILDREN_0_TO_4, [
            'type'  => 'number',
            'label' => __('Niños de 0 a 4 años (GRATIS)', 'zero-sense'),
            'class' => ['form-row-last'],
            'custom_attributes' => ['min' => '0'],
        ], '');

        // Address + Location link
        $addressDefault = $this->getUrlPrefill('address', '');
        woocommerce_form_field('event_address_checkout', [
            'type'     => 'text',
            'label'    => __('Dirección del evento', 'zero-sense'),
            'required' => true,
            'class'    => ['form-row-first'],
            'custom_attributes' => ['required' => 'required'],
            'default'  => $addressDefault,
        ], '');

        woocommerce_form_field('event_location_link_checkout', [
            'type'  => 'text',
            'label' => __('Enlace de la ubicación (si lo tienes disponible)', 'zero-sense'),
            'class' => ['form-row-last'],
        ], '');

        // Event date + Serving time
        woocommerce_form_field(MetaKeys::EVENT_DATE, [
            'type'     => 'text',
            'label'    => __('Fecha del evento', 'zero-sense'),
            'required' => true,
            'class'    => ['form-row-first', 'zs-datepicker'],
            'custom_attributes' => ['required' => 'required', 'autocomplete' => 'off'],
        ], '');

        woocommerce_form_field(MetaKeys::SERVING_TIME, [
            'type'     => 'text',
            'label'    => __('Hora de servir la paella (aprox)', 'zero-sense'),
            'required' => true,
            'class'    => ['form-row-last', 'zs-timepicker'],
            'custom_attributes' => ['required' => 'required', 'autocomplete' => 'off'],
            'default'  => '18:00',
        ], '');

        // Start time + Event type
        woocommerce_form_field(MetaKeys::START_TIME, [
            'type'    => 'text',
            'label'   => __('Hora del inicio del evento', 'zero-sense'),
            'class'   => ['form-row-first', 'zs-timepicker'],
            'custom_attributes' => ['autocomplete' => 'off'],
            'default' => '18:00',
        ], '');

        $eventTypeOptions = array_merge(['' => $selectPlaceholder], FieldOptions::getEventTypeOptions());
        woocommerce_form_field(MetaKeys::EVENT_TYPE, [
            'type'     => 'select',
            'label'    => __('Tipo de evento', 'zero-sense'),
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
        echo '<h3>' . esc_html__('Intolerancias', 'zero-sense') . '</h3>';
        $intolerancesId = esc_attr(MetaKeys::INTOLERANCES);
        $intolerancesLabel = esc_html__('¿Debemos tener en cuenta alguna alergia o intolerancia alimentaria destacable?', 'zero-sense');
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

        // Validate guest sum: adults + children_5_to_8 + children_0_to_4 = total_guests
        $total  = absint($this->getSubmittedFieldValue(MetaKeys::TOTAL_GUESTS) ?? 0);
        $adults = absint($this->getSubmittedFieldValue(MetaKeys::ADULTS) ?? 0);
        $ch58   = absint($this->getSubmittedFieldValue(MetaKeys::CHILDREN_5_TO_8) ?? 0);
        $ch04   = absint($this->getSubmittedFieldValue(MetaKeys::CHILDREN_0_TO_4) ?? 0);

        if ($total > 0 && ($adults + $ch58 + $ch04) !== $total) {
            wc_add_notice(
                __('The sum of adults and children must match the total number of guests.', 'zero-sense'),
                'error'
            );
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
