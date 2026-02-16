<?php
namespace ZeroSense\Features\WooCommerce;

use ZeroSense\Core\FeatureInterface;
use WP_Post;
use WP_Term;

class Recipes implements FeatureInterface
{
    private const CPT = 'zs_recipe';
    private const TAX_INGREDIENT = 'zs_ingredient';

    private const META_INGREDIENTS = 'zs_recipe_ingredients';
    private const META_PRODUCT_RECIPE_ID = 'zs_recipe_id';

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
        add_action('add_meta_boxes', [$this, 'removeDefaultMetaboxes']);
        add_action('save_post_' . self::CPT, [$this, 'saveRecipeMetabox'], 10, 2);
        
        // Higher priority to load before asset blockers
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets'], 5);

        add_action('wp_ajax_zs_ingredient_search', [$this, 'ajaxIngredientSearch']);
        add_action('wp_ajax_zs_ingredient_create', [$this, 'ajaxIngredientCreate']);

        add_action('woocommerce_product_options_general_product_data', [$this, 'renderProductRecipeField']);
        add_action('woocommerce_admin_process_product_object', [$this, 'saveProductRecipeField']);
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
        error_log('[ZS Recipes] registerContentTypes called');
        
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
            'show_in_menu' => 'woocommerce',
            'menu_position' => 56,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
        
        error_log('[ZS Recipes] CPT registered: ' . self::CPT);

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
        
