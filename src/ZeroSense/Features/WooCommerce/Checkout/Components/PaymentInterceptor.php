<?php
namespace ZeroSense\Features\WooCommerce\Checkout\Components;

class PaymentInterceptor
{
    private function log(string $message, array $context = []): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->debug('[PaymentInterceptor] ' . $message, array_merge($context, ['source' => 'zero-sense']));
        }
    }
    public function __construct()
    {
        add_action('woocommerce_rest_checkout_process_payment_with_context', [$this, 'interceptBlocksCheckout'], 5, 2);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'processBlocksOrderBackup'], 10, 1);
        add_filter('woocommerce_payment_successful_result', [$this, 'interceptClassicCheckout'], 5, 2);
        add_action('woocommerce_checkout_order_processed', [$this, 'processClassicOrderBackup'], 5, 3);
    }

    public function interceptBlocksCheckout($context, $result): void
    {
        if (isset($context->order)) {
            $redirect_url = $this->processOrderInterception($context->order);
            if ($redirect_url) {
                $result->set_status('success');
                $result->set_redirect_url($redirect_url);
            }
        }
    }

    public function interceptClassicCheckout(array $result, int $order_id): array
    {
        $this->log('interceptClassicCheckout fired', ['order_id' => $order_id, 'incoming_result' => $result['result'] ?? 'unknown']);
        $order = wc_get_order($order_id);
        if ($order) {
            $redirect_url = $this->processOrderInterception($order);
            $this->log('interceptClassicCheckout redirect_url', ['order_id' => $order_id, 'redirect_url' => $redirect_url]);
            if ($redirect_url) {
                return ['result' => 'success', 'redirect' => $redirect_url];
            }
        }
        return $result;
    }

    private function processOrderInterception(\WC_Order $order): ?string
    {
        // Only change status to budget-requested for NEW orders (from checkout)
        // Do NOT change status for existing orders being paid (order-pay page)
        $isNewOrder = $order->get_date_created() && 
                      (time() - $order->get_date_created()->getTimestamp()) < 60; // Created within last 60 seconds

        $this->log('processOrderInterception', [
            'order_id' => $order->get_id(),
            'status' => $order->get_status(),
            'isNewOrder' => $isNewOrder,
        ]);
        
        if ($isNewOrder && $order->get_status() !== 'budget-requested') {
            $order->update_status('budget-requested', __('Budget requested after checkout completion.', 'zero-sense'));
        }
        
        // Don't redirect - let WooCommerce use default order-received page
        // BacsRedirects handles BACS payments from order-pay context
        // ReturnHandler handles Redsys payment returns
        return null;
    }

    public function processBlocksOrderBackup(\WC_Order $order): void
    {
        $this->processOrderInterception($order);
    }

    public function processClassicOrderBackup(int $order_id, array $posted_data, \WC_Order $order): void
    {
        $this->processOrderInterception($order);
    }
}
