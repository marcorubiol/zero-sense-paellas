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
        add_action('save_post_' . self::CPT, [$this, 'saveRecipeMetabox'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);

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

    public function renderRecipeMetabox(WP_Post $post): void
    {
        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }

        $ingredients = get_post_meta($post->ID, self::META_INGREDIENTS, true);
        $ingredients = is_array($ingredients) ? $ingredients : [];

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $units = $this->getAllowedUnits();

        ?>
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
                <?php foreach ($ingredients as $row):
                    $termId = isset($row['ingredient']) ? (int) $row['ingredient'] : 0;
                    $qty = isset($row['qty']) ? (string) $row['qty'] : '';
                    $unit = isset($row['unit']) ? (string) $row['unit'] : 'u';

                    $termName = '';
                    if ($termId > 0) {
                        $term = get_term($termId, self::TAX_INGREDIENT);
                        if ($term instanceof WP_Term) {
                            $termName = $term->name;
                        }
                    }
                    ?>
                    <tr>
                        <td>
                            <select name="zs_recipe_ingredients[ingredient][]" class="wc-enhanced-select zs-ingredient-select" style="width:100%;" data-placeholder="<?php echo esc_attr(__('Search or create…', 'zero-sense')); ?>">
                                <?php if ($termId > 0 && $termName !== ''): ?>
                                    <option value="<?php echo esc_attr((string) $termId); ?>" selected="selected"><?php echo esc_html($termName); ?></option>
                                <?php endif; ?>
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
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:10px;">
            <button type="button" class="button" id="zs-recipe-add-row"><?php esc_html_e('Add ingredient', 'zero-sense'); ?></button>
        </p>

        <script>
            (function() {
                var ajaxUrl = <?php echo json_encode(admin_url('admin-ajax.php')); ?>;
                var nonce = <?php echo json_encode(wp_create_nonce('zs_ingredient_ajax')); ?>;

                function initSelect(el) {
                    if (!el || !window.jQuery || !jQuery.fn.selectWoo) return;

                    var $el = jQuery(el);
                    if ($el.data('zs-init')) return;
                    $el.data('zs-init', true);

                    $el.selectWoo({
                        width: '100%',
                        tags: true,
                        tokenSeparators: [','],
                        ajax: {
                            url: ajaxUrl,
                            dataType: 'json',
                            delay: 250,
                            data: function(params) {
                                return {
                                    action: 'zs_ingredient_search',
                                    nonce: nonce,
                                    q: params.term || ''
                                };
                            },
                            processResults: function(data) {
                                return data;
                            }
                        }
                    });

                    $el.on('select2:select', function(e) {
                        var data = e && e.params ? e.params.data : null;
                        if (!data) return;
                        var id = data.id;
                        if (String(parseInt(id, 10)) === String(id)) return;

                        jQuery.post(ajaxUrl, {
                            action: 'zs_ingredient_create',
                            nonce: nonce,
                            name: id
                        }).done(function(resp) {
                            if (!resp || !resp.success || !resp.data) return;
                            var newId = resp.data.id;
                            var text = resp.data.text;

                            var option = new Option(text, newId, true, true);
                            $el.find('option[value="' + id.replace(/"/g, '\\"') + '"]').remove();
                            $el.append(option).trigger('change');
                        });
                    });
                }

                function bindRemoveButtons(scope) {
                    var buttons = (scope || document).querySelectorAll('.zs-recipe-remove');
                    buttons.forEach(function(btn) {
                        if (btn.dataset.zsInit) return;
                        btn.dataset.zsInit = '1';
                        btn.addEventListener('click', function() {
                            var tr = btn.closest('tr');
                            if (tr) tr.remove();
                        });
                    });
                }

                function initAllSelects() {
                    document.querySelectorAll('.zs-ingredient-select').forEach(function(el) {
                        initSelect(el);
                    });
                }

                document.addEventListener('DOMContentLoaded', function() {
                    bindRemoveButtons();
                    initAllSelects();

                    var addBtn = document.getElementById('zs-recipe-add-row');
                    var tbody = document.getElementById('zs-recipe-rows');
                    if (!addBtn || !tbody) return;

                    addBtn.addEventListener('click', function() {
                        var tr = document.createElement('tr');
                        tr.innerHTML = '' +
                            '<td>' +
                                '<select name="zs_recipe_ingredients[ingredient][]" class="wc-enhanced-select zs-ingredient-select" style="width:100%;" data-placeholder="<?php echo esc_js(__('Search or create…', 'zero-sense')); ?>"></select>' +
                            '</td>' +
                            '<td><input type="number" step="0.001" min="0" name="zs_recipe_ingredients[qty][]" value="" style="width:100%;"></td>' +
                            '<td>' +
                                '<select name="zs_recipe_ingredients[unit][]" style="width:100%;">' +
                                    <?php
                                        $opt = [];
                                        foreach ($units as $u => $label) {
                                            $opt[] = '<option value="' . esc_js($u) . '">' . esc_js($label) . '</option>';
                                        }
                                        echo json_encode(implode('', $opt));
                                    ?> +
                                '</select>' +
                            '</td>' +
                            '<td><button type="button" class="button zs-recipe-remove"><?php echo esc_js(__('Remove', 'zero-sense')); ?></button></td>';

                        tbody.appendChild(tr);
                        bindRemoveButtons(tr);
                        initAllSelects();
                    });
                });
            })();
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
            wp_enqueue_style('woocommerce_admin_styles');
            wp_enqueue_script('selectWoo');
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

        $terms = get_terms([
            'taxonomy' => self::TAX_INGREDIENT,
            'hide_empty' => false,
            'number' => 25,
            'search' => $q,
        ]);

        $results = [];
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if ($term instanceof WP_Term) {
                    $results[] = ['id' => (string) $term->term_id, 'text' => $term->name];
                }
            }
        }

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

        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';
        if ($name === '') {
            wp_send_json_error(['message' => 'empty']);
        }

        $existing = term_exists($name, self::TAX_INGREDIENT);
        if (is_array($existing) && isset($existing['term_id'])) {
            wp_send_json_success(['id' => (int) $existing['term_id'], 'text' => $name]);
        }
        if (is_int($existing)) {
            wp_send_json_success(['id' => $existing, 'text' => $name]);
        }

        $created = wp_insert_term($name, self::TAX_INGREDIENT);
        if (is_wp_error($created) || !is_array($created) || !isset($created['term_id'])) {
            wp_send_json_error(['message' => 'create_failed']);
        }

        wp_send_json_success(['id' => (int) $created['term_id'], 'text' => $name]);
    }

    public function renderProductRecipeField(): void
    {
        global $post;
        if (!$post instanceof WP_Post) {
            return;
        }

        $current = (int) get_post_meta($post->ID, self::META_PRODUCT_RECIPE_ID, true);

        $recipes = get_posts([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'numberposts' => 200,
            'orderby' => 'title',
            'order' => 'ASC',
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

        $recipeId = isset($_POST['zs_recipe_id']) ? (int) $_POST['zs_recipe_id'] : 0;

        if ($recipeId > 0) {
            $product->update_meta_data(self::META_PRODUCT_RECIPE_ID, $recipeId);
        } else {
            $product->delete_meta_data(self::META_PRODUCT_RECIPE_ID);
        }
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
