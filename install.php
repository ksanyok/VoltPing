<?php
/**
 * VoltPing Web Installer
 * 
 * Usage:
 * 1. Upload this file to your web server root
 * 2. Open in browser: https://yourdomain.com/install.php
 * 3. Follow the installation wizard
 * 4. Delete install.php after installation!
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

define('VOLTPING_VERSION', '1.0.0');
define('GITHUB_REPO', 'ksanyok/VoltPing');
define('GITHUB_BRANCH', 'main');

// ============================================================================
// EMBEDDED FILES - Created during installation if not available from GitHub
// ============================================================================

function getEmbeddedFiles(): array {
    return [
        'src/config.php' => getEmbeddedConfigPhp(),
        'src/tuya_client.php' => getEmbeddedTuyaClientPhp(),
        'src/watch_power.php' => getEmbeddedWatchPowerPhp(),
        'src/bot.php' => getEmbeddedBotPhp(),
        'src/admin.php' => getEmbeddedAdminPhp(),
    ];
}

// Files to install (will try GitHub first, then use embedded)
$FILES_TO_DOWNLOAD = [
    'src/config.php',
    'src/tuya_client.php', 
    'src/tuya_local_client.php',
    'src/watch_power.php',
    'src/bot.php',
    'src/admin.php',
    'src/schedule_parser.php',
];

// ============================================================================
// AJAX Handler
// ============================================================================

if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'check_requirements':
            echo json_encode(checkRequirements());
            break;
            
        case 'test_telegram':
            echo json_encode(testTelegram($_POST['token'] ?? ''));
            break;
            
        case 'test_tuya':
            echo json_encode(testTuya(
                $_POST['access_id'] ?? '',
                $_POST['access_secret'] ?? '',
                $_POST['device_id'] ?? '',
                $_POST['region'] ?? 'eu'
            ));
            break;
            
        case 'download_files':
            echo json_encode(downloadFiles());
            break;
            
        case 'save_config':
            echo json_encode(saveConfig($_POST));
            break;
            
        case 'setup_database':
            echo json_encode(setupDatabase());
            break;
            
        case 'setup_webhook':
            echo json_encode(setupWebhook($_POST['token'] ?? '', $_POST['webhook_url'] ?? ''));
            break;
            
        case 'detect_local_key':
            echo json_encode(detectLocalKey($_POST));
            break;
            
        case 'finalize':
            echo json_encode(finalizeInstallation());
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
    exit;
}

// ============================================================================
// Helper Functions
// ============================================================================

function checkRequirements(): array {
    $checks = [];
    
    // PHP Version
    $phpVersion = phpversion();
    $checks['php_version'] = [
        'name' => 'PHP Version',
        'required' => '8.0+',
        'current' => $phpVersion,
        'ok' => version_compare($phpVersion, '8.0.0', '>=')
    ];
    
    // Extensions
    $extensions = ['curl', 'json', 'sqlite3', 'openssl', 'mbstring'];
    foreach ($extensions as $ext) {
        $checks["ext_$ext"] = [
            'name' => "PHP Extension: $ext",
            'required' => 'Installed',
            'current' => extension_loaded($ext) ? 'Installed' : 'Not installed',
            'ok' => extension_loaded($ext)
        ];
    }
    
    // Write permissions
    $writableDirs = ['.', 'src', 'data', 'docs'];
    foreach ($writableDirs as $dir) {
        $path = __DIR__ . '/' . $dir;
        $isWritable = is_dir($path) ? is_writable($path) : is_writable(__DIR__);
        $checks["writable_$dir"] = [
            'name' => "Writable: $dir/",
            'required' => 'Yes',
            'current' => $isWritable ? 'Yes' : 'No',
            'ok' => $isWritable
        ];
    }
    
    // cURL can reach GitHub (check API availability, not specific repo)
    $checks['github_access'] = [
        'name' => 'GitHub/Internet Access',
        'required' => 'Available',
        'current' => 'Checking...',
        'ok' => false
    ];
    
    // First try the actual repo, then fallback to GitHub API
    $ch = curl_init('https://raw.githubusercontent.com/' . GITHUB_REPO . '/' . GITHUB_BRANCH . '/env.example');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_NOBODY => true, // HEAD request only
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $checks['github_access']['current'] = 'Repository available';
        $checks['github_access']['ok'] = true;
    } else {
        // Fallback: check if GitHub is reachable at all
        $ch = curl_init('https://api.github.com');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'VoltPing-Installer',
        ]);
        $result = curl_exec($ch);
        $httpCode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode2 === 200) {
            // GitHub works, but repo not published yet - show warning but allow continue
            $checks['github_access']['current'] = 'GitHub OK (repo not found - local mode)';
            $checks['github_access']['ok'] = true; // Allow to continue
            $checks['github_access']['warning'] = true;
        } else {
            $checks['github_access']['current'] = "No internet (HTTP $httpCode2)";
            $checks['github_access']['ok'] = false;
        }
    }
    
    $allOk = true;
    foreach ($checks as $check) {
        if (!$check['ok']) {
            $allOk = false;
            break;
        }
    }
    
    return ['success' => true, 'checks' => $checks, 'all_ok' => $allOk];
}

function testTelegram(string $token): array {
    if (empty($token)) {
        return ['success' => false, 'error' => 'Token is required'];
    }
    
    $url = "https://api.telegram.org/bot$token/getMe";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => "cURL error: $error"];
    }
    
    $data = json_decode($response, true);
    if (!$data || !($data['ok'] ?? false)) {
        return ['success' => false, 'error' => $data['description'] ?? 'Invalid token'];
    }
    
    return [
        'success' => true,
        'bot_name' => $data['result']['first_name'] ?? 'Bot',
        'bot_username' => $data['result']['username'] ?? ''
    ];
}

function testTuya(string $accessId, string $accessSecret, string $deviceId, string $region): array {
    if (empty($accessId) || empty($accessSecret) || empty($deviceId)) {
        return ['success' => false, 'error' => 'All Tuya credentials are required'];
    }
    
    $regions = [
        'eu' => 'https://openapi.tuyaeu.com',
        'us' => 'https://openapi.tuyaus.com',
        'cn' => 'https://openapi.tuyacn.com',
        'in' => 'https://openapi.tuyain.com',
    ];
    
    $baseUrl = $regions[$region] ?? $regions['eu'];
    
    // Get token - Tuya signature v2.0
    $timestamp = (string) round(microtime(true) * 1000);
    $nonce = '';
    $path = '/v1.0/token?grant_type=1';
    
    // Build string to sign for token request (no access_token yet)
    $contentHash = hash('sha256', ''); // Empty body
    $headers = ''; // No signed headers
    $stringToSign = "GET\n$contentHash\n$headers\n$path";
    $signStr = $accessId . $timestamp . $nonce . $stringToSign;
    $sign = strtoupper(hash_hmac('sha256', $signStr, $accessSecret));
    
    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            "client_id: $accessId",
            "sign: $sign",
            "t: $timestamp",
            "sign_method: HMAC-SHA256",
            "nonce: $nonce",
        ],
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => "cURL error: $error"];
    }
    
    $data = json_decode($response, true);
    if (!$data || !($data['success'] ?? false)) {
        return ['success' => false, 'error' => $data['msg'] ?? 'Failed to get token'];
    }
    
    $accessToken = $data['result']['access_token'] ?? '';
    
    // Test device access
    $path = "/v1.0/devices/$deviceId";
    $timestamp = (string) round(microtime(true) * 1000);
    
    // Build string to sign with access token
    $contentHash = hash('sha256', '');
    $headers = '';
    $stringToSign = "GET\n$contentHash\n$headers\n$path";
    $signStr = $accessId . $accessToken . $timestamp . $nonce . $stringToSign;
    $sign = strtoupper(hash_hmac('sha256', $signStr, $accessSecret));
    
    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            "client_id: $accessId",
            "access_token: $accessToken",
            "sign: $sign",
            "t: $timestamp",
            "sign_method: HMAC-SHA256",
            "nonce: $nonce",
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (!$data || !($data['success'] ?? false)) {
        return ['success' => false, 'error' => $data['msg'] ?? 'Device not found'];
    }
    
    $device = $data['result'] ?? [];
    
    return [
        'success' => true,
        'device_name' => $device['name'] ?? 'Unknown',
        'device_model' => $device['model'] ?? 'Unknown',
        'online' => $device['online'] ?? false,
        'local_key' => $device['local_key'] ?? null,
    ];
}

function downloadFiles(): array {
    global $FILES_TO_DOWNLOAD;
    
    $baseUrl = 'https://raw.githubusercontent.com/' . GITHUB_REPO . '/' . GITHUB_BRANCH . '/';
    $downloaded = [];
    $embedded = [];
    $skipped = [];
    $errors = [];
    
    // Get embedded files
    $embeddedFiles = getEmbeddedFiles();
    
    // Create directories
    $dirs = ['src', 'data'];
    foreach ($dirs as $dir) {
        $path = __DIR__ . '/' . $dir;
        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true)) {
                $errors[] = "Failed to create directory: $dir";
            }
        }
    }
    
    // Process each file
    foreach ($FILES_TO_DOWNLOAD as $file) {
        $localPath = __DIR__ . '/' . $file;
        
        // Check if file already exists locally
        if (file_exists($localPath)) {
            $skipped[] = $file;
            continue;
        }
        
        // Try to download from GitHub first
        $url = $baseUrl . $file;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $content && strlen($content) > 100) {
            // Downloaded from GitHub
            if (file_put_contents($localPath, $content) !== false) {
                $downloaded[] = $file;
                continue;
            }
        }
        
        // Fallback: use embedded file
        if (isset($embeddedFiles[$file])) {
            $embeddedContent = $embeddedFiles[$file];
            if ($embeddedContent && file_put_contents($localPath, $embeddedContent) !== false) {
                $embedded[] = $file;
                continue;
            }
        }
        
        // File not available
        $errors[] = "Cannot create: $file";
    }
    
    // Check what we have
    $available = [];
    $missing = [];
    foreach ($FILES_TO_DOWNLOAD as $file) {
        $localPath = __DIR__ . '/' . $file;
        if (file_exists($localPath)) {
            $available[] = $file;
        } else {
            $missing[] = $file;
        }
    }
    
    // Success if we have all files
    $success = empty($missing);
    
    // Build message
    $message = '';
    if (!empty($skipped)) {
        $message = count($skipped) . ' exist';
    }
    if (!empty($downloaded)) {
        $message .= ($message ? ', ' : '') . count($downloaded) . ' from GitHub';
    }
    if (!empty($embedded)) {
        $message .= ($message ? ', ' : '') . count($embedded) . ' from installer';
    }
    
    return [
        'success' => $success,
        'downloaded' => $downloaded,
        'skipped' => $skipped,
        'available' => $available,
        'missing' => $missing,
        'errors' => $success ? [] : $errors, // Only show errors if actually failed
        'total' => count($FILES_TO_DOWNLOAD),
        'message' => $message ?: 'All files ready',
    ];
}

function saveConfig(array $data): array {
    $adminPass = $data['admin_password'] ?? '';
    if (strlen($adminPass) < 6) {
        $adminPass = bin2hex(random_bytes(8));
    }
    
    // Extract bot username from Telegram if token is provided
    $botUsername = '';
    $token = $data['telegram_token'] ?? '';
    if ($token !== '') {
        $ch = curl_init("https://api.telegram.org/bot{$token}/getMe");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp) {
            $json = json_decode($resp, true);
            if (($json['ok'] ?? false) && isset($json['result']['username'])) {
                $botUsername = $json['result']['username'];
            }
        }
    }
    
    $config = [
        'PROJECT_NAME' => $data['project_name'] ?? 'VoltPing',
        'ADMIN_PASSWORD' => $adminPass,
        'TG_BOT_TOKEN' => $token,
        'TG_BOT_USERNAME' => $botUsername,
        'TUYA_DEVICE_ID' => $data['tuya_device_id'] ?? '',
        'TUYA_ACCESS_ID' => $data['tuya_access_id'] ?? '',
        'TUYA_ACCESS_SECRET' => $data['tuya_access_secret'] ?? '',
        'TUYA_REGION' => $data['tuya_region'] ?? 'eu',
        'TUYA_MODE' => $data['tuya_mode'] ?? 'cloud',
        'TUYA_LOCAL_IP' => $data['tuya_local_ip'] ?? '',
        'TUYA_LOCAL_KEY' => $data['tuya_local_key'] ?? '',
        'TUYA_LOCAL_VERSION' => $data['tuya_local_version'] ?? '3.5',
        'VOLTAGE_ON_THRESHOLD' => $data['voltage_threshold'] ?? '50',
    ];
    
    $envContent = "# VoltPing Configuration\n";
    $envContent .= "# Generated by installer on " . date('Y-m-d H:i:s') . "\n\n";
    
    foreach ($config as $key => $value) {
        if (!empty($value) || in_array($key, ['PROJECT_NAME', 'TUYA_MODE'])) {
            $envContent .= "$key=\"$value\"\n";
        }
    }
    
    // Write .env to src directory (where config.php looks)
    $srcDir = __DIR__ . '/src';
    if (!is_dir($srcDir)) {
        mkdir($srcDir, 0755, true);
    }
    
    $envPathSrc = $srcDir . '/.env';
    if (file_put_contents($envPathSrc, $envContent) === false) {
        return ['success' => false, 'error' => 'Failed to write src/.env file'];
    }
    
    // Also write to root for compatibility
    $envPathRoot = __DIR__ . '/.env';
    file_put_contents($envPathRoot, $envContent);
    
    // Store admin password in session for display
    $_SESSION['admin_password'] = $adminPass;
    
    return ['success' => true, 'admin_password' => $adminPass];
}

function setupDatabase(): array {
    // Database in src directory (where config.php expects it)
    $srcDir = __DIR__ . '/src';
    if (!is_dir($srcDir)) {
        mkdir($srcDir, 0755, true);
    }
    
    $dbPath = $srcDir . '/voltping.sqlite';
    
    try {
        $db = new SQLite3($dbPath);
        
        // Create minimal state table - config.php will add all other tables
        $db->exec("CREATE TABLE IF NOT EXISTS state (
            id INTEGER PRIMARY KEY,
            is_on INTEGER DEFAULT 1,
            last_voltage REAL DEFAULT 0,
            last_power REAL DEFAULT 0,
            last_current REAL DEFAULT 0,
            updated_at TEXT,
            local_key TEXT
        )");
        
        // Initialize state
        $db->exec("INSERT OR IGNORE INTO state (id, is_on, updated_at) VALUES (1, 1, datetime('now', 'localtime'))");
        
        $db->close();
        
        // Now use config.php to create all proper tables
        if (file_exists(__DIR__ . '/src/config.php')) {
            require_once __DIR__ . '/src/config.php';
            $config = getConfig();
            getDatabase($config); // This creates all tables properly
        }
        
        return ['success' => true];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function setupWebhook(string $token, string $webhookUrl): array {
    if (empty($token) || empty($webhookUrl)) {
        return ['success' => false, 'error' => 'Token and webhook URL are required'];
    }
    
    $url = "https://api.telegram.org/bot$token/setWebhook";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'url' => $webhookUrl,
            'allowed_updates' => json_encode(['message', 'callback_query']),
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => "cURL error: $error"];
    }
    
    $data = json_decode($response, true);
    if (!$data || !($data['ok'] ?? false)) {
        return ['success' => false, 'error' => $data['description'] ?? 'Failed to set webhook'];
    }
    
    return ['success' => true];
}

function detectLocalKey(array $data): array {
    $result = testTuya(
        $data['tuya_access_id'] ?? '',
        $data['tuya_access_secret'] ?? '',
        $data['tuya_device_id'] ?? '',
        $data['tuya_region'] ?? 'eu'
    );
    
    if (!$result['success']) {
        return $result;
    }
    
    if (empty($result['local_key'])) {
        return ['success' => false, 'error' => 'Local Key not available via API'];
    }
    
    return [
        'success' => true,
        'local_key' => $result['local_key'],
    ];
}

function finalizeInstallation(): array {
    // Generate cron command
    $watchScript = __DIR__ . '/src/watch_power.php';
    $cronCommand = "* * * * * php $watchScript >> /dev/null 2>&1";
    
    // Check if install.php should be deleted
    $installFile = __FILE__;
    
    return [
        'success' => true,
        'cron_command' => $cronCommand,
        'install_file' => basename($installFile),
        'admin_password' => $_SESSION['admin_password'] ?? null,
    ];
}

// ============================================================================
// HTML Interface
// ============================================================================
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VoltPing Installer</title>
    <style>
        :root {
            --bg: #0a0a0a;
            --bg-card: #141414;
            --bg-input: #1a1a1a;
            --border: #2a2a2a;
            --text: #ffffff;
            --text-dim: #888;
            --accent: #3b82f6;
            --success: #22c55e;
            --warning: #eab308;
            --error: #ef4444;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.6;
        }
        
        .container {
            max-width: 700px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .logo { font-size: 3rem; margin-bottom: 0.5rem; }
        h1 { font-size: 2rem; margin-bottom: 0.25rem; }
        .version { color: var(--text-dim); font-size: 0.9rem; }
        
        /* Progress Steps */
        .steps {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        
        .step-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--bg-card);
            border-radius: 2rem;
            font-size: 0.85rem;
            color: var(--text-dim);
            border: 1px solid var(--border);
            transition: all 0.3s;
        }
        
        .step-indicator.active {
            color: var(--accent);
            border-color: var(--accent);
        }
        
        .step-indicator.completed {
            color: var(--success);
            border-color: var(--success);
        }
        
        .step-num {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.75rem;
        }
        
        .step-indicator.active .step-num { background: var(--accent); color: white; }
        .step-indicator.completed .step-num { background: var(--success); color: white; }
        
        /* Card */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 1rem;
            padding: 2rem;
            margin-bottom: 1.5rem;
        }
        
        .card h2 {
            font-size: 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Form Elements */
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        .label-hint {
            color: var(--text-dim);
            font-weight: normal;
            font-size: 0.8rem;
        }
        
        input, select {
            width: 100%;
            padding: 0.75rem 1rem;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            color: var(--text);
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: var(--accent);
        }
        
        input::placeholder { color: var(--text-dim); }
        
        .input-group {
            display: flex;
            gap: 0.5rem;
        }
        
        .input-group input { flex: 1; }
        
        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-primary {
            background: var(--accent);
            color: white;
        }
        
        .btn-primary:hover { background: #2563eb; }
        .btn-primary:disabled { background: var(--border); cursor: not-allowed; }
        
        .btn-secondary {
            background: var(--bg-input);
            color: var(--text);
            border: 1px solid var(--border);
        }
        
        .btn-secondary:hover { border-color: var(--text-dim); }
        
        .btn-success { background: var(--success); color: white; }
        .btn-warning { background: var(--warning); color: black; }
        
        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }
        
        .actions {
            display: flex;
            justify-content: space-between;
            margin-top: 2rem;
        }
        
        /* Checks List */
        .checks-list {
            list-style: none;
        }
        
        .check-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border);
        }
        
        .check-item:last-child { border-bottom: none; }
        
        .check-name { font-weight: 500; }
        .check-value { color: var(--text-dim); font-size: 0.9rem; }
        
        .check-status {
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .check-ok { background: rgba(34, 197, 94, 0.2); color: var(--success); }
        .check-fail { background: rgba(239, 68, 68, 0.2); color: var(--error); }
        
        /* Alert */
        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }
        
        .alert-icon { font-size: 1.25rem; flex-shrink: 0; }
        
        .alert-success { background: rgba(34, 197, 94, 0.1); border: 1px solid var(--success); }
        .alert-error { background: rgba(239, 68, 68, 0.1); border: 1px solid var(--error); }
        .alert-warning { background: rgba(234, 179, 8, 0.1); border: 1px solid var(--warning); }
        .alert-info { background: rgba(59, 130, 246, 0.1); border: 1px solid var(--accent); }
        
        /* Progress Bar */
        .progress-bar {
            height: 8px;
            background: var(--border);
            border-radius: 4px;
            overflow: hidden;
            margin: 1rem 0;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--accent);
            border-radius: 4px;
            transition: width 0.3s;
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .tab {
            padding: 0.5rem 1rem;
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            color: var(--text-dim);
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        
        .tab:hover { border-color: var(--text-dim); }
        .tab.active { background: var(--accent); border-color: var(--accent); color: white; }
        
        /* Code Block */
        .code-block {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            padding: 1rem;
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 0.9rem;
            overflow-x: auto;
            position: relative;
        }
        
        .code-block .copy-btn {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }
        
        /* Final Summary */
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border);
        }
        
        .summary-item:last-child { border-bottom: none; }
        .summary-label { color: var(--text-dim); }
        .summary-value { font-weight: 500; }
        
        /* Spinner */
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid var(--border);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin { to { transform: rotate(360deg); } }
        
        /* Responsive */
        @media (max-width: 600px) {
            .container { padding: 1rem; }
            .card { padding: 1.25rem; }
            .actions { flex-direction: column-reverse; gap: 0.5rem; }
            .actions .btn { width: 100%; justify-content: center; }
            .steps { gap: 0.25rem; }
            .step-indicator { padding: 0.4rem 0.75rem; font-size: 0.75rem; }
        }
        
        /* Hide inactive steps */
        .step-content { display: none; }
        .step-content.active { display: block; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">⚡</div>
            <h1>VoltPing Installer</h1>
            <p class="version">v<?= VOLTPING_VERSION ?></p>
        </header>
        
        <div class="steps">
            <div class="step-indicator active" data-step="1">
                <span class="step-num">1</span>
                <span>Перевірка</span>
            </div>
            <div class="step-indicator" data-step="2">
                <span class="step-num">2</span>
                <span>Telegram</span>
            </div>
            <div class="step-indicator" data-step="3">
                <span class="step-num">3</span>
                <span>Tuya</span>
            </div>
            <div class="step-indicator" data-step="4">
                <span class="step-num">4</span>
                <span>Налаштування</span>
            </div>
            <div class="step-indicator" data-step="5">
                <span class="step-num">5</span>
                <span>Встановлення</span>
            </div>
            <div class="step-indicator" data-step="6">
                <span class="step-num">6</span>
                <span>Готово</span>
            </div>
        </div>
        
        <!-- Step 1: Requirements Check -->
        <div class="step-content active" id="step-1">
            <div class="card">
                <h2>🔍 Перевірка системи</h2>
                <div id="requirements-list">
                    <div style="text-align: center; padding: 2rem;">
                        <div class="spinner" style="margin: 0 auto;"></div>
                        <p style="margin-top: 1rem; color: var(--text-dim);">Перевірка вимог...</p>
                    </div>
                </div>
            </div>
            <div class="actions">
                <div></div>
                <button class="btn btn-primary" id="btn-step1-next" disabled>
                    Далі →
                </button>
            </div>
        </div>
        
        <!-- Step 2: Telegram Setup -->
        <div class="step-content" id="step-2">
            <div class="card">
                <h2>🤖 Telegram Bot</h2>
                
                <div class="alert alert-info">
                    <span class="alert-icon">💡</span>
                    <div>
                        <strong>Як отримати токен:</strong><br>
                        1. Відкрийте <a href="https://t.me/BotFather" target="_blank" style="color: var(--accent);">@BotFather</a> в Telegram<br>
                        2. Надішліть /newbot<br>
                        3. Введіть назву та username бота<br>
                        4. Скопіюйте токен
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Bot Token</label>
                    <div class="input-group">
                        <input type="text" id="telegram_token" placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz">
                        <button class="btn btn-secondary btn-sm" id="btn-test-telegram">Перевірити</button>
                    </div>
                </div>
                
                <div id="telegram-result"></div>
            </div>
            <div class="actions">
                <button class="btn btn-secondary" onclick="goToStep(1)">← Назад</button>
                <button class="btn btn-primary" id="btn-step2-next" disabled>Далі →</button>
            </div>
        </div>
        
        <!-- Step 3: Tuya Setup -->
        <div class="step-content" id="step-3">
            <div class="card">
                <h2>⚡ Tuya API</h2>
                
                <div class="alert alert-info">
                    <span class="alert-icon">📖</span>
                    <div>
                        Детальна інструкція: <a href="https://github.com/ksanyok/VoltPing/blob/main/docs/TUYA_SETUP.md" target="_blank" style="color: var(--accent);">TUYA_SETUP.md</a>
                        | <a href="https://iot.tuya.com" target="_blank" style="color: var(--accent);">Tuya IoT Platform</a>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Device ID <span class="label-hint">з Tuya IoT Platform</span></label>
                    <input type="text" id="tuya_device_id" placeholder="bfa7XXXXXXXXXXXXXXXX">
                </div>
                
                <div class="form-group">
                    <label>Access ID / Client ID</label>
                    <input type="text" id="tuya_access_id" placeholder="xxxxxxxxxxxxxx">
                </div>
                
                <div class="form-group">
                    <label>Access Secret / Client Secret</label>
                    <input type="password" id="tuya_access_secret" placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                </div>
                
                <div class="form-group">
                    <label>Регіон</label>
                    <select id="tuya_region">
                        <option value="eu">🇪🇺 Europe (eu)</option>
                        <option value="us">🇺🇸 America (us)</option>
                        <option value="cn">🇨🇳 China (cn)</option>
                        <option value="in">🇮🇳 India (in)</option>
                    </select>
                </div>
                
                <button class="btn btn-secondary" id="btn-test-tuya" style="width: 100%;">
                    🔌 Перевірити підключення
                </button>
                
                <div id="tuya-result" style="margin-top: 1rem;"></div>
            </div>
            <div class="actions">
                <button class="btn btn-secondary" onclick="goToStep(2)">← Назад</button>
                <button class="btn btn-primary" id="btn-step3-next" disabled>Далі →</button>
            </div>
        </div>
        
        <!-- Step 4: Settings -->
        <div class="step-content" id="step-4">
            <div class="card">
                <h2>⚙️ Налаштування</h2>
                
                <div class="form-group">
                    <label>Назва проекту</label>
                    <input type="text" id="project_name" value="VoltPing" placeholder="VoltPing">
                </div>
                
                <div class="form-group">
                    <label>Пароль адмінки <span class="label-hint">мін. 6 символів</span></label>
                    <input type="password" id="admin_password" placeholder="Залиште пустим для автогенерації">
                </div>
                
                <hr style="border-color: var(--border); margin: 1.5rem 0;">
                
                <div class="form-group">
                    <label>Режим підключення Tuya</label>
                    <select id="tuya_mode">
                        <option value="cloud">☁️ Cloud — через API (ліміт 30к/міс)</option>
                        <option value="local">🏠 Local — пряме підключення (без лімітів)</option>
                        <option value="hybrid">🔄 Hybrid — local + cloud fallback</option>
                    </select>
                </div>
                
                <div id="local-settings" style="display: none;">
                    <div class="form-group">
                        <label>IP адреса розетки <span class="label-hint">в локальній мережі</span></label>
                        <input type="text" id="tuya_local_ip" placeholder="192.168.1.100">
                    </div>
                    
                    <div class="form-group">
                        <label>
                            Local Key 
                            <button class="btn btn-secondary btn-sm" id="btn-detect-key" style="margin-left: 0.5rem;">
                                🔑 Отримати автоматично
                            </button>
                        </label>
                        <input type="text" id="tuya_local_key" placeholder="Буде отримано автоматично">
                    </div>
                    
                    <div class="form-group">
                        <label>Версія протоколу</label>
                        <select id="tuya_local_version">
                            <option value="3.5">3.5 (нові пристрої)</option>
                            <option value="3.4">3.4</option>
                            <option value="3.3">3.3 (старі пристрої)</option>
                        </select>
                    </div>
                </div>
                
                <hr style="border-color: var(--border); margin: 1.5rem 0;">
                
                <div class="form-group">
                    <label>Інтервал опитування <span class="label-hint">секунд</span></label>
                    <select id="poll_interval">
                        <option value="30">30 секунд</option>
                        <option value="60" selected>1 хвилина</option>
                        <option value="120">2 хвилини</option>
                        <option value="300">5 хвилин</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Поріг напруги для визначення світла <span class="label-hint">V</span></label>
                    <input type="number" id="voltage_threshold" value="180" min="100" max="250">
                </div>
                
                <hr style="border-color: var(--border); margin: 1.5rem 0;">
                
                <details>
                    <summary style="cursor: pointer; color: var(--text-dim);">
                        📅 Моніторинг графіків (опційно)
                    </summary>
                    <div style="padding-top: 1rem;">
                        <div class="form-group">
                            <label>ID каналу з графіками</label>
                            <input type="text" id="schedule_channel_id" placeholder="-1001234567890">
                        </div>
                        <div class="form-group">
                            <label>Номер черги (1-6)</label>
                            <input type="number" id="schedule_queue" placeholder="1" min="1" max="6">
                        </div>
                    </div>
                </details>
            </div>
            <div class="actions">
                <button class="btn btn-secondary" onclick="goToStep(3)">← Назад</button>
                <button class="btn btn-primary" onclick="goToStep(5)">Далі →</button>
            </div>
        </div>
        
        <!-- Step 5: Installation -->
        <div class="step-content" id="step-5">
            <div class="card">
                <h2>📦 Встановлення</h2>
                
                <div id="install-progress">
                    <div class="progress-item" data-task="download">
                        <span>📥 Завантаження файлів...</span>
                        <span class="status">⏳</span>
                    </div>
                    <div class="progress-bar"><div class="progress-fill" style="width: 0%;"></div></div>
                    
                    <div class="progress-item" data-task="config" style="margin-top: 1rem;">
                        <span>⚙️ Збереження конфігурації...</span>
                        <span class="status">⏳</span>
                    </div>
                    
                    <div class="progress-item" data-task="database" style="margin-top: 0.5rem;">
                        <span>🗄️ Створення бази даних...</span>
                        <span class="status">⏳</span>
                    </div>
                    
                    <div class="progress-item" data-task="webhook" style="margin-top: 0.5rem;">
                        <span>🔗 Налаштування webhook...</span>
                        <span class="status">⏳</span>
                    </div>
                </div>
                
                <div id="install-result" style="margin-top: 1.5rem;"></div>
            </div>
            <div class="actions">
                <button class="btn btn-secondary" onclick="goToStep(4)" id="btn-step5-back">← Назад</button>
                <button class="btn btn-primary" id="btn-step5-install">🚀 Встановити</button>
                <button class="btn btn-success" id="btn-step5-next" style="display: none;">Далі →</button>
            </div>
        </div>
        
        <!-- Step 6: Complete -->
        <div class="step-content" id="step-6">
            <div class="card">
                <h2>✅ Встановлення завершено!</h2>
                
                <div class="alert alert-success">
                    <span class="alert-icon">🎉</span>
                    <div>VoltPing успішно встановлено!</div>
                </div>
                
                <h3 style="margin: 1.5rem 0 1rem;">📋 Дані для входу</h3>
                <div id="credentials-info"></div>
                
                <h3 style="margin: 1.5rem 0 1rem;">⏰ Налаштування Cron</h3>
                <p style="color: var(--text-dim); margin-bottom: 0.5rem;">
                    Додайте цей рядок до crontab для автоматичного моніторингу:
                </p>
                <div class="code-block">
                    <code id="cron-command">* * * * * php <?= __DIR__ ?>/src/watch_power.php >> /dev/null 2>&1</code>
                    <button class="btn btn-secondary btn-sm copy-btn" onclick="copyCode('cron-command')">📋</button>
                </div>
                <p style="color: var(--text-dim); font-size: 0.85rem; margin-top: 0.5rem;">
                    Команда: <code>crontab -e</code> і вставте рядок вище
                </p>
                
                <h3 style="margin: 1.5rem 0 1rem;">⚠️ Важливо!</h3>
                <div class="alert alert-warning">
                    <span class="alert-icon">🗑️</span>
                    <div>
                        <strong>Видаліть install.php!</strong><br>
                        Цей файл містить конфіденційну інформацію. Видаліть його після встановлення.
                    </div>
                </div>
                
                <div style="margin-top: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                    <a href="index.php" class="btn btn-primary">🏠 Головна сторінка</a>
                    <a href="src/admin.php" class="btn btn-secondary">⚙️ Адмін панель</a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // State
        let currentStep = 1;
        const formData = {};
        
        // API helper
        async function api(action, data = {}) {
            const formData = new FormData();
            formData.append('action', action);
            for (const [key, value] of Object.entries(data)) {
                formData.append(key, value);
            }
            
            const response = await fetch('install.php', {
                method: 'POST',
                body: formData
            });
            
            return response.json();
        }
        
        // Step navigation
        function goToStep(step) {
            document.querySelectorAll('.step-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.step-indicator').forEach(el => {
                el.classList.remove('active');
                if (parseInt(el.dataset.step) < step) {
                    el.classList.add('completed');
                } else {
                    el.classList.remove('completed');
                }
            });
            
            document.getElementById(`step-${step}`).classList.add('active');
            document.querySelector(`.step-indicator[data-step="${step}"]`).classList.add('active');
            currentStep = step;
            
            // Trigger step init
            if (step === 1) checkRequirements();
            if (step === 5) initInstallStep();
        }
        
        // Step 1: Check Requirements
        async function checkRequirements() {
            const result = await api('check_requirements');
            const container = document.getElementById('requirements-list');
            
            if (!result.success) {
                container.innerHTML = `<div class="alert alert-error">Помилка: ${result.error}</div>`;
                return;
            }
            
            let html = '<ul class="checks-list">';
            for (const [key, check] of Object.entries(result.checks)) {
                html += `
                    <li class="check-item">
                        <div>
                            <div class="check-name">${check.name}</div>
                            <div class="check-value">${check.current}</div>
                        </div>
                        <span class="check-status ${check.ok ? 'check-ok' : 'check-fail'}">
                            ${check.ok ? '✓ OK' : '✗ Fail'}
                        </span>
                    </li>
                `;
            }
            html += '</ul>';
            
            container.innerHTML = html;
            document.getElementById('btn-step1-next').disabled = !result.all_ok;
            
            if (!result.all_ok) {
                container.innerHTML += `
                    <div class="alert alert-error" style="margin-top: 1rem;">
                        <span class="alert-icon">❌</span>
                        <div>Виправте проблеми вище перед продовженням</div>
                    </div>
                `;
            }
        }
        
        // Step 2: Test Telegram
        document.getElementById('btn-test-telegram').addEventListener('click', async () => {
            const token = document.getElementById('telegram_token').value.trim();
            const resultDiv = document.getElementById('telegram-result');
            const btn = document.getElementById('btn-test-telegram');
            
            btn.disabled = true;
            btn.innerHTML = '<div class="spinner"></div>';
            
            const result = await api('test_telegram', { token });
            
            btn.disabled = false;
            btn.innerHTML = 'Перевірити';
            
            if (result.success) {
                resultDiv.innerHTML = `
                    <div class="alert alert-success">
                        <span class="alert-icon">✅</span>
                        <div>
                            <strong>Бот підключено!</strong><br>
                            @${result.bot_username} (${result.bot_name})
                        </div>
                    </div>
                `;
                formData.telegram_token = token;
                formData.bot_username = result.bot_username;
                document.getElementById('btn-step2-next').disabled = false;
            } else {
                resultDiv.innerHTML = `
                    <div class="alert alert-error">
                        <span class="alert-icon">❌</span>
                        <div>${result.error}</div>
                    </div>
                `;
            }
        });
        
        // Step 3: Test Tuya
        document.getElementById('btn-test-tuya').addEventListener('click', async () => {
            const data = {
                access_id: document.getElementById('tuya_access_id').value.trim(),
                access_secret: document.getElementById('tuya_access_secret').value.trim(),
                device_id: document.getElementById('tuya_device_id').value.trim(),
                region: document.getElementById('tuya_region').value
            };
            
            const resultDiv = document.getElementById('tuya-result');
            const btn = document.getElementById('btn-test-tuya');
            
            btn.disabled = true;
            btn.innerHTML = '<div class="spinner" style="display: inline-block;"></div> Перевірка...';
            
            const result = await api('test_tuya', data);
            
            btn.disabled = false;
            btn.innerHTML = '🔌 Перевірити підключення';
            
            if (result.success) {
                resultDiv.innerHTML = `
                    <div class="alert alert-success">
                        <span class="alert-icon">✅</span>
                        <div>
                            <strong>Пристрій знайдено!</strong><br>
                            ${result.device_name} (${result.device_model})<br>
                            Статус: ${result.online ? '🟢 Online' : '🔴 Offline'}
                            ${result.local_key ? '<br>Local Key: ✓ доступний' : ''}
                        </div>
                    </div>
                `;
                
                Object.assign(formData, data);
                if (result.local_key) {
                    formData.tuya_local_key = result.local_key;
                    document.getElementById('tuya_local_key').value = result.local_key;
                }
                document.getElementById('btn-step3-next').disabled = false;
            } else {
                resultDiv.innerHTML = `
                    <div class="alert alert-error">
                        <span class="alert-icon">❌</span>
                        <div>${result.error}</div>
                    </div>
                `;
            }
        });
        
        // Step 4: Mode toggle
        document.getElementById('tuya_mode').addEventListener('change', (e) => {
            const localSettings = document.getElementById('local-settings');
            localSettings.style.display = e.target.value !== 'cloud' ? 'block' : 'none';
        });
        
        // Step 4: Detect local key
        document.getElementById('btn-detect-key').addEventListener('click', async () => {
            const btn = document.getElementById('btn-detect-key');
            btn.disabled = true;
            btn.innerHTML = '⏳ Отримання...';
            
            const result = await api('detect_local_key', {
                tuya_access_id: formData.access_id || document.getElementById('tuya_access_id').value,
                tuya_access_secret: formData.access_secret || document.getElementById('tuya_access_secret').value,
                tuya_device_id: formData.device_id || document.getElementById('tuya_device_id').value,
                tuya_region: document.getElementById('tuya_region').value
            });
            
            btn.disabled = false;
            btn.innerHTML = '🔑 Отримати автоматично';
            
            if (result.success) {
                document.getElementById('tuya_local_key').value = result.local_key;
                formData.tuya_local_key = result.local_key;
            } else {
                alert('Помилка: ' + result.error);
            }
        });
        
        // Step 5: Installation
        function initInstallStep() {
            // Reset progress
            document.querySelectorAll('.progress-item .status').forEach(el => el.textContent = '⏳');
            document.querySelector('.progress-fill').style.width = '0%';
            document.getElementById('install-result').innerHTML = '';
            document.getElementById('btn-step5-next').style.display = 'none';
            document.getElementById('btn-step5-install').style.display = '';
        }
        
        document.getElementById('btn-step5-install').addEventListener('click', async () => {
            const installBtn = document.getElementById('btn-step5-install');
            const backBtn = document.getElementById('btn-step5-back');
            const nextBtn = document.getElementById('btn-step5-next');
            const resultDiv = document.getElementById('install-result');
            
            installBtn.disabled = true;
            backBtn.disabled = true;
            installBtn.innerHTML = '<div class="spinner" style="display: inline-block;"></div> Встановлення...';
            
            const updateStatus = (task, status) => {
                const item = document.querySelector(`.progress-item[data-task="${task}"] .status`);
                if (item) item.textContent = status;
            };
            
            try {
                // 1. Download files
                updateStatus('download', '⏳');
                const downloadResult = await api('download_files');
                if (!downloadResult.success) {
                    throw new Error('Download failed: ' + downloadResult.errors.join(', '));
                }
                updateStatus('download', '✅');
                document.querySelector('.progress-fill').style.width = '25%';
                
                // 2. Save config
                updateStatus('config', '⏳');
                const configData = {
                    project_name: document.getElementById('project_name').value || 'VoltPing',
                    admin_password: document.getElementById('admin_password').value,
                    telegram_token: formData.telegram_token,
                    tuya_device_id: formData.device_id,
                    tuya_access_id: formData.access_id,
                    tuya_access_secret: formData.access_secret,
                    tuya_region: document.getElementById('tuya_region').value,
                    tuya_mode: document.getElementById('tuya_mode').value,
                    tuya_local_ip: document.getElementById('tuya_local_ip').value,
                    tuya_local_key: document.getElementById('tuya_local_key').value || formData.tuya_local_key || '',
                    tuya_local_version: document.getElementById('tuya_local_version').value,
                    poll_interval: document.getElementById('poll_interval').value,
                    voltage_threshold: document.getElementById('voltage_threshold').value,
                    schedule_channel_id: document.getElementById('schedule_channel_id').value,
                    schedule_queue: document.getElementById('schedule_queue').value,
                };
                
                const configResult = await api('save_config', configData);
                if (!configResult.success) {
                    throw new Error('Config save failed: ' + configResult.error);
                }
                formData.admin_password = configResult.admin_password;
                updateStatus('config', '✅');
                document.querySelector('.progress-fill').style.width = '50%';
                
                // 3. Setup database
                updateStatus('database', '⏳');
                const dbResult = await api('setup_database');
                if (!dbResult.success) {
                    throw new Error('Database setup failed: ' + dbResult.error);
                }
                updateStatus('database', '✅');
                document.querySelector('.progress-fill').style.width = '75%';
                
                // 4. Setup webhook
                updateStatus('webhook', '⏳');
                const webhookUrl = window.location.href.replace('install.php', 'src/bot.php');
                const webhookResult = await api('setup_webhook', {
                    token: formData.telegram_token,
                    webhook_url: webhookUrl
                });
                if (!webhookResult.success) {
                    throw new Error('Webhook setup failed: ' + webhookResult.error);
                }
                updateStatus('webhook', '✅');
                document.querySelector('.progress-fill').style.width = '100%';
                
                // Success!
                resultDiv.innerHTML = `
                    <div class="alert alert-success">
                        <span class="alert-icon">🎉</span>
                        <div><strong>Всі компоненти успішно встановлено!</strong></div>
                    </div>
                `;
                
                installBtn.style.display = 'none';
                nextBtn.style.display = '';
                backBtn.disabled = false;
                
            } catch (error) {
                resultDiv.innerHTML = `
                    <div class="alert alert-error">
                        <span class="alert-icon">❌</span>
                        <div>${error.message}</div>
                    </div>
                `;
                installBtn.disabled = false;
                backBtn.disabled = false;
                installBtn.innerHTML = '🚀 Спробувати знову';
            }
        });
        
        // Step 5: Next button
        document.getElementById('btn-step5-next').addEventListener('click', async () => {
            // Finalize and show summary
            const result = await api('finalize');
            
            // Update credentials info
            const credentialsDiv = document.getElementById('credentials-info');
            credentialsDiv.innerHTML = `
                <div class="summary-item">
                    <span class="summary-label">🌐 Сайт:</span>
                    <span class="summary-value"><a href="index.php">${window.location.origin}/</a></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">⚙️ Адмінка:</span>
                    <span class="summary-value"><a href="src/admin.php">${window.location.origin}/src/admin.php</a></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">🔑 Пароль:</span>
                    <span class="summary-value" style="font-family: monospace;">${formData.admin_password || result.admin_password || 'див. .env'}</span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">🤖 Telegram:</span>
                    <span class="summary-value"><a href="https://t.me/${formData.bot_username}" target="_blank">@${formData.bot_username}</a></span>
                </div>
            `;
            
            goToStep(6);
        });
        
        // Navigation buttons
        document.getElementById('btn-step1-next').addEventListener('click', () => goToStep(2));
        document.getElementById('btn-step2-next').addEventListener('click', () => goToStep(3));
        document.getElementById('btn-step3-next').addEventListener('click', () => goToStep(4));
        
        // Copy code helper
        function copyCode(elementId) {
            const text = document.getElementById(elementId).textContent;
            navigator.clipboard.writeText(text).then(() => {
                const btn = document.querySelector(`#${elementId} + .copy-btn`);
                if (btn) {
                    btn.textContent = '✓';
                    setTimeout(() => btn.textContent = '📋', 2000);
                }
            });
        }
        
        // Init
        checkRequirements();
    </script>
</body>
</html>
<?php

// ============================================================================
// EMBEDDED FILES CONTENT
// ============================================================================

function getEmbeddedConfigPhp(): string {
    return <<<'PHPFILE'
<?php
declare(strict_types=1);

function loadEnvFile(string $path): void {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        $value = trim($value, "\"'");
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
}

function env(string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

loadEnvFile(__DIR__ . '/../.env');
date_default_timezone_set((string)env('TIMEZONE', 'Europe/Kyiv'));

function getConfig(): array {
    $regions = ['eu' => 'https://openapi.tuyaeu.com', 'us' => 'https://openapi.tuyaus.com', 'cn' => 'https://openapi.tuyacn.com', 'in' => 'https://openapi.tuyain.com'];
    $region = (string)env('TUYA_REGION', 'eu');
    return [
        'project_name' => (string)env('PROJECT_NAME', 'VoltPing'),
        'admin_password' => (string)env('ADMIN_PASSWORD', ''),
        'tuya_endpoint' => $regions[$region] ?? $regions['eu'],
        'tuya_client_id' => (string)env('TUYA_ACCESS_ID', ''),
        'tuya_secret' => (string)env('TUYA_ACCESS_SECRET', ''),
        'tuya_device_id' => (string)env('TUYA_DEVICE_ID', ''),
        'tuya_mode' => (string)env('TUYA_MODE', 'cloud'),
        'tuya_local_key' => (string)env('TUYA_LOCAL_KEY', ''),
        'tuya_local_ip' => (string)env('TUYA_LOCAL_IP', ''),
        'tuya_local_version' => (float)env('TUYA_LOCAL_VERSION', 3.5),
        'tg_token' => (string)env('TG_BOT_TOKEN', ''),
        'db_file' => __DIR__ . '/voltping.sqlite',
        'voltage_on_threshold' => (float)env('VOLTAGE_ON_THRESHOLD', 50.0),
    ];
}

function getDatabase(array $config): PDO {
    $pdo = new PDO('sqlite:' . $config['db_file']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    ensureDb($pdo);
    return $pdo;
}

function ensureDb(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS state (k TEXT PRIMARY KEY, v TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL, type TEXT NOT NULL, voltage REAL NULL, note TEXT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_subscribers (chat_id INTEGER PRIMARY KEY, is_active INTEGER DEFAULT 1, username TEXT, first_name TEXT, dashboard_msg_id INTEGER, notify_power INTEGER DEFAULT 1)");
}

function dbGet(PDO $pdo, string $key, ?string $default = null): ?string {
    $st = $pdo->prepare('SELECT v FROM state WHERE k = :k LIMIT 1');
    $st->execute([':k' => $key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function dbSet(PDO $pdo, string $key, string $value): void {
    $st = $pdo->prepare('INSERT INTO state(k, v) VALUES(:k, :v) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $st->execute([':k' => $key, ':v' => $value]);
}

function tgRequest(string $token, string $method, array $payload): array {
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => 30]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?: [];
}

function formatDuration(int $seconds): string {
    if ($seconds < 60) return "{$seconds} сек";
    if ($seconds < 3600) return (int)floor($seconds/60) . " хв";
    return (int)floor($seconds/3600) . " год " . (int)floor(($seconds%3600)/60) . " хв";
}
PHPFILE;
}

function getEmbeddedTuyaClientPhp(): string {
    return <<<'PHPFILE'
<?php
declare(strict_types=1);

class TuyaClient {
    private string $clientId;
    private string $secret;
    private string $endpoint;
    private string $deviceId;
    private ?string $accessToken = null;
    private int $tokenExpires = 0;
    
    public function __construct(array $config) {
        $this->clientId = $config['tuya_client_id'];
        $this->secret = $config['tuya_secret'];
        $this->endpoint = $config['tuya_endpoint'];
        $this->deviceId = $config['tuya_device_id'];
    }
    
    private function getToken(): string {
        if ($this->accessToken && time() < $this->tokenExpires - 60) {
            return $this->accessToken;
        }
        $timestamp = (string)round(microtime(true) * 1000);
        $path = '/v1.0/token?grant_type=1';
        $contentHash = hash('sha256', '');
        $stringToSign = "GET\n$contentHash\n\n$path";
        $signStr = $this->clientId . $timestamp . $stringToSign;
        $sign = strtoupper(hash_hmac('sha256', $signStr, $this->secret));
        
        $ch = curl_init($this->endpoint . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ["client_id: {$this->clientId}", "sign: $sign", "t: $timestamp", "sign_method: HMAC-SHA256", "nonce: "],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        if (!($data['success'] ?? false)) {
            throw new RuntimeException('Token error: ' . ($data['msg'] ?? 'unknown'));
        }
        $this->accessToken = $data['result']['access_token'];
        $this->tokenExpires = time() + ($data['result']['expire_time'] ?? 7200);
        return $this->accessToken;
    }
    
    public function getDeviceStatus(): array {
        $token = $this->getToken();
        $timestamp = (string)round(microtime(true) * 1000);
        $path = "/v1.0/devices/{$this->deviceId}/status";
        $contentHash = hash('sha256', '');
        $stringToSign = "GET\n$contentHash\n\n$path";
        $signStr = $this->clientId . $token . $timestamp . $stringToSign;
        $sign = strtoupper(hash_hmac('sha256', $signStr, $this->secret));
        
        $ch = curl_init($this->endpoint . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ["client_id: {$this->clientId}", "access_token: $token", "sign: $sign", "t: $timestamp", "sign_method: HMAC-SHA256", "nonce: "],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        if (!($data['success'] ?? false)) {
            throw new RuntimeException('Status error: ' . ($data['msg'] ?? 'unknown'));
        }
        
        $result = ['voltage' => 0, 'power' => 0, 'current' => 0];
        foreach ($data['result'] ?? [] as $item) {
            $code = $item['code'] ?? '';
            $value = $item['value'] ?? 0;
            if ($code === 'cur_voltage') $result['voltage'] = $value / 10;
            elseif ($code === 'cur_power') $result['power'] = $value / 10;
            elseif ($code === 'cur_current') $result['current'] = $value;
        }
        return $result;
    }
    
    public function getLocalKey(): ?string {
        $token = $this->getToken();
        $timestamp = (string)round(microtime(true) * 1000);
        $path = "/v1.0/devices/{$this->deviceId}";
        $contentHash = hash('sha256', '');
        $stringToSign = "GET\n$contentHash\n\n$path";
        $signStr = $this->clientId . $token . $timestamp . $stringToSign;
        $sign = strtoupper(hash_hmac('sha256', $signStr, $this->secret));
        
        $ch = curl_init($this->endpoint . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ["client_id: {$this->clientId}", "access_token: $token", "sign: $sign", "t: $timestamp", "sign_method: HMAC-SHA256", "nonce: "],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        return $data['result']['local_key'] ?? null;
    }
}
PHPFILE;
}

function getEmbeddedWatchPowerPhp(): string {
    return <<<'PHPFILE'
<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tuya_client.php';

$config = getConfig();
$pdo = getDatabase($config);
$tuya = new TuyaClient($config);

try {
    $status = $tuya->getDeviceStatus();
    $voltage = $status['voltage'] ?? 0;
    $power = $status['power'] ?? 0;
    
    $lastVoltage = (float)dbGet($pdo, 'last_voltage', '0');
    $lastPowerState = dbGet($pdo, 'last_power_state', 'ON');
    $lastPowerTs = (int)dbGet($pdo, 'last_power_change_ts', '0');
    
    $isOn = $voltage >= $config['voltage_on_threshold'];
    $newPowerState = $isOn ? 'ON' : 'OFF';
    
    dbSet($pdo, 'last_voltage', (string)$voltage);
    dbSet($pdo, 'last_power', (string)$power);
    dbSet($pdo, 'last_check_ts', (string)time());
    
    // Power state changed
    if ($newPowerState !== $lastPowerState) {
        dbSet($pdo, 'last_power_state', $newPowerState);
        dbSet($pdo, 'last_power_change_ts', (string)time());
        
        $st = $pdo->prepare("INSERT INTO events(ts, type, voltage, note) VALUES(?, ?, ?, ?)");
        $st->execute([time(), $isOn ? 'power_on' : 'power_off', $voltage, null]);
        
        // Notify subscribers
        $token = $config['tg_token'];
        if ($token) {
            $duration = $lastPowerTs > 0 ? formatDuration(time() - $lastPowerTs) : '';
            $text = $isOn 
                ? "✅ *Світло з'явилося!*\n\n🕒 " . date('H:i:s') . "\n⚡ Напруга: {$voltage}V" . ($duration ? "\n⏱ Не було: $duration" : "")
                : "❌ *Світло зникло!*\n\n🕒 " . date('H:i:s') . ($duration ? "\n⏱ Було увімкнено: $duration" : "");
            
            $subs = $pdo->query("SELECT chat_id FROM bot_subscribers WHERE is_active = 1 AND notify_power = 1")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($subs as $chatId) {
                tgRequest($token, 'sendMessage', ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown']);
            }
        }
    }
    
    echo "OK: {$voltage}V, Power: {$power}W\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
PHPFILE;
}

function getEmbeddedBotPhp(): string {
    return <<<'PHPFILE'
<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$config = getConfig();
$pdo = getDatabase($config);
$token = $config['tg_token'];

$input = file_get_contents('php://input');
$update = json_decode($input, true);
if (!$update) exit;

$message = $update['message'] ?? $update['callback_query']['message'] ?? null;
$callbackQuery = $update['callback_query'] ?? null;
$chatId = $message['chat']['id'] ?? 0;
$text = trim($message['text'] ?? '');
$username = $message['from']['username'] ?? '';
$firstName = $message['from']['first_name'] ?? '';

// Handle callback
if ($callbackQuery) {
    $data = $callbackQuery['data'] ?? '';
    $chatId = $callbackQuery['message']['chat']['id'] ?? 0;
    tgRequest($token, 'answerCallbackQuery', ['callback_query_id' => $callbackQuery['id']]);
    $text = '/' . $data;
}

// Upsert subscriber
$st = $pdo->prepare("INSERT INTO bot_subscribers(chat_id, username, first_name, is_active) VALUES(?, ?, ?, 1) ON CONFLICT(chat_id) DO UPDATE SET is_active = 1, username = excluded.username");
$st->execute([$chatId, $username, $firstName]);

// Commands
if ($text === '/start' || $text === 'start') {
    $voltage = (float)dbGet($pdo, 'last_voltage', '0');
    $powerState = dbGet($pdo, 'last_power_state', 'ON');
    $emoji = $powerState === 'ON' ? '🟢' : '🔴';
    
    $reply = "👋 Вітаю в *{$config['project_name']}*!\n\n";
    $reply .= "$emoji Статус: " . ($powerState === 'ON' ? 'Світло є' : 'Світла немає') . "\n";
    $reply .= "⚡ Напруга: {$voltage}V\n\n";
    $reply .= "Ви будете отримувати сповіщення про зміни.";
    
    tgRequest($token, 'sendMessage', [
        'chat_id' => $chatId,
        'text' => $reply,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode(['inline_keyboard' => [
            [['text' => '📊 Статус', 'callback_data' => 'status']],
            [['text' => '🔕 Відписатись', 'callback_data' => 'stop']],
        ]]),
    ]);
}
elseif ($text === '/status' || $text === 'status') {
    $voltage = (float)dbGet($pdo, 'last_voltage', '0');
    $powerState = dbGet($pdo, 'last_power_state', 'ON');
    $lastTs = (int)dbGet($pdo, 'last_power_change_ts', '0');
    $emoji = $powerState === 'ON' ? '🟢' : '🔴';
    
    $reply = "$emoji *Статус*: " . ($powerState === 'ON' ? 'Світло є' : 'Світла немає') . "\n";
    $reply .= "⚡ Напруга: {$voltage}V\n";
    if ($lastTs > 0) {
        $reply .= "🕒 З " . date('H:i d.m', $lastTs) . " (" . formatDuration(time() - $lastTs) . ")";
    }
    
    tgRequest($token, 'sendMessage', ['chat_id' => $chatId, 'text' => $reply, 'parse_mode' => 'Markdown']);
}
elseif ($text === '/stop' || $text === 'stop') {
    $st = $pdo->prepare("UPDATE bot_subscribers SET is_active = 0 WHERE chat_id = ?");
    $st->execute([$chatId]);
    tgRequest($token, 'sendMessage', ['chat_id' => $chatId, 'text' => '🔕 Сповіщення вимкнено. /start щоб увімкнути знову.']);
}
PHPFILE;
}

function getEmbeddedAdminPhp(): string {
    return <<<'PHPFILE'
<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config.php';

$config = getConfig();
$pdo = getDatabase($config);
$password = $config['admin_password'];

// Auth check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $password) {
        $_SESSION['admin_auth'] = true;
    }
}

if (!($_SESSION['admin_auth'] ?? false) && $password !== '') {
    ?><!DOCTYPE html><html><head><meta charset="UTF-8"><title>Admin Login</title>
    <style>body{font-family:sans-serif;background:#111;color:#fff;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;}
    .login{background:#222;padding:2rem;border-radius:1rem;}.login input{padding:0.5rem;margin:0.5rem 0;width:200px;}
    .login button{padding:0.5rem 1rem;background:#3b82f6;color:#fff;border:none;cursor:pointer;border-radius:0.25rem;}</style></head>
    <body><div class="login"><h2>🔐 Admin</h2><form method="POST"><input type="password" name="password" placeholder="Пароль"><br><button type="submit">Увійти</button></form></div></body></html><?php
    exit;
}

// API endpoints
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];
    
    if ($action === 'status') {
        echo json_encode([
            'voltage' => (float)dbGet($pdo, 'last_voltage', '0'),
            'power_state' => dbGet($pdo, 'last_power_state', 'ON'),
            'last_check' => (int)dbGet($pdo, 'last_check_ts', '0'),
            'subscribers' => (int)$pdo->query("SELECT COUNT(*) FROM bot_subscribers WHERE is_active = 1")->fetchColumn(),
        ]);
    }
    elseif ($action === 'events') {
        $events = $pdo->query("SELECT * FROM events ORDER BY ts DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($events);
    }
    exit;
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin - <?= htmlspecialchars($config['project_name']) ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,sans-serif;background:#0a0a0a;color:#fff;padding:1rem}
.card{background:#1a1a1a;border-radius:1rem;padding:1.5rem;margin-bottom:1rem}.status{font-size:2rem;text-align:center}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem}.stat{text-align:center}
.stat-value{font-size:1.5rem;font-weight:bold}.stat-label{color:#888;font-size:0.9rem}
table{width:100%;border-collapse:collapse}th,td{padding:0.5rem;text-align:left;border-bottom:1px solid #333}
</style></head><body>
<h1 style="margin-bottom:1rem">⚡ <?= htmlspecialchars($config['project_name']) ?> Admin</h1>
<div class="card"><div class="status" id="status">Loading...</div></div>
<div class="grid">
<div class="card stat"><div class="stat-value" id="voltage">-</div><div class="stat-label">Voltage</div></div>
<div class="card stat"><div class="stat-value" id="subs">-</div><div class="stat-label">Subscribers</div></div>
</div>
<div class="card"><h3>Recent Events</h3><table id="events"><tr><th>Time</th><th>Type</th><th>Voltage</th></tr></table></div>
<script>
async function load() {
    const s = await fetch('?api=status').then(r=>r.json());
    document.getElementById('status').innerHTML = s.power_state === 'ON' ? '🟢 Світло є' : '🔴 Світла немає';
    document.getElementById('voltage').textContent = s.voltage + 'V';
    document.getElementById('subs').textContent = s.subscribers;
    
    const e = await fetch('?api=events').then(r=>r.json());
    const rows = e.slice(0,20).map(ev => `<tr><td>${new Date(ev.ts*1000).toLocaleString()}</td><td>${ev.type}</td><td>${ev.voltage||'-'}V</td></tr>`).join('');
    document.getElementById('events').innerHTML = '<tr><th>Time</th><th>Type</th><th>Voltage</th></tr>' + rows;
}
load(); setInterval(load, 30000);
</script></body></html>
PHPFILE;
}
