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
        
        // Obtener staff asignado
        $staffData = $order->get_meta('zs_event_staff', true) ?: [];
        $staffCount = self::countStaff($staffData);
        
        // Calcular paellas y cremadores
        if ($analysis['paella_count'] > 0) {
            $paellaCalc = self::calculatePaellasAndCremadors($totalGuests, $analysis);
            $result = array_merge($result, $paellaCalc);
        }
        
        // Calcular taules treball
        $result = array_merge($result, self::calculateTaulesTreball($totalGuests, $analysis));
        
        // Calcular equipament cuina
        $result = array_merge($result, self::calculateEquipamentCuina($totalGuests, $analysis, $result));
        
        // Calcular roba personal
        $result = array_merge($result, self::calculateRobaPersonal($staffCount));
        
        // Calcular textils i neteja
        $result = array_merge($result, self::calculateTextilsNeteja($totalGuests, $result));
        
        // Calcular refrigeració
        $result = array_merge($result, self::calculateRefrigeracio($totalGuests, $analysis));
        
        // Calcular vaixella i menatge
        $result = array_merge($result, self::calculateVaixellaMenatge($totalGuests, $analysis));
        
        // Calcular caixes i contenidors
        $result = array_merge($result, self::calculateCaixesContenidors($totalGuests));
        
        return $result;
    }
    
    /**
     * Cuenta staff por roles
     */
    private static function countStaff(array $staffData): array
    {
        $count = ['cuiner' => 0, 'cambrer' => 0];
        
        foreach ($staffData as $roleSlug => $staffIds) {
            if (empty($staffIds) || !is_array($staffIds)) {
                continue;
            }
            
            // Mapear roles a categorías
            if (in_array($roleSlug, ['jefe-de-voluntarios', 'cocineros', 'ayudantes'])) {
                $count['cuiner'] += count($staffIds);
            } elseif (in_array($roleSlug, ['camareros', 'barra', 'coqueteles', 'tallador-de-pernil'])) {
                $count['cambrer'] += count($staffIds);
            }
        }
        
        return $count;
    }
    
    /**
     * TAULES TREBALL
     */
    private static function calculateTaulesTreball(int $guests, array $analysis): array
    {
        $taules = 0;
        
        if ($guests <= 20) {
            $taules = 1;
        } elseif ($guests <= 35) {
            $taules = 2;
        } elseif ($guests <= 50) {
            $taules = 3;
        } elseif ($guests <= 70) {
            $taules = 4;
        } else {
            $taules = (int) ceil($guests / 20);
        }
        
        // +1 si hay entrants
        if ($analysis['has_entrants']) {
            $taules++;
        }
        
        // +1 si hay barra libre
        if ($analysis['has_barra_lliure']) {
            $taules++;
        }
        
        return ['taules_treball' => $taules];
    }
    
    /**
     * PAELLES Y CREMADORS
     */
    private static function calculatePaellasAndCremadors(int $guests, array $analysis): array
    {
        $result = [];
        
        // Definición de capacidades de paellas (pax promedio)
        $paellaSizes = [
            ['key' => 'paella_135cm', 'min' => 60, 'max' => 80, 'avg' => 70, 'cremador' => 'cremador_90cm'],
            ['key' => 'paella_115cm', 'min' => 40, 'max' => 60, 'avg' => 50, 'cremador' => 'cremador_70cm'],
            ['key' => 'paella_100cm', 'min' => 25, 'max' => 40, 'avg' => 32, 'cremador' => 'cremador_90cm'],
            ['key' => 'paella_90cm', 'min' => 15, 'max' => 25, 'avg' => 20, 'cremador' => 'cremador_70cm'],
            ['key' => 'paella_80cm', 'min' => 12, 'max' => 15, 'avg' => 13, 'cremador' => 'cremador_70cm'],
            ['key' => 'paella_70cm', 'min' => 8, 'max' => 12, 'avg' => 10, 'cremador' => 'cremador_70cm'],
            ['key' => 'paella_65cm', 'min' => 6, 'max' => 9, 'avg' => 7, 'cremador' => 'cremador_50cm'],
            ['key' => 'paella_55cm', 'min' => 4, 'max' => 6, 'avg' => 5, 'cremador' => 'cremador_50cm'],
        ];
        
        // Algoritmo: usar paellas más grandes posibles
        $remaining = $guests;
        $cremadorCount = [];
        
        foreach ($paellaSizes as $size) {
            if ($remaining <= 0) break;
            
            // Calcular cuántas paellas de este tamaño necesitamos
            $count = 0;
            
            // Si quedan suficientes personas para llenar esta paella
            while ($remaining >= $size['min']) {
                $count++;
                $remaining -= $size['avg'];
                
                // Contar cremadores
                if (!isset($cremadorCount[$size['cremador']])) {
                    $cremadorCount[$size['cremador']] = 0;
                }
                $cremadorCount[$size['cremador']]++;
            }
            
            if ($count > 0) {
                $result[$size['key']] = $count;
            }
        }
        
        // Si aún quedan personas (resto pequeño), añadir una paella pequeña
        if ($remaining > 0) {
            if (!isset($result['paella_55cm'])) {
                $result['paella_55cm'] = 0;
            }
            $result['paella_55cm']++;
            
            if (!isset($cremadorCount['cremador_50cm'])) {
                $cremadorCount['cremador_50cm'] = 0;
            }
            $cremadorCount['cremador_50cm']++;
        }
        
        // Añadir cremadores
        foreach ($cremadorCount as $cremador => $count) {
            $result[$cremador] = $count;
        }
        
        // Calcular total de cremadores
        $totalCremadors = array_sum($cremadorCount);
        
        // Potes/Trípodes: 1 por cremador
        $result['potes_tripodes'] = $totalCremadors;
        
        // Butà: 1 por cremador + extras si >60pax
        $result['buta'] = $totalCremadors;
        if ($guests > 60) {
            $result['buta'] += 2; // 1-2 extra para eventos grandes
        }
        
        // Catifes: 1 por paella (sumar todas las paellas)
        $totalPaellas = 0;
        foreach ($result as $key => $value) {
            if (strpos($key, 'paella_') === 0) {
                $totalPaellas += $value;
            }
        }
        $result['catifes'] = $totalPaellas;
        
        return $result;
    }
    
    /**
     * EQUIPAMENT CUINA
     */
    private static function calculateEquipamentCuina(int $guests, array $analysis, array $currentResult): array
    {
        $result = [];
        
        // Cassoles: 1 por variedad de paella
        $varieties = array_unique($analysis['paella_varieties']);
        $result['cassoles'] = count($varieties);
        
        // Poals fems: 1 cada 20pax
        $result['poals_fems'] = (int) ceil($guests / 20);
        
        // Vitro/Cremadors: 1 o 2 fijos
        $result['vitro_cremadors'] = $guests > 50 ? 2 : 1;
        
        return $result;
    }
    
    /**
     * ROBA PERSONAL
     */
    private static function calculateRobaPersonal(array $staffCount): array
    {
        $result = [];
        
        // Xaquetes: 1 por cuiner
        $result['xaquetes'] = $staffCount['cuiner'];
        
        // Bandanes: 1 por cuiner
        $result['bandanes'] = $staffCount['cuiner'];
        
        // Davantals cuiners: 1 por cuiner
        $result['davantals_cuiners'] = $staffCount['cuiner'];
        
        // Davantals cambrers: 1 por cambrer
        $result['davantals_cambrers'] = $staffCount['cambrer'];
        
        return $result;
    }
    
    /**
     * TEXTILS I NETEJA
     */
    private static function calculateTextilsNeteja(int $guests, array $currentResult): array
    {
        $result = [];
        
        // Draps: 1 cada 15pax + 1 extra
        $result['draps'] = (int) ceil($guests / 15) + 1;
        
        // Teles negres: mismo número que taules
        if (isset($currentResult['taules_treball'])) {
            $result['teles_negres'] = $currentResult['taules_treball'];
        }
        
        // Estovalles: mismo número que taules
        if (isset($currentResult['taules_treball'])) {
            $result['estovalles'] = $currentResult['taules_treball'];
        }
        
        return $result;
    }
    
    /**
     * REFRIGERACIÓ
     */
    private static function calculateRefrigeracio(int $guests, array $analysis): array
    {
        $result = [];
        
        // Neveres portàtils
        // Para cuina: 1 cada 40pax
        $neveresCuina = (int) ceil($guests / 40);
        
        // Para openbar: 1 cada 15pax
        $neveresBar = 0;
        if ($analysis['has_barra_lliure']) {
            $neveresBar = (int) ceil($guests / 15);
        }
        
        $result['neveres_portatils'] = $neveresCuina + $neveresBar;
        
        // Poal refrigerador begudes: Para openbar >25pax
        if ($analysis['has_barra_lliure'] && $guests > 25) {
            $result['poal_refrigerador_begudes'] = 1;
        }
        
        return $result;
    }
    
    /**
     * VAIXELLA I MENATGE
     */
    private static function calculateVaixellaMenatge(int $guests, array $analysis): array
    {
        $result = [];
        
        if ($analysis['has_barra_lliure']) {
            // Font aigua 8L: Para openbar
            $result['font_aigua_8l'] = 1;
            
            // Cubiteres llautó: 1 cada 15pax
            $result['cubiteres_llauto'] = (int) ceil($guests / 15);
            
            // Cubiteres gel: 1 cada 30pax
            $result['cubiteres_gel'] = (int) ceil($guests / 30);
            
            // Pinzes gel: mismo que cubiteres gel
            $result['pinzes_gel'] = $result['cubiteres_gel'];
            
            // Copes vi tritan: 2 por persona
            $result['copes_vi_tritan'] = $guests * 2;
            
            // Gots reutilitzables: 3 por persona
            $result['gots_reutilitzables_plastic'] = $guests * 3;
        }
        
        return $result;
    }
    
    /**
     * CAIXES I CONTENIDORS
     */
    private static function calculateCaixesContenidors(int $guests): array
    {
        $result = [];
        
        // Caixa gris gran extra: Para eventos grandes (+60pax)
        if ($guests > 60) {
            $result['caixa_gris_gran_extra'] = 1;
        }
        
        return $result;
    }
}
