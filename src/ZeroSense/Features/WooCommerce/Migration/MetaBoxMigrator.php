<?php
namespace ZeroSense\Features\WooCommerce\Migration;

use WC_Order;
use ZeroSense\Core\Logger;

/**
 * MetaBox to Plugin Fields Migrator
 *
 * Migrates custom fields from MetaBox to ZeroSense plugin fields
 * for HPOS compatibility and better data management.
 */
class MetaBoxMigrator
{
    private const FIELD_MAPPING = [
        'total_guests' => 'zs_event_total_guests',
        'adults' => 'zs_event_adults',
        'children_5_to_8' => 'zs_event_children_5_to_8',
        'children_0_to_4' => 'zs_event_children_0_to_4',
        'event_service_location' => 'zs_event_service_location',
        'event_address' => '_shipping_address_1',
        'event_city' => '_shipping_city',
        'location_link' => '_shipping_location_link',
        'event_date' => 'zs_event_date',
        'serving_time' => 'zs_event_serving_time',
        'paellas_service_time' => 'zs_event_serving_time',
        'starters_service_time' => 'zs_event_starters_service_time',
        'event_start_time' => 'zs_event_start_time',
        'open_bar_start' => 'zs_event_open_bar_start',
        'open_bar_end' => 'zs_event_open_bar_end',
        'cocktail_start' => 'zs_event_cocktail_start',
        'cocktail_end' => 'zs_event_cocktail_end',
        'event_type' => 'zs_event_type',
        'how_found_us' => 'zs_event_how_found_us',
        'promo_code' => 'zs_event_promo_code',
        'intolerances' => 'zs_event_intolerances',
        'location' => 'zs_event_location',
        'budget_email_content' => 'zs_budget_email_content',
        'final_details_email_content' => 'zs_final_details_email_content',
        'marketing_consent_checkbox' => 'zs_marketing_consent',
    ];

    private const LEGACY_EVENT_MAPPING = [
        'total_guests' => '_event_total_guests',
        'adults' => '_event_adults',
        'children_5_to_8' => '_event_children_5_to_8',
        'children_0_to_4' => '_event_children_0_to_4',
        'event_service_location' => '_event_service_location',
        'event_address' => '_event_address',
        'event_city' => '_event_city',
        'location_link' => '_event_location_link',
        'event_date' => '_event_date',
        'serving_time' => '_event_serving_time',
        'paellas_service_time' => '_event_paellas_service_time',
        'starters_service_time' => '_event_starters_service_time',
        'event_start_time' => '_event_start_time',
        'open_bar_start' => '_event_open_bar_start',
        'open_bar_end' => '_event_open_bar_end',
        'cocktail_start' => '_event_cocktail_start',
        'cocktail_end' => '_event_cocktail_end',
        'event_type' => '_event_type',
        'how_found_us' => '_event_how_found_us',
        'promo_code' => '_event_promo_code',
        'intolerances' => '_event_intolerances',
        'location' => '_event_location',
        'budget_email_content' => '_budget_email_content',
        'final_details_email_content' => '_final_details_email_content',
        'marketing_consent_checkbox' => '_marketing_consent_checkbox',
    ];

    private const EVENT_DATE_META_BOX_KEY = 'event_date';
    private const EVENT_DATE_ZEROSENSE_KEY = 'zs_event_date';

    private const META_SHIPPING_EMAIL = '_shipping_email';
    private const META_OPS_MATERIAL = 'zs_ops_material';

    private const NATIVE_SHIPPING_SETTERS = [
        '_shipping_address_1' => 'set_shipping_address_1',
        '_shipping_city' => 'set_shipping_city',
    ];

