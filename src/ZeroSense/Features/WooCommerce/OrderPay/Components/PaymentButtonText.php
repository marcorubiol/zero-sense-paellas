<?php
namespace ZeroSense\Features\WooCommerce\OrderPay\Components;

class PaymentButtonText
{
    public function __construct()
    {
        add_filter('woocommerce_pay_order_button_text', [$this, 'custom_pay_order_button']);
    }

    /**
     * Change Pay for order button text on the Order Pay page
     */
    public function custom_pay_order_button()
    {
        global $wp;
        $locale = determine_locale();
        
        // Check if we're on the order-pay page and get the order
        if (is_checkout_pay_page() && !empty($wp->query_vars['order-pay'])) {
            $order_id = absint($wp->query_vars['order-pay']);
            $order = wc_get_order($order_id);
            
            if ($order) {
                // Check if this is a deposit-paid order
                $is_deposit_paid = $order->get_status() === 'deposit-paid';
                
                if ($is_deposit_paid) {
                    // Text when deposit is already paid
                    if (strpos($locale, 'ca') === 0) {
                        return 'Completar el pagament';
                    } elseif (strpos($locale, 'es') === 0) {
                        return 'Completar el pago';
                    } else {
                        return 'Complete payment';
                    }
                }
            }
        }
        
        // Default text when no deposit has been paid yet
        if (strpos($locale, 'ca') === 0) {
            return 'Vull formalitzar la reserva';
        } elseif (strpos($locale, 'es') === 0) {
            return 'Quiero formalizar la reserva';
        } else {
            return 'I want to formalize the reservation';
        }
    }

    // Explanatory text removed per user request.
}
