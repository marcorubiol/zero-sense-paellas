<?php
/**
 * WooCommerce Tab Detection and Cart Timeout
 * Clears cart after all tabs have been closed for a specific time
 */

if (!defined('ABSPATH')) exit; // Prevent direct access

/**
 * Add tab close detection script to WooCommerce pages
 */
function add_tab_close_detection() {
    // Check if feature is enabled in settings
    $enabled = get_option('zs_tab_detection_enabled', true);
    if (!$enabled) {
        return;
    }
    
    // Get timeout from settings (convert to seconds)
    $timeout_minutes = get_option('zs_cart_timeout', 5);
    $timeout_seconds = $timeout_minutes * 60;
    
    if (is_shop() || is_product() || is_cart() || is_checkout()) {
        ?>
        <script type="text/javascript">
        (function() {
            // Generate a unique ID for this tab
            const tabId = Math.random().toString(36).substring(2, 15);
            
            // Function to register this tab as active
            function registerActiveTab() {
                let activeTabs = JSON.parse(localStorage.getItem('wc_active_tabs') || '{}');
                activeTabs[tabId] = Date.now();
                localStorage.setItem('wc_active_tabs', JSON.stringify(activeTabs));
            }

            // Register tab when loading
            registerActiveTab();

            // Update periodically to keep the tab marked as active
            setInterval(registerActiveTab, 5000);

            // Remove this tab when closing
            window.addEventListener('beforeunload', function() {
                let activeTabs = JSON.parse(localStorage.getItem('wc_active_tabs') || '{}');
                delete activeTabs[tabId];
                
                // If no active tabs remain, save the closing time
                if (Object.keys(activeTabs).length === 0) {
                    localStorage.setItem('wc_last_tab_closed_time', Date.now());
                }
                
                localStorage.setItem('wc_active_tabs', JSON.stringify(activeTabs));
            });

            // Check on load if we should clear the cart
            window.addEventListener('load', function() {
                let activeTabs = JSON.parse(localStorage.getItem('wc_active_tabs') || '{}');
                
                // Clean up "dead" tabs (more than 10 seconds without updates)
                const now = Date.now();
                for (let tab in activeTabs) {
                    if (now - activeTabs[tab] > 10000) {
                        delete activeTabs[tab];
                    }
                }
                localStorage.setItem('wc_active_tabs', JSON.stringify(activeTabs));

                // Check if all tabs were closed and time limit has passed
                const lastCloseTime = localStorage.getItem('wc_last_tab_closed_time');
                if (lastCloseTime && Object.keys(activeTabs).length === 1) { // 1 because it includes current tab
                    const timeElapsed = (now - lastCloseTime) / 1000; // convert to seconds
                    if (timeElapsed > <?php echo esc_js($timeout_seconds); ?>) { // Timeout from settings
                        // AJAX call to clear cart
                        jQuery.ajax({
                            url: '<?php echo admin_url('admin-ajax.php'); ?>',
                            type: 'POST',
                            data: {
                                action: 'clear_cart_after_timeout',
                                nonce: '<?php echo wp_create_nonce('clear_cart_nonce'); ?>'
                            },
                            success: function(response) {
                                if(response.success) {
                                    localStorage.removeItem('wc_last_tab_closed_time');
                                    window.location.reload();
                                }
                            }
                        });
                    }
                }
            });
        })();
        </script>
        <?php
    }
}
add_action('wp_footer', 'add_tab_close_detection');

/**
 * Handle AJAX call to clear cart after timeout
 */
function handle_cart_timeout() {
    check_ajax_referer('clear_cart_nonce', 'nonce');
    
    // Check if feature is enabled in settings
    $enabled = get_option('zs_tab_detection_enabled', true);
    if (!$enabled) {
        wp_send_json_error(['message' => 'Feature disabled']);
        return;
    }

    if (WC()->cart) {
        WC()->cart->empty_cart();
        wp_send_json_success();
    } else {
        wp_send_json_error();
    }
}
add_action('wp_ajax_clear_cart_after_timeout', 'handle_cart_timeout');
add_action('wp_ajax_nopriv_clear_cart_after_timeout', 'handle_cart_timeout'); 