<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support;

use WC_Order;
use WP_Term;

class ProductMapper
{
    /**
     * Meta key para recetas (del sistema Recipes.php existente)
     */
    const META_PRODUCT_RECIPE_ID = 'zs_recipe_id';
    
    /**
     * Category slugs BASE (español) para fallback
     * Solo para servicios que NO tienen receta (barra, staff)
     * 
     * IMPORTANTE: Usar el slug del idioma por defecto (español).
     * WPML resolverá automáticamente a las traducciones:
     * - ES: "barras-libres" → CA: "barres-lliures" → EN: "open-bar"
     */
    const ENTRANT_CATEGORY_SLUGS = ['entrantes', 'aperitivos'];
    const BARRA_CATEGORY_SLUGS = ['barras-libres'];
    
    /**
     * Cache de term IDs
     */
    private static $termIdsCache = [];
    
    /**
     * Cache de análisis de pedidos (para evitar inconsistencias en múltiples llamadas)
     */
    private static $analysisCache = [];
    
    /**
     * Analiza order items usando LÓGICA WATERFALL:
     * 1. Recetas (preciso para comida)
     * 2. Categorías (fallback para servicios)
     * 
     * @param WC_Order $order
     * @return array
     */
    public static function analyzeOrder(WC_Order $order): array
    {
        $orderId = $order->get_id();
        
        // Return cached result if available (same request)
        if (isset(self::$analysisCache[$orderId])) {
            error_log('📦 CACHE HIT for Order #' . $orderId . ' - has_barra_lliure: ' . (self::$analysisCache[$orderId]['has_barra_lliure'] ? 'YES' : 'NO'));
            return self::$analysisCache[$orderId];
        }
        
        // DEBUG: Log caller to understand flow
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        $caller = '';
        foreach ($backtrace as $i => $trace) {
            if (isset($trace['class']) && isset($trace['function'])) {
                $caller .= ($i > 0 ? ' <- ' : '') . $trace['class'] . '::' . $trace['function'];
            }
        }
        error_log('🔍 CACHE MISS for Order #' . $orderId . ' - Caller: ' . $caller);
        
        $result = [
            'has_entrants' => false,
            'has_barra_lliure' => false,
            'paella_varieties' => [],
            'paella_count' => 0,
            'paella_items' => [],
            'staff_count' => ['cuiner' => 0, 'cambrer' => 0],
        ];
        
        // Pre-carga de categorías (Safety Net para servicios sin receta)
        $barraTermIds = self::getTermIds(self::BARRA_CATEGORY_SLUGS);
        $entrantsTermIds = self::getTermIds(self::ENTRANT_CATEGORY_SLUGS);
        
        $items = $order->get_items();
        error_log('   Items count: ' . count($items));
        
        foreach ($items as $itemId => $item) {
            $product = $item->get_product();
            
            if (!$product) {
                continue;
            }
            
            // ---------------------------------------------------------
            // NIVEL 1: RECETA (La verdad absoluta para comida)
            // ---------------------------------------------------------
            // Always read from original product (WPML support)
            $originalProductId = self::resolveOriginalProductId($product->get_id());
            $recipeId = get_post_meta($originalProductId, self::META_PRODUCT_RECIPE_ID, true);
            
            if ($recipeId) {
                $recipe = get_post($recipeId);
                
                if ($recipe && $recipe->post_type === 'zs_recipe') {
                    // UTF-8 safe
                    $recipeName = mb_strtolower($recipe->post_title, 'UTF-8');
                    
                    // Detectar paellas por checkbox en receta
                    $needsPaella = get_post_meta($recipeId, 'zs_recipe_needs_paella', true);
                    
                    if ($needsPaella === '1') {
                        $guests = (int) $item->get_quantity();
                        $result['paella_count'] += $guests;
                        
                        // Detectar variedad por nombre (para cassoles)
                        $variety = '';
                        if (mb_stripos($recipeName, 'valenciana', 0, 'UTF-8') !== false) {
                            $variety = 'valenciana';
                            $result['paella_varieties'][] = 'valenciana';
                        } elseif (mb_stripos($recipeName, 'marisco', 0, 'UTF-8') !== false) {
                            $variety = 'marisco';
                            $result['paella_varieties'][] = 'marisco';
                        } elseif (mb_stripos($recipeName, 'secreta', 0, 'UTF-8') !== false) {
                            $variety = 'secreta';
                            $result['paella_varieties'][] = 'secreta';
                        } elseif (mb_stripos($recipeName, 'mixta', 0, 'UTF-8') !== false) {
                            $variety = 'mixta';
                            $result['paella_varieties'][] = 'mixta';
                        }
                        
                        // Añadir item detallado para cálculos
                        $paellaItem = [
                            'item_id' => $itemId,
                            'recipe_id' => $recipeId,
                            'recipe_name' => $recipe->post_title,
                            'guests' => $guests,
                            'variety' => $variety,
                        ];
                        $result['paella_items'][] = $paellaItem;
                        
                        continue; // ✅ Procesado por receta, skip categorías
                    }
                    
                    // Detectar entrants por receta
                    if (mb_stripos($recipeName, 'entrante', 0, 'UTF-8') !== false || 
                        mb_stripos($recipeName, 'ensalada', 0, 'UTF-8') !== false ||
                        mb_stripos($recipeName, 'aperitivo', 0, 'UTF-8') !== false) {
                        $result['has_entrants'] = true;
                        continue;
                    }
                }
            }
            
            // ---------------------------------------------------------
            // NIVEL 2: CATEGORÍA (Fallback para Servicios/Staff)
            // ---------------------------------------------------------
            // Si no tenía receta (o no era comida), miramos categorías
            
            $productId = $item->get_product_id();
            
            // Barra Libre (suele venderse por horas, sin receta de cocina)
            if (!empty($barraTermIds) && has_term($barraTermIds, 'product_cat', $productId)) {
                $result['has_barra_lliure'] = true;
            }
            
            // Entrants (si venden "Pack Aperitivo" sin receta detallada)
            if (!empty($entrantsTermIds) && has_term($entrantsTermIds, 'product_cat', $productId)) {
                $result['has_entrants'] = true;
            }
        }
        
        // Cache result for this request
        self::$analysisCache[$orderId] = $result;
        error_log('   ➡️ RESULT: has_barra_lliure=' . ($result['has_barra_lliure'] ? 'YES' : 'NO') . ', has_entrants=' . ($result['has_entrants'] ? 'YES' : 'NO'));
        
        return $result;
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
     * Obtiene term IDs de categorías (WPML-safe)
     * 
     * @param array $slugs Category slugs en idioma base
     * @return array Term IDs
     */
    private static function getTermIds(array $slugs): array
    {
        $cacheKey = md5(implode(',', $slugs));
        
        if (isset(self::$termIdsCache[$cacheKey])) {
            return self::$termIdsCache[$cacheKey];
        }
        
        $termIds = [];
        
        foreach ($slugs as $slug) {
            $term = get_term_by('slug', $slug, 'product_cat');
            
            // VALIDACIÓN: Si categoría no existe, skip
            if (!$term || is_wp_error($term)) {
                continue;
            }
            
            // Obtener ID del idioma base si WPML está activo
            if (function_exists('apply_filters') && defined('ICL_SITEPRESS_VERSION')) {
                $defaultLang = apply_filters('wpml_default_language', null);
                $baseId = apply_filters('wpml_object_id', $term->term_id, 'product_cat', true, $defaultLang);
                $termIds[] = $baseId ?: $term->term_id;
            } else {
                $termIds[] = $term->term_id;
            }
        }
        
        self::$termIdsCache[$cacheKey] = $termIds;
        
        return $termIds;
    }
}
