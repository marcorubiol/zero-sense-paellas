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

    private static bool $bolosLoaded = false;
    private static array $bolosCounts = [];

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
        add_action('init', [$this, 'ensureCoreRoles'], 20); // After taxonomy registration
        add_action('add_meta_boxes', [$this, 'addStaffMetabox']);
        add_action('add_meta_boxes', [$this, 'addBolosMetabox']);
        add_action('save_post_' . self::CPT, [$this, 'saveStaffMetabox'], 10, 2);
        add_action('admin_footer', [$this, 'addEditRolesLink']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueRoleOrderingAssets']);
        add_action('admin_print_footer_scripts', [$this, 'printRoleOrderingScript']);
        add_action('wp_ajax_zs_update_role_order', [$this, 'ajaxUpdateRoleOrder']);
        add_filter('get_terms_args', [$this, 'orderRoleTerms'], 10, 2);
        add_filter('get_terms', [$this, 'sortRoleTerms'], 10, 3);
        add_action('pre_delete_term', [$this, 'protectCoreRoles'], 10, 2);
        add_filter('user_has_cap', [$this, 'preventCoreRoleDeletion'], 10, 3);
        add_filter(self::TAX_ROLE . '_row_actions', [$this, 'removeCoreRoleActions'], 10, 2);
        add_filter('manage_' . self::CPT . '_posts_columns', [$this, 'addBolosColumn']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'renderBolosColumn'], 10, 2);
        add_action('restrict_manage_posts', [$this, 'renderBolosFilter']);
        add_action('restrict_manage_posts', [$this, 'renderExportButton']);
        add_action('admin_init', [$this, 'handleCsvExport']);
    }
    
    public function sortRoleTerms(array $terms, ?array $taxonomies, array $args): array
    {
        // Only apply to our staff role taxonomy
        if (empty($taxonomies) || !in_array(self::TAX_ROLE, $taxonomies, true)) {
            return $terms;
        }
        
        if (empty($terms) || !($terms[0] instanceof \WP_Term)) {
            return $terms;
        }
        
        // Sort by role_order, then by name for terms without order
        usort($terms, function($a, $b) {
            $order_a = get_term_meta($a->term_id, 'role_order', true);
            $order_b = get_term_meta($b->term_id, 'role_order', true);
            
            $order_a = $order_a !== '' ? (int)$order_a : 999;
            $order_b = $order_b !== '' ? (int)$order_b : 999;
            
            if ($order_a === $order_b) {
                return strcmp($a->name, $b->name);
            }
            
            return $order_a - $order_b;
        });
        
        return $terms;
    }
    
    public function orderRoleTerms(array $args, array $taxonomies): array
    {
        // Only apply to our staff role taxonomy
        if (in_array(self::TAX_ROLE, $taxonomies, true)) {
            $args['orderby'] = 'meta_value_num';
            $args['meta_key'] = 'role_order';
            $args['order'] = 'ASC';
            
            // Force cache invalidation
            $args['cache_results'] = false;
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
                $term_id = (int)$item['term_id'];
                $new_order = (int)$item['order'];
                
                update_term_meta($term_id, 'role_order', $new_order);
            }
        }
        
        // Clear WordPress term cache to force refresh
        wp_cache_delete('zs_staff_role', 'terms');
        wp_cache_delete('zs_staff_role', 'term_hierarchy');
        
        // Also clear the last_changed cache
        wp_cache_set_last_changed('terms');
        
        wp_send_json_success(['message' => 'Order updated successfully']);
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

    /**
     * Core roles that cannot be deleted (used for material calculations)
     */
    private function getCoreRoles(): array
    {
        return [
            'cap-de-bolo' => 'Cap de Bolo',
            'cuiner-a' => 'Cuiner/a',
            'ajudant-a-de-cuina' => 'Ajudant/a de cuina',
            'cambrer-a-barra' => 'Cambrer/a - Barra',
            'cockteler-a' => 'Cockteler/a',
            'tallador-a-de-pernil' => 'Tallador/a de pernil',
        ];
    }
    
    /**
     * Ensure core roles exist in database
     */
    public function ensureCoreRoles(): void
    {
        foreach ($this->getCoreRoles() as $slug => $name) {
            $term = get_term_by('slug', $slug, self::TAX_ROLE);
            
            if (!$term) {
                wp_insert_term($name, self::TAX_ROLE, [
                    'slug' => $slug,
                    'description' => __('Core system role - cannot be deleted', 'zero-sense'),
                ]);
            }
        }
    }
    
    /**
     * Remove delete and edit actions for core roles in admin
     */
    public function removeCoreRoleActions(array $actions, \WP_Term $term): array
    {
        if (in_array($term->slug, array_keys($this->getCoreRoles()), true)) {
            unset($actions['delete']);
            unset($actions['edit']);
            unset($actions['inline hide-if-no-js']);
            
            // Add visual indicator
            $actions['protected'] = '<span style="color: #2271b1;">🔒 ' . __('System Role', 'zero-sense') . '</span>';
        }
        
        return $actions;
    }
    
    /**
     * Prevent deletion of core roles
     */
    public function protectCoreRoles($term_id, $taxonomy): void
    {
        if ($taxonomy !== self::TAX_ROLE) {
            return;
        }
        
        $term = get_term($term_id, $taxonomy);
        if (!$term || is_wp_error($term)) {
            return;
        }
        
        if (in_array($term->slug, array_keys($this->getCoreRoles()), true)) {
            wp_die(
                __('This role cannot be deleted as it is required for automatic material calculations.', 'zero-sense'),
                __('Protected Role', 'zero-sense'),
                ['back_link' => true]
            );
        }
    }
    
    /**
     * Remove delete capability for core roles
     */
    public function preventCoreRoleDeletion($allcaps, $caps, $args): array
    {
        // Check if this is a delete_term capability check
        if (!isset($args[0]) || $args[0] !== 'delete_term') {
            return $allcaps;
        }
        
        // Get term ID from args
        $term_id = $args[2] ?? 0;
        if (!$term_id) {
            return $allcaps;
        }
        
        // Check if it's our taxonomy
        $term = get_term($term_id);
        if (!$term || is_wp_error($term) || $term->taxonomy !== self::TAX_ROLE) {
            return $allcaps;
        }
        
        // Prevent deletion of core roles
        if (in_array($term->slug, array_keys($this->getCoreRoles()), true)) {
            $allcaps['delete_term'] = false;
        }
        
        return $allcaps;
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
            'menu_position' => 65,
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

    // -------------------------------------------------------------------------
    // Bolos column + month filter
    // -------------------------------------------------------------------------

    public function addBolosColumn(array $columns): array
    {
        $month = sanitize_text_field($_GET['zs_bolos_month'] ?? '');
        $year = sanitize_text_field($_GET['zs_bolos_year'] ?? '');

        $parts = ['Bolos'];
        if ($month !== '') {
            $ts = mktime(0, 0, 0, (int) $month, 1, 2000);
            if ($ts) {
                $parts[] = ucfirst(date_i18n('F', $ts));
            }
        }
        if ($year !== '') {
            $parts[] = $year;
        }

        $result = [];
        foreach ($columns as $key => $val) {
            $result[$key] = $val;
            if ($key === 'title') {
                $result['bolos_count'] = implode(' ', $parts);
            }
        }
        return $result;
    }

    public function renderBolosColumn(string $column, int $postId): void
    {
        if ($column !== 'bolos_count') {
            return;
        }
        $counts = self::getBolosCounts();
        echo (int) ($counts[$postId] ?? 0);
    }

    public function renderBolosFilter(string $postType): void
    {
        if ($postType !== self::CPT) {
            return;
        }

        $currentYear = sanitize_text_field($_GET['zs_bolos_year'] ?? '');
        $currentMonth = sanitize_text_field($_GET['zs_bolos_month'] ?? '');
        $thisYear = (int) current_time('Y');

        // Year filter
        echo '<select name="zs_bolos_year">';
        echo '<option value="">' . esc_html__('All years', 'zero-sense') . '</option>';
        for ($y = $thisYear; $y >= $thisYear - 3; $y--) {
            echo '<option value="' . esc_attr((string) $y) . '"' . selected($currentYear, (string) $y, false) . '>' . esc_html((string) $y) . '</option>';
        }
        echo '</select>';

        // Month filter
        echo '<select name="zs_bolos_month">';
        echo '<option value="">' . esc_html__('All months', 'zero-sense') . '</option>';
        for ($m = 1; $m <= 12; $m++) {
            $val = (string) $m;
            $label = ucfirst(date_i18n('F', mktime(0, 0, 0, $m, 1, $thisYear)));
            echo '<option value="' . esc_attr($val) . '"' . selected($currentMonth, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    private static function getBolosCounts(): array
    {
        if (self::$bolosLoaded) {
            return self::$bolosCounts;
        }
        self::$bolosLoaded = true;

        $filterYear = sanitize_text_field($_GET['zs_bolos_year'] ?? '');
        $filterMonth = sanitize_text_field($_GET['zs_bolos_month'] ?? '');

        if ($filterYear !== '' && $filterMonth !== '') {
            $from = sprintf('%s-%02d-01', $filterYear, (int) $filterMonth);
            $to = date('Y-m-t', strtotime($from));
        } elseif ($filterYear !== '') {
            $from = $filterYear . '-01-01';
            $to = $filterYear . '-12-31';
        } elseif ($filterMonth !== '') {
            $thisYear = (int) current_time('Y');
            $from = sprintf('%d-%02d-01', $thisYear, (int) $filterMonth);
            $to = date('Y-m-t', strtotime($from));
        } else {
            $from = '2000-01-01';
            $to = '2099-12-31';
        }

        $orders = wc_get_orders([
            'limit'      => -1,
            'status'     => array_keys(wc_get_order_statuses()),
            'return'     => 'objects',
            'meta_query' => [[
                'key'     => 'zs_event_date',
                'value'   => [$from, $to],
                'compare' => 'BETWEEN',
                'type'    => 'CHAR',
            ]],
        ]);

        foreach ($orders as $order) {
            $staff = $order->get_meta('zs_event_staff', true);
            if (!is_array($staff)) {
                continue;
            }
            $seen = [];
            foreach ($staff as $assignment) {
                if (!is_array($assignment) || empty($assignment['staff_id'])) {
                    continue;
                }
                $sid = (int) $assignment['staff_id'];
                if (isset($seen[$sid])) {
                    continue;
                }
                $seen[$sid] = true;
                self::$bolosCounts[$sid] = (self::$bolosCounts[$sid] ?? 0) + 1;
            }
        }

        return self::$bolosCounts;
    }

    // -------------------------------------------------------------------------
    // Bolos history metabox (single staff edit page)
    // -------------------------------------------------------------------------

    public function addBolosMetabox(): void
    {
        add_meta_box(
            'zs_staff_bolos_history',
            __('Event History', 'zero-sense'),
            [$this, 'renderBolosMetabox'],
            self::CPT,
            'normal',
            'low'
        );
    }

    public function renderBolosMetabox(WP_Post $post): void
    {
        $staffId = $post->ID;

        $orders = wc_get_orders([
            'limit'      => -1,
            'status'     => array_keys(wc_get_order_statuses()),
            'return'     => 'objects',
            'meta_query' => [[
                'key'     => 'zs_event_date',
                'value'   => '',
                'compare' => '!=',
            ]],
        ]);

        $events = [];
        foreach ($orders as $order) {
            $staff = $order->get_meta('zs_event_staff', true);
            if (!is_array($staff)) {
                continue;
            }
            foreach ($staff as $assignment) {
                if (!is_array($assignment) || (int) ($assignment['staff_id'] ?? 0) !== $staffId) {
                    continue;
                }
                $eventDate = $order->get_meta('zs_event_date', true);
                $ts = is_numeric($eventDate) ? (int) $eventDate : strtotime($eventDate);
                $roleSlug = $assignment['role'] ?? '';
                $roleName = '';
                if ($roleSlug !== '') {
                    $term = get_term_by('slug', $roleSlug, self::TAX_ROLE);
                    $roleName = $term instanceof \WP_Term ? $term->name : $roleSlug;
                }
                $events[] = [
                    'date'     => $ts ? date('Y-m-d', $ts) : $eventDate,
                    'date_fmt' => $ts ? date_i18n('d/m/Y', $ts) : $eventDate,
                    'month'    => $ts ? ucfirst(date_i18n('F Y', $ts)) : '',
                    'role'     => $roleName,
                    'order_id' => $order->get_id(),
                ];
            }
        }

        usort($events, function (array $a, array $b): int {
            return strcmp($b['date'], $a['date']);
        });

        $total = count($events);
        $byYear = [];
        foreach ($events as $e) {
            $y = substr($e['date'], 0, 4);
            $byYear[$y] = ($byYear[$y] ?? 0) + 1;
        }
        krsort($byYear);

        echo '<div style="margin-bottom:12px;">';
        echo '<strong>' . esc_html__('Total:', 'zero-sense') . '</strong> ' . (int) $total . ' bolos';
        if (!empty($byYear)) {
            $parts = [];
            foreach ($byYear as $y => $c) {
                $parts[] = $y . ': ' . $c;
            }
            echo ' &nbsp;(' . esc_html(implode(', ', $parts)) . ')';
        }
        echo '</div>';

        if (empty($events)) {
            echo '<p>' . esc_html__('No events found.', 'zero-sense') . '</p>';
            return;
        }

        echo '<table class="widefat fixed striped" style="margin-top:8px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Date', 'zero-sense') . '</th>';
        echo '<th>' . esc_html__('Role', 'zero-sense') . '</th>';
        echo '<th>' . esc_html__('Order', 'zero-sense') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($events as $e) {
            $orderUrl = admin_url('post.php?post=' . $e['order_id'] . '&action=edit');
            echo '<tr>';
            echo '<td>' . esc_html($e['date_fmt']) . '</td>';
            echo '<td>' . esc_html($e['role']) . '</td>';
            echo '<td><a href="' . esc_url($orderUrl) . '">#' . esc_html((string) $e['order_id']) . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // -------------------------------------------------------------------------
    // CSV Export
    // -------------------------------------------------------------------------

    public function renderExportButton(string $postType): void
    {
        if ($postType !== self::CPT) {
            return;
        }
        $url = wp_nonce_url(admin_url('edit.php?post_type=' . self::CPT . '&zs_export_staff_csv=1'), 'zs_export_staff_csv');
        echo '<a href="' . esc_url($url) . '" class="button" style="margin-left:8px;">Export CSV</a>';
    }

    public function handleCsvExport(): void
    {
        if (empty($_GET['zs_export_staff_csv'])) {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions.', 'zero-sense'));
        }
        check_admin_referer('zs_export_staff_csv');

        $orders = wc_get_orders([
            'limit'      => -1,
            'return'     => 'objects',
            'meta_query' => [[
                'key'     => 'zs_event_date',
                'value'   => '',
                'compare' => '!=',
            ]],
        ]);

        $staffNames = [];
        $rows = [];

        foreach ($orders as $order) {
            $eventDate = $order->get_meta('zs_event_date', true);
            if (empty($eventDate)) {
                continue;
            }

            $staff = $order->get_meta('zs_event_staff', true);
            if (!is_array($staff)) {
                continue;
            }

            foreach ($staff as $assignment) {
                if (!is_array($assignment) || empty($assignment['staff_id'])) {
                    continue;
                }

                $staffId = (int) $assignment['staff_id'];
                $roleSlug = $assignment['role'] ?? '';

                if (!isset($staffNames[$staffId])) {
                    $staffNames[$staffId] = get_the_title($staffId) ?: '(#' . $staffId . ')';
                }

                $roleName = '';
                if ($roleSlug !== '') {
                    $term = get_term_by('slug', $roleSlug, self::TAX_ROLE);
                    if ($term instanceof \WP_Term) {
                        $roleName = $term->name;
                    } else {
                        $roleName = $roleSlug;
                    }
                }

                $ts = is_numeric($eventDate) ? (int) $eventDate : strtotime($eventDate);
                $dateFormatted = $ts ? date('Y-m-d', $ts) : $eventDate;
                $monthName = $ts ? ucfirst(date_i18n('F', $ts)) : '';
                $year = $ts ? date('Y', $ts) : '';

                $rows[] = [
                    'staff'      => $staffNames[$staffId],
                    'role'       => $roleName,
                    'event_date' => $dateFormatted,
                    'month'      => $monthName,
                    'year'       => $year,
                    'order_id'   => $order->get_id(),
                ];
            }
        }

        usort($rows, function (array $a, array $b): int {
            $cmp = strcmp($a['staff'], $b['staff']);
            return $cmp !== 0 ? $cmp : strcmp($a['event_date'], $b['event_date']);
        });

        $filename = 'staff-assignments-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        fputcsv($out, ['Staff', 'Role', 'Event Date', 'Month', 'Year', 'Order ID'], ';');

        foreach ($rows as $row) {
            fputcsv($out, array_values($row), ';');
        }

        fclose($out);
        exit;
    }
}
