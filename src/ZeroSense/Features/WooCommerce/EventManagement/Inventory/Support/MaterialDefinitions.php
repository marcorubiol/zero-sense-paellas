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
            // Paellas
            [
                'key' => 'paella_90cm',
                'label' => 'Paellas 90cm',
                'category' => 'paellas',
                'unit' => 'u',
            ],
            [
                'key' => 'paella_100cm',
                'label' => 'Paellas 100cm',
                'category' => 'paellas',
                'unit' => 'u',
            ],
            [
                'key' => 'paella_110cm',
                'label' => 'Paellas 110cm',
                'category' => 'paellas',
                'unit' => 'u',
            ],
            
            // Cremadores
            [
                'key' => 'cremador_90cm',
                'label' => 'Cremadores 90cm',
                'category' => 'cremadores',
                'unit' => 'u',
            ],
            [
                'key' => 'cremador_100cm',
                'label' => 'Cremadores 100cm',
                'category' => 'cremadores',
                'unit' => 'u',
            ],
            [
                'key' => 'cremador_110cm',
                'label' => 'Cremadores 110cm',
                'category' => 'cremadores',
                'unit' => 'u',
            ],
            
            // Bombonas
            [
                'key' => 'bombona_gas',
                'label' => 'Bombonas de Gas',
                'category' => 'gas',
                'unit' => 'u',
            ],
            
            // Mesas
            [
                'key' => 'mesa_rectangular',
                'label' => 'Mesas Rectangulares',
                'category' => 'mobiliario',
                'unit' => 'u',
            ],
            [
                'key' => 'mesa_redonda',
                'label' => 'Mesas Redondas',
                'category' => 'mobiliario',
                'unit' => 'u',
            ],
            
            // Sillas
            [
                'key' => 'silla_plegable',
                'label' => 'Sillas Plegables',
                'category' => 'mobiliario',
                'unit' => 'u',
            ],
            
            // Manteles
            [
                'key' => 'mantel_blanco',
                'label' => 'Manteles Blancos',
                'category' => 'textil',
                'unit' => 'u',
            ],
            
            // Barra
            [
                'key' => 'barra_bar',
                'label' => 'Barras de Bar',
                'category' => 'barra',
                'unit' => 'u',
            ],
            [
                'key' => 'nevera_portatil',
                'label' => 'Neveras Portátiles',
                'category' => 'barra',
                'unit' => 'u',
            ],
            
            // Personal
            [
                'key' => 'staff_cuiner',
                'label' => 'Cocineros',
                'category' => 'personal',
                'unit' => 'personas',
            ],
            [
                'key' => 'staff_cambrer',
                'label' => 'Camareros',
                'category' => 'personal',
                'unit' => 'personas',
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
