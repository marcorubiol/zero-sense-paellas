<?php
declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce\OrderManagement;

use WC_Order;
use ZeroSense\Core\FeatureInterface;
use ZeroSense\Features\WooCommerce\Deposits\Support\MetaKeys as DepositMetaKeys;

if (!defined('ABSPATH')) { exit; }

class AdminOrdersCsvExport implements FeatureInterface
{
    private const ACTION      = 'zs_export_orders_csv';
    private const NONCE_KEY   = 'zs_export_orders_csv';
    private const CAPABILITY  = 'manage_woocommerce';
    private const BATCH_SIZE  = 50;

    public function getName(): string        { return __('Orders: CSV Export', 'zero-sense'); }
    public function getDescription(): string { return __('Adds an "Export CSV" button to the WooCommerce orders list that downloads a CSV with event, deposit and order data, respecting active filters.', 'zero-sense'); }
    public function getCategory(): string    { return 'WooCommerce'; }
    public function isToggleable(): bool     { return false; }
    public function isEnabled(): bool        { return true; }
    public function getPriority(): int       { return 10; }
    public function getConditions(): array   { return ['is_admin', 'class_exists:WooCommerce']; }

    public function init(): void
    {
        // Render button — classic orders screen
        add_action('restrict_manage_posts', [$this, 'renderButton'], 20);

        // Render button — HPOS orders screen
        add_action('woocommerce_order_list_table_restrict_manage_orders', [$this, 'renderButton'], 20);

        // Handle export
        add_action('admin_post_' . self::ACTION, [$this, 'handleExport']);
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
        $params['action'] = self::ACTION;
        $params['_wpnonce'] = wp_create_nonce(self::NONCE_KEY);

        $actionUrl = add_query_arg($params, admin_url('admin-post.php'));

        printf(
            '<a href="%s" class="button" style="margin-left:4px;">%s</a>',
            esc_url($actionUrl),
            esc_html__('Export CSV', 'zero-sense')
        );
    }

    public function handleExport(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('Insufficient permissions.', 'zero-sense'), 403);
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), self::NONCE_KEY)) {
            wp_die(__('Security check failed.', 'zero-sense'), 403);
        }

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

        fputcsv($out, $this->getHeaders());

        $queryArgs = $this->buildQueryArgs();
        $page      = 1;

        do {
            $queryArgs['paged'] = $page;
            $orders = $this->fetchOrders($queryArgs);

            foreach ($orders as $order) {
                fputcsv($out, $this->buildRow($order));
            }

            $page++;
        } while (count($orders) === self::BATCH_SIZE);

        fclose($out);
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getHeaders(): array
    {
        return [
            __('Order ID', 'zero-sense'),
            __('Date', 'zero-sense'),
            __('Status', 'zero-sense'),
            __('Customer name', 'zero-sense'),
            __('Email', 'zero-sense'),
            __('Phone', 'zero-sense'),
            __('Event date', 'zero-sense'),
            __('Event start time', 'zero-sense'),
            __('Paellas service time', 'zero-sense'),
            __('Total guests', 'zero-sense'),
            __('Event type', 'zero-sense'),
            __('Service location', 'zero-sense'),
            __('City', 'zero-sense'),
            __('Order total', 'zero-sense'),
            __('Deposit amount', 'zero-sense'),
            __('Deposit %', 'zero-sense'),
            __('Remaining amount', 'zero-sense'),
            __('Payment method', 'zero-sense'),
            __('Language', 'zero-sense'),
            __('Products', 'zero-sense'),
        ];
    }

    private function buildRow(WC_Order $order): array
    {
        $eventDate = (string) $order->get_meta('zs_event_date', true);

        $serviceLocationId = (int) $order->get_meta('zs_event_service_location', true);
        $serviceLocationName = '';
        if ($serviceLocationId > 0) {
            $term = get_term($serviceLocationId, 'service-area');
            $serviceLocationName = ($term instanceof \WP_Term) ? $term->name : '';
        }

        $depositAmount    = (string) $order->get_meta(DepositMetaKeys::DEPOSIT_AMOUNT, true);
        $depositPct       = (string) $order->get_meta(DepositMetaKeys::DEPOSIT_PERCENTAGE, true);
        $remainingAmount  = (string) $order->get_meta(DepositMetaKeys::REMAINING_AMOUNT, true);

        $products = $this->getProductsSummary($order);

        $createdDate = $order->get_date_created();

        return [
            $order->get_id(),
            $createdDate ? $createdDate->date('d/m/Y H:i') : '',
            wc_get_order_status_name($order->get_status()),
            trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            $order->get_billing_email(),
            $order->get_billing_phone(),
            $eventDate !== '' ? date('d/m/Y', strtotime($eventDate)) : '',
            (string) $order->get_meta('zs_event_start_time', true),
            (string) $order->get_meta('zs_event_paellas_service_time', true),
            (string) $order->get_meta('zs_event_total_guests', true),
            (string) $order->get_meta('zs_event_type', true),
            $serviceLocationName,
            $order->get_shipping_city() ?: (string) $order->get_meta('zs_event_city', true),
            number_format((float) $order->get_total(), 2, '.', ''),
            $depositAmount !== '' ? number_format((float) $depositAmount, 2, '.', '') : '',
            $depositPct !== '' ? number_format((float) $depositPct, 2, '.', '') : '',
            $remainingAmount !== '' ? number_format((float) $remainingAmount, 2, '.', '') : '',
            $order->get_payment_method_title(),
            (string) $order->get_meta('wpml_language', true),
            $products,
        ];
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

        // Status filter
        $status = isset($_GET['post_status']) ? sanitize_text_field(wp_unslash($_GET['post_status'])) : '';
        if ($status !== '' && $status !== 'all') {
            $args['status'] = str_replace('wc-', '', $status);
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

        // Date range (m = YYYYMM, passed by WP's month dropdown)
        $month = isset($_GET['m']) ? sanitize_text_field(wp_unslash($_GET['m'])) : '';
        if (strlen($month) === 6) {
            $year  = (int) substr($month, 0, 4);
            $mon   = (int) substr($month, 4, 2);
            $args['date_created'] = sprintf('%d-%02d-01...%d-%02d-%02d', $year, $mon, $year, $mon, (int) date('t', mktime(0, 0, 0, $mon, 1, $year)));
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
