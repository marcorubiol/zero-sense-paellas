<?php
namespace ZeroSense\Features\WooCommerce\Migration;

use WC_Order;
use WP_Query;

/**
 * MetaBox to Plugin Fields Migrator
 *
 * Migrates custom fields from MetaBox to ZeroSense plugin fields
 * for HPOS compatibility and better data management.
 */
class MetaBoxMigrator
{
    private const FIELD_MAPPING = [
        'total_guests' => 'total_guests',
        'adults' => 'adults',
        'children_5_to_8' => 'children_5_to_8',
        'children_0_to_4' => 'children_0_to_4',
        'event_service_location' => 'event_service_location',
        'event_address' => 'event_address',
        'event_city' => 'event_city',
        'location_link' => 'location_link',
        'event_date' => 'event_date',
        'serving_time' => 'serving_time',
        'event_start_time' => 'event_start_time',
        'event_type' => 'event_type',
        'how_found_us' => 'how_found_us',
        'promo_code' => 'promo_code',
        'intolerances' => 'intolerances',
        'location' => 'location',
    ];

    public function getMigrationStatus(): array
    {
        error_log('[ZS Migration] getMigrationStatus() called');
        $start_time = microtime(true);
        
        $total_orders = 0;
        $migrated_orders = 0;

        if ($this->isHposEnabled()) {
            error_log('[ZS Migration] HPOS is enabled');
            
            $hpos_start = microtime(true);
            $total_orders = $this->getTotalOrdersWithMetaBoxHpos();
            error_log('[ZS Migration] HPOS total orders: ' . $total_orders . ' (took ' . round(microtime(true) - $hpos_start, 2) . 's)');
            
            $migrated_start = microtime(true);
            $migrated_orders = $this->getMigratedOrdersCountHpos();
            error_log('[ZS Migration] HPOS migrated orders: ' . $migrated_orders . ' (took ' . round(microtime(true) - $migrated_start, 2) . 's)');

            if ($total_orders === 0) {
                error_log('[ZS Migration] HPOS returned 0, falling back to legacy postmeta');
                $legacy_start = microtime(true);
                
                global $wpdb;
                $meta_keys = array_keys(self::FIELD_MAPPING);
                $statuses = $this->getOrderStatuses();
                $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
                $meta_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
                
                $sql = "SELECT COUNT(DISTINCT pm.post_id)
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                    WHERE p.post_type = 'shop_order'
                      AND p.post_status IN ({$status_placeholders})
                      AND pm.meta_key IN ({$meta_placeholders})";
                
                $params = array_merge($statuses, $meta_keys);
                $total_orders = (int) $wpdb->get_var($wpdb->prepare($sql, $params));
                error_log('[ZS Migration] Legacy total orders: ' . $total_orders . ' (took ' . round(microtime(true) - $legacy_start, 2) . 's)');
                
                $migrated_legacy_start = microtime(true);
                $sql = "SELECT COUNT(DISTINCT pm.post_id)
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                    WHERE p.post_type = 'shop_order'
                      AND p.post_status IN ({$status_placeholders})
                      AND pm.meta_key = %s";
                
                $params = array_merge($statuses, ['zs_metabox_migrated']);
                $migrated_orders = (int) $wpdb->get_var($wpdb->prepare($sql, $params));
                error_log('[ZS Migration] Legacy migrated orders: ' . $migrated_orders . ' (took ' . round(microtime(true) - $migrated_legacy_start, 2) . 's)');
            }
        } else {
            error_log('[ZS Migration] HPOS is disabled, using legacy postmeta');
            
            global $wpdb;
            $meta_keys = array_keys(self::FIELD_MAPPING);
            $statuses = $this->getOrderStatuses();
            $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
            $meta_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
            
            $sql = "SELECT COUNT(DISTINCT pm.post_id)
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type = 'shop_order'
                  AND p.post_status IN ({$status_placeholders})
                  AND pm.meta_key IN ({$meta_placeholders})";
            
            $params = array_merge($statuses, $meta_keys);
            $total_orders = (int) $wpdb->get_var($wpdb->prepare($sql, $params));
            error_log('[ZS Migration] Legacy total orders: ' . $total_orders);
            
            $sql = "SELECT COUNT(DISTINCT pm.post_id)
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type = 'shop_order'
                  AND p.post_status IN ({$status_placeholders})
                  AND pm.meta_key = %s";
            
            $params = array_merge($statuses, ['zs_metabox_migrated']);
            $migrated_orders = (int) $wpdb->get_var($wpdb->prepare($sql, $params));
            error_log('[ZS Migration] Legacy migrated orders: ' . $migrated_orders);
        }

        $pendingOrders = $total_orders - $migrated_orders;

        error_log('[ZS Migration] getMigrationStatus() finished (took ' . round(microtime(true) - $start_time, 2) . 's)');

        return [
            'total_orders' => $total_orders,
            'migrated_orders' => $migrated_orders,
            'pending_orders' => $pendingOrders,
            'migration_complete' => $pendingOrders === 0,
            'last_migration' => get_option('zs_metabox_migration_last_run', null),
            'migration_version' => get_option('zs_metabox_migration_version', null),
        ];
    }

