<?php
namespace ZeroSense\Features\WooCommerce\OrderPay\Components;

use WC_Order;

class MarketingConsent
{
    const CANONICAL_CONSENT_KEY = 'zs_marketing_consent';
    const FORM_CONSENT_KEY = 'marketing_consent';

    public function __construct()
    {
        // Only apply on order-pay pages
        add_action('template_redirect', [$this, 'maybeAddOrderPayMarketing'], 1);
    }

    public function maybeAddOrderPayMarketing(): void
    {
        if (!$this->isOrderPayPage()) {
            return;
        }

        // Remove (optional) text from marketing consent field
        add_filter('woocommerce_form_field', [$this, 'remove_optional_label'], 10, 4);
        
        // Add marketing consent checkbox to order-pay
        // Classic Woo template hook
        add_action('woocommerce_review_order_before_submit', [$this, 'add_consent_checkbox'], 9);
        // Bricks form-pay template hook
        add_action('woocommerce_pay_order_before_submit', [$this, 'add_consent_checkbox'], 9);
        
        // Save checkbox value and add order notes
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_consent_to_order'], 10, 1);
    }

    /**
     * Remove (optional) text from the marketing consent field
     */
    public function remove_optional_label($field, $key, $args, $value)
    {
        if ($key === self::FORM_CONSENT_KEY) {
            $field = str_replace([' (opcional)', ' (optional)'], '', $field);
        }
        return $field;
    }

    /**
     * Add marketing consent checkbox to order-pay
     */
    public function add_consent_checkbox()
    {
        $checkbox_label = __('Quiero recibir información y ofertas especiales (tranquilos, ¡nosotros también odiamos el spam!)', 'zero-sense');
        
        woocommerce_form_field(self::FORM_CONSENT_KEY, [
            'type'          => 'checkbox',
            'class'         => ['form-row', 'privacy'],
            'label_class'   => ['woocommerce-form__label', 'woocommerce-form__label-for-checkbox', 'checkbox'],
            'input_class'   => ['woocommerce-form__input', 'woocommerce-form__input-checkbox', 'input-checkbox'],
            'required'      => false,
            'label'         => $checkbox_label,
        ]);
    }

    /**
     * Save checkbox value to order meta and add order notes
     */
    public function save_consent_to_order($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }

        $marketing_consent = isset($_POST[self::FORM_CONSENT_KEY]) ? 1 : 0;

        $order->update_meta_data(self::CANONICAL_CONSENT_KEY, $marketing_consent);
        $order->save();

        $consent_text = $marketing_consent ? __('yes', 'zero-sense') : __('no', 'zero-sense');
        $order->add_order_note(sprintf(__('Marketing Consent: %s', 'zero-sense'), $consent_text));
    }

    private function isOrderPayPage(): bool
    {
        return (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) ||
               (isset($_GET['pay_for_order'], $_GET['key']));
    }
}
