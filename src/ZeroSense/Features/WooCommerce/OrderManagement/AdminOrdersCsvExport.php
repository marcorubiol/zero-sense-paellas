<?php
declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce\OrderManagement;

use WC_Order;
use ZeroSense\Core\FeatureInterface;
use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys as DepositMetaKeys;

if (!defined('ABSPATH')) { exit; }

class AdminOrdersCsvExport implements FeatureInterface
{
    private const ACTION         = 'zs_export_orders_csv';
    private const ACTION_DOWNLOAD = 'zs_download_orders_csv';
    private const NONCE_KEY       = 'zs_export_orders_csv';
    private const CAPABILITY      = 'manage_woocommerce';
    private const BATCH_SIZE      = 50;

    private const ALL_COLUMNS = [
        'order_id'           => 'Order ID',
        'date'               => 'Date',
        'status'             => 'Status',
        'customer_name'      => 'Customer name',
        'email'              => 'Email',
        'phone'              => 'Phone',
        'event_date'         => 'Event date',
        'start_time'         => 'Event start time',
        'serving_time'       => 'Paellas service time',
        'total_guests'       => 'Total guests',
        'event_type'         => 'Event type',
        'location'           => 'Service location',
        'city'               => 'City',
        'order_total'        => 'Order total',
        'deposit_amount'     => 'Deposit amount',
        'deposit_pct'        => 'Deposit %',
        'deposit_paid'       => 'Deposit paid?',
        'first_payment_date' => 'First payment date',
        'remaining'          => 'Remaining amount',
        'second_payment_date'=> 'Second payment date',
        'total_paid'         => 'Total paid',
        'payment_method'     => 'Payment method',
        'language'           => 'Language',
        'products'           => 'Products',
    ];

    public function getName(): string        { return __('Orders: CSV Export', 'zero-sense'); }
    public function getDescription(): string { return __('Adds an "Export CSV" button to the WooCommerce orders list that downloads a CSV with event, deposit and order data, respecting active filters.', 'zero-sense'); }
    public function getCategory(): string    { return 'WooCommerce'; }
    public function isToggleable(): bool     { return true; }
    public function getOptionName(): string  { return 'zs_feature_orders_csv_export'; }
    public function isEnabled(): bool        { return (bool) get_option($this->getOptionName(), true); }
    public function getPriority(): int       { return 10; }
    public function getConditions(): array   { return ['is_admin', 'class_exists:WooCommerce']; }

    public function init(): void
    {
        // Render button — classic orders screen
        add_action('restrict_manage_posts', [$this, 'renderButton'], PHP_INT_MAX);

        // Render button — HPOS orders screen
        add_action('woocommerce_order_list_table_restrict_manage_orders', [$this, 'renderButton'], PHP_INT_MAX);

        // Move button after the Filter button via JS
        add_action('admin_footer', [$this, 'renderMoveScript']);

        // Show column selector page
        add_action('admin_post_' . self::ACTION, [$this, 'handleColumnSelector']);

        // Stream the CSV
        add_action('admin_post_' . self::ACTION_DOWNLOAD, [$this, 'handleExport']);
    }

    public function renderButton(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return;
        }
        if (!in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'], true)) {
            return;
        }
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        // Pass through all current filter params
        $params = $this->getCurrentFilterParams();
        $params['action']   = self::ACTION;
        $params['_wpnonce'] = wp_create_nonce(self::NONCE_KEY);

        $actionUrl = add_query_arg($params, admin_url('admin-post.php'));

