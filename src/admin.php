<?php
declare(strict_types=1);

/**
 * VoltPing - Admin Panel v1.2.0
 * Повноцінна веб-панель адміністрування
 */

require_once __DIR__ . '/config.php';

$config = getConfig();
$pdo = getDatabase($config);

// ==================== AUTHENTICATION ====================
session_start();

$adminPassword = $config['admin_password'] ?? '';
$isAuthenticated = empty($adminPassword) || ($_SESSION['admin_auth'] ?? false);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $adminPassword) {
        $_SESSION['admin_auth'] = true;
        $isAuthenticated = true;
    } else {
        $loginError = 'Невірний пароль';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ==================== CURRENT TAB ====================
$currentTab = $_GET['tab'] ?? 'dashboard';
$validTabs = ['dashboard', 'schedule', 'messages', 'notifications', 'users', 'settings', 'updates'];
if (!in_array($currentTab, $validTabs)) {
    $currentTab = 'dashboard';
}

// ==================== HELPER: Get DB Setting ====================
function getDbSetting(PDO $pdo, string $key, string $default = ''): string {
    $val = dbGet($pdo, $key);
    return $val !== null ? $val : $default;
}

// ==================== NOTIFICATION TEMPLATES ====================
function getNotificationTemplates(PDO $pdo): array {
    $defaults = [
        'power_on' => ['title' => 'Світло увімкнено', 'body' => '🟢 <b>Світло є!</b>\n\nЧас: {time}\nБуло вимкнено: {duration}', 'enabled' => 1],
        'power_off' => ['title' => 'Світло вимкнено', 'body' => '🔴 <b>Світла немає</b>\n\nЧас: {time}\nБуло увімкнено: {duration}', 'enabled' => 1],
        'voltage_warning' => ['title' => 'Попередження напруги', 'body' => '⚠️ <b>Нестабільна напруга!</b>\n\nПоточна: {voltage}V\nЧас: {time}', 'enabled' => 1],
        'voltage_critical' => ['title' => 'Критична напруга', 'body' => '🚨 <b>КРИТИЧНА НАПРУГА!</b>\n\n{voltage}V\nЧас: {time}', 'enabled' => 1],
        'voltage_normal' => ['title' => 'Напруга в нормі', 'body' => '✅ Напруга повернулась в норму\n\n{voltage}V', 'enabled' => 1],
    ];
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_templates (
        id TEXT PRIMARY KEY,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        enabled INTEGER DEFAULT 1
    )");
    
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO notification_templates (id, title, body, enabled) VALUES (?, ?, ?, ?)");
    foreach ($defaults as $id => $tpl) {
        $stmt->execute([$id, $tpl['title'], $tpl['body'], $tpl['enabled']]);
    }
    
    $result = [];
    $rows = $pdo->query("SELECT * FROM notification_templates")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $result[$row['id']] = $row;
    }
    return $result;
}

// ==================== API ACTIONS ====================
$flash = '';
$flashType = 'info';

