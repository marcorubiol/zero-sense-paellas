<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Components;

use WC_Order;
use ZeroSense\Features\WooCommerce\Deposits\Support\Logs;
use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\Deposits\Support\Settings;
use ZeroSense\Utilities\LogDeletionTrait;

class DepositsLogMetabox
{
    use LogDeletionTrait;
    public function register(): void
    {
        if (!is_admin()) { return; }
        add_action('add_meta_boxes', [$this, 'addMetabox']);
        add_action('wp_ajax_zs_delete_log_entry', [$this, 'ajaxDeleteLogEntry']);
    }

    public function addMetabox(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            $screen_id = $screen->id === 'woocommerce_page_wc-orders' ? wc_get_page_screen_id('shop-order') : 'shop_order';
            
            add_meta_box(
                'zs_deposits_logs',
                __('Deposits Logs', 'zero-sense'),
                [$this, 'renderMetabox'],
                $screen_id,
                'normal',
                'default'
            );
        }
    }

    /**
     * Determine badge class, label and title based on log type and action
     */
    private function getBadgeInfo(string $type, array $data): array
    {
        $action = $data['action'] ?? '';
        
        // AUTO (Azul) - Sistema automático y resets
        $autoActions = [
            'auto_initial',
            'auto_recalculate',
            'auto_preserve_deposit',
            'auto_fix_missing_deposit',
            'reset_to_auto',
            'auto_calculate',
            'auto_recompute',
        ];
        
        // MAN (Naranja) - Solo cambios manuales del depósito
        $manualActions = [
            'manual_set',
            'save_post_set_amount',
            'save_post_enable_manual_override',
            'recompute_remaining_manual_override',
            'manual_override',
        ];
        
        // Determine by action first, then by type
        if (in_array($action, $autoActions) || $type === 'auto') {
            return [
                'class' => 'zs-badge-auto',
                'item_class' => 'zs-auto',
                'label' => __('AUTO', 'zero-sense'),
                'title' => __('Auto calculation', 'zero-sense'),
            ];
        }
        
        if (in_array($action, $manualActions) || $type === 'admin' || $type === 'manual') {
            return [
                'class' => 'zs-badge-manual',
                'item_class' => 'zs-manual',
                'label' => __('MAN', 'zero-sense'),
                'title' => __('Manual change', 'zero-sense'),
            ];
        }
        
        // Other types
        if ($type === 'gateway') {
            return [
                'class' => 'zs-badge-gateway',
                'item_class' => 'zs-gateway',
                'label' => __('GATEWAY', 'zero-sense'),
                'title' => __('Gateway event', 'zero-sense'),
            ];
        }
        
        if ($type === 'error') {
            return [
                'class' => 'zs-badge-error',
                'item_class' => 'zs-error',
                'label' => __('ERROR', 'zero-sense'),
                'title' => __('Error', 'zero-sense'),
            ];
        }
        
        // Default
        return [
            'class' => 'zs-badge-auto',
            'item_class' => 'zs-auto',
            'label' => __('AUTO', 'zero-sense'),
            'title' => __('Auto calculation', 'zero-sense'),
        ];
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

        $logs = Logs::getForOrder($order);
        // Exclude status logs entirely from Deposits Logs
        $logs = array_values(array_filter($logs, function($log){
            $type = sanitize_key($log['type'] ?? '');
            if ($type === 'status') { return false; }
            $action = is_array($log['data'] ?? null) ? ($log['data']['action'] ?? '') : '';
            $action = is_string($action) ? $action : '';
            $excluded = ['status_update', 'manual_status_to_deposit_paid', 'manual_status_to_fully_paid'];
            return !in_array($action, $excluded, true);
        }));
        $noEvents = empty($logs);

        $orderTotal = (float) $order->get_total();
        $depositAmount = (float) (MetaKeys::get($order, MetaKeys::DEPOSIT_AMOUNT, true) ?: 0);
        $remainingAmount = (float) (MetaKeys::get($order, MetaKeys::REMAINING_AMOUNT, true) ?: max(0, $orderTotal - $depositAmount));

        echo '<div class="zs-deposits-logs-metabox">';

        // Header context (plain text amounts)
        $depositText   = wp_strip_all_tags(wc_price($depositAmount, ['currency'=>$order->get_currency()]));
        $remainingText = wp_strip_all_tags(wc_price($remainingAmount, ['currency'=>$order->get_currency()]));
        $totalText     = wp_strip_all_tags(wc_price($orderTotal, ['currency'=>$order->get_currency()]));

        echo '<div style="margin-bottom:10px; font-size:12px; color:#555;">';
        echo esc_html(sprintf(__('Context — Deposit: %s · Remaining: %s · Total: %s', 'zero-sense'), $depositText, $remainingText, $totalText));
        echo '</div>';

        // Effective percentage (Option A)
        $pctMetaRaw = MetaKeys::get($order, MetaKeys::DEPOSIT_PERCENTAGE, true);
        $pctMeta = is_numeric($pctMetaRaw) ? (float) $pctMetaRaw : null;
        $pctComputed = ($orderTotal > 0 && $depositAmount > 0) ? round(($depositAmount / $orderTotal) * 100) : null;
        $pctGlobal = (float) Settings::getDepositPercentage();

        if ($pctMeta !== null && $pctMeta > 0) {
            $pctText = sprintf(__('Effective deposit %%: %s%% (source: order meta)', 'zero-sense'), wc_format_localized_decimal($pctMeta));
        } elseif ($pctComputed !== null) {
            $pctText = sprintf(__('Effective deposit %%: %s%% (source: computed from amounts)', 'zero-sense'), wc_format_localized_decimal($pctComputed));
        } else {
            $pctText = sprintf(__('Effective deposit %%: %s%% (source: settings)', 'zero-sense'), wc_format_localized_decimal($pctGlobal));
        }

        echo '<div style="margin:-4px 0 10px; font-size:11px; color:#666;">' . esc_html($pctText) . '</div>';

        // Events list (show first 3)
        if ($noEvents) {
            echo '<div style="margin:8px 0 10px; font-size:12px; color:#777;">' . esc_html__('No deposit events for this order yet.', 'zero-sense') . '</div>';
        } else {
            $count = 0; $max = 3; $hasMore = count($logs) > $max;
            
            // Show first 3 logs
            foreach ($logs as $index => $log) {
                if ($index >= $max) break;
                
                $type = sanitize_key($log['type'] ?? 'info');
                $data = is_array($log['data'] ?? null) ? $log['data'] : [];
                
                // Use new badge info system
                $badgeInfo = $this->getBadgeInfo($type, $data);
                $badgeClass = $badgeInfo['class'];
                $itemClass = $badgeInfo['item_class'];
                $title = $badgeInfo['title'];
                $badgeLabel = $badgeInfo['label'];

                $ts = esc_html($log['timestamp'] ?? '');
                $status = esc_html($log['status'] ?? '');
                $data = is_array($log['data'] ?? null) ? $log['data'] : [];
                $by = '';
                if (isset($data['_by']) && is_array($data['_by'])) {
                    $byName = isset($data['_by']['name']) ? (string) $data['_by']['name'] : '';
                    $byLogin = isset($data['_by']['login']) ? (string) $data['_by']['login'] : '';
                    $label = $byName !== '' ? ($byLogin !== '' ? ($byName . ' (' . $byLogin . ')') : $byName) : $byLogin;
                    if ($label !== '') {
                        $by = ' · ' . sprintf(__('By: %s', 'zero-sense'), esc_html($label));
                    }
                }
                // Pretty formatting for details (client-friendly)
                $currency = $order->get_currency();
                $details = [];
                $percent = isset($data['percentage']) && is_numeric($data['percentage'])
                    ? wc_format_localized_decimal((float) $data['percentage']) . '%'
                    : null;
                $depAmt = isset($data['deposit_amount']) && is_numeric($data['deposit_amount'])
                    ? wp_strip_all_tags(wc_price((float) $data['deposit_amount'], ['currency' => $currency]))
                    : null;
                $remAmt = isset($data['remaining_amount']) && is_numeric($data['remaining_amount'])
                    ? wp_strip_all_tags(wc_price((float) $data['remaining_amount'], ['currency' => $currency]))
                    : null;
                $actionRaw = isset($data['action']) ? (string) $data['action'] : '';
                $actionLabelMap = [
                    'auto_initial' => __('Initial calculation', 'zero-sense'),
                    'auto_recalculate' => __('Recalculated automatically', 'zero-sense'),
                    'auto_preserve_deposit' => __('Preserved deposit (adjusted remaining)', 'zero-sense'),
                    'auto_fix_missing_deposit' => __('Fixed missing deposit', 'zero-sense'),
                    'reset_to_auto' => __('Reset to automatic', 'zero-sense'),
                    'manual_override' => __('Manual override', 'zero-sense'),
                ];
                $actionPretty = $actionRaw !== '' ? ($actionLabelMap[$actionRaw] ?? ucfirst(str_replace('_',' ', $actionRaw))) : '';

                if ($percent !== null) { $details[] = sprintf(__('Percentage: %s', 'zero-sense'), esc_html($percent)); }
                if ($depAmt !== null) { $details[] = sprintf(__('Deposit: %s', 'zero-sense'), esc_html($depAmt)); }
                if ($remAmt !== null) { $details[] = sprintf(__('Remaining: %s', 'zero-sense'), esc_html($remAmt)); }
                if ($actionPretty !== '') { $details[] = sprintf(__('Action: %s', 'zero-sense'), esc_html($actionPretty)); }

                echo '<div class="zs-log-item ' . esc_attr($itemClass) . '">';
                $this->renderLogDeleteButton($index, 'zs_deposits_logs', $orderId);
                echo '<div class="zs-log-title"><strong>' . esc_html($title) . '</strong></div>';
                echo '<div class="zs-log-time">' . esc_html($ts) . ' · ' . sprintf(__('Order status: %s', 'zero-sense'), $status) . $by . '</div>';
                if ($details) {
                    echo '<div class="zs-log-details">' . implode(' · ', $details) . '</div>';
                }
                echo '<span class="zs-badge ' . esc_attr($badgeClass) . '">' . esc_html($badgeLabel) . '</span>';
                echo '</div>';
            }
            
            // Show remaining logs (hidden)
            if ($hasMore) {
                echo '<div id="zs-deposits-logs-hidden" style="display:none;">';
                foreach ($logs as $index => $log) {
                    if ($index < $max) continue;
                    
                    $type = sanitize_key($log['type'] ?? 'info');
                    $data = is_array($log['data'] ?? null) ? $log['data'] : [];
                    
                    // Use new badge info system
                    $badgeInfo = $this->getBadgeInfo($type, $data);
                    $badgeClass = $badgeInfo['class'];
                    $itemClass = $badgeInfo['item_class'];
                    $title = $badgeInfo['title'];
                    $badgeLabel = $badgeInfo['label'];

                    $ts = esc_html($log['timestamp'] ?? '');
                    $status = esc_html($log['status'] ?? '');
                    $data = is_array($log['data'] ?? null) ? $log['data'] : [];
                    $by = '';
                    if (isset($data['_by']) && is_array($data['_by'])) {
                        $byName = isset($data['_by']['name']) ? (string) $data['_by']['name'] : '';
                        $byLogin = isset($data['_by']['login']) ? (string) $data['_by']['login'] : '';
                        $label = $byName !== '' ? ($byLogin !== '' ? ($byName . ' (' . $byLogin . ')') : $byName) : $byLogin;
                        if ($label !== '') {
                            $by = ' · ' . sprintf(__('By: %s', 'zero-sense'), esc_html($label));
                        }
                    }
                    // Pretty formatting for details (client-friendly)
                    $currency = $order->get_currency();
                    $details = [];
                    $percent = isset($data['percentage']) && is_numeric($data['percentage'])
                        ? wc_format_localized_decimal((float) $data['percentage']) . '%'
                        : null;
                    $depAmt = isset($data['deposit_amount']) && is_numeric($data['deposit_amount'])
                        ? wp_strip_all_tags(wc_price((float) $data['deposit_amount'], ['currency' => $currency]))
                        : null;
                    $remAmt = isset($data['remaining_amount']) && is_numeric($data['remaining_amount'])
                        ? wp_strip_all_tags(wc_price((float) $data['remaining_amount'], ['currency' => $currency]))
                        : null;
                    $actionRaw = isset($data['action']) ? (string) $data['action'] : '';
                    $actionLabelMap = [
                        'auto_initial' => __('Initial calculation', 'zero-sense'),
                        'auto_recalculate' => __('Recalculated automatically', 'zero-sense'),
                        'auto_preserve_deposit' => __('Preserved deposit (adjusted remaining)', 'zero-sense'),
                        'auto_fix_missing_deposit' => __('Fixed missing deposit', 'zero-sense'),
                        'reset_to_auto' => __('Reset to automatic', 'zero-sense'),
                        'manual_override' => __('Manual override', 'zero-sense'),
                    ];
                    $actionPretty = $actionRaw !== '' ? ($actionLabelMap[$actionRaw] ?? ucfirst(str_replace('_',' ', $actionRaw))) : '';

                    if ($percent !== null) { $details[] = sprintf(__('Percentage: %s', 'zero-sense'), esc_html($percent)); }
                    if ($depAmt !== null) { $details[] = sprintf(__('Deposit: %s', 'zero-sense'), esc_html($depAmt)); }
                    if ($remAmt !== null) { $details[] = sprintf(__('Remaining: %s', 'zero-sense'), esc_html($remAmt)); }
                    if ($actionPretty !== '') { $details[] = sprintf(__('Action: %s', 'zero-sense'), esc_html($actionPretty)); }

                    echo '<div class="zs-log-item ' . esc_attr($itemClass) . '">';
                    $this->renderLogDeleteButton($index, 'zs_deposits_logs', $orderId);
                    echo '<div class="zs-log-title"><strong>' . esc_html($title) . '</strong></div>';
                    echo '<div class="zs-log-time">' . esc_html($ts) . ' · ' . sprintf(__('Order status: %s', 'zero-sense'), $status) . $by . '</div>';
                    if ($details) {
                        echo '<div class="zs-log-details">' . implode(' · ', $details) . '</div>';
                    }
                    echo '<span class="zs-badge ' . esc_attr($badgeClass) . '">' . esc_html($badgeLabel) . '</span>';
                    echo '</div>';
                }
                echo '</div>';
                
                $remainingCount = count($logs) - $max;
                echo '<div style="text-align:center;margin-top:10px;"><button type="button" class="button-link" id="zs-deposits-logs-toggle">' . sprintf(esc_html__('Show %d more', 'zero-sense'), $remainingCount) . '</button></div>';
                echo '<script>document.addEventListener("DOMContentLoaded",function(){var b=document.getElementById("zs-deposits-logs-toggle");if(!b) return;var total=' . esc_js($remainingCount) . ';b.addEventListener("click",function(){var h=document.getElementById("zs-deposits-logs-hidden");if(h.style.display==="none"){h.style.display="block";this.textContent="' . esc_js(__('Show less', 'zero-sense')) . '";}else{h.style.display="none";this.textContent="' . esc_js(__('Show', 'zero-sense')) . ' "+total+" ' . esc_js(__('more', 'zero-sense')) . '";}});});</script>';
            }
        }

        echo '</div>';
        
        $this->enqueueLogDeletionScript();
    }
}
