<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Components;

use WC_Order;
use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\Deposits\Support\Logs;

class StatusSync
{
    public function register(): void
    {
        add_action('woocommerce_order_status_changed', [$this, 'onOrderStatusChanged'], 10, 4);
    }

    public function onOrderStatusChanged(int $orderId, string $fromStatus, string $toStatus, $order): void
    {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($orderId);
        }
        if (!$order instanceof WC_Order) {
            return;
        }

        // Only act for our deposit-related statuses
        if ($toStatus === 'deposit-paid') {
            $this->syncDepositPaid($order, $fromStatus);
            return;
        }

        if ($toStatus === 'fully-paid') {
            $this->syncFullyPaid($order, $fromStatus);
            return;
        }
    }

    private function syncDepositPaid(WC_Order $order, string $fromStatus): void
    {
        // Set flags for deposit paid
        MetaKeys::update($order, MetaKeys::IS_DEPOSIT_PAID, 'yes');
        if (!$order->get_meta(MetaKeys::DEPOSIT_PAYMENT_DATE, true)) {
            MetaKeys::update($order, MetaKeys::DEPOSIT_PAYMENT_DATE, current_time('mysql'));
        }

        // Ensure deposit flow flags are consistent
        MetaKeys::update($order, MetaKeys::PAYMENT_FLOW, 'deposit_manual');
        MetaKeys::delete($order, MetaKeys::IS_BALANCE_PAID);
        MetaKeys::delete($order, MetaKeys::BALANCE_PAYMENT_DATE);

        // Remaining amount may be present already. If deposit amount exists, ensure remaining meta is non-negative.
        $orderTotal     = (float) $order->get_total();
        $depositAmount  = (float) ($order->get_meta(MetaKeys::DEPOSIT_AMOUNT, true) ?: 0);
        if ($depositAmount > 0) {
            $remaining = max(0, $orderTotal - $depositAmount);
            $balanceAmount = max(0, $orderTotal - $depositAmount);
            MetaKeys::update($order, MetaKeys::REMAINING_AMOUNT, $remaining);
            MetaKeys::update($order, MetaKeys::BALANCE_AMOUNT, $balanceAmount);
        }

        $order->save();
        $order->save_meta_data();

        // Log manual transition
        try {
            Logs::add($order, 'status', [
                'event' => 'manual_status_to_deposit_paid',
                'from' => $fromStatus,
            ]);
        } catch (\Throwable $e) { /* silent */ }
    }

    private function syncFullyPaid(WC_Order $order, string $fromStatus): void
    {
        // Only set BALANCE_PAID if coming from deposit-paid (i.e., second payment)
        if ($fromStatus === 'deposit-paid') {
            MetaKeys::update($order, MetaKeys::IS_BALANCE_PAID, 'yes');
            MetaKeys::update($order, MetaKeys::BALANCE_PAYMENT_DATE, current_time('mysql'));
        } else {
            // Direct full payment: register as first payment if not already set
            if (!$order->get_meta(MetaKeys::DEPOSIT_PAYMENT_DATE, true)) {
                MetaKeys::update($order, MetaKeys::DEPOSIT_PAYMENT_DATE, current_time('mysql'));
                MetaKeys::update($order, MetaKeys::IS_DEPOSIT_PAID, 'yes');
            }
            
            // Ensure we do NOT mark balance paid for direct full payments
            MetaKeys::delete($order, MetaKeys::IS_BALANCE_PAID);
            MetaKeys::delete($order, MetaKeys::BALANCE_PAYMENT_DATE);
        }

        // If coming from deposit-paid, mark type accordingly; else direct full
        $paymentType = ($fromStatus === 'deposit-paid') ? 'full_after_deposit_manual' : 'full_manual';
        MetaKeys::update($order, MetaKeys::PAYMENT_FLOW, $paymentType);

        // Remaining is zero when fully paid
        MetaKeys::update($order, MetaKeys::REMAINING_AMOUNT, 0);
        
        // Balance always reflects total - deposit (mathematical difference, regardless of payment status)
        $orderTotal = (float) $order->get_total();
        $depositAmount = (float) ($order->get_meta(MetaKeys::DEPOSIT_AMOUNT, true) ?: 0);
        $balanceAmount = max(0, $orderTotal - $depositAmount);
        MetaKeys::update($order, MetaKeys::BALANCE_AMOUNT, $balanceAmount);

        $order->save();
        $order->save_meta_data();

        // Log manual transition
        try {
            Logs::add($order, 'status', [
                'event' => 'manual_status_to_fully_paid',
                'from' => $fromStatus,
                'balance_marked' => ($fromStatus === 'deposit-paid') ? 'yes' : 'no',
            ]);
        } catch (\Throwable $e) { /* silent */ }
    }
}
