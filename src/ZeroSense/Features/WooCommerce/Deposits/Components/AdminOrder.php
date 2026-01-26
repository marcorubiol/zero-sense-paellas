<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Components;

use WC_Order;
use ZeroSense\Features\WooCommerce\Deposits\Support\Settings;
use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\Deposits\Support\Utils;
use ZeroSense\Features\WooCommerce\Deposits\Support\Logs;

class AdminOrder
{
    private const AJAX_ACTION = 'zs_deposits_update_amount';

    public function register(): void
    {
        if (!is_admin()) {
            return;
        }

        add_filter('wc_order_is_editable', [$this, 'allowEditingStatuses'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handleAjaxUpdate']);
        add_action('wp_ajax_zs_deposits_reset_to_auto', [$this, 'handleResetToAuto']);
        add_action('save_post_shop_order', [$this, 'saveDepositMeta'], 10, 2);
    }
    public function allowEditingStatuses($isEditable, $order)
    {
        if (!$order instanceof WC_Order) {
            return $isEditable;
        }

        $editableStatuses = apply_filters('zs_deposits_editable_statuses', ['budget-requested', 'deposit-paid', 'fully-paid']);
        if (in_array($order->get_status(), $editableStatuses, true)) {
            return true;
        }

        return $isEditable;
    }

    public function enqueueAssets($hook): void
    {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'shop_order') {
            return;
        }

        $assetBase = plugin_dir_url(ZERO_SENSE_FILE) . 'src/ZeroSense/Features/WooCommerce/Deposits/Assets/';

        $assetFsBase = plugin_dir_path(ZERO_SENSE_FILE) . 'src/ZeroSense/Features/WooCommerce/Deposits/Assets/';
        $cssVersion = ZERO_SENSE_VERSION;
        $jsVersion = ZERO_SENSE_VERSION;
        if (is_string($assetFsBase)) {
            $cssFile = $assetFsBase . 'admin-deposits.css';
            $jsFile = $assetFsBase . 'admin-deposits.js';
            if (file_exists($cssFile)) {
                $cssVersion = (string) filemtime($cssFile);
            }
            if (file_exists($jsFile)) {
                $jsVersion = (string) filemtime($jsFile);
            }
        }

        wp_enqueue_style(
            'zs-deposits-admin',
            $assetBase . 'admin-deposits.css',
            [],
            $cssVersion
        );

        wp_enqueue_script(
            'zs-deposits-admin',
            $assetBase . 'admin-deposits.js',
            ['jquery'],
            $jsVersion,
            true
        );

        wp_localize_script(
            'zs-deposits-admin',
            'zsDepositsAdminSettings',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('order-item'),
                'action' => self::AJAX_ACTION,
            ]
        );

        // Provide current order meta (v3) for debugging/visibility in console
        $orderId = isset($_GET['post']) ? absint($_GET['post']) : 0;
        if ($orderId) {
            $order = wc_get_order($orderId);
            if ($order instanceof \WC_Order) {
                $keys = [
                    MetaKeys::HAS_DEPOSIT,
                    MetaKeys::DEPOSIT_AMOUNT,
                    MetaKeys::REMAINING_AMOUNT,
                    MetaKeys::BALANCE_AMOUNT,
                    MetaKeys::IS_MANUAL_OVERRIDE,
                    MetaKeys::DEPOSIT_PERCENTAGE,
                    MetaKeys::IS_DEPOSIT_PAID,
                    MetaKeys::DEPOSIT_PAYMENT_DATE,
                    MetaKeys::IS_BALANCE_PAID,
                    MetaKeys::BALANCE_PAYMENT_DATE,
                    MetaKeys::PAYMENT_FLOW,
                    MetaKeys::IS_CANCELLED,
                    MetaKeys::CANCELLED_DATE,
                    MetaKeys::IS_FAILED,
                    MetaKeys::FAILED_CODE,
                    MetaKeys::FAILED_DATE,
                ];

                $v3 = [];
                foreach ($keys as $k) {
                    $v3[$k] = MetaKeys::get($order, $k, true);
                }

                wp_localize_script(
                    'zs-deposits-admin',
                    'zsDepositsAdminData',
                    [
                        'orderId' => $orderId,
                        'v3' => $v3,
                    ]
                );

                // Back-compat/global debug payload as `zs_data`
                wp_localize_script(
                    'zs-deposits-admin',
                    'zs_data',
                    [
                        'order_id' => $orderId,
                        'v3' => $v3,
                    ]
                );

            }
        }
    }

