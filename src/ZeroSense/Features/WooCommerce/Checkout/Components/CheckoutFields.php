<?php
namespace ZeroSense\Features\WooCommerce\Checkout\Components;

use WC_Order;

class CheckoutFields
{
    public function __construct()
    {
        // Basic field customizations
        add_filter('woocommerce_checkout_fields', [$this, 'customize_checkout_fields']);

        // Meta Box integration (like legacy plugin)
        add_action('woocommerce_after_checkout_billing_form', [$this, 'display_meta_box_fields']);
        add_action('woocommerce_checkout_process', [$this, 'validate_meta_box_fields']);
        add_action('woocommerce_checkout_create_order', [$this, 'save_meta_box_fields'], 20, 2);

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
     * Display meta box fields on checkout page (from legacy implementation)
     */
    public function display_meta_box_fields($checkout)
    {
        $meta_boxes = apply_filters("rwmb_meta_boxes", []);

        // Filter meta boxes for 'shop_order' post type.
        $order_meta_boxes = array_filter($meta_boxes, function ($meta_box) {
            return isset($meta_box["post_types"]) && in_array("shop_order", $meta_box["post_types"]);
        });

        if (empty($order_meta_boxes)) {
            return; // Exit if no relevant meta boxes are found.
        }

        foreach ($order_meta_boxes as $meta_box) {
            $metabox_title = isset($meta_box["title"]) ? esc_html($meta_box["title"]) : esc_html__("Information", "woocommerce");

            // Generate a unique, sanitized HTML class for the meta box container.
            $container_id = sanitize_html_class(strtolower(str_replace(" ", "-", remove_accents($metabox_title))));

            echo '<h3 id="' . $container_id . '-title">' . $metabox_title . '</h3>';
            echo '<div id="' . $container_id . '" class="woocommerce-billing-fields__field-wrapper">';

            if (!empty($meta_box["fields"])) {
                foreach ($meta_box["fields"] as $field) {
                    if (!isset($field["id"])) {
                        continue; // Skip fields without an ID.
                    }

                    $wc_field_type = "text"; // Default field type.
                    $field_options = [];

                    if (isset($field["type"])) {
                        switch ($field["type"]) {
                            case "select":
                                $wc_field_type = "select";
                                $field_options = ["" => __("-- Select an option --", "woocommerce")];
                                if (isset($field["options"])) {
                                    $field_options = array_merge($field_options, $field["options"]);
                                }
                                break;

                            case "taxonomy":
                            case "taxonomy_advanced":
                                $wc_field_type = "select";
                                $taxonomy = isset($field["taxonomy"]) ? $field["taxonomy"] : "";

                                if (!empty($taxonomy)) {
                                    $terms = get_terms(["taxonomy" => $taxonomy, "hide_empty" => false]);

                                    if (!is_wp_error($terms) && !empty($terms)) {
                                        $field_options = ["" => __("-- Select an option --", "woocommerce")];
                                        foreach ($terms as $term) {
                                            $field_options[$term->term_id] = $term->name;
                                        }
                                    }
                                }

                                if (!empty($field["multiple"])) {
                                    $wc_field_type = "multiselect";
                                }
                                break;

                            default:
                                $wc_field_type = $field["type"];
                                if (isset($field["options"]) && is_array($field["options"])) {
                                    $field_options = $field["options"];
                                }
                                break;
                        }
                    }

                    // Prefill defaults from URL when available
                    $prefillDefault = $this->getPrefillDefault($field, $wc_field_type);

                    // Generate the field using WooCommerce's form field function.
                    woocommerce_form_field(
                        $field["id"],
                        [
                            "type" => $wc_field_type,
                            "label" => isset($field["name"]) ? $field["name"] : "",
                            "required" => !empty($field["required"]),
                            "default" => $prefillDefault !== null ? $prefillDefault : "",
                            "placeholder" => isset($field["placeholder"]) ? $field["placeholder"] :
                                (in_array($wc_field_type, ["select", "taxonomy"]) ? __("-- Select an option --", "woocommerce") : ""),
                            "options" => $field_options,
                            "class" => ["form-row-wide"],
                            "custom_attributes" => !empty($field["required"]) ? ["required" => "required"] : [],
                            "input_class" => ["form-row-wide"],
                        ],
                        ""
                    );
                }
            }

            echo "</div>"; // Close the meta box container.
        }
    }

    /**
     * Validate meta box fields (from legacy implementation)
     */
    public function validate_meta_box_fields()
    {
        $meta_boxes = apply_filters("rwmb_meta_boxes", []);

        foreach ($meta_boxes as $meta_box) {
            if (!isset($meta_box["post_types"]) || !in_array("shop_order", $meta_box["post_types"])) {
                continue;
            }

            if (!empty($meta_box["fields"])) {
                foreach ($meta_box["fields"] as $field) {
                    if (!empty($field["required"])) {
                        $field_value = isset($_POST[$field["id"]]) ? sanitize_text_field($_POST[$field["id"]]) : "";

                        if (empty($field_value) || $field_value === "0") {
                            $field_name = isset($field["name"]) ? $field["name"] : $field["id"];
                            wc_add_notice(
                                sprintf(__("%s is a required field.", "woocommerce"), "<strong>" . esc_html($field_name) . "</strong>"),
                                "error"
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * Save meta box fields (from legacy implementation)
     */
    public function save_meta_box_fields(WC_Order $order, $data): void
    {
        $order_id = (int) $order->get_id();
        if ($order_id <= 0) {
            return;
        }

        $meta_boxes = apply_filters("rwmb_meta_boxes", []);

        foreach ($meta_boxes as $meta_box) {
            if (!isset($meta_box["post_types"]) || !in_array("shop_order", $meta_box["post_types"])) {
                continue;
            }

            if (!empty($meta_box["fields"])) {
                foreach ($meta_box["fields"] as $field) {
                    if (!isset($field["id"])) {
                        continue;
                    }

                    $field_value = $this->getSubmittedFieldValue($field['id']);

                    // Fix: Normalize Service Area ID to default language (Spanish)
                    // This creates compatibility with MetaBox backend which expects default-language IDs
                    if ($field['id'] === 'event_service_location' && !empty($field_value)) {
                        $default_lang = apply_filters('wpml_default_language', null);
                        if ($default_lang) {
                            // Use field taxonomy if defined, otherwise fallback to 'service-area' (hyphen) as confirmed by user
                            $taxonomy = isset($field['taxonomy']) && !empty($field['taxonomy']) ? $field['taxonomy'] : 'service-area';

                            // Robustness: Ensure taxonomy is a string (MetaBox sometimes returns an array)
                            if (is_array($taxonomy)) {
                                $taxonomy = reset($taxonomy);
                            }

                            $translated_id = apply_filters('wpml_object_id', $field_value, $taxonomy, true, $default_lang);
                            if ($translated_id) {
                                $field_value = $translated_id;
                            }
                        }
                    }

                    if ($field_value === null) {
                        continue;
                    }

                    if (isset($field["type"]) && $field["type"] === 'date') {
                        $date = \DateTime::createFromFormat('d/m/Y', $field_value);
                        if ($date) {
                            $order->update_meta_data($field['id'], $date->getTimestamp());
                        } else {
                            $fallback_date = strtotime(is_string($field_value) ? $field_value : '');
                            if ($fallback_date !== false) {
                                $order->update_meta_data($field['id'], $fallback_date);
                            } else {
                                $order->update_meta_data($field['id'], 0);
                            }
                        }
                    } else {
                        if (is_array($field_value)) {
                            $field_value = implode(',', array_map('sanitize_text_field', $field_value));
                        } else {
                            $field_value = sanitize_text_field((string) $field_value);
                        }
                        $order->update_meta_data($field['id'], $field_value);
                    }
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

    private function getPrefillDefault(array $field, string $wcFieldType)
    {
        if (!empty($_POST)) {
            return null;
        }

        $fieldId = isset($field['id']) ? $field['id'] : '';

        if ($fieldId === 'event_service_location' && isset($_GET['b_service-area'])) {
            $raw = (int) $_GET['b_service-area'];
            if ($wcFieldType === 'select' || $wcFieldType === 'multiselect') {
                $taxonomy = isset($field['taxonomy']) ? $field['taxonomy'] : null;
                if (!$taxonomy) {
                    $term = get_term($raw);
                    $taxonomy = (!is_wp_error($term) && $term && isset($term->taxonomy)) ? $term->taxonomy : 'service_area';
                }
                $currentLang = apply_filters('wpml_current_language', null);
                $translated = apply_filters('wpml_object_id', $raw, $taxonomy, true, is_string($currentLang) ? $currentLang : null);
                return $translated ?: $raw;
            }
            return $raw;
        }

        if ($fieldId === 'event_city' && isset($_GET['city'])) {
            return sanitize_text_field((string) $_GET['city']);
        }

        return null;
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
