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

if (!$signature || !$payload) {
    log_msg("❌ Missing signature or payload");
    log_msg("=== WEBHOOK END ===\n");
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'bad_request',
        'delivery' => $delivery,
        'debug' => [
            'has_signature' => !empty($signature),
            'has_payload' => !empty($payload),
            'headers_found' => array_keys($headers)
        ]
    ]);
    exit;
}

// Verify signature
$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
log_msg("Expected signature: $expected");
log_msg("Received signature: $signature");

if (!hash_equals($expected, $signature)) {
    log_msg("❌ Invalid signature");
    log_msg("=== WEBHOOK END ===\n");
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'forbidden',
        'delivery' => $delivery,
        'debug' => [
            'expected' => $expected,
            'received' => $signature
        ]
    ]);
    exit;
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
            $deploy_ok = true;
            log_msg("🎉 Deploy success! HEAD: $deployed_head");
        } else {
            log_msg("❌ Deploy failed");
        }
    }
}

log_msg("=== WEBHOOK END ===\n");

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
