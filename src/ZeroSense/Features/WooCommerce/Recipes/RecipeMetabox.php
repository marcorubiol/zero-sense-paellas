<?php
declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce\Recipes;

use WP_Post;
use WP_Term;
use ZeroSense\Features\WooCommerce\EventManagement\Inventory\Support\MaterialDefinitions;

class RecipeMetabox
{
    private const CPT = RecipePostType::CPT;
    private const TAX_INGREDIENT = RecipePostType::TAX_INGREDIENT;
    private const TAX_UTENSIL = RecipePostType::TAX_UTENSIL;
    private const TAX_LIQUID = RecipePostType::TAX_LIQUID;

    private const META_INGREDIENTS = 'zs_recipe_ingredients';
    private const META_UTENSILS = 'zs_recipe_utensils';
    private const META_LIQUIDS = 'zs_recipe_liquids';
    private const META_STOCK = 'zs_recipe_stock';
    public const META_PRODUCT_RECIPE_ID = 'zs_recipe_id';
    public const META_PRODUCT_RECIPE_NO_RABBIT = 'zs_recipe_id_no_rabbit';
    private const META_NEEDS_PAELLA = 'zs_recipe_needs_paella';

    private const NONCE_FIELD = 'zs_recipe_nonce';
    private const NONCE_ACTION = 'zs_recipe_save';