if ($isAuthenticated && isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    switch ($_GET['api']) {
        case 'status':
            $state = loadLastState($pdo, $config);
            $stats = getSubscriberStats($pdo);
            $apiStats = getApiStats($pdo);
            
            echo json_encode([
                'state' => $state,
                'subscribers' => $stats,
                'api' => $apiStats,
                'version' => VOLTPING_VERSION,
            ], JSON_UNESCAPED_UNICODE);
            exit;
            
        case 'check_update':
            $url = 'https://api.github.com/repos/ksanyok/VoltPing/releases/latest';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_USERAGENT => 'VoltPing Updater',
            ]);
            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $latest = null;
            if ($code === 200 && $response) {
                $data = json_decode($response, true);
                $latest = [
                    'version' => $data['tag_name'] ?? null,
                    'url' => $data['html_url'] ?? null,
                    'notes' => $data['body'] ?? null,
                ];
            }
            
            $hasUpdate = $latest && version_compare(ltrim($latest['version'] ?? '', 'v'), VOLTPING_VERSION, '>');
            echo json_encode([
                'current' => VOLTPING_VERSION,
                'latest' => $latest,
                'has_update' => $hasUpdate,
            ], JSON_UNESCAPED_UNICODE);
            exit;
            
        case 'download_update':
            $url = 'https://api.github.com/repos/ksanyok/VoltPing/releases/latest';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_USERAGENT => 'VoltPing Updater',
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            
            $data = json_decode($response, true);
            $version = $data['tag_name'] ?? null;
            
            if (!$version) {
                echo json_encode(['ok' => false, 'error' => 'Не вдалося отримати версію']);
                exit;
            }
            
            $files = ['config.php', 'admin.php', 'bot.php', 'watch_power.php', 'schedule_parser.php'];
            $updated = [];
            $errors = [];
            
            foreach ($files as $file) {
                $fileUrl = "https://raw.githubusercontent.com/ksanyok/VoltPing/{$version}/src/{$file}";
                $ch = curl_init($fileUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_USERAGENT => 'VoltPing Updater',
                    CURLOPT_FOLLOWLOCATION => true,
                ]);
                $content = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($code === 200 && $content) {
                    @copy(__DIR__ . "/{$file}", __DIR__ . "/{$file}.backup");
                    if (@file_put_contents(__DIR__ . "/{$file}", $content)) {
                        $updated[] = $file;
                    } else {
                        $errors[] = $file;
                    }
                }
            }
            
            // Update index.php
            $indexUrl = "https://raw.githubusercontent.com/ksanyok/VoltPing/{$version}/index.php";
            $ch = curl_init($indexUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'VoltPing Updater',
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $content = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($code === 200 && $content) {
                @copy(dirname(__DIR__) . "/index.php", dirname(__DIR__) . "/index.php.backup");
                if (@file_put_contents(dirname(__DIR__) . "/index.php", $content)) {
                    $updated[] = 'index.php';
                }
            }
            
            echo json_encode([
                'ok' => true,
                'version' => $version,
                'updated' => $updated,
                'errors' => $errors,
            ], JSON_UNESCAPED_UNICODE);
            exit;
            
        case 'save_settings':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                echo json_encode(['ok' => false, 'error' => 'Invalid input']);
                exit;
            }
            
            $envPath = dirname(__DIR__) . '/.env';
            $envContent = file_exists($envPath) ? file_get_contents($envPath) : '';
            
            foreach ($input as $key => $value) {
                $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
                $replacement = "{$key}={$value}";
                if (preg_match($pattern, $envContent)) {
                    $envContent = preg_replace($pattern, $replacement, $envContent);
                } else {
                    $envContent .= "\n{$replacement}";
                }
            }
            
            if (file_put_contents($envPath, trim($envContent) . "\n")) {
                echo json_encode(['ok' => true]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Cannot write .env']);
            }
            exit;
            
        case 'save_template':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input || !isset($input['id'])) {
                echo json_encode(['ok' => false, 'error' => 'Invalid input']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE notification_templates SET title = ?, body = ?, enabled = ? WHERE id = ?");
            $stmt->execute([
                $input['title'] ?? '',
                $input['body'] ?? '',
                (int)($input['enabled'] ?? 1),
                $input['id'],
            ]);
            
            echo json_encode(['ok' => true]);
            exit;
            
        case 'test_notification':
            $input = json_decode(file_get_contents('php://input'), true);
            $templateId = $input['id'] ?? '';
            $target = $input['target'] ?? 'admin';
            
            $templates = getNotificationTemplates($pdo);
            if (!isset($templates[$templateId])) {
                echo json_encode(['ok' => false, 'error' => 'Template not found']);
                exit;
            }
            
            $tpl = $templates[$templateId];
            $body = str_replace(
                ['{time}', '{duration}', '{voltage}'],
                [date('H:i'), '1 год 23 хв', '227'],
                $tpl['body']
            );
            $body = str_replace('\n', "\n", $body);
            
            $botToken = $config['tg_bot_token'] ?? '';
            if (!$botToken) {
                echo json_encode(['ok' => false, 'error' => 'Bot token not configured']);
                exit;
            }
            
            $sent = 0;
            if ($target === 'admin') {
                $adminId = $config['tg_admin_id'] ?? '';
                if ($adminId) {
                    sendTelegramMessage($botToken, $adminId, $body, 'HTML');
                    $sent = 1;
                }
            } else {
                $subscribers = $pdo->query("SELECT chat_id FROM bot_subscribers WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($subscribers as $chatId) {
                    sendTelegramMessage($botToken, $chatId, $body, 'HTML');
                    $sent++;
                    usleep(50000);
                }
            }
            
            echo json_encode(['ok' => true, 'sent' => $sent]);
            exit;
            
        case 'parse_schedule':
            require_once __DIR__ . '/schedule_parser.php';
            
            $channelId = getDbSetting($pdo, 'schedule_channel_id', '');
            $queue = getDbSetting($pdo, 'schedule_queue', '4.1');
            
            if (!$channelId) {
                echo json_encode(['ok' => false, 'error' => 'Канал не налаштовано']);
                exit;
            }
            
            $botToken = $config['tg_bot_token'] ?? '';
            if (!$botToken) {
                echo json_encode(['ok' => false, 'error' => 'Bot token not configured']);
                exit;
            }
            
            // Get channel messages via Telegram API
            $result = parseChannelSchedule($pdo, $botToken, $channelId, $queue);
            
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
    }
}

// Helper for Telegram
function sendTelegramMessage(string $token, $chatId, string $text, string $parseMode = ''): bool {
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = ['chat_id' => $chatId, 'text' => $text];
    if ($parseMode) $data['parse_mode'] = $parseMode;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
    return true;
}

