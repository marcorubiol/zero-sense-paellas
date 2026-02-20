<?php
declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce\Recipes;

class RecipePostType
{
    public const CPT = 'zs_recipe';
    public const TAX_INGREDIENT = 'zs_ingredient';
    public const TAX_UTENSIL = 'zs_utensil';
    public const TAX_LIQUID = 'zs_liquid';

    public function init(): void
    {
        add_action('init', [$this, 'registerContentTypes']);
    }

    public function registerContentTypes(): void
    {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => __('Recipes', 'zero-sense'),
                'singular_name' => __('Recipe', 'zero-sense'),
                'add_new' => __('Add recipe', 'zero-sense'),
                'add_new_item' => __('Add new recipe', 'zero-sense'),
                'edit_item' => __('Edit recipe', 'zero-sense'),
                'new_item' => __('New recipe', 'zero-sense'),
                'view_item' => __('View recipe', 'zero-sense'),
                'search_items' => __('Search recipes', 'zero-sense'),
                'not_found' => __('No recipes found', 'zero-sense'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=product',
            'menu_position' => 56,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);

        register_taxonomy(self::TAX_INGREDIENT, [self::CPT], [
            'labels' => [
                'name' => __('Ingredients', 'zero-sense'),
                'singular_name' => __('Ingredient', 'zero-sense'),
                'search_items' => __('Search ingredients', 'zero-sense'),
                'all_items' => __('All ingredients', 'zero-sense'),
                'edit_item' => __('Edit ingredient', 'zero-sense'),
                'update_item' => __('Update ingredient', 'zero-sense'),
                'add_new_item' => __('Add new ingredient', 'zero-sense'),
                'new_item_name' => __('New ingredient name', 'zero-sense'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_admin_column' => false,
            'hierarchical' => false,
        ]);

        register_taxonomy(self::TAX_UTENSIL, [self::CPT], [
            'labels' => [
                'name' => __('Utensils', 'zero-sense'),
                'singular_name' => __('Utensil', 'zero-sense'),
                'search_items' => __('Search utensils', 'zero-sense'),
                'all_items' => __('All utensils', 'zero-sense'),
                'edit_item' => __('Edit utensil', 'zero-sense'),
                'update_item' => __('Update utensil', 'zero-sense'),
                'add_new_item' => __('Add new utensil', 'zero-sense'),
                'new_item_name' => __('New utensil name', 'zero-sense'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_admin_column' => false,
            'hierarchical' => false,
        ]);

        register_taxonomy(self::TAX_LIQUID, [self::CPT], [
            'labels' => [
                'name' => __('Liquids', 'zero-sense'),
                'singular_name' => __('Liquid', 'zero-sense'),
                'search_items' => __('Search liquids', 'zero-sense'),
                'all_items' => __('All liquids', 'zero-sense'),
                'edit_item' => __('Edit liquid', 'zero-sense'),
                'update_item' => __('Update liquid', 'zero-sense'),
                'add_new_item' => __('Add new liquid', 'zero-sense'),
                'new_item_name' => __('New liquid name', 'zero-sense'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_admin_column' => false,
            'hierarchical' => false,
        ]);
    }
}
