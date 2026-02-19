<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support;

use WC_Order;

class MaterialCalculator
{
    /**
     * Cassola sizes sorted largest to smallest (litres => material_key)
     */
    private const CASSOLA_SIZES = [
        33.8 => 'cassola_33l',
        15.5 => 'cassola_15l',
        13.0 => 'cassola_xata_13l',
        11.6 => 'cassola_xata_11l',
        9.5  => 'cassola_9l',
        6.6  => 'cassola_6l',
        4.9  => 'cassola_5l',
    ];

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
        
        // Calcular logística cuina
        $result = array_merge($result, self::calculateLogisticaCuina($totalGuests, $analysis, $result));
        
        // Calcular items fixos (1 per event)
        $result = array_merge($result, self::calculateFixed());
        
        // Calcular caixes condicionals
        $result = array_merge($result, self::calculateCaixesCondicionals($totalGuests));
        
        // Calcular roba personal
        $result = array_merge($result, self::calculateRobaPersonal($staffCount));
        
        // Calcular textil
        $result = array_merge($result, self::calculateTextil($totalGuests, $result));
        
        return $result;
    }
    
    /**
     * Cuenta staff por roles
     * 
     * Mapeo de roles específicos a categorías de ropa:
     * - CUINER: Jefe de voluntarios, Cocineros, Ayudantes
     * - CAMBRER: Camareros, Barra, Coqueteles, Tallador de pernil
     */
    private static function countStaff(array $staffData): array
    {
        $count = ['cuiner' => 0, 'cambrer' => 0];
        
        // Roles que necesitan ropa de cocina (xaquetes, bandanes, davantals cuiners)
        $cuinerRoles = [
            'cap-de-bolo',           // Cap de Bolo
            'cuiner-a',              // Cuiner/a
            'ajudant-a-de-cuina',    // Ajudant/a de cuina
        ];
        
        // Roles que necesitan ropa de servicio (davantals cambrers)
        $cambrerRoles = [
            'cambrer-a-barra',       // Cambrer/a - Barra
            'cockteler-a',           // Cockteler/a
            'tallador-a-de-pernil',  // Tallador/a de pernil
        ];
        
        // El formato del meta es: [['role' => 'slug', 'staff_id' => id], ...]
        foreach ($staffData as $assignment) {
            if (!is_array($assignment) || !isset($assignment['role'])) {
                continue;
            }
            
            $roleSlug = $assignment['role'];
            
            // Obtener el term para verificar el slug
            $term = get_term_by('slug', $roleSlug, 'zs_staff_role');
            if (!$term) {
                // Intentar por term_id si role es un ID
                $term = get_term($roleSlug, 'zs_staff_role');
            }
            
            if ($term && !is_wp_error($term)) {
                $roleSlug = $term->slug;
            }
            
            // Mapear a categorías
            if (in_array($roleSlug, $cuinerRoles, true)) {
                $count['cuiner']++;
            } elseif (in_array($roleSlug, $cambrerRoles, true)) {
                $count['cambrer']++;
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
        
        return ['taules_treball' => $taules];
    }
    
    /**
     * PAELLES Y CREMADORS
     */
    private static function calculatePaellasAndCremadors(int $guests, array $analysis): array
    {
        $result = [];
        $cremadorCount = [];
        $totalPaellas = 0;
        
        // Si no hay paella_items, no calculamos nada
        if (empty($analysis['paella_items'])) {
            return $result;
        }
        
        // Definición de capacidades de paellas (pax promedio)
        $paellaSizes = [
            ['key' => 'paella_135cm', 'min' => 60, 'max' => 80, 'avg' => 70, 'cremador' => 'cremador_90cm'],
            ['key' => 'paella_115cm', 'min' => 40, 'max' => 60, 'avg' => 50, 'cremador' => 'cremador_90cm'],
            ['key' => 'paella_100cm', 'min' => 25, 'max' => 40, 'avg' => 32, 'cremador' => 'cremador_90cm'],
            ['key' => 'paella_90cm', 'min' => 15, 'max' => 25, 'avg' => 20, 'cremador' => 'cremador_70cm'],
            ['key' => 'paella_80cm', 'min' => 12, 'max' => 15, 'avg' => 13, 'cremador' => 'cremador_70cm'],
            ['key' => 'paella_70cm', 'min' => 8, 'max' => 12, 'avg' => 10, 'cremador' => 'cremador_60cm'],
            ['key' => 'paella_65cm', 'min' => 6, 'max' => 9, 'avg' => 7, 'cremador' => 'cremador_50cm'],
            ['key' => 'paella_55cm', 'min' => 4, 'max' => 6, 'avg' => 5, 'cremador' => 'cremador_50cm'],
        ];
        
        // Iterar sobre cada receta de paella individual
        foreach ($analysis['paella_items'] as $paellaItem) {
            $itemGuests = (int) $paellaItem['guests'];
            
            if ($itemGuests <= 0) {
                continue;
            }
            
            // Encontrar el tamaño de paella más apropiado para ESTE número de personas
            $selectedSize = null;
            
            foreach ($paellaSizes as $size) {
                if ($itemGuests >= $size['min'] && $itemGuests <= $size['max']) {
                    $selectedSize = $size;
                    break;
                }
            }
            
            // Si no encontramos tamaño exacto, usar el más cercano
            if (!$selectedSize) {
                if ($itemGuests < 4) {
                    $selectedSize = $paellaSizes[count($paellaSizes) - 1]; // Más pequeña
                } else {
                    $selectedSize = $paellaSizes[0]; // Más grande
                }
            }
            
            // Añadir paella
            if (!isset($result[$selectedSize['key']])) {
                $result[$selectedSize['key']] = 0;
            }
            $result[$selectedSize['key']]++;
            $totalPaellas++;
            
            // Añadir cremador correspondiente
            if (!isset($cremadorCount[$selectedSize['cremador']])) {
                $cremadorCount[$selectedSize['cremador']] = 0;
            }
            $cremadorCount[$selectedSize['cremador']]++;
        }
        
        // Añadir cremadores al resultado
        foreach ($cremadorCount as $cremador => $count) {
            $result[$cremador] = $count;
        }
        
        // Calcular total de cremadores
        $totalCremadors = array_sum($cremadorCount);
        
        // Potes/Trípodes: 1 por cremador
        $result['potes_tripodes'] = $totalCremadors;
        
        // Butà: 1 por cremador + 1 extra si >60pax
        $result['buta'] = $totalCremadors;
        if ($guests > 60) {
            $result['buta'] += 1;
        }
        
        // Catifes: 1 por paella
        $result['catifes'] = $totalPaellas;
        
        return $result;
    }
    
    /**
     * LOGÍSTICA CUINA
     */
    private static function calculateLogisticaCuina(int $guests, array $analysis, array $currentResult): array
    {
        $result = [];

        // Cassoles: pick the right size based on total litres from recipe liquids
        foreach ($analysis['paella_items'] as $paellaItem) {
            $recipeId = (int) ($paellaItem['recipe_id'] ?? 0);
            $itemGuests = (int) ($paellaItem['guests'] ?? 0);

            if ($recipeId <= 0 || $itemGuests <= 0) {
                continue;
            }

            $totalLitres = self::calculateTotalLitres($recipeId, $itemGuests);
            $cassolaKey = self::selectCassola($totalLitres);

            if (!isset($result[$cassolaKey])) {
                $result[$cassolaKey] = 0;
            }
            $result[$cassolaKey]++;
        }

        // Poals fems: 1 cada 20pax
        $result['poals_fems'] = (int) ceil($guests / 20);

        // Vitro: 1 per varietat de paella
        $varieties = array_unique($analysis['paella_varieties']);
        $result['vitro'] = count($varieties);

        return $result;
    }

    /**
     * Calculate total litres needed for a recipe item
     */
    private static function calculateTotalLitres(int $recipeId, int $guests): float
    {
        $liquids = get_post_meta($recipeId, 'zs_recipe_liquids', true);

        if (!is_array($liquids) || empty($liquids)) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($liquids as $row) {
            if (!is_array($row)) {
                continue;
            }
            $litresPerPax = isset($row['qty']) ? (float) $row['qty'] : 0.0;
            $total += $litresPerPax * $guests;
        }

        return $total;
    }

    /**
     * Select the smallest cassola that fits the required litres.
     * Falls back to cassola_15l if no liquids are defined (total = 0).
     */
    private static function selectCassola(float $totalLitres): string
    {
        if ($totalLitres <= 0) {
            return 'cassola_15l';
        }

        // Sizes are sorted largest to smallest; find the smallest that fits
        $best = 'cassola_33l';
        foreach (self::CASSOLA_SIZES as $capacity => $key) {
            if ($capacity >= $totalLitres) {
                $best = $key;
            }
        }

        return $best;
    }
    
    /**
     * ITEMS FIXOS (1 per event)
     */
    private static function calculateFixed(): array
    {
        return [
            'carreto'                    => 1,
            'caixa_gris_gran_utensilis'  => 1,
            'caixa_gris_mitjana_paravents' => 1,
            'caixa_gris_petita_neteja'   => 1,
            'caixa_marro_especies'       => 1,
        ];
    }
    
    /**
     * CAIXES CONDICIONALS
     */
    private static function calculateCaixesCondicionals(int $guests): array
    {
        $result = [];
        
        if ($guests > 60) {
            $result['caixa_gris_gran_extra'] = 1;
        }
        
        $result['neveres_portatils'] = (int) ceil($guests / 30);
        
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
     * TEXTIL
     */
    private static function calculateTextil(int $guests, array $currentResult): array
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
}