    public function init(): void
    {
        add_action('add_meta_boxes', [$this, 'addRecipeMetabox']);
        add_action('add_meta_boxes', [$this, 'addLinkedProductsMetabox']);
        add_action('add_meta_boxes', [$this, 'removeDefaultMetaboxes']);
        add_action('save_post_' . self::CPT, [$this, 'saveRecipeMetabox'], 10, 2);
        
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets'], 5);

        // Recipe list columns
        add_filter('manage_' . self::CPT . '_posts_columns', [$this, 'addRecipeColumns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'renderRecipeColumn'], 10, 2);
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
        $recipeId = $post->ID;

        $products = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'numberposts'    => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'suppress_filters' => true,
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'   => self::META_PRODUCT_RECIPE_ID,
                    'value' => $recipeId,
                ],
                [
                    'key'   => self::META_PRODUCT_RECIPE_NO_RABBIT,
                    'value' => $recipeId,
                ],
            ],
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
                $editUrl  = get_edit_post_link($product->ID);
                $status   = $product->post_status !== 'publish' ? ' <span style="color:#646970; font-size:11px;">(' . esc_html($product->post_status) . ')</span>' : '';
                $noRabbit = get_post_meta($product->ID, self::META_PRODUCT_RECIPE_NO_RABBIT, true) == $recipeId;
                $tag      = $noRabbit ? ' <span style="color:#646970; font-size:11px;">(no rabbit)</span>' : '';
                echo '<li style="padding:4px 0; border-bottom:1px solid #f0f0f1; font-size:13px;">';
                echo '<a href="' . esc_url((string) $editUrl) . '" target="_blank" style="text-decoration:none;">' . esc_html($product->post_title) . '</a>' . $tag . $status;
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
        remove_meta_box('tagsdiv-' . self::TAX_INGREDIENT, self::CPT, 'side');
        remove_meta_box('tagsdiv-' . self::TAX_UTENSIL, self::CPT, 'side');
        remove_meta_box('tagsdiv-' . self::TAX_LIQUID, self::CPT, 'side');
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

    private function getAllowedUnits(): array
    {
        return [
            'g' => __('gr', 'zero-sense'),
            'kg' => __('kg', 'zero-sense'),
            'ml' => __('ml', 'zero-sense'),
            'l' => __('lit', 'zero-sense'),
            'u' => __('pcs', 'zero-sense'),
        ];
    }

    private function getUtensilUnits(): array
    {
        return [
            'l' => __('lit', 'zero-sense'),
            'u' => __('pcs', 'zero-sense'),
        ];
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
        
        $manage_url = admin_url('edit-tags.php?taxonomy=' . self::TAX_INGREDIENT . '&post_type=' . self::CPT);
        $manage_utensils_url = admin_url('edit-tags.php?taxonomy=' . self::TAX_UTENSIL . '&post_type=' . self::CPT);
        $manage_liquids_url = admin_url('edit-tags.php?taxonomy=' . self::TAX_LIQUID . '&post_type=' . self::CPT);
        
        $utensils = get_post_meta($post->ID, self::META_UTENSILS, true);
        $utensils = is_array($utensils) ? $utensils : [];
        $liquids = get_post_meta($post->ID, self::META_LIQUIDS, true);
        $liquids = is_array($liquids) ? $liquids : [];
        $needsPaella = get_post_meta($post->ID, self::META_NEEDS_PAELLA, true);

        // Preload all terms to avoid N+1 queries
        $termIdsToLoad = [];
        $termTypes = [];
        
        foreach ($ingredients as $row) {
            if (isset($row['ingredient']) && (int)$row['ingredient'] > 0) {
                $termIdsToLoad[] = (int)$row['ingredient'];
                $termTypes[(int)$row['ingredient']] = self::TAX_INGREDIENT;
            }
        }
        foreach ($liquids as $row) {
            if (isset($row['liquid']) && (int)$row['liquid'] > 0) {
                $termIdsToLoad[] = (int)$row['liquid'];
                $termTypes[(int)$row['liquid']] = self::TAX_LIQUID;
            }
        }
        foreach ($utensils as $row) {
            if (isset($row['utensil']) && (int)$row['utensil'] > 0) {
                $termIdsToLoad[] = (int)$row['utensil'];
                $termTypes[(int)$row['utensil']] = self::TAX_UTENSIL;
            }
        }
        
        $loadedTerms = [];
        if (!empty($termIdsToLoad)) {
            $termIdsToLoad = array_unique($termIdsToLoad);
            // Translate IDs if WPML is active
            $translatedIds = [];
            foreach ($termIdsToLoad as $id) {
                $translatedIds[$id] = $this->resolveOriginalTermId($id, $termTypes[$id] ?? self::TAX_INGREDIENT);
            }
            
            $terms = get_terms([
                'include' => array_values($translatedIds),
                'hide_empty' => false,
                'suppress_filters' => true,
            ]);
            
            if (is_array($terms)) {
                foreach ($terms as $term) {
                    if ($term instanceof WP_Term) {
                        // Map back to original IDs so we can find them
                        foreach ($translatedIds as $origId => $transId) {
                            if ($transId === $term->term_id) {
                                $loadedTerms[$origId] = $term;
                            }
                        }
                    }
                }
            }
        }
        
        // Fetch remaining terms for dropdowns
        $existing_ingredients = get_terms(['taxonomy' => self::TAX_INGREDIENT, 'hide_empty' => false, 'number' => 50, 'suppress_filters' => true]);
        $existing_liquids = get_terms(['taxonomy' => self::TAX_LIQUID, 'hide_empty' => false, 'number' => 50, 'suppress_filters' => true]);
        $existing_utensils = get_terms(['taxonomy' => self::TAX_UTENSIL, 'hide_empty' => false, 'number' => 50, 'suppress_filters' => true]);

        $stockRows = get_post_meta($post->ID, self::META_STOCK, true);
        $stockRows = is_array($stockRows) ? $stockRows : [];
        $eligibleMaterials = MaterialDefinitions::getStockEligible();
        $materialsByGroup = [];
        foreach ($eligibleMaterials as $mat) {
            $materialsByGroup[$mat['parent_category']][$mat['category']][] = $mat;
        }
        $parentCategoryLabels = MaterialDefinitions::getParentCategories();
        $stockCascade = MaterialDefinitions::getStockCascade();
        $cascadeLabels = [];
        foreach ($stockCascade as $parentKey => $childKeys) {
            $childLabels = [];
            foreach ($childKeys as $ck) {
                $def = MaterialDefinitions::get($ck);
                $childLabels[] = $def ? $def['label'] : $ck;
            }
            $cascadeLabels[$parentKey] = implode(', ', $childLabels);
        }

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
                    <p><?php esc_html_e('Enable to automatically calculate paella pans, burners and cassola size for this recipe.', 'zero-sense'); ?></p>
                </div>
                <div class="zs-paella-mode-notice" style="display:<?php echo $needsPaella === '1' ? 'block' : 'none'; ?>; background:#fff8e1; border-left:3px solid #f0a500; padding:8px 12px; margin:8px 0 4px; border-radius:3px; font-size:12px; color:#444;">
                    <strong><?php esc_html_e('💧 Liquids determine the cassola size', 'zero-sense'); ?></strong><br>
                    <?php esc_html_e('Enter the main cooking liquid (water, broth…) in the Liquids section below. The system adds up all liquid volume across items in the order to automatically pick the right cassola.', 'zero-sense'); ?><br>
                    <?php esc_html_e('Oils and fats go in Ingredients — they are not counted for cassola sizing.', 'zero-sense'); ?>
                </div>
            </div>

            <!-- Liquids Section (shown only in Paella Mode — placed first so order is: Liquids → Ingredients → Stock → Utensils) -->
            <div class="zs-mb-section zs-mb-section--first zs-liquids-section"<?php echo $needsPaella === '1' ? '' : ' style="display:none;"'; ?>>
                <h3 class="zs-mb-subheader" style="font-size:14px; text-transform:none; letter-spacing:0;"><?php esc_html_e('Cooking liquids (water & broth)', 'zero-sense'); ?></h3>
                <p style="margin:2px 0 8px; color:#666; font-size:12px;"><?php esc_html_e('Only add the main cooking liquid (water, stock…) used to cook the rice. This determines the cassola size. Fats like oil belong in Ingredients.', 'zero-sense'); ?></p>
                <table class="widefat striped" style="margin-top:8px;">
                    <thead>
                        <tr>
                            <th style="width: 5%; text-align: center;"></th>
                            <th style="width: 60%;"><?php esc_html_e('Liquid', 'zero-sense'); ?></th>
                            <th style="width: 20%;"><?php esc_html_e('Litres / pax', 'zero-sense'); ?></th>
                            <th style="width: 15%;"></th>
                        </tr>
                    </thead>
                    <tbody id="zs-liquid-rows">
                        <?php
                        $liquid_row_index = 0;
                        foreach ($liquids as $row):
                            $termId = isset($row['liquid']) ? (int) $row['liquid'] : 0;
                            $qty = isset($row['qty']) ? (string) $row['qty'] : '';

                            $termName = '';
                            if ($termId > 0 && isset($loadedTerms[$termId])) {
                                $termName = $loadedTerms[$termId]->name;
                            }
                            ?>
                            <tr data-row="<?php echo $liquid_row_index; ?>">
                                <td class="zs-drag-handle">
                                    <span class="dashicons dashicons-menu"></span>
                                </td>
                                <td>
                                    <select name="zs_recipe_liquids[liquid][]" class="zs-liquid-select" data-placeholder="<?php echo esc_attr(__('Search or create…', 'zero-sense')); ?>">
                                        <?php if ($termId > 0 && $termName !== ''): ?>
                                            <option value="<?php echo esc_attr((string) $termId); ?>" selected="selected"><?php echo esc_html($termName); ?></option>
                                        <?php endif; ?>
                                        <?php 
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
                                    <input type="number" step="0.001" min="0" name="zs_recipe_liquids[qty][]" value="<?php echo esc_attr($qty); ?>">
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

            <div class="zs-mb-section<?php echo $needsPaella !== '1' ? ' zs-mb-section--first' : ''; ?>">
            <h3 class="zs-mb-subheader" style="font-size:14px; text-transform:none; letter-spacing:0;"><?php esc_html_e('Ingredients', 'zero-sense'); ?></h3>
            <table class="widefat striped" style="margin-top:8px;">
                <thead>
                    <tr>
                        <th style="width: 5%; text-align: center;"></th>
                        <th style="width: 40%;"><?php esc_html_e('Ingredient', 'zero-sense'); ?></th>
                        <th style="width: 20%;"><?php esc_html_e('Qty / pax', 'zero-sense'); ?></th>
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
                        if ($termId > 0 && isset($loadedTerms[$termId])) {
                            $termName = $loadedTerms[$termId]->name;
                        }
                        ?>
                        <tr data-row="<?php echo $row_index; ?>">
                            <td class="zs-drag-handle">
                                <span class="dashicons dashicons-menu"></span>
                            </td>
                            <td>
                                <select name="zs_recipe_ingredients[ingredient][]" class="zs-ingredient-select" style="width:100%;" data-placeholder="<?php echo esc_attr(__('Search or create…', 'zero-sense')); ?>">
                                    <?php if ($termId > 0 && $termName !== ''): ?>
                                        <option value="<?php echo esc_attr((string) $termId); ?>" selected="selected"><?php echo esc_html($termName); ?></option>
                                    <?php endif; ?>
                                    <?php 
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
            </div><!-- /.zs-mb-section Ingredients -->

            <!-- Equipment Stock Section (between Ingredients and Utensils) -->
            <div class="zs-mb-section zs-stock-section">
                <h3 class="zs-mb-subheader" style="font-size:14px; text-transform:none; letter-spacing:0;"><?php esc_html_e('Equipment Stock', 'zero-sense'); ?></h3>
                <p style="margin:2px 0 8px; color:#666; font-size:12px;"><?php esc_html_e('Materials required by this recipe. Qty and ratio work like utensils: ceil(guests / every-X) × qty is added to the order\'s equipment total.', 'zero-sense'); ?></p>
                <table class="widefat striped" style="margin-top:8px;">
                    <thead>
                        <tr>
                            <th style="width:5%; text-align:center;"></th>
                            <th style="width:40%;"><?php esc_html_e('Material', 'zero-sense'); ?></th>
                            <th style="width:15%;"><?php esc_html_e('Qty', 'zero-sense'); ?></th>
                            <th style="width:25%;"><?php esc_html_e('Per X pax', 'zero-sense'); ?></th>
                            <th style="width:15%;"></th>
                        </tr>
                    </thead>
                    <tbody id="zs-stock-rows">
                        <?php
                        $stock_row_index = 0;
                        foreach ($stockRows as $row):
                            $matKey  = isset($row['material_key']) ? (string) $row['material_key'] : '';
                            $qty     = isset($row['qty']) ? (string) $row['qty'] : '';
                            $ratio   = isset($row['pax_ratio']) ? (int) $row['pax_ratio'] : 1;
                            ?>
                            <tr data-row="<?php echo $stock_row_index; ?>">
                                <td class="zs-drag-handle"><span class="dashicons dashicons-menu"></span></td>
                                <td>
                                    <select name="zs_recipe_stock[material_key][]" style="width:100%;" class="zs-stock-material-select">
                                        <option value=""><?php esc_html_e('— Select material —', 'zero-sense'); ?></option>
                                        <?php foreach ($materialsByGroup as $parentKey => $categories): ?>
                                            <optgroup label="<?php echo esc_attr($parentCategoryLabels[$parentKey] ?? $parentKey); ?>">
                                                <?php foreach ($categories as $catKey => $mats): ?>
                                                    <?php foreach ($mats as $mat): ?>
                                                        <option value="<?php echo esc_attr($mat['key']); ?>" <?php selected($matKey, $mat['key']); ?>>
                                                            <?php echo esc_html($mat['label']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($matKey !== '' && isset($cascadeLabels[$matKey])): ?>
                                        <small class="zs-stock-cascade-hint" style="display:block; color:#666; font-size:11px; margin-top:3px;">↳ <?php esc_html_e('Also auto-adds:', 'zero-sense'); ?> <?php echo esc_html($cascadeLabels[$matKey]); ?></small>
                                    <?php else: ?>
                                        <small class="zs-stock-cascade-hint" style="display:none;"></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input type="number" step="0.001" min="0" name="zs_recipe_stock[qty][]" value="<?php echo esc_attr($qty); ?>" style="width:100%;">
                                </td>
                                <td>
                                    <input type="number" step="1" min="1" name="zs_recipe_stock[pax_ratio][]" value="<?php echo esc_attr((string) $ratio); ?>" style="width:100%;" placeholder="1">
                                </td>
                                <td>
                                    <button type="button" class="button zs-stock-remove"><?php esc_html_e('Remove', 'zero-sense'); ?></button>
                                </td>
                            </tr>
                            <?php
                            $stock_row_index++;
                        endforeach;
                        ?>
                    </tbody>
                </table>
                <p class="zs-mb-row-actions">
                    <button type="button" class="button" id="zs-stock-add-row"><?php esc_html_e('Add material', 'zero-sense'); ?></button>
                </p>
                <p class="zs-mb-description" style="font-style:italic;">
                    <?php esc_html_e('"Per X pax" = ratio (1 = per pax, 10 = every 10 pax, 50 = every 50 pax)', 'zero-sense'); ?>
                </p>
            </div>

            <!-- Utensils Section -->
            <div class="zs-mb-section zs-utensils-section"<?php echo $needsPaella === '1' ? ' style="display:none;"' : ''; ?>>
                <h3 class="zs-mb-subheader" style="font-size:14px; text-transform:none; letter-spacing:0;"><?php esc_html_e('Utensils', 'zero-sense'); ?></h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width: 5%; text-align: center;"></th>
                            <th style="width: 30%;"><?php esc_html_e('Utensil', 'zero-sense'); ?></th>
                            <th style="width: 15%;"><?php esc_html_e('Qty', 'zero-sense'); ?></th>
                            <th style="width: 20%;"><?php esc_html_e('Per X pax', 'zero-sense'); ?></th>
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
                            if ($termId > 0 && isset($loadedTerms[$termId])) {
                                $termName = $loadedTerms[$termId]->name;
                            }
                            ?>
                            <tr data-row="<?php echo $utensil_row_index; ?>">
                                <td class="zs-drag-handle">
                                    <span class="dashicons dashicons-menu"></span>
                                </td>
                                <td>
                                    <select name="zs_recipe_utensils[utensil][]" class="zs-utensil-select" data-placeholder="<?php echo esc_attr(__('Search or create…', 'zero-sense')); ?>">
                                        <?php if ($termId > 0 && $termName !== ''): ?>
                                        <option value="<?php echo esc_attr((string) $termId); ?>" selected="selected"><?php echo esc_html($termName); ?></option>
                                    <?php endif; ?>
                                    <?php 
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
                    <?php esc_html_e('"Per X pax" = ratio (1 = per pax, 4 = every 4 pax, 10 = every 10 pax)', 'zero-sense'); ?>
                </p>
            </div>

        </div>
        <?php
        wp_localize_script('zs-recipes-admin', 'zsRecipesData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('zs_ingredient_ajax'),
            'rowCount' => max(0, count($ingredients)),
            'utensilRowCount' => max(0, count($utensils)),
            'liquidRowCount' => max(0, count($liquids)),
            'stockRowCount' => max(0, count($stockRows)),
            'units' => array_keys($units),
            'unitLabels' => array_values($units),
            'utensilUnits' => array_keys($utensilUnits),
            'utensilUnitLabels' => array_values($utensilUnits),
            'i18n' => [
                'search_or_create' => __('Search or create…', 'zero-sense'),
                'remove' => __('Remove', 'zero-sense'),
                'create_new' => __('(crear nuevo)', 'zero-sense'),
                'error_create_ingredient' => __('Error al crear el ingrediente.', 'zero-sense'),
                'error_conn_ingredient' => __('Error de conexión al crear el ingrediente.', 'zero-sense'),
                'error_create_utensil' => __('Error al crear el utensilio.', 'zero-sense'),
                'error_conn_utensil' => __('Error de conexión al crear el utensilio.', 'zero-sense'),
                'error_create_liquid' => __('Error al crear el líquido.', 'zero-sense'),
                'error_conn_liquid' => __('Error de conexión al crear el líquido.', 'zero-sense'),
                'select_material' => __('— Select material —', 'zero-sense')
            ],
            'stockMaterials' => array_values(array_map(function($mat) {
                return ['key' => $mat['key'], 'label' => $mat['label'], 'group' => $mat['parent_category']];
            }, $eligibleMaterials)),
            'stockMaterialGroups' => array_map(function($label) { return $label; }, $parentCategoryLabels),
            'stockCascade' => $cascadeLabels
        ]);
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

        // Always save stock regardless of paella mode
        $rawStock = $_POST['zs_recipe_stock'] ?? null;
        if (is_array($rawStock)) {
            $stockKeys   = isset($rawStock['material_key']) && is_array($rawStock['material_key']) ? $rawStock['material_key'] : [];
            $stockQtys   = isset($rawStock['qty']) && is_array($rawStock['qty']) ? $rawStock['qty'] : [];
            $stockRatios = isset($rawStock['pax_ratio']) && is_array($rawStock['pax_ratio']) ? $rawStock['pax_ratio'] : [];

            $outStock = [];
            $countStock = max(count($stockKeys), count($stockQtys), count($stockRatios));
            for ($i = 0; $i < $countStock; $i++) {
                $matKey  = isset($stockKeys[$i]) ? sanitize_key((string) $stockKeys[$i]) : '';
                $qty     = isset($stockQtys[$i]) ? (float) $stockQtys[$i] : 0.0;
                $ratio   = isset($stockRatios[$i]) ? max(1, (int) $stockRatios[$i]) : 1;

                if ($matKey === '' || $qty <= 0 || MaterialDefinitions::get($matKey) === null) {
                    continue;
                }

                $outStock[] = [
                    'material_key' => $matKey,
                    'qty'          => $qty,
                    'pax_ratio'    => $ratio,
                ];
            }

            if ($outStock === []) {
                delete_post_meta($postId, self::META_STOCK);
            } else {
                update_post_meta($postId, self::META_STOCK, $outStock);
            }
        } else {
            delete_post_meta($postId, self::META_STOCK);
        }

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
                plugin_dir_url(dirname(dirname(dirname(dirname(dirname(__FILE__)))))) . 'assets/css/admin-components.css',
                [],
                '1.0'
            );

            wp_enqueue_style('woocommerce_admin_styles');
            
            wp_enqueue_script('selectWoo');
            wp_enqueue_script('jquery-ui-core');
            wp_enqueue_script('jquery-ui-widget');
            wp_enqueue_script('jquery-ui-autocomplete');
            wp_enqueue_script('jquery-ui-sortable');
            
            // Register and enqueue our custom scripts
            wp_register_script(
                'zs-recipes-admin',
                plugin_dir_url(dirname(dirname(dirname(dirname(dirname(__FILE__)))))) . 'assets/js/recipes-admin.js',
                ['jquery', 'selectWoo', 'jquery-ui-sortable'],
                (string) time(),
                true
            );
            wp_enqueue_script('zs-recipes-admin');
        }
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
                $new_columns['stock'] = __('Equipment Stock', 'zero-sense');
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

