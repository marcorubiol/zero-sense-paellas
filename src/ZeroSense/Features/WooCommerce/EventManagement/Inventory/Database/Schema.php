<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Database;

class Schema
{
    /**
     * Get table name for inventory stock
     */
    public static function getStockTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'zs_inventory_stock';
    }

    /**
     * Get table name for inventory reservations
     */
    public static function getReservationsTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'zs_inventory_reservations';
    }

    /**
     * Create database tables
     */
    public static function createTables(): void
    {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Stock table
        $stockTable = self::getStockTableName();
        $stockSql = "CREATE TABLE IF NOT EXISTS {$stockTable} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            service_area_id bigint(20) UNSIGNED NOT NULL,
            material_key varchar(100) NOT NULL,
            quantity int(11) NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            updated_by bigint(20) UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY service_material (service_area_id, material_key),
            KEY service_area_id (service_area_id),
            KEY material_key (material_key),
            KEY updated_by (updated_by)
        ) {$charset_collate};";
        
        // Reservations table
        $reservationsTable = self::getReservationsTableName();
        $reservationsSql = "CREATE TABLE IF NOT EXISTS {$reservationsTable} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id bigint(20) UNSIGNED NOT NULL,
            service_area_id bigint(20) UNSIGNED NOT NULL,
            material_key varchar(100) NOT NULL,
            quantity int(11) NOT NULL DEFAULT 0,
            event_date date NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY order_material (order_id, material_key),
            KEY order_id (order_id),
            KEY service_area_id (service_area_id),
            KEY material_key (material_key),
            KEY event_date (event_date)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($stockSql);
        dbDelta($reservationsSql);
    }

    /**
     * Drop database tables (for uninstall)
     */
    public static function dropTables(): void
    {
        global $wpdb;
        
        $stockTable = self::getStockTableName();
        $reservationsTable = self::getReservationsTableName();
        
        $wpdb->query("DROP TABLE IF EXISTS {$reservationsTable}");
        $wpdb->query("DROP TABLE IF EXISTS {$stockTable}");
    }
}
