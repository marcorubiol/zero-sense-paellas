<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Integrations\Redsys;

use WC_Order;
use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\Deposits\Support\Logs;

trait PaymentApplicator
{
    /**
     * Apply payment success to an order: update status, meta keys, and logs.
     * Returns the target status string ('deposit-paid' or 'fully-paid').
     *
     * @param string $intent  Value of MetaKeys::PAYMENT_FLOW ('deposit', 'full', 'balance', etc.)
     * @param float  $amountPaid  Amount paid in order currency
     */
    protected function applyPaymentSuccess(WC_Order $order, string $intent, float $amountPaid = 0.0): string
    {
        $orderTotal        = (float) $order->get_total();
        $depositAmountMeta = (float) ($order->get_meta(MetaKeys::DEPOSIT_AMOUNT, true) ?: 0);

        if ($intent === 'deposit') {
            $target    = 'deposit-paid';
            $remaining = max(0.0, $orderTotal - $depositAmountMeta);

            MetaKeys::update($order, MetaKeys::IS_DEPOSIT_PAID, 'yes');
            MetaKeys::update($order, MetaKeys::FIRST_PAYMENT_DATE, current_time('mysql'));
            MetaKeys::update($order, MetaKeys::PAYMENT_FLOW, 'deposit');
            MetaKeys::delete($order, MetaKeys::IS_BALANCE_PAID);
            MetaKeys::delete($order, MetaKeys::SECOND_PAYMENT_DATE);
            MetaKeys::update($order, MetaKeys::REMAINING_AMOUNT, $remaining);
            MetaKeys::update($order, MetaKeys::BALANCE_AMOUNT, $remaining);

            Logs::add($order, 'gateway', [
                'event'            => 'deposit_paid',
                'amount_paid'      => wc_format_decimal($amountPaid),
                'expected_deposit' => wc_format_decimal($depositAmountMeta),
                'payment_type'     => 'deposit',
            ]);
        } else {
            $target      = 'fully-paid';
            $flowValue   = $intent ?: 'full';
            $balanceAmount = max(0.0, $orderTotal - $depositAmountMeta);

            MetaKeys::update($order, MetaKeys::PAYMENT_FLOW, $flowValue);
            MetaKeys::update($order, MetaKeys::REMAINING_AMOUNT, 0);
            MetaKeys::update($order, MetaKeys::BALANCE_AMOUNT, $balanceAmount);

            Logs::add($order, 'gateway', [
                'event'        => 'full_paid',
                'amount_paid'  => wc_format_decimal($amountPaid),
                'payment_type' => $flowValue,
            ]);
        }

        $previousStatus = $order->get_status();
        $order->update_status($target);
        $order->save();
        $order->save_meta_data();

        if ($previousStatus === $target) {
            do_action('woocommerce_order_status_changed', $order->get_id(), $previousStatus, $target, $order);
        }

        return $target;
    }
}
