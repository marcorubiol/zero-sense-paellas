<?php
// Fixed webhook deploy script - VERSION 2.3
header('X-Webhook-Version: 2.0-UPDATED-' . date('Y-m-d-H-i-s'));
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Log (versioned to avoid mixing with older runs)
$log_file = '/tmp/webhook-deploy-v21.log';
$max_log_bytes = 2097152;

function rotate_log_if_needed(): void {
    global $log_file, $max_log_bytes;
    if (!file_exists($log_file)) {
        return;
    }
    $size = @filesize($log_file);
    if ($size === false || $size <= $max_log_bytes) {
        return;
    }
    $rotated = $log_file . '.1';
    @unlink($rotated);
    @rename($log_file, $rotated);
}

// FORCE LOG AT THE VERY BEGINNING
rotate_log_if_needed();
file_put_contents($log_file, date('Y-m-d H:i:s') . " - === WEBHOOK v2.1 START ===\n", FILE_APPEND);

// Config
$secret = (string) (getenv('ZEROSENSE_DEPLOY_SECRET') ?: ($_ENV['ZEROSENSE_DEPLOY_SECRET'] ?? ''));
$staging_path = detect_zero_sense_plugin_dir(__DIR__);
$log_token = (string) (getenv('ZEROSENSE_LOG_TOKEN') ?: ($_ENV['ZEROSENSE_LOG_TOKEN'] ?? ''));

function log_msg($msg) {
    global $log_file;
    rotate_log_if_needed();
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
}

// Log endpoint
if (isset($_GET['log'])) {
    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';
    if (!$log_token || !hash_equals($log_token, $token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'forbidden']);
        exit;
    }
    header('Content-Type: text/plain');
    readfile($log_file);
    exit;
}

// Test endpoint
if (isset($_GET['test'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'test_ok',
        'version' => '2.0-UPDATED-' . date('Y-m-d-H-i-s'),
        'file_modified' => date('Y-m-d H:i:s', filemtime(__FILE__)),
        'file_path' => __FILE__,
        'server_time' => date('Y-m-d H:i:s')
    ]);
    exit;
}

if (isset($_GET['sync'])) {
    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';
    if (!$token) {
        $token = (string) ($_SERVER['HTTP_X_ZEROSENSE_TOKEN'] ?? '');
    }
    if (!$log_token || !hash_equals($log_token, $token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'forbidden']);
        exit;
    }

    $sync_result = handlePluginInstallOrSync();

    header('Content-Type: application/json');
    if (($sync_result['status'] ?? '') !== 'success') {
        http_response_code(500);
    }
    echo json_encode([
        'status' => $sync_result['status'] ?? 'error',
        'message' => $sync_result['message'] ?? '',
        'timestamp' => date('c'),
    ]);
    exit;
}

// Only POST requests
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'method_not_allowed']);
    exit;
}

log_msg("=== WEBHOOK START ===");
log_msg("🔥 VERSION: v2.0 - Simplified SHA256/SHA1 Validation");
log_msg("📅 UPDATED: " . date('Y-m-d H:i:s'));
log_msg("🚀 FILE MODIFIED: " . date('Y-m-d H:i:s', filemtime(__FILE__)));
log_msg("Method: {$method}");
log_msg("User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
log_msg("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? ''));
log_msg("Content-Length: " . ($_SERVER['CONTENT_LENGTH'] ?? ''));

// Debug: Log ALL server variables
log_msg("=== DEBUG: ALL SERVER VARIABLES ===");
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0 || strpos($key, 'CONTENT_') === 0) {
        log_msg("$key: $value");
    }
}
log_msg("=== END DEBUG ===");

// Get payload
$payload = file_get_contents('php://input');
log_msg("Payload length: " . strlen($payload));

// Get headers - compatible method
$headers = [];
foreach ($_SERVER as $name => $value) {
    if (strpos($name, 'HTTP_') === 0) {
        $header_name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
        $headers[$header_name] = $value;
    }
}

