<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Components;

use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\AlertCalculator;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\MaterialDefinitions;

class AlertsDashboardPage
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenuPage(): void
    {
        add_submenu_page(
            'event-operations',
            __('Inventory Alerts', 'zero-sense'),
            __('Inventory Alerts', 'zero-sense'),
            'manage_woocommerce',
            'zs-inventory-alerts',
            [$this, 'render']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'event-operations_page_zs-inventory-alerts') {
            return;
        }

        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'zs-admin-inventory',
            defined('ZERO_SENSE_URL') ? ZERO_SENSE_URL . 'assets/css/admin-inventory.css' : '',
            ['dashicons'],
            '1.0.0'
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $allowedStatuses = ['deposit-paid', 'fully-paid'];
        $orderIds = AlertCalculator::getOrderIdsWithReservations(7, 60);
        $orderIds = array_filter($orderIds, function ($id) use ($allowedStatuses) {
            $order = wc_get_order($id);
            return $order && in_array($order->get_status(), $allowedStatuses, true);
        });
        $alerts = AlertCalculator::getAlertsForOrders(array_values($orderIds));
        $materials = MaterialDefinitions::getAll();

        $criticalCount    = 0;
        $maxCapacityCount = 0;
        $lowStockCount    = 0;

        foreach ($alerts as $alert) {
            switch ($alert['alert_type']) {
                case AlertCalculator::ALERT_CRITICAL:
                    $criticalCount++;
                    break;
                case AlertCalculator::ALERT_MAX_CAPACITY:
                    $maxCapacityCount++;
                    break;
                case AlertCalculator::ALERT_LOW_STOCK:
                    $lowStockCount++;
                    break;
            }
        }

        usort($alerts, function ($a, $b) {
            $order = [
                AlertCalculator::ALERT_CRITICAL    => 1,
                AlertCalculator::ALERT_MAX_CAPACITY => 2,
                AlertCalculator::ALERT_LOW_STOCK   => 3,
            ];
            $aOrder = $order[$a['alert_type']] ?? 9;
            $bOrder = $order[$b['alert_type']] ?? 9;
            if ($aOrder !== $bOrder) {
                return $aOrder - $bOrder;
            }
            return strcmp($a['event_date'] ?? '', $b['event_date'] ?? '');
        });

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Inventory Alerts', 'zero-sense'); ?></h1>

            <?php if (empty($alerts)): ?>
                <div class="notice notice-success inline" style="margin-top: 15px;">
                    <p><?php esc_html_e('No active inventory alerts in the next 60 days.', 'zero-sense'); ?></p>
                </div>
            <?php else: ?>

                <div style="display: flex; gap: 12px; margin: 15px 0; flex-wrap: wrap;">
                    <?php if ($criticalCount > 0): ?>
                        <div style="background:#fff; border-left:4px solid #dc3545; padding:12px 18px; box-shadow:0 1px 2px rgba(0,0,0,.08); border-radius:3px; display:flex; align-items:center; gap:8px;">
                            <span class="dashicons dashicons-dismiss" style="color:#dc3545; font-size:20px; width:20px; height:20px;"></span>
                            <strong style="font-size:22px;"><?php echo $criticalCount; ?></strong>
                            <span style="color:#666;"><?php esc_html_e('Critical', 'zero-sense'); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($maxCapacityCount > 0): ?>
                        <div style="background:#fff; border-left:4px solid #fd7e14; padding:12px 18px; box-shadow:0 1px 2px rgba(0,0,0,.08); border-radius:3px; display:flex; align-items:center; gap:8px;">
                            <span class="dashicons dashicons-warning" style="color:#fd7e14; font-size:20px; width:20px; height:20px;"></span>
                            <strong style="font-size:22px;"><?php echo $maxCapacityCount; ?></strong>
                            <span style="color:#666;"><?php esc_html_e('Max Capacity', 'zero-sense'); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($lowStockCount > 0): ?>
                        <div style="background:#fff; border-left:4px solid #ffc107; padding:12px 18px; box-shadow:0 1px 2px rgba(0,0,0,.08); border-radius:3px; display:flex; align-items:center; gap:8px;">
                            <span class="dashicons dashicons-flag" style="color:#ffc107; font-size:20px; width:20px; height:20px;"></span>
                            <strong style="font-size:22px;"><?php echo $lowStockCount; ?></strong>
                            <span style="color:#666;"><?php esc_html_e('Low Stock', 'zero-sense'); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <table class="widefat striped" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Order', 'zero-sense'); ?></th>
                            <th><?php esc_html_e('Event Date', 'zero-sense'); ?></th>
                            <th><?php esc_html_e('Material', 'zero-sense'); ?></th>
                            <th><?php esc_html_e('Alert', 'zero-sense'); ?></th>
                            <th><?php esc_html_e('Needed / Stock', 'zero-sense'); ?></th>
                            <th><?php esc_html_e('Conflicts', 'zero-sense'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alerts as $alert):
                            $orderId     = $alert['order_id'];
                            $order       = wc_get_order($orderId);
                            $eventDate   = $order ? $order->get_meta('zs_event_date', true) : '';
                            $materialDef = $materials[$alert['material_key']] ?? null;
                            $materialLabel = $materialDef ? $materialDef['label'] : ucwords(str_replace('_', ' ', $alert['material_key']));

                            $iconClass = AlertCalculator::getAlertIcon($alert['alert_type']);
                            $cssClass  = 'alert-' . str_replace('_', '-', $alert['alert_type']);
                        ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $orderId . '&action=edit')); ?>" style="font-weight:600;">
                                        #<?php echo $orderId; ?>
                                    </a>
                                    <?php if ($order): ?>
                                        <div style="font-size:11px; color:#666;"><?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $eventDate ? esc_html(date_i18n(get_option('date_format'), strtotime($eventDate))) : '—'; ?>
                                </td>
                                <td><?php echo esc_html($materialLabel); ?></td>
                                <td>
                                    <span style="display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:600;">
                                        <span class="dashicons <?php echo esc_attr($iconClass); ?> <?php echo esc_attr($cssClass); ?>" style="font-size:16px; width:16px; height:16px;"></span>
                                        <?php echo esc_html(AlertCalculator::getAlertLabel($alert['alert_type'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php printf('%d / %d', $alert['total_needed'], $alert['total_stock']); ?>
                                    <?php if ($alert['shortage'] > 0): ?>
                                        <span style="color:#dc3545; font-size:11px;">(<?php printf(__('-%d shortage', 'zero-sense'), $alert['shortage']); ?>)</span>
                                    <?php else: ?>
                                        <span style="color:#666; font-size:11px;">(<?php echo $alert['usage_percent']; ?>%)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($alert['conflicts'])): ?>
                                        <?php foreach ($alert['conflicts'] as $idx => $conflict): ?>
                                            <?php if ($idx > 0) echo ', '; ?>
                                            <a href="<?php echo esc_url(admin_url('post.php?post=' . $conflict['order_id'] . '&action=edit')); ?>" target="_blank">
                                                #<?php echo $conflict['order_id']; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="color:#666; font-size:12px; margin-top:10px;">
                    <?php esc_html_e('Showing unresolved alerts for orders with events between 7 days ago and 60 days ahead. Open an order to resolve alerts.', 'zero-sense'); ?>
                </p>

            <?php endif; ?>
        </div>
        <style>
            .dashicons.alert-critical { color: #dc3545; }
            .dashicons.alert-max-capacity { color: #fd7e14; }
            .dashicons.alert-low-stock { color: #ffc107; }
        </style>
        <?php
    }
}
