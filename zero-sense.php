<?php
/**
 * Plugin Name: Zerø Sense
 * Plugin URI: https://paellasencasa.com
 * Description: Modern PSR-4 WordPress plugin for Paellas en Casa website with custom fields migration and HPOS compatibility
 * Version: 3.4.9.110
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
$_zs_ver = get_file_data(__FILE__, ['Version' => 'Version'])['Version'] ?? '0.0.0';
define('ZERO_SENSE_VERSION', in_array(wp_get_environment_type(), ['local', 'development']) ? $_zs_ver . '-dev' : $_zs_ver);
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
        echo esc_html__('Composer autoloader not found. Please run "composer install" in the plugin directory.', 'zero-sense');
        echo '</p></div>';
    }
}

/**
 * Global helper function for FlowMattic to save Google Calendar Event ID
 * 
 * @param string|int $order_id Order ID
 * @param string $event_id Google Calendar Event ID
 * @param string $event_title Event title (optional)
 * @param string $calendar_id Google Calendar ID (optional)
 * @param string $trigger_source 'manual' or 'automatic' (optional, defaults to 'automatic')
 * @return array Response with success status and message
 */
function zs_save_calendar_event_id($order_id, $event_id = '', $event_title = '', $calendar_id = '', $trigger_source = 'automatic'): array
{
    try {
        $orderId = absint($order_id);
        $eventId = sanitize_text_field($event_id);
        $eventTitle = sanitize_text_field($event_title);
        $calendarId = sanitize_text_field($calendar_id);
        $triggerSource = in_array($trigger_source, ['manual', 'automatic'], true) ? $trigger_source : 'automatic';

        if ($orderId === 0) {
            return [
                'success' => false,
                'message' => 'Invalid order ID',
            ];
        }

        if ($eventId === '') {
            return [
                'success' => false,
                'message' => 'Invalid event ID',
            ];
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return [
                'success' => false,
                'message' => 'Order not found',
            ];
        }

        // Auto-detect trigger source from manual flag if not explicitly set
        if ($triggerSource === 'automatic') {
            $isManualFlag = $order->get_meta('_zs_manual_trigger', true) === 'yes';
            if ($isManualFlag) {
                $triggerSource = 'manual';
                $order->delete_meta_data('_zs_manual_trigger');
            }
        }

        // Check if event already exists (to determine if this is create or update)
        $existingEventId = $order->get_meta('zs_google_calendar_event_id', true);
        $isUpdate = ($existingEventId !== '' && $existingEventId !== false);

        // Save event ID and calendar ID
        $order->update_meta_data('zs_google_calendar_event_id', $eventId);
        if ($calendarId !== '') {
            $order->update_meta_data('zs_google_calendar_id', $calendarId);
        }
        $order->save_meta_data();

        // Add log entry if CalendarLogs class is available
        if (class_exists('\\ZeroSense\\Features\\WooCommerce\\EventManagement\\Calendar\\CalendarLogs')) {
            $logData = [
                'event_id' => $eventId,
                'trigger_source' => $triggerSource,
            ];
            
            if ($eventTitle !== '') {
                $logData['event_title'] = $eventTitle;
            }
            
            if ($calendarId !== '') {
                $logData['calendar_id'] = $calendarId;
            }

            // Log as 'updated' if event already existed, otherwise 'created'
            $logType = $isUpdate ? 'updated' : 'created';
            
            \ZeroSense\Features\WooCommerce\EventManagement\Calendar\CalendarLogs::add(
                $order,
                $logType,
                $logData
            );
        }

        return [
            'success' => true,
            'message' => 'Event ID saved successfully',
            'order_id' => $orderId,
            'event_id' => $eventId,
            'calendar_id' => $calendarId,
        ];

    } catch (\Throwable $e) {
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
        ];
    }
}

/**
 * Global helper function for FlowMattic to delete Google Calendar Event ID
 * 
 * @param string|int $order_id Order ID
 * @param string $trigger_source 'manual' or 'automatic' (optional, defaults to 'automatic')
 * @return array Response with success status and message
 */
function zs_delete_calendar_event_id($order_id, $trigger_source = 'automatic'): array
{
    try {
        $orderId = absint($order_id);
        $triggerSource = in_array($trigger_source, ['manual', 'automatic'], true) ? $trigger_source : 'automatic';

        if ($orderId === 0) {
            return [
                'success' => false,
                'message' => 'Invalid order ID',
            ];
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return [
                'success' => false,
                'message' => 'Order not found',
            ];
        }

        // Get event ID before deleting (for log)
        $eventId = $order->get_meta('zs_google_calendar_event_id', true);

        // Delete event ID and calendar ID
        $order->delete_meta_data('zs_google_calendar_event_id');
        $order->delete_meta_data('zs_google_calendar_id');
        $order->delete_meta_data(\ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys::EVENT_RESERVED);
        $order->save_meta_data();

        // Add log entry if CalendarLogs class is available
        if (class_exists('\\ZeroSense\\Features\\WooCommerce\\EventManagement\\Calendar\\CalendarLogs')) {
            $logData = [
                'event_id' => $eventId,
                'trigger_source' => $triggerSource,
            ];

            \ZeroSense\Features\WooCommerce\EventManagement\Calendar\CalendarLogs::add(
                $order,
                'deleted',
                $logData
            );
        }

        return [
            'success' => true,
            'message' => 'Event ID deleted successfully',
            'order_id' => $orderId,
        ];

    } catch (\Throwable $e) {
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
        ];
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

    // Load WP Debug Helper
    require_once ZERO_SENSE_PATH . 'src/ZeroSense/Utilities/WpDebugHelper.php';

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

/**
 * Global helper function to check if trigger was manual (from metabox button)
 * This checks and clears a temporary flag set by AJAX handlers
 * 
 * @param string|int $order_id Order ID
 * @return string 'manual' or 'automatic'
 */
function zs_get_trigger_source($order_id): string
{
    $orderId = absint($order_id);
    if ($orderId === 0) {
        return 'automatic';
    }

    $order = wc_get_order($orderId);
    if (!$order instanceof WC_Order) {
        return 'automatic';
    }

    // Check if manual flag is set
    $isManual = $order->get_meta('_zs_manual_trigger', true) === 'yes';
    
    // Clear the flag immediately after reading
    if ($isManual) {
        $order->delete_meta_data('_zs_manual_trigger');
        $order->save_meta_data();
    }
    
    return $isManual ? 'manual' : 'automatic';
}

/**
 * Global helper function for FlowMattic to clear calendar sync flag
 * Call this after successfully syncing to Google Calendar
 * 
 * @param string|int $order_id Order ID
 * @return array Response with success status
 */
function zs_clear_calendar_sync_flag($order_id): array
{
    try {
        $orderId = absint($order_id);
        if ($orderId === 0) {
            return ['success' => false, 'message' => 'Invalid order ID'];
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return ['success' => false, 'message' => 'Order not found'];
        }

        // Clear the sync flag
        $order->delete_meta_data(\ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys::CALENDAR_NEEDS_SYNC);
        $order->save_meta_data();

        return [
            'success' => true,
            'message' => 'Sync flag cleared',
            'order_id' => $orderId,
        ];

    } catch (\Throwable $e) {
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
        ];
    }
}
