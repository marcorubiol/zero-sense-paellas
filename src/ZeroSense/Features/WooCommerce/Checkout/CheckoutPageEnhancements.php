<?php
namespace ZeroSense\Features\WooCommerce\Checkout;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Features\WooCommerce\Checkout\Components\CheckoutTermsHandler;
use ZeroSense\Features\WooCommerce\Checkout\Components\PaymentInterceptor;
use ZeroSense\Features\WooCommerce\Checkout\Components\PaymentMethodClasses;
use ZeroSense\Features\WooCommerce\Checkout\Components\CheckoutFields;
use ZeroSense\Features\WooCommerce\Checkout\Components\FieldLabels;
use ZeroSense\Features\WooCommerce\Checkout\Components\DateTimePicker;
use ZeroSense\Features\WooCommerce\Checkout\Components\PaymentGateways;
use ZeroSense\Features\WooCommerce\Checkout\Components\TextModifications;
use ZeroSense\Features\WooCommerce\Checkout\Components\MarketingConsent;
use ZeroSense\Features\WooCommerce\Checkout\Components\HiddenItemMeta;
use ZeroSense\Features\WooCommerce\Checkout\Components\ShippingEmail;

class CheckoutPageEnhancements implements FeatureInterface
{
    public function getName(): string
    {
        return __('Checkout Page Enhancements', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Always-on bundle of critical checkout enhancements: payment interception, terms bypass, and dynamic payment method classes.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
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
        if (!class_exists('WooCommerce')) {
            return;
        }

        new CheckoutTermsHandler();
        new PaymentInterceptor();
        new PaymentMethodClasses();
        new CheckoutFields();
        new FieldLabels();
        new DateTimePicker();
        new PaymentGateways();
        new TextModifications();
        new MarketingConsent();
        new ShippingEmail();
        new HiddenItemMeta();

        add_action('wp_enqueue_scripts', [$this, 'enqueueCheckoutScripts']);
    }

    public function enqueueCheckoutScripts(): void
    {
        // Sync localStorage location data to WC session on any WC page
        if (function_exists('is_woocommerce') && (is_woocommerce() || is_cart() || is_checkout())) {
            $ajaxUrl = esc_js(admin_url('admin-ajax.php'));
            $js = '(function(){
                var sa = localStorage.getItem(".location") || "";
                var city = localStorage.getItem("city") || "";
                var fd = new FormData();
                fd.append("action", "zs_set_location_session");
                fd.append("service_area", sa);
                fd.append("city", city);
                fetch("' . $ajaxUrl . '", {method:"POST", body:fd, credentials:"same-origin"});
            })();';
            wp_enqueue_script('jquery');
            wp_add_inline_script('jquery', $js);
        }

