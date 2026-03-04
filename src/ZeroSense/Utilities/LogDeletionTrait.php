<?php
namespace ZeroSense\Utilities;

trait LogDeletionTrait
{
    /**
     * Check if log deletion feature is enabled
     *
     * @return bool
     */
    private function isLogDeletionEnabled(): bool
    {
        return (bool) get_option('zs_utilities_logdeletion', true);
    }

    /**
     * Render delete button for a log entry
     *
     * @param int $logIndex The index of the log entry in the array
     * @param string $metaKey The meta key where logs are stored
     * @param int $orderId The order ID
     * @param string|null $uniqueId Optional unique identifier for complex logs (e.g., timestamp+workflow_id)
     */
    protected function renderLogDeleteButton(int $logIndex, string $metaKey, int $orderId, ?string $uniqueId = null): void
    {
        if (!$this->isLogDeletionEnabled()) {
            return;
        }

        $dataAttr = $uniqueId !== null 
            ? 'data-unique-id="' . esc_attr($uniqueId) . '"'
            : 'data-log-index="' . esc_attr($logIndex) . '"';
        
        echo '<button type="button" class="zs-log-delete-btn" '
            . 'data-order-id="' . esc_attr($orderId) . '" '
            . 'data-meta-key="' . esc_attr($metaKey) . '" '
            . $dataAttr
            . ' title="' . esc_attr__('Delete log entry', 'zero-sense') . '">'
            . '×'
            . '</button>';
    }