// ==================== POST ACTIONS ====================
if ($isAuthenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'force_check':
            dbSet($pdo, 'force_check', '1');
            $flash = '✅ Запит на примусову перевірку відправлено';
            $flashType = 'success';
            break;
            
        case 'broadcast':
            $message = trim($_POST['message'] ?? '');
            $target = $_POST['target'] ?? 'all_active';
            
            if ($message) {
                $botToken = $config['tg_bot_token'] ?? '';
                $sent = 0;
                
                if ($target === 'admins') {
                    $subscribers = $pdo->query("SELECT chat_id FROM bot_subscribers WHERE is_admin = 1")->fetchAll(PDO::FETCH_COLUMN);
                } elseif ($target === 'all') {
                    $subscribers = $pdo->query("SELECT chat_id FROM bot_subscribers")->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    $subscribers = $pdo->query("SELECT chat_id FROM bot_subscribers WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
                }
                
                foreach ($subscribers as $chatId) {
                    sendTelegramMessage($botToken, $chatId, $message, 'HTML');
                    $sent++;
                    usleep(50000);
                }
                
                $flash = "✅ Надіслано {$sent} повідомлень";
                $flashType = 'success';
            }
            break;
            
        case 'add_schedule':
            $date = $_POST['date'] ?? '';
            $timeStart = $_POST['time_start'] ?? '';
            $timeEnd = $_POST['time_end'] ?? '';
            $note = $_POST['note'] ?? '';
            
            if ($date && $timeStart && $timeEnd) {
                $stmt = $pdo->prepare("INSERT INTO schedule (date, time_start, time_end, note, source) VALUES (?, ?, ?, ?, 'manual')");
                $stmt->execute([$date, $timeStart, $timeEnd, $note]);
                $flash = '✅ Графік додано';
                $flashType = 'success';
            }
            break;
            
        case 'delete_schedule':
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $pdo->prepare("DELETE FROM schedule WHERE id = ?")->execute([$id]);
                $flash = '✅ Видалено';
                $flashType = 'success';
            }
            break;
            
        case 'toggle_admin':
            $chatId = $_POST['chat_id'] ?? '';
            if ($chatId) {
                $pdo->prepare("UPDATE bot_subscribers SET is_admin = NOT is_admin WHERE chat_id = ?")->execute([$chatId]);
                $flash = '✅ Роль змінено';
                $flashType = 'success';
            }
            break;
            
        case 'save_schedule_channel':
            $channelId = trim($_POST['channel_id'] ?? '');
            $queue = trim($_POST['queue'] ?? '4.1');
            $enabled = isset($_POST['enabled']) ? '1' : '0';
            
            dbSet($pdo, 'schedule_channel_id', $channelId);
            dbSet($pdo, 'schedule_queue', $queue);
            dbSet($pdo, 'schedule_parse_enabled', $enabled);
            
            $flash = '✅ Налаштування парсингу збережено';
            $flashType = 'success';
            break;
    }
    
    if ($flash) {
        $_SESSION['flash'] = $flash;
        $_SESSION['flash_type'] = $flashType;
        header("Location: admin.php?tab={$currentTab}");
        exit;
    }
}

// Get flash from session
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// ==================== GATHER DATA ====================
function getSubscriberStats(PDO $pdo): array {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM bot_subscribers")->fetchColumn();
    $active = (int)$pdo->query("SELECT COUNT(*) FROM bot_subscribers WHERE is_active = 1")->fetchColumn();
    $admins = (int)$pdo->query("SELECT COUNT(*) FROM bot_subscribers WHERE is_admin = 1")->fetchColumn();
    return ['total' => $total, 'active' => $active, 'admins' => $admins];
}