    public function handleAjaxUpdate(): void
    {
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('Permission denied', 'zero-sense')], 403);
        }

        $nonce = isset($_POST['security']) ? sanitize_key($_POST['security']) : '';
        if (!wp_verify_nonce($nonce, 'order-item')) {
            wp_send_json_error(['message' => __('Invalid security token.', 'zero-sense')], 403);
        }


        $orderId = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if (!$orderId) {
            wp_send_json_error(['message' => __('Invalid order.', 'zero-sense')], 400);
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            wp_send_json_error(['message' => __('Order not found.', 'zero-sense')], 404);
        }


        $mode = isset($_POST['mode']) ? sanitize_key($_POST['mode']) : 'manual';

        $manualOverride = MetaKeys::isEnabled($order, MetaKeys::IS_MANUAL_OVERRIDE);
        $statusAllowsAuto = in_array($order->get_status(), ['pending', 'budget-requested'], true);

        // In non-recalculable states (e.g., deposit-paid, fully-paid), if UI asks for AUTO, do not
        // recalculate nor switch to MAN. Just return current values and keep the badge as AUTO.
        if ($mode === 'auto' && !$statusAllowsAuto) {
            $currencyArgs = ['currency' => $order->get_currency()];
            $depositAmount = (float) MetaKeys::get($order, MetaKeys::DEPOSIT_AMOUNT);
            $remainingAmount = (float) MetaKeys::get($order, MetaKeys::REMAINING_AMOUNT);
            if ($remainingAmount <= 0) {
                $orderTotal = (float) $order->get_total();
                $remainingAmount = max(0, $orderTotal - $depositAmount);
            }

            wp_send_json_success([
                'mode' => 'auto',
                'deposit_amount' => wc_price($depositAmount, $currencyArgs),
                'remaining_amount' => wc_price($remainingAmount, $currencyArgs),
                'formatted_deposit_amount' => wc_format_localized_price($depositAmount),
                'formatted_remaining_amount' => wc_format_localized_price($remainingAmount),
            ]);
        }

        if ($mode === 'auto' && !$manualOverride && $statusAllowsAuto) {
            // Signal Persistence to skip auto logging for this request to avoid duplicates
            $skipKey = 'zs_skip_deposits_auto_log_' . $order->get_id();
            wp_cache_set($skipKey, true, 'zero-sense', 30);
            // Use centralized recalculation method
            $info = Utils::recalculateDepositInfo($order);

            $depositAmount = (float) ($info['deposit_amount'] ?? 0);
            $remainingAmount = (float) ($info['remaining_amount'] ?? 0);
            $balanceAmount = (float) ($info['balance_amount'] ?? 0);
            $percentage = (float) ($info['deposit_percentage'] ?? 0);

            // Read old values to detect change
            $oldDeposit = (float) MetaKeys::get($order, MetaKeys::DEPOSIT_AMOUNT);
            $oldRemaining = (float) MetaKeys::get($order, MetaKeys::REMAINING_AMOUNT);

            MetaKeys::update($order, MetaKeys::HAS_DEPOSIT, $depositAmount > 0 ? 'yes' : 'no');
            MetaKeys::update($order, MetaKeys::DEPOSIT_AMOUNT, $depositAmount);
            MetaKeys::update($order, MetaKeys::REMAINING_AMOUNT, $remainingAmount);
            MetaKeys::update($order, MetaKeys::BALANCE_AMOUNT, $balanceAmount);
            MetaKeys::delete($order, MetaKeys::IS_MANUAL_OVERRIDE);
            $order->save();

            // Log admin auto calculation
            if (abs($oldDeposit - $depositAmount) >= 0.01 || abs($oldRemaining - $remainingAmount) >= 0.01) {
                Logs::add($order, 'admin', [
                    'action' => 'auto_calculate',
                    'percentage' => $percentage,
                    'deposit_amount' => $depositAmount,
                    'remaining_amount' => $remainingAmount,
                ]);
            }

            $currencyArgs = ['currency' => $order->get_currency()];
            wp_send_json_success([
                'mode' => 'auto',
                'deposit_amount' => wc_price($depositAmount, $currencyArgs),
                'remaining_amount' => wc_price($remainingAmount, $currencyArgs),
                'formatted_deposit_amount' => wc_format_localized_price($depositAmount),
                'formatted_remaining_amount' => wc_format_localized_price($remainingAmount),
            ]);
        }

        if ($mode === 'auto' && $manualOverride) {
            $currencyArgs = ['currency' => $order->get_currency()];
            $depositAmount = (float) MetaKeys::get($order, MetaKeys::DEPOSIT_AMOUNT);
            $orderTotal = (float) $order->get_total();
            $remainingAmount = $orderTotal - $depositAmount;
            $balanceAmount = max(0, $orderTotal - $depositAmount);

            MetaKeys::update($order, MetaKeys::REMAINING_AMOUNT, $remainingAmount);
            MetaKeys::update($order, MetaKeys::BALANCE_AMOUNT, $balanceAmount);
            $order->save();

            // Log recompute remaining with manual override
            Logs::add($order, 'admin', [
                'action' => 'recompute_remaining_manual_override',
                'deposit_amount' => $depositAmount,
                'remaining_amount' => $remainingAmount,
            ]);

            wp_send_json_success([
                'mode' => 'manual',
                'deposit_amount' => wc_price($depositAmount, $currencyArgs),
                'remaining_amount' => wc_price($remainingAmount, $currencyArgs),
                'formatted_deposit_amount' => wc_format_localized_price($depositAmount),
                'formatted_remaining_amount' => wc_format_localized_price($remainingAmount),
            ]);
        }

        $depositRaw = isset($_POST['deposit_amount']) ? wc_clean(wp_unslash($_POST['deposit_amount'])) : '';
        $depositAmount = wc_format_decimal($depositRaw);
        if ($depositAmount < 0) {
            $depositAmount = 0;
        }


        $orderTotal = (float) $order->get_total();
        if ($depositAmount > $orderTotal) {
            $depositAmount = $orderTotal;
        }

        $remainingAmount = $orderTotal - $depositAmount;
        $balanceAmount = max(0, $orderTotal - $depositAmount);

        MetaKeys::update($order, MetaKeys::HAS_DEPOSIT, $depositAmount > 0 ? 'yes' : 'no');
        MetaKeys::update($order, MetaKeys::DEPOSIT_AMOUNT, $depositAmount);
        MetaKeys::update($order, MetaKeys::REMAINING_AMOUNT, $remainingAmount);
        MetaKeys::update($order, MetaKeys::BALANCE_AMOUNT, $balanceAmount);
        MetaKeys::update($order, MetaKeys::IS_MANUAL_OVERRIDE, 'yes');
        $order->save();


        // Log manual set
        Logs::add($order, 'admin', [
            'action' => 'manual_set',
            'deposit_amount' => $depositAmount,
            'remaining_amount' => $remainingAmount,
        ]);

        $currencyArgs = ['currency' => $order->get_currency()];

        wp_send_json_success([
            'mode' => 'manual',
            'deposit_amount' => wc_price($depositAmount, $currencyArgs),
            'remaining_amount' => wc_price($remainingAmount, $currencyArgs),
            'formatted_deposit_amount' => wc_format_localized_price($depositAmount),
            'formatted_remaining_amount' => wc_format_localized_price($remainingAmount),
        ]);
    }

    public function saveDepositMeta($postId, $post): void
    {
        if (!$post || $post->post_type !== 'shop_order') {
            return;
        }

        if (!current_user_can('edit_shop_orders')) {
            return;
        }

        $nonce = isset($_POST['zs_deposits_save_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['zs_deposits_save_nonce']))
            : '';

        $wpNonce = isset($_POST['_wpnonce'])
            ? sanitize_text_field(wp_unslash($_POST['_wpnonce']))
            : '';

        $isValidDepositsNonce = ($nonce !== '') && wp_verify_nonce($nonce, 'zs_deposits_save_deposit_meta');
        $isValidWpNonce = ($wpNonce !== '') && wp_verify_nonce($wpNonce, 'update-post_' . (int) $postId);

        if (!$isValidDepositsNonce && !$isValidWpNonce) {
            return;
        }

        $order = wc_get_order($postId);
        if (!$order instanceof WC_Order) {
            return;
        }

        if (isset($_POST['zs_deposits_deposit_amount'])) {
            $depositRaw = wc_clean(wp_unslash($_POST['zs_deposits_deposit_amount']));
            if ($depositRaw !== '' && $depositRaw !== null) {
                $depositAmount = wc_format_decimal($depositRaw);
                if ($depositAmount < 0) {
                    $depositAmount = 0;
                }

                $orderTotal = (float) $order->get_total();
                if ($depositAmount > $orderTotal) {
                    $depositAmount = $orderTotal;
                }

                $remainingAmount = $orderTotal - $depositAmount;
                $balanceAmount = max(0, $orderTotal - $depositAmount);

                MetaKeys::update($order, MetaKeys::DEPOSIT_AMOUNT, $depositAmount);
                MetaKeys::update($order, MetaKeys::REMAINING_AMOUNT, $remainingAmount);
                MetaKeys::update($order, MetaKeys::BALANCE_AMOUNT, $balanceAmount);
                MetaKeys::update($order, MetaKeys::HAS_DEPOSIT, $depositAmount > 0 ? 'yes' : 'no');
            }
        }

        $manualOverride = isset($_POST['zs_deposits_deposit_manual_override'])
            && sanitize_text_field(wp_unslash($_POST['zs_deposits_deposit_manual_override'])) === 'yes';

        if ($manualOverride) {
            MetaKeys::update($order, MetaKeys::IS_MANUAL_OVERRIDE, 'yes');
        }

        $order->save();
    }

    public function handleResetToAuto(): void
    {
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('Permission denied', 'zero-sense')], 403);
        }

        $nonce = isset($_POST['security']) ? sanitize_key($_POST['security']) : '';
        if (!wp_verify_nonce($nonce, 'order-item')) {
            wp_send_json_error(['message' => __('Invalid security token.', 'zero-sense')], 403);
        }

        $orderId = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if (!$orderId) {
            wp_send_json_error(['message' => __('Invalid order.', 'zero-sense')], 400);
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            wp_send_json_error(['message' => __('Order not found.', 'zero-sense')], 404);
        }

        // Only allow reset to auto in recalculable statuses
        if (!in_array($order->get_status(), ['pending', 'budget-requested'], true)) {
            wp_send_json_error(['message' => __('Reset to automatic is only available in Pending or Budget Requested status.', 'zero-sense')], 400);
        }

        // Remove manual override flag
        MetaKeys::delete($order, MetaKeys::IS_MANUAL_OVERRIDE);

        // Recalculate from percentage
        // Signal Persistence to skip auto logging for this request to avoid duplicates
        $skipKey = 'zs_skip_deposits_auto_log_' . $order->get_id();
        wp_cache_set($skipKey, true, 'zero-sense', 30);

        $info = Utils::recalculateDepositInfo($order);

        $depositAmount = (float) ($info['deposit_amount'] ?? 0);
        $remainingAmount = (float) ($info['remaining_amount'] ?? 0);
        $balanceAmount = (float) ($info['balance_amount'] ?? 0);
        $percentage = (float) ($info['deposit_percentage'] ?? 0);

        // Save new values
        MetaKeys::update($order, MetaKeys::HAS_DEPOSIT, $depositAmount > 0 ? 'yes' : 'no');
        MetaKeys::update($order, MetaKeys::DEPOSIT_AMOUNT, $depositAmount);
        MetaKeys::update($order, MetaKeys::REMAINING_AMOUNT, $remainingAmount);
        MetaKeys::update($order, MetaKeys::BALANCE_AMOUNT, $balanceAmount);
        $order->save();

        // Log the reset action
        Logs::add($order, 'admin', [
            'action' => 'reset_to_auto',
            'percentage' => $percentage,
            'deposit_amount' => $depositAmount,
            'remaining_amount' => $remainingAmount,
        ]);

        $currencyArgs = ['currency' => $order->get_currency()];
        wp_send_json_success([
            'message' => __('Deposit reset to automatic calculation', 'zero-sense'),
            'deposit_amount' => wc_price($depositAmount, $currencyArgs),
            'remaining_amount' => wc_price($remainingAmount, $currencyArgs),
        ]);
    }
}
