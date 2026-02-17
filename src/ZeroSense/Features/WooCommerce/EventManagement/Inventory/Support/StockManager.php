<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support;

use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Database\Schema;

class StockManager
{
    /**
     * Obtiene stock total de un material en un área de servicio
     * 
     * @param int $serviceAreaId
     * @param string $materialKey
     * @return int
     */
    public static function getStock(int $serviceAreaId, string $materialKey): int
    {
        global $wpdb;
        
        $table = Schema::getStockTableName();
        
        $quantity = $wpdb->get_var($wpdb->prepare(
            "SELECT quantity FROM {$table} WHERE service_area_id = %d AND material_key = %s",
            $serviceAreaId,
            $materialKey
        ));
        
        return $quantity !== null ? (int) $quantity : 0;
    }
    
    /**
     * Actualiza stock de un material
     * 
     * @param int $serviceAreaId
     * @param string $materialKey
     * @param int $quantity
     */
    public static function updateStock(int $serviceAreaId, string $materialKey, int $quantity): void
    {
        global $wpdb;
        
        $table = Schema::getStockTableName();
        
        $wpdb->replace(
            $table,
            [
                'service_area_id' => $serviceAreaId,
                'material_key' => $materialKey,
                'quantity' => $quantity,
                'updated_at' => current_time('mysql'),
                'updated_by' => get_current_user_id(),
            ],
            ['%d', '%s', '%d', '%s', '%d']
        );
    }
    
    /**
     * Actualiza múltiples stocks (para AJAX batch update)
     * 
     * @param array $changes ['material_key|service_area_id' => quantity]
     */
    public static function updateMultiple(array $changes): void
    {
        foreach ($changes as $key => $quantity) {
            // Parse key: "material_key|service_area_id"
            $parts = explode('|', $key);
            
            if (count($parts) !== 2) {
                continue;
            }
            
            list($materialKey, $serviceAreaId) = $parts;
            
            self::updateStock((int) $serviceAreaId, $materialKey, (int) $quantity);
        }
    }
    
    /**
     * Obtiene todo el stock de un área de servicio
     * 
     * @param int $serviceAreaId
     * @return array ['material_key' => quantity]
     */
    public static function getAllStock(int $serviceAreaId): array
    {
        global $wpdb;
        
        $table = Schema::getStockTableName();
        
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT material_key, quantity FROM {$table} WHERE service_area_id = %d",
            $serviceAreaId
        ), ARRAY_A);
        
        $result = [];
        
        foreach ($rows as $row) {
            $result[$row['material_key']] = (int) $row['quantity'];
        }
        
        return $result;
    }
    
    /**
     * Obtiene stock de todos los materiales en todas las áreas
     * 
     * @return array ['material_key|service_area_id' => quantity]
     */
    public static function getAllStockMatrix(): array
    {
        global $wpdb;
        
        $table = Schema::getStockTableName();
        
        $rows = $wpdb->get_results(
            "SELECT service_area_id, material_key, quantity FROM {$table}",
            ARRAY_A
        );
        
        $result = [];
        
        foreach ($rows as $row) {
            $key = $row['material_key'] . '|' . $row['service_area_id'];
            $result[$key] = (int) $row['quantity'];
        }
        
        return $result;
    }
    
    /**
     * Calcula stock reservado de un material en un área para una fecha
     * 
     * @param int $serviceAreaId
     * @param string $materialKey
     * @param string $date YYYY-MM-DD
     * @return int
     */
    public static function getReservedStock(int $serviceAreaId, string $materialKey, string $date): int
    {
        global $wpdb;
        
        $table = Schema::getReservationsTableName();
        
        $quantity = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(quantity) FROM {$table} 
            WHERE service_area_id = %d 
            AND material_key = %s 
            AND event_date = %s",
            $serviceAreaId,
            $materialKey,
            $date
        ));
        
        return $quantity !== null ? (int) $quantity : 0;
    }
    
    /**
     * Calcula stock disponible para una fecha
     * 
     * @param int $serviceAreaId
     * @param string $materialKey
     * @param string $date YYYY-MM-DD
     * @return int
     */
    public static function getAvailableStock(int $serviceAreaId, string $materialKey, string $date): int
    {
        $total = self::getStock($serviceAreaId, $materialKey);
        $reserved = self::getReservedStock($serviceAreaId, $materialKey, $date);
        
        return max(0, $total - $reserved);
    }
}
