<?php
namespace ZeroSense\Features\WooCommerce\Checkout\Components;

use WC_Order;

class MarketingConsent
{
    const CANONICAL_CONSENT_KEY = 'zs_marketing_consent';
    const FORM_CONSENT_KEY = 'marketing_consent';

    public function __construct()
    {
        // Only apply on checkout pages, not order-pay
        add_action('template_redirect', [$this, 'maybeAddCheckoutMarketing'], 1);
    }

    public function maybeAddCheckoutMarketing(): void
    {
        if (!$this->isCheckoutPage()) {
            return;
        }

        // Remove (optional) text from marketing consent field
        add_filter('woocommerce_form_field', [$this, 'remove_optional_label'], 10, 4);
        
        // Add marketing consent checkbox to checkout
        add_action('woocommerce_review_order_before_submit', [$this, 'add_consent_checkbox'], 9);
        
        // Save checkbox value and add order notes
        add_action('woocommerce_checkout_create_order', [$this, 'save_consent_to_order'], 20, 2);
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
     * Add marketing consent checkbox to checkout
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
    public function save_consent_to_order(WC_Order $order, $data): void
    {
        $marketing_consent = isset($_POST[self::FORM_CONSENT_KEY]) ? 1 : 0;

        $order->update_meta_data(self::CANONICAL_CONSENT_KEY, $marketing_consent);

        $consent_text = $marketing_consent ? __('yes', 'zero-sense') : __('no', 'zero-sense');
        $order->add_order_note(sprintf(__('Marketing Consent: %s', 'zero-sense'), $consent_text));
    }

    private function isCheckoutPage(): bool
    {
        return is_checkout() && 
               !is_wc_endpoint_url('order-received') && 
               !is_wc_endpoint_url('order-pay') &&
               !isset($_GET['pay_for_order']);
    }
}
