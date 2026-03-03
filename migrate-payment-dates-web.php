<?php
/**
 * Web-based migration script for payment dates
 * 
 * USAGE:
 * 1. Visit: https://yoursite.com/wp-content/plugins/zero-sense/migrate-payment-dates-web.php?key=migrate2026
 * 2. Wait for completion
 * 3. DELETE this file immediately after use
 * 
 * SECURITY: Change the secret key below before using
 */

// Security key - CHANGE THIS!
define('MIGRATION_SECRET_KEY', 'migrate2026');

// Check security key
if (!isset($_GET['key']) || $_GET['key'] !== MIGRATION_SECRET_KEY) {
    http_response_code(403);
    die('Access denied. Add ?key=' . MIGRATION_SECRET_KEY . ' to the URL.');
}

// Load WordPress
$wp_load_path = dirname(__FILE__) . '/../../../wp-load.php';
if (!file_exists($wp_load_path)) {
    die('Could not find WordPress. Make sure this file is in the correct location.');
}
require_once $wp_load_path;

// Check user permissions
if (!current_user_can('manage_options')) {
    http_response_code(403);
    die('You must be logged in as an administrator to run this script.');
}

// Increase time limit
set_time_limit(600); // 10 minutes
ini_set('memory_limit', '512M');

// Start output
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Payment Dates Migration</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; padding: 40px; background: #f0f0f1; }
        .container { max-width: 800px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { color: #1d2327; margin-top: 0; }
        .progress { background: #f0f0f1; border-radius: 4px; height: 30px; margin: 20px 0; overflow: hidden; }
        .progress-bar { background: #2271b1; height: 100%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; }
        .log { background: #f6f7f7; padding: 15px; border-radius: 4px; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px; margin: 20px 0; }
        .log-item { padding: 4px 0; border-bottom: 1px solid #dcdcde; }
        .success { color: #00a32a; font-weight: 600; }
        .warning { color: #dba617; }
        .error { color: #d63638; }
        .stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin: 20px 0; }
        .stat-box { background: #f6f7f7; padding: 15px; border-radius: 4px; text-align: center; }
        .stat-number { font-size: 32px; font-weight: 700; color: #2271b1; }
        .stat-label { color: #646970; font-size: 14px; margin-top: 5px; }
        .delete-warning { background: #fcf0f1; border-left: 4px solid #d63638; padding: 15px; margin-top: 20px; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔄 Payment Dates Migration</h1>
    <p>Migrating payment dates from old keys to new keys...</p>
    
    <div class="progress">
        <div class="progress-bar" id="progress-bar" style="width: 0%">0%</div>
    </div>
    
    <div class="log" id="log"></div>
    
    <div class="stats" id="stats" style="display: none;">
        <div class="stat-box">
            <div class="stat-number" id="stat-first">0</div>
            <div class="stat-label">First Payment Dates Migrated</div>
        </div>
        <div class="stat-box">
            <div class="stat-number" id="stat-second">0</div>
            <div class="stat-label">Second Payment Dates Migrated</div>
        </div>
        <div class="stat-box">
            <div class="stat-number" id="stat-skipped">0</div>
            <div class="stat-label">Already Existed</div>
        </div>
        <div class="stat-box">
            <div class="stat-number" id="stat-errors">0</div>
            <div class="stat-label">Errors</div>
        </div>
    </div>
    
    <div class="delete-warning" id="delete-warning" style="display: none;">
        <strong>⚠️ IMPORTANT:</strong> Migration complete! Please <strong>DELETE this file immediately</strong> for security reasons.
    </div>
</div>

<script>
function addLog(message, type = 'info') {
    const log = document.getElementById('log');
    const item = document.createElement('div');
    item.className = 'log-item ' + type;
    item.textContent = message;
    log.appendChild(item);
    log.scrollTop = log.scrollHeight;
}

function updateProgress(current, total) {
    const percent = Math.round((current / total) * 100);
    const bar = document.getElementById('progress-bar');
    bar.style.width = percent + '%';
    bar.textContent = percent + '%';
}

function updateStats(first, second, skipped, errors) {
    document.getElementById('stat-first').textContent = first;
    document.getElementById('stat-second').textContent = second;
    document.getElementById('stat-skipped').textContent = skipped;
    document.getElementById('stat-errors').textContent = errors;
    document.getElementById('stats').style.display = 'grid';
}
</script>

<?php
flush();

// Get all orders
$args = [
    'limit' => -1,
    'type' => 'shop_order',
    'return' => 'ids',
    'orderby' => 'ID',
    'order' => 'ASC',
];

$order_ids = wc_get_orders($args);
$total = count($order_ids);

echo "<script>addLog('Found {$total} orders to process', 'info');</script>";
flush();

$migrated_first = 0;
$migrated_second = 0;
$skipped = 0;
$errors = 0;
$processed = 0;

foreach ($order_ids as $order_id) {
    try {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            $errors++;
            continue;
        }

        $changed = false;
        $log_parts = [];

        // Migrate first payment date
        $old_first = $order->get_meta('zs_deposits_deposit_payment_date', true);
        $new_first = $order->get_meta('zs_first_payment_date', true);
        
        if ($old_first && !$new_first) {
            $order->update_meta_data('zs_first_payment_date', $old_first);
            $migrated_first++;
            $changed = true;
            $log_parts[] = 'first payment date';
        } elseif ($new_first) {
            $skipped++;
        }

        // Migrate second payment date
        $old_second = $order->get_meta('zs_deposits_balance_payment_date', true);
        $new_second = $order->get_meta('zs_second_payment_date', true);
        
        if ($old_second && !$new_second) {
            $order->update_meta_data('zs_second_payment_date', $old_second);
            $migrated_second++;
            $changed = true;
            $log_parts[] = 'second payment date';
        } elseif ($new_second) {
            $skipped++;
        }

        // Save if any changes were made
        if ($changed) {
            $order->save_meta_data();
            $log_message = "Order #{$order_id}: Migrated " . implode(' and ', $log_parts);
            echo "<script>addLog('" . esc_js($log_message) . "', 'success');</script>";
        }

    } catch (\Throwable $e) {
        $errors++;
        $error_msg = "Error processing order #{$order_id}: " . $e->getMessage();
        echo "<script>addLog('" . esc_js($error_msg) . "', 'error');</script>";
    }

    $processed++;
    
    // Update progress every 10 orders
    if ($processed % 10 === 0 || $processed === $total) {
        echo "<script>updateProgress({$processed}, {$total});</script>";
        echo "<script>updateStats({$migrated_first}, {$migrated_second}, {$skipped}, {$errors});</script>";
        flush();
    }
}

// Final update
echo "<script>updateProgress({$total}, {$total});</script>";
echo "<script>updateStats({$migrated_first}, {$migrated_second}, {$skipped}, {$errors});</script>";
echo "<script>addLog('✅ Migration complete!', 'success');</script>";
echo "<script>addLog('First payment dates: {$migrated_first} migrated', 'info');</script>";
echo "<script>addLog('Second payment dates: {$migrated_second} migrated', 'info');</script>";
echo "<script>addLog('Already existed: {$skipped}', 'info');</script>";

if ($errors > 0) {
    echo "<script>addLog('⚠️ Errors: {$errors}', 'warning');</script>";
}

echo "<script>document.getElementById('delete-warning').style.display = 'block';</script>";
?>

</body>
</html>
