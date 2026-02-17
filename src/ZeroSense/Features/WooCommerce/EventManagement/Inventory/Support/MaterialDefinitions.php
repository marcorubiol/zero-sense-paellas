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
            'materials_cuina' => 'Materials Cuina/Recepta',
            'materials_servei' => 'Materials Servei',
            'materials_logistica' => 'Materials Logística',
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
            // PAELLES
            [
                'key' => 'paella_55cm',
                'label' => 'Paella 55cm (4-6 pax)',
                'category' => 'paelles',
                'parent_category' => 'materials_cuina',
                'unit' => 'u',
                'description' => '1 paella per cada recepta amb 4-6 persones',
            ],
            [
                'key' => 'paella_65cm',
                'label' => 'Paella 65cm (6-9 pax)',
                'category' => 'paelles',
                'parent_category' => 'materials_cuina',
                'unit' => 'u',
                'description' => '1 paella per cada recepta amb 6-9 persones',
            ],
            [
                'key' => 'paella_70cm',
                'label' => 'Paella 70cm (8-12 pax)',
                'category' => 'paelles',
                'parent_category' => 'materials_cuina',
                'unit' => 'u',
                'description' => '1 paella per cada recepta amb 8-12 persones',
            ],
            [
                'key' => 'paella_80cm',
                'label' => 'Paella 80cm (12-15 pax)',
                'category' => 'paelles',
                'parent_category' => 'materials_cuina',
                'unit' => 'u',
                'description' => '1 paella per cada recepta amb 12-15 persones',
            ],
            [
                'key' => 'paella_90cm',
                'label' => 'Paella 90cm (15-25 pax)',
                'category' => 'paelles',
                'parent_category' => 'materials_cuina',
                'unit' => 'u',
                'description' => '1 paella per cada recepta amb 15-25 persones',
            ],
            [
                'key' => 'paella_100cm',
                'label' => 'Paella 100cm (25-40 pax)',
                'category' => 'paelles',
                'parent_category' => 'materials_cuina',
                'unit' => 'u',
                'description' => '1 paella per cada recepta amb 25-40 persones',
            ],
            [
                'key' => 'paella_115cm',
                'label' => 'Paella 115cm (40-60 pax)',
                'category' => 'paelles',
                'parent_category' => 'materials_cuina',
                'unit' => 'u',
                'description' => '1 paella per cada recepta amb 40-60 persones',
            ],
            [
                'key' => 'paella_135cm',
                'label' => 'Paella 135cm (60-80 pax)',
                'category' => 'paelles',
                'parent_category' => 'materials_cuina',
                'unit' => 'u',
                'description' => '1 paella per cada recepta amb 60-80 persones',
            ],
            
            // CREMADORS
            [
                'key' => 'cremador_50cm',
                'label' => 'Cremador 50-60cm',
                'category' => 'cremadors',
                'parent_category' => 'materials_cuina',
                'unit' => 'u',
                'description' => '1 per cada paella de 55cm o 65cm',
            ],
            [
                'key' => 'cremador_70cm',
                'label' => 'Cremador 70cm',
                'category' => 'cremadors',
                'parent_category' => 'materials_cuina',
                'unit' => 'u',
                'description' => '1 per cada paella de 70cm, 80cm, 90cm o 115cm',
            ],
            [
                'key' => 'cremador_90cm',
                'label' => 'Cremador 90cm',
                'category' => 'cremadors',
                'parent_category' => 'materials_cuina',
                'unit' => 'u',
                'description' => '1 per cada paella de 100cm o 135cm',
            ],
            
            // EQUIPAMENT CUINA
            [
                'key' => 'potes_tripodes',
                'label' => 'Potes / Trípodes',
                'category' => 'equipament_cuina',
                'parent_category' => 'materials_cuina',
                'unit' => 'u',
                'description' => 'Càlcul automàtic: 1 per cremador',
            ],
            [
                'key' => 'buta',
                'label' => 'Butà',
                'category' => 'equipament_cuina',
                'parent_category' => 'materials_cuina',
                'unit' => 'u',
                'description' => 'Càlcul automàtic: 1 per cremador + 2 extra si >60pax',
            ],
            [
                'key' => 'cassoles',
                'label' => 'Cassoles',
                'category' => 'equipament_cuina',
                'parent_category' => 'materials_cuina',
                'unit' => 'u',
                'description' => 'Càlcul automàtic: 1 per varietat de paella',
            ],
            [
                'key' => 'catifes',
                'label' => 'Catifes',
                'category' => 'equipament_cuina',
                'parent_category' => 'materials_cuina',
                'unit' => 'u',
                'description' => 'Càlcul automàtic: 1 per paella',
            ],
            [
                'key' => 'poals_fems',
                'label' => 'Poals Fems',
                'category' => 'equipament_cuina',
                'parent_category' => 'materials_cuina',
                'unit' => 'u',
                'description' => 'Càlcul automàtic: 1 cada 20pax',
            ],
            [
                'key' => 'vitro_cremadors',
                'label' => 'Vitro / Cremadors',
                'category' => 'equipament_cuina',
                'parent_category' => 'materials_cuina',
                'unit' => 'u',
                'description' => '1 si ≤50 persones, 2 si >50 persones',
            ],
            
            // ROBA PERSONAL
            [
                'key' => 'xaquetes',
                'label' => 'Xaquetes',
                'category' => 'roba_personal',
                'parent_category' => 'materials_servei',
                'unit' => 'u',
                'description' => 'Càlcul automàtic: 1 per cuiner assignat',
            ],
            [
                'key' => 'bandanes',
                'label' => 'Bandanes',
                'category' => 'roba_personal',
                'parent_category' => 'materials_servei',
                'unit' => 'u',
                'description' => 'Càlcul automàtic: 1 per cuiner assignat',
            ],
            [
                'key' => 'davantals_cuiners',
                'label' => 'Davantals Cuiners',
                'category' => 'roba_personal',
                'parent_category' => 'materials_servei',
                'unit' => 'u',
                'description' => 'Càlcul automàtic: 1 per cuiner assignat',
            ],
            [
                'key' => 'davantals_cambrers',
                'label' => 'Davantals Cambrers',
                'category' => 'roba_personal',
                'parent_category' => 'materials_servei',
                'unit' => 'u',
                'description' => 'Càlcul automàtic: 1 per cambrer assignat',
            ],
            
            // TEXTILS I NETEJA
            [
                'key' => 'draps',
                'label' => 'Draps',
                'category' => 'textils_neteja',
                'parent_category' => 'materials_servei',
                'unit' => 'u',
                'description' => 'Càlcul automàtic: 1 cada 15pax + 1 extra',
            ],
            [
                'key' => 'teles_negres',
                'label' => 'Teles Negres',
                'category' => 'textils_neteja',
                'parent_category' => 'materials_servei',
                'unit' => 'u',
                'description' => 'Càlcul automàtic: mateix nombre que taules treball',
            ],
            [
                'key' => 'estovalles',
                'label' => 'Estovalles',
                'category' => 'textils_neteja',
                'parent_category' => 'materials_servei',
                'unit' => 'u',
                'description' => 'Càlcul automàtic: mateix nombre que taules treball',
            ],
            
            // CAIXES I CONTENIDORS
            [
                'key' => 'caixa_gris_gran_utensilis',
                'label' => 'Caixa Gris Gran Utensilis',
                'category' => 'caixes_contenidors',
                'parent_category' => 'materials_logistica',
                'unit' => 'u',
                'description' => 'Manual',
            ],
            [
                'key' => 'caixa_gris_mitjana_paravents',
                'label' => 'Caixa Gris Mitjana Paravents',
                'category' => 'caixes_contenidors',
                'parent_category' => 'materials_logistica',
                'unit' => 'u',
                'description' => 'Manual',
            ],
            [
                'key' => 'caixa_gris_petita_neteja',
                'label' => 'Caixa Gris Petita Neteja',
                'category' => 'caixes_contenidors',
                'parent_category' => 'materials_logistica',
                'unit' => 'u',
                'description' => 'Manual',
            ],
            [
                'key' => 'caixa_marro_especies',
                'label' => 'Caixa Marró Espècies',
                'category' => 'caixes_contenidors',
                'parent_category' => 'materials_logistica',
                'unit' => 'u',
                'description' => 'Manual',
            ],
            [
                'key' => 'caixa_gris_gran_extra',
                'label' => 'Caixa Gris Gran Extra',
                'category' => 'caixes_contenidors',
                'parent_category' => 'materials_logistica',
                'unit' => 'u',
                'description' => '1 si >60 persones',
            ],
            
            // REFRIGERACIÓ
            [
                'key' => 'neveres_portatils',
                'label' => 'Neveres Portàtils',
                'category' => 'refrigeracio',
                'parent_category' => 'materials_logistica',
                'unit' => 'u',
                'description' => 'Cuina: 1 cada 40 persones. Openbar: +1 cada 15 persones',
            ],
            [
                'key' => 'poal_refrigerador_begudes',
                'label' => 'Poal Refrigerador Begudes',
                'category' => 'refrigeracio',
                'parent_category' => 'materials_logistica',
                'unit' => 'u',
                'description' => '1 si openbar i >25 persones',
            ],
            
            // UTENSILIS SERVIR
            [
                'key' => 'culleres_servir_extra',
                'label' => 'Culleres per Servir Extra',
                'category' => 'utensilis_servir',
                'parent_category' => 'materials_servei',
                'unit' => 'u',
                'description' => 'Manual',
            ],
            [
                'key' => 'cubiteres_llauto',
                'label' => 'Cubiteres Llautó',
                'category' => 'utensilis_servir',
                'parent_category' => 'materials_servei',
                'unit' => 'u',
                'description' => 'Openbar: 1 cada 15 persones',
            ],
            [
                'key' => 'cubiteres_gel',
                'label' => 'Cubiteres Gel',
                'category' => 'utensilis_servir',
                'parent_category' => 'materials_servei',
                'unit' => 'u',
                'description' => 'Openbar: 1 cada 30 persones',
            ],
            [
                'key' => 'pinzes_gel',
                'label' => 'Pinzes Gel',
                'category' => 'utensilis_servir',
                'parent_category' => 'materials_servei',
                'unit' => 'u',
                'description' => '1 per cada cubitera de gel',
            ],
            
            // MOBILIARI ESDEVENIMENTS
            [
                'key' => 'para_sols',
                'label' => 'Para-sols',
                'category' => 'mobiliari_esdeveniments',
                'parent_category' => 'materials_logistica',
                'unit' => 'u',
                'description' => 'Manual',
            ],
            [
                'key' => 'carpa',
                'label' => 'Carpa',
                'category' => 'mobiliari_esdeveniments',
                'parent_category' => 'materials_logistica',
                'unit' => 'u',
                'description' => 'Manual',
            ],
            [
                'key' => 'illuminacio',
                'label' => 'Il·luminació',
                'category' => 'mobiliari_esdeveniments',
                'parent_category' => 'materials_logistica',
                'unit' => 'u',
                'description' => 'Manual',
            ],
            [
                'key' => 'font_aigua_8l',
                'label' => 'Font Aigua 8L',
                'category' => 'mobiliari_esdeveniments',
                'parent_category' => 'materials_logistica',
                'unit' => 'u',
                'description' => '1 si openbar',
            ],
            
            // VAIXELLA I MENATGE
            [
                'key' => 'tirador_cervesa',
                'label' => 'Tirador Cervesa',
                'category' => 'vaixella_menatge',
                'parent_category' => 'materials_servei',
                'unit' => 'u',
                'description' => 'Manual',
            ],
            [
                'key' => 'copes_vi_tritan',
                'label' => 'Copes Vi Tritan',
                'category' => 'vaixella_menatge',
                'parent_category' => 'materials_servei',
                'unit' => 'u',
                'description' => 'Openbar: 2 per persona',
            ],
            [
                'key' => 'gots_reutilitzables_plastic',
                'label' => 'Gots Reutilitzables Plàstic',
                'category' => 'vaixella_menatge',
                'parent_category' => 'materials_servei',
                'unit' => 'u',
                'description' => 'Openbar: 3 per persona',
            ],
            [
                'key' => 'bols_amanides',
                'label' => 'Bols Amanides',
                'category' => 'vaixella_menatge',
                'parent_category' => 'materials_servei',
                'unit' => 'u',
                'description' => 'Manual',
            ],
            [
                'key' => 'coberts_amanides',
                'label' => 'Coberts Amanides',
                'category' => 'vaixella_menatge',
                'parent_category' => 'materials_servei',
                'unit' => 'u',
                'description' => 'Manual',
            ],
            
            // ALTRES
            [
                'key' => 'taules_treball',
                'label' => 'Taules Treball',
                'category' => 'altres',
                'parent_category' => 'materials_logistica',
                'unit' => 'u',
                'description' => '≤20pax:1, ≤35:2, ≤50:3, ≤70:4, >70:1/20pax. +1 si entrants, +1 si openbar',
            ],
            [
                'key' => 'vehicle',
                'label' => 'Vehicle',
                'category' => 'altres',
                'parent_category' => 'materials_logistica',
                'unit' => 'u',
                'description' => 'Manual',
            ],
            [
                'key' => 'carreto',
                'label' => 'Carretó',
                'category' => 'altres',
                'parent_category' => 'materials_logistica',
                'unit' => 'u',
                'description' => 'Manual',
            ],
            [
                'key' => 'manguera',
                'label' => 'Manguera',
                'category' => 'altres',
                'parent_category' => 'materials_logistica',
                'unit' => 'u',
                'description' => 'Manual',
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