    // Value mappings for field updates
    private const EVENT_TYPE_VALUE_MAPPING = [
        // Old Spanish values -> New English keys
        'Boda' => 'wedding',
        'Comida de empresa' => 'corporate_meal',
        'Día antes de la boda' => 'wedding_eve',
        'Día después de la boda' => 'wedding_day_after',
        'Cumpleaños' => 'birthday',
        'Encuentro de amigos y/o familia' => 'friends_family_gathering',
        'Inauguración' => 'inauguration',
        'Evento deportivo' => 'sports_event',
        'Evento social' => 'social_event',
        'Ceremonia de despedida' => 'farewell_ceremony',
        'Workshop / Teambuilding' => 'workshop_teambuilding',
        'Otro' => 'other',
        
        // Old Catalan values -> New English keys
        'Boda' => 'wedding',
        'Sopar d empresa' => 'corporate_meal',
        'Dia abans de la boda' => 'wedding_eve',
        'Dia després de la boda' => 'wedding_day_after',
        'Aniversari' => 'birthday',
        'Trobada d amics i o familia' => 'friends_family_gathering',
        'Inauguració' => 'inauguration',
        'Esdeveniment esportiu' => 'sports_event',
        'Esdeveniment social' => 'social_event',
        'Cerimònia de comiat' => 'farewell_ceremony',
        'Workshop / Teambuilding' => 'workshop_teambuilding',
        'Altre' => 'other',
        
        // Keep existing English keys if they match
        'wedding' => 'wedding',
        'corporate_meal' => 'corporate_meal',
        'wedding_eve' => 'wedding_eve',
        'wedding_day_after' => 'wedding_day_after',
        'birthday' => 'birthday',
        'friends_family_gathering' => 'friends_family_gathering',
        'inauguration' => 'inauguration',
        'sports_event' => 'sports_event',
        'social_event' => 'social_event',
        'farewell_ceremony' => 'farewell_ceremony',
        'workshop_teambuilding' => 'workshop_teambuilding',
        'other' => 'other',
    ];

    private const HOW_FOUND_US_VALUE_MAPPING = [
        // Old Spanish values -> New English keys
        'Google' => 'google',
        'Instagram' => 'instagram',
        'Facebook' => 'facebook',
        'De ocasiones anteriores' => 'previous_customer',
        'Recomendación de amistades' => 'friend_recommendation',
        'Nuestros anfitriones' => 'our_hosts',
        'Guia catering' => 'catering_guide',
        'Catering click' => 'catering_click',
        'Bodas.net' => 'bodas_net',
        'Otros' => 'other',
        
        // Old Catalan values -> New English keys
        'Google' => 'google',
        'Instagram' => 'instagram',
        'Facebook' => 'facebook',
        'Ocasions anteriors' => 'previous_customer',
        'Recomanació d amistats' => 'friend_recommendation',
        'Els nostres amfitrions' => 'our_hosts',
        'Guia catering' => 'catering_guide',
        'Catering click' => 'catering_click',
        'Bodas.net' => 'bodas_net',
        'Altres' => 'other',
        
        // Keep existing English keys if they match
        'google' => 'google',
        'instagram' => 'instagram',
        'facebook' => 'facebook',
        'previous_customer' => 'previous_customer',
        'friend_recommendation' => 'friend_recommendation',
        'our_hosts' => 'our_hosts',
        'catering_guide' => 'catering_guide',
        'catering_click' => 'catering_click',
        'bodas_net' => 'bodas_net',
        'other' => 'other',
    ];


    public function getMigrationStatus(): array
    {
        $total_orders = 0;
        $migrated_orders = 0;
        $pending_orders = 0;

        if ($this->isHposEnabled()) {
            $total_orders = $this->getTotalOrdersWithMetaBoxHpos();
            $migrated_orders = $this->getMigratedOrdersCountCorrect();

            if ($total_orders === 0) {
                // Fallback to legacy
                global $wpdb;
                $meta_keys = $this->getWatchedMetaKeys();
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
                $migrated_orders = $this->getMigratedOrdersCountCorrect();
            }
        } else {
            $total_orders = $this->getTotalOrdersWithMetaBox();
            $migrated_orders = $this->getMigratedOrdersCountCorrect();
        }

        // Use the same logic as the list to count pending orders
        $pending_orders = $this->getPendingOrdersCount();

        return [
            'total_orders' => $total_orders,
            'migrated_orders' => $migrated_orders,
            'pending_orders' => $pending_orders,
            'migration_complete' => $pending_orders === 0,
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
        $meta_keys = $this->getWatchedMetaKeys();
        $statuses = $this->getOrderStatuses();

        
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $meta_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));

