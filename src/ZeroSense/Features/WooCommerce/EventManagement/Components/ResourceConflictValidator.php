<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Components;

use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;

class ResourceConflictValidator
{
    public function register(): void
    {
        if (!is_admin()) {
            return;
        }

        // Backend validation on form save (priority 1, before actual saves at 10)
        add_action('woocommerce_process_shop_order_meta', [$this, 'validateOnSave'], 2);

        // AJAX endpoint for frontend conflict checks
        add_action('wp_ajax_zs_check_resource_conflicts', [$this, 'ajaxCheckConflicts']);
    }

    /**
     * Validate on form save — block if conflicts exist.
     */
    public function validateOnSave(int $orderId): void
    {
        // Only run when the event details nonce is present (full form save)
        if (!isset($_POST['zs_event_details_nonce']) ||
            !wp_verify_nonce($_POST['zs_event_details_nonce'], 'zs_event_details_save')) {
            return;
        }

        $eventDate = isset($_POST['event_date']) ? sanitize_text_field($_POST['event_date']) : '';
        if (empty($eventDate)) {
            return; // No date — required field validation handles this
        }

        $errors = [];

        // Check vehicle conflicts
        $vehicleIds = isset($_POST['zs_event_vehicles']) && is_array($_POST['zs_event_vehicles'])
            ? array_map('absint', $_POST['zs_event_vehicles'])
            : [];
        $vehicleIds = array_filter($vehicleIds);

        if (!empty($vehicleIds)) {
            $vehicleConflicts = self::findVehicleConflicts($orderId, $eventDate, $vehicleIds);
            foreach ($vehicleConflicts as $conflict) {
                $errors[] = sprintf(
                    __('The vehicle <strong>%s</strong> is already assigned to order <a href="%s">#%d</a> on %s.', 'zero-sense'),
                    esc_html($conflict['resource_name']),
                    esc_url($conflict['order_edit_url']),
                    $conflict['order_id'],
                    esc_html($eventDate)
                );
            }
        }

        // Check staff conflicts
        $rawStaff = isset($_POST['zs_event_staff']) && is_array($_POST['zs_event_staff']) ? $_POST['zs_event_staff'] : [];
        $staffIds = [];
        foreach ($rawStaff as $role => $ids) {
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    $id = absint($id);
                    if ($id > 0) {
                        $staffIds[] = $id;
                    }
                }
            }
        }
        $staffIds = array_unique($staffIds);

        if (!empty($staffIds)) {
            $staffConflicts = self::findStaffConflicts($orderId, $eventDate, $staffIds);
            foreach ($staffConflicts as $conflict) {
                $errors[] = sprintf(
                    __('<strong>%s</strong> (%s) is already assigned to order <a href="%s">#%d</a> on %s.', 'zero-sense'),
                    esc_html($conflict['resource_name']),
                    esc_html($conflict['role']),
                    esc_url($conflict['order_edit_url']),
                    $conflict['order_id'],
                    esc_html($eventDate)
                );
            }
        }

        if (empty($errors)) {
            return;
        }

        $transientKey = 'zs_order_validation_errors_' . get_current_user_id() . '_' . $orderId;
        $existing = get_transient($transientKey);
        if (is_array($existing)) {
            $errors = array_merge($existing, $errors);
        }
        set_transient($transientKey, $errors, 60);

        $redirectUrl = add_query_arg(
            ['post' => $orderId, 'action' => 'edit', 'zs_validation_error' => '1'],
            admin_url('post.php')
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    /**
     * AJAX endpoint — checks conflicts for vehicles and/or staff on a given date.
     */
    public function ajaxCheckConflicts(): void
    {
        check_ajax_referer('zs_check_conflicts', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error('Insufficient permissions');
        }

        $orderId   = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $eventDate = isset($_POST['event_date']) ? sanitize_text_field($_POST['event_date']) : '';

        if (!$orderId || !$eventDate) {
            wp_send_json_success(['conflicts' => []]);
        }

        $conflicts = [];

        // Vehicle conflicts
        $vehicleIds = isset($_POST['vehicle_ids']) && is_array($_POST['vehicle_ids'])
            ? array_map('absint', $_POST['vehicle_ids'])
            : [];
        $vehicleIds = array_filter($vehicleIds);

        if (!empty($vehicleIds)) {
            $conflicts = array_merge($conflicts, self::findVehicleConflicts($orderId, $eventDate, $vehicleIds));
        }

        // Staff conflicts
        $staffIds = isset($_POST['staff_ids']) && is_array($_POST['staff_ids'])
            ? array_map('absint', $_POST['staff_ids'])
            : [];
        $staffIds = array_filter($staffIds);

        if (!empty($staffIds)) {
            $conflicts = array_merge($conflicts, self::findStaffConflicts($orderId, $eventDate, $staffIds));
        }

        wp_send_json_success(['conflicts' => $conflicts]);
    }

    /**
     * Find orders on the same date that have any of the given vehicle IDs assigned.
     *
     * @return array[] Each element: ['order_id', 'order_edit_url', 'resource_name', 'resource_id', 'type']
     */
    public static function findVehicleConflicts(int $currentOrderId, string $eventDate, array $vehicleIds): array
    {
        $orders = self::getOrdersByDate($eventDate, $currentOrderId);
        $conflicts = [];

        foreach ($orders as $order) {
            $assigned = $order->get_meta(MetaKeys::EVENT_VEHICLES, true);
            if (!is_array($assigned)) {
                continue;
            }

            $overlap = array_intersect(array_map('intval', $assigned), $vehicleIds);
            foreach ($overlap as $vehicleId) {
                $vehiclePost = get_post($vehicleId);
                $plate = get_post_meta($vehicleId, 'zs_vehicle_plate', true);
                $name = $vehiclePost ? $vehiclePost->post_title : "#{$vehicleId}";
                if ($plate) {
                    $name .= " ({$plate})";
                }

                $conflicts[] = [
                    'type'           => 'vehicle',
                    'resource_id'    => $vehicleId,
                    'resource_name'  => $name,
                    'role'           => '',
                    'order_id'       => $order->get_id(),
                    'order_edit_url' => $order->get_edit_order_url(),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Find orders on the same date that have any of the given staff IDs assigned.
     *
     * @return array[] Each element: ['order_id', 'order_edit_url', 'resource_name', 'resource_id', 'role', 'type']
     */
    public static function findStaffConflicts(int $currentOrderId, string $eventDate, array $staffIds): array
    {
        $orders = self::getOrdersByDate($eventDate, $currentOrderId);
        $conflicts = [];
        $seen = [];

        foreach ($orders as $order) {
            $assignments = $order->get_meta(MetaKeys::EVENT_STAFF, true);
            if (!is_array($assignments)) {
                continue;
            }

            foreach ($assignments as $assignment) {
                $sid = isset($assignment['staff_id']) ? (int) $assignment['staff_id'] : 0;
                if ($sid <= 0 || !in_array($sid, $staffIds, true)) {
                    continue;
                }

                $key = $sid . '-' . $order->get_id();
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $staffPost = get_post($sid);
                $role = $assignment['role'] ?? '';

                $conflicts[] = [
                    'type'           => 'staff',
                    'resource_id'    => $sid,
                    'resource_name'  => $staffPost ? $staffPost->post_title : "#{$sid}",
                    'role'           => $role,
                    'order_id'       => $order->get_id(),
                    'order_edit_url' => $order->get_edit_order_url(),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Get all orders on a given event date, excluding the current order.
     * Only returns orders in active statuses.
     *
     * @return WC_Order[]
     */
    private static function getOrdersByDate(string $eventDate, int $excludeOrderId): array
    {
        $args = [
            'limit'      => -1,
            'status'     => array_diff(array_keys(wc_get_order_statuses()), ['wc-cancelled', 'wc-refunded', 'wc-failed']),
            'meta_query' => [
                [
                    'key'   => MetaKeys::EVENT_DATE,
                    'value' => $eventDate,
                ],
            ],
            'exclude'    => [$excludeOrderId],
        ];

        return wc_get_orders($args);
    }
}
