<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Components;

use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\AlertCalculator;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\ReservationManager;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\AlertResolutionManager;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\ManualOverride;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\MaterialCalculator;

class OrderAlertsColumn
{
    public function register(): void
    {
        if (!is_admin()) {
            return;
        }

        add_filter('manage_edit-shop_order_columns', [$this, 'addColumn']);
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'addColumn']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'renderColumn'], 10, 2);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'renderColumn'], 10, 2);
        add_action('admin_head', [$this, 'inlineStyles']);
    }

    public function addColumn(array $columns): array
    {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'order_status') {
                $new['zs_stock_alerts'] = '<span class="dashicons dashicons-warning" title="' . esc_attr__('Stock Alerts', 'zero-sense') . '"></span>';
            }
        }
        if (!isset($new['zs_stock_alerts'])) {
            $new['zs_stock_alerts'] = __('Alerts', 'zero-sense');
        }
        return $new;
    }

    public function renderColumn(string $column, $postIdOrOrder): void
    {
        if ($column !== 'zs_stock_alerts') {
            return;
        }

        $orderId = is_object($postIdOrOrder) ? $postIdOrOrder->get_id() : (int) $postIdOrOrder;
        $order   = wc_get_order($orderId);

        if (!$order) {
            return;
        }

        $allowedStatuses = ['deposit-paid', 'fully-paid'];
        if (!in_array($order->get_status(), $allowedStatuses, true)) {
            echo '<span style="color:#ccc;">—</span>';
            return;
        }

        $materials = ReservationManager::get($orderId);

        if (empty($materials)) {
            echo '<span style="color:#ccc;">—</span>';
            return;
        }

        $alerts = AlertCalculator::calculateAlerts($order, $materials);

        if (empty($alerts)) {
            echo '<span class="dashicons dashicons-yes-alt" style="color:#46b450;" title="' . esc_attr__('No stock issues', 'zero-sense') . '"></span>';
            return;
        }

        $worstType   = null;
        $activeCount = 0;

        foreach ($alerts as $materialKey => $alert) {
            if (AlertResolutionManager::isResolved($orderId, $materialKey)) {
                continue;
            }
            $activeCount++;
            if ($worstType === null) {
                $worstType = $alert['alert_type'];
            } elseif ($alert['alert_type'] === AlertCalculator::ALERT_CRITICAL) {
                $worstType = AlertCalculator::ALERT_CRITICAL;
            } elseif ($alert['alert_type'] === AlertCalculator::ALERT_MAX_CAPACITY && $worstType === AlertCalculator::ALERT_LOW_STOCK) {
                $worstType = AlertCalculator::ALERT_MAX_CAPACITY;
            }
        }

        if ($activeCount === 0) {
            echo '<span class="dashicons dashicons-yes-alt" style="color:#46b450;" title="' . esc_attr__('All alerts resolved', 'zero-sense') . '"></span>';
            return;
        }

        $icon    = AlertCalculator::getAlertIcon($worstType);
        $cssClass = 'zs-col-alert-' . str_replace('_', '-', $worstType);
        $label   = AlertCalculator::getAlertLabel($worstType);
        $title   = sprintf(_n('%d active alert (%s)', '%d active alerts (%s)', $activeCount, 'zero-sense'), $activeCount, $label);

        printf(
            '<span class="dashicons %s %s" title="%s"></span>',
            esc_attr($icon),
            esc_attr($cssClass),
            esc_attr($title)
        );

        if ($activeCount > 1) {
            echo '<sup style="font-size:10px; font-weight:600; margin-left:1px;">' . $activeCount . '</sup>';
        }
    }

    public function inlineStyles(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'], true)) {
            return;
        }
        ?>
        <style>
            .column-zs_stock_alerts { width: 50px; text-align: center !important; }
            .column-zs_stock_alerts .dashicons { font-size: 18px; width: 18px; height: 18px; vertical-align: middle; }
            .zs-col-alert-critical { color: #dc3545; }
            .zs-col-alert-max-capacity { color: #fd7e14; }
            .zs-col-alert-low-stock { color: #ffc107; }
        </style>
        <?php
    }
}
