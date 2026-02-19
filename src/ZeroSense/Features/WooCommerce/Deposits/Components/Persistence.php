<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Components;

use WC_Order;
use ZeroSense\Features\WooCommerce\Deposits\Support\Utils;
use ZeroSense\Features\WooCommerce\Deposits\Support\Logs;
use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys;

class Persistence
{
    public function register(): void
    {
        // During checkout order creation
        add_action('woocommerce_checkout_order_created', [$this, 'onOrderCreated'], 20, 1);

        // When admin saves items/totals in the order editor
        add_action('woocommerce_saved_order_items', [$this, 'onOrderItemsSaved'], 20, 1);
        // Note: this hook passes (bool $and_taxes, WC_Order $order)
        add_action('woocommerce_order_after_calculate_totals', [$this, 'onAfterCalculateTotals'], 20, 2);
        // HPOS: fires after the order object is fully saved (items + totals already updated)
        add_action('woocommerce_after_order_object_save', [$this, 'onAfterOrderObjectSave'], 20, 1);

        // Generic payment completion for gateways other than Redsys
        add_action('woocommerce_payment_complete', [$this, 'onPaymentComplete'], 20, 1);
    }

    public function onOrderCreated(WC_Order $order): void
    {
        $this->recomputeAndPersist($order);
    }

    public function onOrderItemsSaved(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order) {
            $this->recomputeAndPersist($order);
        }
    }

    public function onAfterCalculateTotals($andTaxes, $order): void
    {
        if ($order instanceof WC_Order) {
            $this->recomputeAndPersist($order);
        }
    }

    public function onAfterOrderObjectSave(WC_Order $order): void
    {
        $this->recomputeAndPersist($order);
    }

    public function onPaymentComplete(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order) {
            $this->recomputeAndPersist($order);
        }
    }

    private function recomputeAndPersist(WC_Order $order): void
    {
        $orderId = $order->get_id();

        // Avoid duplicate auto logs in the same request when triggered by Admin AJAX or reset
        $skipKey = 'zs_skip_deposits_auto_log_' . $orderId;
        $shouldSkip = (bool) wp_cache_get($skipKey, 'zero-sense');
        if ($shouldSkip) {
            wp_cache_delete($skipKey, 'zero-sense');
            return;
        }

        $orderStatus = $order->get_status();
        $isManualOverride = MetaKeys::isEnabled($order, MetaKeys::IS_MANUAL_OVERRIDE);
        
        // Estados donde se permite recalcular automáticamente
        $recalculableStatuses = ['pending', 'budget-requested'];
        $shouldRecalculate = in_array($orderStatus, $recalculableStatuses, true) && !$isManualOverride;

        // Previous persisted values
        $oldDeposit   = (float) ($order->get_meta(MetaKeys::DEPOSIT_AMOUNT, true) ?: 0);
        $oldRemaining = (float) ($order->get_meta(MetaKeys::REMAINING_AMOUNT, true) ?: 0);

        if ($shouldRecalculate) {
            // Recalcular desde porcentaje
            $info = Utils::recalculateDepositInfo($order);
        } else {
            // Usar valores existentes (preservar depósito)
            $info = Utils::getDepositInfo($order);
        }

        if (empty($info) || ($info['has_deposit'] ?? false) === false) {
            return;
        }

        $newDeposit   = (float) ($info['deposit_amount'] ?? 0);
        $newRemaining = (float) ($info['remaining_amount'] ?? max(0, ($info['order_total'] ?? 0) - ($info['deposit_amount'] ?? 0)));

        // Safety: if in deposit-paid/fully-paid status but deposit is 0, prevent data corruption
        $protectedStatuses = ['deposit-paid', 'fully-paid'];
        if (in_array($orderStatus, $protectedStatuses, true) && $newDeposit <= 0) {
            if ($oldDeposit > 0) {
                // Keep existing deposit, only update remaining
                $newDeposit = $oldDeposit;
                $newRemaining = max(0, ($info['order_total'] ?? 0) - $newDeposit);
            } else {
                // Order is in deposit-paid but has no deposit saved - calculate from percentage
                $recalcInfo = Utils::recalculateDepositInfo($order);
                $newDeposit = (float) ($recalcInfo['deposit_amount'] ?? 0);
                $newRemaining = (float) ($recalcInfo['remaining_amount'] ?? 0);
                
                Logs::add($order, 'auto', [
                    'action' => 'auto_fix_missing_deposit',
                    'order_status' => $orderStatus,
                    'deposit_amount' => wc_format_decimal($newDeposit),
                    'remaining_amount' => wc_format_decimal($newRemaining),
                    'note' => 'Order in deposit-paid status had no deposit saved',
                ]);
            }
        }

        // Check if there are meaningful changes BEFORE saving
        $hasSignificantChange = (abs($oldDeposit - $newDeposit) >= 0.01 || abs($oldRemaining - $newRemaining) >= 0.01);
        
        // First-time persistence: previously zero/empty and now > 0
        $wasEmpty = ($oldDeposit <= 0 && $oldRemaining <= 0);
        $isFirstTime = $wasEmpty && ($newDeposit > 0 || $newRemaining > 0);
        
        // Only save and log if it's first time OR there's a significant change
        if (!$isFirstTime && !$hasSignificantChange) {
            return;
        }

        $data = [
            'deposit_amount'   => $newDeposit,
            'remaining_amount' => $newRemaining,
        ];

        $changed = Utils::saveDepositInfo($order, $data);
        if (!$changed) {
            return;
        }

        $pct = isset($info['deposit_percentage']) ? (float) $info['deposit_percentage'] : null;
        $context = [
            'percentage' => $pct !== null ? wc_format_decimal($pct) : null,
            'deposit_amount' => wc_format_decimal($newDeposit),
            'remaining_amount' => wc_format_decimal($newRemaining),
            'order_status' => $orderStatus,
            'recalculated' => $shouldRecalculate ? 'yes' : 'no',
        ];

        // First-time persistence log
        if ($isFirstTime) {
            $context['action'] = 'auto_initial';
            Logs::add($order, 'auto', $context);
            return;
        }

        // Meaningful change after edits
        if ($hasSignificantChange) {
            if ($shouldRecalculate) {
                $context['action'] = 'auto_recalculate';
            } else {
                $context['action'] = 'auto_preserve_deposit';
            }
            $context['old_deposit_amount'] = wc_format_decimal($oldDeposit);
            $context['old_remaining_amount'] = wc_format_decimal($oldRemaining);
            Logs::add($order, 'auto', $context);
        }
    }
}
