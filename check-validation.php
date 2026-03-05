<?php
// Simple check - no WordPress loading needed
header('Content-Type: text/plain; charset=utf-8');

echo "=== ORDER VALIDATION PAGE CHECK ===\n\n";

// 1. Check if file exists on server
$filePath = __DIR__ . '/src/ZeroSense/Features/WooCommerce/Migration/OrderValidationPage.php';
$fileExists = file_exists($filePath);

echo "1. OrderValidationPage.php exists: " . ($fileExists ? 'YES ❌ PROBLEM!' : 'NO ✅ Good') . "\n";
echo "   Path: {$filePath}\n\n";

if ($fileExists) {
    echo "2. File size: " . filesize($filePath) . " bytes\n";
    echo "   Last modified: " . date('Y-m-d H:i:s', filemtime($filePath)) . "\n\n";
    
    echo "=== SOLUTION ===\n";
    echo "The file still exists on the server!\n";
    echo "This means the deploy didn't work or wasn't executed.\n\n";
    echo "OPTIONS:\n";
    echo "A) Re-run the GitHub deploy\n";
    echo "B) Manually delete the file via FTP/SSH\n";
    echo "C) Run: rm '{$filePath}'\n\n";
} else {
    echo "2. File deleted correctly ✅\n\n";
    
    echo "=== NEXT STEP ===\n";
    echo "The file is deleted, but the page might still appear due to cache.\n";
    echo "Clear these caches:\n";
    echo "- WordPress transient cache\n";
    echo "- Object cache (Redis/Memcached)\n";
    echo "- OPcache (PHP)\n";
    echo "- Browser cache\n\n";
}

// 3. Check directory structure
echo "3. Migration directory exists: " . (is_dir(__DIR__ . '/src/ZeroSense/Features/WooCommerce/Migration') ? 'YES' : 'NO') . "\n";

// 4. List files in Migration directory if exists
$migrationDir = __DIR__ . '/src/ZeroSense/Features/WooCommerce/Migration';
if (is_dir($migrationDir)) {
    $files = scandir($migrationDir);
    $phpFiles = array_filter($files, function($f) { return substr($f, -4) === '.php'; });
    echo "   Files in Migration/: " . implode(', ', $phpFiles) . "\n";
}

echo "\n=== END ===\n";
