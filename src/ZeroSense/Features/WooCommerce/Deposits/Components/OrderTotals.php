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
        add_action('woocommerce_admin_order_totals_after_subtotal', [$this, 'renderAdminTotals'], 20);
        add_filter('woocommerce_get_order_item_totals', [$this, 'injectFrontendTotals'], 20, 3);
    }

    public function renderAdminTotals(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        $depositInfo = Utils::getDepositInfo($order);
        $isDepositStatus = in_array($order->get_status(), ['deposit-paid', 'fully-paid'], true);

        if (($depositInfo['has_deposit'] ?? false) === false && !$isDepositStatus) {
            return;
        }

        if ($isDepositStatus && ($depositInfo['deposit_amount'] ?? 0) <= 0) {
            $orderTotal = $order->get_total();
            $percentage = Settings::getDepositPercentage();
            $depositInfo['deposit_amount'] = round(($orderTotal * $percentage) / 100, wc_get_price_decimals());
            $depositInfo['remaining_amount'] = $orderTotal - $depositInfo['deposit_amount'];
        }

        $depositAmount = $depositInfo['deposit_amount'] ?? 0;
        $remainingAmount = $depositInfo['remaining_amount'] ?? 0;
        ?>
        <tr>
            <td class="label"><?php esc_html_e('Deposit Amount:', 'zero-sense'); ?></td>
            <td width="1%"></td>
            <td class="total">
                <?php echo wc_price($depositAmount, ['currency' => $order->get_currency()]); ?>
            </td>
        </tr>
        <?php if ($remainingAmount > 0) : ?>
            <tr>
                <td class="label"><?php esc_html_e('Remaining Balance:', 'zero-sense'); ?></td>
                <td width="1%"></td>
                <td class="total">
                    <?php echo wc_price($remainingAmount, ['currency' => $order->get_currency()]); ?>
                </td>
            </tr>
        <?php endif; ?>
        <script>
        jQuery(document).ready(function($) {
            // Reorder deposit rows to appear before Order Total
            var depositRows = $('tr:has(td.label:contains("Deposit Amount:")), tr:has(td.label:contains("Remaining Balance:"))');
            var orderTotalRow = $('tr:has(td.label:contains("Order Total:"))');
            
            if (depositRows.length && orderTotalRow.length) {
                orderTotalRow.first().before(depositRows);
            }
        });
        </script>
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

        $newRows = [];
        foreach ($rows as $key => $row) {
            $newRows[$key] = $row;
            if ($key === 'order_total') {
                $newRows['deposit_amount'] = [
                    'label' => __('Deposit Amount:', 'zero-sense'),
                    'value' => wc_price($depositInfo['deposit_amount'] ?? 0, ['currency' => $order->get_currency()]),
                ];

                if (($depositInfo['remaining_amount'] ?? 0) > 0) {
                    $newRows['remaining_balance'] = [
                        'label' => __('Remaining Balance:', 'zero-sense'),
                        'value' => wc_price($depositInfo['remaining_amount'], ['currency' => $order->get_currency()]),
                    ];
                }
            }
        }

        return $newRows;
    }
}
