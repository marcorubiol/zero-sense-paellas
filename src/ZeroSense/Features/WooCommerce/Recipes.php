<?php
namespace ZeroSense\Features\WooCommerce;

use ZeroSense\Core\FeatureInterface;
use WP_Post;
use WP_Term;

class Recipes implements FeatureInterface
{
    private const CPT = 'zs_recipe';
    private const TAX_INGREDIENT = 'zs_ingredient';
    private const TAX_UTENSIL = 'zs_utensil';
    private const TAX_LIQUID = 'zs_liquid';

    private const META_INGREDIENTS = 'zs_recipe_ingredients';
    private const META_UTENSILS = 'zs_recipe_utensils';
    private const META_LIQUIDS = 'zs_recipe_liquids';
    private const META_PRODUCT_RECIPE_ID = 'zs_recipe_id';
    private const META_PRODUCT_RECIPE_NO_RABBIT = 'zs_recipe_id_no_rabbit';
    private const META_NEEDS_PAELLA = 'zs_recipe_needs_paella';

    private const NONCE_FIELD = 'zs_recipe_nonce';
    private const NONCE_ACTION = 'zs_recipe_save';

    public function getName(): string
    {
        return __('Recipes', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Manage reusable recipes and calculate ingredients for events.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isToggleable(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function init(): void
    {
        add_action('init', [$this, 'registerContentTypes']);

        add_action('add_meta_boxes', [$this, 'addRecipeMetabox']);
        add_action('add_meta_boxes', [$this, 'addLinkedProductsMetabox']);
        add_action('add_meta_boxes', [$this, 'removeDefaultMetaboxes']);
        add_action('save_post_' . self::CPT, [$this, 'saveRecipeMetabox'], 10, 2);
        
        // Higher priority to load before asset blockers
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets'], 5);

        add_action('wp_ajax_zs_ingredient_search', [$this, 'ajaxIngredientSearch']);
        add_action('wp_ajax_zs_ingredient_create', [$this, 'ajaxIngredientCreate']);
        add_action('wp_ajax_zs_utensil_search', [$this, 'ajaxUtensilSearch']);
        add_action('wp_ajax_zs_utensil_create', [$this, 'ajaxUtensilCreate']);
        add_action('wp_ajax_zs_liquid_search', [$this, 'ajaxLiquidSearch']);
        add_action('wp_ajax_zs_liquid_create', [$this, 'ajaxLiquidCreate']);

        add_action('woocommerce_product_options_general_product_data', [$this, 'renderProductRecipeField']);
        add_action('woocommerce_admin_process_product_object', [$this, 'saveProductRecipeField']);
        
        // Taxonomy usage protection
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
        
        // Recipe list columns
        add_filter('manage_' . self::CPT . '_posts_columns', [$this, 'addRecipeColumns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'renderRecipeColumn'], 10, 2);
    }

    public function getPriority(): int
    {
        return 30;
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce'];
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

    public function addRecipeMetabox(): void
    {
        add_meta_box(
            'zs_recipe_ingredients',
            __('Recipe', 'zero-sense'),
            [$this, 'renderRecipeMetabox'],
            self::CPT,
            'normal',
            'high'
        );
    }

    public function addLinkedProductsMetabox(): void
    {
        add_meta_box(
            'zs_recipe_linked_products',
            __('Used in Products', 'zero-sense'),
            [$this, 'renderLinkedProductsMetabox'],
            self::CPT,
            'side',
            'default'
        );
    }

    public function renderLinkedProductsMetabox(WP_Post $post): void
    {
        $products = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'numberposts'    => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_key'       => self::META_PRODUCT_RECIPE_ID,
            'meta_value'     => $post->ID,
            'suppress_filters' => true,
        ]);

        echo '<div style="padding:4px 0;">';

        if (empty($products)) {
            echo '<p style="color:#646970; font-size:12px; margin:0;">' . esc_html__('No products linked to this recipe.', 'zero-sense') . '</p>';
        } else {
            echo '<ul style="margin:0; padding:0; list-style:none;">';
            foreach ($products as $product) {
                if (!$product instanceof WP_Post) {
                    continue;
                }
                $editUrl = get_edit_post_link($product->ID);
                $status  = $product->post_status !== 'publish' ? ' <span style="color:#646970; font-size:11px;">(' . esc_html($product->post_status) . ')</span>' : '';
                echo '<li style="padding:4px 0; border-bottom:1px solid #f0f0f1; font-size:13px;">';
                echo '<a href="' . esc_url((string) $editUrl) . '" target="_blank" style="text-decoration:none;">' . esc_html($product->post_title) . '</a>' . $status;
                echo '</li>';
            }
            echo '</ul>';
            echo '<p style="color:#646970; font-size:11px; margin:8px 0 0;">' . sprintf(
                esc_html(_n('%d product', '%d products', count($products), 'zero-sense')),
                count($products)
            ) . '</p>';
        }

        echo '</div>';
    }

    public function removeDefaultMetaboxes(): void
    {
        // Remove default tags metabox for our CPT
        remove_meta_box('tagsdiv-' . self::TAX_INGREDIENT, self::CPT, 'side');
        remove_meta_box('tagsdiv-' . self::TAX_UTENSIL, self::CPT, 'side');
        remove_meta_box('tagsdiv-' . self::TAX_LIQUID, self::CPT, 'side');
    }

    public function renderRecipeMetabox(WP_Post $post): void
    {
        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }

        $ingredients = get_post_meta($post->ID, self::META_INGREDIENTS, true);
        $ingredients = is_array($ingredients) ? $ingredients : [];

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $units = $this->getAllowedUnits();
        $utensilUnits = $this->getUtensilUnits();
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('zs_ingredient_ajax');

        $manage_url = admin_url('edit-tags.php?taxonomy=' . self::TAX_INGREDIENT . '&post_type=' . self::CPT);
        $manage_utensils_url = admin_url('edit-tags.php?taxonomy=' . self::TAX_UTENSIL . '&post_type=' . self::CPT);
        $manage_liquids_url = admin_url('edit-tags.php?taxonomy=' . self::TAX_LIQUID . '&post_type=' . self::CPT);
        
        $utensils = get_post_meta($post->ID, self::META_UTENSILS, true);
        $utensils = is_array($utensils) ? $utensils : [];
        $liquids = get_post_meta($post->ID, self::META_LIQUIDS, true);
        $liquids = is_array($liquids) ? $liquids : [];
        $needsPaella = get_post_meta($post->ID, self::META_NEEDS_PAELLA, true);
        ?>
        <div class="zs-mb-wrapper">
            <!-- Paella Mode Toggle -->
            <div class="zs-paella-mode-toggle">
                <div class="zs-paella-mode-header">
                    <label class="zs-switch">
                        <input type="checkbox" name="zs_recipe_needs_paella" value="1" <?php checked($needsPaella, '1'); ?>>
                        <span class="zs-slider"></span>
                    </label>
                    <strong>🥘 <?php esc_html_e('Paella Recipe Mode', 'zero-sense'); ?></strong>
                </div>
                <div class="zs-paella-mode-info">
                    <p><?php esc_html_e('When enabled, paella pans and burners are automatically calculated by the inventory system based on the number of guests. The utensils section will be hidden as it\'s not needed. Add the liquids used in the recipe so the system can calculate which cassola (pot) is required.', 'zero-sense'); ?></p>
                </div>
            </div>

            <h3 class="zs-mb-subheader" style="font-size:14px; text-transform:none; letter-spacing:0;"><?php esc_html_e('Ingredients', 'zero-sense'); ?></h3>
            <table class="widefat striped" style="margin-top:8px;">
                <thead>
                    <tr>
                        <th style="width: 45%;"><?php esc_html_e('Ingredient', 'zero-sense'); ?></th>
                        <th style="width: 20%;"><?php esc_html_e('Qty per pax', 'zero-sense'); ?></th>
                        <th style="width: 20%;"><?php esc_html_e('Unit', 'zero-sense'); ?></th>
                        <th style="width: 15%;"></th>
                    </tr>
                </thead>
                <tbody id="zs-recipe-rows">
                    <?php 
                    $row_index = 0;
                    foreach ($ingredients as $row): 
                        $termId = isset($row['ingredient']) ? (int) $row['ingredient'] : 0;
                        $qty = isset($row['qty']) ? (string) $row['qty'] : '';
                        $unit = isset($row['unit']) ? (string) $row['unit'] : 'u';

                        $termName = '';
                        if ($termId > 0) {
                            $resolvedId = $this->resolveOriginalTermId($termId);
                            $term = get_term($resolvedId, self::TAX_INGREDIENT);
                            if ($term instanceof WP_Term) {
                                $termName = $term->name;
                            }
                        }
                        ?>
                        <tr data-row="<?php echo $row_index; ?>">
                            <td>
                                <select name="zs_recipe_ingredients[ingredient][]" class="zs-ingredient-select" style="width:100%;" data-placeholder="<?php echo esc_attr(__('Search or create…', 'zero-sense')); ?>">
                                    <?php if ($termId > 0 && $termName !== ''): ?>
                                        <option value="<?php echo esc_attr((string) $termId); ?>" selected="selected"><?php echo esc_html($termName); ?></option>
                                    <?php endif; ?>
                                    <?php 
                                    // Add existing ingredients as fallback options
                                    $existing_ingredients = get_terms([
                                        'taxonomy' => self::TAX_INGREDIENT,
                                        'hide_empty' => false,
                                        'number' => 50,
                                        'suppress_filters' => true,
                                    ]);
                                    if (is_array($existing_ingredients)) {
                                        foreach ($existing_ingredients as $ingredient) {
                                            if ($ingredient instanceof WP_Term && $ingredient->term_id != $termId) {
                                                echo '<option value="' . esc_attr((string) $ingredient->term_id) . '">' . esc_html($ingredient->name) . '</option>';
                                            }
                                        }
                                    }
                                    ?>
                                </select>
                            </td>
                            <td>
                                <input type="number" step="0.001" min="0" name="zs_recipe_ingredients[qty][]" value="<?php echo esc_attr($qty); ?>" style="width:100%;">
                            </td>
                            <td>
                                <select name="zs_recipe_ingredients[unit][]" style="width:100%;">
                                    <?php foreach ($units as $u => $label): ?>
                                        <option value="<?php echo esc_attr($u); ?>" <?php selected($unit, $u); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <button type="button" class="button zs-recipe-remove"><?php esc_html_e('Remove', 'zero-sense'); ?></button>
                            </td>
                        </tr>
                        <?php 
                        $row_index++;
                    endforeach; 
                    ?>
                </tbody>
            </table>
            <p class="zs-mb-row-actions">
                <button type="button" class="button" id="zs-recipe-add-row"><?php esc_html_e('Add ingredient', 'zero-sense'); ?></button>
                <a href="<?php echo esc_url($manage_url); ?>" target="_blank" class="zs-mb-link">
                    <?php esc_html_e('Manage all ingredients', 'zero-sense'); ?> →
                </a>
            </p>

            <!-- Liquids Section (shown only if paella mode is ON) -->
            <div class="zs-liquids-section"<?php echo $needsPaella === '1' ? '' : ' style="display:none;"'; ?>>
                <h3 class="zs-mb-subheader" style="font-size:14px; text-transform:none; letter-spacing:0;"><?php esc_html_e('Liquids', 'zero-sense'); ?></h3>
                <table class="widefat striped" style="margin-top:8px;">
                    <thead>
                        <tr>
                            <th style="width: 70%;"><?php esc_html_e('Liquid', 'zero-sense'); ?></th>
                            <th style="width: 20%;"><?php esc_html_e('Litres per pax', 'zero-sense'); ?></th>
                            <th style="width: 10%;"></th>
                        </tr>
                    </thead>
                    <tbody id="zs-liquid-rows">
                        <?php
                        $liquid_row_index = 0;
                        foreach ($liquids as $row):
                            $termId = isset($row['liquid']) ? (int) $row['liquid'] : 0;
                            $qty = isset($row['qty']) ? (string) $row['qty'] : '';

                            $termName = '';
                            if ($termId > 0) {
                                $resolvedId = $this->resolveOriginalTermId($termId, self::TAX_LIQUID);
                                $term = get_term($resolvedId, self::TAX_LIQUID);
                                if ($term instanceof WP_Term) {
                                    $termName = $term->name;
                                }
                            }
                            ?>
                            <tr data-row="<?php echo $liquid_row_index; ?>">
                                <td>
                                    <select name="zs_recipe_liquids[liquid][]" class="zs-liquid-select" style="width:100%;" data-placeholder="<?php echo esc_attr(__('Search or create…', 'zero-sense')); ?>">
                                        <?php if ($termId > 0 && $termName !== ''): ?>
                                            <option value="<?php echo esc_attr((string) $termId); ?>" selected="selected"><?php echo esc_html($termName); ?></option>
                                        <?php endif; ?>
                                        <?php
                                        $existing_liquids = get_terms([
                                            'taxonomy' => self::TAX_LIQUID,
                                            'hide_empty' => false,
                                            'number' => 50,
                                            'suppress_filters' => true,
                                        ]);
                                        if (is_array($existing_liquids)) {
                                            foreach ($existing_liquids as $liquid) {
                                                if ($liquid instanceof WP_Term && $liquid->term_id != $termId) {
                                                    echo '<option value="' . esc_attr((string) $liquid->term_id) . '">' . esc_html($liquid->name) . '</option>';
                                                }
                                            }
                                        }
                                        ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" step="0.001" min="0" name="zs_recipe_liquids[qty][]" value="<?php echo esc_attr($qty); ?>" style="width:100%;">
                                </td>
                                <td>
                                    <button type="button" class="button zs-liquid-remove"><?php esc_html_e('Remove', 'zero-sense'); ?></button>
                                </td>
                            </tr>
                            <?php
                            $liquid_row_index++;
                        endforeach;
                        ?>
                    </tbody>
                </table>
                <p class="zs-mb-row-actions">
                    <button type="button" class="button" id="zs-liquid-add-row"><?php esc_html_e('Add liquid', 'zero-sense'); ?></button>
                    <a href="<?php echo esc_url($manage_liquids_url); ?>" target="_blank" class="zs-mb-link">
                        <?php esc_html_e('Manage all liquids', 'zero-sense'); ?> →
                    </a>
                </p>
            </div>

            <!-- Utensils Section (hidden if paella mode is ON) -->
            <div class="zs-utensils-section"<?php echo $needsPaella === '1' ? ' style="display:none;"' : ''; ?>>
                <h3 class="zs-mb-subheader" style="font-size:14px; text-transform:none; letter-spacing:0;"><?php esc_html_e('Utensils', 'zero-sense'); ?></h3>
                <table class="widefat striped" style="margin-top:8px;">
                    <thead>
                        <tr>
                            <th style="width: 35%;"><?php esc_html_e('Utensil', 'zero-sense'); ?></th>
                            <th style="width: 15%;"><?php esc_html_e('Qty', 'zero-sense'); ?></th>
                            <th style="width: 20%;"><?php esc_html_e('Every X people', 'zero-sense'); ?></th>
                            <th style="width: 15%;"><?php esc_html_e('Unit', 'zero-sense'); ?></th>
                            <th style="width: 15%;"></th>
                        </tr>
                    </thead>
                    <tbody id="zs-utensil-rows">
                        <?php 
                        $utensil_row_index = 0;
                        foreach ($utensils as $row): 
                            $termId = isset($row['utensil']) ? (int) $row['utensil'] : 0;
                            $qty = isset($row['qty']) ? (string) $row['qty'] : '';
                            $paxRatio = isset($row['pax_ratio']) ? (int) $row['pax_ratio'] : 1;
                            $unit = isset($row['unit']) ? (string) $row['unit'] : 'u';

                            $termName = '';
                            if ($termId > 0) {
                                $resolvedId = $this->resolveOriginalTermId($termId, self::TAX_UTENSIL);
                                $term = get_term($resolvedId, self::TAX_UTENSIL);
                                if ($term instanceof WP_Term) {
                                    $termName = $term->name;
                                }
                            }
                            ?>
                            <tr data-row="<?php echo $utensil_row_index; ?>">
                                <td>
                                    <select name="zs_recipe_utensils[utensil][]" class="zs-utensil-select" style="width:100%;" data-placeholder="<?php echo esc_attr(__('Search or create…', 'zero-sense')); ?>">
                                        <?php if ($termId > 0 && $termName !== ''): ?>
                                        <option value="<?php echo esc_attr((string) $termId); ?>" selected="selected"><?php echo esc_html($termName); ?></option>
                                    <?php endif; ?>
                                    <?php 
                                    $existing_utensils = get_terms([
                                        'taxonomy' => self::TAX_UTENSIL,
                                        'hide_empty' => false,
                                        'number' => 50,
                                        'suppress_filters' => true,
                                    ]);
                                    if (is_array($existing_utensils)) {
                                        foreach ($existing_utensils as $utensil) {
                                            if ($utensil instanceof WP_Term && $utensil->term_id != $termId) {
                                                echo '<option value="' . esc_attr((string) $utensil->term_id) . '">' . esc_html($utensil->name) . '</option>';
                                            }
                                        }
                                    }
                                    ?>
                                </select>
                            </td>
                            <td>
                                <input type="number" step="0.001" min="0" name="zs_recipe_utensils[qty][]" value="<?php echo esc_attr($qty); ?>" style="width:100%;">
                            </td>
                            <td>
                                <input type="number" step="1" min="1" name="zs_recipe_utensils[pax_ratio][]" value="<?php echo esc_attr($paxRatio); ?>" style="width:100%;" placeholder="1">
                            </td>
                            <td>
                                <select name="zs_recipe_utensils[unit][]" style="width:100%;">
                                    <?php foreach ($utensilUnits as $u => $label): ?>
                                        <option value="<?php echo esc_attr($u); ?>" <?php selected($unit, $u); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <button type="button" class="button zs-utensil-remove"><?php esc_html_e('Remove', 'zero-sense'); ?></button>
                            </td>
                        </tr>
                            <?php 
                            $utensil_row_index++;
                        endforeach; 
                        ?>
                    </tbody>
                </table>

                <p class="zs-mb-row-actions">
                    <button type="button" class="button" id="zs-utensil-add-row"><?php esc_html_e('Add utensil', 'zero-sense'); ?></button>
                    <a href="<?php echo esc_url($manage_utensils_url); ?>" target="_blank" class="zs-mb-link">
                        <?php esc_html_e('Manage all utensils', 'zero-sense'); ?> →
                    </a>
                </p>
                <p class="zs-mb-description" style="font-style: italic;">
                    <?php esc_html_e('"Every X people" = ratio (1 = per person, 4 = every 4 people, 10 = every 10 people)', 'zero-sense'); ?>
                </p>
            </div>
        </div>

        <script>
        (function($) {
            var ajaxUrl = '<?php echo $ajax_url; ?>';
            var nonce = '<?php echo $nonce; ?>';
            var rowCount = <?php echo max(0, count($ingredients)); ?>;
            
            function initSelect(element) {
                if (typeof jQuery.fn.selectWoo === 'undefined') {
                    return;
                }
                
                if (!$(element).data('select2')) {
                    $(element).selectWoo({
                        width: '100%',
                        tags: true,
                        tokenSeparators: [','],
                        createTag: function(params) {
                            var term = $.trim(params.term);
                            if (term === '') return null;
                            return {
                                id: term,
                                text: term + ' (crear nuevo)',
                                newTag: true
                            };
                        },
                        insertTag: function(data, tag) {
                            data.push(tag);
                        },
                        ajax: {
                            url: ajaxUrl,
                            dataType: 'json',
                            delay: 250,
                            data: function(params) {
                                // Get already selected ingredient IDs
                                var selectedIds = [];
                                $('.zs-ingredient-select').each(function() {
                                    var val = $(this).val();
                                    if (val && !isNaN(val)) {
                                        selectedIds.push(val);
                                    }
                                });
                                
                                return {
                                    action: 'zs_ingredient_search',
                                    nonce: nonce,
                                    q: params.term || '',
                                    exclude: selectedIds.join(',')
                                };
                            },
                            processResults: function(data) {
                                return data;
                            },
                            transport: function(params, success, failure) {
                                var $request = $.ajax(params);
                                $request.then(success);
                                $request.fail(function(jqXHR, textStatus) {
                                    if (textStatus !== 'abort') failure();
                                });
                                return $request;
                            }
                        }
                    });
                    
                    $(element).on('select2:closing', function() {
                        setTimeout(function() {
                            var val = $(element).val();
                            if (val && typeof val === 'string' && isNaN(val)) {
                                $(element).val(null).trigger('change.select2');
                                createIngredient(val, element);
                            }
                        }, 100);
                    });
                }
            }
            
            function createIngredient(name, selectElement) {
                $.ajax({
                    url: ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'zs_ingredient_create',
                        nonce: nonce,
                        name: name
                    },
                    success: function(resp) {
                        if (resp && resp.success && resp.data) {
                            $(selectElement).empty();
                            var option = new Option(resp.data.text, resp.data.id, true, true);
                            $(selectElement).append(option).trigger('change');
                        } else {
                            alert('Error al crear el ingrediente.');
                        }
                    },
                    error: function() {
                        alert('Error de conexión al crear el ingrediente.');
                    }
                });
            }
            
            function addNewRow() {
                var units = <?php echo json_encode(array_keys($this->getAllowedUnits())); ?>;
                var unitLabels = <?php echo json_encode(array_values($this->getAllowedUnits())); ?>;
                
                var unitOptions = '';
                for (var i = 0; i < units.length; i++) {
                    unitOptions += '<option value="' + units[i] + '">' + unitLabels[i] + '</option>';
                }
                
                var newRow = '<tr data-row="' + rowCount + '">' +
                    '<td>' +
                        '<select name="zs_recipe_ingredients[ingredient][]" class="zs-ingredient-select" style="width:100%;" data-placeholder="<?php echo esc_js(__('Search or create…', 'zero-sense')); ?>"></select>' +
                    '</td>' +
                    '<td><input type="number" step="0.001" min="0" name="zs_recipe_ingredients[qty][]" value="" style="width:100%;"></td>' +
                    '<td>' +
                        '<select name="zs_recipe_ingredients[unit][]" style="width:100%;">' + unitOptions + '</select>' +
                    '</td>' +
                    '<td><button type="button" class="button zs-recipe-remove"><?php echo esc_js(__('Remove', 'zero-sense')); ?></button></td>' +
                '</tr>';
                
                $('#zs-recipe-rows').append(newRow);
                initSelect($('#zs-recipe-rows tr:last .zs-ingredient-select'));
                rowCount++;
            }
            
            // Initialize existing selects
            $(document).ready(function() {
                $('.zs-ingredient-select').each(function() {
                    initSelect(this);
                });
            });
            
            // Add row button
            $('#zs-recipe-add-row').on('click', function() {
                addNewRow();
            });
            
            // Remove buttons
            $(document).on('click', '.zs-recipe-remove', function() {
                $(this).closest('tr').remove();
            });
            
            // UTENSILS SECTION
            var utensilRowCount = <?php echo max(0, count($utensils)); ?>;
            
            function initUtensilSelect(element) {
                if (typeof jQuery.fn.selectWoo === 'undefined') {
                    return;
                }
                
                if (!$(element).data('select2')) {
                    $(element).selectWoo({
                        width: '100%',
                        tags: true,
                        tokenSeparators: [','],
                        createTag: function(params) {
                            var term = $.trim(params.term);
                            if (term === '') return null;
                            return {
                                id: term,
                                text: term + ' (crear nuevo)',
                                newTag: true
                            };
                        },
                        insertTag: function(data, tag) {
                            data.push(tag);
                        },
                        ajax: {
                            url: ajaxUrl,
                            dataType: 'json',
                            delay: 250,
                            data: function(params) {
                                // Get already selected utensil IDs
                                var selectedIds = [];
                                $('.zs-utensil-select').each(function() {
                                    var val = $(this).val();
                                    if (val && !isNaN(val)) {
                                        selectedIds.push(val);
                                    }
                                });
                                
                                return {
                                    action: 'zs_utensil_search',
                                    nonce: nonce,
                                    q: params.term || '',
                                    exclude: selectedIds.join(',')
                                };
                            },
                            processResults: function(data) {
                                return data;
                            },
                            transport: function(params, success, failure) {
                                var $request = $.ajax(params);
                                $request.then(success);
                                $request.fail(function(jqXHR, textStatus) {
                                    if (textStatus !== 'abort') failure();
                                });
                                return $request;
                            }
                        }
                    });
                    
                    $(element).on('select2:closing', function() {
                        setTimeout(function() {
                            var val = $(element).val();
                            if (val && typeof val === 'string' && isNaN(val)) {
                                $(element).val(null).trigger('change.select2');
                                createUtensil(val, element);
                            }
                        }, 100);
                    });
                }
            }
            
            function createUtensil(name, selectElement) {
                $.ajax({
                    url: ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'zs_utensil_create',
                        nonce: nonce,
                        name: name
                    },
                    success: function(resp) {
                        if (resp && resp.success && resp.data) {
                            $(selectElement).empty();
                            var option = new Option(resp.data.text, resp.data.id, true, true);
                            $(selectElement).append(option).trigger('change');
                        } else {
                            alert('Error al crear el utensilio.');
                        }
                    },
                    error: function() {
                        alert('Error de conexión al crear el utensilio.');
                    }
                });
            }
            
            function addNewUtensilRow() {
                var units = <?php echo json_encode(array_keys($this->getUtensilUnits())); ?>;
                var unitLabels = <?php echo json_encode(array_values($this->getUtensilUnits())); ?>;
                
                var unitOptions = '';
                for (var i = 0; i < units.length; i++) {
                    unitOptions += '<option value="' + units[i] + '">' + unitLabels[i] + '</option>';
                }
                
                var newRow = '<tr data-row="' + utensilRowCount + '">' +
                    '<td>' +
                        '<select name="zs_recipe_utensils[utensil][]" class="zs-utensil-select" style="width:100%;" data-placeholder="<?php echo esc_js(__('Search or create…', 'zero-sense')); ?>"></select>' +
                    '</td>' +
                    '<td><input type="number" step="0.001" min="0" name="zs_recipe_utensils[qty][]" value="" style="width:100%;"></td>' +
                    '<td><input type="number" step="1" min="1" name="zs_recipe_utensils[pax_ratio][]" value="1" style="width:100%;" placeholder="1"></td>' +
                    '<td>' +
                        '<select name="zs_recipe_utensils[unit][]" style="width:100%;">' + unitOptions + '</select>' +
                    '</td>' +
                    '<td><button type="button" class="button zs-utensil-remove"><?php echo esc_js(__('Remove', 'zero-sense')); ?></button></td>' +
                '</tr>';
                
                $('#zs-utensil-rows').append(newRow);
                initUtensilSelect($('#zs-utensil-rows tr:last .zs-utensil-select'));
                utensilRowCount++;
            }
            
            // Initialize existing utensil selects
            $(document).ready(function() {
                $('.zs-utensil-select').each(function() {
                    initUtensilSelect(this);
                });
            });
            
            // Add utensil row button
            $('#zs-utensil-add-row').on('click', function() {
                addNewUtensilRow();
            });
            
            // Remove utensil buttons
            $(document).on('click', '.zs-utensil-remove', function() {
                $(this).closest('tr').remove();
            });
            
            // LIQUIDS SECTION
            var liquidRowCount = <?php echo max(0, count($liquids)); ?>;

            function initLiquidSelect(element) {
                if (typeof jQuery.fn.selectWoo === 'undefined') {
                    return;
                }
                if (!$(element).data('select2')) {
                    $(element).selectWoo({
                        width: '100%',
                        tags: true,
                        tokenSeparators: [','],
                        createTag: function(params) {
                            var term = $.trim(params.term);
                            if (term === '') return null;
                            return { id: term, text: term + ' (crear nuevo)', newTag: true };
                        },
                        insertTag: function(data, tag) { data.push(tag); },
                        ajax: {
                            url: ajaxUrl,
                            dataType: 'json',
                            delay: 250,
                            data: function(params) {
                                var selectedIds = [];
                                $('.zs-liquid-select').each(function() {
                                    var val = $(this).val();
                                    if (val && !isNaN(val)) selectedIds.push(val);
                                });
                                return { action: 'zs_liquid_search', nonce: nonce, q: params.term || '', exclude: selectedIds.join(',') };
                            },
                            processResults: function(data) { return data; },
                            transport: function(params, success, failure) {
                                var $request = $.ajax(params);
                                $request.then(success);
                                $request.fail(function(jqXHR, textStatus) { if (textStatus !== 'abort') failure(); });
                                return $request;
                            }
                        }
                    });
                    $(element).on('select2:closing', function() {
                        setTimeout(function() {
                            var val = $(element).val();
                            if (val && typeof val === 'string' && isNaN(val)) {
                                $(element).val(null).trigger('change.select2');
                                createLiquid(val, element);
                            }
                        }, 100);
                    });
                }
            }

            function createLiquid(name, selectElement) {
                $.ajax({
                    url: ajaxUrl, method: 'POST',
                    data: { action: 'zs_liquid_create', nonce: nonce, name: name },
                    success: function(resp) {
                        if (resp && resp.success && resp.data) {
                            $(selectElement).empty();
                            var option = new Option(resp.data.text, resp.data.id, true, true);
                            $(selectElement).append(option).trigger('change');
                        } else { alert('Error creating liquid.'); }
                    },
                    error: function() { alert('Connection error creating liquid.'); }
                });
            }

            function addNewLiquidRow() {
                var newRow = '<tr data-row="' + liquidRowCount + '">' +
                    '<td><select name="zs_recipe_liquids[liquid][]" class="zs-liquid-select" style="width:100%;" data-placeholder="<?php echo esc_js(__('Search or create…', 'zero-sense')); ?>"></select></td>' +
                    '<td><input type="number" step="0.001" min="0" name="zs_recipe_liquids[qty][]" value="" style="width:100%;"></td>' +
                    '<td><button type="button" class="button zs-liquid-remove"><?php echo esc_js(__('Remove', 'zero-sense')); ?></button></td>' +
                    '</tr>';
                $('#zs-liquid-rows').append(newRow);
                initLiquidSelect($('#zs-liquid-rows tr:last .zs-liquid-select'));
                liquidRowCount++;
            }

            $(document).ready(function() {
                $('.zs-liquid-select').each(function() { initLiquidSelect(this); });
            });

            $('#zs-liquid-add-row').on('click', function() { addNewLiquidRow(); });
            $(document).on('click', '.zs-liquid-remove', function() { $(this).closest('tr').remove(); });

            // Paella mode toggle
            $('input[name="zs_recipe_needs_paella"]').on('change', function() {
                if ($(this).is(':checked')) {
                    $('.zs-utensils-section').slideUp(300);
                    $('.zs-liquids-section').slideDown(300);
                } else {
                    $('.zs-utensils-section').slideDown(300);
                    $('.zs-liquids-section').slideUp(300);
                }
            });
            
        })(jQuery);
        </script>
        <?php
    }

    public function saveRecipeMetabox(int $postId, WP_Post $post): void
    {
        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        if (!isset($_POST[self::NONCE_FIELD]) || !wp_verify_nonce((string) $_POST[self::NONCE_FIELD], self::NONCE_ACTION)) {
            return;
        }

        $raw = $_POST['zs_recipe_ingredients'] ?? null;
        if (!is_array($raw)) {
            delete_post_meta($postId, self::META_INGREDIENTS);
            return;
        }

        $ids = isset($raw['ingredient']) && is_array($raw['ingredient']) ? $raw['ingredient'] : [];
        $qtys = isset($raw['qty']) && is_array($raw['qty']) ? $raw['qty'] : [];
        $units = isset($raw['unit']) && is_array($raw['unit']) ? $raw['unit'] : [];

        $allowedUnits = array_keys($this->getAllowedUnits());
        $allowedUtensilUnits = array_keys($this->getUtensilUnits());

        // Save paella checkbox
        if (isset($_POST['zs_recipe_needs_paella']) && $_POST['zs_recipe_needs_paella'] === '1') {
            update_post_meta($postId, self::META_NEEDS_PAELLA, '1');
        } else {
            delete_post_meta($postId, self::META_NEEDS_PAELLA);
        }

        $out = [];
        $count = max(count($ids), count($qtys), count($units));
        for ($i = 0; $i < $count; $i++) {
            $id = isset($ids[$i]) ? (int) $ids[$i] : 0;
            $qty = isset($qtys[$i]) ? (float) $qtys[$i] : 0.0;
            $unit = isset($units[$i]) ? sanitize_key((string) $units[$i]) : 'u';

            if ($id <= 0 || $qty <= 0) {
                continue;
            }

            if (!in_array($unit, $allowedUnits, true)) {
                $unit = 'u';
            }

            $out[] = [
                'ingredient' => $id,
                'qty' => $qty,
                'unit' => $unit,
            ];
        }

        if ($out === []) {
            delete_post_meta($postId, self::META_INGREDIENTS);
        } else {
            update_post_meta($postId, self::META_INGREDIENTS, $out);
        }

        $isPaellaMode = isset($_POST['zs_recipe_needs_paella']) && $_POST['zs_recipe_needs_paella'] === '1';

        // Save utensils — only when section is visible (paella mode OFF)
        if (!$isPaellaMode) {
            $rawUtensils = $_POST['zs_recipe_utensils'] ?? null;
            if (is_array($rawUtensils)) {
                $utensilIds = isset($rawUtensils['utensil']) && is_array($rawUtensils['utensil']) ? $rawUtensils['utensil'] : [];
                $utensilQtys = isset($rawUtensils['qty']) && is_array($rawUtensils['qty']) ? $rawUtensils['qty'] : [];
                $utensilPaxRatios = isset($rawUtensils['pax_ratio']) && is_array($rawUtensils['pax_ratio']) ? $rawUtensils['pax_ratio'] : [];
                $utensilUnits = isset($rawUtensils['unit']) && is_array($rawUtensils['unit']) ? $rawUtensils['unit'] : [];

                $outUtensils = [];
                $countUtensils = max(count($utensilIds), count($utensilQtys), count($utensilPaxRatios), count($utensilUnits));
                for ($i = 0; $i < $countUtensils; $i++) {
                    $id = isset($utensilIds[$i]) ? (int) $utensilIds[$i] : 0;
                    $qty = isset($utensilQtys[$i]) ? (float) $utensilQtys[$i] : 0.0;
                    $paxRatio = isset($utensilPaxRatios[$i]) ? (int) $utensilPaxRatios[$i] : 1;
                    $unit = isset($utensilUnits[$i]) ? (string) $utensilUnits[$i] : 'u';

                    if ($id <= 0 || $qty <= 0 || $paxRatio < 1) {
                        continue;
                    }

                    if (!in_array($unit, $allowedUtensilUnits, true)) {
                        $unit = 'u';
                    }

                    $outUtensils[] = [
                        'utensil' => $id,
                        'qty' => $qty,
                        'pax_ratio' => $paxRatio,
                        'unit' => $unit,
                    ];
                }

                if ($outUtensils === []) {
                    delete_post_meta($postId, self::META_UTENSILS);
                } else {
                    update_post_meta($postId, self::META_UTENSILS, $outUtensils);
                }
            } else {
                delete_post_meta($postId, self::META_UTENSILS);
            }
        }

        // Save liquids — only when section is visible (paella mode ON)
        if ($isPaellaMode) {
            $rawLiquids = $_POST['zs_recipe_liquids'] ?? null;
            $liquidIds = isset($rawLiquids['liquid']) && is_array($rawLiquids['liquid']) ? $rawLiquids['liquid'] : [];
            $liquidQtys = isset($rawLiquids['qty']) && is_array($rawLiquids['qty']) ? $rawLiquids['qty'] : [];

            $outLiquids = [];
            $countLiquids = max(count($liquidIds), count($liquidQtys));
            for ($i = 0; $i < $countLiquids; $i++) {
                $id = isset($liquidIds[$i]) ? (int) $liquidIds[$i] : 0;
                $qty = isset($liquidQtys[$i]) ? (float) $liquidQtys[$i] : 0.0;

                if ($id <= 0 || $qty <= 0) {
                    continue;
                }

                $outLiquids[] = [
                    'liquid' => $id,
                    'qty' => $qty,
                ];
            }

            if ($outLiquids === []) {
                delete_post_meta($postId, self::META_LIQUIDS);
            } else {
                update_post_meta($postId, self::META_LIQUIDS, $outLiquids);
            }
        }
    }

    public function enqueueAdminAssets(string $hook): void
    {
        if (!function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        if ($screen->post_type === self::CPT) {
            wp_enqueue_style(
                'zs-admin-components',
                plugin_dir_url(dirname(dirname(dirname(dirname(__FILE__))))) . 'assets/css/admin-components.css',
                [],
                '1.0'
            );

            // Force enqueue WooCommerce styles
            wp_enqueue_style('woocommerce_admin_styles');
            
            // Force enqueue selectWoo with higher priority
            wp_enqueue_script('selectWoo');
            
            // Also enqueue jQuery UI (selectWoo dependency)
            wp_enqueue_script('jquery-ui-core');
            wp_enqueue_script('jquery-ui-widget');
            wp_enqueue_script('jquery-ui-autocomplete');
        }
    }

    public function ajaxIngredientSearch(): void
    {
        if (!current_user_can('edit_posts')) {
            wp_send_json(['results' => []]);
        }

        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash((string) $_GET['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'zs_ingredient_ajax')) {
            wp_send_json(['results' => []]);
        }

        $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
        
        // Get excluded IDs (already selected)
        $exclude = isset($_GET['exclude']) ? sanitize_text_field(wp_unslash((string) $_GET['exclude'])) : '';
        $excludeIds = array_filter(array_map('intval', explode(',', $exclude)));

        // Get all ingredients (we'll filter manually for case-insensitive search)
        $terms = get_terms([
            'taxonomy' => self::TAX_INGREDIENT,
            'hide_empty' => false,
            'number' => 100,
            'suppress_filters' => true,
        ]);

        $results = [];
        if (is_array($terms) && $q !== '') {
            // Normalize search query
            $normalizedQuery = $this->normalizeIngredientName($q);
            
            foreach ($terms as $term) {
                if ($term instanceof WP_Term) {
                    // Skip if already selected
                    if (in_array($term->term_id, $excludeIds, true)) {
                        continue;
                    }
                    
                    // Normalize term name for comparison
                    $normalizedTermName = $this->normalizeIngredientName($term->name);
                    
                    // Case-insensitive search
                    if (mb_strpos($normalizedTermName, $normalizedQuery, 0, 'UTF-8') !== false) {
                        $results[] = ['id' => (string) $term->term_id, 'text' => $term->name];
                    }
                }
            }
        } elseif (is_array($terms) && $q === '') {
            // Empty query - return all ingredients
            foreach ($terms as $term) {
                if ($term instanceof WP_Term) {
                    // Skip if already selected
                    if (in_array($term->term_id, $excludeIds, true)) {
                        continue;
                    }
                    
                    $results[] = ['id' => (string) $term->term_id, 'text' => $term->name];
                }
            }
        }

        // Limit results to 25
        $results = array_slice($results, 0, 25);

        wp_send_json([
            'results' => $results,
        ]);
    }

    public function ajaxIngredientCreate(): void
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

        // Normalize for searching (lowercase, trim, clean spaces)
        $normalizedName = $this->normalizeIngredientName($rawName);
        
        // Capitalize for display (Title Case)
        $displayName = $this->capitalizeIngredientName($rawName);

        // Search for existing ingredient using normalized name
        $terms = get_terms([
            'taxonomy' => self::TAX_INGREDIENT,
            'hide_empty' => false,
            'number' => 100,
            'suppress_filters' => true,
        ]);

        // Check if normalized version already exists
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

        // Create new ingredient with capitalized name
        $created = wp_insert_term($displayName, self::TAX_INGREDIENT);
        
        if (is_wp_error($created)) {
            
            // Check if error is because term already exists
            if ($created->get_error_code() === 'term_exists') {
                $termId = $created->get_error_data('term_exists');
                if ($termId) {
                    $term = get_term($termId, self::TAX_INGREDIENT);
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

    public function ajaxUtensilSearch(): void
    {
        if (!current_user_can('edit_posts')) {
            wp_send_json(['results' => []]);
        }

        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash((string) $_GET['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'zs_ingredient_ajax')) {
            wp_send_json(['results' => []]);
        }

        $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
        
        // Get excluded IDs (already selected)
        $exclude = isset($_GET['exclude']) ? sanitize_text_field(wp_unslash((string) $_GET['exclude'])) : '';
        $excludeIds = array_filter(array_map('intval', explode(',', $exclude)));

        $terms = get_terms([
            'taxonomy' => self::TAX_UTENSIL,
            'hide_empty' => false,
            'number' => 100,
            'suppress_filters' => true,
        ]);

        $results = [];
        if (is_array($terms) && $q !== '') {
            $normalizedQuery = $this->normalizeIngredientName($q);
            
            foreach ($terms as $term) {
                if ($term instanceof WP_Term) {
                    // Skip if already selected
                    if (in_array($term->term_id, $excludeIds, true)) {
                        continue;
                    }
                    
                    $normalizedTermName = $this->normalizeIngredientName($term->name);
                    
                    if (mb_strpos($normalizedTermName, $normalizedQuery, 0, 'UTF-8') !== false) {
                        $results[] = ['id' => (string) $term->term_id, 'text' => $term->name];
                    }
                }
            }
        } elseif (is_array($terms) && $q === '') {
            foreach ($terms as $term) {
                if ($term instanceof WP_Term) {
                    // Skip if already selected
                    if (in_array($term->term_id, $excludeIds, true)) {
                        continue;
                    }
                    
                    $results[] = ['id' => (string) $term->term_id, 'text' => $term->name];
                }
            }
        }

        $results = array_slice($results, 0, 25);

        wp_send_json([
            'results' => $results,
        ]);
    }

    public function ajaxUtensilCreate(): void
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
            'taxonomy' => self::TAX_UTENSIL,
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

        $created = wp_insert_term($displayName, self::TAX_UTENSIL);
        
        if (is_wp_error($created)) {
            if ($created->get_error_code() === 'term_exists') {
                $termId = $created->get_error_data('term_exists');
                if ($termId) {
                    $term = get_term($termId, self::TAX_UTENSIL);
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

    public function ajaxLiquidSearch(): void
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
            'taxonomy' => self::TAX_LIQUID,
            'hide_empty' => false,
            'number' => 100,
            'suppress_filters' => true,
        ]);

        $results = [];
        if (is_array($terms) && $q !== '') {
            $normalizedQuery = $this->normalizeIngredientName($q);
            foreach ($terms as $term) {
                if ($term instanceof WP_Term) {
                    if (in_array($term->term_id, $excludeIds, true)) continue;
                    if (mb_strpos($this->normalizeIngredientName($term->name), $normalizedQuery, 0, 'UTF-8') !== false) {
                        $results[] = ['id' => (string) $term->term_id, 'text' => $term->name];
                    }
                }
            }
        } elseif (is_array($terms)) {
            foreach ($terms as $term) {
                if ($term instanceof WP_Term) {
                    if (in_array($term->term_id, $excludeIds, true)) continue;
                    $results[] = ['id' => (string) $term->term_id, 'text' => $term->name];
                }
            }
        }

        wp_send_json(['results' => array_slice($results, 0, 25)]);
    }

    public function ajaxLiquidCreate(): void
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
            'taxonomy' => self::TAX_LIQUID,
            'hide_empty' => false,
            'number' => 100,
            'suppress_filters' => true,
        ]);

        if (is_array($terms)) {
            foreach ($terms as $term) {
                if ($term instanceof WP_Term) {
                    if ($this->normalizeIngredientName($term->name) === $normalizedName) {
                        wp_send_json_success(['id' => (int) $term->term_id, 'text' => $term->name]);
                    }
                }
            }
        }

        $created = wp_insert_term($displayName, self::TAX_LIQUID);

        if (is_wp_error($created)) {
            if ($created->get_error_code() === 'term_exists') {
                $termId = $created->get_error_data('term_exists');
                if ($termId) {
                    $term = get_term($termId, self::TAX_LIQUID);
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

    public function renderProductRecipeField(): void
    {
        global $post;
        if (!$post instanceof WP_Post) {
            return;
        }

        $productId = $this->resolveOriginalProductId($post->ID);
        $current = (int) get_post_meta($productId, self::META_PRODUCT_RECIPE_ID, true);

        $recipes = get_posts([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'numberposts' => 200,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => true,
        ]);

        echo '<div class="options_group" style="border-bottom:0;">';
        echo '<p class="form-field">';
        echo '<label for="zs_recipe_id">' . esc_html__('Recipe', 'zero-sense') . '</label>';
        echo '<select id="zs_recipe_id" name="zs_recipe_id" class="wc-enhanced-select" style="width:50%;">';
        echo '<option value="">' . esc_html__('None', 'zero-sense') . '</option>';

        foreach ($recipes as $recipe) {
            if (!$recipe instanceof WP_Post) {
                continue;
            }
            $id = (int) $recipe->ID;
            echo '<option value="' . esc_attr((string) $id) . '"' . selected($current, $id, false) . '>' . esc_html($recipe->post_title) . '</option>';
        }

        echo '<option value="__new__" class="zs-recipe-new-option">&#43; ' . esc_html__('Add New Recipe', 'zero-sense') . '</option>';
        echo '</select>';
        echo '</p>';
        
        // Edit action — only shown when a real recipe is selected
        $editUrl = $current > 0 ? admin_url('post.php?post=' . $current . '&action=edit') : '#';
        echo '<p id="zs-recipe-context-actions" style="margin-top:-15px; padding-left:162px; display:' . ($current > 0 ? 'block' : 'none') . ';">';
        echo '<a id="zs-recipe-edit-btn" href="' . esc_url($editUrl) . '" target="_blank" style="font-size:12px; text-decoration:none; color:#2271b1;">';
        echo '<span class="dashicons dashicons-edit" style="font-size:13px; line-height:1.6; vertical-align:middle; margin-top: -4px; margin-right:2px;"></span>';
        echo esc_html__('Edit Recipe', 'zero-sense');
        echo '</a>';
        echo '</p>';
        
        echo '</div>';

        // --- Recipe without rabbit (conditional on _zs_has_rabbit_option) ---
        $hasRabbit = get_post_meta($productId, '_zs_has_rabbit_option', true) === 'yes';
        $currentNoRabbit = (int) get_post_meta($productId, self::META_PRODUCT_RECIPE_NO_RABBIT, true);

        echo '<div class="options_group" id="zs-recipe-no-rabbit-group" style="border-top:0;' . ($hasRabbit ? '' : 'display:none;') . '">';
        echo '<p class="form-field">';
        echo '<label for="zs_recipe_id_no_rabbit">' . esc_html__('Recipe without rabbit', 'zero-sense') . '</label>';
        echo '<select id="zs_recipe_id_no_rabbit" name="zs_recipe_id_no_rabbit" class="wc-enhanced-select" style="width:50%;">';
        echo '<option value="">' . esc_html__('None', 'zero-sense') . '</option>';

        foreach ($recipes as $recipe) {
            if (!$recipe instanceof WP_Post) {
                continue;
            }
            $id = (int) $recipe->ID;
            echo '<option value="' . esc_attr((string) $id) . '"' . selected($currentNoRabbit, $id, false) . '>' . esc_html($recipe->post_title) . '</option>';
        }

        echo '<option value="__new__" class="zs-recipe-new-option">&#43; ' . esc_html__('Add New Recipe', 'zero-sense') . '</option>';
        echo '</select>';
        echo '</p>';

        // Edit action — only shown when a real recipe is selected
        $editUrlNoRabbit = $currentNoRabbit > 0 ? admin_url('post.php?post=' . $currentNoRabbit . '&action=edit') : '#';
        echo '<p id="zs-recipe-no-rabbit-context-actions" style="margin-top:-15px; padding-left:162px; display:' . ($currentNoRabbit > 0 ? 'block' : 'none') . ';">';
        echo '<a id="zs-recipe-no-rabbit-edit-btn" href="' . esc_url($editUrlNoRabbit) . '" target="_blank" style="font-size:12px; text-decoration:none; color:#2271b1;">';
        echo '<span class="dashicons dashicons-edit" style="font-size:13px; line-height:1.6; vertical-align:middle; margin-top: -4px;margin-right:2px;"></span>';
        echo esc_html__('Edit Recipe', 'zero-sense');
        echo '</a>';
        echo '</p>';

        echo '</div>';

        $baseEditUrl = esc_js(admin_url('post.php'));
        ?>
        <script>
        jQuery(document).ready(function($) {
            var $select  = $('#zs_recipe_id');
            var $actions = $('#zs-recipe-context-actions');
            var $editBtn = $('#zs-recipe-edit-btn');
            var baseEditUrl = '<?php echo $baseEditUrl; ?>?post=';

            function toggleActions(recipeId) {
                recipeId = parseInt(recipeId || $select.val() || 0);
                if (recipeId > 0) {
                    $editBtn.attr('href', baseEditUrl + recipeId + '&action=edit').show();
                    $actions.slideDown(200);
                } else {
                    $editBtn.hide();
                    $actions.slideUp(200);
                }
            }

            var newUrl = '<?php echo esc_js(admin_url('post-new.php?post_type=zs_recipe')); ?>';

            $select.on('change select2:select select2:unselect', function() {
                var val = $(this).val();
                if (val === '__new__') {
                    window.open(newUrl, '_blank');
                    $select.val('').trigger('change.select2');
                    return;
                }
                toggleActions(val);
            });
            toggleActions();

            // No-rabbit select: Edit link + Add New
            var $selectNR  = $('#zs_recipe_id_no_rabbit');
            var $actionsNR = $('#zs-recipe-no-rabbit-context-actions');
            var $editBtnNR = $('#zs-recipe-no-rabbit-edit-btn');

            function toggleActionsNR(recipeId) {
                recipeId = parseInt(recipeId || $selectNR.val() || 0);
                if (recipeId > 0) {
                    $editBtnNR.attr('href', baseEditUrl + recipeId + '&action=edit').show();
                    $actionsNR.slideDown(200);
                } else {
                    $editBtnNR.hide();
                    $actionsNR.slideUp(200);
                }
            }

            $selectNR.on('change select2:select select2:unselect', function() {
                var val = $(this).val();
                if (val === '__new__') {
                    window.open(newUrl, '_blank');
                    $selectNR.val('').trigger('change.select2');
                    return;
                }
                toggleActionsNR(val);
            });
            toggleActionsNR();

            // Show/hide "Recipe without rabbit" based on rabbit option checkbox
            var $rabbitCheck = $('#_zs_has_rabbit_option');
            var $noRabbitGroup = $('#zs-recipe-no-rabbit-group');

            function toggleNoRabbitField() {
                if ($rabbitCheck.is(':checked')) {
                    $noRabbitGroup.slideDown(200);
                } else {
                    $noRabbitGroup.slideUp(200);
                }
            }

            $rabbitCheck.on('change', toggleNoRabbitField);
        });
        </script>
        <?php
    }

    public function saveProductRecipeField($product): void
    {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }

        $originalId = $this->resolveOriginalProductId($product->get_id());
        $recipeId = isset($_POST['zs_recipe_id']) ? (int) $_POST['zs_recipe_id'] : 0;

        // Always save on the original (default-language) product
        if ($originalId !== $product->get_id()) {
            if ($recipeId > 0) {
                update_post_meta($originalId, self::META_PRODUCT_RECIPE_ID, $recipeId);
            } else {
                delete_post_meta($originalId, self::META_PRODUCT_RECIPE_ID);
            }
            $noRabbitId = isset($_POST[self::META_PRODUCT_RECIPE_NO_RABBIT]) ? (int) $_POST[self::META_PRODUCT_RECIPE_NO_RABBIT] : 0;
            if ($noRabbitId > 0) {
                update_post_meta($originalId, self::META_PRODUCT_RECIPE_NO_RABBIT, $noRabbitId);
            } else {
                delete_post_meta($originalId, self::META_PRODUCT_RECIPE_NO_RABBIT);
            }
            return;
        }

        if ($recipeId > 0) {
            $product->update_meta_data(self::META_PRODUCT_RECIPE_ID, $recipeId);
        } else {
            $product->delete_meta_data(self::META_PRODUCT_RECIPE_ID);
        }

        $noRabbitRecipeId = isset($_POST[self::META_PRODUCT_RECIPE_NO_RABBIT]) ? (int) $_POST[self::META_PRODUCT_RECIPE_NO_RABBIT] : 0;
        if ($noRabbitRecipeId > 0) {
            $product->update_meta_data(self::META_PRODUCT_RECIPE_NO_RABBIT, $noRabbitRecipeId);
        } else {
            $product->delete_meta_data(self::META_PRODUCT_RECIPE_NO_RABBIT);
        }

        $product->save();
    }

    private function resolveOriginalProductId(int $productId): int
    {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return $productId;
        }
        $defaultLang = apply_filters('wpml_default_language', null);
        $originalId  = apply_filters('wpml_object_id', $productId, 'product', true, $defaultLang);
        return $originalId ? (int) $originalId : $productId;
    }

    private function resolveOriginalTermId(int $termId, string $taxonomy = null): int
    {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return $termId;
        }
        
        if ($taxonomy === null) {
            $taxonomy = self::TAX_INGREDIENT;
        }
        
        $defaultLang = apply_filters('wpml_default_language', null);
        $originalId  = apply_filters('wpml_object_id', $termId, $taxonomy, true, $defaultLang);
        return $originalId ? (int) $originalId : $termId;
    }

    private function normalizeIngredientName(string $name): string
    {
        // Trim whitespace
        $name = trim($name);
        
        // Remove multiple spaces
        $name = preg_replace('/\s+/', ' ', $name);
        
        // Convert to lowercase for comparison
        return mb_strtolower($name, 'UTF-8');
    }

    private function capitalizeIngredientName(string $name): string
    {
        // Trim and clean spaces first
        $name = trim($name);
        $name = preg_replace('/\s+/', ' ', $name);
        
        // Capitalize first letter of each word
        return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
    }

    private function getAllowedUnits(): array
    {
        return [
            'g' => __('g', 'zero-sense'),
            'kg' => __('kg', 'zero-sense'),
            'ml' => __('ml', 'zero-sense'),
            'l' => __('l', 'zero-sense'),
            'u' => __('u', 'zero-sense'),
        ];
    }

    private function getUtensilUnits(): array
    {
        return [
            'l' => __('l', 'zero-sense'),
            'u' => __('u', 'zero-sense'),
        ];
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

        // Get all recipes
        $recipes = get_posts([
            'post_type' => self::CPT,
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        // Count manually by checking the meta
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
                    break; // Count each recipe only once
                }
            }
        }

        if ($count === 0) {
            return '<span style="color:#999;">—</span>';
        }

        // Don't use taxonomy filter (doesn't work with meta), just show count
        return sprintf(
            '<strong>%d</strong> %s',
            $count,
            $count === 1 ? __('recipe', 'zero-sense') : __('recipes', 'zero-sense')
        );
    }

    public function removeDeleteActionIfInUse(array $actions, \WP_Term $term): array
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
        if (!$term instanceof \WP_Term) {
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
        $term_name = $term instanceof \WP_Term ? $term->name : '';
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

    public function addRecipeColumns(array $columns): array
    {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['ingredients'] = __('Ingredients', 'zero-sense');
                $new_columns['liquids'] = __('Liquids', 'zero-sense');
                $new_columns['utensils'] = __('Utensils', 'zero-sense');
            }
        }
        return $new_columns;
    }

    public function renderRecipeColumn(string $column, int $post_id): void
    {
        if ($column === 'ingredients') {
            $ingredients = get_post_meta($post_id, self::META_INGREDIENTS, true);
            if (!is_array($ingredients) || empty($ingredients)) {
                echo '<span style="color:#999;">—</span>';
                return;
            }

            $names = [];
            foreach ($ingredients as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $term_id = isset($item['ingredient']) ? (int) $item['ingredient'] : 0;
                if ($term_id > 0) {
                    $term = get_term($term_id, self::TAX_INGREDIENT);
                    if ($term instanceof \WP_Term) {
                        $names[] = $term->name;
                    }
                }
            }

            if (empty($names)) {
                echo '<span style="color:#999;">—</span>';
            } else {
                echo '<span style="font-size:12px;">' . esc_html(implode(', ', $names)) . '</span>';
            }
        }

        if ($column === 'liquids') {
            $liquids = get_post_meta($post_id, self::META_LIQUIDS, true);
            if (!is_array($liquids) || empty($liquids)) {
                echo '<span style="color:#999;">—</span>';
                return;
            }

            $names = [];
            foreach ($liquids as $item) {
                if (!is_array($item)) continue;
                $term_id = isset($item['liquid']) ? (int) $item['liquid'] : 0;
                if ($term_id > 0) {
                    $term = get_term($term_id, self::TAX_LIQUID);
                    if ($term instanceof \WP_Term) {
                        $names[] = $term->name;
                    }
                }
            }

            echo empty($names)
                ? '<span style="color:#999;">—</span>'
                : '<span style="font-size:12px;">' . esc_html(implode(', ', $names)) . '</span>';
            return;
        }

        if ($column === 'utensils') {
            $needsPaella = get_post_meta($post_id, self::META_NEEDS_PAELLA, true);
            if ($needsPaella === '1') {
                echo '<span style="color:#999; font-style:italic;">Paella mode</span>';
                return;
            }

            $utensils = get_post_meta($post_id, self::META_UTENSILS, true);
            if (!is_array($utensils) || empty($utensils)) {
                echo '<span style="color:#999;">—</span>';
                return;
            }

            $names = [];
            foreach ($utensils as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $term_id = isset($item['utensil']) ? (int) $item['utensil'] : 0;
                if ($term_id > 0) {
                    $term = get_term($term_id, self::TAX_UTENSIL);
                    if ($term instanceof \WP_Term) {
                        $names[] = $term->name;
                    }
                }
            }

            if (empty($names)) {
                echo '<span style="color:#999;">—</span>';
            } else {
                echo '<span style="font-size:12px;">' . esc_html(implode(', ', $names)) . '</span>';
            }
        }
    }
}