        // Force shipping section to always be visible
        if (function_exists('is_checkout') && is_checkout()) {
            $js = '(function(){
                function showShippingSection() {
                    // Show shipping fields section
                    var shippingSection = document.querySelector(".shipping_address");
                    if (shippingSection) {
                        shippingSection.style.display = "block";
                    }
                    
                    // Check and uncheck the "ship to different address" checkbox to trigger display
                    var checkbox = document.querySelector("#ship-to-different-address-checkbox");
                    if (checkbox && !checkbox.checked) {
                        checkbox.checked = true;
                        // Trigger change event
                        var event = new Event("change", { bubbles: true });
                        checkbox.dispatchEvent(event);
                    }
                    
                    // Also try to show via jQuery if available
                    if (window.jQuery) {
                        jQuery(".shipping_address").show();
                        jQuery("#ship-to-different-address-checkbox").prop("checked", true).trigger("change");
                    }
                }
                
                // Run immediately and also after DOM is ready
                if (document.readyState === "loading") {
                    document.addEventListener("DOMContentLoaded", showShippingSection);
                } else {
                    showShippingSection();
                }
                
                // Also run after a short delay to handle any dynamic loading
                setTimeout(showShippingSection, 500);
            })();';
            wp_enqueue_script('jquery');
            wp_add_inline_script('jquery', $js);
        }

        $is_checkout        = function_exists('is_checkout') ? is_checkout() : false;
        $is_order_received  = function_exists('is_wc_endpoint_url') ? is_wc_endpoint_url('order-received') : false;
        $is_checkout_page   = $is_checkout && !$is_order_received;
        $is_order_pay_page  = function_exists('is_wc_endpoint_url') ? is_wc_endpoint_url('order-pay') : false;

        $plugin_root_url  = plugin_dir_url(ZERO_SENSE_FILE);
        $plugin_root_path = plugin_dir_path(ZERO_SENSE_FILE);

        // Enqueue Terms JS only on checkout (not order-pay)
        if ($is_checkout_page && !$is_order_pay_page) {
            $terms_js_rel = 'assets/js/checkout-terms-hide.js';
            $terms_js_ver = file_exists($plugin_root_path . $terms_js_rel)
                ? (string) filemtime($plugin_root_path . $terms_js_rel)
                : ZERO_SENSE_VERSION;
            wp_enqueue_script(
                'zero-sense-checkout-terms-hide',
                $plugin_root_url . $terms_js_rel,
                [],
                $terms_js_ver,
                true
            );

            $guests_js_rel = 'src/ZeroSense/Features/WooCommerce/assets/checkout-guests.js';
            $guests_js_ver = file_exists($plugin_root_path . $guests_js_rel)
                ? (string) filemtime($plugin_root_path . $guests_js_rel)
                : ZERO_SENSE_VERSION;
            wp_enqueue_script(
                'zero-sense-checkout-guests',
                $plugin_root_url . $guests_js_rel,
                [],
                $guests_js_ver,
                true
            );
            wp_localize_script('zero-sense-checkout-guests', 'zsGuests', [
                'msg' => __('The sum of adults and children must match the total number of guests.', 'zero-sense'),
            ]);
        }
    }

    public function hasInformation(): bool
    {
        return true;
    }

    public function getInformationBlocks(): array
    {
        return [
            [
                'type' => 'list',
                'title' => __('Payment Interception', 'zero-sense'),
                'items' => [
                    __('Intercepts all checkout payments.', 'zero-sense'),
                    __('Sets order status to "budget-requested".', 'zero-sense'),
                    __('Redirects to a custom thank-you page.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Terms & Conditions', 'zero-sense'),
                'items' => [
                    __('Checkbox is hidden and auto-accepted.', 'zero-sense'),
                    __('Privacy policy text remains visible.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Payment Method Classes', 'zero-sense'),
                'items' => [
                    __('Adds dynamic CSS classes (e.g., `flm-action-bacs`) to payment buttons.', 'zero-sense'),
                    __('Enables Flowmattic integration.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Checkout Fields', 'zero-sense'),
                'items' => [
                    __('Makes last name field optional (not required).', 'zero-sense'),
                    __('Removes company and address line 2 fields.', 'zero-sense'),
                    __('Displays Meta Box custom fields automatically.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Field Labels & Messages', 'zero-sense'),
                'items' => [
                    __('Removes "Billing" prefix from error messages.', 'zero-sense'),
                    __('Supports Spanish, Catalan, and English.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Date/Time Picker', 'zero-sense'),
                'items' => [
                    __('Loads Flatpickr for date fields in checkout.', 'zero-sense'),
                    __('Works on both checkout and order-pay pages.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Payment Gateways', 'zero-sense'),
                'items' => [
                    __('Shows only "Pay Later" gateway on checkout.', 'zero-sense'),
                    __('Hides "Pay Later" on order-pay pages.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Text Modifications', 'zero-sense'),
                'items' => [
                    __('Changes "Billing details" to "Contact details".', 'zero-sense'),
                    __('Changes "Place order" button to "Get Quote".', 'zero-sense'),
                    __('Supports Spanish, Catalan, and English.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Marketing Consent', 'zero-sense'),
                'items' => [
                    __('Adds marketing consent checkbox to checkout.', 'zero-sense'),
                    __('Saves consent to MetaBox field and order notes.', 'zero-sense'),
                    __('Optional checkbox with custom Spanish text.', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Entry: src/ZeroSense/Features/WooCommerce/Checkout/CheckoutPageEnhancements.php', 'zero-sense'),
                    __('Payment: .../Components/PaymentInterceptor.php (interceptBlocksCheckout, interceptClassicCheckout, processOrderInterception)', 'zero-sense'),
                    __('Terms: .../Components/CheckoutTermsHandler.php (force_accept_terms, maybe_hide_terms)', 'zero-sense'),
                    __('Payment classes: .../Components/PaymentMethodClasses.php (enqueuePaymentMethodScripts) + assets JS/CSS', 'zero-sense'),
                    __('Fields & Meta Box: .../Components/CheckoutFields.php (customize_checkout_fields, display/validate/save Meta Box fields)', 'zero-sense'),
                    __('Field errors: .../Components/FieldLabels.php (customize_field_errors)', 'zero-sense'),
                    __('Date/Time: .../Components/DateTimePicker.php (enqueue_datepicker_assets)', 'zero-sense'),
                    __('Gateways: .../Components/PaymentGateways.php (filter_checkout_payment_gateways)', 'zero-sense'),
                    __('Texts: .../Components/TextModifications.php (custom_checkout_fields, custom_place_order_button, targeted_string_replacements)', 'zero-sense'),
                    __('Marketing: .../Components/MarketingConsent.php (maybeAddCheckoutMarketing, add_consent_checkbox, save_consent_to_order)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('woocommerce_rest_checkout_process_payment_with_context, woocommerce_store_api_checkout_order_processed, woocommerce_payment_successful_result, woocommerce_checkout_order_processed', 'zero-sense'),
                    __('woocommerce_checkout_process, woocommerce_checkout_show_terms', 'zero-sense'),
                    __('woocommerce_checkout_fields, woocommerce_after_checkout_billing_form, woocommerce_checkout_update_order_meta, init', 'zero-sense'),
                    __('woocommerce_checkout_required_field_notice', 'zero-sense'),
                    __('wp_enqueue_scripts (assets)', 'zero-sense'),
                    __('woocommerce_available_payment_gateways', 'zero-sense'),
                    __('woocommerce_order_button_text, gettext', 'zero-sense'),
                    __('template_redirect, woocommerce_form_field (filter), woocommerce_review_order_before_submit', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Place an order: status becomes budget-requested and redirects to localized URL.', 'zero-sense'),
                    __('Terms: hidden but accepted; privacy text visible.', 'zero-sense'),
                    __('Buttons: verify flm-action-* classes by gateway.', 'zero-sense'),
                    __('Fields: last name optional; company and address_2 removed.', 'zero-sense'),
                    __('Meta Box: required fields produce Woo error; values saved on order.', 'zero-sense'),
                    __('Order-pay: Pay Later hidden when order is pending; checkout shows only Pay Later.', 'zero-sense'),
                    __('Texts: labels and button strings changed per locale (ES/CA/EN).', 'zero-sense'),
                    __('Marketing: checkbox saves to metabox key and adds order note.', 'zero-sense'),
                ],
            ],
        ];
    }
}
