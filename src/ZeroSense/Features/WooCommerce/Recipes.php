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
    }

    public function addRecipeMetabox(): void
    {
        add_meta_box(
            'zs_recipe_ingredients',
            __('Recipe ingredients', 'zero-sense'),
            [$this, 'renderRecipeMetabox'],
            self::CPT,
            'normal',
            'high'
        );
    }

    public function removeDefaultMetaboxes(): void
    {
        // Remove default tags metabox for our CPT
        remove_meta_box('tagsdiv-' . self::TAX_INGREDIENT, self::CPT, 'side');
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
                        createTag: function(params) {
                            var term = $.trim(params.term);
                            if (term === '') {
                                return null;
                            }
                            console.log('Zero Sense Recipes: Creating tag for:', term);
                            return {
                                id: term,
                                text: term + ' (crear nuevo)',
                                newTag: true
                            };
                        },
                        insertTag: function(data, tag) {
                            // Insert the tag at the end of the results
                            data.push(tag);
                        },
                        ajax: {
                            url: ajaxUrl,
                            dataType: 'json',
                            delay: 250,
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
                        
                        // Check if it's a new tag (not an existing ingredient)
                        if (data && (data.newTag || String(parseInt(data.id, 10)) !== String(data.id))) {
                            console.log('Zero Sense Recipes: New ingredient detected, creating...');
                            createIngredient(data.id, element);
                        }
                    });
                } else {
                    console.log('Zero Sense Recipes: Select already initialized on:', element);
                }
            }
            
            function createIngredient(name, selectElement) {
                console.log('Zero Sense Recipes: Creating ingredient:', name);
                
                $.ajax({
                    url: ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'zs_ingredient_create',
                        nonce: nonce,
                        name: name
                    },
                    success: function(resp) {
                        console.log('Zero Sense Recipes: Create response:', resp);
                        if (resp && resp.success && resp.data) {
                            var newId = resp.data.id;
                            var text = resp.data.text;
                            
                            console.log('Zero Sense Recipes: New ingredient created:', {id: newId, text: text});
                            
                            // Remove the temporary option and add the real one
                            $(selectElement).find('option[value="' + name.replace(/"/g, '\\"') + '"]').remove();
                            var option = new Option(text, newId, true, true);
                            $(selectElement).append(option).trigger('change');
                        } else {
                            console.error('Zero Sense Recipes: Create failed:', resp);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Zero Sense Recipes: Create AJAX Error:', {
                            status: status,
                            error: error,
                            responseText: xhr.responseText,
                            readyState: xhr.readyState
                        });
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
        error_log('[ZS Recipes] ajaxIngredientCreate called');
        error_log('[ZS Recipes] POST data: ' . print_r($_POST, true));
        
        if (!current_user_can('edit_posts')) {
            error_log('[ZS Recipes] Permission denied');
            wp_send_json_error(['message' => 'forbidden']);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'zs_ingredient_ajax')) {
            error_log('[ZS Recipes] Invalid nonce');
            wp_send_json_error(['message' => 'invalid_nonce']);
        }

        $rawName = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';
        if ($rawName === '') {
            error_log('[ZS Recipes] Empty name');
            wp_send_json_error(['message' => 'empty']);
        }

        // Normalize for searching (lowercase, trim, clean spaces)
        $normalizedName = $this->normalizeIngredientName($rawName);
        
        // Capitalize for display (Title Case)
        $displayName = $this->capitalizeIngredientName($rawName);
        
        error_log('[ZS Recipes] Raw: "' . $rawName . '", Normalized: "' . $normalizedName . '", Display: "' . $displayName . '"');

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
}
