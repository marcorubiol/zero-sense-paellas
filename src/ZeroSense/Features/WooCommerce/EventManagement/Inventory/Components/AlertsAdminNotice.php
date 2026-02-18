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

        if ($critical > 0) {
            printf(
                '<div class="notice notice-error"><p>⚠️ %s <a href="%s">%s</a></p></div>',
                sprintf(
                    __('Inventory alert: <strong>%d critical</strong> material(s) with insufficient stock.', 'zero-sense'),
                    $critical
                ),
                esc_url($dashboardUrl),
                esc_html__('View Alerts Dashboard', 'zero-sense')
            );
        }

        if ($maxCapacity > 0) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p>⚠️ %s <a href="%s">%s</a></p></div>',
                sprintf(
                    __('Inventory alert: <strong>%d</strong> material(s) at max capacity.', 'zero-sense'),
                    $maxCapacity
                ),
                esc_url($dashboardUrl),
                esc_html__('View Alerts Dashboard', 'zero-sense')
            );
        }
    }

    private function getCounts(): array
    {
        $cached = get_transient(self::TRANSIENT_KEY);

        if ($cached !== false) {
            return $cached;
        }

        $allowedStatuses = ['deposit-paid', 'fully-paid'];
        $orderIds = AlertCalculator::getOrderIdsWithReservations(7, 60);
        $orderIds = array_filter($orderIds, function ($id) use ($allowedStatuses) {
            $order = wc_get_order($id);
            return $order && in_array($order->get_status(), $allowedStatuses, true);
        });
        $alerts = AlertCalculator::getAlertsForOrders(array_values($orderIds));

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
