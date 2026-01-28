<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Components;

use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\Deposits\Support\Settings;
use ZeroSense\Features\WooCommerce\Deposits\Support\Utils;
use WC_Order;

class DepositsCalculatorMetabox
{
    public function register(): void
    {
        if (!is_admin()) {
            return;
        }
        add_action('add_meta_boxes', [$this, 'addMetabox']);
    }

    public function addMetabox(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            $screen_id = $screen->id === 'woocommerce_page_wc-orders' ? wc_get_page_screen_id('shop-order') : 'shop_order';
            
            add_meta_box(
                'zs_deposits_calculator',
                __('Deposit Calculator', 'zero-sense'),
                [$this, 'renderMetabox'],
                $screen_id,
                'side',
                'high'
            );
        }
    }

    public function renderMetabox($postOrOrder): void
    {
        $orderId = 0;
        if ($postOrOrder instanceof \WP_Post) {
            $orderId = $postOrOrder->ID;
        } elseif ($postOrOrder instanceof WC_Order) {
            $orderId = $postOrOrder->get_id();
        }
        
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            echo '<p>' . esc_html__('Invalid order.', 'zero-sense') . '</p>';
            return;
        }

        $depositInfo = Utils::getDepositInfo($order);
        $isDepositStatus = in_array($order->get_status(), ['deposit-paid', 'fully-paid'], true);

        if (($depositInfo['has_deposit'] ?? false) === false && !$isDepositStatus) {
            echo '<p style="color:#666;font-size:12px;">' . esc_html__('This order does not have deposits enabled.', 'zero-sense') . '</p>';
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
        $modeBadgeClass = $manualOverride ? 'zs-badge-manual' : 'zs-badge-auto';
        $modeBadgeText = $manualOverride ? __('MAN', 'zero-sense') : __('AUTO', 'zero-sense');
        $depositAmount = $depositInfo['deposit_amount'] ?? (float) MetaKeys::get($order, MetaKeys::DEPOSIT_AMOUNT);
        $remainingAmount = $depositInfo['remaining_amount'] ?? (float) MetaKeys::get($order, MetaKeys::REMAINING_AMOUNT);
        $orderTotal = $order->get_total();
        ?>
        <div class="zs-deposits-calculator-wrapper">
            <div class="zs-deposits-header">
                <?php if ($manualOverride && $statusAllowsAuto) : ?>
                    <button type="button" 
                            class="zs-deposits-reset-btn" 
                            data-order-id="<?php echo esc_attr($orderId); ?>"
                            title="<?php esc_attr_e('Reset to automatic calculation', 'zero-sense'); ?>">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Reset to Auto', 'zero-sense'); ?>
                    </button>
                <?php endif; ?>
                <span class="zs-badge <?php echo esc_attr($modeBadgeClass); ?>"><?php echo esc_html($modeBadgeText); ?></span>
            </div>

            <table class="zs-deposits-table">
                <tbody>
                    <tr class="zs-deposit-row">
                        <td class="label"><?php esc_html_e('Deposit Amount:', 'zero-sense'); ?></td>
                        <td class="total">
                            <div class="zs-deposit-display">
                                <span class="zs-amount-text"><?php echo wc_price($depositAmount, ['currency' => $order->get_currency()]); ?></span>
                                <a href="#" class="zs-edit-link">
                                    <span class="dashicons dashicons-edit"></span>
                                </a>
                            </div>
                            <div class="zs-deposit-edit" style="display:none;">
                                <input type="number" 
                                       class="zs-deposit-input" 
                                       step="0.01" 
                                       value="<?php echo esc_attr($depositAmount); ?>"
                                       style="width:100%;margin-bottom:4px;">
                                <div class="zs-edit-buttons">
                                    <a href="#" class="zs-save-btn" data-order-id="<?php echo esc_attr($orderId); ?>">
                                        <span class="dashicons dashicons-yes"></span>
                                    </a>
                                    <a href="#" class="zs-cancel-btn">
                                        <span class="dashicons dashicons-no"></span>
                                    </a>
                                </div>
                            </div>
                            <input type="hidden" class="zs-deposit-amount-hidden" value="<?php echo esc_attr($depositAmount); ?>">
                            <input type="hidden" class="zs-deposit-nonce-hidden" value="<?php echo wp_create_nonce('order-item'); ?>">
                        </td>
                    </tr>
                    <?php if ($remainingAmount > 0) : ?>
                        <tr class="zs-balance-row">
                            <td class="label"><?php esc_html_e('Balance:', 'zero-sense'); ?></td>
                            <td class="total">
                                <span class="zs-balance-text"><?php echo wc_price($remainingAmount, ['currency' => $order->get_currency()]); ?></span>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr class="zs-order-total-row">
                        <td class="label"><?php esc_html_e('Order Total:', 'zero-sense'); ?></td>
                        <td class="total">
                            <strong><?php echo wc_price($orderTotal, ['currency' => $order->get_currency()]); ?></strong>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php if ($statusAllowsAuto) : ?>
                <div class="zs-recalculate-wrapper">
                    <button type="button" class="button button-primary zs-deposits-recalculate" data-order-id="<?php echo esc_attr($orderId); ?>" style="width:100%;">
                        <?php esc_html_e('Recalculate', 'zero-sense'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <style>
            .zs-deposits-calculator-wrapper {
                margin: -12px -12px 0;
            }
            .zs-deposits-header {
                display: flex;
                align-items: center;
                justify-content: flex-end;
                gap: 8px;
                padding: 8px 12px;
                border-bottom: 1px solid #f0f0f1;
                position: relative;
            }
            .zs-deposits-table {
                width: 100%;
                border-collapse: collapse;
            }
            .zs-deposits-table tr {
                border-bottom: 1px solid #f0f0f1;
            }
            .zs-deposits-table tr:last-child {
                border-bottom: none;
            }
            .zs-deposits-table td {
                padding: 12px;
                font-size: 13px;
            }
            .zs-deposits-table td.label {
                font-weight: 600;
                color: #50575e;
                width: 50%;
            }
            .zs-deposits-table td.total {
                text-align: right;
                font-weight: 600;
                color: #2c3338;
            }
            .zs-order-total-row {
                border-top: 1px solid #ddd !important;
            }
            .zs-order-total-row td.total {
                color: #2271b1;
            }
            .zs-deposits-reset-btn {
                background: none;
                border: none;
                padding: 4px 8px;
                cursor: pointer;
                color: #2271b1;
                font-size: 11px;
                display: flex;
                align-items: center;
                gap: 4px;
                border-radius: 3px;
                transition: all 0.2s ease;
            }
            .zs-deposits-reset-btn:hover {
                color: #135e96;
                background: rgba(34, 113, 177, 0.1);
            }
            .zs-deposits-reset-btn .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
            }
            .zs-deposit-display {
                display: flex;
                align-items: center;
                justify-content: flex-end;
                gap: 6px;
            }
            .zs-amount-text {
                color: #2271b1;
            }
            .zs-edit-link {
                text-decoration: none;
                color: #2271b1;
                display: inline-flex;
                align-items: center;
            }
            .zs-edit-link:hover {
                color: #135e96;
            }
            .zs-edit-link .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
            }
            .zs-deposit-input {
                padding: 4px 8px;
                border: 1px solid #8c8f94;
                border-radius: 2px;
                font-size: 13px;
            }
            .zs-edit-buttons {
                display: flex;
                gap: 6px;
                justify-content: flex-end;
            }
            .zs-save-btn,
            .zs-cancel-btn {
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                cursor: pointer;
            }
            .zs-save-btn .dashicons {
                color: #00a32a;
                font-size: 20px;
                width: 20px;
                height: 20px;
            }
            .zs-cancel-btn .dashicons {
                color: #d63638;
                font-size: 20px;
                width: 20px;
                height: 20px;
            }
            .zs-recalculate-wrapper {
                padding: 12px;
                border-top: 1px solid #f0f0f1;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Edit deposit amount
            $('.zs-edit-link').on('click', function(e) {
                e.preventDefault();
                var $row = $(this).closest('td');
                $row.find('.zs-deposit-display').hide();
                $row.find('.zs-deposit-edit').show();
                $row.find('.zs-deposit-input').focus().select();
            });

            // Cancel edit
            $('.zs-cancel-btn').on('click', function(e) {
                e.preventDefault();
                var $row = $(this).closest('td');
                $row.find('.zs-deposit-edit').hide();
                $row.find('.zs-deposit-display').show();
            });

            // Save deposit amount
            $('.zs-save-btn').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $row = $btn.closest('td');
                var orderId = $btn.data('order-id');
                var newAmount = parseFloat($row.find('.zs-deposit-input').val());
                var nonce = $row.find('.zs-deposit-nonce-hidden').val();
                
                console.log('Saving deposit:', {orderId: orderId, amount: newAmount, nonce: nonce});
                
                // Disable button during save
                $btn.css('opacity', '0.5').css('pointer-events', 'none');
                
                // Use the correct AJAX action from AdminOrder
                $.post(ajaxurl, {
                    action: 'zs_deposits_update_amount',
                    order_id: orderId,
                    deposit_amount: newAmount,
                    mode: 'manual',
                    security: nonce
                }, function(response) {
                    console.log('Save response:', response);
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error saving deposit: ' + (response.data ? response.data.message : 'Unknown error'));
                        $btn.css('opacity', '1').css('pointer-events', 'auto');
                    }
                }).fail(function(xhr, status, error) {
                    console.error('AJAX error:', {xhr: xhr, status: status, error: error});
                    alert('Error saving deposit. Check console for details.');
                    $btn.css('opacity', '1').css('pointer-events', 'auto');
                });
            });

            // Reset to auto
            $('.zs-deposits-reset-btn').on('click', function(e) {
                e.preventDefault();
                var orderId = $(this).data('order-id');
                var nonce = $('.zs-deposit-nonce-hidden').val();
                
                console.log('Resetting to auto:', {orderId: orderId});
                
                $.post(ajaxurl, {
                    action: 'zs_deposits_reset_to_auto',
                    order_id: orderId,
                    security: nonce
                }, function(response) {
                    console.log('Reset response:', response);
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error resetting: ' + (response.data ? response.data.message : 'Unknown error'));
                    }
                });
            });

            // Recalculate (uses auto mode)
            $('.zs-deposits-recalculate').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var orderId = $btn.data('order-id');
                var nonce = $('.zs-deposit-nonce-hidden').val();
                
                console.log('Recalculating:', {orderId: orderId});
                
                $btn.prop('disabled', true).text('<?php esc_html_e('Calculating...', 'zero-sense'); ?>');
                
                // Use the same action but with mode=auto
                $.post(ajaxurl, {
                    action: 'zs_deposits_update_amount',
                    order_id: orderId,
                    mode: 'auto',
                    security: nonce
                }, function(response) {
                    console.log('Recalculate response:', response);
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error recalculating: ' + (response.data ? response.data.message : 'Unknown error'));
                        $btn.prop('disabled', false).text('<?php esc_html_e('Recalculate', 'zero-sense'); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
}
