<?php
namespace ZeroSense\Features\WooCommerce\Checkout\Components;

class PaymentMethodClasses
{
    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueuePaymentMethodScripts']);
    }

    public function enqueuePaymentMethodScripts(): void
    {
        $is_checkout      = function_exists('is_checkout') ? is_checkout() : false;
        $is_order_pay     = function_exists('is_wc_endpoint_url') ? is_wc_endpoint_url('order-pay') : false;
        $is_add_method    = function_exists('is_add_payment_method_page') ? is_add_payment_method_page() : false;

        if ($is_checkout || $is_order_pay || $is_add_method) {
            $gatewayIds = [
                'bacs',
                'stripe',
                'paypal',
                'cod',
                'pay_later',
            ];

            if (class_exists('\\ZeroSense\\Features\\WooCommerce\\Gateways\\RedsysStandard')) {
                $gatewayIds[] = \ZeroSense\Features\WooCommerce\Gateways\RedsysStandard::GATEWAY_ID;
            }

            if (class_exists('\\ZeroSense\\Features\\WooCommerce\\Gateways\\RedsysBizum')) {
                $gatewayIds[] = \ZeroSense\Features\WooCommerce\Gateways\RedsysBizum::GATEWAY_ID;
            }

            if (function_exists('WC')) {
                $wc = WC();
                if ($wc && method_exists($wc, 'payment_gateways')) {
                    $gateways = $wc->payment_gateways();

                    if ($gateways) {
                        if (method_exists($gateways, 'payment_gateways')) {
                            $registered = array_keys((array) $gateways->payment_gateways());
                            $gatewayIds = array_merge($gatewayIds, $registered);
                        }

                        if (method_exists($gateways, 'get_available_payment_gateways')) {
                            $available = array_keys((array) $gateways->get_available_payment_gateways());
                            $gatewayIds = array_merge($gatewayIds, $available);
                        }
                    }
                }
            }

            $gatewayIds = array_unique(array_filter($gatewayIds, 'strlen'));

            $classMap = [];

            foreach ($gatewayIds as $gatewayId) {
                $sanitized = sanitize_title_with_dashes($gatewayId);
                if ($sanitized === '') {
                    continue;
                }

                $classMap[$gatewayId] = 'flm-action-' . $sanitized;
            }

            $root_url  = plugin_dir_url(ZERO_SENSE_FILE);
            $root_path = plugin_dir_path(ZERO_SENSE_FILE);

            // JS (kept jQuery dep for stability; can be refactored to vanilla later)
            $js_rel = 'src/ZeroSense/Features/WooCommerce/assets/payment-method-classes.js';
            $js_ver = file_exists($root_path . $js_rel) ? (string) filemtime($root_path . $js_rel) : ZERO_SENSE_VERSION;
            wp_enqueue_script('zs-payment-method-classes', $root_url . $js_rel, ['jquery'], $js_ver, true);
            wp_localize_script('zs-payment-method-classes', 'zsPaymentClasses', ['classes' => $classMap]);

            // CSS (moved from CheckoutPageEnhancements)
            $css_rel = 'src/ZeroSense/Features/WooCommerce/Checkout/Assets/payment-methods.css';
            $css_ver = file_exists($root_path . $css_rel) ? (string) filemtime($root_path . $css_rel) : ZERO_SENSE_VERSION;
            wp_enqueue_style('zero-sense-payment-methods', $root_url . $css_rel, [], $css_ver);
        }
    }
}
