<?php
/**
 * Debug script for Recent Changes Highlight feature
 * 
 * Usage: Add ?zs_debug_changes=ORDER_ID to any admin page
 */

add_action('admin_init', function() {
    if (!isset($_GET['zs_debug_changes']) || !current_user_can('manage_woocommerce')) {
        return;
    }

    $orderId = absint($_GET['zs_debug_changes']);
    if (!$orderId) {
        wp_die('Invalid order ID');
    }

    $order = wc_get_order($orderId);
    if (!$order) {
        wp_die('Order not found');
    }

    echo '<h1>Debug: Recent Changes for Order #' . $orderId . '</h1>';
    
    echo '<h2>Event Date</h2>';
    $eventDate = $order->get_meta('zs_event_date', true);
    echo '<p><strong>zs_event_date:</strong> ' . ($eventDate ?: 'NOT SET') . '</p>';
    
    if ($eventDate) {
        $eventTimestamp = strtotime($eventDate . ' 23:59:59');
        $weekBeforeEvent = strtotime($eventDate . ' 00:00:00') - (7 * 24 * 60 * 60);
        echo '<p><strong>Week before event:</strong> ' . date('Y-m-d H:i:s', $weekBeforeEvent) . ' to ' . date('Y-m-d H:i:s', $eventTimestamp) . '</p>';
    }
    
    echo '<h2>Field Changes</h2>';
    $changes = $order->get_meta('_zs_field_changes', true);
    
    if (empty($changes) || !is_array($changes)) {
        echo '<p style="color: red;"><strong>NO CHANGES TRACKED</strong></p>';
    } else {
        echo '<table border="1" cellpadding="5" style="border-collapse: collapse;">';
        echo '<tr><th>Field Key</th><th>Changed At</th><th>Is Recent?</th></tr>';
        
        foreach ($changes as $fieldKey => $timestamp) {
            $isRecent = false;
            if ($eventDate) {
                $eventTimestamp = strtotime($eventDate . ' 23:59:59');
                $changeTimestamp = strtotime($timestamp);
                $weekBeforeEvent = strtotime($eventDate . ' 00:00:00') - (7 * 24 * 60 * 60);
                $isRecent = $changeTimestamp >= $weekBeforeEvent && $changeTimestamp <= $eventTimestamp;
            }
            
            $color = $isRecent ? 'green' : 'orange';
            echo '<tr>';
            echo '<td>' . esc_html($fieldKey) . '</td>';
            echo '<td>' . esc_html($timestamp) . '</td>';
            echo '<td style="color: ' . $color . '; font-weight: bold;">' . ($isRecent ? 'YES' : 'NO') . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
    }
    
    echo '<h2>Billing Fields (Current Values)</h2>';
    echo '<ul>';
    echo '<li><strong>First Name:</strong> ' . $order->get_billing_first_name() . '</li>';
    echo '<li><strong>Last Name:</strong> ' . $order->get_billing_last_name() . '</li>';
    echo '<li><strong>Email:</strong> ' . $order->get_billing_email() . '</li>';
    echo '<li><strong>Phone:</strong> ' . $order->get_billing_phone() . '</li>';
    echo '</ul>';
    
    echo '<h2>Test FieldChangeTracker</h2>';
    echo '<p>Testing if FieldChangeTracker::isFieldRecentlyChanged() works:</p>';
    
    require_once plugin_dir_path(__FILE__) . 'src/ZeroSense/Features/WooCommerce/EventManagement/Components/FieldChangeTracker.php';
    
    $testFields = ['_billing_first_name', '_billing_last_name', '_billing_email', 'zs_event_date'];
    foreach ($testFields as $field) {
        $isRecent = \ZeroSense\Features\WooCommerce\EventManagement\Components\FieldChangeTracker::isFieldRecentlyChanged($orderId, $field);
        $color = $isRecent ? 'green' : 'red';
        echo '<p style="color: ' . $color . ';"><strong>' . $field . ':</strong> ' . ($isRecent ? 'RECENT' : 'NOT RECENT') . '</p>';
    }
    
    echo '<hr>';
    echo '<p><a href="' . admin_url('post.php?post=' . $orderId . '&action=edit') . '">← Back to Order</a></p>';
    
    exit;
});
