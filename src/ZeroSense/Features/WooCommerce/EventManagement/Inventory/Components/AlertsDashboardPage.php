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
        $allAlerts = AlertCalculator::getAlertsForOrders(array_values($orderIds));
        $materials = MaterialDefinitions::getAll();

        // Counts per type
        $counts = [
            AlertCalculator::ALERT_CRITICAL     => 0,
            AlertCalculator::ALERT_MAX_CAPACITY => 0,
            AlertCalculator::ALERT_LOW_STOCK    => 0,
        ];
        foreach ($allAlerts as $alert) {
            if (isset($counts[$alert['alert_type']])) {
                $counts[$alert['alert_type']]++;
            }
        }

        // Active filter
        $filterType = isset($_GET['alert_type']) && array_key_exists($_GET['alert_type'], $counts)
            ? sanitize_key($_GET['alert_type'])
            : '';

        $alerts = $filterType
            ? array_values(array_filter($allAlerts, fn($a) => $a['alert_type'] === $filterType))
            : $allAlerts;

        // Group by order_id, keeping worst alert type per order
        $severityOrder = [
            AlertCalculator::ALERT_CRITICAL     => 1,
            AlertCalculator::ALERT_MAX_CAPACITY => 2,
            AlertCalculator::ALERT_LOW_STOCK    => 3,
        ];

        $byOrder = [];
        foreach ($alerts as $alert) {
            $oid = $alert['order_id'];
            if (!isset($byOrder[$oid])) {
                $byOrder[$oid] = ['order_id' => $oid, 'alerts' => [], 'worst' => $alert['alert_type']];
            }
            $byOrder[$oid]['alerts'][] = $alert;
            if (($severityOrder[$alert['alert_type']] ?? 9) < ($severityOrder[$byOrder[$oid]['worst']] ?? 9)) {
                $byOrder[$oid]['worst'] = $alert['alert_type'];
            }
        }

        // Sort groups by worst alert type, then by event date
        uasort($byOrder, function ($a, $b) use ($severityOrder) {
            $diff = ($severityOrder[$a['worst']] ?? 9) - ($severityOrder[$b['worst']] ?? 9);
            if ($diff !== 0) return $diff;
            $aDate = $a['alerts'][0]['event_date'] ?? '';
            $bDate = $b['alerts'][0]['event_date'] ?? '';
            return strcmp($aDate, $bDate);
        });

        $baseUrl = admin_url('admin.php?page=zs-inventory-alerts');

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Inventory Alerts', 'zero-sense'); ?></h1>

            <?php if (empty($allAlerts)): ?>
                <div class="notice notice-success inline" style="margin-top: 15px;">
                    <p><?php esc_html_e('No active inventory alerts in the next 60 days.', 'zero-sense'); ?></p>
                </div>
            <?php else: ?>

                <?php
                $tabs = [
                    ''                                  => [__('All', 'zero-sense'),          array_sum($counts), ''],
                    AlertCalculator::ALERT_CRITICAL     => [__('Critical', 'zero-sense'),     $counts[AlertCalculator::ALERT_CRITICAL],     '#dc3545'],
                    AlertCalculator::ALERT_MAX_CAPACITY => [__('Max Capacity', 'zero-sense'), $counts[AlertCalculator::ALERT_MAX_CAPACITY], '#fd7e14'],
                    AlertCalculator::ALERT_LOW_STOCK    => [__('Low Stock', 'zero-sense'),    $counts[AlertCalculator::ALERT_LOW_STOCK],    '#ffc107'],
                ];
                ?>
                <ul class="subsubsub" style="margin-bottom:10px;">
                    <?php foreach ($tabs as $type => [$label, $count, $color]): if ($count === 0 && $type !== '') continue; ?>
                        <li>
                            <a href="<?php echo esc_url($type ? add_query_arg('alert_type', $type, $baseUrl) : $baseUrl); ?>"
                               <?php if ($filterType === $type): ?>style="font-weight:700;<?php if ($color): ?> color:<?php echo $color; ?>;<?php endif; ?>"<?php endif; ?>>
                                <?php echo esc_html($label); ?>
                                <span class="count">(<?php echo $count; ?>)</span>
                            </a> |
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if (empty($byOrder)): ?>
                    <p><?php esc_html_e('No alerts match this filter.', 'zero-sense'); ?></p>
                <?php else: ?>

                <table class="widefat striped" style="margin-top: 5px;">
                    <thead>
                        <tr>
                            <th style="width:180px;"><?php esc_html_e('Order', 'zero-sense'); ?></th>
                            <th style="width:110px;"><?php esc_html_e('Event Date', 'zero-sense'); ?></th>
                            <th><?php esc_html_e('Alerts', 'zero-sense'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($byOrder as $group):
                            $orderId = $group['order_id'];
                            $order   = wc_get_order($orderId);
                            $eventDate = $order ? $order->get_meta('zs_event_date', true) : '';
                            $orderUrl  = ($order ? $order->get_edit_order_url() : admin_url('post.php?post=' . $orderId . '&action=edit')) . '#zs_inventory_materials';
                        ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url($orderUrl); ?>" style="font-weight:600;">#<?php echo $orderId; ?></a>
                                    <?php if ($order): ?>
                                        <div style="font-size:11px; color:#666;"><?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $eventDate ? esc_html(date_i18n(get_option('date_format'), strtotime($eventDate))) : '—'; ?>
                                </td>
                                <td>
                                    <div style="display:flex; flex-wrap:wrap; gap:6px;">
                                    <?php foreach ($group['alerts'] as $alert):
                                        $materialDef   = $materials[$alert['material_key']] ?? null;
                                        $materialLabel = $materialDef ? $materialDef['label'] : ucwords(str_replace('_', ' ', $alert['material_key']));
                                        $icon  = AlertCalculator::getAlertIcon($alert['alert_type']);
                                        $color = AlertCalculator::getAlertColor($alert['alert_type']);
                                        $label = AlertCalculator::getAlertLabel($alert['alert_type']);
                                        $detail = $alert['shortage'] > 0
                                            ? sprintf('-%d', $alert['shortage'])
                                            : $alert['usage_percent'] . '%';
                                    ?>
                                        <span style="display:inline-flex; align-items:center; gap:4px; background:#f6f7f7; border:1px solid #ddd; border-left:3px solid <?php echo esc_attr($color); ?>; border-radius:3px; padding:3px 8px; font-size:12px;" title="<?php echo esc_attr($label); ?>">
                                            <span class="dashicons <?php echo esc_attr($icon); ?>" style="color:<?php echo esc_attr($color); ?>; font-size:14px; width:14px; height:14px;"></span>
                                            <strong><?php echo esc_html($materialLabel); ?></strong>
                                            <span style="color:#666;"><?php echo esc_html($detail); ?></span>
                                        </span>
                                    <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="color:#666; font-size:12px; margin-top:10px;">
                    <?php esc_html_e('Showing unresolved alerts for orders with events between 7 days ago and 60 days ahead. Open an order to resolve alerts.', 'zero-sense'); ?>
                </p>

                <?php endif; ?>
            <?php endif; ?>
        </div>
        <style>
            .zs-alerts-dashboard .subsubsub { margin: 10px 0; }
        </style>
        <?php
    }
}
