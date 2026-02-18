<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Components;

use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\AlertCalculator;

class AlertsAdminNotice
{
    const TRANSIENT_KEY = 'zs_active_inventory_alerts';
    const TRANSIENT_TTL = 300; // 5 minutes

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $counts = $this->getCounts();

        $critical    = $counts['critical'] ?? 0;
        $maxCapacity = $counts['max_capacity'] ?? 0;

        if ($critical === 0 && $maxCapacity === 0) {
            return;
        }

        $dashboardUrl = admin_url('admin.php?page=zs-inventory-alerts');

        $parts = [];
        if ($critical > 0) {
            $parts[] = sprintf(
                '<strong>%d %s</strong>',
                $critical,
                _n('critical', 'critical', $critical, 'zero-sense')
            );
        }
        if ($maxCapacity > 0) {
            $parts[] = sprintf(
                '<strong>%d %s</strong>',
                $maxCapacity,
                _n('at max capacity', 'at max capacity', $maxCapacity, 'zero-sense')
            );
        }

        $type = $critical > 0 ? 'error' : 'warning';

        printf(
            '<div class="notice notice-%s"><p>⚠️ %s <a href="%s">%s</a></p></div>',
            esc_attr($type),
            sprintf(
                __('Inventory alert: %s material(s) with stock issues.', 'zero-sense'),
                implode(' + ', $parts)
            ),
            esc_url($dashboardUrl),
            esc_html__('View Alerts Dashboard', 'zero-sense')
        );
    }

    private function getCounts(): array
    {
        $cached = get_transient(self::TRANSIENT_KEY);

        if ($cached !== false) {
            return $cached;
        }

        $allowedStatuses = ['deposit-paid', 'fully-paid'];
        $orderIds = AlertCalculator::getOrderIdsWithReservations(7, 60);
        $debugInfo = [];
        $orderIds = array_filter($orderIds, function ($id) use ($allowedStatuses, &$debugInfo) {
            $order = wc_get_order($id);
            $status = $order ? $order->get_status() : 'not-found';
            $debugInfo[$id] = $status;
            return $order && in_array($status, $allowedStatuses, true);
        });
        error_log('[ZS AlertsAdminNotice] Orders with reservations: ' . json_encode($debugInfo));
        error_log('[ZS AlertsAdminNotice] Orders after status filter: ' . json_encode(array_values($orderIds)));
        $alerts = AlertCalculator::getAlertsForOrders(array_values($orderIds));
        error_log('[ZS AlertsAdminNotice] Alerts found: ' . json_encode(array_column($alerts, 'alert_type')));

        $counts = [
            'critical'     => 0,
            'max_capacity' => 0,
            'low_stock'    => 0,
        ];

        foreach ($alerts as $alert) {
            if (isset($counts[$alert['alert_type']])) {
                $counts[$alert['alert_type']]++;
            }
        }

        set_transient(self::TRANSIENT_KEY, $counts, self::TRANSIENT_TTL);

        return $counts;
    }
}