$state = loadLastState($pdo, $config);
$subscribers = $pdo->query("SELECT * FROM bot_subscribers ORDER BY chat_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$subscriberStats = getSubscriberStats($pdo);
$schedules = getUpcomingSchedule($pdo, 14);
$events = $pdo->query("SELECT * FROM events ORDER BY ts DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
$apiStats = getApiStats($pdo);
$notificationTemplates = getNotificationTemplates($pdo);

// Read schedule settings from DB
$scheduleChannelId = getDbSetting($pdo, 'schedule_channel_id', '');
$scheduleQueue = getDbSetting($pdo, 'schedule_queue', '4.1');
$scheduleParseEnabled = getDbSetting($pdo, 'schedule_parse_enabled', '0') === '1';

$projectName = $config['project_name'] ?? 'VoltPing';

// Calculate API limits
$checkInterval = (int)($config['check_interval_seconds'] ?? 60);
$requestsPerDay = $checkInterval > 0 ? (86400 / $checkInterval) : 1440;
$requestsPerMonth = $requestsPerDay * 30;
$apiLimit = 30000;
$limitPercent = min(100, round($requestsPerMonth / $apiLimit * 100));
$limitOk = $requestsPerMonth <= $apiLimit;

// ==================== HTML OUTPUT ====================
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($projectName) ?> - Адмін-панель</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚙️</text></svg>">
    <style>
        :root {
            --bg: #0f0f1a;
            --card: #1a1a2e;
            --border: #2d2d44;
            --text: #e8e8f0;
            --muted: #8888aa;
            --accent: #3b82f6;
            --success: #22c55e;
            --warning: #eab308;
            --danger: #ef4444;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
        }
        
        .container { max-width: 1200px; margin: 0 auto; padding: 1rem; }
        
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1.5rem;
        }
        
        header h1 { font-size: 1.5rem; display: flex; align-items: center; gap: 0.5rem; }
        
        .header-actions { display: flex; gap: 1rem; align-items: center; }
        .header-actions a { color: var(--muted); text-decoration: none; font-size: 0.9rem; }
        .header-actions a:hover { color: var(--text); }
        
        .flash {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-weight: 500;
        }
        .flash.success { background: rgba(34, 197, 94, 0.2); color: var(--success); }
        .flash.warning { background: rgba(234, 179, 8, 0.2); color: var(--warning); }
        .flash.danger { background: rgba(239, 68, 68, 0.2); color: var(--danger); }
        
        .tabs {
            display: flex;
            gap: 0.5rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1.5rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
        }
        
        .tab {
            background: transparent;
            border: none;
            color: var(--muted);
            padding: 0.75rem 1rem;
            cursor: pointer;
            font-size: 0.95rem;
            border-radius: 8px 8px 0 0;
            white-space: nowrap;
            text-decoration: none;
            display: inline-block;
        }
        
        .tab:hover { color: var(--text); background: var(--card); }
        .tab.active { color: var(--accent); background: var(--card); }
        
        .grid { display: grid; gap: 1rem; }
        .grid-2 { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
        .grid-3 { grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); }
        .grid-4 { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
        
        .card {
            background: var(--card);
            border-radius: 12px;
            padding: 1.25rem;
            border: 1px solid var(--border);
        }
        
        .card-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .stat-value { font-size: 2rem; font-weight: 700; }
        .stat-label { color: var(--muted); font-size: 0.9rem; }
        
        .status-on { color: var(--success); }
        .status-off { color: var(--danger); }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border); }
        th { color: var(--muted); font-weight: 500; font-size: 0.85rem; text-transform: uppercase; }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            text-decoration: none;
            transition: opacity 0.2s;
        }
        
        .btn:hover { opacity: 0.85; }
        .btn-primary { background: var(--accent); color: white; }
        .btn-success { background: var(--success); color: white; }
        .btn-warning { background: var(--warning); color: #000; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
        .btn-sm { padding: 0.3rem 0.6rem; font-size: 0.8rem; }
        
        input, textarea, select {
            width: 100%;
            padding: 0.75rem;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--bg);
            color: var(--text);
            font-size: 0.95rem;
        }
        
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: var(--accent);
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--muted);
            font-size: 0.9rem;
        }
        
        .form-group { margin-bottom: 1rem; }
        
        .badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-success { background: rgba(34, 197, 94, 0.2); color: var(--success); }
        .badge-danger { background: rgba(239, 68, 68, 0.2); color: var(--danger); }
        .badge-warning { background: rgba(234, 179, 8, 0.2); color: var(--warning); }
        .badge-info { background: rgba(59, 130, 246, 0.2); color: var(--accent); }
        
        .update-banner {
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid var(--accent);
            border-radius: 12px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: none;
            justify-content: space-between;
            align-items: center;
        }
        
        .update-banner.show { display: flex; }
        
        .toggle-switch {
            position: relative;
            width: 50px;
            height: 26px;
            display: inline-block;
        }
        
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: var(--border);
            transition: .3s;
            border-radius: 26px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider { background-color: var(--success); }
        input:checked + .toggle-slider:before { transform: translateX(24px); }
        
        .template-card {
            background: var(--bg);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .template-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        
        .template-title { font-weight: 600; }
        
        .hint {
            background: rgba(59, 130, 246, 0.1);
            border-left: 3px solid var(--accent);
            padding: 0.75rem 1rem;
            margin: 0.5rem 0;
            font-size: 0.9rem;
            color: var(--muted);
        }
        
        .progress-bar {
            height: 8px;
            background: var(--border);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.5rem;
        }
        
        .progress-bar .fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s;
        }
        
        /* Login */
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        
        .login-card {
            background: var(--card);
            border-radius: 16px;
            padding: 2rem;
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        
        .login-card h2 { margin-bottom: 1.5rem; }
        .login-error { color: var(--danger); margin-bottom: 1rem; }
        
        @media (max-width: 768px) {
            .grid-2, .grid-3, .grid-4 { grid-template-columns: 1fr; }
            .header-actions { flex-wrap: wrap; }
        }
    </style>
</head>
<body>

<?php if (!$isAuthenticated): ?>
    <div class="login-container">
        <div class="login-card">
            <h2>🔐 Вхід в адмін-панель</h2>
            <?php if (isset($loginError)): ?>
                <div class="login-error"><?= h($loginError) ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <input type="password" name="password" placeholder="Пароль" autofocus required>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">Увійти</button>
            </form>
        </div>
    </div>
<?php else: ?>

<div class="container">
    <header>
        <h1>⚙️ <?= h($projectName) ?></h1>
        <div class="header-actions">
            <span style="color: var(--muted);">v<?= VOLTPING_VERSION ?></span>
            <a href="../">🏠 На сайт</a>
            <a href="?logout">🚪 Вийти</a>
        </div>
    </header>
    
    <div class="update-banner" id="updateBanner">
        <div>
            <strong>🆕 Доступна нова версія!</strong>
            <span id="updateVersion"></span>
        </div>
        <a href="?tab=updates" class="btn btn-primary btn-sm">Переглянути</a>
    </div>
    
    <?php if ($flash): ?>
        <div class="flash <?= $flashType ?>"><?= $flash ?></div>
    <?php endif; ?>
    
    <div class="tabs">
        <a href="?tab=dashboard" class="tab <?= $currentTab === 'dashboard' ? 'active' : '' ?>">📊 Огляд</a>
        <a href="?tab=schedule" class="tab <?= $currentTab === 'schedule' ? 'active' : '' ?>">📅 Графік</a>
        <a href="?tab=messages" class="tab <?= $currentTab === 'messages' ? 'active' : '' ?>">✉️ Розсилка</a>
        <a href="?tab=notifications" class="tab <?= $currentTab === 'notifications' ? 'active' : '' ?>">🔔 Сповіщення</a>
        <a href="?tab=users" class="tab <?= $currentTab === 'users' ? 'active' : '' ?>">👥 Користувачі</a>
        <a href="?tab=settings" class="tab <?= $currentTab === 'settings' ? 'active' : '' ?>">⚙️ Налаштування</a>
        <a href="?tab=updates" class="tab <?= $currentTab === 'updates' ? 'active' : '' ?>">🔄 Оновлення</a>
    </div>
    
    <?php if ($currentTab === 'dashboard'): ?>
    <!-- Dashboard Tab -->
    <div class="grid grid-4">
        <div class="card">
            <div class="card-title">💡 Статус</div>
            <div class="stat-value <?= ($state['power_state'] ?? '') === 'LIGHT_ON' ? 'status-on' : 'status-off' ?>">
                <?= ($state['power_state'] ?? '') === 'LIGHT_ON' ? 'ВКЛ' : 'ВИКЛ' ?>
            </div>
        </div>
        <div class="card">
            <div class="card-title">⚡ Напруга</div>
            <div class="stat-value"><?= ($state['voltage'] ?? 0) ? round($state['voltage']) . 'V' : '—' ?></div>
        </div>
        <div class="card">
            <div class="card-title">👥 Підписників</div>
            <div class="stat-value"><?= $subscriberStats['active'] ?></div>
            <div class="stat-label">з <?= $subscriberStats['total'] ?> всього</div>
        </div>
        <div class="card">
            <div class="card-title">📡 API</div>
            <div class="stat-value"><?= $apiStats['success'] ?? 0 ?></div>
            <div class="stat-label">успішних запитів</div>
        </div>
    </div>
    
    <div class="grid grid-2" style="margin-top: 1rem;">
        <div class="card">
            <div class="card-title">📋 Останні події</div>
            <table>
                <thead><tr><th>Подія</th><th>Напруга</th><th>Час</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($events, 0, 10) as $e): ?>
                    <tr>
                        <td>
                            <?= $e['type'] === 'LIGHT_ON' ? '🟢 Увімкнення' : '🔴 Відключення' ?>
                        </td>
                        <td><?= $e['voltage'] ? round($e['voltage']) . 'V' : '—' ?></td>
                        <td><?= date('d.m H:i', $e['ts']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="card">
            <div class="card-title">🔧 Швидкі дії</div>
            <form method="POST" style="margin-bottom: 1rem;">
                <input type="hidden" name="action" value="force_check">
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    🔄 Примусова перевірка
                </button>
            </form>
            
            <div class="card-title" style="margin-top: 1rem;">📊 API ліміти</div>
            <p style="font-size: 0.9rem;">
                Інтервал: <strong><?= $checkInterval ?> сек</strong><br>
                Запитів/день: <strong><?= number_format($requestsPerDay, 0, ',', ' ') ?></strong><br>
                Запитів/місяць: <strong><?= number_format($requestsPerMonth, 0, ',', ' ') ?></strong>
            </p>
            <div class="progress-bar">
                <div class="fill" style="width: <?= $limitPercent ?>%; background: <?= $limitOk ? 'var(--success)' : 'var(--danger)' ?>;"></div>
            </div>
            <p style="font-size: 0.85rem; color: var(--muted); margin-top: 0.5rem;">
                <?= $limitPercent ?>% від ліміту (30 000)
                <?= $limitOk ? '✅' : '⚠️ Перевищення!' ?>
            </p>
        </div>
    </div>
    
    <?php elseif ($currentTab === 'schedule'): ?>
    <!-- Schedule Tab -->
    <div class="grid grid-2">
        <div class="card">
            <div class="card-title">➕ Додати графік</div>
            <form method="POST">
                <input type="hidden" name="action" value="add_schedule">
                <div class="form-group">
                    <label>Дата</label>
                    <input type="date" name="date" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="grid grid-2">
                    <div class="form-group">
                        <label>Початок</label>
                        <input type="time" name="time_start" required>
                    </div>
                    <div class="form-group">
                        <label>Кінець</label>
                        <input type="time" name="time_end" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Примітка</label>
                    <input type="text" name="note" placeholder="Необов'язково">
                </div>
                <button type="submit" class="btn btn-success">Додати</button>
            </form>
        </div>
        
        <div class="card">
            <div class="card-title">📅 Найближчі відключення</div>
            <?php if (empty($schedules)): ?>
                <p style="color: var(--muted);">Немає запланованих відключень</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Дата</th><th>Час</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($schedules as $s): ?>
                        <tr>
                            <td><?= h($s['date']) ?></td>
                            <td><?= h($s['time_start']) ?> - <?= h($s['time_end']) ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_schedule">
                                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">✕</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="card" style="margin-top: 1rem;">
        <div class="card-title">📡 Парсинг каналу</div>
        <form method="POST">
            <input type="hidden" name="action" value="save_schedule_channel">
            <div class="grid grid-3">
                <div class="form-group">
                    <label>Telegram канал (username)</label>
                    <input type="text" name="channel_id" placeholder="electronewsboryspil" 
                           value="<?= h($scheduleChannelId) ?>">
                </div>
                <div class="form-group">
                    <label>Група (наприклад: 4.1, 3.2)</label>
                    <input type="text" name="queue" placeholder="4.1" 
                           value="<?= h($scheduleQueue) ?>">
                </div>
                <div class="form-group">
                    <label style="visibility: hidden;">_</label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; visibility: visible;">
                        <input type="checkbox" name="enabled" <?= $scheduleParseEnabled ? 'checked' : '' ?>>
                        Автопарсинг
                    </label>
                </div>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" class="btn btn-primary">💾 Зберегти</button>
                <button type="button" class="btn btn-outline" onclick="parseScheduleNow()">🔄 Спарсити зараз</button>
            </div>
        </form>
        
        <div id="parseResult" style="margin-top: 1rem; display: none;">
            <div style="background: var(--bg); border-radius: 8px; padding: 1rem; font-family: monospace; font-size: 0.85rem;">
                <pre id="parseResultContent"></pre>
            </div>
        </div>
        
        <div class="hint" style="margin-top: 1rem;">
            <strong>Підтримувані формати:</strong><br>
            • Групи 4.1 і 4.2<br>
            • ⚫️08:00 відкл. (6.1)<br>
            • 🟢10:00 увімк.<br>
            Парсер читає останні 20 повідомлень і шукає вашу групу.
        </div>
    </div>
    
    <?php elseif ($currentTab === 'messages'): ?>
    <!-- Messages Tab -->
    <div class="grid grid-2">
        <div class="card">
            <div class="card-title">✉️ Масова розсилка</div>
            <form method="POST">
                <input type="hidden" name="action" value="broadcast">
                <div class="form-group">
                    <label>Повідомлення (підтримується HTML)</label>
                    <textarea name="message" rows="6" required placeholder="Введіть текст повідомлення...&#10;&#10;Підтримуються теги: <b>жирний</b>, <i>курсив</i>, <code>код</code>"></textarea>
                </div>
                <div class="form-group">
                    <label>Кому надіслати</label>
                    <select name="target">
                        <option value="all_active">📱 Тільки активним підписникам (<?= $subscriberStats['active'] ?>)</option>
                        <option value="admins">👑 Тільки адмінам (<?= $subscriberStats['admins'] ?>)</option>
                        <option value="all">📢 Всім (включаючи неактивних) (<?= $subscriberStats['total'] ?>)</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-warning" onclick="return confirm('Надіслати повідомлення?');">
                    📤 Надіслати
                </button>
            </form>
        </div>
        
        <div class="card">
            <div class="card-title">💡 Підказки</div>
            <div style="color: var(--muted); font-size: 0.9rem;">
                <p><strong>Доступні HTML теги:</strong></p>
                <ul style="margin: 0.5rem 0 1rem 1.5rem;">
                    <li><code>&lt;b&gt;жирний&lt;/b&gt;</code></li>
                    <li><code>&lt;i&gt;курсив&lt;/i&gt;</code></li>
                    <li><code>&lt;u&gt;підкреслений&lt;/u&gt;</code></li>
                    <li><code>&lt;code&gt;код&lt;/code&gt;</code></li>
                    <li><code>&lt;a href="..."&gt;посилання&lt;/a&gt;</code></li>
                </ul>
            </div>
        </div>
    </div>
    
    <?php elseif ($currentTab === 'notifications'): ?>
    <!-- Notifications Tab -->
    <div class="card">
        <div class="card-title">🔔 Шаблони сповіщень</div>
        <p style="color: var(--muted); margin-bottom: 1rem;">
            Налаштуйте тексти автоматичних сповіщень. Змінні: <code>{time}</code>, <code>{duration}</code>, <code>{voltage}</code>
        </p>
        
        <?php foreach ($notificationTemplates as $id => $tpl): ?>
        <div class="template-card" id="template-<?= $id ?>">
            <div class="template-header">
                <div>
                    <span class="template-title">
                        <?php
                        $icons = [
                            'power_on' => '🟢',
                            'power_off' => '🔴',
                            'voltage_warning' => '⚠️',
                            'voltage_critical' => '🚨',
                            'voltage_normal' => '✅',
                        ];
                        echo ($icons[$id] ?? '📌') . ' ' . h($tpl['title']);
                        ?>
                    </span>
                    <span class="badge <?= $tpl['enabled'] ? 'badge-success' : 'badge-danger' ?>" style="margin-left: 0.5rem;">
                        <?= $tpl['enabled'] ? 'Увімкнено' : 'Вимкнено' ?>
                    </span>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" <?= $tpl['enabled'] ? 'checked' : '' ?> onchange="toggleTemplate('<?= $id ?>', this.checked)">
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div class="form-group">
                <label>Текст повідомлення</label>
                <textarea id="body-<?= $id ?>" rows="3" style="font-family: monospace;"><?= h($tpl['body']) ?></textarea>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <button class="btn btn-primary btn-sm" onclick="saveTemplate('<?= $id ?>')">💾 Зберегти</button>
                <button class="btn btn-outline btn-sm" onclick="testNotification('<?= $id ?>', 'admin')">📤 Тест (адмін)</button>
                <button class="btn btn-warning btn-sm" onclick="testNotification('<?= $id ?>', 'all')">📢 Тест (всім)</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <?php elseif ($currentTab === 'users'): ?>
    <!-- Users Tab -->
    <div class="card">
        <div class="card-title">👥 Підписники (<?= count($subscribers) ?>)</div>
        <table>
            <thead>
                <tr>
                    <th>Ім'я</th>
                    <th>Username</th>
                    <th>Chat ID</th>
                    <th>Статус</th>
                    <th>Роль</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($subscribers as $s): ?>
                <tr>
                    <td><?= h((string)($s['first_name'] ?? '')) ?></td>
                    <td><?= $s['username'] ? '@' . h((string)$s['username']) : '—' ?></td>
                    <td><code><?= (int)$s['chat_id'] ?></code></td>
                    <td>
                        <?php if ($s['is_active']): ?>
                            <span class="badge badge-success">Активний</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Неактивний</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($s['is_admin'] ?? false): ?>
                            <span class="badge badge-info">👑 Адмін</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Користувач</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="toggle_admin">
                            <input type="hidden" name="chat_id" value="<?= (int)$s['chat_id'] ?>">
                            <button type="submit" class="btn btn-sm <?= ($s['is_admin'] ?? false) ? 'btn-danger' : 'btn-outline' ?>" 
                                    title="<?= ($s['is_admin'] ?? false) ? 'Зняти права адміна' : 'Призначити адміном' ?>">
                                <?= ($s['is_admin'] ?? false) ? '👑 ✕' : '👑' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php elseif ($currentTab === 'settings'): ?>
    <!-- Settings Tab -->
    <div class="grid grid-2">
        <div class="card">
            <div class="card-title">🏷️ Загальні</div>
            <div class="form-group">
                <label>Назва проекту</label>
                <input type="text" id="setting_project_name" value="<?= h($config['project_name'] ?? 'VoltPing') ?>">
            </div>
            <div class="form-group">
                <label>Заголовок каналу</label>
                <input type="text" id="setting_channel_title" value="<?= h($config['channel_base_title'] ?? '') ?>">
            </div>
        </div>
        
        <div class="card">
            <div class="card-title">⚡ Пороги напруги</div>
            <div class="grid grid-2">
                <div class="form-group">
                    <label>Низька (попередження)</label>
                    <input type="number" id="setting_v_warn_low" value="<?= $config['v_warn_low'] ?? 207 ?>">
                </div>
                <div class="form-group">
                    <label>Висока (попередження)</label>
                    <input type="number" id="setting_v_warn_high" value="<?= $config['v_warn_high'] ?? 253 ?>">
                </div>
                <div class="form-group">
                    <label>Критично низька</label>
                    <input type="number" id="setting_v_crit_low" value="<?= $config['v_crit_low'] ?? 190 ?>">
                </div>
                <div class="form-group">
                    <label>Критично висока</label>
                    <input type="number" id="setting_v_crit_high" value="<?= $config['v_crit_high'] ?? 260 ?>">
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-title">⏱️ Інтервали</div>
            <div class="form-group">
                <label>Інтервал перевірки (секунди)</label>
                <input type="number" id="setting_check_interval" value="<?= $config['check_interval_seconds'] ?? 60 ?>">
                <div class="hint">
                    Як часто опитувати пристрій. При 60 сек = 1440 запитів/день.<br>
                    Ліміт Tuya: 30 000 запитів/місяць.
                </div>
            </div>
            <div class="form-group">
                <label>Повторні сповіщення про напругу (хвилини)</label>
                <input type="number" id="setting_notify_repeat" value="<?= $config['notify_repeat_minutes'] ?? 60 ?>">
                <div class="hint">
                    Якщо напруга залишається критичною/низькою, повторне сповіщення
                    буде надіслано через цей інтервал. 0 = вимкнено.
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-title">🔑 Tuya API</div>
            <div class="form-group">
                <label>Режим</label>
                <select id="setting_tuya_mode">
                    <option value="cloud" <?= ($config['tuya_mode'] ?? 'cloud') === 'cloud' ? 'selected' : '' ?>>Cloud (через інтернет)</option>
                    <option value="local" <?= ($config['tuya_mode'] ?? '') === 'local' ? 'selected' : '' ?>>Local (локальна мережа)</option>
                    <option value="hybrid" <?= ($config['tuya_mode'] ?? '') === 'hybrid' ? 'selected' : '' ?>>Hybrid (спочатку local, потім cloud)</option>
                </select>
            </div>
        </div>
    </div>
    
    <div style="margin-top: 1rem;">
        <button class="btn btn-success" onclick="saveSettings()">💾 Зберегти налаштування</button>
    </div>
    
    <?php elseif ($currentTab === 'updates'): ?>
    <!-- Updates Tab -->
    <div class="card">
        <div class="card-title">🔄 Оновлення системи</div>
        <div class="grid grid-2">
            <div>
                <p><strong>Поточна версія:</strong> <?= VOLTPING_VERSION ?></p>
                <p id="latestVersionInfo"><strong>Остання версія:</strong> перевірка...</p>
            </div>
            <div style="text-align: right;">
                <button class="btn btn-outline" onclick="checkUpdate()">🔍 Перевірити оновлення</button>
                <button class="btn btn-primary" onclick="downloadUpdate()" id="btnDownloadUpdate" style="display: none;">
                    ⬇️ Завантажити оновлення
                </button>
            </div>
        </div>
        
        <div id="updateLog" style="margin-top: 1rem; display: none;">
            <div style="background: var(--bg); border-radius: 8px; padding: 1rem; font-family: monospace; font-size: 0.85rem;">
                <pre id="updateLogContent"></pre>
            </div>
        </div>
    </div>
    
    <div class="card" style="margin-top: 1rem;">
        <div class="card-title">📋 Що нового</div>
        <div id="releaseNotes" style="color: var(--muted);">
            Завантаження...
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', checkUpdate);

async function checkUpdate() {
    try {
        const res = await fetch('?api=check_update');
        const data = await res.json();
        
        const info = document.getElementById('latestVersionInfo');
        if (info) {
            if (data.latest) {
                info.innerHTML = `<strong>Остання версія:</strong> ${data.latest.version}`;
                
                if (data.has_update) {
                    document.getElementById('updateBanner').classList.add('show');
                    document.getElementById('updateVersion').textContent = data.latest.version;
                    const btn = document.getElementById('btnDownloadUpdate');
                    if (btn) btn.style.display = 'inline-flex';
                }
                
                const notes = document.getElementById('releaseNotes');
                if (notes && data.latest.notes) {
                    notes.innerHTML = data.latest.notes.replace(/\n/g, '<br>');
                }
            } else {
                info.innerHTML = '<strong>Остання версія:</strong> не вдалося перевірити';
            }
        }
    } catch (e) {
        console.error(e);
    }
}

async function downloadUpdate() {
    if (!confirm('Завантажити та встановити оновлення? Буде створено резервні копії файлів.')) return;
    
    const logEl = document.getElementById('updateLog');
    if (logEl) logEl.style.display = 'block';
    const log = document.getElementById('updateLogContent');
    if (log) log.textContent = 'Завантаження оновлення...\n';
    
    try {
        const res = await fetch('?api=download_update', { method: 'POST' });
        const data = await res.json();
        
        if (data.ok) {
            log.textContent += `✅ Оновлено до версії ${data.version}\n`;
            log.textContent += `Оновлені файли:\n`;
            data.updated.forEach(f => log.textContent += `  ✓ ${f}\n`);
            
            if (data.errors.length > 0) {
                log.textContent += `\nПомилки:\n`;
                data.errors.forEach(f => log.textContent += `  ✗ ${f}\n`);
            }
            
            log.textContent += '\n🔄 Перезавантажте сторінку для застосування змін.';
        } else {
            log.textContent += `❌ Помилка: ${data.error || 'Невідома помилка'}\n`;
        }
    } catch (e) {
        log.textContent += `❌ Помилка: ${e.message}\n`;
    }
}

async function saveSettings() {
    const settings = {
        PROJECT_NAME: document.getElementById('setting_project_name')?.value || '',
        CHANNEL_BASE_TITLE: document.getElementById('setting_channel_title')?.value || '',
        V_WARN_LOW: document.getElementById('setting_v_warn_low')?.value || '207',
        V_WARN_HIGH: document.getElementById('setting_v_warn_high')?.value || '253',
        V_CRIT_LOW: document.getElementById('setting_v_crit_low')?.value || '190',
        V_CRIT_HIGH: document.getElementById('setting_v_crit_high')?.value || '260',
        CHECK_INTERVAL_SECONDS: document.getElementById('setting_check_interval')?.value || '60',
        NOTIFY_REPEAT_MINUTES: document.getElementById('setting_notify_repeat')?.value || '60',
        TUYA_MODE: document.getElementById('setting_tuya_mode')?.value || 'cloud',
    };
    
    try {
        const res = await fetch('?api=save_settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(settings),
        });
        const data = await res.json();
        
        if (data.ok) {
            alert('✅ Налаштування збережено!');
        } else {
            alert('❌ Помилка: ' + (data.error || 'Невідома помилка'));
        }
    } catch (e) {
        alert('❌ Помилка: ' + e.message);
    }
}

