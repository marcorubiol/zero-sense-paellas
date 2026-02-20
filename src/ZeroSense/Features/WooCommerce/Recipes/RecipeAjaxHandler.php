<?php
declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce\Recipes;

use WP_Term;

class RecipeAjaxHandler
{
    private const TAX_INGREDIENT = RecipePostType::TAX_INGREDIENT;
    public const TAX_UTENSIL = RecipePostType::TAX_UTENSIL;
    public const TAX_LIQUID = RecipePostType::TAX_LIQUID;

    public function init(): void
    {
        add_action('wp_ajax_zs_ingredient_search', [$this, 'ajaxIngredientSearch']);
        add_action('wp_ajax_zs_ingredient_create', [$this, 'ajaxIngredientCreate']);
        add_action('wp_ajax_zs_utensil_search', [$this, 'ajaxUtensilSearch']);
        add_action('wp_ajax_zs_utensil_create', [$this, 'ajaxUtensilCreate']);
        add_action('wp_ajax_zs_liquid_search', [$this, 'ajaxLiquidSearch']);
        add_action('wp_ajax_zs_liquid_create', [$this, 'ajaxLiquidCreate']);
    }

    private function normalizeIngredientName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/', ' ', $name);
        return mb_strtolower($name, 'UTF-8');
    }

    private function capitalizeIngredientName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/', ' ', $name);
        return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
    }

    private function handleSearch(string $taxonomy): void
    {
        if (!current_user_can('edit_posts')) {
            wp_send_json(['results' => []]);
        }

        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash((string) $_GET['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'zs_ingredient_ajax')) {
            wp_send_json(['results' => []]);
        }

        $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
        
        $exclude = isset($_GET['exclude']) ? sanitize_text_field(wp_unslash((string) $_GET['exclude'])) : '';
        $excludeIds = array_filter(array_map('intval', explode(',', $exclude)));

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => 100,
            'suppress_filters' => true,
        ]);

        $results = [];
        if (is_array($terms)) {
            $normalizedQuery = $q !== '' ? $this->normalizeIngredientName($q) : '';
            
            foreach ($terms as $term) {
                if ($term instanceof WP_Term) {
                    if (in_array($term->term_id, $excludeIds, true)) {
                        continue;
                    }
                    
                    if ($normalizedQuery === '') {
                        $results[] = ['id' => (string) $term->term_id, 'text' => $term->name];
                    } else {
                        $normalizedTermName = $this->normalizeIngredientName($term->name);
                        if (mb_strpos($normalizedTermName, $normalizedQuery, 0, 'UTF-8') !== false) {
                            $results[] = ['id' => (string) $term->term_id, 'text' => $term->name];
                        }
                    }
                }
            }
        }

        $results = array_slice($results, 0, 25);

        wp_send_json([
            'results' => $results,
        ]);
    }

    private function handleCreate(string $taxonomy): void
    {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'forbidden']);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'zs_ingredient_ajax')) {
            wp_send_json_error(['message' => 'invalid_nonce']);
        }

        $rawName = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';
        if ($rawName === '') {
            wp_send_json_error(['message' => 'empty']);
        }

        $normalizedName = $this->normalizeIngredientName($rawName);
        $displayName = $this->capitalizeIngredientName($rawName);

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => 100,
            'suppress_filters' => true,
        ]);

        if (is_array($terms)) {
            foreach ($terms as $term) {
                if ($term instanceof WP_Term) {
                    $existingNormalized = $this->normalizeIngredientName($term->name);
                    if ($existingNormalized === $normalizedName) {
                        wp_send_json_success(['id' => (int) $term->term_id, 'text' => $term->name]);
                    }
                }
            }
        }

        $created = wp_insert_term($displayName, $taxonomy);
        
        if (is_wp_error($created)) {
            if ($created->get_error_code() === 'term_exists') {
                $termId = $created->get_error_data('term_exists');
                if ($termId) {
                    $term = get_term($termId, $taxonomy);
                    if ($term instanceof WP_Term) {
                        wp_send_json_success(['id' => (int) $term->term_id, 'text' => $term->name]);
                    }
                }
            }
            
            wp_send_json_error(['message' => 'create_failed']);
        }
        
        if (!is_array($created) || !isset($created['term_id'])) {
            wp_send_json_error(['message' => 'create_failed']);
        }

        wp_send_json_success(['id' => (int) $created['term_id'], 'text' => $displayName]);
    }

    public function ajaxIngredientSearch(): void
    {
        $this->handleSearch(self::TAX_INGREDIENT);
    }

    public function ajaxIngredientCreate(): void
    {
        $this->handleCreate(self::TAX_INGREDIENT);
    }

    public function ajaxUtensilSearch(): void
    {
        $this->handleSearch(self::TAX_UTENSIL);
    }

    public function ajaxUtensilCreate(): void
    {
        $this->handleCreate(self::TAX_UTENSIL);
    }

    public function ajaxLiquidSearch(): void
    {
        $this->handleSearch(self::TAX_LIQUID);
    }

    public function ajaxLiquidCreate(): void
    {
        $this->handleCreate(self::TAX_LIQUID);
    }
}
