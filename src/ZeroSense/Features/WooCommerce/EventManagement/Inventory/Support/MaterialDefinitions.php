<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support;

class MaterialDefinitions
{
    /**
     * Parent categories for grouping materials
     */
    public static function getParentCategories(): array
    {
        return [
            'materia_pesada'    => 'Matèria Pesada',
            'transport_muntatge' => 'Transport i Muntatge',
            'textil_imatge'     => 'Textil i Imatge',
        ];
    }
    
    /**
     * Obtiene todas las definiciones de materiales
     * 
     * @return array
     */
    public static function getAll(): array
    {
        return [
            // MATÈRIA PESADA — paelles
            [
                'key' => 'paella_55cm',
                'label' => 'Paella 55cm (4-6 pax)',
                'category' => 'paelles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cada recepta de paella amb 4-6 persones',
            ],
            [
                'key' => 'paella_65cm',
                'label' => 'Paella 65cm (6-9 pax)',
                'category' => 'paelles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cada recepta de paella amb 6-9 persones',
            ],
            [
                'key' => 'paella_70cm',
                'label' => 'Paella 70cm (8-12 pax)',
                'category' => 'paelles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cada recepta de paella amb 8-12 persones',
            ],
            [
                'key' => 'paella_80cm',
                'label' => 'Paella 80cm (12-15 pax)',
                'category' => 'paelles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cada recepta de paella amb 12-15 persones',
            ],
            [
                'key' => 'paella_90cm',
                'label' => 'Paella 90cm (15-25 pax)',
                'category' => 'paelles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cada recepta de paella amb 15-25 persones',
            ],
            [
                'key' => 'paella_100cm',
                'label' => 'Paella 100cm (25-40 pax)',
                'category' => 'paelles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cada recepta de paella amb 25-40 persones',
            ],
            [
                'key' => 'paella_115cm',
                'label' => 'Paella 115cm (40-60 pax)',
                'category' => 'paelles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cada recepta de paella amb 40-60 persones',
            ],
            [
                'key' => 'paella_135cm',
                'label' => 'Paella 135cm (60-80 pax)',
                'category' => 'paelles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cada recepta de paella amb 60-80 persones',
            ],

            // MATÈRIA PESADA — cassoles
            [
                'key' => 'cassola_5l',
                'label' => 'Cassola 5L',
                'category' => 'cassoles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per recepta amb ≤ 5L totals',
            ],
            [
                'key' => 'cassola_7l',
                'label' => 'Cassola 7L',
                'category' => 'cassoles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per recepta amb 5-7L totals',
            ],
            [
                'key' => 'cassola_10l',
                'label' => 'Cassola 10L',
                'category' => 'cassoles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per recepta amb 7-10L totals',
            ],
            [
                'key' => 'cassola_12l',
                'label' => 'Cassola 12L',
                'category' => 'cassoles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per recepta amb 10-12L totals',
            ],
            [
                'key' => 'cassola_13l',
                'label' => 'Cassola 13L',
                'category' => 'cassoles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per recepta amb 12-13L totals',
            ],
            [
                'key' => 'cassola_16l',
                'label' => 'Cassola 16L',
                'category' => 'cassoles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per recepta amb 13-16L totals',
            ],
            [
                'key' => 'cassola_34l',
                'label' => 'Cassola 34L',
                'category' => 'cassoles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per recepta amb 16L-34L totals',
            ],

            // MATÈRIA PESADA — equipament de foc
            [
                'key' => 'cremador_50cm',
                'label' => 'Cremador 50cm',
                'category' => 'equipament_foc',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cada paella de 55cm o 65cm',
                'dependency_label' => '↳ Calculat a partir de les paelles',
            ],
            [
                'key' => 'cremador_60cm',
                'label' => 'Cremador 60cm',
                'category' => 'equipament_foc',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cada paella de 70cm',
                'dependency_label' => '↳ Calculat a partir de les paelles',
            ],
            [
                'key' => 'cremador_70cm',
                'label' => 'Cremador 70cm',
                'category' => 'equipament_foc',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cada paella de 80cm o 90cm',
                'dependency_label' => '↳ Calculat a partir de les paelles',
            ],
            [
                'key' => 'cremador_90cm',
                'label' => 'Cremador 90cm',
                'category' => 'equipament_foc',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cada paella de 100cm, 115cm o 135cm',
                'dependency_label' => '↳ Calculat a partir de les paelles',
            ],
            [
                'key' => 'potes_tripodes',
                'label' => 'Potes / Trípodes',
                'category' => 'equipament_foc',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cremador',
                'dependency_label' => '↳ Calculat a partir dels cremadors',
            ],
            [
                'key' => 'buta',
                'label' => 'Butà',
                'category' => 'equipament_foc',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cremador + 1 extra si >60pax',
                'dependency_label' => '↳ Calculat a partir dels cremadors',
            ],
            [
                'key' => 'vitro_gran',
                'label' => 'Vitro Gran',
                'category' => 'equipament_foc',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => 'Manual sense autocàlcul',
            ],
            [
                'key' => 'vitro_petita',
                'label' => 'Vitro Petita',
                'category' => 'equipament_foc',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cassola',
                'dependency_label' => '↳ Calculat a partir de les cassoles',
            ],
            [
                'key' => 'catifes',
                'label' => 'Catifes',
                'category' => 'equipament_foc',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cremador',
                'dependency_label' => '↳ Calculat a partir dels cremadors',
            ],

            // TRANSPORT I MUNTATGE — caixes
            [
                'key' => 'caixa_gris_gran_utensilis',
                'label' => 'KIT utensilis cuina (caixa gris gran)',
                'category' => 'caixes',
                'parent_category' => 'transport_muntatge',
                'unit' => 'u',
                'description' => '1 per event',
            ],
            [
                'key' => 'caixa_gris_mitjana_paravents',
                'label' => 'KIT paravents (caixa gris mitjana)',
                'category' => 'caixes',
                'parent_category' => 'transport_muntatge',
                'unit' => 'u',
                'description' => '1 per event',
            ],
            [
                'key' => 'caixa_gris_petita_neteja',
                'label' => 'KIT neteja (caixa gris petita)',
                'category' => 'caixes',
                'parent_category' => 'transport_muntatge',
                'unit' => 'u',
                'description' => '1 per event',
            ],
            [
                'key' => 'caixa_marro_especies',
                'label' => 'Caixa Marró Espècies',
                'category' => 'caixes',
                'parent_category' => 'transport_muntatge',
                'unit' => 'u',
                'description' => '1 per event',
            ],
            [
                'key' => 'caixa_gris_gran_extra',
                'label' => 'KIT Utensilis Extra',
                'category' => 'caixes',
                'parent_category' => 'transport_muntatge',
                'unit' => 'u',
                'description' => '1 si >60 persones',
            ],

            // TRANSPORT I MUNTATGE — suport muntatge
            [
                'key' => 'carreto',
                'label' => 'Carretó',
                'category' => 'suport_muntatge',
                'parent_category' => 'transport_muntatge',
                'unit' => 'u',
                'description' => '1 per event',
            ],
            [
                'key' => 'taules_treball',
                'label' => 'Taules Treball',
                'category' => 'suport_muntatge',
                'parent_category' => 'transport_muntatge',
                'unit' => 'u',
                'description' => '≤20pax:1, ≤35:2, ≤50:3, ≤70:4, >70:ceil/20',
            ],
            [
                'key' => 'poals_fems',
                'label' => 'Poals Fems',
                'category' => 'suport_muntatge',
                'parent_category' => 'transport_muntatge',
                'unit' => 'u',
                'description' => '1 cada 20pax',
            ],
            [
                'key' => 'neveres_portatils',
                'label' => 'Nevera portàtil',
                'category' => 'suport_muntatge',
                'parent_category' => 'transport_muntatge',
                'unit' => 'u',
                'description' => '1 cada 30 persones',
            ],

            // TEXTIL I IMATGE — vestimenta staff
            [
                'key' => 'xaquetes',
                'label' => 'Xaquetes',
                'category' => 'roba_personal',
                'parent_category' => 'textil_imatge',
                'unit' => 'u',
                'description' => '1 per cuiner assignat',
            ],
            [
                'key' => 'bandanes',
                'label' => 'Bandanes',
                'category' => 'roba_personal',
                'parent_category' => 'textil_imatge',
                'unit' => 'u',
                'description' => '1 per cuiner assignat',
            ],
            [
                'key' => 'davantals_cuiners',
                'label' => 'Davantals Cuiners',
                'category' => 'roba_personal',
                'parent_category' => 'textil_imatge',
                'unit' => 'u',
                'description' => '1 per cuiner assignat',
            ],
            [
                'key' => 'davantals_cambrers',
                'label' => 'Davantals Cambrers',
                'category' => 'roba_personal',
                'parent_category' => 'textil_imatge',
                'unit' => 'u',
                'description' => '1 per cambrer assignat',
            ],

            // TEXTIL I IMATGE — vestimenta taules
            [
                'key' => 'draps',
                'label' => 'Draps',
                'category' => 'textils_neteja',
                'parent_category' => 'textil_imatge',
                'unit' => 'u',
                'description' => '1 cada 15pax + 1 extra',
            ],
            [
                'key' => 'teles_negres',
                'label' => 'Teles Negres',
                'category' => 'textils_neteja',
                'parent_category' => 'textil_imatge',
                'unit' => 'u',
                'description' => 'mateix nombre que taules treball',
            ],
            [
                'key' => 'estovalles',
                'label' => 'Estovalles',
                'category' => 'textils_neteja',
                'parent_category' => 'textil_imatge',
                'unit' => 'u',
                'description' => 'mateix nombre que taules treball',
            ],
        ];
    }
    
