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
            'materia_pesada' => 'Matèria Pesada',
            'logistica'      => 'Logística',
            'textil'         => 'Textil',
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
                'description' => '1 paella per cada recepta amb 4-6 persones',
            ],
            [
                'key' => 'paella_65cm',
                'label' => 'Paella 65cm (6-9 pax)',
                'category' => 'paelles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 paella per cada recepta amb 6-9 persones',
            ],
            [
                'key' => 'paella_70cm',
                'label' => 'Paella 70cm (8-12 pax)',
                'category' => 'paelles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 paella per cada recepta amb 8-12 persones',
            ],
            [
                'key' => 'paella_80cm',
                'label' => 'Paella 80cm (12-15 pax)',
                'category' => 'paelles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 paella per cada recepta amb 12-15 persones',
            ],
            [
                'key' => 'paella_90cm',
                'label' => 'Paella 90cm (15-25 pax)',
                'category' => 'paelles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 paella per cada recepta amb 15-25 persones',
            ],
            [
                'key' => 'paella_100cm',
                'label' => 'Paella 100cm (25-40 pax)',
                'category' => 'paelles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 paella per cada recepta amb 25-40 persones',
            ],
            [
                'key' => 'paella_115cm',
                'label' => 'Paella 115cm (40-60 pax)',
                'category' => 'paelles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 paella per cada recepta amb 40-60 persones',
            ],
            [
                'key' => 'paella_135cm',
                'label' => 'Paella 135cm (60-80 pax)',
                'category' => 'paelles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 paella per cada recepta amb 60-80 persones',
            ],

            // MATÈRIA PESADA — equipament paella
            [
                'key' => 'cremador_50cm',
                'label' => 'Cremador 50cm',
                'category' => 'equipament_paella',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cada paella de 55cm o 65cm',
            ],
            [
                'key' => 'cremador_60cm',
                'label' => 'Cremador 60cm',
                'category' => 'equipament_paella',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cada paella de 70cm',
            ],
            [
                'key' => 'cremador_70cm',
                'label' => 'Cremador 70cm',
                'category' => 'equipament_paella',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cada paella de 80cm o 90cm',
            ],
            [
                'key' => 'cremador_90cm',
                'label' => 'Cremador 90cm',
                'category' => 'equipament_paella',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cada paella de 100cm, 115cm o 135cm',
            ],
            [
                'key' => 'potes_tripodes',
                'label' => 'Potes / Trípodes',
                'category' => 'equipament_paella',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cremador',
            ],
            [
                'key' => 'buta',
                'label' => 'Butà',
                'category' => 'equipament_paella',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per cremador + 1 extra si >60pax',
            ],
            [
                'key' => 'catifes',
                'label' => 'Catifes',
                'category' => 'equipament_paella',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per paella',
            ],

            // MATÈRIA PESADA — cassoles
            [
                'key' => 'cassola_5l',
                'label' => 'Cassola 4.9L (13×23cm)',
                'category' => 'cassoles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per recepta amb ≤ 4.9L totals',
            ],
            [
                'key' => 'cassola_6l',
                'label' => 'Cassola 6.6L (15×25cm)',
                'category' => 'cassoles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per recepta amb 4.9–6.6L totals',
            ],
            [
                'key' => 'cassola_9l',
                'label' => 'Cassola 9.5L (17×28cm)',
                'category' => 'cassoles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per recepta amb 6.6–9.5L totals',
            ],
            [
                'key' => 'cassola_xata_11l',
                'label' => 'Cassola Xata 11.6L (12×37cm)',
                'category' => 'cassoles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per recepta amb 9.5–11.6L totals',
            ],
            [
                'key' => 'cassola_xata_13l',
                'label' => 'Cassola Xata 13.0L (17×33cm)',
                'category' => 'cassoles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per recepta amb 11.6–13.0L totals',
            ],
            [
                'key' => 'cassola_15l',
                'label' => 'Cassola 15.5L (30×27cm)',
                'category' => 'cassoles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per recepta amb 13.0–15.5L totals',
            ],
            [
                'key' => 'cassola_33l',
                'label' => 'Cassola 33.8L (35×37cm)',
                'category' => 'cassoles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per recepta amb > 15.5L totals',
            ],
            [
                'key' => 'vitro',
                'label' => 'Vitro',
                'category' => 'cassoles',
                'parent_category' => 'materia_pesada',
                'unit' => 'u',
                'description' => '1 per varietat de paella',
            ],

            // LOGÍSTICA
            [
                'key' => 'carreto',
                'label' => 'Carretó',
                'category' => 'logistica',
                'parent_category' => 'logistica',
                'unit' => 'u',
                'description' => '1 per event',
            ],
            [
                'key' => 'taules_treball',
                'label' => 'Taules Treball',
                'category' => 'logistica',
                'parent_category' => 'logistica',
                'unit' => 'u',
                'description' => '≤20pax:1, ≤35:2, ≤50:3, ≤70:4, >70:ceil/20',
            ],
            [
                'key' => 'poals_fems',
                'label' => 'Poals Fems',
                'category' => 'logistica',
                'parent_category' => 'logistica',
                'unit' => 'u',
                'description' => '1 cada 20pax',
            ],
            [
                'key' => 'neveres_portatils',
                'label' => 'Nevera portàtil',
                'category' => 'logistica',
                'parent_category' => 'logistica',
                'unit' => 'u',
                'description' => '1 cada 30 persones',
            ],

            // LOGÍSTICA — caixes
            [
                'key' => 'caixa_gris_gran_utensilis',
                'label' => 'KIT utensilis cuina (caixa gris gran)',
                'category' => 'caixes',
                'parent_category' => 'logistica',
                'unit' => 'u',
                'description' => '1 per event',
            ],
            [
                'key' => 'caixa_gris_mitjana_paravents',
                'label' => 'KIT paravents (caixa gris mitjana)',
                'category' => 'caixes',
                'parent_category' => 'logistica',
                'unit' => 'u',
                'description' => '1 per event',
            ],
            [
                'key' => 'caixa_gris_petita_neteja',
                'label' => 'KIT neteja (caixa gris petita)',
                'category' => 'caixes',
                'parent_category' => 'logistica',
                'unit' => 'u',
                'description' => '1 per event',
            ],
            [
                'key' => 'caixa_marro_especies',
                'label' => 'Caixa Marró Espècies',
                'category' => 'caixes',
                'parent_category' => 'logistica',
                'unit' => 'u',
                'description' => '1 per event',
            ],
            [
                'key' => 'caixa_gris_gran_extra',
                'label' => 'KIT Utensilis Extra',
                'category' => 'caixes',
                'parent_category' => 'logistica',
                'unit' => 'u',
                'description' => '1 si >60 persones',
            ],
            // TEXTIL — roba personal
            [
                'key' => 'xaquetes',
                'label' => 'Xaquetes',
                'category' => 'roba_personal',
                'parent_category' => 'textil',
                'unit' => 'u',
                'description' => '1 per cuiner assignat',
            ],
            [
                'key' => 'bandanes',
                'label' => 'Bandanes',
                'category' => 'roba_personal',
                'parent_category' => 'textil',
                'unit' => 'u',
                'description' => '1 per cuiner assignat',
            ],
            [
                'key' => 'davantals_cuiners',
                'label' => 'Davantals Cuiners',
                'category' => 'roba_personal',
                'parent_category' => 'textil',
                'unit' => 'u',
                'description' => '1 per cuiner assignat',
            ],
            [
                'key' => 'davantals_cambrers',
                'label' => 'Davantals Cambrers',
                'category' => 'roba_personal',
                'parent_category' => 'textil',
                'unit' => 'u',
                'description' => '1 per cambrer assignat',
            ],

            // TEXTIL — teixits i neteja
            [
                'key' => 'draps',
                'label' => 'Draps',
                'category' => 'textils_neteja',
                'parent_category' => 'textil',
                'unit' => 'u',
                'description' => '1 cada 15pax + 1 extra',
            ],
            [
                'key' => 'teles_negres',
                'label' => 'Teles Negres',
                'category' => 'textils_neteja',
                'parent_category' => 'textil',
                'unit' => 'u',
                'description' => 'mateix nombre que taules treball',
            ],
            [
                'key' => 'estovalles',
                'label' => 'Estovalles',
                'category' => 'textils_neteja',
                'parent_category' => 'textil',
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
