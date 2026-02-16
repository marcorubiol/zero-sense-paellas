<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Components;

use WC_Order;
use WP_Post;
use WP_Term;
use ZeroSense\Features\WooCommerce\EventManagement\Components\FieldChangeTracker;

class StaffAssignmentMetabox
{
    private const META_KEY = 'zs_event_staff';
    private const NONCE_FIELD = 'zs_staff_assignment_nonce';
    private const NONCE_ACTION = 'zs_staff_assignment_save';

    private const STAFF_CPT = 'zs_staff_member';
    private const STAFF_TAX = 'zs_staff_role';

    private const META_PHONE = 'zs_staff_phone';
    private const META_EMAIL = 'zs_staff_email';

    public function register(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('add_meta_boxes', [$this, 'addMetabox']);
        add_action('woocommerce_process_shop_order_meta', [$this, 'save'], 10, 1);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_zs_create_staff_member', [$this, 'ajaxCreateStaffMember']);
    }
    
    public function ajaxCreateStaffMember(): void
    {
        check_ajax_referer('zs_create_staff', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $role = isset($_POST['role']) ? sanitize_text_field($_POST['role']) : '';
        
        if (empty($name)) {
            wp_send_json_error('Name is required');
        }
        
        // Create staff member
        $post_id = wp_insert_post([
            'post_title' => $name,
            'post_type' => self::STAFF_CPT,
            'post_status' => 'publish',
        ]);
        
        if (is_wp_error($post_id)) {
            wp_send_json_error($post_id->get_error_message());
        }
        
        // Assign role if provided
        if (!empty($role)) {
            wp_set_object_terms($post_id, $role, self::STAFF_TAX);
        }
        
        wp_send_json_success([
            'id' => $post_id,
            'name' => $name,
        ]);
    }

    public function addMetabox(): void
    {
        $screen = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';
        
        add_meta_box(
            'zs_event_staff_assignment',
            __('Event Staff', 'zero-sense'),
            [$this, 'render'],
            $screen,
            'normal',
            'default'
        );
    }

    public function enqueueAssets(string $hook): void
    {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        
        // Support both classic orders and HPOS
        $is_order_screen = ($screen->post_type === 'shop_order') || 
                          ($screen->id === 'woocommerce_page_wc-orders') ||
                          ($screen->id === wc_get_page_screen_id('shop-order'));
        
        if (!$is_order_screen) {
            return;
        }

        wp_enqueue_style('woocommerce_admin_styles');
        wp_enqueue_script('selectWoo');
    }

    public function render($post_or_order): void
    {
        // Support both classic (WP_Post) and HPOS (WC_Order) systems
        if ($post_or_order instanceof WP_Post) {
            $order = wc_get_order($post_or_order->ID);
        } elseif ($post_or_order instanceof WC_Order) {
            $order = $post_or_order;
        } else {
            return;
        }
        
        if (!$order instanceof WC_Order) {
            return;
        }

        $staffAssignments = $order->get_meta(self::META_KEY, true);
        if (!is_array($staffAssignments)) {
            $staffAssignments = [];
        }

        $roles = $this->getStaffRoles();

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        ?>
        <div class="zs-staff-assignment-wrapper">
            <?php foreach ($roles as $roleSlug => $roleName): ?>
                <?php
                $assignedStaff = array_filter($staffAssignments, function($assignment) use ($roleSlug) {
                    return isset($assignment['role']) && $assignment['role'] === $roleSlug;
                });
                
                // Get staff filtered by this specific role
                $roleStaff = $this->getAllStaff($roleSlug);
                
                // Debug: show what we got
                $debugInfo = sprintf(
                    'Role: %s (slug: %s) - Found %d staff members',
                    $roleName,
                    $roleSlug,
                    count($roleStaff)
                );
                ?>
                
                <div style="border-top: 1px solid #dcdcde; padding-top: 12px; margin-top: 12px;">
                    <h4 style="margin: 0 0 8px 0; font-size: 12px; font-weight: 600; color: #50575e; text-transform: uppercase; letter-spacing: 0.5px;">
                        <?php echo esc_html($roleName); ?>
                        <small style="font-weight: normal; color: #999; margin-left: 8px;"><?php echo esc_html($debugInfo); ?></small>
                    </h4>
                    
                    <div class="zs-staff-role-section" data-role="<?php echo esc_attr($roleSlug); ?>">
                        <?php if (!empty($assignedStaff)): ?>
                            <?php foreach ($assignedStaff as $index => $assignment): ?>
                                <?php
                                $staffId = isset($assignment['staff_id']) ? (int) $assignment['staff_id'] : 0;
                                $staffPost = $staffId > 0 ? get_post($staffId) : null;
                                $staffName = $staffPost ? $staffPost->post_title : '';
                                $phone = $staffId > 0 ? get_post_meta($staffId, self::META_PHONE, true) : '';
                                $email = $staffId > 0 ? get_post_meta($staffId, self::META_EMAIL, true) : '';
                                ?>
                                <div class="zs-staff-row" style="display: flex; gap: 10px; align-items: center; margin-bottom: 8px;" data-staff-id="<?php echo esc_attr($staffId); ?>">
                                    <input type="hidden" name="zs_event_staff[<?php echo esc_attr($roleSlug); ?>][]" value="<?php echo esc_attr($staffId); ?>" class="zs-staff-hidden-input">
                                    
                                    <div class="zs-staff-display" style="flex: 1; display: flex; gap: 10px; align-items: center;">
                                        <strong style="min-width: 150px;"><?php echo esc_html($staffName); ?></strong>
                                        <span class="zs-staff-info" style="flex: 1; font-size: 12px; color: #646970;">
                                            <?php if ($phone || $email): ?>
                                                <?php if ($phone): ?>
                                                    📞 <?php echo esc_html($phone); ?>
                                                <?php endif; ?>
                                                <?php if ($phone && $email): ?> | <?php endif; ?>
                                                <?php if ($email): ?>
                                                    ✉️ <?php echo esc_html($email); ?>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    
                                    <select class="zs-staff-select" 
                                            style="flex: 1; max-width: 300px; display: none;"
                                            data-role="<?php echo esc_attr($roleSlug); ?>">
                                        <option value=""><?php esc_html_e('Select staff member...', 'zero-sense'); ?></option>
                                        <?php foreach ($roleStaff as $staff): ?>
                                            <option value="<?php echo esc_attr((string) $staff->ID); ?>" 
                                                    <?php selected($staffId, $staff->ID); ?>
                                                    data-phone="<?php echo esc_attr(get_post_meta($staff->ID, self::META_PHONE, true)); ?>"
                                                    data-email="<?php echo esc_attr(get_post_meta($staff->ID, self::META_EMAIL, true)); ?>">
                                                <?php echo esc_html($staff->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    
                                    <button type="button" class="button button-small zs-staff-edit" style="flex-shrink: 0;">
                                        <?php esc_html_e('Change', 'zero-sense'); ?>
                                    </button>
                                    <button type="button" class="button button-small zs-staff-remove" style="flex-shrink: 0;">
                                        <?php esc_html_e('Remove', 'zero-sense'); ?>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <a href="#" 
                           class="zs-staff-add" 
                           data-role="<?php echo esc_attr($roleSlug); ?>"
                           style="display: inline-block; margin-top: 8px; text-decoration: none; font-size: 13px;">
                            + <?php esc_html_e('Add staff member', 'zero-sense'); ?>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <script>
        (function($) {
            'use strict';
            
            $(document).ready(function() {
                // Initialize selectWoo on existing selects with tags functionality
                $('.zs-staff-select').selectWoo({
                    width: '100%',
                    tags: true,
                    createTag: function(params) {
                        var term = $.trim(params.term);
                        if (term === '') {
                            return null;
                        }
                        return {
                            id: 'new:' + term,
                            text: term + ' (Create new)',
                            newTag: true
                        };
                    }
                });
                
                // Handle edit/change button
                $(document).on('click', '.zs-staff-edit', function() {
                    var $row = $(this).closest('.zs-staff-row');
                    var $display = $row.find('.zs-staff-display');
                    var $select = $row.find('.zs-staff-select');
                    var $editBtn = $(this);
                    
                    // Toggle between display and select mode
                    if ($select.is(':visible')) {
                        // Save mode - hide select, show display
                        var selectedValue = $select.val();
                        if (selectedValue) {
                            var $option = $select.find('option:selected');
                            var staffName = $option.text();
                            var phone = $option.data('phone') || '';
                            var email = $option.data('email') || '';
                            
                            // Update hidden input
                            $row.find('.zs-staff-hidden-input').val(selectedValue);
                            
                            // Update display
                            $display.find('strong').text(staffName);
                            var infoHtml = '';
                            if (phone || email) {
                                if (phone) infoHtml += '📞 ' + phone;
                                if (phone && email) infoHtml += ' | ';
                                if (email) infoHtml += '✉️ ' + email;
                            }
                            $display.find('.zs-staff-info').html(infoHtml);
                        }
                        
                        $select.hide();
                        $display.show();
                        $editBtn.text('<?php echo esc_js(__('Change', 'zero-sense')); ?>');
                    } else {
                        // Edit mode - show select, hide display
                        $display.hide();
                        $select.show().selectWoo({
                            width: '100%',
                            tags: true,
                            createTag: function(params) {
                                var term = $.trim(params.term);
                                if (term === '') return null;
                                return {
                                    id: 'new:' + term,
                                    text: term + ' (Create new)',
                                    newTag: true
                                };
                            }
                        });
                        $editBtn.text('<?php echo esc_js(__('Save', 'zero-sense')); ?>');
                    }
                });
                
                // Handle selection change - create new staff if needed
                $(document).on('change', '.zs-staff-select', function() {
                    var $select = $(this);
                    var value = $select.val();
                    var $row = $select.closest('.zs-staff-row');
                    
                    // Check if it's a new staff member
                    if (value && value.indexOf('new:') === 0) {
                        var staffName = value.replace('new:', '');
                        var role = $select.data('role');
                        
                        // Create the staff member via AJAX
                        $.post(ajaxurl, {
                            action: 'zs_create_staff_member',
                            nonce: '<?php echo wp_create_nonce('zs_create_staff'); ?>',
                            name: staffName,
                            role: role
                        }, function(response) {
                            if (response.success) {
                                // Replace the temporary option with the real one
                                $select.find('option[value="' + value + '"]').remove();
                                var newOption = new Option(staffName, response.data.id, true, true);
                                $select.append(newOption).trigger('change');
                                
                                // Auto-save after creating
                                $row.find('.zs-staff-edit').click();
                            } else {
                                alert('Error creating staff member: ' + (response.data || 'Unknown error'));
                                $select.val('').trigger('change');
                            }
                        });
                    }
                });
                
                // Add staff member
                $(document).on('click', '.zs-staff-add', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var role = $btn.data('role');
                    var $section = $btn.closest('.zs-staff-role-section');
                    
                    var $newRow = $('<div class="zs-staff-row" style="display: flex; gap: 10px; align-items: center; margin-bottom: 8px;"></div>');
                    
                    var $hiddenInput = $('<input type="hidden" class="zs-staff-hidden-input" name="zs_event_staff[' + role + '][]" value="">');
                    var $display = $('<div class="zs-staff-display" style="flex: 1; display: none; gap: 10px; align-items: center;"><strong style="min-width: 150px;"></strong><span class="zs-staff-info" style="flex: 1; font-size: 12px; color: #646970;"></span></div>');
                    
                    var $select = $('<select class="zs-staff-select" style="flex: 1; max-width: 300px;" data-role="' + role + '"></select>');
                    $select.append('<option value=""><?php echo esc_js(__('Select staff member...', 'zero-sense')); ?></option>');
                    
                    // Get staff options for this specific role from existing select in the same section
                    var $existingSelect = $section.find('.zs-staff-select').first();
                    if ($existingSelect.length) {
                        $existingSelect.find('option').each(function() {
                            if ($(this).val()) {
                                $select.append($(this).clone());
                            }
                        });
                    }
                    
                    var $editBtn = $('<button type="button" class="button button-small zs-staff-edit" style="flex-shrink: 0;"><?php echo esc_js(__('Save', 'zero-sense')); ?></button>');
                    var $removeBtn = $('<button type="button" class="button button-small zs-staff-remove" style="flex-shrink: 0;"><?php echo esc_js(__('Remove', 'zero-sense')); ?></button>');
                    
                    $newRow.append($hiddenInput).append($display).append($select).append($editBtn).append($removeBtn);
                    $btn.before($newRow);
                    
                    $select.selectWoo({
                        width: '100%',
                        tags: true,
                        createTag: function(params) {
                            var term = $.trim(params.term);
                            if (term === '') return null;
                            return {
                                id: 'new:' + term,
                                text: term + ' (Create new)',
                                newTag: true
                            };
                        }
                    }).selectWoo('open');
                });
                
                // Remove staff member
                $(document).on('click', '.zs-staff-remove', function() {
                    $(this).closest('.zs-staff-row').remove();
                });
            });
        })(jQuery);
        </script>

        <style>
            .zs-staff-assignment-wrapper {
                padding: 12px;
            }
            .zs-staff-role-section {
                margin-bottom: 8px;
            }
        </style>
        <?php
    }

    public function save(int $orderId): void
    {
        if (!isset($_POST[self::NONCE_FIELD]) || !wp_verify_nonce((string) $_POST[self::NONCE_FIELD], self::NONCE_ACTION)) {
            return;
        }

        if (!current_user_can('edit_shop_order', $orderId)) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        $oldStaffAssignments = $order->get_meta(self::META_KEY, true);
        if (!is_array($oldStaffAssignments)) {
            $oldStaffAssignments = [];
        }

        $rawStaff = isset($_POST['zs_event_staff']) && is_array($_POST['zs_event_staff']) ? $_POST['zs_event_staff'] : [];
        
        $staffAssignments = [];
        foreach ($rawStaff as $role => $staffIds) {
            if (!is_array($staffIds)) {
                continue;
            }
            
            $role = sanitize_key($role);
            
            foreach ($staffIds as $staffId) {
                $staffId = (int) $staffId;
                if ($staffId <= 0) {
                    continue;
                }
                
                $staffAssignments[] = [
                    'staff_id' => $staffId,
                    'role' => $role,
                ];
            }
        }

        FieldChangeTracker::compareAndTrack($orderId, self::META_KEY, $oldStaffAssignments, $staffAssignments);

        if (empty($staffAssignments)) {
            $order->delete_meta_data(self::META_KEY);
        } else {
            $order->update_meta_data(self::META_KEY, $staffAssignments);
        }

        $order->save();
    }

    private function getStaffRoles(): array
    {
        $terms = get_terms([
            'taxonomy' => self::STAFF_TAX,
            'hide_empty' => false,
            'orderby' => 'meta_value_num',
            'meta_key' => 'role_order',
            'order' => 'ASC',
        ]);
        
        if (is_wp_error($terms) || empty($terms)) {
            return [];
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
        
        $roles = [];
        foreach ($terms as $term) {
            if ($term instanceof \WP_Term) {
                $roles[$term->slug] = $term->name;
            }
        }
        
        return $roles;
    }

    private function getAllStaff(string $roleSlug = ''): array
    {
        $args = [
            'post_type' => self::STAFF_CPT,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ];
        
        // Filter by role if provided
        if (!empty($roleSlug)) {
            $args['tax_query'] = [
                [
                    'taxonomy' => self::STAFF_TAX,
                    'field' => 'slug',
                    'terms' => $roleSlug,
                    'operator' => 'IN',
                ],
            ];
        }

        $staff = get_posts($args);
        
        // If no staff found with this role, return all staff (so user can assign roles)
        if (empty($staff) && !empty($roleSlug)) {
            unset($args['tax_query']);
            $staff = get_posts($args);
        }

        return is_array($staff) ? $staff : [];
    }
}
