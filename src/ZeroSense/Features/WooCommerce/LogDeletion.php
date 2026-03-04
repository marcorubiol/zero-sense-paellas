<?php
namespace ZeroSense\Features\WooCommerce;

use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Calendar\CalendarLogs;
use ZeroSense\Features\WooCommerce\Deposits\Support\Logs as DepositsLogs;
use ZeroSense\Features\WooCommerce\OrderStatuses\Support\StatusLogs;
use ZeroSense\Features\Integrations\Flowmattic\Flowmattic;

/**
 * Log Deletion Feature
 * 
 * Provides individual log deletion functionality across all Zero Sense log metaboxes:
 * - Calendar Logs
 * - Deposits Logs  
 * - Order Status Logs
 * - Email Logs (FlowMattic)
 * - Holded Logs (FlowMattic)
 */
class LogDeletion
{
    public function register(): void
    {
        if (!is_admin()) { return; }
        
        // Register AJAX handlers
        add_action('wp_ajax_zs_delete_log', [$this, 'ajaxDeleteLog']);
        
        // Enqueue scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }
    
    /**
     * Enqueue JavaScript and pass configuration
     */
    public function enqueueAssets($hook): void
    {
        // Only load on order edit pages
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            return;
        }
        
        // Enqueue JavaScript
        wp_enqueue_script(
            'zs-log-deletion',
            ZERO_SENSE_URL . 'assets/js/log-deletion.js',
            ['jquery'],
            '1.0.0',
            true
        );
        
        // Pass configuration to JavaScript
        wp_localize_script('zs-log-deletion', 'zsLogDeletion', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('zs_delete_log_nonce'),
            'i18n' => [
                'confirmDelete' => __('Delete this log entry? This action cannot be undone.', 'zero-sense'),
                'deleteSuccess' => __('Log deleted successfully.', 'zero-sense'),
                'deleteError' => __('Error deleting log.', 'zero-sense'),
                'ajaxError' => __('Network error. Please try again.', 'zero-sense'),
            ]
        ]);
    }
    
    /**
     * AJAX handler for log deletion
     */
    public function ajaxDeleteLog(): void
    {
        // Verify nonce
        check_ajax_referer('zs_delete_log_nonce', 'nonce');
        
        // Verify permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'zero-sense')]);
        }
        
        // Get parameters
        $logType = sanitize_text_field($_POST['log_type'] ?? '');
        $orderId = absint($_POST['order_id'] ?? 0);
        $logId = sanitize_text_field($_POST['log_id'] ?? '');
        $timestamp = sanitize_text_field($_POST['timestamp'] ?? '');
        
        if (empty($logType) || $orderId === 0) {
            wp_send_json_error(['message' => __('Missing required parameters.', 'zero-sense')]);
        }
        
        // Get order
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            wp_send_json_error(['message' => __('Order not found.', 'zero-sense')]);
        }
        
        // Process deletion based on log type
        $result = $this->processLogDeletion($logType, $order, $logId, $timestamp);
        
        if ($result) {
            wp_send_json_success(['message' => __('Log deleted successfully.', 'zero-sense')]);
        } else {
            wp_send_json_error(['message' => __('Failed to delete log.', 'zero-sense')]);
        }
    }
    
    /**
     * Process log deletion based on type
     */
    private function processLogDeletion(string $logType, WC_Order $order, string $logId = '', string $timestamp = ''): bool
    {
        switch ($logType) {
            case 'calendar':
                return $this->deleteCalendarLog($order, $timestamp);
                
            case 'deposits':
                return $this->deleteDepositsLog($order, $timestamp);
                
            case 'status':
                return $this->deleteStatusLog($order, $timestamp);
                
            case 'email':
                return $this->deleteEmailLog($order, $logId);
                
            case 'holded':
                return $this->deleteHoldedLog($order, $logId);
                
            default:
                return false;
        }
    }
    
    /**
     * Delete Calendar log by timestamp
     */
    private function deleteCalendarLog(WC_Order $order, string $timestamp): bool
    {
        $logs = CalendarLogs::getForOrder($order);
        $updatedLogs = [];
        
        foreach ($logs as $log) {
            if (($log['timestamp'] ?? '') !== $timestamp) {
                $updatedLogs[] = $log;
            }
        }
        
        // Update the meta with filtered logs
        return $order->update_meta_data('zs_calendar_logs', $updatedLogs)->save();
    }
    
    /**
     * Delete Deposits log by timestamp
     */
    private function deleteDepositsLog(WC_Order $order, string $timestamp): bool
    {
        $logs = DepositsLogs::getForOrder($order);
        $updatedLogs = [];
        
        foreach ($logs as $log) {
            if (($log['timestamp'] ?? '') !== $timestamp) {
                $updatedLogs[] = $log;
            }
        }
        
        // Update the meta with filtered logs
        return $order->update_meta_data('zs_deposits_logs', $updatedLogs)->save();
    }
    
    /**
     * Delete Status log by timestamp
     */
    private function deleteStatusLog(WC_Order $order, string $timestamp): bool
    {
        $logs = StatusLogs::getForOrder($order);
        $updatedLogs = [];
        
        foreach ($logs as $log) {
            if (($log['timestamp'] ?? '') !== $timestamp) {
                $updatedLogs[] = $log;
            }
        }
        
        // Update the meta with filtered logs
        return $order->update_meta_data('zs_status_logs', $updatedLogs)->save();
    }
    
    /**
     * Delete Email log by ID (requires FlowMattic integration)
     */
    private function deleteEmailLog(WC_Order $order, string $logId): bool
    {
        // This would need to be implemented based on how FlowMattic stores email logs
        // For now, we'll assume logs are stored in order meta and can be filtered
        $emailLogs = $this->getEmailLogsForOrder($order->get_id());
        $updatedLogs = [];
        
        foreach ($emailLogs as $log) {
            if (($log['id'] ?? '') !== $logId) {
                $updatedLogs[] = $log;
            }
        }
        
        // Update the meta with filtered logs
        return $order->update_meta_data('zs_email_logs', $updatedLogs)->save();
    }
    
    /**
     * Delete Holded log by ID (requires FlowMattic integration)
     */
    private function deleteHoldedLog(WC_Order $order, string $logId): bool
    {
        // This would need to be implemented based on how FlowMattic stores holded logs
        // For now, we'll assume logs are stored in order meta and can be filtered
        $holdedLogs = $this->getHoldedLogsForOrder($order->get_id());
        $updatedLogs = [];
        
        foreach ($holdedLogs as $log) {
            if (($log['id'] ?? '') !== $logId) {
                $updatedLogs[] = $log;
            }
        }
        
        // Update the meta with filtered logs
        return $order->update_meta_data('zs_holded_logs', $updatedLogs)->save();
    }
    
    /**
     * Get email logs for an order (helper method)
     */
    private function getEmailLogsForOrder(int $orderId): array
    {
        // This should match the logic in Flowmattic::getEmailLogsForOrder()
        // For now, return empty array - will be implemented when we modify Flowmattic class
        return [];
    }
    
    /**
     * Get holded logs for an order (helper method)
     */
    private function getHoldedLogsForOrder(int $orderId): array
    {
        // This should match the logic in Flowmattic::getHoldedLogsForOrder()
        // For now, return empty array - will be implemented when we modify Flowmattic class
        return [];
    }
}
