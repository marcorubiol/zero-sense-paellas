<?php
/**
 * WooCommerce Checkout Meta Boxes
 *
 * Display, validate, and save custom meta box fields in WooCommerce checkout.
 */

if (!defined('ABSPATH')) exit; // Prevent direct access

/**
 * Display meta box fields on checkout page
 */
function zs_checkout_display_meta_box_fields($checkout)
{
    $meta_boxes = apply_filters("rwmb_meta_boxes", []);

    // Filter meta boxes for 'shop_order' post type.
    $order_meta_boxes = array_filter($meta_boxes, function ($meta_box) {
        return
            isset($meta_box["post_types"]) &&
            in_array("shop_order", $meta_box["post_types"]);
    });

    if (empty($order_meta_boxes)) {
        return; // Exit if no relevant meta boxes are found.
    }

    foreach ($order_meta_boxes as $meta_box) {
        $metabox_title =
            isset($meta_box["title"])
                ? esc_html($meta_box["title"])
                : esc_html__("Information", "woocommerce");

        // Generate a unique, sanitized HTML class for the meta box container.
        $container_id = sanitize_html_class(
            strtolower(str_replace(" ", "-", remove_accents($metabox_title)))
        );

        echo '<h3 id="' .$container_id .'-title">' . $metabox_title . '</h3>';
        echo '<div id="' .
            $container_id .
            '" class="woocommerce-billing-fields__field-wrapper">';

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
                            $field_options = [
                                "" => __("-- Select an option --", "woocommerce"),
                            ];
                            if (isset($field["options"])) {
                                $field_options = array_merge(
                                    $field_options,
                                    $field["options"]
                                );
                            }
                            break;

                        case "taxonomy":
                        case "taxonomy_advanced":
                            $wc_field_type = "select";
                            $taxonomy = isset($field["taxonomy"])
                                ? $field["taxonomy"]
                                : "";

                            if (!empty($taxonomy)) {
                                $terms = get_terms([
                                    "taxonomy" => $taxonomy,
                                    "hide_empty" => false,
                                ]);

                                if (
                                    !is_wp_error($terms) &&
                                    !empty($terms)
                                ) {
                                    $field_options = [
                                        "" => __("-- Select an option --", "woocommerce"),
                                    ];
                                    foreach ($terms as $term) {
                                        $field_options[$term->term_id] =
                                            $term->name;
                                    }
                                }
                            }

                            if (!empty($field["multiple"])) {
                                $wc_field_type = "multiselect";
                            }
                            break;

                        default:
                            $wc_field_type = $field["type"];
                            if (
                                isset($field["options"]) &&
                                is_array($field["options"])
                            ) {
                                $field_options = $field["options"];
                            }
                            break;
                    }
                }

                // Generate the field using WooCommerce's form field function.
                woocommerce_form_field(
                    $field["id"],
                    [
                        "type" => $wc_field_type,
                        "label" => isset($field["name"]) ? $field["name"] : "",
                        "required" => !empty($field["required"]),
                        "default" => "",
                        "placeholder" =>
                            isset($field["placeholder"])
                                ? $field["placeholder"]
                                : (in_array($wc_field_type, [
                                    "select",
                                    "taxonomy",
                                ])
                                    ? __("-- Select an option --", "woocommerce")
                                    : ""),
                        "options" => $field_options,
                        "class" => ["form-row-wide"],
                        "custom_attributes" => !empty($field["required"])
                            ? ["required" => "required"]
                            : [],
                        "input_class" => ["form-row-wide"],
                    ],
                    ""
                );
            }
        }

        echo "</div>"; // Close the meta box container.
    }
}
add_action(
    "woocommerce_after_checkout_billing_form",
    "zs_checkout_display_meta_box_fields"
);

/**
 * Validates required meta box fields during the WooCommerce checkout process.
 *
 * This function checks if any of the registered meta box fields (specifically those marked as 'required')
 * have been left empty by the user during checkout. If a required field is empty, an error notice is added
 * to the WooCommerce checkout, and a filter is applied to add an 'invalid' class to the form field for
 * visual feedback.
 */
