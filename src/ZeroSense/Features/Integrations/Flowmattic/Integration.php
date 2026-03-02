<?php
namespace ZeroSense\Features\Integrations\Flowmattic;

use WC_Order;
use WP_Error;

class Integration
{
    private const MANUAL_TRIGGER_NONCE = 'zero_sense_flowmattic_manual_trigger';

    /**
     * @var array<string, array<string, string|array<string>>>
     */
    private static array $workflowTriggers = [];

    /**
     * @var array<int, array{from:string,to:string,id:string}>
     */
    private static array $orderedTriggers = [];

    /**
     * @var array<string, string>
     */
    private static array $manualTriggers = [];

    /**
     * @var array Active workflow execution contexts for email tracking
     */
    private static array $activeWorkflowContexts = [];


    public function register(): void
    {
        if (!function_exists('zs_format_event_date_for_admin')) {
            require_once dirname(__DIR__, 2) . '/Utilities/helpers/order-event-date-formatter.php';
        }

        // Hook into WooCommerce order status changes
        add_action('woocommerce_order_status_changed', [$this, 'onOrderStatusChanged'], 10, 4);

        // Setup triggers when WordPress initializes
        add_action('init', [$this, 'setupTriggers']);
        // Prime the runtime immediately so first request has triggers available
        $this->setupTriggers();

        // Hook for Class Actions triggered by button clicks
        add_action('wp_ajax_zs_trigger_class_action', [$this, 'handleClassActionAjax']);
        add_action('wp_ajax_nopriv_zs_trigger_class_action', [$this, 'handleClassActionAjax']);

        // Add JavaScript for Class Action detection
        add_action('wp_enqueue_scripts', [$this, 'enqueueClassActionScript']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueClassActionScript']);

        // No metaboxes, admin columns or manual order actions; all control moves to backend-stored triggers.

        (new ApiExtension())->register();
        OrderExtension::init();

        // Reconstruct workflow context before FlowMattic resumes a delayed workflow.
        // Priority 1 runs before FlowMattic's own handler at priority 10.
        add_action('flowmattic_delay_workflow_step', [$this, 'restoreContextBeforeDelayedStep'], 1, 3);
        add_action('flowmattic_delay_workflow_route', [$this, 'restoreContextBeforeDelayedStep'], 1, 3);

        // Async runner for scheduled Flowmattic workflows (status transitions)
        add_action('zs_run_flowmattic_workflow', [$this, 'runScheduledWorkflow'], 10, 2);

        // Cleanup hook
        add_action('zs_cleanup_workflow_context', [$this, 'cleanupWorkflowContext'], 10, 2);

        // Add email logs to order list and order admin (Classic + HPOS)
        add_filter('manage_edit-shop_order_columns', [$this, 'addSentEmailsOrderColumn']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'renderSentEmailsOrderColumn'], 10, 2);
        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'addSentEmailsOrderColumn']);
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'renderSentEmailsOrderColumnHpos'], 10, 2);
        add_action('add_meta_boxes', [$this, 'addEmailLogsMetabox']);

        // Track real email delivery results
        add_filter('wp_mail', [$this, 'trackEmailSend']);
        add_action('wp_mail_failed', [$this, 'trackEmailFailure']);
    }

    /**
     * Log Flowmattic execution
     * Track email sends during workflow execution
     */
    public function trackEmailSend(array $atts): array
    {
        if (!empty(self::$activeWorkflowContexts)) {
            $context = end(self::$activeWorkflowContexts);
            if ($context && !empty($context['workflow_id']) && !empty($context['order_id'])) {
                // Determine status based on trigger source
                $status = (strpos($context['trigger_source'] ?? '', 'manual') !== false) ? 'manual' : 'auto';

                // Log successful email send
                $this->logEmailToFlowmattic(
                    $context['workflow_id'],
                    $context['order_id'],
                    $status,
                    [
                        'to' => is_array($atts['to']) ? implode(', ', $atts['to']) : $atts['to'],
                        'subject' => $atts['subject'],
                        'trigger_source' => $context['trigger_source'] ?? 'unknown'
                    ]
                );
            }
        } else {
            // No active context in memory - try to recover from transients
            // This handles cases where FlowMattic sends emails asynchronously
            $this->attemptTransientContextRecovery($atts);
        }

        return $atts;
    }
    
    /**
     * Attempt to recover workflow context from transients when email is sent asynchronously
     */
    private function attemptTransientContextRecovery(array $atts): void
    {
        // Get all stored workflow triggers to find potential matches
        $stored = get_option('zs_flowmattic_custom_triggers', []);
        if (!is_array($stored)) {
            return;
        }
        
        // Look for recently active workflow contexts
        foreach ($stored as $trigger) {
            $workflowId = $trigger['workflow_id'] ?? '';
            if (!$workflowId) {
                continue;
            }
            
            $orderId = (int) get_transient('zs_wf_ctx_idx_' . $workflowId);
            if ($orderId <= 0) {
                continue;
            }
            
            $context = get_transient('zs_wf_ctx_' . $workflowId . '_' . $orderId);
            if (!is_array($context)) {
                continue;
            }
            
            // Found a valid context - use it
            $status = (strpos($context['trigger_source'] ?? '', 'manual') !== false) ? 'manual' : 'auto';
            
            $this->logEmailToFlowmattic(
                $workflowId,
                $orderId,
                $status,
                [
                    'to' => is_array($atts['to']) ? implode(', ', $atts['to']) : $atts['to'],
                    'subject' => $atts['subject'],
                    'trigger_source' => $context['trigger_source'] ?? 'unknown'
                ]
            );
            
            // Only log once per email
            break;
        }
    }

    /**
     * Track email failures
     */
    public function trackEmailFailure(\WP_Error $error): void
    {
        if (!empty(self::$activeWorkflowContexts)) {
            $context = end(self::$activeWorkflowContexts);
            if ($context && !empty($context['workflow_id']) && !empty($context['order_id'])) {
                // Log failed email send
                $this->logEmailToFlowmattic(
                    $context['workflow_id'],
                    $context['order_id'],
                    'error',
                    [
                        'error' => $error->get_error_message(),
                        'trigger_source' => $context['trigger_source'] ?? 'unknown'
                    ]
                );
            }
        }
    }

    /**
     * Log email send to Flowmattic feature
     */
    private function logEmailToFlowmattic(string $workflowId, int $orderId, string $status, array $emailData = []): void
    {
        // Get the Flowmattic feature instance to log the email
        $flowmatticFeature = new \ZeroSense\Features\Integrations\Flowmattic\Flowmattic();
        $flowmatticFeature->logEmailSend($workflowId, $orderId, $status, $emailData);
    }

    // Removed BACS-specific helpers that referenced hardcoded workflow IDs.

    public static function getCustomTriggers(): array
    {
        // Only return triggers stored via the dashboard (no hardcoded defaults)
        $result = [];
        $seen = [];
        $stored = get_option('zs_flowmattic_custom_triggers', []);
        $stored = is_array($stored) ? $stored : [];
        foreach ($stored as $entry) {
            if (!empty($entry['workflow_id']) && !empty($entry['title'])) {
                $key = (string) ($entry['title'] ?? '') . '|' . (string) ($entry['workflow_id'] ?? '');
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $result[] = [
                        'label' => (string) $entry['title'],
                        'workflow_id' => (string) $entry['workflow_id'],
                    ];
                }
            }
        }

        return $result;
    }

    public static function getWorkflowTriggers(bool $ordered = false, ?string $type = 'status'): array
    {
        if (empty(self::$workflowTriggers)) {
            (new self())->setupTriggers();
        }

        if ($type === 'status') {
            return $ordered ? self::$orderedTriggers : self::$workflowTriggers;
        }

        if ($type === 'manual') {
            return self::$manualTriggers;
        }

        return [
            'status' => self::$workflowTriggers,
            'status_ordered' => self::$orderedTriggers,
            'manual' => self::$manualTriggers,
        ];
    }

    public function setupTriggers(): void
    {
        // Initialize empty maps. Only populate from backend stored configuration.
        self::$workflowTriggers = [];
        self::$orderedTriggers = [];
        self::$manualTriggers = [];

        // Inject stored Status Transitions and Class Actions from dashboard into runtime
        $stored = get_option('zs_flowmattic_custom_triggers', []);
        if (is_array($stored)) {
            foreach ($stored as $row) {
                $tag = $row['tag'] ?? '';

                if ($tag === 'status') {
                    // Handle Status Transitions
                    $from = isset($row['from_status']) ? (string) $row['from_status'] : '';
                    $to = isset($row['to_status']) ? (string) $row['to_status'] : '';
                    $id = isset($row['workflow_id']) ? (string) $row['workflow_id'] : '';
                    if ($from === '' || $to === '' || $id === '') {
                        continue;
                    }

                    // Normalize statuses to match our keys
                    $from = $this->normalizeStatus($from);
                    $to = $this->normalizeStatus($to);

                    // Assign into matrix, supporting multiple IDs per transition
                    if (!isset(self::$workflowTriggers[$from][$to])) {
                        self::$workflowTriggers[$from][$to] = $id;
                    } else {
                        $existing = self::$workflowTriggers[$from][$to];
                        if (is_array($existing)) {
                            if (!in_array($id, $existing, true)) {
                                $existing[] = $id;
                            }
                            self::$workflowTriggers[$from][$to] = $existing;
                        } else {
                            if ($existing !== $id) {
                                self::$workflowTriggers[$from][$to] = [$existing, $id];
                            }
                        }
                    }

                    // Keep ordered reference list
                    self::$orderedTriggers[] = ['from' => $from, 'to' => $to, 'id' => $id];
                } elseif ($tag === 'class') {
                    // Handle Class Actions
                    $class = isset($row['class']) ? (string) $row['class'] : '';
                    $id = isset($row['workflow_id']) ? (string) $row['workflow_id'] : '';
                    if ($class !== '' && $id !== '') {
                        // Add to manual triggers (support multiple workflows per class)
                        if (!isset(self::$manualTriggers[$class])) {
                            self::$manualTriggers[$class] = $id;
                        } else {
                            $existing = self::$manualTriggers[$class];
                            if (is_array($existing)) {
                                if (!in_array($id, $existing, true)) {
                                    $existing[] = $id;
                                }
                                self::$manualTriggers[$class] = $existing;
                            } else {
                                if ($existing !== $id) {
                                    self::$manualTriggers[$class] = [$existing, $id];
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Handle WooCommerce order status changes
     */
    public function onOrderStatusChanged(int $orderId, string $oldStatus, string $newStatus, WC_Order $order): void
    {
        $this->maybeTriggerWorkflow($orderId, $oldStatus, $newStatus, $order);
    }

    private function maybeTriggerWorkflow(int $orderId, string $oldStatus, string $newStatus, WC_Order $order): void
    {
        // DEBUG LOG: Log every status change attempt
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->debug('Flowmattic: Status change detected', [
                'source' => 'zero-sense-flowmattic-debug',
                'order_id' => $orderId,
                'old_status_raw' => $oldStatus,
                'new_status_raw' => $newStatus,
                'is_admin' => is_admin() ? 'yes' : 'no',
                'doing_ajax' => defined('DOING_AJAX') && DOING_AJAX ? 'yes' : 'no',
                'ajax_action' => $_POST['action'] ?? 'none',
            ]);
        }

        if (empty(self::$workflowTriggers)) {
            $this->setupTriggers();
        }
        $workflowIds = $this->resolveWorkflowsForTransition($oldStatus, $newStatus);

        // DEBUG LOG: Log workflow resolution result
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->debug('Flowmattic: Workflow resolution', [
                'source' => 'zero-sense-flowmattic-debug',
                'order_id' => $orderId,
                'old_normalized' => $this->normalizeStatus($oldStatus),
                'new_normalized' => $this->normalizeStatus($newStatus),
                'workflows_found' => !empty($workflowIds) ? (is_array($workflowIds) ? implode(',', $workflowIds) : $workflowIds) : 'none',
                'triggers_matrix' => json_encode(self::$workflowTriggers),
            ]);
        }

        if (empty($workflowIds)) {
            return;
        }
        $ids = (array) $workflowIds;

        $delay = 0;
        foreach ($ids as $workflowId) {
            // Check if this is an email workflow and if send_once is enabled (status transitions only)
            if ($this->shouldSkipEmailWorkflow($workflowId, $orderId)) {
                // Log skipped due to send_once
                $this->logEmailToFlowmattic(
                    $workflowId,
                    $orderId,
                    \ZeroSense\Features\Integrations\Flowmattic\Flowmattic::EMAIL_STATUS_SKIPPED,
                    [
                        'error' => sprintf(
                            'Not sent: already sent once automatically (send_once). From: %s → To: %s',
                            $oldStatus,
                            $newStatus
                        ),
                        'trigger_source' => 'zero_sense_status_transition'
                    ]
                );
                continue;
            }
            // Prepare debug data for async or direct execution
            $debugData = [
                'workflow_id' => $workflowId,
                'order_id' => $orderId,
                'trigger_source' => 'zero_sense_status_transition',
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'timestamp' => current_time('mysql')
            ];

            // Decide execution mode
            $inAdmin = is_admin();
            $isCheckoutAjax = (defined('DOING_AJAX') && DOING_AJAX && isset($_POST['action']) && in_array((string) $_POST['action'], ['woocommerce_checkout', 'woocommerce_update_order_review'], true));
            $isCheckoutPage = (function_exists('is_checkout') && is_checkout() && !(function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')));
            $isCheckoutContext = $isCheckoutAjax || $isCheckoutPage;

            // Force direct execution for Redsys-related payment statuses
            $normalizedNewStatus = $this->normalizeStatus($newStatus);
            $forceDirect = in_array($normalizedNewStatus, ['deposit-paid', 'fully-paid'], true);

            $executionMode = $forceDirect
                ? 'direct'
                : (($inAdmin && !$isCheckoutContext) ? 'direct' : 'async');

            // Allow global override for debugging/stability
            if (defined('ZS_FLOWMATTIC_FORCE_DIRECT') && ZS_FLOWMATTIC_FORCE_DIRECT) {
                $executionMode = 'direct';
            }

            // DEBUG LOG: Log execution decision
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->debug('Flowmattic: Execution mode decision', [
                    'source' => 'zero-sense-flowmattic-debug',
                    'order_id' => $orderId,
                    'workflow_id' => $workflowId,
                    'execution_mode' => $executionMode,
                    'is_admin' => $inAdmin ? 'yes' : 'no',
                    'is_checkout_context' => $isCheckoutContext ? 'yes' : 'no',
                    'normalized_new_status' => $normalizedNewStatus,
                    'force_direct' => $forceDirect ? 'yes' : 'no',
                ]);
            }

            if ($executionMode === 'direct') {
                do_action('zs_run_flowmattic_workflow', $workflowId, $debugData);
            } else {
                // Use Action Scheduler for reliable background processing (replaces WP-Cron)
                if (function_exists('as_schedule_single_action')) {
                    as_schedule_single_action(
                        time() + $delay,
                        'zs_run_flowmattic_workflow',
                        [$workflowId, $debugData],
                        'zero-sense-flowmattic'
                    );
                } else {
                    // Fallback if AS is missing (unlikely in WC)
                    wp_schedule_single_event(time() + $delay, 'zs_run_flowmattic_workflow', [$workflowId, $debugData]);
                }
                $delay += 2; // 2 seconds between each workflow
            }
        }
    }

    /**
     * Run a scheduled Flowmattic workflow asynchronously (Action Scheduler/wp-cron context)
     */
    public function runScheduledWorkflow(string $workflowId, array $debugData): void
    {
        // TEMP LOG: Log async execution
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info('Flowmattic: async workflow execution', [
                'source' => 'zero-sense-flowmattic-debug',
                'workflow_id' => $workflowId,
                'order_id' => $debugData['order_id'] ?? 'none',
                'trigger_source' => $debugData['trigger_source'] ?? 'unknown',
                'timestamp' => current_time('mysql')
            ]);
        }

        // Set up email tracking context (done in the async request)
        $orderId = isset($debugData['order_id']) ? (int) $debugData['order_id'] : 0;
        if ($orderId > 0) {
            $context = [
                'workflow_id' => $workflowId,
                'order_id' => $orderId,
                'trigger_source' => 'zero_sense_status_transition',
                'started_at' => microtime(true)
            ];
            self::$activeWorkflowContexts[] = $context;
            // Persist context so it survives PHP requests when Flowmattic has a delay
            set_transient('zs_wf_ctx_' . $workflowId . '_' . $orderId, $context, 10 * MINUTE_IN_SECONDS);
            set_transient('zs_wf_ctx_idx_' . $workflowId, $orderId, 10 * MINUTE_IN_SECONDS);
        }
        do_action('flowmattic_trigger_workflow', $workflowId, $debugData);

        // Clean up context after a short delay
        if ($orderId > 0) {
            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(
                    time() + 30,
                    'zs_cleanup_workflow_context',
                    [$workflowId, $orderId],
                    'zero-sense-flowmattic'
                );
            } else {
                wp_schedule_single_event(time() + 30, 'zs_cleanup_workflow_context', [$workflowId, $orderId]);
            }
        }
    }

    /**
     * Check if email workflow should be skipped due to send_once setting
     */
    private function shouldSkipEmailWorkflow(string $workflowId, int $orderId): bool
    {
        $stored = get_option('zs_flowmattic_custom_triggers', []);
        if (!is_array($stored)) {
            return false;
        }

        foreach ($stored as $trigger) {
            if (
                ($trigger['workflow_id'] ?? '') === $workflowId &&
                ($trigger['tag'] ?? '') === 'status' &&
                !empty($trigger['email_config']['is_email']) &&
                !empty($trigger['email_config']['send_once'])
            ) {

                // Check if email was already sent for this order
                $flowmatticFeature = new \ZeroSense\Features\Integrations\Flowmattic\Flowmattic();
                return $flowmatticFeature->hasEmailBeenSent($workflowId, $orderId);
            }
        }

        return false;
    }

    private function resolveWorkflowsForTransition(string $from, string $to)
    {
        $from = $this->normalizeStatus($from);
        $to = $this->normalizeStatus($to);

        $workflows = [];

        // Collect specific transition workflows (from → to)
        if (isset(self::$workflowTriggers[$from][$to])) {
            $specific = self::$workflowTriggers[$from][$to];
            if (is_array($specific)) {
                $workflows = array_merge($workflows, $specific);
            } else {
                $workflows[] = $specific;
            }
        }

        // Collect "any" transition workflows (any → to)
        if (isset(self::$workflowTriggers['any'][$to])) {
            $any = self::$workflowTriggers['any'][$to];
            if (is_array($any)) {
                $workflows = array_merge($workflows, $any);
            } else {
                $workflows[] = $any;
            }
        }

        // Remove duplicates and return
        $workflows = array_unique($workflows);

        return empty($workflows) ? null : $workflows;
    }

    private function normalizeStatus(string $status): string
    {
        if (strpos($status, 'wc-') === 0) {
            return substr($status, 3);
        }

        return $status;
    }

    public function registerOrderActions(array $actions): array
    {
        return [];
    }

    // Removed manual order action helpers and single-workflow helper.

    /**
     * Handle AJAX request for Class Actions triggered by button clicks
     */
    public function handleClassActionAjax(): void
    {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'zs_class_action_nonce')) {
            wp_send_json_error('Invalid nonce');
        }

        $className = sanitize_text_field(wp_unslash($_POST['class_name'] ?? ''));
        $orderId = intval(wp_unslash($_POST['order_id'] ?? 0));

        if (!$className) {
            wp_send_json_error('Missing class name');
        }

        // Check if we have workflows for this class
        if (!isset(self::$manualTriggers[$className])) {
            wp_send_json_error('No workflow configured for class: ' . $className);
        }

        // Trigger workflow with or without Order ID context
        $workflowIds = self::$manualTriggers[$className];
        if (is_array($workflowIds)) {
            foreach ($workflowIds as $workflowId) {
                $this->triggerClassWorkflow($workflowId, $className, $orderId, 'manual');
            }
        } else {
            $this->triggerClassWorkflow($workflowIds, $className, $orderId, 'manual');
        }

        $message = $orderId > 0
            ? "Workflow triggered for class '{$className}' with order {$orderId}"
            : "Workflow triggered for class '{$className}' (no order context)";

        wp_send_json_success(['message' => $message]);
    }

    /**
     * Trigger workflow for Class Action with smart Order ID handling
     * Made public to allow manual triggering from Flowmattic.php without modifying $_POST
     */
    public function triggerClassWorkflow(string $workflowId, string $className, int $orderId = 0, string $triggerType = 'auto'): void
    {
        // Check if this is an email workflow and if send_once is enabled
        // Class actions never enforce send_once; status transitions are handled earlier.

        // Set up email tracking context (both manual and automatic)
        if ($orderId > 0) {
            $context = [
                'workflow_id' => $workflowId,
                'order_id' => $orderId,
                'trigger_source' => ($triggerType === 'manual')
                    ? 'zero_sense_class_action_manual'
                    : 'zero_sense_class_action_auto',
                'started_at' => microtime(true)
            ];
            self::$activeWorkflowContexts[] = $context;
            // Persist context so it survives PHP requests when Flowmattic has a delay
            set_transient('zs_wf_ctx_' . $workflowId . '_' . $orderId, $context, 10 * MINUTE_IN_SECONDS);
            set_transient('zs_wf_ctx_idx_' . $workflowId, $orderId, 10 * MINUTE_IN_SECONDS);
        }

        $debugData = [
            'workflow_id' => $workflowId,
            'class_name' => $className,
            'trigger_source' => $triggerType === 'manual' ? 'zero_sense_class_action_manual' : 'zero_sense_class_action',
            'timestamp' => current_time('mysql')
        ];

        if ($orderId > 0) {
            // Has Order ID context - include it
            $order = wc_get_order($orderId);
            if ($order instanceof WC_Order) {
                $debugData['order_id'] = $orderId;
                $debugData['has_order_context'] = true;
            } else {
                $debugData['has_order_context'] = false;
            }
        } else {
            // No Order ID context
            $debugData['has_order_context'] = false;
        }

        do_action('flowmattic_trigger_workflow', $workflowId, $debugData);

        // Clean up context for both manual and automatic triggers
        if ($orderId > 0) {
            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(
                    time() + 30,
                    'zs_cleanup_workflow_context',
                    [$workflowId, $orderId],
                    'zero-sense-flowmattic'
                );
            } else {
                wp_schedule_single_event(time() + 30, 'zs_cleanup_workflow_context', [$workflowId, $orderId]);
            }
        }
    }

    /**
     * Enqueue JavaScript for Class Action detection
     */
    public function enqueueClassActionScript(): void
    {
        // Ensure triggers are loaded
        if (empty(self::$manualTriggers)) {
            $this->setupTriggers();
        }

        // Get configured classes from database
        $stored = get_option('zs_flowmattic_custom_triggers', []);
        $configuredClasses = [];
        if (is_array($stored)) {
            foreach ($stored as $row) {
                if (($row['tag'] ?? '') === 'class' && !empty($row['class'])) {
                    $configuredClasses[] = $row['class'];
                }
            }
        }

        // Skip if no classes configured
        if (empty($configuredClasses)) {
            return;
        }

        wp_enqueue_script(
            'zs-class-actions',
            plugin_dir_url(__FILE__) . 'assets/class-actions.js',
            ['jquery'],
            '1.0.1', // Increment version to force reload
            true
        );

        wp_localize_script('zs-class-actions', 'zsClassActions', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('zs_class_action_nonce'),
            'classes' => array_unique($configuredClasses),
            'confirmWithOrder' => __('Trigger workflow "{label}" for order #{order}?', 'zero-sense'),
            'confirmWithoutOrder' => __('Trigger workflow "{label}" now?', 'zero-sense')
        ]);
    }

    // Removed email logging helpers used by manual order actions.

    /**
     * Add 'Sent Emails' column to WooCommerce Orders list table
     */
    public function addSentEmailsOrderColumn(array $columns): array
    {
        // Get Flowmattic instance to access methods
        $flowmattic = new \ZeroSense\Features\Integrations\Flowmattic\Flowmattic();
        return $flowmattic->addSentEmailsOrderColumn($columns);
    }

    /**
     * Render 'Sent Emails' column content for each order row
     */
    public function renderSentEmailsOrderColumn(string $column, int $postId): void
    {
        // Get Flowmattic instance to access methods
        $flowmattic = new \ZeroSense\Features\Integrations\Flowmattic\Flowmattic();
        $flowmattic->renderSentEmailsOrderColumn($column, $postId);
    }

    /**
     * Render 'Sent Emails' column content for HPOS
     */
    public function renderSentEmailsOrderColumnHpos(string $column, $order): void
    {
        if ($column !== 'zs_email_logs') {
            return;
        }

        if (!$order instanceof \WC_Order) {
            return;
        }

        // Get Flowmattic instance to access methods
        $flowmattic = new \ZeroSense\Features\Integrations\Flowmattic\Flowmattic();
        $flowmattic->renderSentEmailsOrderColumn($column, $order->get_id());
    }

    /**
     * Add email logs metabox to order admin
     */
    public function addEmailLogsMetabox(): void
    {
        // Get Flowmattic instance to access methods
        $flowmattic = new \ZeroSense\Features\Integrations\Flowmattic\Flowmattic();
        $flowmattic->addEmailLogsMetabox();
    }

    /**
     * Restore workflow context from transient before FlowMattic resumes a delayed step.
     * Hooked on flowmattic_delay_workflow_step / flowmattic_delay_workflow_route at priority 1,
     * which runs before FlowMattic's own handler at priority 10.
     * Args: ($task_history_id, $next_step_id, $workflow_id [, $route_context])
     */
    public function restoreContextBeforeDelayedStep($taskHistoryId, $nextStepId, $workflowId): void
    {
        if (!is_string($workflowId) || $workflowId === '') {
            return;
        }

        // Skip if context already in memory for this workflow
        foreach (self::$activeWorkflowContexts as $ctx) {
            if (($ctx['workflow_id'] ?? '') === $workflowId) {
                return;
            }
        }

        $orderId = (int) get_transient('zs_wf_ctx_idx_' . $workflowId);
        if ($orderId <= 0) {
            return;
        }

        $context = get_transient('zs_wf_ctx_' . $workflowId . '_' . $orderId);
        if (is_array($context)) {
            self::$activeWorkflowContexts[] = $context;
            // Clean up — context now lives in memory for this request
            delete_transient('zs_wf_ctx_' . $workflowId . '_' . $orderId);
            delete_transient('zs_wf_ctx_idx_' . $workflowId);
        }
    }


    public function cleanupWorkflowContext(string $workflowId, int $orderId): void
    {
        // This is a no-op method just to act as a target for the scheduled action.
        // The actual context cleanup happens because PHP request ends.
        // We keep this to avoid "action not found" errors in logs.

        // Optionally, we could clean up any persistent transient/cache here if we used one.
    }
}
