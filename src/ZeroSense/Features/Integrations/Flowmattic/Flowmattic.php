<?php
namespace ZeroSense\Features\Integrations\Flowmattic;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Core\Logger;

class Flowmattic implements FeatureInterface
{
    private const WORKFLOW_EXECUTIONS_OPTION = 'zs_flowmattic_workflow_executions';
    
    // Email send status constants (public so runtime can reference them)
    public const EMAIL_STATUS_AUTO = 'auto';     // Automatically sent via status transition
    public const EMAIL_STATUS_MANUAL = 'manual'; // Manually sent via button
    public const EMAIL_STATUS_ERROR = 'error';   // Workflow triggered but email failed
    public const EMAIL_STATUS_SKIPPED = 'skipped'; // Not sent because send_once rule prevented it
    
    // Workflow execution status constants (generic, reusable)
    public const WORKFLOW_STATUS_AUTO = 'auto';
    public const WORKFLOW_STATUS_MANUAL = 'manual';
    public const WORKFLOW_STATUS_ERROR = 'error';
    public const WORKFLOW_STATUS_SKIPPED = 'skipped';

    public function getName(): string
    {
        return __('Flowmattic Integration', 'zero-sense');
    }

    /**
     * AJAX: Return the latest rendered email log item HTML for an order
     */
    public function ajaxGetLatestEmailLog(): void
    {
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error('forbidden');
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'zs_manual_email_nonce')) {
            wp_send_json_error('bad_nonce');
        }

        $orderId = isset($_POST['order_id']) ? intval(wp_unslash($_POST['order_id'])) : 0;
        if ($orderId <= 0) {
            wp_send_json_error('bad_params');
        }

        $logs = $this->getEmailLogsForOrder($orderId);
        if (empty($logs)) {
            wp_send_json_success(['html' => '']);
        }
        // newest first
        usort($logs, function($a, $b) { return $b['timestamp'] <=> $a['timestamp']; });
        $latest = $logs[0];

        ob_start();
        $this->renderSingleEmailLog($latest, false);
        $html = ob_get_clean();
        wp_send_json_success(['html' => $html]);
    }

    public function getDescription(): string
    {
        return __('Comprehensive automation system with status transitions, class actions, and real-time workflow execution through Flowmattic integration.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'Integrations';
    }

    public function isToggleable(): bool
    {
        // Flowmattic integration must always be active (no toggle)
        return false;
    }

    public function isEnabled(): bool
    {
        // Always enabled
        return true;
    }


    public function getPriority(): int
    {
        return 20;
    }

    public function getConditions(): array
    {
        return [];
    }

    // Configuration: show static reference of workflow IDs and actions in the settings card
    public function hasConfiguration(): bool
    {
        return true;
    }

    public function getConfigurationFields(): array
    {
        // Build HTML with workflow IDs sourced from Integration class
        $html = '';
        try {
            // Backend-only configuration: no hardcoded IDs. We only render stored triggers below.

            // CRUD UI: Add new mapping
            // Status options (pulled dynamically from WooCommerce)
            $statusOptions = $this->getStatusOptions();

            $manualActions = [
                'send_reminder' => __('Send Reminder', 'zero-sense'),
                'send_ultimatum' => __('Send Ultimatum', 'zero-sense'),
                'send_form_received_confirmation' => __('Form Received Confirmation', 'zero-sense'),
                'send_budget_proposal' => __('Budget Proposal', 'zero-sense'),
                'send_bank_details_information' => __('Bank Details Information', 'zero-sense'),
                'send_deposit_paid_confirmation' => __('Deposit Paid Confirmation', 'zero-sense'),
                'send_fully_paid_confirmation_direct' => __('Fully Paid Confirmation — Direct', 'zero-sense'),
                'send_fully_paid_confirmation_after_deposit' => __('Fully Paid Confirmation — After Deposit', 'zero-sense'),
                'completed' => __('Completed', 'zero-sense'),
            ];

            $html .= '<div class="zs-flowmattic-config"'
                . '<h5 style="margin:0 0 8px;">' . esc_html__('Add new Flowmattic sync', 'zero-sense') . '</h5>'
                . '<div class="zs-grid zs-flow-inline-form" style="grid-template-columns:1fr 1fr 1fr 1fr 1fr;align-items:end;gap:6px;">'
                . '<div><label class="zs-config-label">' . esc_html__('Action Type', 'zero-sense') . '</label>'
                . '<select class="zs-config-input" id="zs-flow-tag">'
                . '<option value="status">' . esc_html__('Status Transition', 'zero-sense') . '</option>'
                . '<option value="class">' . esc_html__('Class Action', 'zero-sense') . '</option>'
                . '</select></div>'
                . '<div><label class="zs-config-label">' . esc_html__('Name', 'zero-sense') . '</label>'
                . '<input type="text" class="zs-config-input" id="zs-flow-title" placeholder="' . esc_attr__('Short label', 'zero-sense') . '" /></div>'
                . '<div><label class="zs-config-label">' . esc_html__('Workflow ID', 'zero-sense') . '</label>'
                . '<input type="text" class="zs-config-input" id="zs-flow-id" placeholder="xxxxxxxxxx" minlength="10" maxlength="10" pattern="[A-Za-z0-9]{10}" title="10 chars: letters and numbers" /></div>'
                // Status fields (visible by default)
                . '<div id="zs-flow-from-container"><label class="zs-config-label">' . esc_html__('From status', 'zero-sense') . '</label><select class="zs-config-input" id="zs-flow-from">'
                . implode('', array_map(function($k) use ($statusOptions){ return '<option value="' . esc_attr($k) . '">' . esc_html($statusOptions[$k]) . '</option>'; }, array_keys($statusOptions)))
                . '</select></div>'
                . '<div id="zs-flow-to-container"><label class="zs-config-label">' . esc_html__('To status', 'zero-sense') . '</label><select class="zs-config-input" id="zs-flow-to">'
                . implode('', array_map(function($k) use ($statusOptions){ return '<option value="' . esc_attr($k) . '">' . esc_html($statusOptions[$k]) . '</option>'; }, array_keys($statusOptions)))
                . '</select></div>'
                // Class field (hidden by default, spans 2 columns when visible)
                . '<div id="zs-flow-class-container" style="display:none;grid-column:span 2;"><label class="zs-config-label">' . esc_html__('Class (no dot)', 'zero-sense') . '</label><input type="text" class="zs-config-input" id="zs-flow-class" placeholder="class_name" /></div>'
                . '</div>'
                
                // Container for Email and Holded configs (side by side)
                . '<div style="display:flex;gap:12px;margin-top:12px;">'
                
                // Email configuration section
                . '<div class="zs-flow-email-config" style="flex:1;padding:12px;background:#f9f9f9;border-radius:4px;">'
                . '<h6 style="margin:0 0 8px;color:#666;">' . esc_html__('Email Configuration (Optional)', 'zero-sense') . '</h6>'
                . '<label style="display:block;margin-bottom:12px;"><input type="checkbox" id="zs-flow-is-email" style="margin-right:6px;" /> ' . esc_html__('Enable Email Features', 'zero-sense') . '</label>'
                . '<div id="zs-email-fields" style="display:none;max-width:350px;">'
                . '<div style="display:flex;flex-direction:column;gap:12px;">'
                . '<div>'
                . '<label class="zs-config-label" id="zs-flow-email-desc-label">' . esc_html__('Email Description', 'zero-sense') . '</label>'
                . '<input type="text" class="zs-config-input" id="zs-flow-email-desc" placeholder="' . esc_attr__('e.g., Order confirmation email', 'zero-sense') . '" />'
                . '</div>'
                . '<div id="zs-flow-generated-class-container" style="display:none;">'
                . '<label class="zs-config-label">' . esc_html__('Generated CSS Class', 'zero-sense') . '</label>'
                . '<input type="text" class="zs-config-input" id="zs-flow-generated-class" readonly style="background:#f0f0f0;color:#666;" />'
                . '</div>'
                . '<div id="zs-flow-send-once-container" style="display:none;">'
                . '<label class="zs-config-label"><input type="checkbox" id="zs-flow-send-once" style="margin-right:6px;" /> ' . esc_html__('Send only once per order', 'zero-sense') . '</label>'
                . '</div>'
                . '<div id="zs-flow-manual-states-container" style="display:none;">'
                . '<label class="zs-config-label">' . esc_html__('Show button in these order states (optional)', 'zero-sense') . '</label>'
                . '<select class="zs-config-input" id="zs-flow-manual-states" multiple style="height:80px;" title="' . esc_attr__('Hold Ctrl/Cmd to select multiple states', 'zero-sense') . '">'
                . implode('', array_map(function($k) use ($statusOptions){ return '<option value="' . esc_attr($k) . '">' . esc_html($statusOptions[$k]) . '</option>'; }, array_keys($statusOptions)))
                . '</select>'
                . '</div>'
                . '</div>'
                . '<p id="zs-flow-email-help" style="margin:8px 0 0;font-size:11px;color:#666;">' . esc_html__('Status Transitions: Email description and send-once option. Class Actions: Button name and optional order states.', 'zero-sense') . '</p>'
                . '</div>'
                . '</div>'
                
                // Holded configuration section
                . '<div class="zs-flow-holded-config" style="flex:1;padding:12px;background:#f0f9ff;border-radius:4px;">'
                . '<h6 style="margin:0 0 8px;color:#666;">' . esc_html__('Holded Integration (Optional)', 'zero-sense') . '</h6>'
                . '<label style="display:block;margin-bottom:12px;"><input type="checkbox" id="zs-flow-is-holded" style="margin-right:6px;" /> ' . esc_html__('Enable Holded Integration', 'zero-sense') . '</label>'
                . '<div id="zs-holded-fields" style="display:none;">'
                . '<div style="display:flex;flex-direction:column;gap:12px;">'
                . '<div>'
                . '<label class="zs-config-label">' . esc_html__('Integration Description', 'zero-sense') . '</label>'
                . '<input type="text" class="zs-config-input" id="zs-flow-holded-desc" placeholder="' . esc_attr__('e.g., Send invoice to Holded', 'zero-sense') . '" />'
                . '</div>'
                . '<div id="zs-flow-holded-run-once-container" style="display:none;">'
                . '<label class="zs-config-label"><input type="checkbox" id="zs-flow-holded-run-once" style="margin-right:6px;" /> ' . esc_html__('Run only once per order', 'zero-sense') . '</label>'
                . '</div>'
                . '<div id="zs-flow-holded-manual-states-container" style="display:none;">'
                . '<label class="zs-config-label">' . esc_html__('Show manual trigger button in these order states (optional)', 'zero-sense') . '</label>'
                . '<select class="zs-config-input" id="zs-flow-holded-manual-states" multiple style="height:80px;" title="' . esc_attr__('Hold Ctrl/Cmd to select multiple states', 'zero-sense') . '">'
                . implode('', array_map(function($k) use ($statusOptions){ return '<option value="' . esc_attr($k) . '">' . esc_html($statusOptions[$k]) . '</option>'; }, array_keys($statusOptions)))
                . '</select>'
                . '</div>'
                . '</div>'
                . '<p id="zs-flow-holded-help" style="margin:8px 0 0;font-size:11px;color:#666;">' . esc_html__('Only for Status Transitions. Configure automatic trigger to Holded with optional manual re-trigger button.', 'zero-sense') . '</p>'
                . '</div>'
                . '</div>'
                
                // Close flex container
                . '</div>'
                
                // Add button at the end
                . '<div style="margin-top:12px;text-align:right;">'
                . '<button type="button" class="zs-btn-primary" id="zs-flow-add">' . esc_html__('Add', 'zero-sense') . '</button>'
                . '</div>'
                
                . '</div>';

            // Your custom list (editable) grouped and ordered by Title
            $stored = get_option('zs_flowmattic_custom_triggers', []);
            $stored = is_array($stored) ? $stored : [];
            $groups = ['status' => [], 'class' => []];
            foreach ($stored as $row) {
                if (empty($row['title']) || empty($row['workflow_id']) || empty($row['tag'])) continue;
                if (!in_array($row['tag'], ['status','class'], true)) continue;
                $groups[$row['tag']][] = $row;
            }
            foreach ($groups as $k => &$arr) {
                usort($arr, static function($a,$b){ return strcasecmp($a['title'] ?? '', $b['title'] ?? ''); });
            }
            unset($arr);

            $html .= '<div class="zs-flowmattic-ref" style="margin-top:10px;">';
            $html .= '<h5 style="margin:0 0 6px;">' . esc_html__('Your Actions', 'zero-sense') . '</h5>';

            $renderGroup = function(string $title, string $id, array $items) {
                $out  = '<div style="margin:8px 0;">';
                $out .= '<strong>' . esc_html($title) . ':</strong>';
                $out .= '<ul id="' . esc_attr($id) . '" style="margin:6px 0 0; padding-left:16px; list-style:disc;">';
                foreach ($items as $row) {
                    $uid = esc_attr($row['uid']);
                    $label = esc_html($row['title']);
                    $wid = esc_html($row['workflow_id']);
                    $tag = esc_attr($row['tag']);
                    $extra = '';
                    $dataAttrs = '';
                    if ($tag === 'status') {
                        $fromRaw = (string) ($row['from_status'] ?? '');
                        $toRaw = (string) ($row['to_status'] ?? '');
                        $from = esc_html($fromRaw);
                        $to = esc_html($toRaw);
                        $statusDisplay = $from . ' → ' . $to;
                        if (!empty($row['email_config']['description'])) {
                            $statusDisplay .= ' [' . esc_html($row['email_config']['description']) . ']';
                        }
                        $extra = '<span class="zs-flow-extra">' . $statusDisplay . '</span> · ';
                        $dataAttrs = ' data-from="' . esc_attr($fromRaw) . '" data-to="' . esc_attr($toRaw) . '"';
                    } elseif ($tag === 'class') {
                        $classRaw = (string) ($row['class'] ?? '');
                        $class = esc_html($classRaw);
                        
                        // Show original class if it was auto-generated from button name
                        $displayClass = $class;
                        if (!empty($row['original_class']) && !empty($row['email_config']['description'])) {
                            $buttonName = esc_html($row['email_config']['description']);
                            $displayClass = $class . ' [' . $buttonName . ']';
                        }
                        
                        $extra = '<span class="zs-flow-extra">.' . $displayClass . '</span> · ';
                        $dataAttrs = ' data-class="' . esc_attr($classRaw) . '"';
                    }
                    // Email indicator
                    $emailIndicator = '';
                    if (!empty($row['email_config']['is_email'])) {
                        $emailDesc = !empty($row['email_config']['description']) ? esc_attr($row['email_config']['description']) : esc_attr__('Email workflow', 'zero-sense');
                        $sendOnce = !empty($row['email_config']['send_once']) ? ' (once)' : '';
                        $emailIndicator = '<span class="zs-flow-email" title="' . $emailDesc . $sendOnce . '" style="color:#0073aa;font-weight:bold;">📧</span> ';
                    }
                    
                    // Build edit form data attributes
                    $editData = [
                        'data-uid="' . $uid . '"',
                        'data-tag="' . $tag . '"',
                        'data-workflow-id="' . $wid . '"'
                    ];
                    
                    if ($tag === 'status') {
                        $editData[] = 'data-from="' . esc_attr($fromRaw) . '"';
                        $editData[] = 'data-to="' . esc_attr($toRaw) . '"';
                    } elseif ($tag === 'class') {
                        $editData[] = 'data-class="' . esc_attr($classRaw) . '"';
                        if (!empty($row['original_class'])) {
                            $editData[] = 'data-original-class="' . esc_attr($row['original_class']) . '"';
                        }
                    }
                    
                    // Email config data
                    if (!empty($row['email_config'])) {
                        $editData[] = 'data-is-email="true"';
                        if (!empty($row['email_config']['description'])) {
                            $editData[] = 'data-email-desc="' . esc_attr($row['email_config']['description']) . '"';
                        }
                        if (!empty($row['email_config']['send_once'])) {
                            $editData[] = 'data-send-once="true"';
                        }
                        if (!empty($row['email_config']['manual_states']) && is_array($row['email_config']['manual_states'])) {
                            $editData[] = 'data-manual-states="' . esc_attr(implode(',', $row['email_config']['manual_states'])) . '"';
                        }
                    }
                    
                    // Holded workflow config data
                    if (!empty($row['workflow_config']) && $row['workflow_config']['category'] === 'holded') {
                        $editData[] = 'data-is-holded="true"';
                        if (!empty($row['workflow_config']['description'])) {
                            $editData[] = 'data-holded-desc="' . esc_attr($row['workflow_config']['description']) . '"';
                        }
                        if (!empty($row['workflow_config']['run_once'])) {
                            $editData[] = 'data-run-once="true"';
                        }
                        if (!empty($row['workflow_config']['manual_states']) && is_array($row['workflow_config']['manual_states'])) {
                            $editData[] = 'data-holded-manual-states="' . esc_attr(implode(',', $row['workflow_config']['manual_states'])) . '"';
                        }
                    }
                    
                    $out .= '<li ' . implode(' ', $editData) . '><button type="button" class="zs-btn-icon zs-flow-play" data-workflow-id="' . $wid . '" title="Run workflow">▶</button> '
                         . '<span class="zs-flow-title">' . $label . '</span> · '
                         . $emailIndicator . $extra . '<code class="zs-flow-id">' . $wid . '</code> '
                         . '<button type="button" class="button-link zs-flow-edit">' . esc_html__('Edit', 'zero-sense') . '</button> · '
                         . '<button type="button" class="button-link zs-flow-delete">' . esc_html__('Delete', 'zero-sense') . '</button>'
                         . '</li>';
                }
                $out .= '</ul></div>';
                return $out;
            };

            $html .= $renderGroup(__('Status Transitions', 'zero-sense'), 'zs-flow-custom-list-status', $groups['status']);
            $html .= $renderGroup(__('Class Actions', 'zero-sense'), 'zs-flow-custom-list-class', $groups['class']);

            $html .= '</div>';
        } catch (\Throwable $e) {
            $html = '<p>' . esc_html__('Unable to load Flowmattic workflows.', 'zero-sense') . '</p>';
        }

        return [
            [
                'type' => 'html',
                'name' => 'zs_flowmattic_reference',
                'html' => $html,
            ],
        ];
    }

    /**
     * Store and retrieve custom triggers added from the dashboard
     */
    private function getStoredCustom(): array
    {
        $data = get_option('zs_flowmattic_custom_triggers', []);
        return is_array($data) ? $data : [];
    }

    private function saveStoredCustom(array $items): void
    {
        update_option('zs_flowmattic_custom_triggers', array_values($items));
    }

    // Runs counter removed

    public function logEmailSend(string $workflowId, int $orderId, string $status, array $emailData = []): void
    {
        if ($workflowId === '' || $orderId <= 0) {
            return;
        }

        // Auto-annotate current user if available
        if (!isset($emailData['_by']) && function_exists('is_user_logged_in') && is_user_logged_in()) {
            $u = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
            if ($u && isset($u->ID) && (int) $u->ID > 0) {
                $emailData['_by'] = [
                    'id' => (int) $u->ID,
                    'name' => isset($u->display_name) ? (string) $u->display_name : '',
                    'login' => isset($u->user_login) ? (string) $u->user_login : '',
                ];
            }
        }

        // Save to generic workflow execution system
        $this->logWorkflowExecution($workflowId, $orderId, $status, [
            'category' => 'email',
            'trigger_source' => $emailData['trigger_source'] ?? 'unknown',
            'metadata' => [
                'email_to' => $emailData['to'] ?? '',
                'email_subject' => $emailData['subject'] ?? '',
            ],
            'error' => $emailData['error'] ?? null,
            '_by' => $emailData['_by'] ?? null,
        ]);
    }

    public function hasEmailBeenSent(string $workflowId, int $orderId): bool
    {
        $executions = $this->getWorkflowExecutionsForOrder($orderId, 'email');
        
        foreach ($executions as $exec) {
            if ($exec['workflow_id'] === $workflowId && 
                $exec['status'] !== self::EMAIL_STATUS_ERROR) {
                return true;
            }
        }
        
        return false;
    }

    public function getEmailSendStatus(string $workflowId, int $orderId): ?string
    {
        $executions = $this->getWorkflowExecutionsForOrder($orderId, 'email');
        
        foreach ($executions as $exec) {
            if ($exec['workflow_id'] === $workflowId) {
                return $exec['status'];
            }
        }
        
        return null;
    }

    // ========================================================================
    // GENERIC WORKFLOW EXECUTION LOGGING (for Holded and future integrations)
    // ========================================================================

    private function getWorkflowExecutions(): array
    {
        $raw = get_option(self::WORKFLOW_EXECUTIONS_OPTION, []);
        return is_array($raw) ? $raw : [];
    }

    private function saveWorkflowExecutions(array $executions): void
    {
        update_option(self::WORKFLOW_EXECUTIONS_OPTION, $executions, false);
    }

    public function logWorkflowExecution(string $workflowId, int $orderId, string $status, array $data = []): void
    {
        if ($workflowId === '' || $orderId <= 0) {
            return;
        }

        $executions = $this->getWorkflowExecutions();
        $logId = uniqid('wf_', true);
        
        // Auto-annotate current user if available
        if (!isset($data['_by']) && function_exists('is_user_logged_in') && is_user_logged_in()) {
            $u = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
            if ($u && isset($u->ID) && (int) $u->ID > 0) {
                $data['_by'] = [
                    'id' => (int) $u->ID,
                    'name' => isset($u->display_name) ? (string) $u->display_name : '',
                    'login' => isset($u->user_login) ? (string) $u->user_login : '',
                ];
            }
        }

        $executions[$logId] = [
            'workflow_id' => $workflowId,
            'order_id' => $orderId,
            'category' => $data['category'] ?? 'generic',
            'status' => $status,
            'timestamp' => current_time('mysql'),
            'trigger_source' => $data['trigger_source'] ?? 'unknown',
            'metadata' => $data['metadata'] ?? [],
            'error_message' => $data['error'] ?? null,
            'by_id' => isset($data['_by']['id']) ? (int) $data['_by']['id'] : 0,
            'by_name' => isset($data['_by']['name']) ? (string) $data['_by']['name'] : '',
            'by_login' => isset($data['_by']['login']) ? (string) $data['_by']['login'] : '',
        ];

        // Keep only last 500 entries to prevent database bloat
        if (count($executions) > 500) {
            $executions = array_slice($executions, -500, null, true);
        }

        $this->saveWorkflowExecutions($executions);
    }

    public function hasWorkflowExecuted(string $workflowId, int $orderId, ?string $category = null): bool
    {
        $executions = $this->getWorkflowExecutions();
        
        foreach ($executions as $execution) {
            if ($execution['workflow_id'] === $workflowId && 
                $execution['order_id'] === $orderId && 
                $execution['status'] !== self::WORKFLOW_STATUS_ERROR) {
                
                // If category specified, must match
                if ($category !== null && ($execution['category'] ?? 'generic') !== $category) {
                    continue;
                }
                
                return true;
            }
        }
        
        return false;
    }

    public function getWorkflowExecutionStatus(string $workflowId, int $orderId): ?string
    {
        $executions = $this->getWorkflowExecutions();
        
        foreach (array_reverse($executions, true) as $execution) {
            if ($execution['workflow_id'] === $workflowId && $execution['order_id'] === $orderId) {
                return $execution['status'];
            }
        }
        
        return null;
    }

    private function getWorkflowExecutionsForOrder(int $orderId, ?string $category = null): array
    {
        $executions = $this->getWorkflowExecutions();
        $logs = [];
        
        foreach ($executions as $execution) {
            if ((int) ($execution['order_id'] ?? 0) === $orderId) {
                // Filter by category if specified
                if ($category !== null && ($execution['category'] ?? 'generic') !== $category) {
                    continue;
                }
                
                $logs[] = [
                    'workflow_id' => $execution['workflow_id'],
                    'category' => $execution['category'] ?? 'generic',
                    'status' => $execution['status'],
                    'description' => $this->getWorkflowDescription($execution['workflow_id'], $execution['category'] ?? 'generic'),
                    'timestamp' => strtotime($execution['timestamp']),
                    'formatted_time' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($execution['timestamp'])),
                    'metadata' => $execution['metadata'] ?? [],
                    'error_message' => $execution['error_message'] ?? null,
                    'by_name' => $execution['by_name'] ?? '',
                    'by_login' => $execution['by_login'] ?? '',
                ];
            }
        }
        
        // Sort by timestamp descending (newest first) so getEmailSendStatus returns most recent status
        usort($logs, function($a, $b) {
            return $b['timestamp'] <=> $a['timestamp'];
        });
        
        return $logs;
    }

    /**
     * Get workflow description from trigger configuration
     */
    private function getWorkflowDescription(string $workflowId, string $category = 'generic'): string
    {
        $stored = get_option('zs_flowmattic_custom_triggers', []);
        
        foreach ($stored as $trigger) {
            if (($trigger['workflow_id'] ?? '') === $workflowId) {
                // For Holded workflows
                if ($category === 'holded' && !empty($trigger['workflow_config']['description'])) {
                    return $trigger['workflow_config']['description'];
                }
                
                // For email workflows
                if (!empty($trigger['email_config']['description'])) {
                    return $trigger['email_config']['description'];
                }
                
                // Fallback to title
                return $trigger['title'] ?? $workflowId;
            }
        }
        
        return $workflowId;
    }

    /**
     * Add metabox to order admin for manual email sending
     */
    public function addOrderEmailMetabox(): void
    {
        $screen = get_current_screen();
        if ($screen && in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            $screen_id = $screen->id === 'woocommerce_page_wc-orders' ? wc_get_page_screen_id('shop-order') : 'shop_order';
            
            wp_enqueue_style(
                'zs-flowmattic-admin',
                plugin_dir_url(__FILE__) . 'assets/flowmattic-admin.css',
                [],
                '1.0.0'
            );
            add_meta_box(
                'zs_flowmattic_emails',
                __('Email Actions', 'zero-sense'),
                [$this, 'renderOrderEmailMetabox'],
                $screen_id,
                'side',
                'default'
            );
        }
    }

    /**
     * Render the order email metabox
     */
    public function renderOrderEmailMetabox($postOrOrder): void
    {
        $orderId = 0;
        if ($postOrOrder instanceof \WP_Post) {
            $orderId = $postOrOrder->ID;
        } elseif ($postOrOrder instanceof \WC_Order) {
            $orderId = $postOrOrder->get_id();
        }
        $order = wc_get_order($orderId);
        
        if (!$order instanceof \WC_Order) {
            echo '<p>' . esc_html__('Invalid order.', 'zero-sense') . '</p>';
            return;
        }

        $currentStatus = $order->get_status();
        $emailTriggers = $this->getEmailTriggersForOrder($currentStatus);
        $allEmailTriggers = $this->getAllEmailTriggers();
        $unavailableTriggers = $this->getUnavailableEmailTriggers($currentStatus, $allEmailTriggers);
        $statusTransitions = $this->getStatusTransitionTriggers($currentStatus);
        
        if (empty($emailTriggers) && empty($unavailableTriggers) && empty($statusTransitions)) {
            echo '<p>' . esc_html__('No email actions configured.', 'zero-sense') . '</p>';
            return;
        }

        wp_nonce_field('zs_manual_email_nonce', 'zs_manual_email_nonce');
        
        echo '<div class="zs-manual-email-actions">';
        
        // Section 1: Manual Email Actions (Class Actions)
        foreach ($emailTriggers as $trigger) {
            $workflowId = $trigger['workflow_id'];
            $buttonText = $trigger['email_config']['description'] ?? $trigger['title'];
            $originalClass = $trigger['class'] ?? '';
            
            // Generate the CSS class for the button (same logic as in backend)
            $buttonCssClass = '';
            if (!empty($trigger['email_config']['description'])) {
                $sanitizedName = sanitize_title($trigger['email_config']['description']);
                $buttonCssClass = 'flm-action-' . $sanitizedName;
            } else {
                $buttonCssClass = $originalClass;
            }
            
            // Check email status for display purposes only (manual buttons are never disabled)
            $emailStatus = $this->getEmailSendStatus($workflowId, $orderId);
            $badgeHtml = '';
            $statusAttr = '';
            
            if ($emailStatus) {
                switch ($emailStatus) {
                    case self::EMAIL_STATUS_AUTO:
                        $badgeHtml = '<span class="zs-badge zs-badge-auto">AUTO</span>';
                        $statusAttr = 'auto';
                        break;
                    case self::EMAIL_STATUS_MANUAL:
                        $badgeHtml = '<span class="zs-badge zs-badge-manual">MAN</span>';
                        $statusAttr = 'manual';
                        break;
                    case self::EMAIL_STATUS_ERROR:
                        $badgeHtml = '<span class="zs-badge zs-badge-error">ERROR</span>';
                        $statusAttr = 'error';
                        break;
                    case self::EMAIL_STATUS_SKIPPED:
                        $badgeHtml = '<span class="zs-badge zs-badge-skipped">SKIP</span>';
                        $statusAttr = 'skipped';
                        break;
                }
            }
            
            $itemClass = 'zs-email-action-item' . ($statusAttr ? ' zs-email-status-' . $statusAttr : '');
            echo '<div class="' . esc_attr($itemClass) . '">';
            echo '<button type="button" class="zs-btn is-action zs-manual-email-btn ' . esc_attr($buttonCssClass) . '" data-workflow-id="' . esc_attr($workflowId) . '" data-order-id="' . esc_attr($orderId) . '">';
            echo '<span class="zs-email-btn-label">' . esc_html($buttonText) . '</span>';
            if ($badgeHtml) {
                echo $badgeHtml;
            }
            echo '</button>';
            echo '</div>';
        }
        
        // Show unavailable triggers section
        if (!empty($unavailableTriggers)) {
            echo '<div class="zs-email-section-sep zs-show-more">';
            echo '<button type="button" class="zs-toggle-logs" id="zs-toggle-unavailable">';
            echo sprintf(esc_html__('Show %d unavailable actions', 'zero-sense'), count($unavailableTriggers));
            echo '</button>';
            
            echo '<div id="zs-unavailable-actions" style="display:none;">';
            foreach ($unavailableTriggers as $trigger) {
                $buttonText = $trigger['email_config']['description'] ?? $trigger['title'];
                $availableStates = $trigger['email_config']['manual_states'] ?? [];
                
                // Format available states (we know they're not empty since we filtered those out)
                $statesText = '';
                if (in_array('any', $availableStates)) {
                    $statesText = esc_html__('Available in all states', 'zero-sense');
                } else {
                    $statusOptions = $this->getStatusOptions();
                    $stateLabels = [];
                    foreach ($availableStates as $state) {
                        $stateLabels[] = $statusOptions[$state] ?? ucfirst($state);
                    }
                    $statesText = sprintf(esc_html__('Available in: %s', 'zero-sense'), implode(', ', $stateLabels));
                }
                
                echo '<div class="zs-unavailable-action-item">';
                echo '<div class="zs-unavailable-action-name">' . esc_html($buttonText) . '</div>';
                echo '<div class="zs-unavailable-action-states">(' . $statesText . ')</div>';
                echo '</div>';
            }
            echo '</div>';
            echo '</div>';
        }
        
        // Section 2: Automatic Email Actions
        if (!empty($statusTransitions)) {
            echo '<div class="zs-email-section-sep zs-show-more">';
            echo '<button type="button" class="zs-toggle-logs" id="zs-toggle-automatic-actions">';
            echo sprintf(esc_html__('Show %d automatic actions', 'zero-sense'), count($statusTransitions));
            echo '</button>';
            
            echo '<div id="zs-automatic-actions" style="display:none;">';
            
            $statusOptions = $this->getStatusOptions();
            
            foreach ($statusTransitions as $transition) {
                $workflowId = $transition['workflow_id'] ?? '';
                $fromStatus = $transition['from_status'] ?? '';
                $toStatus = $transition['to_status'] ?? '';
                $direction = $transition['direction'] ?? 'outgoing';
                
                // Get status labels
                $fromLabel = $statusOptions[$fromStatus] ?? ucfirst($fromStatus);
                $toLabel = $statusOptions[$toStatus] ?? ucfirst($toStatus);
                
                // Build transition display
                $transitionText = $fromLabel . ' → ' . $toLabel;
                
                // Get email description if available (display in square brackets)
                $emailDesc = '';
                if (!empty($transition['email_config']['description'])) {
                    $emailDesc = ' [' . esc_html($transition['email_config']['description']) . ']';
                }
                
                // Check if email was sent
                $emailStatus = $this->getEmailSendStatus($workflowId, $orderId);
                $statusBadge = '';
                
                if ($emailStatus) {
                    switch ($emailStatus) {
                        case self::EMAIL_STATUS_AUTO:
                            $statusBadge = '<span class="zs-badge zs-badge-auto">AUTO</span>';
                            break;
                        case self::EMAIL_STATUS_MANUAL:
                            $statusBadge = '<span class="zs-badge zs-badge-manual">MAN</span>';
                            break;
                        case self::EMAIL_STATUS_ERROR:
                            $statusBadge = '<span class="zs-badge zs-badge-error">ERROR</span>';
                            break;
                        case self::EMAIL_STATUS_SKIPPED:
                            $statusBadge = '<span class="zs-badge zs-badge-skipped">SKIP</span>';
                            break;
                    }
                }
                
                // Determine container class based on email status
                $containerClass = 'zs-email-item';
                if ($emailStatus) {
                    switch ($emailStatus) {
                        case self::EMAIL_STATUS_AUTO:
                            $containerClass .= ' zs-email-auto';
                            break;
                        case self::EMAIL_STATUS_MANUAL:
                            $containerClass .= ' zs-email-manual';
                            break;
                        case self::EMAIL_STATUS_ERROR:
                            $containerClass .= ' zs-email-error';
                            break;
                        case self::EMAIL_STATUS_SKIPPED:
                            $containerClass .= ' zs-email-skipped';
                            break;
                    }
                }
                
                echo '<div class="' . esc_attr($containerClass) . '">';
                echo '<div class="zs-auto-action-title">' . esc_html($transitionText) . $emailDesc . '</div>';
                if ($statusBadge) {
                    echo $statusBadge;
                }
                echo '</div>';
            }
            
            echo '</div>'; // Close zs-automatic-actions
            echo '</div>'; // Close section container
        }
        
        echo '</div>';
        
        // Add JavaScript for AJAX handling
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.zs-manual-email-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (this.disabled) return;

                    const workflowLabel = (this.querySelector('.zs-email-btn-label') || this).textContent.trim();
                    const orderIdPreview = this.getAttribute('data-order-id');
                    const templateWithOrder = <?php echo wp_json_encode(__('Send the email workflow "{label}" for order #{order}?', 'zero-sense')); ?>;
                    const templateWithoutOrder = <?php echo wp_json_encode(__('Send the email workflow "{label}" now?', 'zero-sense')); ?>;
                    const confirmMessage = (orderIdPreview ? templateWithOrder : templateWithoutOrder)
                        .replace('{label}', workflowLabel)
                        .replace('{order}', orderIdPreview || '');

                    if (!window.confirm(confirmMessage)) {
                        return;
                    }

                    const workflowId = this.getAttribute('data-workflow-id');
                    const orderId = this.getAttribute('data-order-id');
                    const nonce = document.getElementById('zs_manual_email_nonce').value;
                    
                    this.disabled = true;
                    const labelEl = this.querySelector('.zs-email-btn-label') || this;
                    const originalText = labelEl.textContent;
                    labelEl.textContent = '<?php echo esc_js(__('Sending...', 'zero-sense')); ?>';
                    
                    const requestStartTime = Date.now();
                    
                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'zs_flow_send_manual_email',
                            workflow_id: workflowId,
                            order_id: orderId,
                            nonce: nonce
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        labelEl.textContent = originalText;

                        const btn = this;
                        const workflowId = btn.getAttribute('data-workflow-id');
                        const orderId = btn.getAttribute('data-order-id');
                        const nonceCheck = document.getElementById('zs_manual_email_nonce').value;

                        let attempts = 0;
                        const maxAttempts = 8; // ~8s
                        const prependLatest = () => {
                            fetch(ajaxurl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: new URLSearchParams({
                                    action: 'zs_flow_get_latest_email_log',
                                    order_id: orderId,
                                    nonce: nonceCheck
                                })
                            })
                            .then(r => r.json())
                            .then(res2 => {
                                if (res2.success && res2.data && res2.data.html) {
                                    const box = document.querySelector('.zs-email-logs-metabox');
                                    if (box) {
                                        const firstItem = box.querySelector('.zs-log-item');
                                        const temp = document.createElement('div');
                                        temp.innerHTML = res2.data.html.trim();
                                        const newItem = temp.firstElementChild;
                                        if (newItem) {
                                            if (firstItem) {
                                                box.insertBefore(newItem, firstItem);
                                            } else {
                                                box.appendChild(newItem);
                                            }
                                        }
                                    }
                                }
                            });
                        };
                        const poll = () => {
                            fetch(ajaxurl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: new URLSearchParams({
                                    action: 'zs_flow_check_email_status',
                                    workflow_id: workflowId,
                                    order_id: orderId,
                                    nonce: nonceCheck
                                })
                            })
                            .then(r => r.json())
                            .then(res => {
                                const st = res && res.success ? (res.data?.status ?? null) : null;
                                if (st === 'manual' || st === 'auto' || st === 'error' || st === 'skipped') {
                                    this.disabled = false;
                                    prependLatest();
                                    
                                    // Update button badge in real-time
                                    const wrapper = btn.parentElement;
                                    let existingBadge = btn.querySelector('.zs-badge');

                                    const badgeMap = {
                                        manual: ['zs-badge-manual', 'MAN'],
                                        auto:   ['zs-badge-auto',   'AUTO'],
                                        error:  ['zs-badge-error',  'ERROR'],
                                        skipped:['zs-badge-skipped','SKIP'],
                                    };
                                    const statusClassMap = {
                                        manual: 'zs-email-status-manual',
                                        auto:   'zs-email-status-auto',
                                        error:  'zs-email-status-error',
                                        skipped:'zs-email-status-skipped',
                                    };

                                    if (badgeMap[st]) {
                                        const [badgeClass, badgeText] = badgeMap[st];
                                        if (existingBadge) {
                                            existingBadge.className = 'zs-badge ' + badgeClass;
                                            existingBadge.textContent = badgeText;
                                        } else {
                                            const span = document.createElement('span');
                                            span.className = 'zs-badge ' + badgeClass;
                                            span.textContent = badgeText;
                                            btn.appendChild(span);
                                        }
                                        // Update wrapper status class
                                        wrapper.className = wrapper.className
                                            .replace(/\bzs-email-status-\w+/g, '')
                                            .trim() + ' ' + statusClassMap[st];
                                    }
                                    
                                    return;
                                }
                                if (++attempts < maxAttempts) {
                                    return setTimeout(poll, 1000);
                                }
                                this.disabled = false;
                            })
                            .catch((err) => {
                                if (++attempts < maxAttempts) {
                                    return setTimeout(poll, 1000);
                                }
                                this.disabled = false;
                            });
                        };

                        if (data.success) {
                            poll();
                        } else {
                            alert('Error: ' + (data.data || data.message || 'Unknown error'));
                            this.disabled = false;
                        }
                    })
                    .catch(error => {
                        alert('<?php echo esc_js(__('Network error:', 'zero-sense')); ?> ' + error.message);
                        this.disabled = false;
                        labelEl.textContent = originalText;
                    });
                });
            });
            
            // Handle toggle for unavailable actions
            const toggleBtn = document.getElementById('zs-toggle-unavailable');
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function() {
                    const unavailableDiv = document.getElementById('zs-unavailable-actions');
                    const isHidden = unavailableDiv.style.display === 'none';
                    unavailableDiv.style.display = isHidden ? 'block' : 'none';
                    this.textContent = isHidden
                        ? this.textContent.replace(/^Show/, 'Hide')
                        : this.textContent.replace(/^Hide/, 'Show');
                });
            }

            // Handle toggle for automatic actions
            const toggleAutoBtn = document.getElementById('zs-toggle-automatic-actions');
            if (toggleAutoBtn) {
                toggleAutoBtn.addEventListener('click', function() {
                    const autoDiv = document.getElementById('zs-automatic-actions');
                    const isHidden = autoDiv.style.display === 'none';
                    autoDiv.style.display = isHidden ? 'block' : 'none';
                    this.textContent = isHidden
                        ? this.textContent.replace(/^Show/, 'Hide')
                        : this.textContent.replace(/^Hide/, 'Show');
                });
            }
        });
        </script>
        <?php
    }

    /**
     * Get email triggers available for current order status
     */
    private function getEmailTriggersForOrder(string $orderStatus): array
    {
        $stored = get_option('zs_flowmattic_custom_triggers', []);
        if (!is_array($stored)) {
            return [];
        }

        $emailTriggers = [];
        
        foreach ($stored as $trigger) {
            // Only class actions with email config
            if (($trigger['tag'] ?? '') !== 'class' || empty($trigger['email_config']['is_email'])) {
                continue;
            }
            
            // Check if current order status is in manual_states
            $manualStates = $trigger['email_config']['manual_states'] ?? [];
            
            // Show button if:
            // 1. Current status is specifically in the configured states
            // 2. "any" is selected in manual states (show in all states)
            // Note: If no states are selected (empty array), button won't appear anywhere
            if (is_array($manualStates) && !empty($manualStates) && 
                (in_array($orderStatus, $manualStates, true) || in_array('any', $manualStates, true))) {
                $emailTriggers[] = $trigger;
            }
        }
        
        return $emailTriggers;
    }

    /**
     * Get all email triggers (regardless of order status)
     */
    private function getAllEmailTriggers(): array
    {
        $stored = get_option('zs_flowmattic_custom_triggers', []);
        if (!is_array($stored)) {
            return [];
        }

        $emailTriggers = [];
        
        foreach ($stored as $trigger) {
            // Only class actions with email config
            if (($trigger['tag'] ?? '') !== 'class' || empty($trigger['email_config']['is_email'])) {
                continue;
            }
            
            $emailTriggers[] = $trigger;
        }
        
        return $emailTriggers;
    }

    /**
     * Get email triggers that are NOT available for current order status
     */
    private function getUnavailableEmailTriggers(string $orderStatus, array $allEmailTriggers): array
    {
        $unavailableTriggers = [];
        
        foreach ($allEmailTriggers as $trigger) {
            $manualStates = $trigger['email_config']['manual_states'] ?? [];
            
            // Skip if no states configured (these won't show anywhere)
            if (empty($manualStates)) {
                continue;
            }
            
            // Skip if available in current status
            if (in_array('any', $manualStates) || in_array($orderStatus, $manualStates)) {
                continue;
            }
            
            // This trigger is not available in current status
            $unavailableTriggers[] = $trigger;
        }
        
        return $unavailableTriggers;
    }

    /**
     * Get all status transition triggers with email capabilities (ordered by status)
     */
    private function getStatusTransitionTriggers(string $orderStatus): array
    {
        $stored = get_option('zs_flowmattic_custom_triggers', []);
        if (!is_array($stored)) {
            return [];
        }

        $transitions = [];
        
        foreach ($stored as $trigger) {
            // Only status transitions with email capabilities
            if (($trigger['tag'] ?? '') !== 'status' || empty($trigger['email_config']['is_email'])) {
                continue;
            }
            
            $fromStatus = $trigger['from_status'] ?? '';
            $toStatus = $trigger['to_status'] ?? '';
            
            // Mark if this is relevant to current order status
            $trigger['is_current'] = ($fromStatus === $orderStatus || $toStatus === $orderStatus || $fromStatus === 'any');
            $trigger['direction'] = ($fromStatus === $orderStatus || $fromStatus === 'any') ? 'outgoing' : 'incoming';
            
            $transitions[] = $trigger;
        }
        
        // Get WooCommerce status order
        $statusOrder = [];
        if (function_exists('wc_get_order_statuses')) {
            $wcStatuses = wc_get_order_statuses();
            $index = 0;
            foreach ($wcStatuses as $slug => $label) {
                $clean = strpos($slug, 'wc-') === 0 ? substr($slug, 3) : $slug;
                $statusOrder[$clean] = $index++;
            }
        }
        $statusOrder['any'] = -1; // "any" comes first
        
        // Sort by destination status (to_status) in WooCommerce order
        usort($transitions, function($a, $b) use ($statusOrder) {
            $toA = $a['to_status'] ?? '';
            $toB = $b['to_status'] ?? '';
            $fromA = $a['from_status'] ?? '';
            $fromB = $b['from_status'] ?? '';
            
            // Get order positions for destination status
            $toOrderA = $statusOrder[$toA] ?? 999;
            $toOrderB = $statusOrder[$toB] ?? 999;
            
            // Sort by to_status order first
            if ($toOrderA !== $toOrderB) {
                return $toOrderA <=> $toOrderB;
            }
            
            // Then by from_status order within same destination
            $fromOrderA = $statusOrder[$fromA] ?? 999;
            $fromOrderB = $statusOrder[$fromB] ?? 999;
            return $fromOrderA <=> $fromOrderB;
        });
        
        return $transitions;
    }

    /**
     * Get status options for dropdowns
     */
    private function getStatusOptions(): array
    {
        $statusOptions = ['any' => __('Any', 'zero-sense')];
        if (function_exists('wc_get_order_statuses')) {
            $wcStatuses = wc_get_order_statuses(); // e.g. [ 'wc-pending' => 'Pending payment', ... ]
            foreach ($wcStatuses as $slug => $label) {
                $clean = strpos($slug, 'wc-') === 0 ? substr($slug, 3) : $slug;
                $statusOptions[$clean] = $label;
            }
        } else {
            // Fallback minimal set
            $statusOptions += [
                'pending' => __('Pending', 'zero-sense'),
                'processing' => __('Processing', 'zero-sense'),
                'on-hold' => __('On Hold', 'zero-sense'),
                'completed' => __('Completed', 'zero-sense'),
                'cancelled' => __('Cancelled', 'zero-sense'),
                'refunded' => __('Refunded', 'zero-sense'),
                'failed' => __('Failed', 'zero-sense'),
            ];
        }
        return $statusOptions;
    }

    /**
     * Convert status label to slug (e.g., "Deposit Paid" -> "deposit-paid")
     * If already a slug, returns as-is.
     */
    private function labelToSlug(string $input): string
    {
        // If it's already a known slug, return it
        $statusOptions = $this->getStatusOptions();
        if (isset($statusOptions[$input])) {
            return $input;
        }
        
        // Otherwise, try to find the slug by matching the label
        foreach ($statusOptions as $slug => $label) {
            if (strcasecmp($label, $input) === 0) {
                return $slug;
            }
        }
        
        // Fallback: sanitize as slug (lowercase, replace spaces with dashes)
        return sanitize_title($input);
    }

    /**
     * AJAX handler for manual email sending
     */
    public function ajaxSendManualEmail(): void
    {
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error('insufficient_permissions');
        }
        
        if (!wp_verify_nonce(wp_unslash($_POST['nonce'] ?? ''), 'zs_manual_email_nonce')) {
            wp_send_json_error('invalid_nonce');
        }
        
        $workflowId = sanitize_text_field(wp_unslash($_POST['workflow_id'] ?? ''));
        $orderId = intval(wp_unslash($_POST['order_id'] ?? 0));
        
        if (!$workflowId || !$orderId) {
            wp_send_json_error('missing_parameters');
        }
        
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            wp_send_json_error('invalid_order');
        }
        
        // Check if this is a valid email trigger
        $trigger = $this->getEmailTriggerByWorkflowId($workflowId);
        if (!$trigger) {
            wp_send_json_error('invalid_trigger');
        }
        
        // Note: Manual buttons can always be used, regardless of send_once setting
        // The send_once limitation only applies to automatic status transitions
        
        // Trigger the workflow manually
        $className = $trigger['class'] ?? '';
        if (!$className) {
            wp_send_json_error('missing_class');
        }
        
        // Trigger workflow directly via Integration class method
        // This avoids modifying $_POST (security anti-pattern) and ensures clean JSON response
        try {
            $integration = new Integration();
            $integration->triggerClassWorkflow($workflowId, $className, $orderId, 'manual');
        } catch (\Throwable $e) {
            wp_send_json_error([
                'code' => 'workflow_exception',
                'message' => $e->getMessage()
            ]);
        }
        
        wp_send_json_success([
            'message' => 'Email sent successfully',
            'workflow_id' => $workflowId,
            'class_name' => $className,
            'order_id' => $orderId
        ]);
    }

    /**
     * AJAX: Check latest email status for a workflow/order
     */
    public function ajaxCheckEmailStatus(): void
    {
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error('forbidden');
        }
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'zs_manual_email_nonce')) {
            wp_send_json_error('bad_nonce');
        }

        $workflowId = sanitize_text_field(wp_unslash($_POST['workflow_id'] ?? ''));
        $orderId = intval(wp_unslash($_POST['order_id'] ?? 0));
        if ($workflowId === '' || $orderId <= 0) {
            wp_send_json_error('bad_params');
        }

        $status = $this->getEmailSendStatus($workflowId, $orderId); // 'auto' | 'manual' | 'error' | null

        if ($status === null) {
            wp_send_json_success(['status' => null]);
        }

        // Find latest send to include error message if needed
        $sends = $this->getEmailSends();
        $errorMsg = null;
        foreach (array_reverse($sends, true) as $send) {
            if ($send['workflow_id'] === $workflowId && $send['order_id'] === $orderId) {
                $errorMsg = $send['error_message'] ?? null;
                break;
            }
        }

        wp_send_json_success([
            'status' => $status,
            'error' => $errorMsg,
        ]);
    }

    /**
     * Get email trigger by workflow ID
     */
    private function getEmailTriggerByWorkflowId(string $workflowId): ?array
    {
        $stored = get_option('zs_flowmattic_custom_triggers', []);
        if (!is_array($stored)) {
            return null;
        }
        
        foreach ($stored as $trigger) {
            if (($trigger['workflow_id'] ?? '') === $workflowId && 
                ($trigger['tag'] ?? '') === 'class' && 
                !empty($trigger['email_config']['is_email'])) {
                return $trigger;
            }
        }
        
        return null;
    }

    public function __construct()
    {
        // AJAX endpoints for CRUD
        add_action('wp_ajax_zs_flow_add_trigger', [$this, 'ajaxAddTrigger']);
        add_action('wp_ajax_zs_flow_update_trigger', [$this, 'ajaxUpdateTrigger']);
        add_action('wp_ajax_zs_flow_delete_trigger', [$this, 'ajaxDeleteTrigger']);
        add_action('wp_ajax_zs_flow_run_workflow', [$this, 'ajaxRunWorkflow']);
        
        // Removed runs counter hooks
        
        // Add manual email buttons to order admin
        add_action('add_meta_boxes', [$this, 'addOrderEmailMetabox']);
        add_action('wp_ajax_zs_flow_send_manual_email', [$this, 'ajaxSendManualEmail']);
        add_action('wp_ajax_zs_flow_check_email_status', [$this, 'ajaxCheckEmailStatus']);
        add_action('wp_ajax_zs_flow_get_latest_email_log', [$this, 'ajaxGetLatestEmailLog']);
        
        // Add Holded metaboxes to order admin
        add_action('add_meta_boxes', [$this, 'addHoldedActionsMetabox']);
        add_action('add_meta_boxes', [$this, 'addHoldedLogsMetabox']);
        add_action('wp_ajax_zs_flow_trigger_holded_sync', [$this, 'ajaxTriggerHoldedSync']);
    }

    public function ajaxAddTrigger(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden');
        }
        if (!wp_verify_nonce(wp_unslash($_POST['nonce'] ?? ''), 'zs_admin_nonce')) {
            wp_send_json_error('bad_nonce');
        }

        $wid   = sanitize_text_field(wp_unslash($_POST['workflow_id'] ?? ''));
        $tag   = sanitize_text_field(wp_unslash($_POST['tag'] ?? ''));
        if ($wid === '' || !in_array($tag, ['status', 'class'], true)) {
            wp_send_json_error('invalid_fields');
        }

        $items = $this->getStoredCustom();
        $duplicate = false;
        foreach ($items as $it) {
            if (!empty($it['workflow_id']) && $it['workflow_id'] === $wid) {
                $duplicate = true; // allowed, but warn
                break;
            }
        }

        $uid = md5(uniqid('zs', true));
        $entry = [
            'uid' => $uid,
            'workflow_id' => $wid,
            'tag' => $tag,
        ];
        // Optional explicit title from UI
        $givenTitle = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        
        // Process email configuration
        $isEmail = !empty($_POST['is_email']) && sanitize_text_field(wp_unslash($_POST['is_email'])) === 'true';
        $allowSendOnce = ($tag === 'status');
        if ($isEmail) {
            $emailDesc = sanitize_text_field(wp_unslash($_POST['email_description'] ?? ''));
            $sendOnce = $allowSendOnce && !empty($_POST['send_once']) && sanitize_text_field(wp_unslash($_POST['send_once'])) === 'true';
            $manualStates = [];
            
            if (!empty($_POST['manual_states']) && is_array($_POST['manual_states'])) {
                $manualStatesRaw = array_map('sanitize_text_field', wp_unslash($_POST['manual_states']));
                foreach ($manualStatesRaw as $state) {
                    $cleanState = $state;
                    if ($cleanState !== '') {
                        $manualStates[] = $cleanState;
                    }
                }
            }
            
            $entry['email_config'] = [
                'is_email' => true,
                'description' => $emailDesc,
                'manual_states' => $manualStates
            ];
            if ($sendOnce) {
                $entry['email_config']['send_once'] = true;
            }
        }
        
        // Process Holded workflow configuration (only for status transitions)
        $isHolded = !empty($_POST['is_holded']) && sanitize_text_field(wp_unslash($_POST['is_holded'])) === 'true';
        if ($isHolded && $tag === 'status') {
            $holdedDesc = sanitize_text_field(wp_unslash($_POST['holded_description'] ?? ''));
            $runOnce = !empty($_POST['holded_run_once']) && sanitize_text_field(wp_unslash($_POST['holded_run_once'])) === 'true';
            $holdedManualStates = [];
            
            if (!empty($_POST['holded_manual_states']) && is_array($_POST['holded_manual_states'])) {
                $holdedManualStatesRaw = array_map('sanitize_text_field', wp_unslash($_POST['holded_manual_states']));
                foreach ($holdedManualStatesRaw as $state) {
                    $cleanState = $state;
                    if ($cleanState !== '') {
                        $holdedManualStates[] = $cleanState;
                    }
                }
            }
            
            $entry['workflow_config'] = [
                'category' => 'holded',
                'description' => $holdedDesc,
                'manual_states' => $holdedManualStates
            ];
            if ($runOnce) {
                $entry['workflow_config']['run_once'] = true;
            }
        }
        
        // Auto-generate title based on action type (unless user provided one)
        if ($tag === 'status') {
            $from = sanitize_text_field(wp_unslash($_POST['from_status'] ?? ''));
            $to   = sanitize_text_field(wp_unslash($_POST['to_status'] ?? ''));
            if ($from === '' || $to === '') {
                wp_send_json_error('invalid_status_fields');
            }
            // Normalize: convert label to slug if needed
            $from = $this->labelToSlug($from);
            $to = $this->labelToSlug($to);
            $entry['from_status'] = $from;
            $entry['to_status'] = $to;
            if ($givenTitle !== '') {
                $entry['title'] = $givenTitle;
            } else {
                $entry['title'] = $this->generateStatusTitle($from, $to);
            }
        } elseif ($tag === 'class') {
            $class = sanitize_text_field(wp_unslash($_POST['class'] ?? ''));
            if ($class === '') {
                wp_send_json_error('invalid_class');
            }
            
            // For class actions with email config, generate class from button name
            if ($isEmail && !empty($emailDesc)) {
                // Generate CSS class from button name: flm-action-{sanitized-name}
                $sanitizedName = sanitize_title($emailDesc);
                $generatedClass = 'flm-action-' . $sanitizedName;
                $entry['class'] = $generatedClass;
                if ($givenTitle !== '') {
                    $entry['title'] = $givenTitle;
                } else {
                    $entry['title'] = $emailDesc; // Default to button name
                }
                $entry['original_class'] = $class; // Keep original class for reference
            } else {
                $entry['class'] = $class;
                $entry['title'] = ($givenTitle !== '') ? $givenTitle : $class;
            }
        }
        $items[] = $entry;
        $this->saveStoredCustom($items);

        wp_send_json_success(['duplicate' => $duplicate, 'count' => count($items), 'uid' => $uid, 'title' => $entry['title']]);
    }

    public function ajaxUpdateTrigger(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden');
        }
        if (!wp_verify_nonce(wp_unslash($_POST['nonce'] ?? ''), 'zs_admin_nonce')) {
            wp_send_json_error('bad_nonce');
        }

        $uid   = sanitize_text_field(wp_unslash($_POST['uid'] ?? ''));
        $wid   = sanitize_text_field(wp_unslash($_POST['workflow_id'] ?? ''));
        $tag   = sanitize_text_field(wp_unslash($_POST['tag'] ?? ''));
        if ($uid === '' || $wid === '' || !in_array($tag, ['status', 'class'], true)) {
            wp_send_json_error('invalid_fields');
        }

        $items = $this->getStoredCustom();
        $found = false;
        $duplicate = false;
        foreach ($items as $idx => $it) {
            if (!empty($it['uid']) && $it['uid'] === $uid) {
                $found = true;
                $items[$idx]['workflow_id'] = $wid;
                $items[$idx]['tag'] = $tag;
                
                // Process email configuration
                $isEmail = !empty($_POST['is_email']) && sanitize_text_field(wp_unslash($_POST['is_email'])) === 'true';
                $allowSendOnce = ($tag === 'status');
                if ($isEmail) {
                    $emailDesc = sanitize_text_field(wp_unslash($_POST['email_description'] ?? ''));
                    $sendOnce = $allowSendOnce && !empty($_POST['send_once']) && sanitize_text_field(wp_unslash($_POST['send_once'])) === 'true';
                    $manualStates = [];
                    
                    if (!empty($_POST['manual_states']) && is_array($_POST['manual_states'])) {
                        $manualStatesRaw = array_map('sanitize_text_field', wp_unslash($_POST['manual_states']));
                        foreach ($manualStatesRaw as $state) {
                            $cleanState = $state;
                            if ($cleanState !== '') {
                                $manualStates[] = $cleanState;
                            }
                        }
                    }
                    
                    $items[$idx]['email_config'] = [
                        'is_email' => true,
                        'description' => $emailDesc,
                        'manual_states' => $manualStates
                    ];
                    if ($sendOnce) {
                        $items[$idx]['email_config']['send_once'] = true;
                    }
                } else {
                    // Remove email config if not an email workflow
                    unset($items[$idx]['email_config']);
                }
                
                // Process Holded workflow configuration (only for status transitions)
                $isHolded = !empty($_POST['is_holded']) && sanitize_text_field(wp_unslash($_POST['is_holded'])) === 'true';
                if ($isHolded && $tag === 'status') {
                    $holdedDesc = sanitize_text_field(wp_unslash($_POST['holded_description'] ?? ''));
                    $runOnce = !empty($_POST['holded_run_once']) && sanitize_text_field(wp_unslash($_POST['holded_run_once'])) === 'true';
                    $holdedManualStates = [];
                    
                    if (!empty($_POST['holded_manual_states']) && is_array($_POST['holded_manual_states'])) {
                        $holdedManualStatesRaw = array_map('sanitize_text_field', wp_unslash($_POST['holded_manual_states']));
                        foreach ($holdedManualStatesRaw as $state) {
                            $cleanState = $state;
                            if ($cleanState !== '') {
                                $holdedManualStates[] = $cleanState;
                            }
                        }
                    }
                    
                    $items[$idx]['workflow_config'] = [
                        'category' => 'holded',
                        'description' => $holdedDesc,
                        'manual_states' => $holdedManualStates
                    ];
                    if ($runOnce) {
                        $items[$idx]['workflow_config']['run_once'] = true;
                    }
                } else {
                    // Remove workflow config if not a Holded workflow
                    unset($items[$idx]['workflow_config']);
                }
                
                // Title: use provided one if given, otherwise auto-generate based on action type
                $givenTitle = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
                if ($tag === 'status') {
                    $from = sanitize_text_field(wp_unslash($_POST['from_status'] ?? ''));
                    $to   = sanitize_text_field(wp_unslash($_POST['to_status'] ?? ''));
                    if ($from === '' || $to === '') {
                        wp_send_json_error('invalid_status_fields');
                    }
                    // Normalize: convert label to slug if needed
                    $from = $this->labelToSlug($from);
                    $to = $this->labelToSlug($to);
                    $items[$idx]['from_status'] = $from;
                    $items[$idx]['to_status'] = $to;
                    $items[$idx]['title'] = ($givenTitle !== '') ? $givenTitle : $this->generateStatusTitle($from, $to);
                    unset($items[$idx]['class']);
                } elseif ($tag === 'class') {
                    $class = sanitize_text_field(wp_unslash($_POST['class'] ?? ''));
                    if ($class === '') {
                        wp_send_json_error('invalid_class');
                    }
                    
                    // For class actions with email config, generate class from button name
                    if ($isEmail && !empty($emailDesc)) {
                        // Generate CSS class from button name: flm-action-{sanitized-name}
                        $sanitizedName = sanitize_title($emailDesc);
                        $generatedClass = 'flm-action-' . $sanitizedName;
                        $items[$idx]['class'] = $generatedClass;
                        $items[$idx]['title'] = ($givenTitle !== '') ? $givenTitle : $emailDesc; // Default to button name
                        $items[$idx]['original_class'] = $class; // Keep original class for reference
                    } else {
                        $items[$idx]['class'] = $class;
                        $items[$idx]['title'] = ($givenTitle !== '') ? $givenTitle : $class;
                    }
                    unset($items[$idx]['from_status'], $items[$idx]['to_status']);
                }
            } elseif (!empty($it['workflow_id']) && $it['workflow_id'] === $wid) {
                $duplicate = true; // warn only
            }
        }

        if (!$found) {
            wp_send_json_error('not_found');
        }

        $this->saveStoredCustom($items);
        wp_send_json_success(['duplicate' => $duplicate]);
    }

    public function ajaxDeleteTrigger(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden');
        }
        if (!wp_verify_nonce(wp_unslash($_POST['nonce'] ?? ''), 'zs_admin_nonce')) {
            wp_send_json_error('bad_nonce');
        }

        $uid = sanitize_text_field(wp_unslash($_POST['uid'] ?? ''));
        if ($uid === '') {
            wp_send_json_error('invalid_uid');
        }

        $items = $this->getStoredCustom();
        $new = [];
        $removed = false;
        foreach ($items as $it) {
            if (!empty($it['uid']) && $it['uid'] === $uid) {
                $removed = true;
                continue;
            }
            $new[] = $it;
        }

        if (!$removed) {
            wp_send_json_error('not_found');
        }

        $this->saveStoredCustom($new);
        wp_send_json_success(['count' => count($new)]);
    }

    /**
     * Run a workflow by ID with test order data for better Flowmattic compatibility
     */
    public function ajaxRunWorkflow(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden');
        }
        if (!wp_verify_nonce(wp_unslash($_POST['nonce'] ?? ''), 'zs_admin_nonce')) {
            wp_send_json_error('bad_nonce');
        }
        $wid = sanitize_text_field(wp_unslash($_POST['workflow_id'] ?? ''));
        if ($wid === '') {
            wp_send_json_error('invalid_id');
        }

        // Try to get a real order for better Flowmattic compatibility
        $testOrder = $this->getTestOrder();
        $orderId = $testOrder ? $testOrder->get_id() : 0;
        
        // Add debug info for Flowmattic
        $debugData = [
            'workflow_id' => $wid,
            'order_id' => $orderId,
            'trigger_source' => 'zero_sense_dashboard_play',
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id()
        ];
        
        // Check if Flowmattic is active
        $flowmatticActive = $this->isFlowmatticActive();
        $debugData['flowmattic_active'] = $flowmatticActive;
        
        // Log the trigger attempt
        Logger::debug("Triggering Flowmattic workflow {$wid} with order ID {$orderId}", "Flowmattic active: " . ($flowmatticActive ? 'yes' : 'no'));
        
        if (!$flowmatticActive) {
            wp_send_json_error('Flowmattic plugin is not active or not found');
        }
        
        /**
         * Trigger Flowmattic using the correct hook (flowmattic_trigger_workflow)
         */
        Logger::debug("Triggering Flowmattic workflow {$wid} using flowmattic_trigger_workflow");
        do_action('flowmattic_trigger_workflow', $wid, $debugData);
        Logger::debug("Flowmattic workflow triggered successfully");
        
        wp_send_json_success([
            'workflow_id' => $wid,
            'order_id' => $orderId,
            'debug_data' => $debugData
        ]);
    }
    
    /**
     * Get a test order for Play button functionality
     */
    private function getTestOrder(): ?\WC_Order
    {
        // Try to get the most recent order
        $orders = wc_get_orders([
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'status' => ['wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed']
        ]);
        
        return !empty($orders) ? $orders[0] : null;
    }
    
    /**
     * Check if Flowmattic plugin is active and available
     */
    private function isFlowmatticActive(): bool
    {
        // Check if Flowmattic is active
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        // Check various ways Flowmattic might be installed
        $flowmatticPaths = [
            'flowmattic/flowmattic.php',
            'flowmattic-pro/flowmattic.php',
            'wp-flowmattic/flowmattic.php'
        ];
        
        foreach ($flowmatticPaths as $path) {
            if (function_exists('is_plugin_active') && is_plugin_active($path)) {
                return true;
            }
        }
        
        // Check if Flowmattic classes exist
        return class_exists('FlowMattic') || class_exists('Flowmattic') || function_exists('flowmattic_run_workflow');
    }
    
    // Runs counter removed

    /**
     * Generate title for status transitions using WooCommerce status labels
     */
    private function generateStatusTitle(string $from, string $to): string
    {
        $statusOptions = $this->getStatusOptions();
        
        $fromLabel = $statusOptions[$from] ?? ucfirst(str_replace('-', ' ', $from));
        $toLabel = $statusOptions[$to] ?? ucfirst(str_replace('-', ' ', $to));
        
        return $fromLabel . ' → ' . $toLabel;
    }

    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $integration = new Integration();
        $integration->register();
    }

    /**
     * Check if feature has information
     */
    public function hasInformation(): bool
    {
        return true;
    }

    /**
     * Get information blocks for dashboard
     */
    public function getInformationBlocks(): array
    {
        return [
            [
                'type' => 'list',
                'title' => __('Automation types', 'zero-sense'),
                'items' => [
                    __('Status Transitions - Automatic triggers on order status changes', 'zero-sense'),
                    __('Class Actions - Manual triggers via button/link clicks', 'zero-sense'),
                    __('Play Testing - Instant workflow testing from dashboard', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Key features', 'zero-sense'),
                'items' => [
                    __('CRUD interface for managing triggers', 'zero-sense'),
                    __('Real-time workflow execution', 'zero-sense'),
                    __('Order context detection', 'zero-sense'),
                    __('Silent operation - no interference with normal functionality', 'zero-sense'),
                    __('Comprehensive logging and debugging', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Data sent to Flowmattic', 'zero-sense'),
                'items' => [
                    __('workflow_id - Target Flowmattic workflow', 'zero-sense'),
                    __('order_id - WooCommerce order context', 'zero-sense'),
                    __('trigger_source - Source of the trigger', 'zero-sense'),
                    __('Status transitions: old_status, new_status', 'zero-sense'),
                    __('Class actions: class_name, has_order_context', 'zero-sense'),
                ],
            ],
            [
                'type' => 'text',
                'title' => __('Configuration', 'zero-sense'),
                'content' => __('Use the configuration panel above to add, edit, and test your Flowmattic triggers. All triggers are stored and managed through the dashboard interface.', 'zero-sense'),
            ],
            [
                'type' => 'list',
                'title' => __('Code map', 'zero-sense'),
                'items' => [
                    __('Feature: src/ZeroSense/Features/Integrations/Flowmattic/Flowmattic.php (dashboard UI, AJAX, config)', 'zero-sense'),
                    __('Runtime: src/ZeroSense/Features/Integrations/Flowmattic/Integration.php (workflows execution, hooks)', 'zero-sense'),
                    __('API: src/ZeroSense/Features/Integrations/Flowmattic/ApiExtension.php (REST + webhook data enrichment)', 'zero-sense'),
                    __('Order: src/ZeroSense/Features/Integrations/Flowmattic/OrderExtension.php (WC_Order extension)', 'zero-sense'),
                    __('Frontend: assets/js/admin.js (CRUD), assets/class-actions.js (silent class triggers)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Hooks & filters', 'zero-sense'),
                'items' => [
                    __('flowmattic_trigger_workflow (primary trigger)', 'zero-sense'),
                    __('woocommerce_order_status_changed (runtime status transitions)', 'zero-sense'),
                    __('woocommerce_rest_prepare_shop_order_object (API enrichment)', 'zero-sense'),
                    __('flowmattic/trigger/wc_order/data, flowmattic/trigger/wc_order/order_items (Flowmattic data)', 'zero-sense'),
                    __('woocommerce_webhook_payload (webhook enrichment)', 'zero-sense'),
                    __('wp_ajax_zs_flow_* (CRUD + play)', 'zero-sense'),
                ],
            ],
            [
                'type' => 'list',
                'title' => __('Testing notes', 'zero-sense'),
                'items' => [
                    __('Add a Status Transition and change an order status → expect workflow to fire with correct old/new status.', 'zero-sense'),
                    __('Add a Class Action and click a button/link with that class (with/without order context).', 'zero-sense'),
                    __('Use Play button to trigger workflow with most recent order for immediate verification.', 'zero-sense'),
                    __('Check logs for flowmattic_workflow_started/completed entries.', 'zero-sense'),
                    __('Verify multilingual payment_url_* fields are present in REST/webhook payloads.', 'zero-sense'),
                ],
            ],
        ];
    }

    /**
     * Add 'Sent Emails' column to WooCommerce Orders list table
     */
    public function addSentEmailsOrderColumn(array $columns): array
    {
        // Place near order status
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'order_status') {
                $new['zs_email_logs'] = __('Email Logs', 'zero-sense');
            }
        }
        // Fallback if hook order differs
        if (!isset($new['zs_email_logs'])) {
            $new['zs_email_logs'] = __('Email Logs', 'zero-sense');
        }
        return $new;
    }

    /**
     * Render 'Sent Emails' column content for each order row
     */
    public function renderSentEmailsOrderColumn(string $column, int $postId): void
    {
        if ($column !== 'zs_email_logs') {
            return;
        }
        
        $order = wc_get_order($postId);
        if (!$order instanceof \WC_Order) {
            echo '&mdash;';
            return;
        }

        $emailLogs = $this->getEmailLogsForOrder($postId);
        
        if (empty($emailLogs)) {
            echo '<span class="dashicons dashicons-minus"></span>';
            return;
        }

        // Sort by timestamp (newest first)
        usort($emailLogs, function($a, $b) {
            return $b['timestamp'] <=> $a['timestamp'];
        });
        
        $latest = $emailLogs[0];
        $listId = 'zs-sent-emails-' . $postId;
        
        // Status badge
        $badge = $this->getStatusBadge($latest['status']);
        
        // Short description
        $shortDesc = $this->getShortDescription($latest['description']);
        
        // Render compact cell (styles loaded via admin-components.css)
        echo '<div class="zs-sent-emails-cell zs-cell">';
        echo '<div class="zs-title" title="' . esc_attr($latest['description']) . '">' . $badge . '<span>' . esc_html($shortDesc) . '</span></div>';
        echo '<small class="zs-time" title="' . esc_attr($latest['formatted_time']) . '">' . esc_html($latest['formatted_time']) . '</small>';
        
        if (count($emailLogs) > 1) {
            echo '<a href="#" class="zs-toggle-btn" data-target="' . esc_attr($listId) . '" aria-expanded="false">' . esc_html__('Show all', 'zero-sense') . '</a>';
            echo '<ul id="' . esc_attr($listId) . '" class="zs-list">';
            
            // Skip first (latest) entry to avoid duplication
            for ($i = 1; $i < count($emailLogs); $i++) {
                $log = $emailLogs[$i];
                $badge = $this->getStatusBadge($log['status']);
                $shortDesc = $this->getShortDescription($log['description']);
                
                echo '<li>' . $badge . '<strong title="' . esc_attr($log['description']) . '">' . esc_html($shortDesc) . '</strong> <small title="' . esc_attr($log['formatted_time']) . '">' . esc_html($log['formatted_time']) . '</small></li>';
            }
            
            echo '</ul>';
        }
        
        echo '</div>';
        
        // Add toggle JavaScript
        echo '<script>
        (function(){
            var btn = document.querySelector("a.zs-toggle-btn[data-target=\"' . esc_js($listId) . '\"]");
            if (!btn) return;
            btn.addEventListener("click", function(e) {
                e.preventDefault();
                var list = document.getElementById("' . esc_js($listId) . '");
                if (!list) return;
                var isOpen = list.style.display === "block";
                list.style.display = isOpen ? "none" : "block";
                btn.setAttribute("aria-expanded", String(!isOpen));
                btn.textContent = isOpen ? "' . esc_js(__('Show all', 'zero-sense')) . '" : "' . esc_js(__('Hide', 'zero-sense')) . '";
            });
        })();
        </script>';
    }

    /**
     * Get email logs for a specific order (using new generic system)
     */
    private function getEmailLogsForOrder(int $orderId): array
    {
        $executions = $this->getWorkflowExecutionsForOrder($orderId, 'email');
        $logs = [];
        
        foreach ($executions as $exec) {
            // getWorkflowExecutionsForOrder already returns timestamp as Unix timestamp
            $logs[] = [
                'workflow_id' => $exec['workflow_id'],
                'status' => $exec['status'],
                'description' => $exec['description'], // Already computed by getWorkflowExecutionsForOrder
                'timestamp' => $exec['timestamp'], // Already Unix timestamp
                'formatted_time' => $exec['formatted_time'], // Already formatted
                'email_to' => $exec['metadata']['email_to'] ?? '',
                'email_subject' => $exec['metadata']['email_subject'] ?? '',
                'error_message' => $exec['error_message'] ?? null,
                'by_name' => $exec['by_name'] ?? '',
                'by_login' => $exec['by_login'] ?? '',
            ];
        }
        
        // Sort by timestamp descending (newest first)
        usort($logs, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        return $logs;
    }

    /**
     * Get status badge HTML
     */
    private function getStatusBadge(string $status): string
    {
        switch ($status) {
            case self::EMAIL_STATUS_AUTO:
                return '<span class="zs-badge zs-badge-auto">' . esc_html__('AUTO', 'zero-sense') . '</span>';
            case self::EMAIL_STATUS_MANUAL:
                return '<span class="zs-badge zs-badge-manual">' . esc_html__('MAN', 'zero-sense') . '</span>';
            case self::EMAIL_STATUS_ERROR:
                return '<span class="zs-badge zs-badge-error">' . esc_html__('ERROR', 'zero-sense') . '</span>';
            case self::EMAIL_STATUS_SKIPPED:
                return '<span class="zs-badge zs-badge-skipped">' . esc_html__('SKIPPED', 'zero-sense') . '</span>';
            default:
                return '<span class="zs-badge zs-badge-auto">' . esc_html__('AUTO', 'zero-sense') . '</span>';
        }
    }

    /**
     * Get short description for compact display
     */
    private function getShortDescription(string $description): string
    {
        $shortMap = [
            'Form Received Confirmation' => __('Form Received', 'zero-sense'),
            'Budget Proposal' => __('Budget Proposal', 'zero-sense'),
            'Reminder' => __('Reminder', 'zero-sense'),
            'Ultimatum' => __('Ultimatum', 'zero-sense'),
            'Bank Details Information' => __('Bank Details', 'zero-sense'),
            'Deposit Paid Confirmation' => __('Deposit Paid', 'zero-sense'),
            'Fully Paid Confirmation — Direct' => __('Fully Paid — Dir', 'zero-sense'),
            'Fully Paid Confirmation — After Deposit' => __('Fully Paid — Dep', 'zero-sense'),
            'Final Details' => __('Final Details', 'zero-sense'),
            'Completed' => __('Completed', 'zero-sense'),
            'Send Invoice' => __('Invoice', 'zero-sense'),
            'Payment Reminder' => __('Payment Rem.', 'zero-sense'),
            'Order Confirmation' => __('Order Conf.', 'zero-sense'),
        ];
        
        return $shortMap[$description] ?? $description;
    }

    /**
     * Add email logs metabox to order admin
     */
    public function addEmailLogsMetabox(): void
    {
        $screen = get_current_screen();
        if ($screen && in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            $screen_id = $screen->id === 'woocommerce_page_wc-orders' ? wc_get_page_screen_id('shop-order') : 'shop_order';
            
            add_meta_box(
                'zs_email_logs',
                __('Email Logs', 'zero-sense'),
                [$this, 'renderEmailLogsMetabox'],
                $screen_id,
                'normal',
                'default'
            );
        }
    }

    /**
     * Render email logs metabox
     */
    public function renderEmailLogsMetabox($postOrOrder): void
    {
        $orderId = 0;
        if ($postOrOrder instanceof \WP_Post) {
            $orderId = $postOrOrder->ID;
        } elseif ($postOrOrder instanceof \WC_Order) {
            $orderId = $postOrOrder->get_id();
        }
        $emailLogs = $this->getEmailLogsForOrder($orderId);
        
        if (empty($emailLogs)) {
            echo '<p>' . esc_html__('No email logs found for this order.', 'zero-sense') . '</p>';
            return;
        }
        
        $totalLogs = count($emailLogs);
        $listId = 'zs-email-logs-' . $orderId;
        
        echo '<div class="zs-email-logs-metabox">';
        
        // Header actions (right-aligned): SMTP Logs
        $order = wc_get_order($orderId);
        $billing_email = $order ? $order->get_billing_email() : '';
        $smtp_url = 'https://paellasencasa.com/wp-admin/options-general.php?page=fluent-mail#/logs?per_page=10&page=1&status=&search=' . urlencode($billing_email);
        echo '<div style="display:flex; justify-content:flex-end; margin-bottom:8px;">'
            . '<a href="' . esc_url($smtp_url) . '" target="_blank" rel="noopener" class="button-link" style="font-size:12px; color:#2271b1; text-decoration:underline;">'
            . esc_html__('SMTP Logs', 'zero-sense')
            . '</a>'
            . '</div>';
        
        // Show first 3 logs
        for ($i = 0; $i < min(3, $totalLogs); $i++) {
            $log = $emailLogs[$i];
            $this->renderSingleEmailLog($log, false);
        }
        
        // Show remaining logs (hidden initially)
        if ($totalLogs > 3) {
            echo '<div id="' . esc_attr($listId) . '" class="zs-hidden-logs">';
            for ($i = 3; $i < $totalLogs; $i++) {
                $log = $emailLogs[$i];
                $this->renderSingleEmailLog($log, false);
            }
            echo '</div>';
            
            echo '<div class="zs-show-more">';
            echo '<button type="button" class="zs-toggle-logs" data-target="' . esc_attr($listId) . '" data-total="' . esc_attr($totalLogs - 3) . '">';
            echo sprintf(esc_html__('Show %d more', 'zero-sense'), $totalLogs - 3);
            echo '</button>';
            echo '</div>';
        }
        
        echo '</div>';
        
        // Add toggle JavaScript
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            var toggleBtn = document.querySelector(".zs-toggle-logs[data-target=\"' . esc_js($listId) . '\"]");
            if (!toggleBtn) return;
            
            toggleBtn.addEventListener("click", function() {
                var hiddenLogs = document.getElementById("' . esc_js($listId) . '");
                var total = parseInt(this.getAttribute("data-total"));
                
                if (hiddenLogs.style.display === "block") {
                    hiddenLogs.style.display = "none";
                    this.textContent = "' . esc_js(__('Show', 'zero-sense')) . ' " + total + " ' . esc_js(__('more', 'zero-sense')) . '";
                } else {
                    hiddenLogs.style.display = "block";
                    this.textContent = "' . esc_js(__('Hide', 'zero-sense')) . '";
                }
            });
            
        });
        </script>';
    }

    /**
     * Render a single email log item
     */
    private function renderSingleEmailLog(array $log, bool $isCompact = true): void
    {
        $statusClass = 'zs-' . $log['status'];
        $badge = $this->getStatusBadge($log['status']);
        
        echo '<div class="zs-log-item ' . esc_attr($statusClass) . '">';
        echo $badge;
        echo '<div class="zs-log-title">';
        echo '<strong>' . esc_html($log['description']) . '</strong>';
        echo '</div>';
        $by = '';
        if (!empty($log['by_name']) || !empty($log['by_login'])) {
            $label = $log['by_name'] !== ''
                ? ($log['by_login'] !== '' ? ($log['by_name'] . ' (' . $log['by_login'] . ')') : $log['by_name'])
                : $log['by_login'];
            if ($label !== '') {
                $by = ' · ' . sprintf(__('By: %s', 'zero-sense'), esc_html($label));
            }
        }
        echo '<div class="zs-log-time">' . esc_html($log['formatted_time']) . $by . '</div>';
        
        if (!empty($log['email_to'])) {
            echo '<div class="zs-log-details"><strong>' . esc_html__('To:', 'zero-sense') . '</strong> ' . esc_html($log['email_to']) . '</div>';
        }
        
        if (!empty($log['email_subject'])) {
            echo '<div class="zs-log-details"><strong>' . esc_html__('Subject:', 'zero-sense') . '</strong> ' . esc_html($log['email_subject']) . '</div>';
        }
        
        if (!empty($log['error_message'])) {
            if (($log['status'] ?? '') === self::EMAIL_STATUS_SKIPPED) {
                // Skipped is not a real error: show neutral note without the word "Error"
                echo '<div class="zs-log-note">' . esc_html($log['error_message']) . '</div>';
            } else {
                echo '<div class="zs-log-error"><strong>' . esc_html__('Error:', 'zero-sense') . '</strong> ' . esc_html($log['error_message']) . '</div>';
            }
        }
        
        echo '</div>';
    }

    // ========================================================================
    // HOLDED SYNC METABOXES
    // ========================================================================

    /**
     * Add Holded Actions metabox to order admin (only if there are manual actions)
     */
    public function addHoldedActionsMetabox(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            return;
        }
        
        // Get current order
        global $post;
        $orderId = 0;
        
        if ($post instanceof \WP_Post) {
            $orderId = $post->ID;
        } elseif (isset($_GET['id'])) {
            $orderId = absint($_GET['id']);
        }
        
        if ($orderId <= 0) {
            return;
        }
        
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return;
        }
        
        $orderStatus = $order->get_status();
        
        // Check if there are any manual actions available for this order status
        $stored = get_option('zs_flowmattic_custom_triggers', []);
        $hasManualActions = false;
        
        foreach ($stored as $trigger) {
            if (!empty($trigger['workflow_config']['category']) && 
                $trigger['workflow_config']['category'] === 'holded' &&
                ($trigger['tag'] ?? '') === 'status') {
                
                $manualStates = $trigger['workflow_config']['manual_states'] ?? [];
                if (!empty($manualStates) && in_array($orderStatus, $manualStates, true)) {
                    $hasManualActions = true;
                    break;
                }
            }
        }
        
        // Only add metabox if there are manual actions
        if ($hasManualActions) {
            $screen_id = $screen->id === 'woocommerce_page_wc-orders' ? wc_get_page_screen_id('shop-order') : 'shop_order';
            
            add_meta_box(
                'zs_holded_actions',
                __('Holded Actions', 'zero-sense'),
                [$this, 'renderHoldedActionsMetabox'],
                $screen_id,
                'side',
                'default'
            );
        }
    }

    /**
     * Render Holded Actions metabox (manual buttons only)
     */
    public function renderHoldedActionsMetabox($postOrOrder): void
    {
        $orderId = 0;
        if ($postOrOrder instanceof \WP_Post) {
            $orderId = $postOrOrder->ID;
        } elseif (method_exists($postOrOrder, 'get_id')) {
            $orderId = $postOrOrder->get_id();
        }
        
        if ($orderId <= 0) {
            echo '<p>' . esc_html__('Invalid order', 'zero-sense') . '</p>';
            return;
        }
        
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            echo '<p>' . esc_html__('Order not found', 'zero-sense') . '</p>';
            return;
        }
        
        $orderStatus = $order->get_status();
        
        // Get Holded triggers
        $stored = get_option('zs_flowmattic_custom_triggers', []);
        $holdedTriggers = [];
        
        foreach ($stored as $trigger) {
            if (!empty($trigger['workflow_config']['category']) && 
                $trigger['workflow_config']['category'] === 'holded' &&
                ($trigger['tag'] ?? '') === 'status') {
                $holdedTriggers[] = $trigger;
            }
        }
        
        if (empty($holdedTriggers)) {
            echo '<p style="color:#666;font-size:12px;">' . esc_html__('No Holded actions configured', 'zero-sense') . '</p>';
            return;
        }
        
        echo '<div class="zs-manual-email-actions">';
        
        // Manual buttons (filtered by manual_states)
        $manualButtons = [];
        foreach ($holdedTriggers as $trigger) {
            $manualStates = $trigger['workflow_config']['manual_states'] ?? [];
            if (!empty($manualStates) && in_array($orderStatus, $manualStates, true)) {
                $manualButtons[] = $trigger;
            }
        }
        
        if (empty($manualButtons)) {
            echo '<p style="color:#666;font-size:12px;">' . esc_html__('No manual actions available for this order status', 'zero-sense') . '</p>';
        } else {
            foreach ($manualButtons as $trigger) {
                $workflowId = $trigger['workflow_id'];
                $description = $trigger['workflow_config']['description'] ?? $trigger['title'];
                $status = $this->getWorkflowExecutionStatus($workflowId, $orderId);
                $badge = $status ? $this->getStatusBadge($status) : '';
                
                echo '<button type="button" class="zs-btn is-action zs-manual-holded-btn" data-workflow-id="' . esc_attr($workflowId) . '" data-order-id="' . esc_attr($orderId) . '" style="width:100%;margin-bottom:6px;position:relative;">';
                echo '<span class="zs-email-btn-label">' . esc_html($description) . '</span>';
                echo $badge;
                echo '</button>';
            }
        }
        
        echo '</div>';
        
        // Add JavaScript for AJAX handling
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.zs-manual-holded-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var workflowId = this.getAttribute('data-workflow-id');
                    var orderId = this.getAttribute('data-order-id');
                    var originalText = this.querySelector('.zs-email-btn-label').textContent;
                    
                    this.disabled = true;
                    this.querySelector('.zs-email-btn-label').textContent = '<?php echo esc_js(__('Syncing...', 'zero-sense')); ?>';
                    
                    var data = {
                        action: 'zs_flow_trigger_holded_sync',
                        nonce: '<?php echo esc_js(wp_create_nonce('zs_holded_sync_nonce')); ?>',
                        workflow_id: workflowId,
                        order_id: orderId
                    };
                    
                    var self = this;
                    jQuery.post(ajaxurl, data, function(response) {
                        if (response.success) {
                            self.querySelector('.zs-email-btn-label').textContent = originalText;
                            // Update badge
                            var existingBadge = self.querySelector('.zs-badge');
                            if (existingBadge) {
                                existingBadge.remove();
                            }
                            var badge = document.createElement('span');
                            badge.className = 'zs-badge zs-badge-manual';
                            badge.textContent = 'MAN';
                            self.appendChild(badge);
                            
                            alert('<?php echo esc_js(__('Holded sync triggered successfully', 'zero-sense')); ?>');
                        } else {
                            alert('<?php echo esc_js(__('Error triggering sync', 'zero-sense')); ?>: ' + (response.data || 'unknown'));
                        }
                        self.disabled = false;
                    }).fail(function() {
                        alert('<?php echo esc_js(__('AJAX error', 'zero-sense')); ?>');
                        self.disabled = false;
                        self.querySelector('.zs-email-btn-label').textContent = originalText;
                    });
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Add Holded Logs metabox to order admin
     */
    public function addHoldedLogsMetabox(): void
    {
        $screen = get_current_screen();
        if ($screen && in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            $screen_id = $screen->id === 'woocommerce_page_wc-orders' ? wc_get_page_screen_id('shop-order') : 'shop_order';
            
            add_meta_box(
                'zs_holded_logs',
                __('Holded Logs', 'zero-sense'),
                [$this, 'renderHoldedLogsMetabox'],
                $screen_id,
                'normal',
                'default'
            );
        }
    }

    /**
     * Render Holded Logs metabox
     */
    public function renderHoldedLogsMetabox($postOrOrder): void
    {
        $orderId = 0;
        if ($postOrOrder instanceof \WP_Post) {
            $orderId = $postOrOrder->ID;
        } elseif (method_exists($postOrOrder, 'get_id')) {
            $orderId = $postOrOrder->get_id();
        }
        
        if ($orderId <= 0) {
            echo '<p>' . esc_html__('Invalid order', 'zero-sense') . '</p>';
            return;
        }
        
        $logs = $this->getWorkflowExecutionsForOrder($orderId, 'holded');
        
        if (empty($logs)) {
            echo '<p style="color:#666;font-size:12px;">' . esc_html__('No Holded sync logs yet', 'zero-sense') . '</p>';
            return;
        }
        
        // Sort by timestamp descending
        usort($logs, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        echo '<div class="zs-email-logs-metabox">';
        
        // Show first 3 logs
        $visible = array_slice($logs, 0, 3);
        $hidden = array_slice($logs, 3);
        
        foreach ($visible as $log) {
            $this->renderSingleWorkflowLog($log);
        }
        
        if (!empty($hidden)) {
            $listId = 'zs-holded-hidden-logs-' . $orderId;
            echo '<div class="zs-show-more">';
            echo '<button type="button" class="zs-toggle-logs" data-target="' . esc_attr($listId) . '" data-total="' . esc_attr(count($hidden)) . '">';
            echo esc_html(sprintf(__('Show %d more', 'zero-sense'), count($hidden)));
            echo '</button>';
            echo '</div>';
            
            echo '<div id="' . esc_attr($listId) . '" class="zs-hidden-logs" style="display:none;">';
            foreach ($hidden as $log) {
                $this->renderSingleWorkflowLog($log);
            }
            echo '</div>';
            
            // Add toggle JavaScript
            echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                var toggleBtn = document.querySelector(".zs-toggle-logs[data-target=\"' . esc_js($listId) . '\"]");
                if (!toggleBtn) return;
                
                toggleBtn.addEventListener("click", function() {
                    var hiddenLogs = document.getElementById("' . esc_js($listId) . '");
                    var total = parseInt(this.getAttribute("data-total"));
                    
                    if (hiddenLogs.style.display === "block") {
                        hiddenLogs.style.display = "none";
                        this.textContent = "' . esc_js(__('Show', 'zero-sense')) . ' " + total + " ' . esc_js(__('more', 'zero-sense')) . '";
                    } else {
                        hiddenLogs.style.display = "block";
                        this.textContent = "' . esc_js(__('Hide', 'zero-sense')) . '";
                    }
                });
            });
            </script>';
        }
        
        echo '</div>';
    }

    /**
     * Render a single workflow log item (for Holded logs)
     */
    private function renderSingleWorkflowLog(array $log): void
    {
        $statusClass = 'zs-' . $log['status'];
        $badge = $this->getStatusBadge($log['status']);
        
        echo '<div class="zs-log-item ' . esc_attr($statusClass) . '">';
        echo $badge;
        echo '<div class="zs-log-title">';
        echo '<strong>' . esc_html($log['description']) . '</strong>';
        echo '</div>';
        
        $by = '';
        if (!empty($log['by_name']) || !empty($log['by_login'])) {
            $label = $log['by_name'] !== ''
                ? ($log['by_login'] !== '' ? ($log['by_name'] . ' (' . $log['by_login'] . ')') : $log['by_name'])
                : $log['by_login'];
            if ($label !== '') {
                $by = ' · ' . sprintf(__('By: %s', 'zero-sense'), esc_html($label));
            }
        }
        echo '<div class="zs-log-time">' . esc_html($log['formatted_time']) . $by . '</div>';
        
        // Show metadata if available
        if (!empty($log['metadata']['old_status']) && !empty($log['metadata']['new_status'])) {
            echo '<div class="zs-log-details"><strong>' . esc_html__('Transition:', 'zero-sense') . '</strong> ' . 
                esc_html($log['metadata']['old_status']) . ' → ' . esc_html($log['metadata']['new_status']) . '</div>';
        }
        
        if (!empty($log['error_message'])) {
            if (($log['status'] ?? '') === self::WORKFLOW_STATUS_SKIPPED) {
                echo '<div class="zs-log-note">' . esc_html($log['error_message']) . '</div>';
            } else {
                echo '<div class="zs-log-error"><strong>' . esc_html__('Error:', 'zero-sense') . '</strong> ' . esc_html($log['error_message']) . '</div>';
            }
        }
        
        echo '</div>';
    }


    /**
     * AJAX: Trigger Holded sync manually
     */
    public function ajaxTriggerHoldedSync(): void
    {
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error('forbidden');
        }
        
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'zs_holded_sync_nonce')) {
            wp_send_json_error('bad_nonce');
        }
        
        $workflowId = sanitize_text_field(wp_unslash($_POST['workflow_id'] ?? ''));
        $orderId = intval(wp_unslash($_POST['order_id'] ?? 0));
        
        if (!$workflowId || !$orderId) {
            wp_send_json_error('missing_parameters');
        }
        
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            wp_send_json_error('invalid_order');
        }
        
        // Verify this is a valid Holded trigger
        $stored = get_option('zs_flowmattic_custom_triggers', []);
        $validTrigger = false;
        
        foreach ($stored as $trigger) {
            if (($trigger['workflow_id'] ?? '') === $workflowId &&
                !empty($trigger['workflow_config']['category']) &&
                $trigger['workflow_config']['category'] === 'holded') {
                $validTrigger = true;
                break;
            }
        }
        
        if (!$validTrigger) {
            wp_send_json_error('invalid_trigger');
        }
        
        // Log manual execution
        $this->logWorkflowExecution(
            $workflowId,
            $orderId,
            self::WORKFLOW_STATUS_MANUAL,
            [
                'category' => 'holded',
                'trigger_source' => 'manual_button'
            ]
        );
        
        // Trigger workflow
        try {
            do_action('flowmattic_trigger_workflow', $workflowId, [
                'order_id' => $orderId,
                'trigger_source' => 'manual_holded_sync'
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error([
                'code' => 'workflow_exception',
                'message' => $e->getMessage()
            ]);
        }
        
        wp_send_json_success([
            'message' => 'Holded sync triggered successfully',
            'workflow_id' => $workflowId,
            'order_id' => $orderId
        ]);
    }
}
