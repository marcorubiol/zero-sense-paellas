<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Components;

use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\AlertCalculator;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\ReservationManager;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\AlertResolutionManager;

class AlertsAdminNotice
{
    const TRANSIENT_KEY = 'zs_active_equipment_alerts';
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
        if (isset($_GET['page']) && $_GET['page'] === 'zs-stock-alerts') {
            return;
        }

        $isOrderPage = $screen && in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true);

        if ($isOrderPage) {
            $orderId = isset($_GET['id']) ? absint($_GET['id']) : (isset($_GET['post']) ? absint($_GET['post']) : 0);
            if (!$orderId) {
                return;
            }
            $order = wc_get_order($orderId);
            if (!$order) {
                return;
            }
            $materials = ReservationManager::get($orderId);
            if (empty($materials)) {
                return;
            }
            $alerts = AlertCalculator::calculateAlerts($order, $materials);
            $hasCritical = false;
            foreach ($alerts as $materialKey => $alert) {
                if ($alert['alert_type'] === AlertCalculator::ALERT_CRITICAL && !AlertResolutionManager::isResolved($orderId, $materialKey)) {
                    $hasCritical = true;
                    break;
                }
            }
            if (!$hasCritical) {
                return;
            }
            printf(
                '<div class="notice notice-error"><p>⚠️ %s <a href="%s">%s</a></p></div>',
                __('<strong>This order has critical equipment stock alerts.</strong>', 'zero-sense'),
                esc_url('#zs_event_equipment'),
                esc_html(__('View in Event Equipment', 'zero-sense'))
            );
        } else {
            printf(
                '<div class="notice notice-error"><p>⚠️ %s <a href="%s">%s</a></p></div>',
                sprintf(
                    _n(
                        'Stock alert: <strong>%d upcoming order has a critical equipment shortage</strong> — not enough stock available.',
                        'Stock alert: <strong>%d upcoming orders have a critical equipment shortage</strong> — not enough stock available.',
                        $critical,
                        'zero-sense'
                    ),
                    $critical
                ),
                esc_url(admin_url('admin.php?page=zs-stock-alerts')),
                esc_html(__('View Alerts Dashboard', 'zero-sense'))
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

        $ordersByType = [
            'critical'     => [],
            'max_capacity' => [],
            'low_stock'    => [],
        ];

        foreach ($alerts as $alert) {
            if (isset($ordersByType[$alert['alert_type']])) {
                $ordersByType[$alert['alert_type']][$alert['order_id']] = true;
            }
        }

        $counts = [
            'critical'     => count($ordersByType['critical']),
            'max_capacity' => count($ordersByType['max_capacity']),
            'low_stock'    => count($ordersByType['low_stock']),
        ];

        set_transient(self::TRANSIENT_KEY, $counts, self::TRANSIENT_TTL);

        return $counts;
    }
}