function zs_checkout_validate_meta_box_fields()
{
    $meta_boxes = apply_filters("rwmb_meta_boxes", []);

    foreach ($meta_boxes as $meta_box) {
        if (
            !isset($meta_box["post_types"]) ||
            !in_array("shop_order", $meta_box["post_types"])
        ) {
            continue;
        }

        if (!empty($meta_box["fields"])) {
            foreach ($meta_box["fields"] as $field) {
                if (!empty($field["required"])) {
                    $field_value = isset($_POST[$field["id"]])
                        ? sanitize_text_field($_POST[$field["id"]])
                        : "";

                    if (empty($field_value) || $field_value === "0") {
                        $field_name = isset($field["name"])
                            ? $field["name"]
                            : $field["id"];
                        wc_add_notice(
                            sprintf(
                                __("%s is a required field.", "woocommerce"),
                                "<strong>" . esc_html($field_name) . "</strong>"
                            ),
                            "error"
                        );

                        // Add filter to append invalid class to the field.
                        add_filter(
                            "woocommerce_form_field_" . $field["id"],
                            function ($field_html) {
                                // Use DOMDocument to safely modify the HTML
                                $dom = new DOMDocument();
                                libxml_use_internal_errors(true); // Suppress warnings
                                $dom->loadHTML(
                                    mb_convert_encoding(
                                        $field_html,
                                        "HTML-ENTITIES",
                                        "UTF-8"
                                    )
                                ); // Handle UTF-8 characters
                                libxml_clear_errors();

                                $xpath = new DOMXPath($dom);

                                // Find the container element (e.g., <p class="form-row">)
                                $container = $xpath->query("//*[contains(@class, 'form-row')]")->item(0);

                                if ($container) {
                                    $classes = $container->getAttribute('class');
                                    // Add the woocommerce-invalid and woocommerce-invalid-required-field classes
                                    $new_classes = trim($classes . ' woocommerce-invalid woocommerce-invalid-required-field');
                                    $container->setAttribute('class', $new_classes);

                                    // Output the modified HTML
                                    return $dom->saveHTML($container);
                                } else {
                                    return $field_html; // Return original if container not found
                                }
                            }
                        );
                    }
                }
            }
        }
    }
}
add_action("woocommerce_checkout_process", "zs_checkout_validate_meta_box_fields");

/**
 * Saves meta box field values when the WooCommerce checkout form is submitted and the order is being created.
 *
 * This function iterates through the registered meta boxes and their fields, saving the submitted values as
 * post meta data associated with the newly created WooCommerce order. It handles different field types,
 * including text fields, textareas, select fields, and taxonomy-based fields.
 *
 * @param int $order_id The ID of the WooCommerce order being created.
 */
function zs_checkout_save_meta_box_fields($order_id)
{
    if (!$order_id) {
        return;
    }

    $meta_boxes = apply_filters("rwmb_meta_boxes", []);

    foreach ($meta_boxes as $meta_box) {
        if (
            !isset($meta_box["post_types"]) ||
            !in_array("shop_order", $meta_box["post_types"])
        ) {
            continue;
        }

        if (!empty($meta_box["fields"])) {
            foreach ($meta_box["fields"] as $field) {
                if (!isset($field["id"])) {
                    continue;
                }

                if (isset($_POST[$field["id"]])) {
                    $field_value = $_POST[$field["id"]];

                    if (
                        isset($field["type"]) &&
                        in_array($field["type"], [
                            "taxonomy_advanced",
                            "taxonomy",
                        ]) &&
                        isset($field["taxonomy"])
                    ) {
                        $taxonomy = $field["taxonomy"];
                        $term_ids = is_array($field_value)
                            ? array_map("sanitize_text_field", $field_value)
                            : [sanitize_text_field($field_value)];
                        $term_ids = array_filter($term_ids);

                        if (!empty($term_ids)) {
                            wp_set_object_terms(
                                $order_id,
                                $term_ids,
                                $taxonomy
                            );
                            update_post_meta(
                                $order_id,
                                $field["id"],
                                $term_ids
                            );
                        }
                    } elseif (is_array($field_value)) {
                        update_post_meta(
                            $order_id,
                            $field["id"],
                            array_map("sanitize_text_field", $field_value)
                        );
                    } elseif (
                        isset($field["type"]) &&
                        $field["type"] === "textarea"
                    ) {
                        update_post_meta(
                            $order_id,
                            $field["id"],
                            sanitize_textarea_field($field_value)
                        );
                    } else {
                        update_post_meta(
                            $order_id,
                            $field["id"],
                            sanitize_text_field($field_value)
                        );
                    }
                }
            }
        }
    }
}
add_action(
    "woocommerce_checkout_update_order_meta",
    "zs_checkout_save_meta_box_fields"
);

/**
 * Register translation strings with WPML if available
 */
function zs_register_checkout_strings_for_translation() {
    if (function_exists('icl_register_string')) {
        icl_register_string('woocommerce', 'checkout_select_option', '-- Select an option --');
    }
}
add_action('init', 'zs_register_checkout_strings_for_translation'); 