// Prefer direct $_SERVER vars (these are the canonical way in PHP)
$signature_sha256 = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$signature_sha1 = $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? '';
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
$delivery = $_SERVER['HTTP_X_GITHUB_DELIVERY'] ?? '';

// Fallback: case-insensitive lookup in $headers (because array keys are case-sensitive)
if (!$signature_sha256 || !$signature_sha1 || !$event || !$delivery) {
    $headers_lc = [];
    foreach ($headers as $k => $v) {
        $headers_lc[strtolower($k)] = $v;
    }
    $signature_sha256 = $signature_sha256 ?: ($headers_lc['x-hub-signature-256'] ?? '');
    $signature_sha1 = $signature_sha1 ?: ($headers_lc['x-hub-signature'] ?? '');
    $event = $event ?: ($headers_lc['x-github-event'] ?? '');
    $delivery = $delivery ?: ($headers_lc['x-github-delivery'] ?? '');
}

log_msg("Event: $event");
log_msg("Delivery: $delivery");
log_msg("SHA256 Signature: " . ($signature_sha256 ? 'PRESENT' : 'NULL'));
log_msg("SHA1 Signature: " . ($signature_sha1 ? 'PRESENT' : 'NULL'));
log_msg("Available headers: " . implode(', ', array_keys($headers)));

// GitHub IP whitelist for security (since signatures are blocked)
$github_ips = [
    '192.30.252.0/22',
    '185.199.108.0/22', 
    '140.82.112.0/20',
    '143.55.64.0/20'
];

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$is_github = false;

foreach ($github_ips as $ip_range) {
    if (ip_in_range($client_ip, $ip_range)) {
        $is_github = true;
        break;
    }
}

// For debugging: allow if User-Agent is GitHub-Hookshot
$is_github_user_agent = strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'GitHub-Hookshot') !== false;

// Allow without signature only when signatures are missing AND request looks like GitHub
$allow_without_signature = (!$signature_sha256 && !$signature_sha1) && ($is_github || $is_github_user_agent);

if (!$secret && ($is_github || $is_github_user_agent)) {
    $allow_without_signature = true;
    log_msg("⚠️ Secret missing; allowing based on GitHub IP/User-Agent");
}

log_msg("Client IP: $client_ip");
log_msg("Is GitHub IP: " . ($is_github ? 'YES' : 'NO'));
log_msg("Is GitHub User-Agent: " . ($is_github_user_agent ? 'YES' : 'NO'));
log_msg("Allow without signature: " . ($allow_without_signature ? 'YES' : 'NO'));

// Skip signature validation if headers are blocked but request is from GitHub
if (!$allow_without_signature && (!$signature_sha256 && !$signature_sha1)) {
    log_msg("❌ Missing signatures and not from GitHub");
    log_msg("=== WEBHOOK END ===\n");
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'forbidden',
        'delivery' => $delivery,
        'debug' => [
            'has_signature_sha256' => !empty($signature_sha256),
            'has_signature_sha1' => !empty($signature_sha1),
            'is_github_ip' => $is_github,
            'is_github_user_agent' => $is_github_user_agent,
            'client_ip' => $client_ip
        ]
    ]);
    exit;
}

