<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support;

use WC_Order;

class MaterialCalculator
{
    /**
     * Calcula materiales necesarios para un pedido
     * 
     * @param WC_Order $order
     * @return array ['material_key' => quantity]
     */
    public static function calculate(WC_Order $order): array
    {
        $result = [];
        
        // Obtener datos del pedido
        $totalGuests = (int) $order->get_meta('zs_event_total_guests', true);
        
        if ($totalGuests <= 0) {
            return $result;
        }
        
        // Analizar productos del pedido
        $analysis = ProductMapper::analyzeOrder($order);
        
        // Calcular paellas y cremadores
        if ($analysis['paella_count'] > 0) {
            $paellaCalc = self::calculatePaellas($totalGuests, $analysis['paella_count']);
            $result = array_merge($result, $paellaCalc);
        }
        
        // Calcular mobiliario
        $furnitureCalc = self::calculateFurniture($totalGuests, $analysis['has_entrants']);
        $result = array_merge($result, $furnitureCalc);
        
        // Calcular barra
        if ($analysis['has_barra_lliure']) {
            $barraCalc = self::calculateBarra($totalGuests);
            $result = array_merge($result, $barraCalc);
        }
        
        // Calcular personal
        $staffCalc = self::calculateStaff($totalGuests, $analysis);
        $result = array_merge($result, $staffCalc);
        
        // TESTING: Forzar siempre 1 butano para pruebas
        $result['buta'] = 1;
        
        return $result;
    }
    
    /**
     * Calcula paellas y cremadores necesarios
     * 
     * @param int $totalGuests
     * @param int $paellaCount
     * @return array
     */
    private static function calculatePaellas(int $totalGuests, int $paellaCount): array
    {
        $result = [];
        
        // Regla: 1 paella 90cm por cada 15 personas
        // Regla: 1 paella 100cm por cada 20 personas
        // Regla: 1 paella 110cm por cada 25 personas
        
        // Estrategia simple: usar paellas 100cm como base
        $paellas100cm = (int) ceil($totalGuests / 20);
        
        $result['paella_100cm'] = $paellas100cm;
        $result['cremador_100cm'] = $paellas100cm; // 1 cremador por paella
        
        // Bombonas: 1 por cada 2 cremadores
        $result['bombona_gas'] = (int) ceil($paellas100cm / 2);
        
        return $result;
    }
    
    /**
     * Calcula mobiliario necesario
     * 
     * @param int $totalGuests
     * @param bool $hasEntrants
     * @return array
     */
    private static function calculateFurniture(int $totalGuests, bool $hasEntrants): array
    {
        $result = [];
        
        // Regla: 1 mesa rectangular por cada 8 personas
        $result['mesa_rectangular'] = (int) ceil($totalGuests / 8);
        
        // Regla: 1 silla por persona
        $result['silla_plegable'] = $totalGuests;
        
        // Regla: 1 mantel por mesa
        $result['mantel_blanco'] = $result['mesa_rectangular'];
        
        return $result;
    }
    
    /**
     * Calcula materiales de barra
     * 
     * @param int $totalGuests
     * @return array
     */
    private static function calculateBarra(int $totalGuests): array
    {
        $result = [];
        
        // Regla: 1 barra por cada 50 personas
        $result['barra_bar'] = (int) ceil($totalGuests / 50);
        
        // Regla: 1 nevera por cada 30 personas
        $result['nevera_portatil'] = (int) ceil($totalGuests / 30);
        
        return $result;
    }
    
    /**
     * Calcula personal necesario
     * 
     * @param int $totalGuests
     * @param array $analysis
     * @return array
     */
    private static function calculateStaff(int $totalGuests, array $analysis): array
    {
        $result = [];
        
        // Regla: 1 cocinero por cada 50 personas
        if ($analysis['paella_count'] > 0) {
            $result['staff_cuiner'] = (int) ceil($totalGuests / 50);
        }
        
        // Regla: 1 camarero por cada 25 personas
        $result['staff_cambrer'] = (int) ceil($totalGuests / 25);
        
        return $result;
    }
}
