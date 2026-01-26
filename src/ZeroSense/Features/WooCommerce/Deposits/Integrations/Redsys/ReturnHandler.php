<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Integrations\Redsys;

use WC_Order;
use ZeroSense\Features\WooCommerce\Deposits\Support\Utils;
use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\Deposits\Support\Logs;

class ReturnHandler
{
    public function register(): void
    {
        add_action('template_redirect', [$this, 'handleReturn'], 1);
    }

    public function handleReturn(): void
    {
        if (!$this->hasRedsysParameters()) {
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
        if (!isset($_GET['Ds_SignatureVersion'], $_GET['Ds_MerchantParameters'], $_GET['Ds_Signature'])) {
            return false;
        }

        return is_order_received_page() || is_checkout_pay_page();
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

        if ($responseCode !== '' && Config::isSuccessResponse($responseCode)) {
            if ($isAlreadyPaid) {
                // Do not change status, only choose redirect based on current state
                $redirectType = $order->has_status('deposit-paid') ? 'deposit' : 'full';
            } else {
                $redirectType = $this->handleSuccess($order, $hasDeposit, $depositAmount, $amountPaid);
            }
        } elseif ($responseCode !== '' && Config::isCancelledByUser($responseCode)) {
            if ($isAlreadyPaid) {
                // Do not downgrade a paid order
                $redirectType = $order->has_status('deposit-paid') ? 'deposit' : 'full';
            } else {
                $redirectType = $this->handleCancellation($order);
            }
        } else {
            if ($isAlreadyPaid) {
                // Do not mark as failed if already paid
                $redirectType = $order->has_status('deposit-paid') ? 'deposit' : 'full';
            } else {
                $redirectType = $this->handleFailure($order, $responseCode);
            }
        }

        $this->emptyCart();

        return [
            'redirect' => $this->buildRedirectUrl($order, $redirectType),
        ];
    }

    private function handleSuccess(WC_Order $order, bool $hasDeposit, float $depositAmount, float $amountPaid): string
    {
        $orderId = $order->get_id();

        if ($hasDeposit && $depositAmount > 0) {
            $isDepositPayment = abs($amountPaid - $depositAmount) < 1;

            if ($isDepositPayment) {
                $order->update_status('deposit-paid', __('Deposit payment received via Redsys.', 'zero-sense'));
                MetaKeys::update($order, MetaKeys::IS_DEPOSIT_PAID, 'yes');
                MetaKeys::update($order, MetaKeys::DEPOSIT_PAYMENT_DATE, current_time('mysql'));
                MetaKeys::update($order, MetaKeys::PAYMENT_FLOW, 'deposit');
                MetaKeys::delete($order, MetaKeys::IS_BALANCE_PAID);
                MetaKeys::delete($order, MetaKeys::BALANCE_PAYMENT_DATE);
                // Update remaining/balance after deposit success
                $orderTotal = (float) $order->get_total();
                $remaining = max(0, $orderTotal - (float) $depositAmount);
                $balanceAmount = max(0, $orderTotal - (float) $depositAmount);
                MetaKeys::update($order, MetaKeys::REMAINING_AMOUNT, $remaining);
                MetaKeys::update($order, MetaKeys::BALANCE_AMOUNT, $balanceAmount);
                $order->save();
                $order->save_meta_data();

                // Log deposit success
                Logs::add($order, 'gateway', [
                    'event' => 'deposit_paid',
                    'amount_paid' => wc_format_decimal($amountPaid),
                    'expected_deposit' => wc_format_decimal($depositAmount),
                    'payment_type' => 'deposit',
                    'gateway' => 'redsys',
                ]);

                return 'deposit';
            }

            $order->update_status('fully-paid', __('Full payment received via Redsys.', 'zero-sense'));
            // Direct full payment (not after deposit): do NOT mark balance flags
            MetaKeys::update($order, MetaKeys::PAYMENT_FLOW, 'full_initial');
            MetaKeys::update($order, MetaKeys::REMAINING_AMOUNT, 0);
            // Balance always = total - deposit (mathematical difference)
            $orderTotal = (float) $order->get_total();
            $depositAmountMeta = (float) ($order->get_meta(MetaKeys::DEPOSIT_AMOUNT, true) ?: 0);
            $balanceAmount = max(0, $orderTotal - $depositAmountMeta);
            MetaKeys::update($order, MetaKeys::BALANCE_AMOUNT, $balanceAmount);
            $order->save();
            $order->save_meta_data();

            Logs::add($order, 'gateway', [
                'event' => 'remaining_paid',
                'amount_paid' => wc_format_decimal($amountPaid),
                'expected_remaining' => wc_format_decimal(max(0, ($depositAmount > 0 ? ($order->get_total() - $depositAmount) : 0))),
                'payment_type' => 'full_initial',
                'gateway' => 'redsys',
            ]);

            return 'full';
        }

        $order->update_status('fully-paid', __('Payment received via Redsys.', 'zero-sense'));
        MetaKeys::update($order, MetaKeys::PAYMENT_FLOW, 'full_standard');
        MetaKeys::update($order, MetaKeys::REMAINING_AMOUNT, 0);
        // Balance always = total - deposit (mathematical difference)
        $orderTotal = (float) $order->get_total();
        $depositAmountMeta = (float) ($order->get_meta(MetaKeys::DEPOSIT_AMOUNT, true) ?: 0);
        $balanceAmount = max(0, $orderTotal - $depositAmountMeta);
        MetaKeys::update($order, MetaKeys::BALANCE_AMOUNT, $balanceAmount);
        $order->save();
        $order->save_meta_data();

        Logs::add($order, 'gateway', [
            'event' => 'full_paid',
            'amount_paid' => wc_format_decimal($amountPaid),
            'payment_type' => 'full_standard',
            'gateway' => 'redsys',
        ]);

        return 'full';
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