if ($allow_without_signature) {
    log_msg("⚠️ Skipping signature validation (GitHub detected by IP/User-Agent)");
} else {
    // Validate signatures - try both SHA256 and SHA1
    $valid_signature = false;
    $expected_sha256 = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    $expected_sha1 = 'sha1=' . hash_hmac('sha1', $payload, $secret);

    log_msg("Expected SHA256: $expected_sha256");
    log_msg("Expected SHA1: $expected_sha1");

    // Check SHA256 first (preferred)
    if ($signature_sha256 && hash_equals($expected_sha256, $signature_sha256)) {
        $valid_signature = true;
        log_msg("✅ SHA256 signature valid");
    }
    // Fallback to SHA1
    elseif ($signature_sha1 && hash_equals($expected_sha1, $signature_sha1)) {
        $valid_signature = true;
        log_msg("✅ SHA1 signature valid");
    }
    else {
        log_msg("❌ Invalid signature");
        log_msg("Received SHA256: " . ($signature_sha256 ?: 'NULL'));
        log_msg("Received SHA1: " . ($signature_sha1 ?: 'NULL'));
    }

    if (!$valid_signature) {
        log_msg("=== WEBHOOK END ===\n");
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'forbidden',
            'delivery' => $delivery,
            'debug' => [
                'expected_sha256' => $expected_sha256,
                'received_sha256' => $signature_sha256,
                'expected_sha1' => $expected_sha1,
                'received_sha1' => $signature_sha1,
                'sha256_valid' => $signature_sha256 ? hash_equals($expected_sha256, $signature_sha256) : false,
                'sha1_valid' => $signature_sha1 ? hash_equals($expected_sha1, $signature_sha1) : false
            ]
        ]);
        exit;
    }
}

log_msg("✅ Request validated");

$data = json_decode($payload, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    log_msg("❌ Invalid JSON: " . json_last_error_msg());
    log_msg("=== WEBHOOK END ===\n");
    http_response_code(400);
    echo json_encode(['status' => 'invalid_json']);
    exit;
}

$ref = $data['ref'] ?? '';
$branch = str_replace('refs/heads/', '', $ref);
$repo = $data['repository']['name'] ?? '';

log_msg("Repository: $repo");
log_msg("Branch: $branch");
log_msg("Ref: $ref");

if ($event !== 'push' || $branch !== 'develop') {
    log_msg("Ignored - Event: $event, Branch: $branch");
    log_msg("=== WEBHOOK END ===\n");
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ignored',
        'event' => $event,
        'branch' => $branch,
        'delivery' => $delivery
    ]);
    exit;
}

// Verify path exists
if (!is_dir($staging_path)) {
    log_msg("❌ Staging path does not exist: {$staging_path}");
    log_msg("=== WEBHOOK END ===\n");
    http_response_code(500);
    echo json_encode([
        'status' => 'path_not_found',
        'path' => $staging_path
    ]);
    exit;
}

log_msg("🚀 Starting deploy...");

$deploy_ok = false;
$deploy_status = 'error';
$deploy_message = '';
$deployed_head = '';

