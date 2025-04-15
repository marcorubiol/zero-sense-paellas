<?php
/**
 * Plugin Name: Zero Sense
 * Plugin URI: https://paellasencasa.com
 * Description: A multi-purpose WordPress plugin for Paellas en Casa website
 * Version: 1.0.0
 * Author: zero sense
 * Author URI: https://zerosense.blue
 * Text Domain: zero-sense
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ZERO_SENSE_VERSION', '1.0.0');
define('ZERO_SENSE_PATH', plugin_dir_path(__FILE__));
define('ZERO_SENSE_URL', plugin_dir_url(__FILE__));
define('ZERO_SENSE_SITE_NAME', 'Paellas en Casa');
define('ZERO_SENSE_SITE_URL', 'https://paellasencasa.com');
define('ZERO_SENSE_DEV_NAME', 'zero sense');
define('ZERO_SENSE_DEV_URL', 'https://zerosense.blue');

// Plugin initialization
function zero_sense_init() {
    // Load plugin text domain
    load_plugin_textdomain('zero-sense', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Include admin settings if in admin area
    if (is_admin()) {
        require_once ZERO_SENSE_PATH . 'includes/admin/settings.php';
    }
    
    // Include required core files
    require_once ZERO_SENSE_PATH . 'includes/shortcodes.php';
    
    // Include features initialization
    require_once ZERO_SENSE_PATH . 'includes/features/init.php';
}
add_action('plugins_loaded', 'zero_sense_init');

// Register activation hook
function zero_sense_activate() {
    // Nothing to do on activation
}
register_activation_hook(__FILE__, 'zero_sense_activate');

// Register deactivation hook
function zero_sense_deactivate() {
    // Nothing to do on deactivation
}
register_deactivation_hook(__FILE__, 'zero_sense_deactivate');

// Register uninstall hook
function zero_sense_uninstall() {
    // Nothing to do on uninstall
}
register_uninstall_hook(__FILE__, 'zero_sense_uninstall'); 