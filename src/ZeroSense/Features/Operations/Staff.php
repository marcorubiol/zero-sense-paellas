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
            // Find the Staff Roles taxonomy metabox
            var $taxonomyDiv = $('#tagsdiv-<?php echo esc_js(self::TAX_ROLE); ?>');
            if ($taxonomyDiv.length) {
                // Add edit roles link after the "separate tags with commas" text
                var $addButton = $taxonomyDiv.find('.tagadd');
                if ($addButton.length) {
                    $addButton.after(
                        '<p style="margin-top: 8px;">' +
                        '<a href="<?php echo esc_js($edit_roles_url); ?>" class="button button-secondary" style="text-decoration: none;">' +
                        '<?php echo esc_js(__('Edit Roles', 'zero-sense')); ?>' +
                        '</a>' +
                        '</p>'
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
