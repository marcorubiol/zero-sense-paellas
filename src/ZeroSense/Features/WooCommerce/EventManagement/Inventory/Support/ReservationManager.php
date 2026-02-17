<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support;

use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Database\Schema;

class ReservationManager
{
    /**
     * Crea o actualiza reservas para un pedido
     * 
     * @param int $orderId
     * @param array $materials ['material_key' => quantity]
     */
    public static function createOrUpdate(int $orderId, array $materials): void
    {
        $order = wc_get_order($orderId);
        
        if (!$order) {
            return;
        }
        
        // Obtener datos del pedido
        $eventDate = $order->get_meta('zs_event_date', true);
        $serviceAreaId = (int) $order->get_meta('zs_event_service_location', true);
        
        if (!$eventDate || !$serviceAreaId) {
            return;
        }
        
        global $wpdb;
        $table = Schema::getReservationsTableName();
        
        foreach ($materials as $materialKey => $quantity) {
            if ($quantity <= 0) {
                // Si cantidad es 0, eliminar reserva
                self::delete($orderId, $materialKey);
                continue;
            }
            
            $wpdb->replace(
                $table,
                [
                    'order_id' => $orderId,
                    'service_area_id' => $serviceAreaId,
                    'material_key' => $materialKey,
                    'quantity' => $quantity,
                    'event_date' => $eventDate,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ],
                ['%d', '%d', '%s', '%d', '%s', '%s', '%s']
            );
        }
    }
    
    /**
     * Elimina una reserva específica
     * 
     * @param int $orderId
     * @param string $materialKey
     */
    public static function delete(int $orderId, string $materialKey): void
    {
        global $wpdb;
        
        $table = Schema::getReservationsTableName();
        
        $wpdb->delete(
            $table,
            [
                'order_id' => $orderId,
                'material_key' => $materialKey,
            ],
            ['%d', '%s']
        );
    }
    
    /**
     * Elimina todas las reservas de un pedido
     * 
     * @param int $orderId
     */
    public static function deleteAll(int $orderId): void
    {
        global $wpdb;
        
        $table = Schema::getReservationsTableName();
        
        $wpdb->delete(
            $table,
            ['order_id' => $orderId],
            ['%d']
        );
    }
    
    /**
     * Obtiene reservas de un pedido
     * 
     * @param int $orderId
     * @return array ['material_key' => quantity]
     */
    public static function get(int $orderId): array
    {
        global $wpdb;
        
        $table = Schema::getReservationsTableName();
        
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT material_key, quantity FROM {$table} WHERE order_id = %d",
            $orderId
        ), ARRAY_A);
        
        $result = [];
        
        foreach ($rows as $row) {
            $result[$row['material_key']] = (int) $row['quantity'];
        }
        
        return $result;
    }
}
