<?php
declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Features\WooCommerce\Recipes\RecipePostType;
use ZeroSense\Features\WooCommerce\Recipes\RecipeAjaxHandler;
use ZeroSense\Features\WooCommerce\Recipes\RecipeMetabox;
use ZeroSense\Features\WooCommerce\Recipes\RecipeTaxonomyProtection;

class Recipes implements FeatureInterface
{
    private RecipePostType $postType;
    private RecipeAjaxHandler $ajaxHandler;
    private RecipeMetabox $metabox;
    private RecipeTaxonomyProtection $taxonomyProtection;

    public function __construct()
    {
        $this->postType = new RecipePostType();
        $this->ajaxHandler = new RecipeAjaxHandler();
        $this->metabox = new RecipeMetabox();
        $this->taxonomyProtection = new RecipeTaxonomyProtection();
    }

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
        $this->postType->init();
        $this->ajaxHandler->init();
        $this->metabox->init();
        $this->taxonomyProtection->init();

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

    public function renderProductRecipeField(): void
    {
        global $post;
        if (!$post instanceof \WP_Post) {
            return;
        }

        $productId = $this->resolveOriginalProductId($post->ID);
        $current = (int) get_post_meta($productId, RecipeMetabox::META_PRODUCT_RECIPE_ID, true);

        $recipes = get_posts([
            'post_type' => RecipePostType::CPT,
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
            if (!$recipe instanceof \WP_Post) {
                continue;
            }
            $id = (int) $recipe->ID;
            echo '<option value="' . esc_attr((string) $id) . '"' . selected($current, $id, false) . '>' . esc_html($recipe->post_title) . '</option>';
        }

        echo '<option value="__new__" class="zs-recipe-new-option">&#43; ' . esc_html__('Add New Recipe', 'zero-sense') . '</option>';
        echo '</select>';
        echo '</p>';
        
        $editUrl = $current > 0 ? admin_url('post.php?post=' . $current . '&action=edit') : '#';
        echo '<p id="zs-recipe-context-actions" style="margin-top:-15px; padding-left:162px; display:' . ($current > 0 ? 'block' : 'none') . ';">';
        echo '<a id="zs-recipe-edit-btn" href="' . esc_url($editUrl) . '" target="_blank" style="font-size:12px; text-decoration:none; color:#2271b1;">';
        echo '<span class="dashicons dashicons-edit" style="font-size:13px; line-height:1.6; vertical-align:middle; margin-top: -4px; margin-right:2px;"></span>';
        echo esc_html__('Edit Recipe', 'zero-sense');
        echo '</a>';
        echo '</p>';
        echo '</div>';

        $hasRabbit = get_post_meta($productId, '_zs_has_rabbit_option', true) === 'yes';
        $currentNoRabbit = (int) get_post_meta($productId, RecipeMetabox::META_PRODUCT_RECIPE_NO_RABBIT, true);

        echo '<div class="options_group" id="zs-recipe-no-rabbit-group" style="border-top:0;' . ($hasRabbit ? '' : 'display:none;') . '">';
        echo '<p class="form-field">';
        echo '<label for="zs_recipe_id_no_rabbit">' . esc_html__('Recipe without rabbit', 'zero-sense') . '</label>';
        echo '<select id="zs_recipe_id_no_rabbit" name="zs_recipe_id_no_rabbit" class="wc-enhanced-select" style="width:50%;">';
        echo '<option value="">' . esc_html__('None', 'zero-sense') . '</option>';

        foreach ($recipes as $recipe) {
            if (!$recipe instanceof \WP_Post) {
                continue;
            }
            $id = (int) $recipe->ID;
            echo '<option value="' . esc_attr((string) $id) . '"' . selected($currentNoRabbit, $id, false) . '>' . esc_html($recipe->post_title) . '</option>';
        }

        echo '<option value="__new__" class="zs-recipe-new-option">&#43; ' . esc_html__('Add New Recipe', 'zero-sense') . '</option>';
        echo '</select>';
        echo '</p>';

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

            var newUrl = '<?php echo esc_js(admin_url('post-new.php?post_type=' . RecipePostType::CPT)); ?>';

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

        if ($originalId !== $product->get_id()) {
            if ($recipeId > 0) {
                update_post_meta($originalId, RecipeMetabox::META_PRODUCT_RECIPE_ID, $recipeId);
            } else {
                delete_post_meta($originalId, RecipeMetabox::META_PRODUCT_RECIPE_ID);
            }
            $noRabbitId = isset($_POST[RecipeMetabox::META_PRODUCT_RECIPE_NO_RABBIT]) ? (int) $_POST[RecipeMetabox::META_PRODUCT_RECIPE_NO_RABBIT] : 0;
            if ($noRabbitId > 0) {
                update_post_meta($originalId, RecipeMetabox::META_PRODUCT_RECIPE_NO_RABBIT, $noRabbitId);
            } else {
                delete_post_meta($originalId, RecipeMetabox::META_PRODUCT_RECIPE_NO_RABBIT);
            }
            return;
        }

        if ($recipeId > 0) {
            $product->update_meta_data(RecipeMetabox::META_PRODUCT_RECIPE_ID, $recipeId);
        } else {
            $product->delete_meta_data(RecipeMetabox::META_PRODUCT_RECIPE_ID);
        }

        $noRabbitRecipeId = isset($_POST[RecipeMetabox::META_PRODUCT_RECIPE_NO_RABBIT]) ? (int) $_POST[RecipeMetabox::META_PRODUCT_RECIPE_NO_RABBIT] : 0;
        if ($noRabbitRecipeId > 0) {
            $product->update_meta_data(RecipeMetabox::META_PRODUCT_RECIPE_NO_RABBIT, $noRabbitRecipeId);
        } else {
            $product->delete_meta_data(RecipeMetabox::META_PRODUCT_RECIPE_NO_RABBIT);
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
}
