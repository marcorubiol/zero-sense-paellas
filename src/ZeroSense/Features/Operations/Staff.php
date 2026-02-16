<?php
namespace ZeroSense\Features\Operations;

use ZeroSense\Core\FeatureInterface;
use WP_Post;

class Staff implements FeatureInterface
{
    private const CPT = 'zs_staff_member';
    private const TAX_ROLE = 'zs_staff_role';

    private const META_PHONE = 'zs_staff_phone';
    private const META_EMAIL = 'zs_staff_email';
    private const META_NOTES = 'zs_staff_notes';

    private const NONCE_FIELD = 'zs_staff_nonce';
    private const NONCE_ACTION = 'zs_staff_save';

    public function getName(): string
    {
        return __('Staff Members', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Manage event staff members with roles, contact information, and notes.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'Operations';
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
        add_action('add_meta_boxes', [$this, 'addStaffMetabox']);
        add_action('save_post_' . self::CPT, [$this, 'saveStaffMetabox'], 10, 2);
        add_action('admin_footer', [$this, 'addEditRolesLink']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueRoleOrderingAssets']);
        add_action('admin_print_footer_scripts', [$this, 'printRoleOrderingScript']);
        add_action('wp_ajax_zs_update_role_order', [$this, 'ajaxUpdateRoleOrder']);
        add_filter('get_terms_args', [$this, 'orderRoleTerms'], 10, 2);
    }
    
    public function orderRoleTerms(array $args, array $taxonomies): array
    {
        // Only apply to our staff role taxonomy
        if (in_array(self::TAX_ROLE, $taxonomies, true)) {
            $args['orderby'] = 'meta_value_num';
            $args['meta_key'] = 'role_order';
            $args['order'] = 'ASC';
        }
        return $args;
    }
    
    public function enqueueRoleOrderingAssets(string $hook): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->taxonomy !== self::TAX_ROLE) {
            return;
        }
        
        wp_enqueue_script('jquery-ui-sortable');
    }
    
