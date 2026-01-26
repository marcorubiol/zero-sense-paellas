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
        $totalOrders = $this->getTotalOrdersWithMetaBox();
        $migratedOrders = $this->getMigratedOrdersCount();
        $pendingOrders = $totalOrders - $migratedOrders;

        return [
            'total_orders' => $totalOrders,
            'migrated_orders' => $migratedOrders,
            'pending_orders' => $pendingOrders,
            'migration_complete' => $pendingOrders === 0,
            'last_migration' => get_option('zs_metabox_migration_last_run', null),
            'migration_version' => get_option('zs_metabox_migration_version', null),
        ];
    }

    private function getTotalOrdersWithMetaBox(): int
    {
        $args = [
            'post_type' => 'shop_order',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'meta_query' => [
                'relation' => 'OR',
                ...array_map(function ($field) {
                    return [
                        'key' => $field,
                        'compare' => 'EXISTS',
                    ];
                }, array_keys(self::FIELD_MAPPING)),
            ],
            'fields' => 'ids',
        ];

        $query = new WP_Query($args);
        return (int) $query->found_posts;
    }

    private function getMigratedOrdersCount(): int
    {
        $args = [
            'post_type' => 'shop_order',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'zs_metabox_migrated',
                    'compare' => 'EXISTS',
                ],
            ],
            'fields' => 'ids',
        ];

        $query = new WP_Query($args);
        return (int) $query->found_posts;
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
        $args = [
            'post_type' => 'shop_order',
            'post_status' => 'any',
            'posts_per_page' => $limit,
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
        $orders = [];

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $order_id = get_the_ID();
                $order = wc_get_order($order_id);

                if ($order instanceof WC_Order) {
                    $sample_data = [
                        'order_id' => $order_id,
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
        }

        wp_reset_postdata();
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
