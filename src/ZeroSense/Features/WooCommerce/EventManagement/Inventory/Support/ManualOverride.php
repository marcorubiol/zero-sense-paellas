<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support;

use WC_Order;

class ManualOverride
{
    const META_KEY = 'zs_equipment_manual_overrides';
    
    /**
     * Guarda overrides manuales para un pedido
     * 
     * @param int $orderId
     * @param array $overrides ['material_key' => value]
     */
    public static function save(int $orderId, array $overrides): void
    {
        // Filtrar solo valores válidos
        // CRÍTICO: '0' es un valor válido (diferente de vacío)
        $filtered = array_filter($overrides, function($value) {
            // Solo filtrar vacíos o nulos. El '0' se queda.
            return $value !== '' && $value !== null;
        });
        
        if (empty($filtered)) {
            delete_post_meta($orderId, self::META_KEY);
            return;
        }
        
        update_post_meta($orderId, self::META_KEY, $filtered);
    }
    
    /**
     * Obtiene overrides manuales de un pedido
     * 
     * @param int $orderId
     * @return array
     */
    public static function get(int $orderId): array
    {
        $overrides = get_post_meta($orderId, self::META_KEY, true);
        
        return is_array($overrides) ? $overrides : [];
    }
    
    /**
     * Aplica overrides manuales a cantidades calculadas
     * 
     * @param array $calculated ['material_key' => quantity]
     * @param array $overrides ['material_key' => quantity]
     * @return array
     */
    public static function apply(array $calculated, array $overrides): array
    {
        $result = $calculated;
        
        foreach ($overrides as $materialKey => $override) {
            // CRÍTICO: Permitir 0 estrictamente
            $hasOverride = $override !== null && $override !== '';
            
            if ($hasOverride) {
                $result[$materialKey] = (int) $override;
            }
        }
        
        return $result;
    }
    
    const CASCADE_META_KEY = 'zs_equipment_cascade_overrides';

    /**
     * Guarda overrides de cascada (calculados automáticamente por JS, no por el usuario)
     */
    public static function saveCascade(int $orderId, array $overrides): void
    {
        $filtered = array_filter($overrides, function($value) {
            return $value !== '' && $value !== null;
        });

        if (empty($filtered)) {
            delete_post_meta($orderId, self::CASCADE_META_KEY);
            return;
        }

        update_post_meta($orderId, self::CASCADE_META_KEY, $filtered);
    }

    /**
     * Obtiene overrides de cascada de un pedido
     */
    public static function getCascade(int $orderId): array
    {
        $overrides = get_post_meta($orderId, self::CASCADE_META_KEY, true);
        return is_array($overrides) ? $overrides : [];
    }

    /**
     * Elimina un override específico
     * 
     * @param int $orderId
     * @param string $materialKey
     */
    public static function remove(int $orderId, string $materialKey): void
    {
        $overrides = self::get($orderId);
        
        if (isset($overrides[$materialKey])) {
            unset($overrides[$materialKey]);
            self::save($orderId, $overrides);
        }
    }
    
    /**
     * Elimina todos los overrides de un pedido
     * 
     * @param int $orderId
     */
    public static function removeAll(int $orderId): void
    {
        delete_post_meta($orderId, self::META_KEY);
    }
}