    public function printRoleOrderingScript(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->taxonomy !== self::TAX_ROLE) {
            return;
        }
        ?>
        <style>
            .column-drag_handle {
                width: 40px;
                text-align: center;
                padding: 0 !important;
            }
            .zs-drag-handle {
                cursor: move;
                display: inline-block;
                padding: 8px 10px;
                font-size: 18px;
                color: #8c8f94;
                user-select: none;
            }
            .zs-drag-handle:hover {
                color: #2271b1;
            }
            .wp-list-table tbody tr.ui-sortable-helper {
                background-color: #f0f0f1;
                opacity: 0.8;
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            }
            .wp-list-table tbody .ui-state-highlight {
                height: 50px;
                background-color: #e5f5fa;
                border: 2px dashed #2271b1;
            }
            .wp-list-table thead .column-drag_handle,
            .wp-list-table tfoot .column-drag_handle {
                width: 40px;
            }
        </style>
        <script>
        jQuery(document).ready(function($) {
            var table = $('.wp-list-table tbody');
            
            if (table.length && $.fn.sortable) {
                table.sortable({
                    items: 'tr',
                    cursor: 'move',
                    axis: 'y',
                    handle: '.zs-drag-handle',
                    placeholder: 'ui-state-highlight',
                    helper: function(e, tr) {
                        var $originals = tr.children();
                        var $helper = tr.clone();
                        $helper.children().each(function(index) {
                            $(this).width($originals.eq(index).width());
                        });
                        return $helper;
                    },
                    start: function(e, ui) {
                        ui.placeholder.height(ui.item.height());
                    },
                    update: function(event, ui) {
                        var order = [];
                        table.find('tr').each(function(index) {
                            var termId = $(this).attr('id');
                            if (termId) {
                                termId = termId.replace('tag-', '');
                                order.push({
                                    term_id: termId,
                                    order: index
                                });
                            }
                        });
                        
                        $.post(ajaxurl, {
                            action: 'zs_update_role_order',
                            nonce: '<?php echo wp_create_nonce('zs_role_order'); ?>',
                            order: order
                        }, function(response) {
                            if (response.success) {
                                table.find('tr').each(function(index) {
                                    $(this).find('.column-role_order').text(index);
                                });
                            }
                        });
                    }
                });
            }
        });
        </script>
        <?php
    }
    
    public function ajaxUpdateRoleOrder(): void
    {
        check_ajax_referer('zs_role_order', 'nonce');
        
        if (!current_user_can('manage_categories')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $order = isset($_POST['order']) ? $_POST['order'] : [];
        
        foreach ($order as $item) {
            if (isset($item['term_id']) && isset($item['order'])) {
                update_term_meta((int)$item['term_id'], 'role_order', (int)$item['order']);
            }
        }
        
        wp_send_json_success();
    }
    
    public function addEditRolesLink(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== self::CPT) {
            return;
        }
        
        $edit_roles_url = admin_url('edit-tags.php?taxonomy=' . self::TAX_ROLE . '&post_type=' . self::CPT);
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Find the Staff Roles taxonomy metabox (hierarchical style)
            var $taxonomyDiv = $('#<?php echo esc_js(self::TAX_ROLE); ?>div');
            if ($taxonomyDiv.length) {
                // Add edit roles link after the "Add new role" link
                var $addNewLink = $taxonomyDiv.find('#<?php echo esc_js(self::TAX_ROLE); ?>-add-toggle');
                if ($addNewLink.length) {
                    $addNewLink.after(
                        ' | <a href="<?php echo esc_js($edit_roles_url); ?>" style="text-decoration: none;">' +
                        '<?php echo esc_js(__('Edit Roles', 'zero-sense')); ?>' +
                        '</a>'
                    );
                }
            }
        });
        </script>
        <?php
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function getConditions(): array
    {
        return ['is_admin'];
    }

    public function registerContentTypes(): void
    {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => __('Staff Members', 'zero-sense'),
                'singular_name' => __('Staff Member', 'zero-sense'),
                'add_new' => __('Add staff member', 'zero-sense'),
                'add_new_item' => __('Add new staff member', 'zero-sense'),
                'edit_item' => __('Edit staff member', 'zero-sense'),
                'new_item' => __('New staff member', 'zero-sense'),
                'view_item' => __('View staff member', 'zero-sense'),
                'search_items' => __('Search staff members', 'zero-sense'),
                'not_found' => __('No staff members found', 'zero-sense'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'event-operations',
            'menu_position' => 57,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);

        register_taxonomy(self::TAX_ROLE, [self::CPT], [
            'labels' => [
                'name' => __('Staff Roles', 'zero-sense'),
                'singular_name' => __('Staff Role', 'zero-sense'),
                'search_items' => __('Search roles', 'zero-sense'),
                'all_items' => __('All roles', 'zero-sense'),
                'edit_item' => __('Edit role', 'zero-sense'),
                'update_item' => __('Update role', 'zero-sense'),
                'add_new_item' => __('Add new role', 'zero-sense'),
                'new_item_name' => __('New role name', 'zero-sense'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_admin_column' => true,
            'hierarchical' => true,
        ]);
        
        // Add order field to taxonomy
        add_action(self::TAX_ROLE . '_add_form_fields', [$this, 'addOrderField']);
        add_action(self::TAX_ROLE . '_edit_form_fields', [$this, 'editOrderField']);
        add_action('created_' . self::TAX_ROLE, [$this, 'saveOrderField']);
        add_action('edited_' . self::TAX_ROLE, [$this, 'saveOrderField']);
        add_filter('manage_edit-' . self::TAX_ROLE . '_columns', [$this, 'addOrderColumn']);
        add_filter('manage_' . self::TAX_ROLE . '_custom_column', [$this, 'renderOrderColumn'], 10, 3);
    }
    
    public function addOrderField(): void
    {
        ?>
        <div class="form-field">
            <label for="role-order"><?php _e('Order', 'zero-sense'); ?></label>
            <input type="number" name="role_order" id="role-order" value="0" min="0" step="1">
            <p class="description"><?php _e('Order in which this role appears (lower numbers first)', 'zero-sense'); ?></p>
        </div>
        <?php
    }
    
    public function editOrderField(\WP_Term $term): void
    {
        $order = get_term_meta($term->term_id, 'role_order', true);
        if ($order === '') {
            $order = 0;
        }
        ?>
        <tr class="form-field">
            <th scope="row">
                <label for="role-order"><?php _e('Order', 'zero-sense'); ?></label>
            </th>
            <td>
                <input type="number" name="role_order" id="role-order" value="<?php echo esc_attr($order); ?>" min="0" step="1">
                <p class="description"><?php _e('Order in which this role appears (lower numbers first)', 'zero-sense'); ?></p>
            </td>
        </tr>
        <?php
    }
    
    public function saveOrderField(int $term_id): void
    {
        if (isset($_POST['role_order'])) {
            $order = absint($_POST['role_order']);
            update_term_meta($term_id, 'role_order', $order);
        }
    }
    
    public function addOrderColumn(array $columns): array
    {
        $new_columns = [];
        // Add drag handle as first column
        $new_columns['drag_handle'] = '';
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'name') {
                $new_columns['role_order'] = __('Order', 'zero-sense');
            }
        }
        return $new_columns;
    }
    
    public function renderOrderColumn(string $content, string $column_name, int $term_id): string
    {
        if ($column_name === 'drag_handle') {
            return '<span class="zs-drag-handle" style="cursor: move; display: inline-block; padding: 5px;">☰</span>';
        }
        
        if ($column_name === 'role_order') {
            $order = get_term_meta($term_id, 'role_order', true);
            return $order !== '' ? esc_html($order) : '0';
        }
        return $content;
    }

    public function addStaffMetabox(): void
    {
        add_meta_box(
            'zs_staff_details',
            __('Staff details', 'zero-sense'),
            [$this, 'renderStaffMetabox'],
            self::CPT,
            'normal',
            'high'
        );
    }

    public function renderStaffMetabox(WP_Post $post): void
    {
        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }

        $phone = get_post_meta($post->ID, self::META_PHONE, true);
        $email = get_post_meta($post->ID, self::META_EMAIL, true);
        $notes = get_post_meta($post->ID, self::META_NOTES, true);

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        ?>
        <div class="zs-staff-metabox">
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="zs_staff_phone"><?php esc_html_e('Phone', 'zero-sense'); ?></label>
                    </th>
                    <td>
                        <input type="tel" 
                               id="zs_staff_phone" 
                               name="zs_staff_phone" 
                               value="<?php echo esc_attr($phone); ?>" 
                               class="regular-text"
                               placeholder="<?php esc_attr_e('Optional', 'zero-sense'); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="zs_staff_email"><?php esc_html_e('Email', 'zero-sense'); ?></label>
                    </th>
                    <td>
                        <input type="email" 
                               id="zs_staff_email" 
                               name="zs_staff_email" 
                               value="<?php echo esc_attr($email); ?>" 
                               class="regular-text"
                               placeholder="<?php esc_attr_e('Optional', 'zero-sense'); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="zs_staff_notes"><?php esc_html_e('Notes', 'zero-sense'); ?></label>
                    </th>
                    <td>
                        <textarea id="zs_staff_notes" 
                                  name="zs_staff_notes" 
                                  rows="4" 
                                  class="large-text"
                                  placeholder="<?php esc_attr_e('Optional notes about this staff member', 'zero-sense'); ?>"><?php echo esc_textarea($notes); ?></textarea>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    public function saveStaffMetabox(int $postId, WP_Post $post): void
    {
        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        if (!isset($_POST[self::NONCE_FIELD]) || !wp_verify_nonce((string) $_POST[self::NONCE_FIELD], self::NONCE_ACTION)) {
            return;
        }

        $phone = isset($_POST['zs_staff_phone']) ? sanitize_text_field((string) $_POST['zs_staff_phone']) : '';
        $email = isset($_POST['zs_staff_email']) ? sanitize_email((string) $_POST['zs_staff_email']) : '';
        $notes = isset($_POST['zs_staff_notes']) ? sanitize_textarea_field((string) $_POST['zs_staff_notes']) : '';

        if ($phone !== '') {
            update_post_meta($postId, self::META_PHONE, $phone);
        } else {
            delete_post_meta($postId, self::META_PHONE);
        }

        if ($email !== '') {
            update_post_meta($postId, self::META_EMAIL, $email);
        } else {
            delete_post_meta($postId, self::META_EMAIL);
        }

        if ($notes !== '') {
            update_post_meta($postId, self::META_NOTES, $notes);
        } else {
            delete_post_meta($postId, self::META_NOTES);
        }
    }
}