async function saveTemplate(id) {
    const body = document.getElementById('body-' + id)?.value || '';
    const enabled = document.querySelector('#template-' + id + ' input[type=checkbox]')?.checked ? 1 : 0;
    
    try {
        const res = await fetch('?api=save_template', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, body, title: '', enabled }),
        });
        const data = await res.json();
        
        if (data.ok) {
            alert('✅ Шаблон збережено!');
            location.reload();
        } else {
            alert('❌ Помилка: ' + (data.error || 'Невідома помилка'));
        }
    } catch (e) {
        alert('❌ Помилка: ' + e.message);
    }
}

function toggleTemplate(id, enabled) {
    saveTemplate(id);
}

async function testNotification(id, target) {
    const msg = target === 'all' ? 'Надіслати тестове сповіщення ВСІМ підписникам?' : 'Надіслати тестове сповіщення адміну?';
    if (!confirm(msg)) return;
    
    try {
        const res = await fetch('?api=test_notification', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, target }),
        });
        const data = await res.json();
        
        if (data.ok) {
            alert(`✅ Надіслано: ${data.sent}`);
        } else {
            alert('❌ Помилка: ' + (data.error || 'Невідома помилка'));
        }
    } catch (e) {
        alert('❌ Помилка: ' + e.message);
    }
}

async function parseScheduleNow() {
    const result = document.getElementById('parseResult');
    const content = document.getElementById('parseResultContent');
    
    result.style.display = 'block';
    content.textContent = '🔄 Парсинг графіку...\n';
    
    try {
        const res = await fetch('?api=parse_schedule', { method: 'POST' });
        const data = await res.json();
        
        if (data.ok) {
            content.textContent = `✅ Знайдено записів: ${data.found}\n`;
            content.textContent += `📅 Дата: ${data.date || '?'}\n`;
            if (data.schedules && data.schedules.length > 0) {
                content.textContent += `\nГрафік:\n`;
                data.schedules.forEach(s => {
                    content.textContent += `  ${s.time_start} - ${s.time_end}\n`;
                });
            }
        } else {
            content.textContent = `❌ Помилка: ${data.error || 'Невідома помилка'}\n`;
        }
    } catch (e) {
        content.textContent = `❌ Помилка: ${e.message}\n`;
    }
}
</script>

<?php endif; ?>

</body>
</html>
