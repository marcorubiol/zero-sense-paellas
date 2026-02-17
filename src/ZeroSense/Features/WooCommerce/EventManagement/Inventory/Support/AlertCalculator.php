<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support;

use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Database\Schema;

class AlertCalculator
{
    const ALERT_CRITICAL = 'critical';
    const ALERT_MAX_CAPACITY = 'max_capacity';
    const ALERT_LOW_STOCK = 'low_stock';
    
    /**
     * Calcula alertas de stock para un pedido
     * 
     * @param WC_Order $order
     * @param array $materials ['material_key' => quantity]
     * @return array
     */
    public static function calculateAlerts(WC_Order $order, array $materials): array
    {
        $orderId = $order->get_id();
        $eventDate = $order->get_meta(MetaKeys::EVENT_DATE, true);
        $serviceAreaId = (int) $order->get_meta(MetaKeys::SERVICE_LOCATION, true);
        
        // Skip si no hay fecha o ubicación
        if (!$eventDate || !$serviceAreaId) {
            return [];
        }
        
        $alerts = [];
        
        foreach ($materials as $materialKey => $quantity) {
            if ($quantity <= 0) {
                continue;
            }
            
            $alert = self::calculateMaterialAlert(
                $orderId,
                $serviceAreaId,
                $materialKey,
                $quantity,
                $eventDate
            );
            
            if ($alert) {
                $alerts[$materialKey] = $alert;
            }
        }
        
        return $alerts;
    }
    
    /**
     * Calcula alerta para un material específico
     * 
     * @param int $orderId
     * @param int $serviceAreaId
     * @param string $materialKey
     * @param int $neededQuantity
     * @param string $eventDate
     * @return array|null
     */
    private static function calculateMaterialAlert(
        int $orderId,
        int $serviceAreaId,
        string $materialKey,
        int $neededQuantity,
        string $eventDate
    ): ?array {
        // Stock total disponible en la ubicación
        $totalStock = StockManager::getStock($serviceAreaId, $materialKey);
        
        // Stock reservado por otros eventos ese día (excluyendo este pedido)
        $reservedByOthers = self::getReservedByOthers($orderId, $serviceAreaId, $materialKey, $eventDate);
        
        // Stock disponible después de otros eventos
        $availableStock = max(0, $totalStock - $reservedByOthers);
        
        // Stock total necesario ese día (incluyendo este pedido)
        $totalNeeded = $reservedByOthers + $neededQuantity;
        
        // Determinar tipo de alerta
        $alertType = null;
        
        if ($totalNeeded > $totalStock) {
            // CRITICAL: No hay suficiente stock
            $alertType = self::ALERT_CRITICAL;
        } elseif ($totalNeeded == $totalStock) {
            // MAX CAPACITY: Stock máximo usado (100%)
            $alertType = self::ALERT_MAX_CAPACITY;
        } elseif ($totalNeeded > ($totalStock * 0.8)) {
            // LOW STOCK: Más del 80% usado (menos del 120% disponible)
            $alertType = self::ALERT_LOW_STOCK;
        }
        
        // Si no hay alerta, retornar null
        if (!$alertType) {
            return null;
        }
        
        // Obtener eventos conflictivos
        $conflicts = self::getConflictingOrders($orderId, $serviceAreaId, $materialKey, $eventDate);
        
        return [
            'material_key' => $materialKey,
            'alert_type' => $alertType,
            'needed' => $neededQuantity,
            'total_stock' => $totalStock,
            'available_stock' => $availableStock,
            'reserved_by_others' => $reservedByOthers,
            'total_needed' => $totalNeeded,
            'shortage' => max(0, $totalNeeded - $totalStock),
            'usage_percent' => $totalStock > 0 ? round(($totalNeeded / $totalStock) * 100) : 0,
            'conflicts' => $conflicts,
        ];
    }
    
    /**
     * Obtiene stock reservado por otros pedidos (excluyendo el actual)
     * 
     * @param int $excludeOrderId
     * @param int $serviceAreaId
     * @param string $materialKey
     * @param string $eventDate
     * @return int
     */
    private static function getReservedByOthers(
        int $excludeOrderId,
        int $serviceAreaId,
        string $materialKey,
        string $eventDate
    ): int {
        global $wpdb;
        
        $table = Schema::getReservationsTableName();
        
        $quantity = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(quantity) FROM {$table} 
            WHERE service_area_id = %d 
            AND material_key = %s 
            AND event_date = %s
            AND order_id != %d",
            $serviceAreaId,
            $materialKey,
            $eventDate,
            $excludeOrderId
        ));
        
        return $quantity !== null ? (int) $quantity : 0;
    }
    
    /**
     * Obtiene pedidos que tienen conflicto con el material
     * 
     * @param int $excludeOrderId
     * @param int $serviceAreaId
     * @param string $materialKey
     * @param string $eventDate
     * @return array
     */
    private static function getConflictingOrders(
        int $excludeOrderId,
        int $serviceAreaId,
        string $materialKey,
        string $eventDate
    ): array {
        global $wpdb;
        
        $table = Schema::getReservationsTableName();
        
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT order_id, quantity FROM {$table} 
            WHERE service_area_id = %d 
            AND material_key = %s 
            AND event_date = %s
            AND order_id != %d
            ORDER BY quantity DESC",
            $serviceAreaId,
            $materialKey,
            $eventDate,
            $excludeOrderId
        ), ARRAY_A);
        
        $conflicts = [];
        
        foreach ($rows as $row) {
            $conflicts[] = [
                'order_id' => (int) $row['order_id'],
                'quantity' => (int) $row['quantity'],
            ];
        }
        
        return $conflicts;
    }
    
    /**
     * Obtiene el label del tipo de alerta
     * 
     * @param string $alertType
     * @return string
     */
    public static function getAlertLabel(string $alertType): string
    {
        switch ($alertType) {
            case self::ALERT_CRITICAL:
                return __('Critical', 'zero-sense');
            case self::ALERT_MAX_CAPACITY:
                return __('Max Capacity', 'zero-sense');
            case self::ALERT_LOW_STOCK:
                return __('Low Stock', 'zero-sense');
            default:
                return '';
        }
    }
    
    /**
     * Obtiene el color del tipo de alerta
     * 
     * @param string $alertType
     * @return string
     */
    public static function getAlertColor(string $alertType): string
    {
        switch ($alertType) {
            case self::ALERT_CRITICAL:
                return '#dc3545';
            case self::ALERT_MAX_CAPACITY:
                return '#fd7e14';
            case self::ALERT_LOW_STOCK:
                return '#ffc107';
            default:
                return '#6c757d';
        }
    }
    
    /**
     * Obtiene el emoji del tipo de alerta
     * 
     * @param string $alertType
     * @return string
     */
    public static function getAlertEmoji(string $alertType): string
    {
        switch ($alertType) {
            case self::ALERT_CRITICAL:
                return '🔴';
            case self::ALERT_MAX_CAPACITY:
                return '🟠';
            case self::ALERT_LOW_STOCK:
                return '🟡';
            default:
                return '⚪';
        }
    }
}