            // Optimize loading using a single get_terms call instead of loop
            $termIds = [];
            foreach ($ingredients as $item) {
                if (is_array($item) && isset($item['ingredient']) && $item['ingredient'] > 0) {
                    $termIds[] = (int) $item['ingredient'];
                }
            }

            if (empty($termIds)) {
                echo '<span style="color:#999;">—</span>';
                return;
            }

            $terms = get_terms([
                'include' => $termIds,
                'taxonomy' => self::TAX_INGREDIENT,
                'hide_empty' => false,
            ]);

            $names = [];
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $names[] = $term->name;
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

            $termIds = [];
            foreach ($liquids as $item) {
                if (is_array($item) && isset($item['liquid']) && $item['liquid'] > 0) {
                    $termIds[] = (int) $item['liquid'];
                }
            }

            if (empty($termIds)) {
                echo '<span style="color:#999;">—</span>';
                return;
            }

            $terms = get_terms([
                'include' => $termIds,
                'taxonomy' => self::TAX_LIQUID,
                'hide_empty' => false,
            ]);

            $names = [];
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $names[] = $term->name;
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

            $termIds = [];
            foreach ($utensils as $item) {
                if (is_array($item) && isset($item['utensil']) && $item['utensil'] > 0) {
                    $termIds[] = (int) $item['utensil'];
                }
            }

