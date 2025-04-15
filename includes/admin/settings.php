<?php
/**
 * Zero Sense Admin Settings
 */

if (!defined('ABSPATH')) exit; // Prevent direct access

/**
 * Add settings menu
 */
function zs_add_settings_menu() {
    add_options_page(
        __('Zero Sense Settings', 'zero-sense'),
        __('Zero Sense', 'zero-sense'),
        'manage_options',
        'zero-sense-settings',
        'zs_settings_page'
    );
}
add_action('admin_menu', 'zs_add_settings_menu');

/**
 * Register settings
 */
function zs_register_settings() {
    // Cart timeout settings
    register_setting('zero_sense_settings', 'zs_tab_detection_enabled', array(
        'type' => 'boolean',
        'default' => true,
        'sanitize_callback' => 'rest_sanitize_boolean'
    ));
    
    register_setting('zero_sense_settings', 'zs_cart_timeout', array(
        'type' => 'integer',
        'default' => 5,
        'sanitize_callback' => 'absint'
    ));
    
    // Admin Bar settings
    register_setting('zero_sense_settings', 'zs_hide_admin_bar_non_admins', array(
        'type' => 'boolean',
        'default' => true,
        'sanitize_callback' => 'rest_sanitize_boolean'
    ));
    
    // Cart settings section
    add_settings_section(
        'zs_cart_settings',
        __('Cart Settings', 'zero-sense'),
        'zs_cart_settings_section_callback',
        'zero-sense-settings'
    );
    
    add_settings_field(
        'zs_tab_detection_enabled',
        __('Enable Cart Timeout', 'zero-sense'),
        'zs_tab_detection_enabled_callback',
        'zero-sense-settings',
        'zs_cart_settings'
    );
    
    add_settings_field(
        'zs_cart_timeout',
        __('Cart Timeout (minutes)', 'zero-sense'),
        'zs_cart_timeout_callback',
        'zero-sense-settings',
        'zs_cart_settings'
    );
    
    // Admin Bar settings section
    add_settings_section(
        'zs_admin_bar_settings',
        __('Admin Bar Settings', 'zero-sense'),
        'zs_admin_bar_settings_section_callback',
        'zero-sense-settings'
    );
    
    add_settings_field(
        'zs_hide_admin_bar_non_admins',
        __('Admin Bar Visibility', 'zero-sense'),
        'zs_hide_admin_bar_non_admins_callback',
        'zero-sense-settings',
        'zs_admin_bar_settings'
    );
}
add_action('admin_init', 'zs_register_settings');

/**
 * Cart settings section description
 */
function zs_cart_settings_section_callback() {
    echo '<p>' . __('Configure cart timeout settings for abandoned carts.', 'zero-sense') . '</p>';
}

/**
 * Admin Bar settings section description
 */
function zs_admin_bar_settings_section_callback() {
    echo '<p>' . __('Control the visibility of the admin bar for different user roles.', 'zero-sense') . '</p>';
}

/**
 * Enable tab detection option field
 */
function zs_tab_detection_enabled_callback() {
    $enabled = get_option('zs_tab_detection_enabled', true);
    ?>
    <label>
        <input type="checkbox" name="zs_tab_detection_enabled" value="1" <?php checked(1, $enabled); ?>>
        <?php _e('Clear cart after browser tabs are closed', 'zero-sense'); ?>
    </label>
    <p class="description"><?php _e('When enabled, cart will be cleared if the website is closed for the specified time period.', 'zero-sense'); ?></p>
    <?php
}

/**
 * Hide admin bar for non-administrators option field
 */
function zs_hide_admin_bar_non_admins_callback() {
    $enabled = get_option('zs_hide_admin_bar_non_admins', true);
    ?>
    <label>
        <input type="checkbox" name="zs_hide_admin_bar_non_admins" value="1" <?php checked(1, $enabled); ?>>
        <?php _e('Hide admin bar for non-administrator users on the frontend', 'zero-sense'); ?>
    </label>
    <p class="description"><?php _e('When enabled, the WordPress admin bar will be hidden for all users except administrators.', 'zero-sense'); ?></p>
    <?php
}

/**
 * Cart timeout option field
 */
function zs_cart_timeout_callback() {
    $timeout = get_option('zs_cart_timeout', 5);
    ?>
    <input type="number" name="zs_cart_timeout" value="<?php echo esc_attr($timeout); ?>" min="1" max="1440" step="1">
    <p class="description"><?php _e('Time in minutes after all browser tabs are closed before cart is cleared (default: 5).', 'zero-sense'); ?></p>
    <?php
}

/**
 * Settings page content
 */
function zs_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('zero_sense_settings');
            do_settings_sections('zero-sense-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
} 