    private function isHposEnabled(): bool
    {
        return class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    private function getTotalOrdersWithMetaBoxHpos(): int
    {
        global $wpdb;

        $tables = $this->getHposTables();
        $meta_keys = array_keys(self::FIELD_MAPPING);
        $statuses = $this->getOrderStatuses();

        error_log('[ZS Migration] getTotalOrdersWithMetaBoxHpos() - Statuses: ' . implode(', ', $statuses));
        error_log('[ZS Migration] getTotalOrdersWithMetaBoxHpos() - Meta keys: ' . implode(', ', $meta_keys));
        error_log('[ZS Migration] getTotalOrdersWithMetaBoxHpos() - Tables: ' . json_encode($tables));

        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $meta_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));

        $sql = "SELECT COUNT(DISTINCT m.order_id)
            FROM {$tables['meta']} m
            INNER JOIN {$tables['orders']} o ON o.id = m.order_id
            WHERE o.type = 'shop_order'
              AND o.status IN ({$status_placeholders})
              AND m.meta_key IN ({$meta_placeholders})";

        $params = array_merge($statuses, $meta_keys);
        $prepared_sql = $wpdb->prepare($sql, $params);
        error_log('[ZS Migration] getTotalOrdersWithMetaBoxHpos() - SQL: ' . $prepared_sql);
        
        $result = (int) $wpdb->get_var($prepared_sql);
        error_log('[ZS Migration] getTotalOrdersWithMetaBoxHpos() - Result: ' . $result);
        
        if ($wpdb->last_error) {
            error_log('[ZS Migration] getTotalOrdersWithMetaBoxHpos() - SQL Error: ' . $wpdb->last_error);
        }
        
