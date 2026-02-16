<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Components;

use WC_Order;
use WP_Post;
use WP_Term;

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
    }

    public function addMetabox(): void
    {
        add_meta_box(
            'zs_event_staff_assignment',
            __('Event Staff', 'zero-sense'),
            [$this, 'render'],
            'shop_order',
            'normal',
            'default'
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'shop_order') {
            return;
        }

        wp_enqueue_style('woocommerce_admin_styles');
        wp_enqueue_script('selectWoo');
    }

    public function render(WP_Post $post): void
    {
        $order = wc_get_order($post->ID);
        if (!$order instanceof WC_Order) {
            return;
        }

        $staffAssignments = $order->get_meta(self::META_KEY, true);
        if (!is_array($staffAssignments)) {
            $staffAssignments = [];
        }

        $roles = $this->getStaffRoles();
        $allStaff = $this->getAllStaff();

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        ?>
        <div class="zs-staff-assignment-wrapper">
            <?php foreach ($roles as $roleSlug => $roleName): ?>
                <?php
                $assignedStaff = array_filter($staffAssignments, function($assignment) use ($roleSlug) {
                    return isset($assignment['role']) && $assignment['role'] === $roleSlug;
                });
                ?>
                
                <div style="border-top: 1px solid #dcdcde; padding-top: 12px; margin-top: 12px;">
                    <h4 style="margin: 0 0 8px 0; font-size: 12px; font-weight: 600; color: #50575e; text-transform: uppercase; letter-spacing: 0.5px;">
                        <?php echo esc_html($roleName); ?>
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
                                <div class="zs-staff-row" style="display: flex; gap: 10px; align-items: center; margin-bottom: 8px;">
                                    <select name="zs_event_staff[<?php echo esc_attr($roleSlug); ?>][]" 
                                            class="zs-staff-select" 
                                            style="flex: 1; max-width: 300px;"
                                            data-role="<?php echo esc_attr($roleSlug); ?>">
                                        <option value=""><?php esc_html_e('Select staff member...', 'zero-sense'); ?></option>
                                        <?php foreach ($allStaff as $staff): ?>
                                            <option value="<?php echo esc_attr((string) $staff->ID); ?>" 
                                                    <?php selected($staffId, $staff->ID); ?>
                                                    data-phone="<?php echo esc_attr(get_post_meta($staff->ID, self::META_PHONE, true)); ?>"
                                                    data-email="<?php echo esc_attr(get_post_meta($staff->ID, self::META_EMAIL, true)); ?>">
                                                <?php echo esc_html($staff->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    
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
                                    
                                    <button type="button" class="button zs-staff-remove" style="flex-shrink: 0;">
                                        <?php esc_html_e('Remove', 'zero-sense'); ?>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <button type="button" 
                                class="button zs-staff-add" 
                                data-role="<?php echo esc_attr($roleSlug); ?>"
                                style="margin-top: 4px;">
                            <?php esc_html_e('Add staff member', 'zero-sense'); ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <script>
        (function($) {
            'use strict';
            
            $(document).ready(function() {
                // Initialize selectWoo on existing selects
                $('.zs-staff-select').selectWoo({
                    width: '100%'
                });
                
                // Update info when selection changes
                $(document).on('change', '.zs-staff-select', function() {
                    var $select = $(this);
                    var $row = $select.closest('.zs-staff-row');
                    var $info = $row.find('.zs-staff-info');
                    var $option = $select.find('option:selected');
                    
                    var phone = $option.data('phone') || '';
                    var email = $option.data('email') || '';
                    
                    var infoHtml = '';
                    if (phone || email) {
                        if (phone) {
                            infoHtml += '📞 ' + phone;
                        }
                        if (phone && email) {
                            infoHtml += ' | ';
                        }
                        if (email) {
                            infoHtml += '✉️ ' + email;
                        }
                    }
                    
                    $info.html(infoHtml);
                });
                
                // Add staff member
                $(document).on('click', '.zs-staff-add', function() {
                    var $btn = $(this);
                    var role = $btn.data('role');
                    var $section = $btn.closest('.zs-staff-role-section');
                    
                    var $newRow = $('<div class="zs-staff-row" style="display: flex; gap: 10px; align-items: center; margin-bottom: 8px;"></div>');
                    
                    var $select = $('<select name="zs_event_staff[' + role + '][]" class="zs-staff-select" style="flex: 1; max-width: 300px;" data-role="' + role + '"></select>');
                    $select.append('<option value=""><?php echo esc_js(__('Select staff member...', 'zero-sense')); ?></option>');
                    
                    <?php foreach ($allStaff as $staff): ?>
                    $select.append($('<option></option>')
                        .val('<?php echo esc_js((string) $staff->ID); ?>')
                        .text('<?php echo esc_js($staff->post_title); ?>')
                        .data('phone', '<?php echo esc_js(get_post_meta($staff->ID, self::META_PHONE, true)); ?>')
                        .data('email', '<?php echo esc_js(get_post_meta($staff->ID, self::META_EMAIL, true)); ?>')
                    );
                    <?php endforeach; ?>
                    
                    var $info = $('<span class="zs-staff-info" style="flex: 1; font-size: 12px; color: #646970;"></span>');
                    var $removeBtn = $('<button type="button" class="button zs-staff-remove" style="flex-shrink: 0;"><?php echo esc_js(__('Remove', 'zero-sense')); ?></button>');
                    
                    $newRow.append($select).append($info).append($removeBtn);
                    $btn.before($newRow);
                    
                    $select.selectWoo({
                        width: '100%'
                    });
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

        if (empty($staffAssignments)) {
            $order->delete_meta_data(self::META_KEY);
        } else {
            $order->update_meta_data(self::META_KEY, $staffAssignments);
        }

        $order->save();
    }

    private function getStaffRoles(): array
    {
        return [
            'jefe-voluntarios' => __('Jefe de voluntarios', 'zero-sense'),
            'cocineros' => __('Cocineros', 'zero-sense'),
            'ayudantes' => __('Ayudantes', 'zero-sense'),
            'camareros' => __('Camareros', 'zero-sense'),
            'barra' => __('Barra', 'zero-sense'),
            'coqueteles' => __('Coqueteles', 'zero-sense'),
            'tallador-pernil' => __('Tallador de pernil', 'zero-sense'),
        ];
    }

    private function getAllStaff(): array
    {
        $staff = get_posts([
            'post_type' => self::STAFF_CPT,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => true,
        ]);

        return is_array($staff) ? $staff : [];
    }
}
