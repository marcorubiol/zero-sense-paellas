<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Integrations\Redsys;

use WC_Order;
use ZeroSense\Features\WooCommerce\Deposits\Support\Utils;
use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\Deposits\Support\Logs;

class ReturnHandler
{
    use PaymentApplicator;
    public function register(): void
    {
        add_action('template_redirect', [$this, 'handleReturn'], 1);
    }

    public function handleReturn(): void
    {
        $isReturnPage = is_order_received_page() || is_checkout_pay_page();
        if (!$isReturnPage) {
            return;
        }

        $orderId = $this->resolveOrderId();
        if (!$orderId) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        // Fast-path: S2S callback already updated the order — redirect to thank-you without needing GET params.
        // deposit-paid on order-pay must NOT redirect (customer is there to pay the remainder).
        $isOrderPay = is_checkout_pay_page();
        $isFullyPaid = $order->has_status('fully-paid');
        $isDepositPaid = $order->has_status('deposit-paid');

        if (($isFullyPaid || $isDepositPaid) && !$isOrderPay) {
            $originalLanguage = $this->switchToOrderLanguage($order);
            $type = $isDepositPaid ? 'deposit' : 'full';
            $redirectUrl = $this->buildRedirectUrl($order, $type);
            $this->restoreLanguage($originalLanguage, $order);
            if ($redirectUrl) {
                $this->emptyCart();
                wp_safe_redirect($redirectUrl);
                exit;
            }
            return;
        }

        // Normal path: Redsys GET params present (used as fallback when S2S hasn't fired yet).
        if (!$this->hasRedsysParameters()) {
            return;
        }

        $params = $this->decodeParameters($_GET['Ds_MerchantParameters'] ?? '');
        if (!$params) {
            return;
        }

        $responseCode = (string) ($params['Ds_Response'] ?? '');
        $amountPaid = isset($params['Ds_Amount']) ? ((float) $params['Ds_Amount'] / 100) : 0.0;

        $originalLanguage = $this->switchToOrderLanguage($order);

        $result = $this->processResponse($order, $responseCode, $amountPaid);

        $this->restoreLanguage($originalLanguage, $order);

        if (!$result['redirect']) {
            return;
        }

        wp_safe_redirect($result['redirect']);
        exit;
    }

    private function hasRedsysParameters(): bool
    {
        return isset($_GET['Ds_SignatureVersion'], $_GET['Ds_MerchantParameters'], $_GET['Ds_Signature']);
    }

    private function resolveOrderId(): int
    {
        global $wp;

        if (is_order_received_page() && isset($wp->query_vars['order-received'])) {
            return absint($wp->query_vars['order-received']);
        }

        if (isset($wp->query_vars['order-pay'])) {
            return absint($wp->query_vars['order-pay']);
        }

        if (isset($_GET['key'])) {
            $orderKey = wc_clean(wp_unslash($_GET['key']));
            return (int) wc_get_order_id_by_order_key($orderKey);
        }

        return 0;
    }

    private function decodeParameters(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = base64_decode(strtr($raw, '-_', '+/'));
        if (!$decoded) {
            return [];
        }

        $data = json_decode($decoded, true);
        return is_array($data) ? $data : [];
    }

    private function processResponse(WC_Order $order, string $responseCode, float $amountPaid): array
    {
        $depositInfo = Utils::getDepositInfo($order);
        $hasDeposit = (bool) ($depositInfo['has_deposit'] ?? false);
        $depositAmount = (float) ($depositInfo['deposit_amount'] ?? 0.0);

        $redirectType = null;

        $isAlreadyPaid = $order->has_status('deposit-paid') || $order->has_status('fully-paid');

        if ($isAlreadyPaid) {
            // Already processed (e.g. by server-to-server callback) — just redirect
            $redirectType = $order->has_status('deposit-paid') ? 'deposit' : 'full';
        } elseif ($responseCode !== '' && Config::isSuccessResponse($responseCode)) {
            $redirectType = $this->handleSuccess($order, $hasDeposit, $depositAmount, $amountPaid);
        } elseif ($responseCode !== '' && Config::isCancelledByUser($responseCode)) {
            $redirectType = $this->handleCancellation($order);
        } else {
            $redirectType = $this->handleFailure($order, $responseCode);
        }

        $this->emptyCart();

        return [
            'redirect' => $this->buildRedirectUrl($order, $redirectType),
        ];
    }