        return $result;
    }

    private function getMigratedOrdersCountHpos(): int
    {
        global $wpdb;

        $tables = $this->getHposTables();
        $statuses = $this->getOrderStatuses();
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sql = "SELECT COUNT(DISTINCT m.order_id)
            FROM {$tables['meta']} m
            INNER JOIN {$tables['orders']} o ON o.id = m.order_id
            WHERE o.type = 'shop_order'
              AND o.status IN ({$status_placeholders})
              AND m.meta_key = %s";

        $params = array_merge($statuses, ['zs_metabox_migrated']);
        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    private function getSampleOrdersHpos(int $limit): array
    {
        global $wpdb;

        $tables = $this->getHposTables();
        $meta_keys = array_keys(self::FIELD_MAPPING);
        $statuses = $this->getOrderStatuses();

        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $meta_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));

        $sql = "SELECT DISTINCT o.id
            FROM {$tables['orders']} o
            INNER JOIN {$tables['meta']} m ON m.order_id = o.id
            LEFT JOIN {$tables['meta']} migrated ON migrated.order_id = o.id AND migrated.meta_key = %s
            WHERE o.type = 'shop_order'
              AND o.status IN ({$status_placeholders})
              AND m.meta_key IN ({$meta_placeholders})
              AND migrated.order_id IS NULL
            ORDER BY o.id DESC
            LIMIT %d";

        $params = array_merge(['zs_metabox_migrated'], $statuses, $meta_keys, [(int) $limit]);
        $order_ids = $wpdb->get_col($wpdb->prepare($sql, $params));

        $output = [];

        foreach ($order_ids as $order_id) {
            $order = wc_get_order((int) $order_id);
            if (!$order instanceof WC_Order) {
                continue;
            }

            $sample_data = [
                'order_id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'status' => $order->get_status(),
                'date' => $order->get_date_created()->format('Y-m-d H:i:s'),
                'metabox_fields' => [],
                'zerosense_fields' => [],
            ];

            foreach (array_keys(self::FIELD_MAPPING) as $metabox_key) {
                $value = $order->get_meta($metabox_key, true);
                if ($value !== '' && $value !== null) {
                    $sample_data['metabox_fields'][$metabox_key] = $value;
                }
            }

            foreach (self::FIELD_MAPPING as $metabox_key => $zerosense_key) {
                $value = $order->get_meta($zerosense_key, true);
                if ($value !== '' && $value !== null) {
                    $sample_data['zerosense_fields'][$zerosense_key] = $value;
                }
            }

            $output[] = $sample_data;
        }

        return $output;
    }

    private function getHposTables(): array
    {
        global $wpdb;

        return [
            'orders' => $wpdb->prefix . 'wc_orders',
            'meta' => $wpdb->prefix . 'wc_order_meta',
        ];
    }

    private function getTotalOrdersWithMetaBox(): int
    {
        if ($this->isHposEnabled()) {
            $count = $this->getTotalOrdersWithMetaBoxHpos();
            if ($count > 0) {
                return $count;
            }
        }

        global $wpdb;

        $meta_keys = array_keys(self::FIELD_MAPPING);
        $statuses = $this->getOrderStatuses();

        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $meta_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));

        $sql = "SELECT COUNT(DISTINCT pm.post_id)
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_order'
              AND p.post_status IN ({$status_placeholders})
              AND pm.meta_key IN ({$meta_placeholders})";

        $params = array_merge($statuses, $meta_keys);
        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    private function getMigratedOrdersCount(): int
    {
        if ($this->isHposEnabled()) {
            $count = $this->getMigratedOrdersCountHpos();
            if ($count > 0) {
                return $count;
            }
        }

        global $wpdb;

        $statuses = $this->getOrderStatuses();
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sql = "SELECT COUNT(DISTINCT pm.post_id)
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_order'
              AND p.post_status IN ({$status_placeholders})
              AND pm.meta_key = %s";

        $params = array_merge($statuses, ['zs_metabox_migrated']);
        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    private function getOrderStatuses(): array
    {
        global $wpdb;

        if ($this->isHposEnabled()) {
            $tables = $this->getHposTables();
            $sql = "SELECT DISTINCT status FROM {$tables['orders']} WHERE type = 'shop_order'";
            $statuses = $wpdb->get_col($sql);
            error_log('[ZS Migration] getOrderStatuses() - HPOS raw statuses: ' . implode(', ', $statuses));
        } else {
            $sql = "SELECT DISTINCT post_status FROM {$wpdb->posts} WHERE post_type = 'shop_order'";
            $statuses = $wpdb->get_col($sql);
            error_log('[ZS Migration] getOrderStatuses() - Legacy raw statuses: ' . implode(', ', $statuses));
        }

        $filtered = array_filter($statuses, function($status) {
            return !in_array($status, ['trash', 'auto-draft'], true);
        });
        
        error_log('[ZS Migration] getOrderStatuses() - Filtered statuses: ' . implode(', ', $filtered));
        
        return $filtered;
    }

    public function migrateAll(): array
    {
        $results = [
            'success' => 0,
            'errors' => 0,
            'skipped' => 0,
            'details' => [],
        ];

        $args = [
            'post_type' => 'shop_order',
            'post_status' => 'any',
            'posts_per_page' => 50,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'zs_metabox_migrated',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'relation' => 'OR',
                    ...array_map(function ($field) {
                        return [
                            'key' => $field,
                            'compare' => 'EXISTS',
                        ];
                    }, array_keys(self::FIELD_MAPPING)),
                ],
            ],
        ];

        $query = new WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $order_id = get_the_ID();
                $order = wc_get_order($order_id);

                if (!$order instanceof WC_Order) {
                    $results['skipped']++;
                    $results['details'][] = [
                        'order_id' => $order_id,
                        'status' => 'skipped',
                        'message' => 'Invalid order object',
                    ];
                    continue;
                }

                $migration_result = $this->migrateOrder($order);

                if ($migration_result['success']) {
                    $results['success']++;
                } else {
                    $results['errors']++;
                }

                $results['details'][] = $migration_result;
            }
        }

        wp_reset_postdata();

        update_option('zs_metabox_migration_last_run', current_time('mysql'));
        update_option('zs_metabox_migration_version', ZERO_SENSE_VERSION);

        return $results;
    }

    public function migrateOrder(WC_Order $order): array
    {
        $order_id = $order->get_id();
        $migrated_fields = [];
        $errors = [];

        foreach (self::FIELD_MAPPING as $metabox_key => $zerosense_key) {
            $metabox_value = get_post_meta($order_id, $metabox_key, true);

            if ($metabox_value !== '' && $metabox_value !== null) {
                $existing_value = $order->get_meta($zerosense_key, true);

                if ($existing_value === '' || $existing_value === null) {
                    $order->update_meta_data($zerosense_key, $metabox_value);
                    $migrated_fields[] = [
                        'field' => $zerosense_key,
                        'from' => $metabox_key,
                        'value' => $metabox_value,
                    ];
                }
            }
        }

        $order->update_meta_data('zs_metabox_migrated', true);
        $order->update_meta_data('zs_metabox_migration_date', current_time('mysql'));
        $order->update_meta_data('zs_metabox_migration_version', ZERO_SENSE_VERSION);

        $order->update_meta_data('zs_metabox_migration_log', [
            'migrated_fields' => $migrated_fields,
            'migration_date' => current_time('mysql'),
            'migration_version' => ZERO_SENSE_VERSION,
        ]);

        $order->save();

        return [
            'success' => empty($errors),
            'order_id' => $order_id,
            'migrated_fields' => $migrated_fields,
            'errors' => $errors,
        ];
    }

    public function getSampleOrders(int $limit = 5): array
    {
        if ($this->isHposEnabled()) {
            $orders = $this->getSampleOrdersHpos($limit);
            if (!empty($orders)) {
                return $orders;
            }
        }

        global $wpdb;

        $meta_keys = array_keys(self::FIELD_MAPPING);
        $statuses = $this->getOrderStatuses();

        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $meta_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));

        $sql = "SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            LEFT JOIN {$wpdb->postmeta} migrated ON migrated.post_id = p.ID AND migrated.meta_key = %s
            WHERE p.post_type = 'shop_order'
              AND p.post_status IN ({$status_placeholders})
              AND pm.meta_key IN ({$meta_placeholders})
              AND migrated.post_id IS NULL
            ORDER BY p.ID DESC
            LIMIT %d";

        $params = array_merge(['zs_metabox_migrated'], $statuses, $meta_keys, [(int) $limit]);
        $order_ids = $wpdb->get_col($wpdb->prepare($sql, $params));

        $orders = [];

        foreach ($order_ids as $order_id) {
            $order = wc_get_order((int) $order_id);

            if ($order instanceof WC_Order) {
                $sample_data = [
                    'order_id' => (int) $order_id,
                    'order_number' => $order->get_order_number(),
                    'status' => $order->get_status(),
                    'date' => $order->get_date_created()->format('Y-m-d H:i:s'),
                    'metabox_fields' => [],
                    'zerosense_fields' => [],
                ];

                foreach (array_keys(self::FIELD_MAPPING) as $metabox_key) {
                    $value = get_post_meta($order_id, $metabox_key, true);
                    if ($value !== '' && $value !== null) {
                        $sample_data['metabox_fields'][$metabox_key] = $value;
                    }
                }

                foreach (self::FIELD_MAPPING as $metabox_key => $zerosense_key) {
                    $value = $order->get_meta($zerosense_key, true);
                    if ($value !== '' && $value !== null) {
                        $sample_data['zerosense_fields'][$zerosense_key] = $value;
                    }
                }

                $orders[] = $sample_data;
            }
        }

        return $orders;
    }

    public function rollbackOrder(WC_Order $order): array
    {
        $order_id = $order->get_id();
        $rollback_fields = [];

        $migration_log = $order->get_meta('zs_metabox_migration_log', true);

        if (is_array($migration_log) && isset($migration_log['migrated_fields'])) {
            foreach ($migration_log['migrated_fields'] as $field_info) {
                $zerosense_key = $field_info['field'];
                $order->delete_meta_data($zerosense_key);
                $rollback_fields[] = $zerosense_key;
            }
        }

        $order->delete_meta_data('zs_metabox_migrated');
        $order->delete_meta_data('zs_metabox_migration_date');
        $order->delete_meta_data('zs_metabox_migration_version');
        $order->delete_meta_data('zs_metabox_migration_log');

        $order->save();

        return [
            'success' => true,
            'order_id' => $order_id,
            'rollback_fields' => $rollback_fields,
        ];
    }
}
