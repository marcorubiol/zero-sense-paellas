<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Components;

use ZeroSense\Features\WooCommerce\Deposits\Support\Utils;
use WC_Order;

class PaymentNotice
{
    public function register(): void
    {
        add_action('woocommerce_before_account_orders', [$this, 'renderMyAccountNotice']);
        add_action('woocommerce_view_order', [$this, 'renderOrderDetailNotice'], 10, 1);
    }

    public function renderMyAccountNotice(): void
    {
        $customerId = get_current_user_id();
        if (!$customerId) {
            return;
        }

        $orders = wc_get_orders([
            'customer' => $customerId,
            'status' => ['deposit-paid'],
            'limit' => 5,
        ]);

        if (empty($orders)) {
            return;
        }

        $totalDue = 0;
        $dueOrders = [];

        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            $info = Utils::getDepositInfo($order);
            if (($info['has_deposit'] ?? false) && ($info['remaining_amount'] ?? 0) > 0) {
                $totalDue += $info['remaining_amount'];
                $dueOrders[] = [
                    'id' => $order->get_id(),
                    'remaining' => $info['remaining_amount'],
                    'url' => $order->get_view_order_url(),
                    'currency' => $order->get_currency(),
                ];
            }
        }

        if (empty($dueOrders)) {
            return;
        }

        ob_start();
        echo '<div class="zs-deposits-payment-notice zs-wd-payment-notice">';

        if (count($dueOrders) === 1) {
            $entry = $dueOrders[0];
            printf(
                /* translators: 1: remaining amount 2: order id 3: order url */
                __('You have a remaining balance of %1$s for order #%2$s. <a href="%3$s">View details</a>', 'zero-sense'),
                '<strong>' . wc_price($entry['remaining'], ['currency' => $entry['currency']]) . '</strong>',
                $entry['id'],
                esc_url($entry['url'])
            );
        } else {
            printf(
                /* translators: 1: total remaining amount 2: number of orders */
                __('You have remaining balances totaling %1$s on %2$d orders.', 'zero-sense'),
                '<strong>' . wc_price($totalDue) . '</strong>',
                count($dueOrders)
            );

            echo '<ul class="zs-deposits-orders-due zs-wd-orders-due">';
            foreach ($dueOrders as $entry) {
                printf(
                    '<li>' . __('Order #%1$s: %2$s - <a href="%3$s">View details</a>', 'zero-sense') . '</li>',
                    $entry['id'],
                    wc_price($entry['remaining'], ['currency' => $entry['currency']]),
                    esc_url($entry['url'])
                );
            }
            echo '</ul>';
        }

        echo '</div>';
        $notice = ob_get_clean();

        if (!empty($notice)) {
            wc_print_notice($notice, 'notice');
        }
    }

    public function renderOrderDetailNotice(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order || !$order->has_status('deposit-paid')) {
            return;
        }

        $info = Utils::getDepositInfo($order);
        if (($info['has_deposit'] ?? false) === false || ($info['remaining_amount'] ?? 0) <= 0) {
            return;
        }

        $remaining = wc_price($info['remaining_amount'], ['currency' => $order->get_currency()]);
        $notice = sprintf(
            /* translators: 1: remaining amount 2: payment url */
            __('Your deposit has been received. There is a remaining balance of %1$s for this order. <a href="%2$s" class="button pay-now">Pay Remaining Balance</a>', 'zero-sense'),
            '<strong>' . $remaining . '</strong>',
            esc_url($order->get_checkout_payment_url())
        );

        wc_print_notice($notice, 'notice');
    }
}
