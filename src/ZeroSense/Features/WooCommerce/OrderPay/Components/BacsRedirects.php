<?php
namespace ZeroSense\Features\WooCommerce\OrderPay\Components;

use ZeroSense\Core\Logger;

class BacsRedirects
{
    public function __construct()
    {
        add_filter('woocommerce_payment_successful_result', [$this, 'adjustPaymentSuccessRedirect'], 999, 2);
        add_action('woocommerce_thankyou_bacs', [$this, 'handleBacsThankYouRedirect'], 1);
        add_action('woocommerce_payment_complete', [$this, 'handleBacsPaymentCompleteRedirect'], 999);
        add_action('template_redirect', [$this, 'handleBacsSecondPaymentRedirect'], 1);
    }

    public function adjustPaymentSuccessRedirect(array $result, int $orderId): array
    {
        $order = wc_get_order($orderId);
        
        if ($order && $this->isOrderPayContext() && $order->get_payment_method() === 'bacs') {
            $language = $this->detectOrderLanguage($order);
            $status = $order->get_status();
            $redirect = $this->getRedirectUrl($language, $status);
            
            if ($redirect) {
                Logger::debug(sprintf(
                    "[BACS] Redirecting Order #%d (status: %s) to custom page: %s",
                    $orderId,
                    $status,
                    $redirect
                ));
                $result['redirect'] = $redirect;
            }
        }
        return $result;
    }

    public function handleBacsThankYouRedirect(int $orderId): void
    {
        $order = wc_get_order($orderId);
        
        if ($order && $order->get_payment_method() === 'bacs') {
            $this->maybeRedirectForBacsDeposit($order);
        }
    }

    public function handleBacsPaymentCompleteRedirect(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if ($order && $order->get_payment_method() === 'bacs') {
            $this->maybeRedirectForBacsDeposit($order);
        }
    }

    public function handleBacsSecondPaymentRedirect(): void
    {
        if (is_wc_endpoint_url('order-received')) {
            $orderId = absint(get_query_var('order-received'));
            $order = $orderId ? wc_get_order($orderId) : null;
            if ($order && $this->isDepositPaidStatus($order->get_status()) && get_transient('zs_bacs_2nd_pay_processed_' . $orderId) === 'yes') {
                delete_transient('zs_bacs_2nd_pay_processed_' . $orderId);
                $language = $this->detectOrderLanguage($order);
                $redirect = $this->getRedirectUrl($language, 'deposit-paid');
                if ($redirect && !headers_sent()) {
                    wp_redirect($redirect);
                    exit;
                }
            }
        }
    }

    private function maybeRedirectForBacsDeposit(\WC_Order $order): void
    {
        if ($this->isDepositPaidStatus($order->get_status())) {
            $language = $this->detectOrderLanguage($order);
            $redirect = $this->getRedirectUrl($language, 'deposit-paid');
            
            if ($redirect && !headers_sent()) {
                wp_redirect($redirect);
                exit;
            }
        }
    }

    private function isOrderPayContext(): bool
    {
        // Check explicit order-pay params
        if (isset($_GET['pay_for_order'], $_GET['key'])) {
            return true;
        }
        
        // Check if we're on order-pay endpoint
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            return true;
        }
        
        return false;
    }

    private function getRedirectUrl(string $language, string $status): ?string
    {
        $map = [
            'es' => [
                'pending' => home_url('/woo-status/gracias-transfer-primer-pago/'),
                'deposit-paid' => home_url('/woo-status/gracias-transfer-segundo-pago/'),
            ],
            'en' => [
                'pending' => home_url('/en/woo-status/thanks-transfer-first-payment/'),
                'deposit-paid' => home_url('/en/woo-status/thanks-transfer-second-payment/'),
            ],
            'ca' => [
                'pending' => home_url('/ca/woo-status/gracies-transfer-primer-pagament/'),
                'deposit-paid' => home_url('/ca/woo-status/gracies-transfer-segon-pagament/'),
            ],
        ];
        $normalized = $this->isDepositPaidStatus($status) ? 'deposit-paid' : $status;
        return $map[$language][$normalized] ?? null;
    }

    private function detectOrderLanguage(\WC_Order $order): string
    {
        $language = $order->get_meta('wpml_language');
        
        if (in_array($language, ['en', 'ca'], true)) {
            return $language;
        }
        
        if (empty($language) && function_exists('apply_filters')) {
            $current = apply_filters('wpml_current_language', null);
            
            if (in_array($current, ['en', 'ca'], true)) {
                return $current;
            }
        }
        
        return 'es';
    }

    private function isDepositPaidStatus(string $status): bool
    {
        return in_array($status, ['wc-deposit-paid', 'deposit-paid', 'deposit_paid'], true);
    }
}