            if (empty($termIds)) {
                echo '<span style="color:#999;">—</span>';
                return;
            }

            $terms = get_terms([
                'include' => $termIds,
                'taxonomy' => self::TAX_UTENSIL,
                'hide_empty' => false,
            ]);

            $names = [];
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $names[] = $term->name;
                }
            }

            if (empty($names)) {
                echo '<span style="color:#999;">—</span>';
            } else {
                echo '<span style="font-size:12px;">' . esc_html(implode(', ', $names)) . '</span>';
            }
        }

        if ($column === 'stock') {
            $stockRows = get_post_meta($post_id, self::META_STOCK, true);
            if (!is_array($stockRows) || empty($stockRows)) {
                echo '<span style="color:#999;">—</span>';
                return;
            }

            $parts = [];
            foreach ($stockRows as $row) {
                $matKey = isset($row['material_key']) ? sanitize_key((string) $row['material_key']) : '';
                $qty    = isset($row['qty']) ? (float) $row['qty'] : 0.0;
                $ratio  = isset($row['pax_ratio']) ? (int) $row['pax_ratio'] : 1;
                if ($matKey === '' || $qty <= 0) {
                    continue;
                }
                $matDef = MaterialDefinitions::get($matKey);
                $label  = $matDef ? $matDef['label'] : $matKey;
                $ratioStr = $ratio > 1 ? '/' . $ratio . 'p' : '/p';
                $parts[] = esc_html($qty . '×' . $label . ' ' . $ratioStr);
            }

            if (empty($parts)) {
                echo '<span style="color:#999;">—</span>';
            } else {
                echo '<span style="font-size:11px; line-height:1.6;">' . implode('<br>', $parts) . '</span>';
            }
        }
    }
}