        printf(
            '<a id="zs-export-csv-btn" href="%s" class="button" style="margin-left:4px;">%s</a>',
            esc_url($actionUrl),
            esc_html__('Export CSV', 'zero-sense')
        );
    }

    public function renderMoveScript(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'], true)) {
            return;
        }
        ?>
        <script>
        (function () {
            var btn = document.getElementById('zs-export-csv-btn');
            if (!btn) return;
            // Classic: Filter button is input[name="filter_action"] or input[value="Filter"]
            // HPOS: button.search-submit or input[value="Filter"]
            var filter = document.querySelector('input[name="filter_action"], button.search-submit, input[value="Filter"]');
            if (filter && filter.parentNode) {
                filter.parentNode.insertBefore(btn, filter.nextSibling);
            }
        })();
        </script>
        <?php
    }

    public function handleColumnSelector(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('Insufficient permissions.', 'zero-sense'), 403);
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), self::NONCE_KEY)) {
            wp_die(__('Security check failed.', 'zero-sense'), 403);
        }

        // Pass filters through to the download action
        $filters = [];
        foreach (['post_status', 's', '_customer_user', 'm'] as $key) {
            if (!empty($_GET[$key])) {
                $filters[$key] = sanitize_text_field(wp_unslash($_GET[$key]));
            }
        }

        // New filters: statuses (multi-select) and date range
        if (!empty($_GET['statuses']) && is_array($_GET['statuses'])) {
            $filters['statuses'] = array_map('sanitize_text_field', wp_unslash($_GET['statuses']));
        }
        if (!empty($_GET['date_from'])) {
            $filters['date_from'] = sanitize_text_field(wp_unslash($_GET['date_from']));
        }
        if (!empty($_GET['date_to'])) {
            $filters['date_to'] = sanitize_text_field(wp_unslash($_GET['date_to']));
        }

        $downloadBase = add_query_arg(
            array_merge($filters, [
                'action'   => self::ACTION_DOWNLOAD,
                '_wpnonce' => wp_create_nonce(self::NONCE_KEY),
            ]),
            admin_url('admin-post.php')
        );

        $this->renderColumnSelectorPage($downloadBase);
        exit;
    }

    private function renderColumnSelectorPage(string $downloadBase): void
    {
        $allColumns = self::ALL_COLUMNS;
        
        // Pre-selected filters
        $preselectedStatuses = isset($_GET['statuses']) && is_array($_GET['statuses']) 
            ? array_map('sanitize_text_field', wp_unslash($_GET['statuses'])) 
            : [];
        $dateFrom = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
        $dateTo = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php esc_html_e('Export CSV — Select columns', 'zero-sense'); ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f0f0f1; margin: 0; padding: 40px 20px; }
                .zs-csv-wrap { max-width: 700px; margin: 0 auto; background: #fff; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,.12); padding: 32px 36px; }
                h1 { font-size: 20px; margin: 0 0 20px; color: #1d2327; }
                h2 { font-size: 16px; margin: 24px 0 12px; color: #1d2327; border-bottom: 1px solid #dcdcde; padding-bottom: 8px; }
                .zs-filters-section { margin-bottom: 28px; padding-bottom: 28px; border-bottom: 2px solid #dcdcde; }
                .zs-filter-group { margin-bottom: 20px; }
                .zs-filter-group > label { display: block; font-weight: 600; margin-bottom: 8px; font-size: 13px; color: #1d2327; }
                .zs-status-checkboxes { display: flex; flex-direction: column; gap: 8px; margin-bottom: 8px; }
                .zs-status-checkboxes label { display: flex; align-items: center; gap: 6px; font-size: 13px; color: #1d2327; cursor: pointer; }
                .zs-status-checkboxes input[type="checkbox"] { margin: 0; }
                .zs-filter-actions { display: flex; gap: 10px; }
                .zs-filter-actions a { font-size: 12px; color: #2271b1; text-decoration: underline; cursor: pointer; }
                .zs-date-inputs { display: flex; align-items: center; gap: 10px; }
                .zs-date-inputs input[type="date"] { padding: 6px 10px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; }
                .zs-date-inputs span { color: #646970; font-size: 13px; }
                .zs-cols { columns: 2; gap: 12px; margin-bottom: 24px; }
                .zs-col-item { display: flex; align-items: center; gap: 8px; padding: 5px 0; break-inside: avoid; }
                .zs-col-item label { cursor: pointer; font-size: 14px; color: #1d2327; }
                .zs-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; }
                .zs-actions a { font-size: 13px; color: #2271b1; text-decoration: underline; cursor: pointer; background: none; border: none; padding: 0; }
                .button-primary { background: #2271b1; color: #fff; border: none; padding: 10px 22px; border-radius: 4px; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-block; }
                .button-primary:hover { background: #135e96; }
                .back { font-size: 13px; color: #646970; text-decoration: none; display: block; margin-top: 16px; }
                .back:hover { color: #2271b1; }
            </style>
        </head>
        <body>
        <div class="zs-csv-wrap">
            <h1><?php esc_html_e('Select columns to export', 'zero-sense'); ?></h1>
            <form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php foreach (['action', '_wpnonce', 'post_status', 's', '_customer_user', 'm'] as $k): ?>
                    <?php
                    $v = '';
                    if ($k === 'action') { $v = self::ACTION_DOWNLOAD; }
                    elseif ($k === '_wpnonce') { $v = wp_create_nonce(self::NONCE_KEY); }
                    elseif (!empty($_GET[$k])) { $v = sanitize_text_field(wp_unslash($_GET[$k])); }
                    if ($v !== ''): ?>
                        <input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>">
                    <?php endif; ?>
                <?php endforeach; ?>

                <!-- Filters Section -->
                <div class="zs-filters-section">
                    <h2><?php esc_html_e('Filters', 'zero-sense'); ?></h2>
                    
                    <!-- Status filter -->
                    <div class="zs-filter-group">
                        <label><?php esc_html_e('Order Status', 'zero-sense'); ?></label>
                        <div class="zs-status-checkboxes">
                            <?php 
                            $allStatuses = wc_get_order_statuses();
                            foreach ($allStatuses as $statusKey => $statusLabel): 
                                $isChecked = in_array($statusKey, $preselectedStatuses, true);
                            ?>
                                <label>
                                    <input type="checkbox" name="statuses[]" value="<?php echo esc_attr($statusKey); ?>" <?php checked($isChecked); ?>>
                                    <?php echo esc_html($statusLabel); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="zs-filter-actions">
                            <a class="select-all-statuses"><?php esc_html_e('Select all', 'zero-sense'); ?></a>
                            <a class="deselect-all-statuses"><?php esc_html_e('Deselect all', 'zero-sense'); ?></a>
                        </div>
                    </div>
                    
                    <!-- Date range filter -->
                    <div class="zs-filter-group">
                        <label><?php esc_html_e('Date Range', 'zero-sense'); ?></label>
                        <div class="zs-date-inputs">
                            <input type="date" name="date_from" id="date_from" value="<?php echo esc_attr($dateFrom); ?>" placeholder="<?php esc_attr_e('From', 'zero-sense'); ?>">
                            <span><?php esc_html_e('to', 'zero-sense'); ?></span>
                            <input type="date" name="date_to" id="date_to" value="<?php echo esc_attr($dateTo); ?>" placeholder="<?php esc_attr_e('To', 'zero-sense'); ?>">
                        </div>
                        <div class="zs-filter-actions">
                            <a class="set-past-month"><?php esc_html_e('Past month', 'zero-sense'); ?></a>
                        </div>
                    </div>
                </div>

                <h2><?php esc_html_e('Columns', 'zero-sense'); ?></h2>
                <div class="zs-actions">
                    <a onclick="document.querySelectorAll('.zs-col-check').forEach(c=>c.checked=true);return false;"><?php esc_html_e('Select all', 'zero-sense'); ?></a>
                    <a onclick="document.querySelectorAll('.zs-col-check').forEach(c=>c.checked=false);return false;"><?php esc_html_e('Deselect all', 'zero-sense'); ?></a>
                </div>

                <div class="zs-cols">
                    <?php foreach ($allColumns as $key => $label): ?>
                    <div class="zs-col-item">
                        <input class="zs-col-check" type="checkbox" name="cols[]" value="<?php echo esc_attr($key); ?>" id="col_<?php echo esc_attr($key); ?>" checked>
                        <label for="col_<?php echo esc_attr($key); ?>"><?php echo esc_html(__($label, 'zero-sense')); ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>

                <button type="submit" class="button-primary"><?php esc_html_e('Download CSV', 'zero-sense'); ?></button>
            </form>
            <a class="back" href="javascript:history.back()">&larr; <?php esc_html_e('Back to orders', 'zero-sense'); ?></a>
        </div>
        <script>
        (function() {
            // Select/Deselect all statuses
            var selectAllStatuses = document.querySelector('.select-all-statuses');
            var deselectAllStatuses = document.querySelector('.deselect-all-statuses');
            
            if (selectAllStatuses) {
                selectAllStatuses.onclick = function(e) {
                    e.preventDefault();
                    document.querySelectorAll('input[name="statuses[]"]').forEach(function(c) {
                        c.checked = true;
                    });
                };
            }
            
            if (deselectAllStatuses) {
                deselectAllStatuses.onclick = function(e) {
                    e.preventDefault();
                    document.querySelectorAll('input[name="statuses[]"]').forEach(function(c) {
                        c.checked = false;
                    });
                };
            }
            
            // Set past month date range
            var setPastMonth = document.querySelector('.set-past-month');
            if (setPastMonth) {
                setPastMonth.onclick = function(e) {
                    e.preventDefault();
                    var today = new Date();
                    var pastMonth = new Date();
                    pastMonth.setMonth(today.getMonth() - 1);
                    
                    // Format dates as YYYY-MM-DD
                    var formatDate = function(date) {
                        var year = date.getFullYear();
                        var month = String(date.getMonth() + 1).padStart(2, '0');
                        var day = String(date.getDate()).padStart(2, '0');
                        return year + '-' + month + '-' + day;
                    };
                    
                    var dateFromInput = document.getElementById('date_from');
                    var dateToInput = document.getElementById('date_to');
                    
                    if (dateFromInput) {
                        dateFromInput.value = formatDate(pastMonth);
                    }
                    if (dateToInput) {
                        dateToInput.value = formatDate(today);
                    }
                };
            }
        })();
        </script>
        </body>
        </html>
        <?php
    }

    public function handleExport(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('Insufficient permissions.', 'zero-sense'), 403);
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), self::NONCE_KEY)) {
            wp_die(__('Security check failed.', 'zero-sense'), 403);
        }

        // Selected columns (default: all)
        $selectedCols = isset($_GET['cols']) && is_array($_GET['cols'])
            ? array_map('sanitize_key', $_GET['cols'])
            : array_keys(self::ALL_COLUMNS);

        $filename = 'orders-' . gmdate('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // BOM for Excel UTF-8 compatibility
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        if ($out === false) {
            wp_die(__('Could not open output stream.', 'zero-sense'));
        }

        fputcsv($out, $this->getHeaders($selectedCols));

        $queryArgs = $this->buildQueryArgs();
        $page      = 1;

        do {
            $queryArgs['paged'] = $page;
            $orders = $this->fetchOrders($queryArgs);

            foreach ($orders as $order) {
                if (!$order instanceof WC_Order) {
                    continue;
                }
                fputcsv($out, $this->buildRow($order, $selectedCols));
            }

            $page++;
        } while (count($orders) === self::BATCH_SIZE);

        fclose($out);
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getHeaders(array $cols): array
    {
        $headers = [];
        foreach (self::ALL_COLUMNS as $key => $label) {
            if (in_array($key, $cols, true)) {
                $headers[] = __($label, 'zero-sense');
            }
        }
        return $headers;
    }

    private function buildRow(WC_Order $order, array $cols): array
    {
        $eventDate = (string) $order->get_meta('zs_event_date', true);

        $serviceLocationId = (int) $order->get_meta('zs_event_service_location', true);
        $serviceLocationName = '';
        if ($serviceLocationId > 0) {
            $term = get_term($serviceLocationId, 'service-area');
            $serviceLocationName = ($term instanceof \WP_Term) ? $term->name : '';
        }

        $depositAmount       = (string) $order->get_meta(DepositMetaKeys::DEPOSIT_AMOUNT, true);
        $depositPct          = (string) $order->get_meta(DepositMetaKeys::DEPOSIT_PERCENTAGE, true);
        $remainingAmount     = (string) $order->get_meta(DepositMetaKeys::REMAINING_AMOUNT, true);
        $isDepositPaid       = (string) $order->get_meta(DepositMetaKeys::IS_DEPOSIT_PAID, true);
        $depositPaymentDate  = (string) $order->get_meta(DepositMetaKeys::DEPOSIT_PAYMENT_DATE, true);
        $balancePaymentDate  = (string) $order->get_meta(DepositMetaKeys::BALANCE_PAYMENT_DATE, true);

        $createdDate = $order->get_date_created();
        $eventTs     = $eventDate !== '' ? strtotime($eventDate) : false;

        // Calculate total paid
        $totalPaid = '';
        if ($remainingAmount !== '') {
            $totalPaidValue = (float) $order->get_total() - (float) $remainingAmount;
            $totalPaid = number_format($totalPaidValue, 2, '.', '');
        }

        // Format deposit paid as Yes/No
        $depositPaidFormatted = '';
        if ($isDepositPaid !== '') {
            $depositPaidFormatted = (in_array($isDepositPaid, ['yes', '1', 1, true], true)) ? 'Yes' : 'No';
        }

        // Format payment dates
        $firstPaymentDateFormatted = '';
        if ($depositPaymentDate !== '' && strtotime($depositPaymentDate) !== false) {
            $firstPaymentDateFormatted = date('d/m/Y H:i', strtotime($depositPaymentDate));
        }

        $secondPaymentDateFormatted = '';
        if ($balancePaymentDate !== '' && strtotime($balancePaymentDate) !== false) {
            $secondPaymentDateFormatted = date('d/m/Y H:i', strtotime($balancePaymentDate));
        }

        $all = [
            'order_id'            => $order->get_id(),
            'date'                => $createdDate ? $createdDate->date('d/m/Y H:i') : '',
            'status'              => wc_get_order_status_name($order->get_status()),
            'customer_name'       => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'email'               => $order->get_billing_email(),
            'phone'               => $order->get_billing_phone(),
            'event_date'          => ($eventTs !== false) ? date('d/m/Y', $eventTs) : '',
            'start_time'          => (string) $order->get_meta('zs_event_start_time', true),
            'serving_time'        => (string) $order->get_meta('zs_event_paellas_service_time', true),
            'total_guests'        => (string) $order->get_meta('zs_event_total_guests', true),
            'event_type'          => (string) $order->get_meta('zs_event_type', true),
            'location'            => $serviceLocationName,
            'city'                => $order->get_shipping_city() ?: (string) $order->get_meta('zs_event_city', true),
            'order_total'         => number_format((float) $order->get_total(), 2, '.', ''),
            'deposit_amount'      => $depositAmount !== '' ? number_format((float) $depositAmount, 2, '.', '') : '',
            'deposit_pct'         => $depositPct !== '' ? number_format((float) $depositPct, 2, '.', '') : '',
            'deposit_paid'        => $depositPaidFormatted,
            'first_payment_date'  => $firstPaymentDateFormatted,
            'remaining'           => $remainingAmount !== '' ? number_format((float) $remainingAmount, 2, '.', '') : '',
            'second_payment_date' => $secondPaymentDateFormatted,
            'total_paid'          => $totalPaid,
            'payment_method'      => $order->get_payment_method_title(),
            'language'            => (string) $order->get_meta('wpml_language', true),
            'products'            => $this->getProductsSummary($order),
        ];

        $row = [];
        foreach (self::ALL_COLUMNS as $key => $_) {
            if (in_array($key, $cols, true)) {
                $row[] = $all[$key] ?? '';
            }
        }
        return $row;
    }

    private function getProductsSummary(WC_Order $order): string
    {
        $parts = [];
        foreach ($order->get_items() as $item) {
            $qty  = (int) $item->get_quantity();
            $name = $item->get_name();
            $parts[] = $qty > 1 ? "{$qty}× {$name}" : $name;
        }
        return implode(', ', $parts);
    }

    /**
     * Build WC_Order_Query args from current GET filters.
     */
    private function buildQueryArgs(): array
    {
        $args = [
            'limit'   => self::BATCH_SIZE,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'objects',
        ];

        // Multi-status filter (new, has priority over single status)
        $statuses = isset($_GET['statuses']) && is_array($_GET['statuses']) 
            ? array_map('sanitize_text_field', wp_unslash($_GET['statuses'])) 
            : [];

        if (!empty($statuses)) {
            // Remove 'wc-' prefix from status keys
            $cleanStatuses = array_map(function($s) {
                return str_replace('wc-', '', $s);
            }, $statuses);
            $args['status'] = $cleanStatuses; // WC_Order_Query accepts array
        } else {
            // Fallback to legacy single status filter from WooCommerce
            $status = isset($_GET['post_status']) ? sanitize_text_field(wp_unslash($_GET['post_status'])) : '';
            if ($status !== '' && $status !== 'all') {
                $args['status'] = str_replace('wc-', '', $status);
            }
        }

        // Search
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        if ($search !== '') {
            $args['s'] = $search;
        }

        // Customer filter
        $customerId = isset($_GET['_customer_user']) ? (int) $_GET['_customer_user'] : 0;
        if ($customerId > 0) {
            $args['customer_id'] = $customerId;
        }

        // Date range filter (new, has priority over month dropdown)
        $dateFrom = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
        $dateTo = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';

        if ($dateFrom !== '' || $dateTo !== '') {
            $from = $dateFrom !== '' ? $dateFrom : '1970-01-01';
            $to = $dateTo !== '' ? $dateTo : gmdate('Y-m-d');
            $args['date_created'] = $from . '...' . $to;
        } else {
            // Fallback to legacy month dropdown (m = YYYYMM)
            $month = isset($_GET['m']) ? sanitize_text_field(wp_unslash($_GET['m'])) : '';
            if (strlen($month) === 6) {
                $year  = (int) substr($month, 0, 4);
                $mon   = (int) substr($month, 4, 2);
                $args['date_created'] = sprintf('%d-%02d-01...%d-%02d-%02d', $year, $mon, $year, $mon, (int) date('t', mktime(0, 0, 0, $mon, 1, $year)));
            }
        }

        return $args;
    }

    /**
     * Fetch orders using WC_Order_Query (HPOS-compatible).
     *
     * @return WC_Order[]
     */
    private function fetchOrders(array $args): array
    {
        $query  = new \WC_Order_Query($args);
        $result = $query->get_orders();
        return is_array($result) ? $result : [];
    }

    /**
     * Collect current filter params from the request to pass through to the export URL.
     */
    private function getCurrentFilterParams(): array
    {
        $params = [];
        $keys   = ['post_status', 's', '_customer_user', 'm'];

        foreach ($keys as $key) {
            if (isset($_GET[$key]) && $_GET[$key] !== '') {
                $params[$key] = sanitize_text_field(wp_unslash($_GET[$key]));
            }
        }

        return $params;
    }
}
