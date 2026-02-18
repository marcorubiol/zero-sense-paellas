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

        $critical = $counts['critical'] ?? 0;

        if ($critical === 0) {
            return;
        }

        $screen = get_current_screen();

        // No mostrar el notice en el propio dashboard de alertas
        if (isset($_GET['page']) && $_GET['page'] === 'zs-inventory-alerts') {
            return;
        }

        $isOrderPage = $screen && in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true);

        if ($isOrderPage) {
            $linkUrl   = '#zs_inventory_materials';
            $linkLabel = __('View Alerts', 'zero-sense');
        } else {
            $linkUrl   = admin_url('admin.php?page=zs-inventory-alerts');
            $linkLabel = __('View Alerts Dashboard', 'zero-sense');
        }

        if ($critical > 0) {
            printf(
                '<div class="notice notice-error"><p>⚠️ %s <a href="%s">%s</a></p></div>',
                sprintf(
                    _n(
                        'Inventory alert: there is <strong>%d event in the next 30 days with a critical stock shortage</strong> — not enough material available.',
                        'Inventory alert: there are <strong>%d events in the next 30 days with a critical stock shortage</strong> — not enough material available.',
                        $critical,
                        'zero-sense'
                    ),
                    $critical
                ),
                esc_url($linkUrl),
                esc_html($linkLabel)
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
        $orderIds = AlertCalculator::getOrderIdsWithReservations(1, 30);
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
