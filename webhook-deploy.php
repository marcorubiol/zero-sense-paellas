<?php
error_reporting(E_ALL);w
ini_set('display_errors', 0);

function load_local_secrets(string $dir): array {
    $p = "$dir/.zs-secrets.php";
    return file_exists($p) ? (include $p) : [];
}

function detect_plugin_dir(string $dir): string {
    if (file_exists("$dir/zero-sense.php")) return $dir;
    for ($i = 0; $i <= 10; $i++) {
        $c = "$dir/wp-content/plugins/zero-sense/zero-sense.php";
        if (file_exists($c)) return dirname($c);
        $p = dirname($dir);
        if ($p === $dir) break;
        $dir = $p;
    }
    return $dir;
}

function log_msg($msg) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "$timestamp - $msg\n";
    
    // Add new log entry
    @file_put_contents($log_file, $log_entry, FILE_APPEND);
    
    // Clean up old logs (keep last 1000 lines)
    cleanup_logs($log_file);
}

function cleanup_logs(string $log_file): void {
    if (!file_exists($log_file)) return;
    
    $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($lines) <= 1000) return;
    
    // Keep only last 1000 lines
    $recent_lines = array_slice($lines, -1000);
    $content = implode("\n", $recent_lines) . "\n";
    
    // Write back the cleaned content
    @file_put_contents($log_file, $content);
}

$log_file = '/tmp/webhook-deploy.log';
$local_secrets = load_local_secrets(__DIR__);
$secret = trim(getenv('ZEROSENSE_DEPLOY_SECRET') ?: ($local_secrets['deploy_secret'] ?? ''));
$log_token = trim(getenv('ZEROSENSE_LOG_TOKEN') ?: ($local_secrets['log_token'] ?? ''));
$staging_path = detect_plugin_dir(__DIR__);

header('Content-Type: application/json');

// Endpoints
if (isset($_GET['log'])) {
    if (!$log_token || !hash_equals($log_token, $_GET['token'] ?? '')) {
        http_response_code(403);
        exit(json_encode(['status' => 'forbidden']));
    }
    header('Content-Type: text/plain');
    readfile($log_file);
    exit;
}

if (isset($_GET['test'])) {
    exit(json_encode([
        'status' => 'ok',
        'has_secret' => (bool)$secret,
        'has_log_token' => (bool)$log_token,
        'plugin_dir' => $staging_path,
        'plugin_exists' => file_exists($staging_path . '/zero-sense.php'),
    ]));
}

if (isset($_GET['sync'])) {
    if (!$log_token || !hash_equals($log_token, $_GET['token'] ?? '')) {
        http_response_code(403);
        exit(json_encode(['status' => 'forbidden']));
    }
    exit(json_encode(sync_plugin()));
}

// Only POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit(json_encode(['status' => 'method_not_allowed']));
}

log_msg("=== WEBHOOK START ===");

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
$delivery = $_SERVER['HTTP_X_GITHUB_DELIVERY'] ?? '';

// GitHub IP ranges
$github_ips = ['192.30.252.0/22', '185.199.108.0/22', '140.82.112.0/20', '143.55.64.0/20'];
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$is_github_ip = false;
foreach ($github_ips as $range) {
    if (ip_in_range($client_ip, $range)) { $is_github_ip = true; break; }
}
$is_github_ua = strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'GitHub-Hookshot') !== false;

// Validate signature
$valid = false;
if ($secret && $signature) {
    $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    $valid = hash_equals($expected, $signature);
}
if (!$valid && ($is_github_ip || $is_github_ua)) {
    $valid = true;
    log_msg("Signature skipped (GitHub detected)");
}

if (!$valid) {
    log_msg("Forbidden: invalid signature");
    http_response_code(403);
    exit(json_encode(['status' => 'forbidden', 'delivery' => $delivery]));
}

$data = json_decode($payload, true);
if (!$data) {
    http_response_code(400);
    exit(json_encode(['status' => 'invalid_json']));
}

$branch = str_replace('refs/heads/', '', $data['ref'] ?? '');
$repo = $data['repository']['full_name'] ?? '';

log_msg("Event: $event, Branch: $branch, Repo: $repo");

if ($event !== 'push' || $branch !== 'develop') {
    exit(json_encode(['status' => 'ignored', 'event' => $event, 'branch' => $branch]));
}

if (!is_dir($staging_path)) {
    http_response_code(500);
    exit(json_encode(['status' => 'error', 'message' => 'Plugin dir not found']));
}

// Deploy
log_msg("Starting deploy...");
$result = deploy_from_zip($repo, $branch, $staging_path, $delivery);

if ($result['status'] === 'success') {
    $sync = sync_plugin();
    log_msg("Sync: " . $sync['message']);
}

log_msg("Deploy: " . $result['status']);
exit(json_encode($result));

// === FUNCTIONS ===

function ip_in_range($ip, $range) {
    if (strpos($range, '/') === false) return $ip === $range;
    list($subnet, $mask) = explode('/', $range);
    $mask = -1 << (32 - (int)$mask);
    return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
}

