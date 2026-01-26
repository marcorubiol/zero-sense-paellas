<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Components;

use ZeroSense\Features\WooCommerce\Deposits\Support\Utils;
use WC_Order;

class PaymentOptions
{

    public function register(): void
    {
        add_action('wp', [$this, 'prepareStatusOverrides'], 5);
        add_action('wp_loaded', [$this, 'fixOrderPayMessages'], 20);
        add_action('woocommerce_review_order_before_payment', [$this, 'renderPaymentOptions'], 5);
        add_action('woocommerce_order_details_before_order_table', [$this, 'renderPaymentOptions'], 5);
        add_action('woocommerce_pay_order_before_payment', [$this, 'renderPaymentOptions'], 5);
        add_action('woocommerce_pay_order_after_submit', [$this, 'renderPaymentOptions'], 5);
        add_action('bricks/woocommerce/review_order_before_payment', [$this, 'renderPaymentOptions'], 5);
        add_action('bricks/woocommerce/pay_order_before_submit', [$this, 'renderPaymentOptions'], 5);

        add_action('wp_ajax_zs_deposits_get_payment_options_html', [$this, 'handleOptionsAjax']);
        add_action('wp_ajax_nopriv_zs_deposits_get_payment_options_html', [$this, 'handleOptionsAjax']);

        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function prepareStatusOverrides(): void
    {
        if (!is_wc_endpoint_url('order-pay')) {
            return;
        }
        global $wp;
        $orderId = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        $order = $orderId ? wc_get_order($orderId) : null;
        if (!$order instanceof WC_Order) {
            return;
        }

        $status = $order->get_status();
        if (!in_array($status, ['deposit-paid', 'fully-paid'], true)) {
            return;
        }

        wc_clear_notices();

        add_filter('woocommerce_valid_order_statuses_for_payment', function ($statuses) {
            if (!is_array($statuses)) {
                $statuses = ['pending', 'failed'];
            }

            $statuses[] = 'deposit-paid';
            $statuses[] = 'fully-paid';
            return array_unique($statuses);
        }, 999);
    }

    public function fixOrderPayMessages(): void
    {
        if (!is_wc_endpoint_url('order-pay')) {
            return;
        }

        $notices = wc_get_notices();
        if (!isset($notices['error']) || !is_array($notices['error'])) {
            return;
        }

        foreach ($notices['error'] as $key => $notice) {
            if (!is_string($notice)) {
                continue;
            }

            if (strpos($notice, 'Deposit Paid') !== false || strpos($notice, 'cannot be paid') !== false) {
                unset($notices['error'][$key]);
            }
        }

        if (empty($notices['error'])) {
            unset($notices['error']);
        }

        WC()->session->set('wc_notices', $notices);
    }

    public function renderPaymentOptions(): void
    {
        if (!is_wc_endpoint_url('order-pay')) {
            return;
        }

        global $wp;
        $orderId = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        $order = $orderId ? wc_get_order($orderId) : null;
        if (!$order instanceof WC_Order) {
            return;
        }

        if (!$order->needs_payment()) {
            return;
        }

        static $rendered = false;

        if (!is_wc_endpoint_url('order-pay')) {
            $rendered = false;
        }

        if ($rendered) {
            return;
        }

        $choice = WC()->session ? WC()->session->get('zs_deposits_payment_choice_order_' . $orderId, 'deposit') : 'deposit';
        $info = Utils::getDepositInfo($order, $choice);
        if (empty($info) || ($info['has_deposit'] ?? false) === false) {
            return;
        }
        $rendered = true;

        echo $this->renderTemplate($order, $info); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function enqueueAssets(): void
    {
        if (!is_checkout() && !is_wc_endpoint_url('order-pay')) {
            return;
        }


        if (!wp_style_is('zs-deposits-payment-choice', 'enqueued')) {
            $assetBase = plugin_dir_url(ZERO_SENSE_FILE) . 'src/ZeroSense/Features/WooCommerce/Deposits/Assets/';

            wp_enqueue_style(
                'zs-deposits-payment-choice',
                $assetBase . 'payment-choice.css',
                [],
                defined('ZERO_SENSE_VERSION') ? ZERO_SENSE_VERSION : filemtime(plugin_dir_path(ZERO_SENSE_FILE) . 'src/ZeroSense/Features/WooCommerce/Deposits/Assets/payment-choice.css')
            );
        }

        wp_enqueue_script(
            'zs-deposits-payment-choice',
            $assetBase . 'payment-choice.js',
            [],
            defined('ZERO_SENSE_VERSION') ? ZERO_SENSE_VERSION : filemtime(plugin_dir_path(ZERO_SENSE_FILE) . 'src/ZeroSense/Features/WooCommerce/Deposits/Assets/payment-choice.js'),
            true
        );

        wp_localize_script(
            'zs-deposits-payment-choice',
            'zsDepositsPaymentChoice',
            [
                'selectors' => [
                    '#wd-summary-price-value',
                    '.payment-summary__amount strong',
                    '.woocommerce-order-pay .order-total .amount',
                    '#order_review .order-total .amount',
                ],
            ]
        );
        
        // Add loading indicator for order-pay pages
        if (is_wc_endpoint_url('order-pay')) {
            wp_enqueue_script(
                'zs-order-pay-loading',
                $assetBase . 'order-pay-loading.js',
                [],
                defined('ZERO_SENSE_VERSION') ? ZERO_SENSE_VERSION : filemtime(plugin_dir_path(ZERO_SENSE_FILE) . 'src/ZeroSense/Features/WooCommerce/Deposits/Assets/order-pay-loading.js'),
                true
            );
        }
    }

    public function handleOptionsAjax(): void
    {
        check_ajax_referer('zs_deposits_get_payment_options_nonce', 'security');

        $orderId = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $orderKey = isset($_POST['order_key']) ? wc_clean(wp_unslash($_POST['order_key'])) : '';
        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order || $order->get_order_key() !== $orderKey) {
            wp_send_json_error();
        }

        if ($order->is_paid() || $order->has_status(['processing', 'completed'])) {
            wp_send_json_error();
        }

        $paymentContext = isset($_POST['payment_context']) ? sanitize_text_field(wp_unslash($_POST['payment_context'])) : '';
        $choice = $paymentContext === 'true'
            ? (WC()->session ? WC()->session->get('zs_deposits_payment_choice_order_' . $orderId, 'deposit') : 'deposit')
            : 'deposit';

        $info = Utils::getDepositInfo($order, $choice);
        if (empty($info) || ($info['has_deposit'] ?? false) === false) {
            wp_send_json_error('Could not retrieve deposit information for the order.');
        }

        $html = $this->renderTemplate($order, $info);
        wp_send_json_success($html);
    }

    private function renderTemplate(WC_Order $order, array $info): string
    {
        $template = $order->has_status('deposit-paid')
            ? 'remaining-payment.php'
            : 'payment-choice.php';

        ob_start();
        $templatePath = plugin_dir_path(ZERO_SENSE_FILE) . 'src/ZeroSense/Features/WooCommerce/Deposits/Templates/' . $template;

        if (file_exists($templatePath)) {
            $info['order'] = $order;
            extract($info, EXTR_SKIP);
            include $templatePath;
        }

        return ob_get_clean();
    }
}
