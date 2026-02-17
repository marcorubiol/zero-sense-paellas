<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support;

class MaterialDefinitions
{
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
                'unit' => 'u',
            ],
            [
                'key' => 'paella_65cm',
                'label' => 'Paella 65cm (6-9 pax)',
                'category' => 'paelles',
                'unit' => 'u',
            ],
            [
                'key' => 'paella_70cm',
                'label' => 'Paella 70cm (8-12 pax)',
                'category' => 'paelles',
                'unit' => 'u',
            ],
            [
                'key' => 'paella_80cm',
                'label' => 'Paella 80cm (12-15 pax)',
                'category' => 'paelles',
                'unit' => 'u',
            ],
            [
                'key' => 'paella_90cm',
                'label' => 'Paella 90cm (15-25 pax)',
                'category' => 'paelles',
                'unit' => 'u',
            ],
            [
                'key' => 'paella_100cm',
                'label' => 'Paella 100cm (25-40 pax)',
                'category' => 'paelles',
                'unit' => 'u',
            ],
            [
                'key' => 'paella_115cm',
                'label' => 'Paella 115cm (40-60 pax)',
                'category' => 'paelles',
                'unit' => 'u',
            ],
            [
                'key' => 'paella_135cm',
                'label' => 'Paella 135cm (60-80 pax)',
                'category' => 'paelles',
                'unit' => 'u',
            ],
            
            // CREMADORS
            [
                'key' => 'cremador_50cm',
                'label' => 'Cremador 50-60cm',
                'category' => 'cremadors',
                'unit' => 'u',
            ],
            [
                'key' => 'cremador_70cm',
                'label' => 'Cremador 70cm',
                'category' => 'cremadors',
                'unit' => 'u',
            ],
            [
                'key' => 'cremador_90cm',
                'label' => 'Cremador 90cm',
                'category' => 'cremadors',
                'unit' => 'u',
            ],
            
            // EQUIPAMENT CUINA
            [
                'key' => 'potes_tripodes',
                'label' => 'Potes / Trípodes',
                'category' => 'equipament_cuina',
                'unit' => 'u',
            ],
            [
                'key' => 'buta',
                'label' => 'Butà',
                'category' => 'equipament_cuina',
                'unit' => 'u',
            ],
            [
                'key' => 'cassoles',
                'label' => 'Cassoles',
                'category' => 'equipament_cuina',
                'unit' => 'u',
            ],
            [
                'key' => 'catifes',
                'label' => 'Catifes',
                'category' => 'equipament_cuina',
                'unit' => 'u',
            ],
            [
                'key' => 'poals_fems',
                'label' => 'Poals Fems',
                'category' => 'equipament_cuina',
                'unit' => 'u',
            ],
            [
                'key' => 'vitro_cremadors',
                'label' => 'Vitro / Cremadors',
                'category' => 'equipament_cuina',
                'unit' => 'u',
            ],
            
            // ROBA PERSONAL
            [
                'key' => 'xaquetes',
                'label' => 'Xaquetes',
                'category' => 'roba_personal',
                'unit' => 'u',
            ],
            [
                'key' => 'bandanes',
                'label' => 'Bandanes',
                'category' => 'roba_personal',
                'unit' => 'u',
            ],
            [
                'key' => 'davantals_cuiners',
                'label' => 'Davantals Cuiners',
                'category' => 'roba_personal',
                'unit' => 'u',
            ],
            [
                'key' => 'davantals_cambrers',
                'label' => 'Davantals Cambrers',
                'category' => 'roba_personal',
                'unit' => 'u',
            ],
            
            // TEXTILS I NETEJA
            [
                'key' => 'draps',
                'label' => 'Draps',
                'category' => 'textils_neteja',
                'unit' => 'u',
            ],
            [
                'key' => 'teles_negres',
                'label' => 'Teles Negres',
                'category' => 'textils_neteja',
                'unit' => 'u',
            ],
            [
                'key' => 'estovalles',
                'label' => 'Estovalles',
                'category' => 'textils_neteja',
                'unit' => 'u',
            ],
            
            // CAIXES I CONTENIDORS
            [
                'key' => 'caixa_gris_gran_utensilis',
                'label' => 'Caixa Gris Gran Utensilis',
                'category' => 'caixes_contenidors',
                'unit' => 'u',
            ],
            [
                'key' => 'caixa_gris_mitjana_paravents',
                'label' => 'Caixa Gris Mitjana Paravents',
                'category' => 'caixes_contenidors',
                'unit' => 'u',
            ],
            [
                'key' => 'caixa_gris_petita_neteja',
                'label' => 'Caixa Gris Petita Neteja',
                'category' => 'caixes_contenidors',
                'unit' => 'u',
            ],
            [
                'key' => 'caixa_marro_especies',
                'label' => 'Caixa Marró Espècies',
                'category' => 'caixes_contenidors',
                'unit' => 'u',
            ],
            [
                'key' => 'caixa_gris_gran_extra',
                'label' => 'Caixa Gris Gran Extra',
                'category' => 'caixes_contenidors',
                'unit' => 'u',
            ],
            
            // REFRIGERACIÓ
            [
                'key' => 'neveres_portatils',
                'label' => 'Neveres Portàtils',
                'category' => 'refrigeracio',
                'unit' => 'u',
            ],
            [
                'key' => 'poal_refrigerador_begudes',
                'label' => 'Poal Refrigerador Begudes',
                'category' => 'refrigeracio',
                'unit' => 'u',
            ],
            
            // UTENSILIS SERVIR
            [
                'key' => 'culleres_servir_extra',
                'label' => 'Culleres per Servir Extra',
                'category' => 'utensilis_servir',
                'unit' => 'u',
            ],
            [
                'key' => 'cubiteres_llauto',
                'label' => 'Cubiteres Llautó',
                'category' => 'utensilis_servir',
                'unit' => 'u',
            ],
            [
                'key' => 'cubiteres_gel',
                'label' => 'Cubiteres Gel',
                'category' => 'utensilis_servir',
                'unit' => 'u',
            ],
            [
                'key' => 'pinzes_gel',
                'label' => 'Pinzes Gel',
                'category' => 'utensilis_servir',
                'unit' => 'u',
            ],
            
            // MOBILIARI ESDEVENIMENTS
            [
                'key' => 'para_sols',
                'label' => 'Para-sols',
                'category' => 'mobiliari_esdeveniments',
                'unit' => 'u',
            ],
            [
                'key' => 'carpa',
                'label' => 'Carpa',
                'category' => 'mobiliari_esdeveniments',
                'unit' => 'u',
            ],
            [
                'key' => 'illuminacio',
                'label' => 'Il·luminació',
                'category' => 'mobiliari_esdeveniments',
                'unit' => 'u',
            ],
            [
                'key' => 'font_aigua_8l',
                'label' => 'Font Aigua 8L',
                'category' => 'mobiliari_esdeveniments',
                'unit' => 'u',
            ],
            
            // VAIXELLA I MENATGE
            [
                'key' => 'tirador_cervesa',
                'label' => 'Tirador Cervesa',
                'category' => 'vaixella_menatge',
                'unit' => 'u',
            ],
            [
                'key' => 'copes_vi_tritan',
                'label' => 'Copes Vi Tritan',
                'category' => 'vaixella_menatge',
                'unit' => 'u',
            ],
            [
                'key' => 'gots_reutilitzables_plastic',
                'label' => 'Gots Reutilitzables Plàstic',
                'category' => 'vaixella_menatge',
                'unit' => 'u',
            ],
            [
                'key' => 'bols_amanides',
                'label' => 'Bols Amanides',
                'category' => 'vaixella_menatge',
                'unit' => 'u',
            ],
            [
                'key' => 'coberts_amanides',
                'label' => 'Coberts Amanides',
                'category' => 'vaixella_menatge',
                'unit' => 'u',
            ],
            
            // ALTRES
            [
                'key' => 'manguera',
                'label' => 'Manguera',
                'category' => 'altres',
                'unit' => 'u',
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