function sync_plugin(): array {
    global $staging_path;
    if (function_exists('opcache_reset')) @opcache_reset();
    
    $cfg = parse_wp_config(find_wp_config(dirname($staging_path)));
    if (!$cfg) return ['status' => 'success', 'message' => 'wp-config not found, skipped'];
    
    $table = ($cfg['table_prefix'] ?: 'wp_') . 'options';
    
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new mysqli($cfg['db_host'], $cfg['db_user'], $cfg['db_password'], $cfg['db_name']);
        $db->set_charset('utf8mb4');
        
        $check = $db->query("SHOW TABLES LIKE '$table'");
        if (!$check || $check->num_rows === 0) {
            $db->close();
            return ['status' => 'success', 'message' => 'Table not found, skipped'];
        }
        
        $db->query("DELETE FROM $table WHERE option_name LIKE '_transient_zs_feature_classes_v%' OR option_name LIKE '_transient_timeout_zs_feature_classes_v%'");
        $db->close();
        return ['status' => 'success', 'message' => 'Transients cleared'];
    } catch (Throwable $e) {
        return ['status' => 'success', 'message' => 'DB error: ' . $e->getMessage()];
    }
}

function find_wp_config(string $dir): string {
    for ($i = 0; $i <= 10; $i++) {
        if (file_exists($dir . '/wp-config.php')) return $dir . '/wp-config.php';
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    return '';
}

function parse_wp_config(string $path): array {
    if (!$path || !file_exists($path)) return [];
    $c = @file_get_contents($path);
    if (!$c) return [];
    
    $get = function($n) use ($c) {
        $pattern = '/define\s*\(\s*[\'"]' . preg_quote($n, '/') . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\\s*\)/';
        return preg_match($pattern, $c, $m) ? $m[1] : '';
    };
    $prefix = preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $c, $m) ? $m[1] : 'wp_';
    
    return [
        'db_name' => $get('DB_NAME'),
        'db_user' => $get('DB_USER'),
        'db_password' => $get('DB_PASSWORD'),
        'db_host' => $get('DB_HOST') ?: 'localhost',
        'table_prefix' => $prefix,
    ];
}

function deploy_from_zip(string $repo, string $branch, string $target, string $delivery): array {
    if (!$repo) return ['status' => 'error', 'message' => 'No repo'];
    
    $url = "https://github.com/$repo/archive/refs/heads/$branch.zip";
    $zip = '/tmp/zs-' . md5($delivery ?: uniqid()) . '.zip';
    $tmp = '/tmp/zs-' . md5($delivery ?: uniqid());
    
    log_msg("Downloading: $url");
    if (!download($url, $zip)) return ['status' => 'error', 'message' => 'Download failed'];
    log_msg("Downloaded: $zip");
    
    @mkdir($tmp, 0755, true);
    $ok = false;
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($zip) === true) { $ok = $z->extractTo($tmp); $z->close(); }
    }
    if (!$ok) {
        exec('unzip -o ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($tmp) . ' 2>&1', $out, $ret);
        $ok = ($ret === 0);
    }
    @unlink($zip);
    
    if (!$ok) return ['status' => 'error', 'message' => 'Unzip failed'];
    log_msg("Extracted to: $tmp");
    
    $src = '';
    foreach (scandir($tmp) as $e) {
        if ($e !== '.' && $e !== '..' && is_dir("$tmp/$e")) { $src = "$tmp/$e"; break; }
    }
    if (!$src) { rrmdir($tmp); return ['status' => 'error', 'message' => 'No root dir']; }
    
    log_msg("Copying from: $src");
    clean_dir($target, ['webhook-deploy.php', '.git', '.zs-secrets.php']);
    rcopy($src, $target);
    rrmdir($tmp);
    
    log_msg("Deploy complete");
    return ['status' => 'success', 'message' => 'Deployed', 'delivery' => $delivery];
}

function download(string $url, string $dest): bool {
    if (function_exists('curl_init')) {
        $fp = @fopen($dest, 'wb');
        if (!$fp) return false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => 'ZS-Deploy']);
        $ok = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $code < 200 || $code >= 300) { @unlink($dest); return false; }
        return true;
    }
    $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 30, 'header' => "User-Agent: ZS-Deploy\r\n"]]));
    return $data !== false && file_put_contents($dest, $data) !== false;
}

function clean_dir(string $dir, array $keep): void {
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..' || in_array($e, $keep)) continue;
        $p = "$dir/$e";
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
}

function rcopy(string $src, string $dst): void {
    if (!is_dir($dst)) @mkdir($dst, 0755, true);
    foreach (scandir($src) as $e) {
        if ($e === '.' || $e === '..') continue;
        is_dir("$src/$e") ? rcopy("$src/$e", "$dst/$e") : @copy("$src/$e", "$dst/$e");
    }
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = "$dir/$e";
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
