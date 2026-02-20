<?php
declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce\Recipes;

use WP_Term;

class RecipeTaxonomyProtection
{
    private const CPT = RecipePostType::CPT;
    private const TAX_INGREDIENT = RecipePostType::TAX_INGREDIENT;
    private const TAX_UTENSIL = RecipePostType::TAX_UTENSIL;
    private const TAX_LIQUID = RecipePostType::TAX_LIQUID;

    private const META_INGREDIENTS = 'zs_recipe_ingredients';
    private const META_UTENSILS = 'zs_recipe_utensils';
    private const META_LIQUIDS = 'zs_recipe_liquids';

    public function init(): void
    {
        add_filter('manage_edit-' . self::TAX_INGREDIENT . '_columns', [$this, 'addUsageColumn']);
        add_filter('manage_' . self::TAX_INGREDIENT . '_custom_column', [$this, 'renderUsageColumn'], 10, 3);
        add_filter('manage_edit-' . self::TAX_UTENSIL . '_columns', [$this, 'addUsageColumn']);
        add_filter('manage_' . self::TAX_UTENSIL . '_custom_column', [$this, 'renderUsageColumn'], 10, 3);
        add_filter('manage_edit-' . self::TAX_LIQUID . '_columns', [$this, 'addUsageColumn']);
        add_filter('manage_' . self::TAX_LIQUID . '_custom_column', [$this, 'renderUsageColumn'], 10, 3);
        
        add_filter(self::TAX_INGREDIENT . '_row_actions', [$this, 'removeDeleteActionIfInUse'], 10, 2);
        add_filter(self::TAX_UTENSIL . '_row_actions', [$this, 'removeDeleteActionIfInUse'], 10, 2);
        add_filter(self::TAX_LIQUID . '_row_actions', [$this, 'removeDeleteActionIfInUse'], 10, 2);
        
        add_filter('user_has_cap', [$this, 'preventTermDeletionCapability'], 10, 3);
        add_action('pre_delete_term', [$this, 'protectTermsInUse'], 10, 2);
    }

    public function addUsageColumn(array $columns): array
    {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'name') {
                $new_columns['usage'] = __('Used in recipes', 'zero-sense');
            }
        }
        return $new_columns;
    }

    public function renderUsageColumn(string $content, string $column_name, int $term_id): string
    {
        if ($column_name !== 'usage') {
            return $content;
        }

        $taxonomy = get_term($term_id)->taxonomy ?? '';
        if ($taxonomy === self::TAX_INGREDIENT) {
            $meta_key = self::META_INGREDIENTS;
            $field_name = 'ingredient';
        } elseif ($taxonomy === self::TAX_LIQUID) {
            $meta_key = self::META_LIQUIDS;
            $field_name = 'liquid';
        } else {
            $meta_key = self::META_UTENSILS;
            $field_name = 'utensil';
        }

        $recipes = get_posts([
            'post_type' => self::CPT,
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        $count = 0;
        foreach ($recipes as $recipe_id) {
            $items = get_post_meta($recipe_id, $meta_key, true);
            if (!is_array($items)) {
                continue;
            }
            
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                
                $item_id = isset($item[$field_name]) ? (int) $item[$field_name] : 0;
                if ($item_id === $term_id) {
                    $count++;
                    break;
                }
            }
        }

        if ($count === 0) {
            return '<span style="color:#999;">—</span>';
        }

        return sprintf(
            '<strong>%d</strong> %s',
            $count,
            $count === 1 ? __('recipe', 'zero-sense') : __('recipes', 'zero-sense')
        );
    }

    public function removeDeleteActionIfInUse(array $actions, WP_Term $term): array
    {
        $taxonomy = $term->taxonomy;
        if ($taxonomy !== self::TAX_INGREDIENT && $taxonomy !== self::TAX_UTENSIL && $taxonomy !== self::TAX_LIQUID) {
            return $actions;
        }

        if ($this->isTermInUse($term->term_id, $taxonomy)) {
            unset($actions['delete']);
            unset($actions['inline hide-if-no-js']);
        }

        return $actions;
    }

    public function preventTermDeletionCapability($allcaps, $caps, $args): array
    {
        if (!isset($args[0]) || $args[0] !== 'delete_term') {
            return $allcaps;
        }

        if (!isset($args[2])) {
            return $allcaps;
        }

        $term = get_term($args[2]);
        if (!$term instanceof WP_Term) {
            return $allcaps;
        }

        if ($term->taxonomy !== self::TAX_INGREDIENT && $term->taxonomy !== self::TAX_UTENSIL && $term->taxonomy !== self::TAX_LIQUID) {
            return $allcaps;
        }

        if ($this->isTermInUse($term->term_id, $term->taxonomy)) {
            $allcaps['delete_term'] = false;
        }

        return $allcaps;
    }

    public function protectTermsInUse(int $term_id, string $taxonomy): void
    {
        if ($taxonomy !== self::TAX_INGREDIENT && $taxonomy !== self::TAX_UTENSIL && $taxonomy !== self::TAX_LIQUID) {
            return;
        }

        if (!$this->isTermInUse($term_id, $taxonomy)) {
            return;
        }

        $term = get_term($term_id);
        $term_name = $term instanceof WP_Term ? $term->name : '';
        if ($taxonomy === self::TAX_INGREDIENT) {
            $type_label = __('ingredient', 'zero-sense');
        } elseif ($taxonomy === self::TAX_LIQUID) {
            $type_label = __('liquid', 'zero-sense');
        } else {
            $type_label = __('utensil', 'zero-sense');
        }
        $count = $this->getTermUsageCount($term_id, $taxonomy);

        wp_die(
            sprintf(
                __('Cannot delete %s "%s". It is currently used in %d recipe(s). Please remove it from all recipes before deleting.', 'zero-sense'),
                $type_label,
                esc_html($term_name),
                $count
            ),
            __('Term in use', 'zero-sense'),
            ['back_link' => true]
        );
    }

    private function isTermInUse(int $term_id, string $taxonomy): bool
    {
        return $this->getTermUsageCount($term_id, $taxonomy) > 0;
    }

    private function getTermUsageCount(int $term_id, string $taxonomy): int
    {
        if ($taxonomy === self::TAX_INGREDIENT) {
            $meta_key = self::META_INGREDIENTS;
            $field_name = 'ingredient';
        } elseif ($taxonomy === self::TAX_LIQUID) {
            $meta_key = self::META_LIQUIDS;
            $field_name = 'liquid';
        } else {
            $meta_key = self::META_UTENSILS;
            $field_name = 'utensil';
        }

        $recipes = get_posts([
            'post_type' => self::CPT,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
        ]);

        $count = 0;
        foreach ($recipes as $recipe_id) {
            $items = get_post_meta($recipe_id, $meta_key, true);
            if (!is_array($items)) {
                continue;
            }
            
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                
                $item_id = isset($item[$field_name]) ? (int) $item[$field_name] : 0;
                if ($item_id === $term_id) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }
}
