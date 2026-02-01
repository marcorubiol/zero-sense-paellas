<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Components;

use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\Deposits\Support\Settings;
use ZeroSense\Features\WooCommerce\Deposits\Support\Utils;
use WC_Order;

class OrderTotals
{
    public function register(): void
    {
        // Hook for both Classic WooCommerce and HPOS - Add deposit info BEFORE order total
        add_action('woocommerce_admin_order_totals_after_tax', [$this, 'renderAdminTotals'], 20);
        // Additional hook for HPOS - Add deposit info BEFORE order total
        add_action('woocommerce_admin_order_totals_after_order_total', [$this, 'renderAdminTotals'], 10);
        // Hook to display order total at the end
        add_action('woocommerce_admin_order_totals_after_order_total', [$this, 'renderOrderTotalAtEnd'], 30);
        add_filter('woocommerce_get_order_item_totals', [$this, 'injectFrontendTotals'], 20, 3);
    }

    public function renderAdminTotals(int $orderId): void
    {
        // Debug log
        error_log('ZS Deposits: renderAdminTotals called for order ID: ' . $orderId);
        
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            error_log('ZS Deposits: Order not found for ID: ' . $orderId);
            return;
        }

        $depositInfo = Utils::getDepositInfo($order);
        $isDepositStatus = in_array($order->get_status(), ['deposit-paid', 'fully-paid'], true);

        error_log('ZS Deposits: Deposit info - ' . print_r($depositInfo, true));

        if (($depositInfo['has_deposit'] ?? false) === false && !$isDepositStatus) {
            error_log('ZS Deposits: No deposit found, skipping display');
            return;
        }

        if ($isDepositStatus && ($depositInfo['deposit_amount'] ?? 0) <= 0) {
            $orderTotal = $order->get_total();
            $percentage = Settings::getDepositPercentage();
            $depositInfo['deposit_amount'] = round(($orderTotal * $percentage) / 100, wc_get_price_decimals());
            $depositInfo['remaining_amount'] = $orderTotal - $depositInfo['deposit_amount'];
        }

        $depositAmount = $depositInfo['deposit_amount'] ?? 0;
        $balanceAmount = (float) MetaKeys::get($order, MetaKeys::BALANCE_AMOUNT);
        if ($balanceAmount <= 0) {
            $balanceAmount = max(0, (float) $order->get_total() - (float) $depositAmount);
        }
        ?>
        <tr>
            <td class="label"><?php esc_html_e('Deposit Amount:', 'zero-sense'); ?></td>
            <td width="1%"></td>
            <td class="total">
                <?php echo wc_price($depositAmount, ['currency' => $order->get_currency()]); ?>
            </td>
        </tr>
        <tr>
            <td class="label"><?php esc_html_e('Remaining Balance:', 'zero-sense'); ?></td>
            <td width="1%"></td>
            <td class="total">
                <?php echo wc_price($balanceAmount, ['currency' => $order->get_currency()]); ?>
            </td>
        </tr>
        <?php
    }

    public function renderOrderTotalAtEnd(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        $depositInfo = Utils::getDepositInfo($order);
        $isDepositStatus = in_array($order->get_status(), ['deposit-paid', 'fully-paid'], true);

        // Only show order total at the end if there's deposit info
        if (($depositInfo['has_deposit'] ?? false) === false && !$isDepositStatus) {
            return;
        }
        ?>
        <tr>
            <td class="label"><?php esc_html_e('Order Total:', 'zero-sense'); ?></td>
            <td width="1%"></td>
            <td class="total">
                <?php echo wc_price($order->get_total(), ['currency' => $order->get_currency()]); ?>
            </td>
        </tr>
        <?php
    }

    public function injectFrontendTotals(array $rows, WC_Order $order, string $taxDisplay): array
    {
        if (!$order instanceof WC_Order) {
            return $rows;
        }

        $depositInfo = Utils::getDepositInfo($order);
        $isDepositStatus = in_array($order->get_status(), ['deposit-paid', 'fully-paid'], true);

        if (($depositInfo['has_deposit'] ?? false) === false && !$isDepositStatus) {
            return $rows;
        }

        if ($isDepositStatus && ($depositInfo['deposit_amount'] ?? 0) <= 0) {
            $orderTotal = $order->get_total();
            $percentage = Settings::getDepositPercentage();
            $depositInfo['deposit_amount'] = round(($orderTotal * $percentage) / 100, wc_get_price_decimals());
            $depositInfo['remaining_amount'] = $orderTotal - $depositInfo['deposit_amount'];
        }

        $depositAmount = (float) ($depositInfo['deposit_amount'] ?? 0);
        $balanceAmount = (float) MetaKeys::get($order, MetaKeys::BALANCE_AMOUNT);
        if ($balanceAmount <= 0) {
            $balanceAmount = max(0, (float) $order->get_total() - $depositAmount);
        }

        $newRows = [];
        foreach ($rows as $key => $row) {
            $newRows[$key] = $row;
            if ($key === 'order_total') {
                $newRows['deposit_amount'] = [
                    'label' => __('Deposit Amount:', 'zero-sense'),
                    'value' => wc_price($depositAmount, ['currency' => $order->get_currency()]),
                ];

                $newRows['balance'] = [
                    'label' => __('Remaining Balance:', 'zero-sense'),
                    'value' => wc_price($balanceAmount, ['currency' => $order->get_currency()]),
                ];
            }
        }

        return $newRows;
    }
}
