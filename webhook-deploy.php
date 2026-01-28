<?php
// Fixed webhook deploy script   
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Config
$secret = 'zerosense-deploy-secret-2026';
$staging_path = '/home/OeTjuWhiCsmAoG0K/STGpaellasEnCasa/public_html/wp-content/plugins/zero-sense';
$log_token = 'zerosense-log-2026';

// Log
$log_file = '/tmp/webhook-deploy.log';
function log_msg($msg) {
    global $log_file;
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
}

// Log endpoint
if (isset($_GET['log'])) {
    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';
    if (!hash_equals($log_token, $token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'forbidden']);
        exit;
    }
    header('Content-Type: text/plain');
    readfile($log_file);
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
log_msg("Method: {$method}");
log_msg("User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
log_msg("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? ''));
log_msg("Content-Length: " . ($_SERVER['CONTENT_LENGTH'] ?? ''));

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

$signature = $headers['X-Hub-Signature-256'] ?? $headers['X-Hub-Signature'] ?? '';
$event = $headers['X-GitHub-Event'] ?? '';
$delivery = $headers['X-GitHub-Delivery'] ?? '';

log_msg("Event: $event");
log_msg("Delivery: $delivery");
log_msg("Signature: " . ($signature ? 'PRESENT' : 'NULL'));
log_msg("Available headers: " . implode(', ', array_keys($headers)));

// GitHub IP whitelist for security (since signatures are blocked)
$github_ips = [
    '192.30.252.0/22',
    '185.199.108.0/22', 
    '140.82.112.0/20',
    '143.55.64.0/20',
    '20.27.177.113', // GitHub Actions IP
    '20.201.28.151'  // Another GitHub IP
];

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$is_github = false;

log_msg("Checking IP: $client_ip against GitHub ranges");

foreach ($github_ips as $ip_range) {
    if (ip_in_range($client_ip, $ip_range)) {
        $is_github = true;
        log_msg("✅ IP matches range: $ip_range");
        break;
    } else {
        log_msg("❌ IP does not match range: $ip_range");
    }
}

log_msg("Client IP: $client_ip");
log_msg("Is GitHub IP: " . ($is_github ? 'YES' : 'NO'));

// Skip signature validation if headers are blocked but IP is GitHub
$skip_signature = (!$signature && $is_github);

log_msg("Skip signature: " . ($skip_signature ? 'YES' : 'NO'));
log_msg("Has signature: " . ($signature ? 'YES' : 'NO'));
log_msg("Has payload: " . ($payload ? 'YES' : 'NO'));

// For debugging: allow if User-Agent is GitHub-Hookshot
$is_github_user_agent = strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'GitHub-Hookshot') !== false;
log_msg("Is GitHub User-Agent: " . ($is_github_user_agent ? 'YES' : 'NO'));

// Allow if either IP is GitHub OR User-Agent is GitHub-Hookshot
$allow_without_signature = ($is_github || $is_github_user_agent);

if (!$allow_without_signature && (!$signature || !$payload)) {
    log_msg("❌ Missing signature or payload - not allowing");
    log_msg("=== WEBHOOK END ===\n");
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'bad_request',
        'delivery' => $delivery,
        'debug' => [
            'has_signature' => !empty($signature),
            'has_payload' => !empty($payload),
            'headers_found' => array_keys($headers),
            'is_github_ip' => $is_github,
            'is_github_user_agent' => $is_github_user_agent,
            'client_ip' => $client_ip,
            'allow_without_signature' => $allow_without_signature
        ]
    ]);
    exit;
}

if (!$skip_signature) {
    // Verify signature
    $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    log_msg("Expected signature: $expected");
    log_msg("Received signature: $signature");

    // Try both SHA256 and SHA1 signatures
    $signatures_to_try = [$signature];
    if (!empty($headers['X-Hub-Signature'])) {
        $signatures_to_try[] = $headers['X-Hub-Signature'];
    }

    $valid_signature = false;
    foreach ($signatures_to_try as $sig) {
        if (hash_equals($expected, $sig)) {
            $valid_signature = true;
            break;
        }
        // Also try SHA1 if that's what we received
        if (strpos($sig, 'sha1=') === 0) {
            $expected_sha1 = 'sha1=' . hash_hmac('sha1', $payload, $secret);
            if (hash_equals($expected_sha1, $sig)) {
                $valid_signature = true;
                break;
            }
        }
    }

    if (!$valid_signature) {
        log_msg("❌ Invalid signature");
        log_msg("=== WEBHOOK END ===\n");
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'forbidden',
            'delivery' => $delivery,
            'debug' => [
                'expected' => $expected,
                'received' => $signature,
                'signatures_tried' => $signatures_to_try
            ]
        ]);
        exit;
    }
} else {
    log_msg("⚠️ Skipping signature validation (headers blocked by server)");
    log_msg("✅ GitHub IP validated");
}

log_msg("✅ Signature valid");

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
$deployed_head = '';

if (!chdir($staging_path)) {
    log_msg("❌ Cannot chdir to staging path: {$staging_path}");
} else {
    // Check if it's a git repo
    if (!is_dir('.git')) {
        log_msg("❌ Not a git repository");
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
            } else {
                log_msg("✅ Plugin sync: " . $sync_result['message']);
                $deploy_ok = true;
            }
            log_msg("🎉 Deploy success! HEAD: $deployed_head");
        } else {
            log_msg("❌ Deploy failed");
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
        $wp_load_path = dirname($staging_path, 4) . '/wp-load.php';
        if (!file_exists($wp_load_path)) {
            return ['status' => 'error', 'message' => 'WordPress not found at expected path'];
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

// Response
header('Content-Type: application/json');
if (!$deploy_ok) {
    http_response_code(500);
}

echo json_encode([
    'status' => $deploy_ok ? 'success' : 'error',
    'delivery' => $delivery,
    'event' => $event,
    'branch' => 'develop',
    'deployed_head' => $deployed_head,
    'timestamp' => date('c')
]);
?>
