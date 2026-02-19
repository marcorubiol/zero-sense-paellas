<?php
/**
 * Plugin Name: Zerø Sense [DEV]
 * Plugin URI: https://paellasencasa.com
 * Description: Modern PSR-4 WordPress plugin for Paellas en Casa website - DEVELOPMENT VERSION with custom fields migration and HPOS compatibility
 * Version: 3.2.2-dev
 * Author: Zero Sense
 * Author URI: https://zerosense.studio
 * Text Domain: zero-sense
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ZERO_SENSE_VERSION', '3.2.2-dev');
define('ZERO_SENSE_FILE', __FILE__);
define('ZERO_SENSE_PATH', plugin_dir_path(__FILE__));
define('ZERO_SENSE_URL', plugin_dir_url(__FILE__));
define('ZERO_SENSE_BASENAME', plugin_basename(__FILE__));

// Load Composer autoloader
$autoloader = ZERO_SENSE_PATH . 'vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
} else {
    // Fallback error if Composer autoloader is missing
    add_action('admin_notices', 'zero_sense_autoloader_error');
    return;
}

// Load checkout debug logger only when WordPress debug logging is enabled - REMOVED


/**
 * Show autoloader error notice
 */
function zero_sense_autoloader_error()
{
    if (current_user_can('manage_options')) {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>Zerø Sense:</strong> ';
        echo __('Composer autoloader not found. Please run "composer install" in the plugin directory.', 'zero-sense');
        echo '</p></div>';
    }
}

// Initialize plugin
add_action('plugins_loaded', 'zero_sense_init');

/**
 * Initialize the plugin
 */
function zero_sense_init()
{
    // Load text domain
    load_plugin_textdomain('zero-sense', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // Initialize main plugin class
    if (class_exists('ZeroSense\Core\Plugin')) {
        ZeroSense\Core\Plugin::getInstance();
    }
}

/**
 * Plugin activation callback
 */
function zero_sense_activate()
{
    if (class_exists('ZeroSense\Core\Plugin')) {
        ZeroSense\Core\Plugin::activate();
    }
    // Auto-deactivate Zerø Sense (Legacy) if active
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $other = 'zero-sense-legacy/zero-sense.php';
    if (function_exists('is_plugin_active') && is_plugin_active($other)) {
        deactivate_plugins($other, false);
    }
}

/**
 * Plugin deactivation callback
 */
function zero_sense_deactivate()
{
    if (class_exists('ZeroSense\Core\Plugin')) {
        ZeroSense\Core\Plugin::deactivate();
    }
}

/**
 * Plugin uninstall callback
 */
function zero_sense_uninstall()
{
    if (class_exists('ZeroSense\Core\Plugin')) {
        ZeroSense\Core\Plugin::uninstall();
    }
}

// Plugin hooks
register_activation_hook(__FILE__, 'zero_sense_activate');
register_deactivation_hook(__FILE__, 'zero_sense_deactivate');
register_uninstall_hook(__FILE__, 'zero_sense_uninstall');