    private function handleSuccess(WC_Order $order, bool $hasDeposit, float $depositAmount, float $amountPaid): string
    {
        if ($hasDeposit && $depositAmount > 0) {
            $intent = abs($amountPaid - $depositAmount) < 1 ? 'deposit' : 'full_initial';
        } else {
            $intent = 'full_standard';
        }

        $target = $this->applyPaymentSuccess($order, $intent, $amountPaid);

        return $target === 'deposit-paid' ? 'deposit' : 'full';
    }

    private function handleCancellation(WC_Order $order): string
    {
        $order->update_status('cancelled', __('Payment cancelled by cardholder via Redsys.', 'zero-sense'));
        MetaKeys::update($order, MetaKeys::IS_CANCELLED, 'yes');
        MetaKeys::update($order, MetaKeys::CANCELLED_DATE, current_time('mysql'));
        $order->save();
        $order->save_meta_data();

        // Log cancellation
        Logs::add($order, 'status', [
            'event' => 'payment_cancelled',
            'gateway' => 'redsys',
        ]);

        return 'cancelled';
    }

    private function handleFailure(WC_Order $order, string $responseCode): string
    {
        $order->update_status('failed', sprintf(__('Redsys payment failed or was denied. Response code: %s', 'zero-sense'), $responseCode));
        MetaKeys::update($order, MetaKeys::IS_FAILED, 'yes');
        if ($responseCode !== '') {
            MetaKeys::update($order, MetaKeys::FAILED_CODE, $responseCode);
        }
        MetaKeys::update($order, MetaKeys::FAILED_DATE, current_time('mysql'));
        $order->save();
        $order->save_meta_data();

        // Log failure
        Logs::add($order, 'error', [
            'event' => 'payment_failed',
            'response_code' => $responseCode,
            'gateway' => 'redsys',
        ]);

        return 'failed';
    }

    private function buildRedirectUrl(WC_Order $order, ?string $type): string
    {
        if (!$type) {
            return '';
        }

        $slugs = [
            'deposit' => [
                'es' => '/woo-status/gracias-deposito/',
                'en' => '/en/woo-status/thanks-deposit/',
                'ca' => '/ca/woo-status/gracies-diposit/',
            ],
            'full' => [
                'es' => '/woo-status/gracias-pago-completo/',
                'en' => '/en/woo-status/thanks-full-payment/',
                'ca' => '/ca/woo-status/gracies-pagament-complet/',
            ],
            'failed' => [
                'es' => '/woo-status/pago-fallido/',
                'en' => '/en/woo-status/payment-failed/',
                'ca' => '/ca/woo-status/pagament-fallit/',
            ],
            'cancelled' => [
                'es' => '/woo-status/pago-cancelado/',
                'en' => '/en/woo-status/payment-cancelled/',
                'ca' => '/ca/woo-status/pagament-cancelat/',
            ],
        ];

        if (!isset($slugs[$type])) {
            return '';
        }

        $language = $order->get_meta('wpml_language', true);
        if (!$language && function_exists('apply_filters')) {
            $language = apply_filters('wpml_current_language', null);
        }
        $language = $language ?: 'es';

        $slug = $slugs[$type][$language] ?? $slugs[$type]['es'];
        $url = site_url($slug);

        return add_query_arg('order', $order->get_id(), $url);
    }

    private function switchToOrderLanguage(WC_Order $order): ?string
    {
        if (!function_exists('apply_filters') || !function_exists('do_action')) {
            return null;
        }

        $current = apply_filters('wpml_current_language', null);
        $orderLanguage = $order->get_meta('wpml_language', true);
        if (!$orderLanguage && function_exists('get_post_meta')) {
            $orderLanguage = get_post_meta($order->get_id(), 'wpml_language', true);
        }

        if ($orderLanguage && $current !== $orderLanguage) {
            do_action('wpml_switch_language', $orderLanguage);
        }

        return $current;
    }

    private function restoreLanguage(?string $language, WC_Order $order): void
    {
        if (!$language || !function_exists('do_action')) {
            return;
        }

        do_action('wpml_switch_language', $language);
    }

    private function emptyCart(): void
    {
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }
    }
}
