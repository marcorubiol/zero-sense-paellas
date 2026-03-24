<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support;

use WC_Order;

class MaterialCalculator
{
    /**
     * Cassola sizes sorted largest to smallest (litres => material_key)
     */
    private const CASSOLA_SIZES = [
        '34' => 'cassola_34l',
        '16' => 'cassola_16l',
        '13' => 'cassola_13l',
        '12' => 'cassola_12l',
        '10' => 'cassola_10l',
        '7'  => 'cassola_7l',
        '5'  => 'cassola_5l',
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

        // Calcular stock requerido por recetas (aditivo sobre todo lo anterior)
        foreach (self::calculateRecipeStock($order) as $key => $qty) {
            $result[$key] = ($result[$key] ?? 0) + $qty;
        }
        
        return $result;
    }

    /**
     * Recalculate dependent items based on final values (after overrides).
     * Items that have an explicit manual override are left untouched.
     *
     * @param array $final      Final quantities (calculated + overrides applied)
     * @param array $overrides  Manual overrides (to know which items the user explicitly set)
     * @param int   $totalGuests Total guests for buta extra logic
     * @return array Updated final quantities
     */
    public static function recalculateDependents(array $final, array $overrides, int $totalGuests): array
    {
        // Sum all cremadors from final values
        $cremadorKeys = ['cremador_50cm', 'cremador_60cm', 'cremador_70cm', 'cremador_90cm'];
        $totalCremadors = 0;
        foreach ($cremadorKeys as $ck) {
            $totalCremadors += ($final[$ck] ?? 0);
        }

        // Update cremador dependents (only if not manually overridden)
        foreach (['potes_tripodes', 'catifes', 'tapapeus'] as $dep) {
            if (!self::hasManualOverride($dep, $overrides)) {
                $final[$dep] = $totalCremadors;
            }
        }

        // Buta: total cremadors + 1 if >60 pax
        if (!self::hasManualOverride('buta', $overrides)) {
            $final['buta'] = $totalCremadors + ($totalGuests > 60 && $totalCremadors > 0 ? 1 : 0);
        }

        // Sum all cassoles from final values
        $cassolaKeys = ['cassola_5l', 'cassola_7l', 'cassola_10l', 'cassola_12l', 'cassola_13l', 'cassola_16l', 'cassola_34l'];
        $totalCassoles = 0;
        foreach ($cassolaKeys as $ck) {
            $totalCassoles += ($final[$ck] ?? 0);
        }

        // Vitro petita: 1 per cassola
        if (!self::hasManualOverride('vitro_petita', $overrides)) {
            $final['vitro_petita'] = $totalCassoles;
        }

        // Taules treball → teles_negres, estovalles
        $taules = $final['taules_treball'] ?? 0;
        foreach (['teles_negres', 'estovalles'] as $dep) {
            if (!self::hasManualOverride($dep, $overrides)) {
                $final[$dep] = $taules;
            }
        }

        return $final;
    }

    private static function hasManualOverride(string $key, array $overrides): bool
    {
        return isset($overrides[$key]) && $overrides[$key] !== '' && $overrides[$key] !== null;
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
        
        // Catifes: 1 por cremador
        $result['catifes'] = $totalCremadors;
        
        // Tapapeus: 1 por cremador
        $result['tapapeus'] = $totalCremadors;
        
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
            $cassoles = self::selectCassoles($totalLitres);

            foreach ($cassoles as $cassolaKey) {
                if (!isset($result[$cassolaKey])) {
                    $result[$cassolaKey] = 0;
                }
                $result[$cassolaKey]++;
            }
        }

        // Poals fems: 1 cada 20pax
        $result['poals_fems'] = (int) ceil($guests / 20);

        // Vitro Petita: 1 per cassola
        $totalCassoles = 0;
        foreach (array_keys(self::CASSOLA_SIZES) as $capacity) {
            $cassolaKey = self::CASSOLA_SIZES[$capacity];
            if (isset($result[$cassolaKey])) {
                $totalCassoles += $result[$cassolaKey];
            }
        }
        $result['vitro_petita'] = $totalCassoles;