    /**
     * AJAX handler to delete a log entry
     */
    public function ajaxDeleteLogEntry(): void
    {
        // Check if feature is enabled
        if (!$this->isLogDeletionEnabled()) {
            wp_send_json_error(['message' => __('Log deletion is disabled', 'zero-sense')]);
        }

        // Verify nonce
        if (!check_ajax_referer('zs_delete_log_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'zero-sense')]);
        }

        // Verify permissions
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'zero-sense')]);
        }

        $orderId = absint($_POST['order_id'] ?? 0);
        $metaKey = sanitize_text_field($_POST['meta_key'] ?? '');
        $logIndex = isset($_POST['log_index']) ? absint($_POST['log_index']) : null;
        $uniqueId = isset($_POST['unique_id']) ? sanitize_text_field($_POST['unique_id']) : null;

        if ($orderId === 0 || $metaKey === '') {
            wp_send_json_error(['message' => __('Invalid parameters', 'zero-sense')]);
        }

        // Special handling for workflow executions (stored as global option)
        if ($metaKey === 'zs_workflow_executions') {
            $this->deleteWorkflowExecution($orderId, $uniqueId);
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found', 'zero-sense')]);
        }

        // Get current logs
        $logs = $order->get_meta($metaKey, true);
        if (!is_array($logs)) {
            $logs = [];
        }
        
        if (empty($logs)) {
            wp_send_json_error(['message' => __('No logs found', 'zero-sense')]);
        }

        // Delete by index or unique ID
        if ($uniqueId !== null) {
            // For complex logs (e.g., workflow executions), find by unique ID
            $logs = array_values(array_filter($logs, function($log) use ($uniqueId) {
                $logUniqueId = $this->generateUniqueId($log);
                return $logUniqueId !== $uniqueId;
            }));
        } elseif ($logIndex !== null) {
            // For simple logs, delete by index
            if (!isset($logs[$logIndex])) {
                wp_send_json_error(['message' => __('Log entry not found', 'zero-sense')]);
            }
            unset($logs[$logIndex]);
            $logs = array_values($logs); // Reindex array
        } else {
            wp_send_json_error(['message' => __('Invalid deletion parameters', 'zero-sense')]);
        }

        // Update meta
        $order->update_meta_data($metaKey, $logs);
        $order->save_meta_data();

        wp_send_json_success(['message' => __('Log entry deleted', 'zero-sense')]);
    }

    /**
     * Delete a workflow execution from global option
     *
     * @param int $orderId Order ID
     * @param string|null $uniqueId Unique identifier
     */
    private function deleteWorkflowExecution(int $orderId, ?string $uniqueId): void
    {
        if ($uniqueId === null) {
            wp_send_json_error(['message' => __('Invalid deletion parameters', 'zero-sense')]);
        }

        $executions = get_option('zs_flowmattic_workflow_executions', []);
        if (!is_array($executions)) {
            $executions = [];
        }

        if (empty($executions)) {
            wp_send_json_error(['message' => __('No logs found', 'zero-sense')]);
        }

        // Filter out the execution with matching unique ID and order ID
        $filtered = array_values(array_filter($executions, function($execution) use ($uniqueId, $orderId) {
            // Check if this execution belongs to the order
            if ((int) ($execution['order_id'] ?? 0) !== $orderId) {
                return true; // Keep executions from other orders
            }
            
            // Generate unique ID for this execution using ORIGINAL timestamp format
            // The stored execution has timestamp as string, but rendered logs have it as Unix timestamp
            $workflowId = $execution['workflow_id'] ?? '';
            $timestamp = $execution['timestamp'] ?? '';
            
            // Convert timestamp to Unix timestamp if it's a string date
            if (is_string($timestamp) && !is_numeric($timestamp)) {
                $timestamp = strtotime($timestamp);
            }
            
            $execUniqueId = $timestamp . '_' . $workflowId;
            return $execUniqueId !== $uniqueId; // Remove if IDs match
        }));

        // Update option
        update_option('zs_flowmattic_workflow_executions', $filtered);

        wp_send_json_success(['message' => __('Log entry deleted', 'zero-sense')]);
    }

    /**
     * Generate unique ID for a log entry (for complex logs like workflow executions)
     *
     * @param array $log The log entry
     * @return string Unique identifier
     */
    protected function generateUniqueId(array $log): string
    {
        // For workflow executions: timestamp + workflow_id
        if (isset($log['timestamp']) && isset($log['workflow_id'])) {
            return $log['timestamp'] . '_' . $log['workflow_id'];
        }
        
        // For other logs: timestamp + type
        if (isset($log['timestamp'])) {
            $type = $log['type'] ?? 'unknown';
            return $log['timestamp'] . '_' . $type;
        }
        
        // Fallback: serialize the log (not ideal but works)
        return md5(serialize($log));
    }

    /**
     * Enqueue deletion script and nonce
     */
    protected function enqueueLogDeletionScript(): void
    {
        if (!$this->isLogDeletionEnabled()) {
            return;
        }

        static $enqueued = false;
        if ($enqueued) {
            return;
        }
        $enqueued = true;

        ?>
        <script>
        (function() {
            if (window.zsLogDeletionInitialized) return;
            window.zsLogDeletionInitialized = true;

            document.addEventListener('click', function(e) {
                if (!e.target.classList.contains('zs-log-delete-btn')) return;
                
                e.preventDefault();
                e.stopPropagation();
                
                if (!confirm('<?php echo esc_js(__('Delete this log entry? This action cannot be undone.', 'zero-sense')); ?>')) {
                    return;
                }
                
                const btn = e.target;
                const orderId = btn.getAttribute('data-order-id');
                const metaKey = btn.getAttribute('data-meta-key');
                const logIndex = btn.getAttribute('data-log-index');
                const uniqueId = btn.getAttribute('data-unique-id');
                const logItem = btn.closest('.zs-log-item');
                
                btn.disabled = true;
                btn.textContent = '...';
                
                const formData = new FormData();
                formData.append('action', 'zs_delete_log_entry');
                formData.append('nonce', '<?php echo wp_create_nonce('zs_delete_log_nonce'); ?>');
                formData.append('order_id', orderId);
                formData.append('meta_key', metaKey);
                if (logIndex !== null) {
                    formData.append('log_index', logIndex);
                }
                if (uniqueId !== null) {
                    formData.append('unique_id', uniqueId);
                }
                
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        // Fade out and remove
                        if (logItem) {
                            logItem.style.opacity = '0';
                            logItem.style.transition = 'opacity 0.3s ease';
                            setTimeout(() => {
                                logItem.remove();
                            }, 300);
                        }
                    } else {
                        alert(res.data?.message || '<?php echo esc_js(__('Error deleting log entry', 'zero-sense')); ?>');
                        btn.disabled = false;
                        btn.textContent = '×';
                    }
                })
                .catch(err => {
                    console.error('Delete log error:', err);
                    alert('<?php echo esc_js(__('Error deleting log entry', 'zero-sense')); ?>');
                    btn.disabled = false;
                    btn.textContent = '×';
                });
            });
        })();
        </script>
        <?php
    }
}