if (!chdir($staging_path)) {
    log_msg("❌ Cannot chdir to staging path: {$staging_path}");
} else {
    // Check if it's a git repo
    if (!is_dir('.git')) {
        log_msg("❌ Not a git repository (.git not found)");
        log_msg("Working dir: " . getcwd());

        // Try to list a few entries for diagnostics
        $entries = @scandir('.') ?: [];
        $entries = array_slice($entries, 0, 30);
        log_msg("Dir entries (first 30): " . implode(', ', $entries));
        log_msg(".git exists: " . (file_exists('.git') ? 'YES' : 'NO'));

        $repo_full_name = (string) ($data['repository']['full_name'] ?? '');
        $zip_result = deploy_from_github_zip($repo_full_name, 'develop', $staging_path, (string) $delivery);
        if (($zip_result['status'] ?? '') !== 'success') {
            log_msg("❌ ZIP deploy failed: " . ($zip_result['message'] ?? ''));
            $deploy_ok = false;
            $deploy_status = 'error';
            $deploy_message = (string) ($zip_result['message'] ?? 'ZIP deploy failed');
        } else {
            $sync_result = handlePluginInstallOrSync();
            if (($sync_result['status'] ?? '') === 'error') {
                log_msg("❌ Plugin sync failed: " . ($sync_result['message'] ?? ''));
                $deploy_ok = false;
                $deploy_status = 'error';
                $deploy_message = (string) ($sync_result['message'] ?? 'Plugin sync failed');
            } else {
                log_msg("✅ Plugin sync: " . ($sync_result['message'] ?? ''));
                $deploy_ok = true;
                $deploy_status = 'success';
                $deploy_message = (string) ($zip_result['message'] ?? 'ZIP deploy success');
            }
        }
    } else {
        $commands = [
            'git status --porcelain',
            'git fetch origin develop',
            'git reset --hard origin/develop',
            'git clean -fd',
            'git rev-parse --short HEAD'
        ];

        $failed = false;
        foreach ($commands as $cmd) {
            $output = [];
            $return = 0;
            exec($cmd . ' 2>&1', $output, $return);
            log_msg("$cmd => exit {$return}");
            if (!empty($output)) {
                foreach ($output as $line) {
                    log_msg("  $line");
                }
            }
            if ($return !== 0) {
                $failed = true;
                break;
            }
            if ($cmd === 'git rev-parse --short HEAD') {
                $deployed_head = !empty($output) ? trim(end($output)) : '';
            }
        }

        if (!$failed) {
            // Check if plugin exists and install/sync
            $sync_result = handlePluginInstallOrSync();
            if ($sync_result['status'] === 'error') {
                log_msg("❌ Plugin sync failed: " . $sync_result['message']);
                $deploy_ok = false;
                $deploy_status = 'error';
                $deploy_message = $sync_result['message'];
            } else {
                log_msg("✅ Plugin sync: " . $sync_result['message']);
                $deploy_ok = true;
                $deploy_status = 'success';
                $deploy_message = $sync_result['message'];
            }
            log_msg("🎉 Deploy success! HEAD: $deployed_head");
        } else {
            log_msg("❌ Deploy failed");
            $repo_full_name = (string) ($data['repository']['full_name'] ?? '');
            $zip_result = deploy_from_github_zip($repo_full_name, 'develop', $staging_path, (string) $delivery);
            if (($zip_result['status'] ?? '') !== 'success') {
                $deploy_status = 'error';
                $deploy_message = 'Git commands failed; ZIP deploy also failed: ' . (string) ($zip_result['message'] ?? '');
            } else {
                $sync_result = handlePluginInstallOrSync();
                if (($sync_result['status'] ?? '') === 'error') {
                    $deploy_status = 'error';
                    $deploy_message = (string) ($sync_result['message'] ?? 'Plugin sync failed');
                } else {
                    $deploy_ok = true;
                    $deploy_status = 'success';
                    $deploy_message = (string) ($zip_result['message'] ?? 'ZIP deploy success');
                }
            }
        }
    }
}

log_msg("=== WEBHOOK END ===\n");

/**
 * Check if IP is in range (CIDR notation)
 */
function ip_in_range($ip, $range) {
    if (strpos($range, '/') === false) {
        return $ip === $range;
    }
    
    list($subnet, $mask) = explode('/', $range);
    $subnet = ip2long($subnet);
    $ip = ip2long($ip);
    $mask = -1 << (32 - (int)$mask);
    
    return ($ip & $mask) === ($subnet & $mask);
}

/**
 * Handle plugin installation or synchronization
 */