        return $result;
    }

    /**
     * RECIPE STOCK
     * Calcula materiales extra declarados en cada receta del pedido.
     * Fórmula: ceil(guests / pax_ratio) * qty  — igual que utensilios.
     */
    private static function calculateRecipeStock(WC_Order $order): array
    {
        $result = [];

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $guests = (int) $item->get_quantity();
            if ($guests <= 0) {
                continue;
            }

            $originalId = self::resolveOriginalProductId($product->get_id());
            $recipeId   = (int) get_post_meta($originalId, 'zs_recipe_id', true);

            // Rabbit-choice bifurcation
            if ($recipeId > 0 && method_exists($item, 'get_meta')) {
                $rabbitChoice = $item->get_meta('_zs_rabbit_choice', true);
                if ($rabbitChoice === 'without') {
                    $noRabbitId = (int) get_post_meta($originalId, 'zs_recipe_id_no_rabbit', true);
                    if ($noRabbitId > 0) {
                        $recipeId = $noRabbitId;
                    }
                }
            }

            if ($recipeId <= 0) {
                continue;
            }

            $stockRows = get_post_meta($recipeId, 'zs_recipe_stock', true);
            if (!is_array($stockRows) || empty($stockRows)) {
                continue;
            }

            foreach ($stockRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $matKey = isset($row['material_key']) ? sanitize_key((string) $row['material_key']) : '';
                $qty    = isset($row['qty']) ? (float) $row['qty'] : 0.0;
                $ratio  = isset($row['pax_ratio']) ? max(1, (int) $row['pax_ratio']) : 1;

                if ($matKey === '' || $qty <= 0) {
                    continue;
                }

                $amount = (int) ceil($guests / $ratio) * $qty;
                if ($amount <= 0) {
                    continue;
                }

                $result[$matKey] = ($result[$matKey] ?? 0) + $amount;
            }
        }

        // Apply cascade: for each parent key that has cascade children,
        // set child qty = parent qty (same count, e.g. taules_treball → teles_negres, estovalles)
        foreach (MaterialDefinitions::getStockCascade() as $parentKey => $childKeys) {
            if (!isset($result[$parentKey]) || $result[$parentKey] <= 0) {
                continue;
            }
            foreach ($childKeys as $childKey) {
                $result[$childKey] = ($result[$childKey] ?? 0) + $result[$parentKey];
            }
        }

        return $result;
    }

    /**
     * Returns recipe stock contributions per material key for display purposes.
     * Structure: [ 'key' => ['total' => N, 'source' => 'recipe'|'cascade', 'via' => 'parent_label'] ]
     * Does NOT affect calculate() — purely for InventoryMetabox breakdown hints.
     */
    public static function calculateRecipeStockBreakdown(WC_Order $order): array
    {
        // Re-run the same accumulation as calculateRecipeStock() to get direct totals
        $direct = [];

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            $guests = (int) $item->get_quantity();
            if ($guests <= 0) {
                continue;
            }
            $originalId = self::resolveOriginalProductId($product->get_id());
            $recipeId   = (int) get_post_meta($originalId, 'zs_recipe_id', true);

            if ($recipeId > 0 && method_exists($item, 'get_meta')) {
                $rabbitChoice = $item->get_meta('_zs_rabbit_choice', true);
                if ($rabbitChoice === 'without') {
                    $noRabbitId = (int) get_post_meta($originalId, 'zs_recipe_id_no_rabbit', true);
                    if ($noRabbitId > 0) {
                        $recipeId = $noRabbitId;
                    }
                }
            }
            if ($recipeId <= 0) {
                continue;
            }

            $stockRows = get_post_meta($recipeId, 'zs_recipe_stock', true);
            if (!is_array($stockRows) || empty($stockRows)) {
                continue;
            }

            foreach ($stockRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $matKey = isset($row['material_key']) ? sanitize_key((string) $row['material_key']) : '';
                $qty    = isset($row['qty']) ? (float) $row['qty'] : 0.0;
                $ratio  = isset($row['pax_ratio']) ? max(1, (int) $row['pax_ratio']) : 1;
                if ($matKey === '' || $qty <= 0) {
                    continue;
                }
                $amount = (int) ceil($guests / $ratio) * $qty;
                if ($amount <= 0) {
                    continue;
                }
                $direct[$matKey] = ($direct[$matKey] ?? 0) + $amount;
            }
        }

        $breakdown = [];

        foreach ($direct as $key => $total) {
            $breakdown[$key] = ['total' => $total, 'source' => 'recipe', 'via' => null];
        }

        // Add cascade children
        foreach (MaterialDefinitions::getStockCascade() as $parentKey => $childKeys) {
            if (!isset($direct[$parentKey]) || $direct[$parentKey] <= 0) {
                continue;
            }
            $parentDef   = MaterialDefinitions::get($parentKey);
            $parentLabel = $parentDef ? $parentDef['label'] : $parentKey;
            foreach ($childKeys as $childKey) {
                $cascadeTotal = $direct[$parentKey];
                if (isset($breakdown[$childKey])) {
                    $breakdown[$childKey]['total'] += $cascadeTotal;
                } else {
                    $breakdown[$childKey] = ['total' => $cascadeTotal, 'source' => 'cascade', 'via' => $parentLabel];
                }
            }
        }

        return $breakdown;
    }

    /**
     * Resolve original product ID (WPML support)
     */
    private static function resolveOriginalProductId(int $productId): int
    {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return $productId;
        }
        $defaultLang = apply_filters('wpml_default_language', null);
        $originalId  = apply_filters('wpml_object_id', $productId, 'product', true, $defaultLang);
        return $originalId ? (int) $originalId : $productId;
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
     * Select cassoles greedily to cover the required litres.
     * Uses the largest fitting cassola first, then repeats for the remainder.
     * Returns empty array if no litres defined.
     */
    private static function selectCassoles(float $totalLitres): array
    {
        if ($totalLitres <= 0) {
            return [];
        }

        $cassoles = [];
        $remaining = $totalLitres;
        $sizes = self::CASSOLA_SIZES; // sorted largest to smallest

        while ($remaining > 0) {
            $selected = null;
            foreach ($sizes as $capacity => $key) {
                if ((float) $capacity >= $remaining) {
                    $selected = $key;
                }
            }

            if ($selected === null) {
                // Remaining exceeds all sizes; use the largest
                $selected = reset($sizes);
            }

            $cassoles[] = $selected;

            // Find the capacity of the selected cassola
            $selectedCapacity = 0.0;
            foreach ($sizes as $capacity => $key) {
                if ($key === $selected) {
                    $selectedCapacity = (float) $capacity;
                    break;
                }
            }

            $remaining -= $selectedCapacity;

            // Safety: avoid infinite loop if largest cassola has 0 capacity
            if ($selectedCapacity <= 0) {
                break;
            }
        }

        return $cassoles;
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
