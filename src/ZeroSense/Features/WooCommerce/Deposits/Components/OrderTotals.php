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
        add_action('woocommerce_admin_order_totals_after_total', [$this, 'renderAdminTotals'], 20);
        add_filter('woocommerce_get_order_item_totals', [$this, 'injectFrontendTotals'], 20, 3);
    }

    public function renderAdminTotals(int $orderId): void
    {
        // Only render in Classic WooCommerce, not in HPOS
        // In HPOS, we use the DepositsCalculatorMetabox instead
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id === 'woocommerce_page_wc-orders') {
            return;
        }

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

        $manualOverride = MetaKeys::isEnabled($order, MetaKeys::IS_MANUAL_OVERRIDE);
        $statusAllowsAuto = in_array($order->get_status(), ['pending', 'budget-requested'], true);
        $modeBadgeClass = $manualOverride ? 'is-manual' : 'is-auto';
        $modeBadgeText = $manualOverride ? __('MAN', 'zero-sense') : __('AUTO', 'zero-sense');
        $depositAmount = $depositInfo['deposit_amount'] ?? (float) MetaKeys::get($order, MetaKeys::DEPOSIT_AMOUNT);
        $remainingAmount = $depositInfo['remaining_amount'] ?? (float) MetaKeys::get($order, MetaKeys::REMAINING_AMOUNT);
        ?>
        <tr>
            <td colspan="3" style="border-top:1px solid #eee;"></td>
        </tr>
        <tr>
            <td class="label">
                <span class="zs-deposit-mode-indicator <?php echo esc_attr($modeBadgeClass); ?>">
                    <?php echo esc_html($modeBadgeText); ?>
                </span>
                <?php if ($manualOverride && $statusAllowsAuto) : ?>
                    <button type="button" 
                            class="zs-deposits-reset-to-auto" 
                            data-order-id="<?php echo esc_attr($orderId); ?>"
                            title="<?php esc_attr_e('Reset to automatic calculation', 'zero-sense'); ?>">
                        <span class="dashicons dashicons-update"></span>
                    </button>
                <?php endif; ?>
                <?php esc_html_e('Deposit Amount:', 'zero-sense'); ?>
            </td>
            <td width="1%"></td>
            <td class="total">
                <span class="deposit-amount-display" data-order-id="<?php echo esc_attr($orderId); ?>">
                    <a href="#" class="edit-deposit" data-order-id="<?php echo esc_attr($orderId); ?>" data-amount="<?php echo esc_attr($depositAmount); ?>">
                        <span class="dashicons dashicons-edit" style="font-size:14px;width:14px;height:14px;vertical-align:middle;"></span>
                    </a>
                    <span class="deposit-amount-value"><?php echo wc_price($depositAmount, ['currency' => $order->get_currency()]); ?></span>
                </span>
                <span class="deposit-amount-edit">
                    <input type="text" class="deposit-amount-input" value="<?php echo esc_attr(wc_format_localized_price($depositAmount)); ?>">
                    <input type="hidden" id="zs_deposits_deposit_amount" name="zs_deposits_deposit_amount" value="<?php echo esc_attr(wc_format_localized_price($depositAmount)); ?>">
                    <input type="hidden" id="zs_deposits_deposit_manual_override" name="zs_deposits_deposit_manual_override" value="<?php echo $manualOverride ? 'yes' : 'no'; ?>">
                    <input type="hidden" id="zs_deposits_save_nonce" name="zs_deposits_save_nonce" value="<?php echo wp_create_nonce('zs_deposits_save_deposit_meta'); ?>">
                    <a href="#" class="save-deposit" data-order-id="<?php echo esc_attr($orderId); ?>" style="margin-right:5px;text-decoration:none;">
                        <span class="dashicons dashicons-yes" style="color:#46b450;font-size:16px;width:16px;height:16px;vertical-align:middle;"></span>
                    </a>
                    <a href="#" class="cancel-deposit" style="text-decoration:none;">
                        <span class="dashicons dashicons-no" style="color:#dc3232;font-size:16px;width:16px;height:16px;vertical-align:middle;"></span>
                    </a>
                </span>
            </td>
        </tr>
        <?php if ($remainingAmount > 0) : ?>
            <tr>
                <td class="label"><?php esc_html_e('Balance:', 'zero-sense'); ?></td>
                <td width="1%"></td>
                <td class="total">
                    <span class="remaining-balance-display">
                        <?php echo wc_price($remainingAmount, ['currency' => $order->get_currency()]); ?>
                    </span>
                </td>
            </tr>
        <?php endif; ?>
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
                        'label' => __('Balance:', 'zero-sense'),
                        'value' => wc_price($depositInfo['remaining_amount'], ['currency' => $order->get_currency()]),
                    ];
                }
            }
        }

        return $newRows;
    }
}