    /**
     * Obtiene definición de un material por su key
     * 
     * @param string $key
     * @return array|null
     */
    public static function get(string $key): ?array
    {
        $all = self::getAll();
        
        foreach ($all as $material) {
            if ($material['key'] === $key) {
                return $material;
            }
        }
        
        return null;
    }
    
    /**
     * Returns cascade dependencies for stock materials.
     * When a key is selected in the recipe stock picker, these additional
     * materials are auto-calculated by MaterialCalculator and shown as hints.
     */
    public static function getStockCascade(): array
    {
        return [
            'taules_treball' => ['teles_negres', 'estovalles'],
        ];
    }

    /**
     * Returns materials eligible for manual recipe stock assignment.
     * Excludes: materia_pesada (all auto-paella), dependency_label items, roba_personal (staff clothing).
     */
    public static function getStockEligible(): array
    {
        $cascadeChildren = [];
        foreach (self::getStockCascade() as $childKeys) {
            foreach ($childKeys as $ck) {
                $cascadeChildren[$ck] = true;
            }
        }

        return array_values(array_filter(self::getAll(), function(array $mat) use ($cascadeChildren): bool {
            if ($mat['parent_category'] === 'materia_pesada') {
                return false;
            }
            if (isset($mat['dependency_label'])) {
                return false;
            }
            if ($mat['category'] === 'roba_personal') {
                return false;
            }
            if (isset($cascadeChildren[$mat['key']])) {
                return false;
            }
            return true;
        }));
    }

    /**
     * Obtiene materiales por categoría
     * 
     * @param string $category
     * @return array
     */
    public static function getByCategory(string $category): array
    {
        $all = self::getAll();
        
        return array_filter($all, function($material) use ($category) {
            return $material['category'] === $category;
        });
    }
}