        error_log('[ZS Recipes] Taxonomy registered: ' . self::TAX_INGREDIENT);
    }

    public function addRecipeMetabox(): void
    {
        error_log('[ZS Recipes] addRecipeMetabox called');
        
        add_meta_box(
            'zs_recipe_ingredients',
            __('Recipe ingredients', 'zero-sense'),
            [$this, 'renderRecipeMetabox'],
            self::CPT,
            'normal',
            'high'
        );
        
        error_log('[ZS Recipes] Metabox added for CPT: ' . self::CPT);
    }

    public function removeDefaultMetaboxes(): void
    {
        // Remove default tags metabox for our CPT
        remove_meta_box('tagsdiv-' . self::TAX_INGREDIENT, self::CPT, 'side');
        error_log('[ZS Recipes] Removed default tags metabox for: ' . self::TAX_INGREDIENT);
    }

    public function renderRecipeMetabox(WP_Post $post): void
    {
        error_log('[ZS Recipes] renderRecipeMetabox called for post: ' . $post->ID);
        error_log('[ZS Recipes] Post type: ' . $post->post_type);
        
        if (!current_user_can('edit_post', $post->ID)) {
            error_log('[ZS Recipes] User cannot edit post');
            return;
        }

        $ingredients = get_post_meta($post->ID, self::META_INGREDIENTS, true);
        $ingredients = is_array($ingredients) ? $ingredients : [];
        
        error_log('[ZS Recipes] Found ingredients: ' . count($ingredients));

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $units = $this->getAllowedUnits();
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('zs_ingredient_ajax');

        ?>
        <div class="zs-recipes-metabox">
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

            <p style="margin-top:10px;">
                <button type="button" class="button" id="zs-recipe-add-row"><?php esc_html_e('Add ingredient', 'zero-sense'); ?></button>
            </p>
        </div>

        <script>
        (function($) {
            console.log('Zero Sense Recipes: Initializing...');
            
            var ajaxUrl = '<?php echo $ajax_url; ?>';
            var nonce = '<?php echo $nonce; ?>';
            var rowCount = <?php echo max(0, count($ingredients)); ?>;
            
            // Debug: Check if jQuery and selectWoo are available
            console.log('Zero Sense Recipes: jQuery available:', typeof jQuery !== 'undefined');
            console.log('Zero Sense Recipes: selectWoo available:', typeof jQuery.fn.selectWoo !== 'undefined');
            console.log('Zero Sense Recipes: Found selects:', $('.zs-ingredient-select').length);
            
            function initSelect(element) {
                console.log('Zero Sense Recipes: initSelect called on:', element);
                
                if (typeof jQuery.fn.selectWoo === 'undefined') {
                    console.error('Zero Sense Recipes: selectWoo not available!');
                    return;
                }
                
                if (!$(element).data('select2')) {
                    console.log('Initializing selectWoo on:', element);
                    $(element).selectWoo({
                        width: '100%',
                        tags: true,
                        tokenSeparators: [','],
                        ajax: {
                            url: ajaxUrl,
                            dataType: 'json',
                            delay: 250,
                            beforeSend: function(xhr) {
                                console.log('Zero Sense Recipes: AJAX request to:', ajaxUrl);
                                console.log('Zero Sense Recipes: Request data:', {
                                    action: 'zs_ingredient_search',
                                    nonce: nonce,
                                    q: params.term || ''
                                });
                            },
                            data: function(params) {
                                console.log('Zero Sense Recipes: Searching for:', params.term);
                                return {
                                    action: 'zs_ingredient_search',
                                    nonce: nonce,
                                    q: params.term || ''
                                };
                            },
                            processResults: function(data) {
                                console.log('Zero Sense Recipes: Search results:', data);
                                return data;
                            },
                            error: function(xhr, status, error) {
                                console.error('Zero Sense Recipes: AJAX Error:', {
                                    status: status,
                                    error: error,
                                    responseText: xhr.responseText,
                                    readyState: xhr.readyState
                                });
                            }
                        }
                    });
                    
                    $(element).on('select2:select', function(e) {
                        console.log('Zero Sense Recipes: Item selected:', e.params.data);
                        var data = e.params.data;
                        if (data && String(parseInt(data.id, 10)) !== String(data.id)) {
                            createIngredient(data.id, element);
                        }
                    });
                } else {
                    console.log('Zero Sense Recipes: Select already initialized on:', element);
                }
            }
            
            function createIngredient(name, selectElement) {
                console.log('Zero Sense Recipes: Creating ingredient:', name);
                $.post(ajaxUrl, {
                    action: 'zs_ingredient_create',
                    nonce: nonce,
                    name: name
                }).done(function(resp) {
                    console.log('Zero Sense Recipes: Create response:', resp);
                    if (resp && resp.success && resp.data) {
                        var newId = resp.data.id;
                        var text = resp.data.text;
                        
                        var option = new Option(text, newId, true, true);
                        $(selectElement).find('option[value="' + name.replace(/"/g, '\\"') + '"]').remove();
                        $(selectElement).append(option).trigger('change');
                    }
                });
            }
            
            function addNewRow() {
                console.log('Zero Sense Recipes: Adding new row');
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
                console.log('Zero Sense Recipes: DOM ready');
                console.log('Zero Sense Recipes: jQuery version:', $.fn.jquery);
                console.log('Zero Sense Recipes: selectWoo available:', typeof jQuery.fn.selectWoo !== 'undefined');
                
                // Initialize all existing selects
                $('.zs-ingredient-select').each(function(index, element) {
                    console.log('Zero Sense Recipes: Processing select #' + index, element);
                    initSelect(element);
                });
                
                // Add row button
                $('#zs-recipe-add-row').on('click', function() {
                    console.log('Zero Sense Recipes: Add row button clicked');
                    addNewRow();
                });
                
                // Remove buttons
                $(document).on('click', '.zs-recipe-remove', function() {
                    console.log('Zero Sense Recipes: Remove button clicked');
                    $(this).closest('tr').remove();
                });
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
            return;
        }

        update_post_meta($postId, self::META_INGREDIENTS, $out);
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
            // Debug logging
            error_log('[ZS Recipes] enqueueAdminAssets called for screen: ' . $screen->id);
            error_log('[ZS Recipes] Hook: ' . $hook);
            
            // Force enqueue WooCommerce styles
            wp_enqueue_style('woocommerce_admin_styles');
            
            // Force enqueue selectWoo with higher priority
            wp_enqueue_script('selectWoo');
            
            // Also enqueue jQuery UI (selectWoo dependency)
            wp_enqueue_script('jquery-ui-core');
            wp_enqueue_script('jquery-ui-widget');
            wp_enqueue_script('jquery-ui-autocomplete');
            
            // Check if scripts are actually enqueued
            global $wp_scripts;
            if (isset($wp_scripts->registered['selectWoo'])) {
                error_log('[ZS Recipes] selectWoo script registered successfully');
            } else {
                error_log('[ZS Recipes] selectWoo script NOT registered - this is the problem!');
            }
        }
    }

    public function ajaxIngredientSearch(): void
    {
        // Debug logging
        error_log('[ZS Recipes] ajaxIngredientSearch called');
        error_log('[ZS Recipes] GET data: ' . print_r($_GET, true));

        if (!current_user_can('edit_posts')) {
            error_log('[ZS Recipes] User cannot edit posts');
            wp_send_json(['results' => []]);
        }

        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash((string) $_GET['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'zs_ingredient_ajax')) {
            error_log('[ZS Recipes] Invalid nonce: ' . $nonce);
            wp_send_json(['results' => []]);
        }

        $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';

        error_log('[ZS Recipes] Search query: "' . $q . '"');

        $terms = get_terms([
            'taxonomy' => self::TAX_INGREDIENT,
            'hide_empty' => false,
            'number' => 25,
            'search' => $q,
            'suppress_filters' => true,
        ]);

        error_log('[ZS Recipes] get_terms result: ' . print_r($terms, true));

        $results = [];
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if ($term instanceof WP_Term) {
                    $results[] = ['id' => (string) $term->term_id, 'text' => $term->name];
                }
            }
        }

        error_log('[ZS Recipes] Final results: ' . print_r($results, true));

        wp_send_json([
            'results' => $results,
        ]);
    }

    public function ajaxIngredientCreate(): void
    {
        // Debug logging
        error_log('[ZS Recipes] ajaxIngredientCreate called');
        error_log('[ZS Recipes] POST data: ' . print_r($_POST, true));

        if (!current_user_can('edit_posts')) {
            error_log('[ZS Recipes] User cannot edit posts');
            wp_send_json_error(['message' => 'forbidden']);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'zs_ingredient_ajax')) {
            error_log('[ZS Recipes] Invalid nonce: ' . $nonce);
            wp_send_json_error(['message' => 'invalid_nonce']);
        }

        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';
        if ($name === '') {
            error_log('[ZS Recipes] Empty name');
            wp_send_json_error(['message' => 'empty']);
        }

        error_log('[ZS Recipes] Creating ingredient: "' . $name . '"');

        $existing = term_exists($name, self::TAX_INGREDIENT);
        error_log('[ZS Recipes] term_exists result: ' . print_r($existing, true));
        
        if (is_array($existing) && isset($existing['term_id'])) {
            error_log('[ZS Recipes] Ingredient exists, returning existing ID: ' . $existing['term_id']);
            wp_send_json_success(['id' => (int) $existing['term_id'], 'text' => $name]);
        }
        if (is_int($existing)) {
            error_log('[ZS Recipes] Ingredient exists (int), returning existing ID: ' . $existing);
            wp_send_json_success(['id' => $existing, 'text' => $name]);
        }

        $created = wp_insert_term($name, self::TAX_INGREDIENT);
        error_log('[ZS Recipes] wp_insert_term result: ' . print_r($created, true));
        
        if (is_wp_error($created) || !is_array($created) || !isset($created['term_id'])) {
            error_log('[ZS Recipes] Failed to create ingredient');
            if (is_wp_error($created)) {
                error_log('[ZS Recipes] WP Error: ' . $created->get_error_message());
            }
            wp_send_json_error(['message' => 'create_failed']);
        }

        error_log('[ZS Recipes] Successfully created ingredient with ID: ' . $created['term_id']);
        wp_send_json_success(['id' => (int) $created['term_id'], 'text' => $name]);
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

        echo '<div class="options_group">';
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

        echo '</select>';
        echo '</p>';
        echo '</div>';
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
            return;
        }

        if ($recipeId > 0) {
            $product->update_meta_data(self::META_PRODUCT_RECIPE_ID, $recipeId);
        } else {
            $product->delete_meta_data(self::META_PRODUCT_RECIPE_ID);
        }
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

    private function resolveOriginalTermId(int $termId): int
    {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return $termId;
        }
        $defaultLang = apply_filters('wpml_default_language', null);
        $originalId  = apply_filters('wpml_object_id', $termId, self::TAX_INGREDIENT, true, $defaultLang);
        return $originalId ? (int) $originalId : $termId;
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
}