function handlePluginInstallOrSync(): array {
    global $staging_path;
    
    // Load WordPress
    if (!defined('ABSPATH')) {
        $wp_load_path = '';
        for ($i = 0; $i <= 6; $i++) {
            $candidate_dir = $i === 0 ? $staging_path : dirname($staging_path, $i);
            if (!$candidate_dir || $candidate_dir === '.' || $candidate_dir === '/') {
                break;
            }
            $candidate = rtrim($candidate_dir, '/') . '/wp-load.php';
            if (file_exists($candidate)) {
                $wp_load_path = $candidate;
                break;
            }
        }
        if (!$wp_load_path) {
            return ['status' => 'error', 'message' => 'WordPress not found (wp-load.php not found by upward search)'];
        }
        require_once $wp_load_path;
    }
    
    // Load WordPress admin functions
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    
    if (!function_exists('activate_plugin')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    
    $plugin_file = 'zero-sense/zero-sense.php';
    
    // Check if plugin files exist
    if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
        log_msg("❌ Plugin files not found in WordPress plugins directory");
        return ['status' => 'error', 'message' => 'Plugin files not found'];
    }
    
    // Check if plugin is already active
    if (is_plugin_active($plugin_file)) {
        log_msg("ℹ️ Plugin already active, syncing...");
        
        // Deactivate first to force refresh
        deactivate_plugins($plugin_file, true);
        
        // Clear caches
        wp_cache_flush();
        
        // Clear feature discovery cache
        if (defined('ZERO_SENSE_VERSION')) {
            $cacheKey = 'zs_feature_classes_v' . ZERO_SENSE_VERSION;
            delete_transient($cacheKey);
        }
        
        // Reactivate
        $result = activate_plugin($plugin_file, '', is_network_admin());
        
        if (is_wp_error($result)) {
            return ['status' => 'error', 'message' => 'Reactivation failed: ' . $result->get_error_message()];
        }
        
        return ['status' => 'success', 'message' => 'Plugin synced successfully'];
    }
    
    // Plugin not active - install and activate
    log_msg("ℹ️ Plugin not active, installing...");
    
    // Get list of available plugins
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    
    $all_plugins = get_plugins();
    
    if (!isset($all_plugins[$plugin_file])) {
        // Plugin not registered in WordPress - try to register it
        log_msg("ℹ️ Plugin not registered, attempting to register...");
        
        // Force WordPress to recognize the plugin
        wp_cache_delete('plugins', 'plugins');
        
        // Try again
        $all_plugins = get_plugins();
        
        if (!isset($all_plugins[$plugin_file])) {
            return ['status' => 'error', 'message' => 'Plugin not recognized by WordPress'];
        }
    }
    
    // Activate the plugin
    $result = activate_plugin($plugin_file, '', is_network_admin());
    
    if (is_wp_error($result)) {
        return ['status' => 'error', 'message' => 'Activation failed: ' . $result->get_error_message()];
    }
    
    // Run activation hooks if plugin class exists
    if (class_exists('ZeroSense\Core\Plugin')) {
        try {
            ZeroSense\Core\Plugin::activate();
            log_msg("✅ Plugin activation hooks executed");
        } catch (Exception $e) {
            log_msg("⚠️ Activation hooks error: " . $e->getMessage());
        }
    }
    
    return ['status' => 'success', 'message' => 'Plugin installed and activated successfully'];
}

function deploy_from_github_zip(string $repo_full_name, string $branch, string $target_dir, string $delivery): array {
    if (!$repo_full_name) {
        return ['status' => 'error', 'message' => 'Repository full_name not found in payload'];
    }

    $zip_url = 'https://github.com/' . $repo_full_name . '/archive/refs/heads/' . rawurlencode($branch) . '.zip';
    $zip_path = '/tmp/zs-deploy-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $delivery ?: uniqid('', true)) . '.zip';
    $extract_dir = '/tmp/zs-deploy-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $delivery ?: uniqid('', true));

    $download_ok = download_file($zip_url, $zip_path);
    if (!$download_ok || !file_exists($zip_path)) {
        return ['status' => 'error', 'message' => 'Failed to download ZIP from ' . $zip_url];
    }

    if (is_dir($extract_dir)) {
        rrmdir($extract_dir);
    }
    @mkdir($extract_dir, 0755, true);

    $unzipped = false;
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zip_path) === true) {
            $unzipped = $zip->extractTo($extract_dir);
            $zip->close();
        }
    }
    if (!$unzipped) {
        $output = [];
        $return = 0;
        exec('unzip -o ' . escapeshellarg($zip_path) . ' -d ' . escapeshellarg($extract_dir) . ' 2>&1', $output, $return);
        $unzipped = ($return === 0);
    }

    if (!$unzipped) {
        return ['status' => 'error', 'message' => 'Failed to unzip downloaded archive'];
    }

    $entries = @scandir($extract_dir) ?: [];
    $src_root = '';
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..' || $e === '__MACOSX') {
            continue;
        }
        $candidate = rtrim($extract_dir, '/') . '/' . $e;
        if (is_dir($candidate)) {
            $src_root = $candidate;
            break;
        }
    }

    if (!$src_root) {
        return ['status' => 'error', 'message' => 'Unzipped archive has no root directory'];
    }

    clean_dir_except($target_dir, ['webhook-deploy.php', '.git']);
    $copy_ok = recursive_copy($src_root, $target_dir);

    @unlink($zip_path);
    rrmdir($extract_dir);

    if (!$copy_ok) {
        return ['status' => 'error', 'message' => 'Failed to copy extracted files into target directory'];
    }

    return ['status' => 'success', 'message' => 'Deployed from GitHub ZIP (' . $repo_full_name . ':' . $branch . ')'];
}