        $sql = "SELECT COUNT(DISTINCT m.order_id)
            FROM {$tables['meta']} m
            INNER JOIN {$tables['orders']} o ON o.id = m.order_id
            WHERE o.type = 'shop_order'
              AND o.status IN ({$status_placeholders})
              AND m.meta_key IN ({$meta_placeholders})";

        $params = array_merge($statuses, $meta_keys);
        $result = (int) $wpdb->get_var($wpdb->prepare($sql, $params));
        
        return $result;
    }

    private function getMigratedOrdersCountHpos(): int
    {
        global $wpdb;

        $tables = $this->getHposTables();
        $statuses = $this->getOrderStatuses();
        $zerosense_keys = array_values(self::FIELD_MAPPING);
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $meta_placeholders = implode(',', array_fill(0, count($zerosense_keys), '%s'));

        // Count orders that have actual ZeroSense data (not just migration flags)
        $sql = "SELECT COUNT(DISTINCT m.order_id)
            FROM {$tables['meta']} m
            INNER JOIN {$tables['orders']} o ON o.id = m.order_id
            WHERE o.type = 'shop_order'
              AND o.status IN ({$status_placeholders})
              AND m.meta_key IN ({$meta_placeholders})
              AND m.meta_value != ''
              AND m.meta_value IS NOT NULL";

        $params = array_merge($statuses, $zerosense_keys);
        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    private function getSampleOrdersHpos(int $limit): array
    {
        // Use the same logic as getPendingOrdersHpos to get only pending orders
        $pending_order_ids = $this->getPendingOrdersHpos($limit);
        
        $order_ids = $pending_order_ids;

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
                $setter = self::NATIVE_SHIPPING_SETTERS[$zerosense_key] ?? null;
                $value = $setter !== null && is_callable([$order, str_replace('set_', 'get_', $setter)])
                    ? $order->{str_replace('set_', 'get_', $setter)}('edit')
                    : $order->get_meta($zerosense_key, true);
                if ($value !== '' && $value !== null) {
                    $sample_data['zerosense_fields'][$zerosense_key] = $value;
                }
            }

            $shipping_email = $order->get_meta(self::META_SHIPPING_EMAIL, true);
            if ($shipping_email !== '' && $shipping_email !== null) {
                $sample_data['zerosense_fields'][self::META_SHIPPING_EMAIL] = $shipping_email;
            }

            $ops_material = $order->get_meta(self::META_OPS_MATERIAL, true);
            if ($ops_material !== '' && $ops_material !== null) {
                $sample_data['zerosense_fields'][self::META_OPS_MATERIAL] = $ops_material;
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
            'meta' => $wpdb->prefix . 'wc_orders_meta',
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

        $meta_keys = $this->getWatchedMetaKeys();
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
        $zerosense_keys = array_values(self::FIELD_MAPPING);
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $meta_placeholders = implode(',', array_fill(0, count($zerosense_keys), '%s'));

        // Count orders that have actual ZeroSense data (not just migration flags)
        $sql = "SELECT COUNT(DISTINCT pm.post_id)
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_order'
              AND p.post_status IN ({$status_placeholders})
              AND pm.meta_key IN ({$meta_placeholders})
              AND pm.meta_value != ''
              AND pm.meta_value IS NOT NULL";

        $params = array_merge($statuses, $zerosense_keys);
        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    private function getOrderStatuses(): array
    {
        global $wpdb;

        if ($this->isHposEnabled()) {
            $tables = $this->getHposTables();
            $sql = "SELECT DISTINCT status FROM {$tables['orders']} WHERE type = 'shop_order'";
            $statuses = $wpdb->get_col($sql);
        } else {
            $sql = "SELECT DISTINCT post_status FROM {$wpdb->posts} WHERE post_type = 'shop_order'";
            $statuses = $wpdb->get_col($sql);
        }

        $filtered = array_filter($statuses, function($status) {
            return !in_array($status, ['trash', 'auto-draft'], true);
        });
        
        return $filtered;
    }

    private function getPendingOrdersHpos(int $limit): array
    {
        global $wpdb;

        $tables = $this->getHposTables();
        $metabox_keys = $this->getWatchedMetaKeys();
        $statuses = $this->getOrderStatuses();

        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $metabox_placeholders = implode(',', array_fill(0, count($metabox_keys), '%s'));

        // Get all orders that have MetaBox data
        $sql = "SELECT DISTINCT o.id
            FROM {$tables['orders']} o
            INNER JOIN {$tables['meta']} m ON m.order_id = o.id
            WHERE o.type = 'shop_order'
              AND o.status IN ({$status_placeholders})
              AND m.meta_key IN ({$metabox_placeholders})
              AND m.meta_value != ''
              AND m.meta_value IS NOT NULL
            ORDER BY o.id DESC";

        $params = array_merge($statuses, $metabox_keys);
        $all_order_ids = $wpdb->get_col($wpdb->prepare($sql, $params));

        // Filter orders that actually need migration
        $pending_order_ids = [];
        $checked = 0;
        
        foreach ($all_order_ids as $order_id) {
            if ($limit > 0 && count($pending_order_ids) >= $limit) {
                break;
            }
            
            $order = wc_get_order($order_id);
            if (!$order instanceof WC_Order) {
                continue;
            }
            
            $needs_migration = $this->needsMigrationForOrder($order);
            
            if ($needs_migration) {
                $pending_order_ids[] = $order_id;
            }
            
            $checked++;
        }
        
        return $pending_order_ids;
    }

    private function getPendingOrdersLegacy(int $limit): array
    {
        global $wpdb;

        $meta_keys = $this->getWatchedMetaKeys();
        $statuses = $this->getOrderStatuses();

        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $meta_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));

        $sql = "SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.post_type = 'shop_order'
              AND p.post_status IN ({$status_placeholders})
              AND pm.meta_key IN ({$meta_placeholders})
              AND pm.meta_value != ''
              AND pm.meta_value IS NOT NULL
            ORDER BY p.ID DESC";

        $params = array_merge($statuses, $meta_keys);
        $all_order_ids = $wpdb->get_col($wpdb->prepare($sql, $params));

        $pending_order_ids = [];
        foreach ($all_order_ids as $order_id) {
            if ($limit > 0 && count($pending_order_ids) >= $limit) {
                break;
            }

            $order = wc_get_order($order_id);
            if (!$order instanceof WC_Order) {
                continue;
            }

            if ($this->needsMigrationForOrder($order)) {
                $pending_order_ids[] = (int) $order_id;
            }
        }

        return $pending_order_ids;
    }

    /**
     * Count orders that need migration using same logic as getPendingOrdersHpos
     */
    public function getPendingOrdersCount(): int
    {
        if ($this->isHposEnabled()) {
            return count($this->getPendingOrdersHpos(0)); // 0 = no limit
        } else {
            return count($this->getPendingOrdersLegacy(0));
        }
    }

    /**
     * Count orders that are fully migrated using opposite logic of getPendingOrdersCount
     */
    public function getMigratedOrdersCountCorrect(): int
    {
        if ($this->isHposEnabled()) {
            // Get all orders with MetaBox data and subtract pending ones
            $total_with_metabox = $this->getTotalOrdersWithMetaBoxHpos();
            $pending_count = $this->getPendingOrdersCount();
            return $total_with_metabox - $pending_count;
        } else {
            // For legacy mode, use same logic
            $total_with_metabox = $this->getTotalOrdersWithMetaBox();
            $pending_count = $this->getPendingOrdersCount();
            return $total_with_metabox - $pending_count;
        }
    }

    public function migrateAll(): array
    {
        Logger::migration('Starting bulk migration process');
        $start_time = microtime(true);
        
        $results = [
            'success' => 0,
            'errors' => 0,
            'skipped' => 0,
            'details' => [],
        ];

        if ($this->isHposEnabled()) {
            $order_ids = $this->getPendingOrdersHpos(0); // 0 = no limit
            Logger::migration('Using HPOS query, found ' . count($order_ids) . ' orders to migrate');
        } else {
            $order_ids = $this->getPendingOrdersLegacy(0);
            Logger::migration('Using legacy query, found ' . count($order_ids) . ' orders to migrate');
        }

        foreach ($order_ids as $order_id) {
            if ($order_id % 100 === 0) {
                Logger::migration("Processed {$order_id} orders...");
            }
            
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

        update_option('zs_metabox_migration_last_run', current_time('mysql'));
        update_option('zs_metabox_migration_version', ZERO_SENSE_VERSION);

        return $results;
    }

    /**
     * Reset migration status for all orders with MetaBox data
     * This forces all orders to be considered pending for migration again
     */
    public function resetMigrationStatus(): array
    {
        Logger::migration('Starting migration status reset');
        $start_time = microtime(true);
        
        $results = [
            'reset_count' => 0,
            'errors' => 0,
        ];

        if ($this->isHposEnabled()) {
            Logger::migration('Resetting HPOS migration status');
            global $wpdb;
            
            $tables = $this->getHposTables();
            $meta_keys = $this->getWatchedMetaKeys();
            $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
            
            // Delete migration flags from orders that have MetaBox data
            $sql = "DELETE m FROM {$tables['meta']} m
                INNER JOIN {$tables['orders']} o ON o.id = m.order_id
                WHERE o.type = 'shop_order'
                  AND m.meta_key = %s
                  AND o.id IN (
                      SELECT DISTINCT order_id FROM {$tables['meta']}
                      WHERE meta_key IN ({$placeholders})
                  )";
            
            $params = array_merge(['zs_metabox_migrated'], $meta_keys);
            $deleted = $wpdb->query($wpdb->prepare($sql, $params));
            
            $results['reset_count'] = $deleted ? $deleted : 0;
            error_log('[ZS Migration] Reset ' . $results['reset_count'] . ' migration flags');
        } else {
            error_log('[ZS Migration] Resetting legacy migration status');
            global $wpdb;
            
            $meta_keys = $this->getWatchedMetaKeys();
            $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
            
            // Delete migration flags from orders that have MetaBox data
            $sql = "DELETE pm FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type = 'shop_order'
                  AND pm.meta_key = %s
                  AND p.ID IN (
                      SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                      WHERE meta_key IN ({$placeholders})
                  )";
            
            $params = array_merge(['zs_metabox_migrated'], $meta_keys);
            $deleted = $wpdb->query($wpdb->prepare($sql, $params));
            
            $results['reset_count'] = $deleted ? $deleted : 0;
            error_log('[ZS Migration] Reset ' . $results['reset_count'] . ' migration flags');
        }

        error_log('[ZS Migration] Migration status reset completed in ' . (microtime(true) - $start_time) . 's');
        return $results;
    }

    public function migrateOrder(WC_Order $order): array
    {
        $order_id = $order->get_id();
        $migrated_fields = [];
        $errors = [];

        error_log('[ZS Migration] migrateOrder() called for order ' . $order_id);

        foreach (self::FIELD_MAPPING as $metabox_key => $zerosense_key) {
            // Read MetaBox data from the correct location (HPOS or legacy)
            if ($this->isHposEnabled()) {
                $metabox_value = $order->get_meta($metabox_key, true);
                error_log('[ZS Migration] HPOS read: ' . $metabox_key . ' = ' . var_export($metabox_value, true));
            } else {
                $metabox_value = get_post_meta($order_id, $metabox_key, true);
                error_log('[ZS Migration] Legacy read: ' . $metabox_key . ' = ' . var_export($metabox_value, true));
            }

            if ($metabox_value === '' || $metabox_value === null) {
                $legacyKey = self::LEGACY_EVENT_MAPPING[$metabox_key] ?? null;
                if (is_string($legacyKey) && $legacyKey !== '') {
                    $legacyValue = $order->get_meta($legacyKey, true);
                    if ($legacyValue !== '' && $legacyValue !== null) {
                        $metabox_value = $legacyValue;
                    }
                }
            }

            if ($metabox_value !== '' && $metabox_value !== null) {
                $setter = self::NATIVE_SHIPPING_SETTERS[$zerosense_key] ?? null;
                $existing_value = $setter !== null && is_callable([$order, str_replace('set_', 'get_', $setter)])
                    ? $order->{str_replace('set_', 'get_', $setter)}('edit')
                    : $order->get_meta($zerosense_key, true);

                $target_value = $metabox_value;
                if ($metabox_key === self::EVENT_DATE_META_BOX_KEY && $zerosense_key === self::EVENT_DATE_ZEROSENSE_KEY) {
                    $normalized = $this->normalizeEventDateToIso($metabox_value);
                    if ($normalized !== '') {
                        $target_value = $normalized;
                    }
                }
                
                // Apply value mapping for event_type and how_found_us
                if ($metabox_key === 'event_type') {
                    $target_value = self::EVENT_TYPE_VALUE_MAPPING[$metabox_value] ?? $metabox_value;
                }
                if ($metabox_key === 'how_found_us') {
                    $target_value = self::HOW_FOUND_US_VALUE_MAPPING[$metabox_value] ?? $metabox_value;
                }

                $existing_scalar = is_scalar($existing_value) ? (string) $existing_value : '';
                $target_scalar = is_scalar($target_value) ? (string) $target_value : '';

                // Force migration if values are different or if target field is empty
                if ($existing_scalar === '' || $existing_scalar !== $target_scalar) {
                    if ($setter !== null) {
                        $order->{$setter}((string) $target_value);
                    } else {
                        $order->update_meta_data($zerosense_key, $target_value);
                    }
                    $migrated_fields[] = [
                        'field' => $zerosense_key,
                        'from' => $metabox_key,
                        'value' => $target_value,
                        'old_value' => $existing_value,
                    ];
                    error_log('[ZS Migration] Migrated field: ' . $metabox_key . ' -> ' . $zerosense_key . ' (was: ' . var_export($existing_value, true) . ')');
                } else {
                    error_log('[ZS Migration] Field ' . $zerosense_key . ' already has same value, skipping');
                }
            } else {
                error_log('[ZS Migration] Field ' . $metabox_key . ' is empty, skipping');
            }
        }

        $existing_event_date = $order->get_meta(self::EVENT_DATE_ZEROSENSE_KEY, true);
        $normalized_date = $this->normalizeEventDateToIso($existing_event_date);
        if ($normalized_date !== '' && $normalized_date !== $existing_event_date) {
            $order->update_meta_data(self::EVENT_DATE_ZEROSENSE_KEY, $normalized_date);
            $migrated_fields[] = [
                'field' => self::EVENT_DATE_ZEROSENSE_KEY,
                'from' => self::EVENT_DATE_ZEROSENSE_KEY,
                'value' => $normalized_date,
                'old_value' => $existing_event_date,
            ];
        }

        foreach (array_values(self::LEGACY_EVENT_MAPPING) as $legacyKey) {
            $order->delete_meta_data($legacyKey);
        }

        $material_migrated = $this->migrateOpsMaterial($order);
        if ($material_migrated !== null) {
            $migrated_fields[] = $material_migrated;
        }

        // Only mark as migrated if we actually migrated data
        if (!empty($migrated_fields)) {
            $order->update_meta_data('zs_metabox_migrated', true);
            $order->update_meta_data('zs_metabox_migration_date', current_time('mysql'));
            $order->update_meta_data('zs_metabox_migration_version', ZERO_SENSE_VERSION);
            
            error_log('[ZS Migration] Order ' . $order_id . ' marked as migrated with ' . count($migrated_fields) . ' fields');
        } else {
            // Mark as skipped if no data was found to migrate
            $order->update_meta_data('zs_metabox_migrated', false);
            $order->update_meta_data('zs_metabox_migration_status', 'no_data_found');
            
            error_log('[ZS Migration] Order ' . $order_id . ' has no MetaBox data to migrate, marking as skipped');
        }

        $order->update_meta_data('zs_metabox_migration_log', [
            'migrated_fields' => $migrated_fields,
            'migration_date' => current_time('mysql'),
            'migration_version' => ZERO_SENSE_VERSION,
        ]);

        $order->save();

        return [
            'success' => !empty($migrated_fields), // Success only if we actually migrated something
            'order_id' => $order_id,
            'migrated_fields' => $migrated_fields,
            'errors' => $errors,
        ];
    }

    private function migrateOpsMaterial(WC_Order $order): ?array
    {
        $raw = $order->get_meta(self::META_OPS_MATERIAL, true);
        if (!is_array($raw)) {
            return null;
        }

        $normalized = $raw;
        $changed = false;


        foreach ($normalized as $k => $v) {
            if (is_array($v)) {
                continue;
            }
            if (is_scalar($v)) {
                $normalized[$k] = ['value' => (string) $v];
                $changed = true;
            }
        }

        if (!$changed) {
            return null;
        }

        $order->update_meta_data(self::META_OPS_MATERIAL, $normalized);

        return [
            'field' => self::META_OPS_MATERIAL,
            'from' => self::META_OPS_MATERIAL,
            'value' => $normalized,
            'old_value' => $raw,
        ];
    }

    private function needsMigrationForOrder(WC_Order $order): bool
    {
        foreach (self::FIELD_MAPPING as $metabox_key => $zerosense_key) {
            $metabox_value = $order->get_meta($metabox_key, true);
            $setter = self::NATIVE_SHIPPING_SETTERS[$zerosense_key] ?? null;
            $zerosense_value = $setter !== null && is_callable([$order, str_replace('set_', 'get_', $setter)])
                ? $order->{str_replace('set_', 'get_', $setter)}('edit')
                : $order->get_meta($zerosense_key, true);

            if ($metabox_key === self::EVENT_DATE_META_BOX_KEY && $zerosense_key === self::EVENT_DATE_ZEROSENSE_KEY) {
                $normalized = $this->normalizeEventDateToIso($metabox_value);
                if ($normalized !== '') {
                    $metabox_value = $normalized;
                }
            }
            
            // Apply value mapping for event_type and how_found_us in comparison
            $expected_value = $metabox_value;
            if ($metabox_key === 'event_type') {
                $expected_value = self::EVENT_TYPE_VALUE_MAPPING[$metabox_value] ?? $metabox_value;
            }
            if ($metabox_key === 'how_found_us') {
                $expected_value = self::HOW_FOUND_US_VALUE_MAPPING[$metabox_value] ?? $metabox_value;
            }

            if (($metabox_value !== '' && $metabox_value !== null) &&
                ($zerosense_value === '' || $zerosense_value === null || $zerosense_value !== $expected_value)) {
                return true;
            }
        }

        $material = $order->get_meta(self::META_OPS_MATERIAL, true);
        if (is_array($material)) {

            foreach ($material as $v) {
                if (!is_array($v) && is_scalar($v)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeEventDateToIso($value): string
    {
        if (empty($value)) {
            return '';
        }

        // Already YYYY-MM-DD
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        // Numeric timestamp → YYYY-MM-DD
        if (is_numeric($value) && (int) $value > 0) {
            return date('Y-m-d', (int) $value);
        }

        // Any other string → try to parse
        if (is_string($value)) {
            $ts = strtotime(trim($value));
            return $ts ? date('Y-m-d', $ts) : '';
        }

        return '';
    }

    private function getWatchedMetaKeys(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(self::FIELD_MAPPING),
            array_values(self::FIELD_MAPPING),
            array_values(self::LEGACY_EVENT_MAPPING),
            [self::META_SHIPPING_EMAIL, self::META_OPS_MATERIAL]
        )));
    }

    public function getSampleOrders(int $limit = 5): array
    {
        if ($this->isHposEnabled()) {
            $orders = $this->getSampleOrdersHpos($limit);
            if (!empty($orders)) {
                return $orders;
            }
        }

        // For legacy mode, get all orders with MetaBox data and filter in PHP
        global $wpdb;
        
        $meta_keys = $this->getWatchedMetaKeys();
        $statuses = $this->getOrderStatuses();
        
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $meta_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
        
        // Get all orders with MetaBox data
        $sql = "SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.post_type = 'shop_order'
              AND p.post_status IN ({$status_placeholders})
              AND pm.meta_key IN ({$meta_placeholders})
              AND pm.meta_value != ''
              AND pm.meta_value IS NOT NULL
            ORDER BY p.ID DESC";
            
        $params = array_merge($statuses, $meta_keys);
        $all_order_ids = $wpdb->get_col($wpdb->prepare($sql, $params));
        
        error_log('[ZS Migration] Legacy getSampleOrders - Found ' . count($all_order_ids) . ' orders with MetaBox data');
        
        $pending_order_ids = [];
        $checked = 0;

        foreach ($all_order_ids as $order_id) {
            if ($limit > 0 && count($pending_order_ids) >= $limit) {
                break;
            }

            $order = wc_get_order($order_id);
            if (!$order instanceof WC_Order) {
                continue;
            }

            if ($this->needsMigrationForOrder($order)) {
                $pending_order_ids[] = $order_id;
            }

            $checked++;
        }
        
        error_log('[ZS Migration] Legacy getSampleOrders - Filtered to ' . count($pending_order_ids) . ' pending orders (checked ' . $checked . ' orders)');
        
        $order_ids = $pending_order_ids;

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
                    $setter = self::NATIVE_SHIPPING_SETTERS[$zerosense_key] ?? null;
                    $value = $setter !== null && is_callable([$order, str_replace('set_', 'get_', $setter)])
                        ? $order->{str_replace('set_', 'get_', $setter)}('edit')
                        : $order->get_meta($zerosense_key, true);
                    if ($value !== '' && $value !== null) {
                        $sample_data['zerosense_fields'][$zerosense_key] = $value;
                    }
                }

                $shipping_email = $order->get_meta(self::META_SHIPPING_EMAIL, true);
                if ($shipping_email !== '' && $shipping_email !== null) {
                    $sample_data['zerosense_fields'][self::META_SHIPPING_EMAIL] = $shipping_email;
                }

                $ops_material = $order->get_meta(self::META_OPS_MATERIAL, true);
                if ($ops_material !== '' && $ops_material !== null) {
                    $sample_data['zerosense_fields'][self::META_OPS_MATERIAL] = $ops_material;
                }

                $orders[] = $sample_data;
            }
        }

        return $orders;
    }

    public function getMigratedOrders(int $limit = 20): array
    {
        $args = [
            'limit'      => $limit,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'meta_key'   => 'zs_metabox_migrated',
            'meta_value' => '1',
        ];
        $wc_orders = wc_get_orders($args);
        $result = [];

        foreach ($wc_orders as $order) {
            $row = [
                'order_id'     => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'status'       => $order->get_status(),
                'fields'       => [],
            ];
            foreach (self::FIELD_MAPPING as $mb_key => $zs_key) {
                $setter = self::NATIVE_SHIPPING_SETTERS[$zs_key] ?? null;
                $val = $setter !== null && is_callable([$order, str_replace('set_', 'get_', $setter)])
                    ? $order->{str_replace('set_', 'get_', $setter)}('edit')
                    : $order->get_meta($zs_key, true);
                if ($val !== '' && $val !== null) {
                    $row['fields'][$zs_key] = is_scalar($val) ? (string) $val : wp_json_encode($val);
                }
            }
            $result[] = $row;
        }

        return $result;
    }

    public function rollbackOrder(WC_Order $order): array
    {
        $order_id = $order->get_id();
        $rollback_fields = [];

        $migration_log = $order->get_meta('zs_metabox_migration_log', true);

        if (is_array($migration_log) && isset($migration_log['migrated_fields'])) {
            foreach ($migration_log['migrated_fields'] as $field_info) {
                $zerosense_key = $field_info['field'];
                $setter = self::NATIVE_SHIPPING_SETTERS[$zerosense_key] ?? null;
                if ($setter !== null) {
                    $order->{$setter}('');
                } else {
                    $order->delete_meta_data($zerosense_key);
                }
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
