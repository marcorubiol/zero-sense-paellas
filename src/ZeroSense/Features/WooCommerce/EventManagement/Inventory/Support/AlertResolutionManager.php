<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support;

class AlertResolutionManager
{
    const META_KEY = 'zs_equipment_alert_resolutions';
    
    /**
     * Guarda una resolución de alerta
     * 
     * @param int $orderId
     * @param string $materialKey
     * @param string $notes
     * @return void
     */
    public static function saveResolution(int $orderId, string $materialKey, string $notes = ''): void
    {
        $resolutions = self::getResolutions($orderId);
        
        $resolutions[$materialKey] = [
            'resolved' => true,
            'resolved_by' => get_current_user_id(),
            'resolved_at' => current_time('mysql'),
            'notes' => sanitize_text_field($notes),
        ];
        
        update_post_meta($orderId, self::META_KEY, $resolutions);

        delete_transient('zs_active_equipment_alerts');

        // FlowMattic trigger
        do_action('zs_equipment_alert_resolved', [
            'order_id' => $orderId,
            'material_key' => $materialKey,
            'notes' => $notes,
            'resolved_by' => get_current_user_id(),
            'resolved_at' => current_time('mysql'),
        ]);
    }
    
    /**
     * Obtiene todas las resoluciones de un pedido
     * 
     * @param int $orderId
     * @return array
     */
    public static function getResolutions(int $orderId): array
    {
        $resolutions = get_post_meta($orderId, self::META_KEY, true);
        
        return is_array($resolutions) ? $resolutions : [];
    }
    
    /**
     * Obtiene una resolución específica
     * 
     * @param int $orderId
     * @param string $materialKey
     * @return array|null
     */
    public static function getResolution(int $orderId, string $materialKey): ?array
    {
        $resolutions = self::getResolutions($orderId);
        
        return $resolutions[$materialKey] ?? null;
    }
    
    /**
     * Verifica si un material tiene resolución
     * 
     * @param int $orderId
     * @param string $materialKey
     * @return bool
     */
    public static function isResolved(int $orderId, string $materialKey): bool
    {
        $resolution = self::getResolution($orderId, $materialKey);
        
        return $resolution && ($resolution['resolved'] ?? false);
    }
    
    /**
     * Elimina una resolución específica
     * 
     * @param int $orderId
     * @param string $materialKey
     * @return void
     */
    public static function removeResolution(int $orderId, string $materialKey): void
    {
        $resolutions = self::getResolutions($orderId);
        
        if (isset($resolutions[$materialKey])) {
            unset($resolutions[$materialKey]);
            
            if (empty($resolutions)) {
                delete_post_meta($orderId, self::META_KEY);
            } else {
                update_post_meta($orderId, self::META_KEY, $resolutions);
            }

            delete_transient('zs_active_equipment_alerts');
        }
    }
    
    /**
     * Elimina todas las resoluciones de un pedido
     * 
     * @param int $orderId
     * @return void
     */
    public static function removeAll(int $orderId): void
    {
        delete_post_meta($orderId, self::META_KEY);
    }
}