function download_file(string $url, string $dest): bool {
    if (function_exists('curl_init')) {
        $fp = @fopen($dest, 'wb');
        if (!$fp) {
            return false;
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ZeroSense-Webhook-Deploy');
        $ok = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $http < 200 || $http >= 300) {
            @unlink($dest);
            return false;
        }
        return true;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'follow_location' => 1,
            'header' => "User-Agent: ZeroSense-Webhook-Deploy\r\n",
        ],
    ]);
    $data = @file_get_contents($url, false, $context);
    if ($data === false) {
        return false;
    }
    return file_put_contents($dest, $data) !== false;
}

function clean_dir_except(string $dir, array $keep): void {
    $entries = @scandir($dir) ?: [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        if (in_array($e, $keep, true)) {
            continue;
        }
        $path = rtrim($dir, '/') . '/' . $e;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
}

function recursive_copy(string $src, string $dst): bool {
    if (!is_dir($src)) {
        return false;
    }
    if (!is_dir($dst) && !@mkdir($dst, 0755, true)) {
        return false;
    }
    $entries = @scandir($src) ?: [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $from = rtrim($src, '/') . '/' . $e;
        $to = rtrim($dst, '/') . '/' . $e;
        if (is_dir($from)) {
            if (!recursive_copy($from, $to)) {
                return false;
            }
        } else {
            if (!@copy($from, $to)) {
                return false;
            }
        }
    }
    return true;
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $entries = @scandir($dir) ?: [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $path = rtrim($dir, '/') . '/' . $e;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function detect_zero_sense_plugin_dir(string $start_dir): string {
    $start_dir = rtrim($start_dir, '/');

    // Case 1: script is already inside the plugin directory
    if (file_exists($start_dir . '/zero-sense.php')) {
        return $start_dir;
    }

    // Case 2: script is somewhere under WP root; walk up and look for wp-content/plugins/zero-sense
    $dir = $start_dir;
    for ($i = 0; $i <= 10; $i++) {
        $candidate = $dir . '/wp-content/plugins/zero-sense/zero-sense.php';
        if (file_exists($candidate)) {
            return dirname($candidate);
        }
        $parent = dirname($dir);
        if (!$parent || $parent === $dir || $parent === '/') {
            break;
        }
        $dir = $parent;
    }

    // Fallback (better than hardcoded path): assume plugin folder is next to this script
    return $start_dir;
}

// Response
header('Content-Type: application/json');
echo json_encode([
    'status' => $deploy_status,
    'message' => $deploy_message,
    'delivery' => $delivery,
    'event' => $event,
    'branch' => 'develop',
    'deployed_head' => $deployed_head,
    'timestamp' => date('c')
]);
?>
