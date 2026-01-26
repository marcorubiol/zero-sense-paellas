<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Integrations;

use WC_Order;

/**
 * Handles offline payment methods (BACS, COD, Cheque) to preserve deposit-paid status.
 * 
 * When a customer pays with offline methods on order-pay page while in deposit-paid status,
 * we preserve the status for manual review instead of auto-changing to on-hold.
 */
class OfflinePaymentHandler
{
    public function register(): void
    {
        // Intercept BACS payment status
        add_filter('woocommerce_bacs_process_payment_order_status', [$this, 'preserveDepositStatus'], 10, 2);
        
        // Intercept COD payment status (if enabled)
        add_filter('woocommerce_cod_process_payment_order_status', [$this, 'preserveDepositStatus'], 10, 2);
        
        // Intercept Cheque payment status (if enabled)
        add_filter('woocommerce_cheque_process_payment_order_status', [$this, 'preserveDepositStatus'], 10, 2);
        
        // Add note when offline payment is selected for deposit-paid orders
        add_action('woocommerce_payment_complete_order_status', [$this, 'addOfflinePaymentNote'], 10, 3);
    }

    /**
     * Preserve deposit-paid status for manual review when using offline payment methods.
     * 
     * @param string $status The default status the gateway would set (usually 'on-hold')
     * @param WC_Order $order The order object
     * @return string The status to use (preserves 'deposit-paid' for manual review)
     */
    public function preserveDepositStatus(string $status, $order): string
    {
        if (!$order instanceof WC_Order) {
            return $status;
        }

        // If order is in deposit-paid status, preserve it for manual review
        if ($order->has_status('deposit-paid')) {
            // Add note about pending offline payment
            $paymentMethod = $order->get_payment_method();
            $methodTitle = $order->get_payment_method_title();
            
            $order->add_order_note(
                sprintf(
                    __('Second payment via %s selected. Awaiting manual confirmation.', 'zero-sense'),
                    $methodTitle ?: $paymentMethod
                )
            );
            
            // Set transient for BACS redirect (used by BacsRedirects component)
            if ($paymentMethod === 'bacs') {
                set_transient('zs_bacs_2nd_pay_processed_' . $order->get_id(), 'yes', 60);
            }
            
            return 'deposit-paid';
        }

        // Allow default behavior for other statuses
        return $status;
    }

    /**
     * Add note when offline payment method is selected (for logging purposes).
     */
    public function addOfflinePaymentNote($status, int $orderId, WC_Order $order): string
    {
        return $status;
    }
}
