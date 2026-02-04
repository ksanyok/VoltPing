<?php
declare(strict_types=1);

/**
 * VoltPing - Admin Panel
 * Повноцінна веб-панель адміністрування
 * 
 * Features:
 * - Огляд стану системи
 * - Управління графіком відключень
 * - Масова розсилка повідомлень
 * - Управління користувачами (призначення адмінів)
 * - Налаштування сповіщень
 * - Оновлення системи
 * - Налаштування парсингу каналу
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

// ==================== VERSION ====================
define('VOLTPING_VERSION', '1.0.0');

function getLatestVersion(): ?array {
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
    
    if ($code === 200 && $response) {
        $data = json_decode($response, true);
        return [
            'version' => $data['tag_name'] ?? null,
            'url' => $data['html_url'] ?? null,
            'notes' => $data['body'] ?? null,
            'published' => $data['published_at'] ?? null,
        ];
    }
    return null;
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
            
        case 'force_check':
            dbSet($pdo, 'force_check', '1');
            echo json_encode(['ok' => true, 'message' => 'Запит на перевірку відправлено']);
            exit;
            
        case 'subscribers':
            $stmt = $pdo->query("SELECT * FROM bot_subscribers ORDER BY started_ts DESC");
            $subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['subscribers' => $subscribers], JSON_UNESCAPED_UNICODE);
            exit;
            
        case 'events':
            $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));
            $stmt = $pdo->query("SELECT * FROM events ORDER BY ts DESC LIMIT {$limit}");
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['events' => $events], JSON_UNESCAPED_UNICODE);
            exit;
            
        case 'check_update':
            $latest = getLatestVersion();
            $hasUpdate = $latest && version_compare($latest['version'] ?? '', VOLTPING_VERSION, '>');
            echo json_encode([
                'current' => VOLTPING_VERSION,
                'latest' => $latest,
                'has_update' => $hasUpdate,
            ], JSON_UNESCAPED_UNICODE);
            exit;
            
        case 'download_update':
            $latest = getLatestVersion();
            if (!$latest || !($latest['version'] ?? null)) {
                echo json_encode(['ok' => false, 'error' => 'Не вдалося отримати інформацію про оновлення']);
                exit;
            }
            
            $version = $latest['version'];
            $files = ['config.php', 'admin.php', 'bot.php', 'watch_power.php', 'schedule_parser.php'];
            $updated = [];
            $errors = [];
            
            foreach ($files as $file) {
                $url = "https://raw.githubusercontent.com/ksanyok/VoltPing/{$version}/src/{$file}";
                $ch = curl_init($url);
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
                    $backup = __DIR__ . "/{$file}.backup";
                    if (file_exists(__DIR__ . "/{$file}")) {
                        @copy(__DIR__ . "/{$file}", $backup);
                    }
                    if (@file_put_contents(__DIR__ . "/{$file}", $content)) {
                        $updated[] = $file;
                    } else {
                        $errors[] = $file;
                    }
                } else {
                    $errors[] = $file;
                }
            }
            
            // Update index.php in root
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
                @copy(dirname(__DIR__) . '/index.php', dirname(__DIR__) . '/index.php.backup');
                if (@file_put_contents(dirname(__DIR__) . '/index.php', $content)) {
                    $updated[] = 'index.php';
                }
            }
            
            echo json_encode([
                'ok' => count($errors) === 0,
                'updated' => $updated,
                'errors' => $errors,
                'version' => $version,
            ], JSON_UNESCAPED_UNICODE);
            exit;
            
        case 'set_admin':
            $chatId = trim($_POST['chat_id'] ?? '');
            $isAdmin = (bool)($_POST['is_admin'] ?? false);
            
            if ($chatId === '') {
                echo json_encode(['ok' => false, 'error' => 'Chat ID required']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE bot_subscribers SET is_admin = :isAdmin WHERE chat_id = :chatId");
            $stmt->execute([':isAdmin' => $isAdmin ? 1 : 0, ':chatId' => $chatId]);
            
            echo json_encode(['ok' => true]);
            exit;
            
        case 'save_settings':
            $settings = json_decode(file_get_contents('php://input'), true);
            if (!is_array($settings)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid settings']);
                exit;
            }
            
            $envPath = __DIR__ . '/.env';
            $envContent = file_exists($envPath) ? file_get_contents($envPath) : '';
            
            foreach ($settings as $key => $value) {
                $pattern = "/^" . preg_quote($key, '/') . "=.*/m";
                $replacement = "{$key}={$value}";
                
                if (preg_match($pattern, $envContent)) {
                    $envContent = preg_replace($pattern, $replacement, $envContent);
                } else {
                    $envContent .= "\n{$replacement}";
                }
            }
            
            if (@file_put_contents($envPath, trim($envContent) . "\n")) {
                echo json_encode(['ok' => true]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Failed to save']);
            }
            exit;
            
        case 'test_connection':
            require_once __DIR__ . '/tuya_client.php';
            
            $results = [];
            
            if (!empty($config['client_id']) && !empty($config['secret'])) {
                try {
                    $client = new TuyaClient(
                        $config['tuya_endpoint'],
                        $config['client_id'],
                        $config['secret'],
                        $config['tuya_token_cache']
                    );
                    $data = $client->getDeviceData($config['device_id']);
                    $results['cloud'] = [
                        'ok' => $data['online'] ?? false,
                        'voltage' => $data['voltage'] ?? null,
                        'latency' => $data['latency_ms'] ?? null,
                    ];
                } catch (Throwable $e) {
                    $results['cloud'] = ['ok' => false, 'error' => $e->getMessage()];
                }
            }
            
            echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
            exit;
    }
}

// ==================== POST ACTIONS ====================
if ($isAuthenticated && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'force_check':
            dbSet($pdo, 'force_check', '1');
            $flash = '✅ Запит на примусову перевірку відправлено';
            $flashType = 'success';
            break;
            
        case 'broadcast':
            $message = trim($_POST['message'] ?? '');
            $onlyActive = isset($_POST['only_active']);
            
            if ($message !== '') {
                $chatIds = $onlyActive 
                    ? getActiveBotSubscribers($pdo) 
                    : array_column(
                        $pdo->query("SELECT chat_id FROM bot_subscribers")->fetchAll(PDO::FETCH_ASSOC) ?: [],
                        'chat_id'
                    );
                
                $sent = 0;
                $failed = 0;
                
                foreach ($chatIds as $chatId) {
                    try {
                        tgRequest($config['tg_token'], 'sendMessage', [
                            'chat_id' => (string)$chatId,
                            'text' => $message,
                            'disable_web_page_preview' => true,
                        ]);
                        $sent++;
                        usleep(50000);
                    } catch (Throwable $e) {
                        $failed++;
                    }
                }
                
                $flash = "✅ Надіслано: {$sent}, помилки: {$failed}";
                $flashType = 'success';
            }
            break;
            
        case 'add_schedule':
            $date = $_POST['date'] ?? '';
            $timeStart = $_POST['time_start'] ?? '';
            $timeEnd = $_POST['time_end'] ?? '';
            $note = $_POST['note'] ?? '';
            
            if ($date && $timeStart && $timeEnd) {
                $stmt = $pdo->prepare("INSERT INTO schedule (date, time_start, time_end, note, created_ts) VALUES (:date, :ts, :te, :note, :now)");
                $stmt->execute([
                    ':date' => $date,
                    ':ts' => $timeStart,
                    ':te' => $timeEnd,
                    ':note' => $note,
                    ':now' => time(),
                ]);
                $flash = '✅ Графік додано';
                $flashType = 'success';
            }
            break;
            
        case 'delete_schedule':
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM schedule WHERE id = :id")->execute([':id' => $id]);
                $flash = '✅ Графік видалено';
                $flashType = 'success';
            }
            break;
            
        case 'toggle_admin':
            $chatId = $_POST['chat_id'] ?? '';
            if ($chatId) {
                $stmt = $pdo->prepare("SELECT is_admin FROM bot_subscribers WHERE chat_id = :chatId");
                $stmt->execute([':chatId' => $chatId]);
                $current = (bool)$stmt->fetchColumn();
                
                $pdo->prepare("UPDATE bot_subscribers SET is_admin = :isAdmin WHERE chat_id = :chatId")
                    ->execute([':isAdmin' => $current ? 0 : 1, ':chatId' => $chatId]);
                
                $flash = $current ? '✅ Права адміна знято' : '✅ Призначено адміном';
                $flashType = 'success';
            }
            break;
            
        case 'save_schedule_channel':
            $channelId = trim($_POST['channel_id'] ?? '');
            $queue = (int)($_POST['queue'] ?? 1);
            $enabled = isset($_POST['enabled']);
            
            $envPath = __DIR__ . '/.env';
            $env = file_exists($envPath) ? file_get_contents($envPath) : '';
            
            $updates = [
                'SCHEDULE_CHANNEL_ID' => $channelId,
                'SCHEDULE_QUEUE' => (string)$queue,
                'SCHEDULE_PARSE_ENABLED' => $enabled ? 'true' : 'false',
            ];
            
            foreach ($updates as $key => $value) {
                $pattern = "/^" . preg_quote($key, '/') . "=.*/m";
                $replacement = "{$key}={$value}";
                if (preg_match($pattern, $env)) {
                    $env = preg_replace($pattern, $replacement, $env);
                } else {
                    $env .= "\n{$replacement}";
                }
            }
            
            file_put_contents($envPath, trim($env) . "\n");
            $flash = '✅ Налаштування парсингу збережено';
            $flashType = 'success';
            break;
    }
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

$projectName = $config['project_name'] ?? 'VoltPing';

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
        }
        
        .tab:hover { color: var(--text); background: var(--card); }
        .tab.active { color: var(--accent); background: var(--card); }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
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
        }
        
        .update-banner.show { display: flex; justify-content: space-between; align-items: center; }
        
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
        <button class="btn btn-primary btn-sm" onclick="downloadUpdate()">Оновити</button>
    </div>
    
    <?php if ($flash): ?>
        <div class="flash <?= $flashType ?>"><?= $flash ?></div>
    <?php endif; ?>
    
    <div class="tabs">
        <button class="tab active" onclick="showTab('dashboard')">📊 Огляд</button>
        <button class="tab" onclick="showTab('schedule')">📅 Графік</button>
        <button class="tab" onclick="showTab('messages')">✉️ Розсилка</button>
        <button class="tab" onclick="showTab('users')">👥 Користувачі</button>
        <button class="tab" onclick="showTab('settings')">⚙️ Налаштування</button>
        <button class="tab" onclick="showTab('updates')">🔄 Оновлення</button>
    </div>
    
    <!-- Dashboard Tab -->
    <div id="tab-dashboard" class="tab-content active">
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
                    <thead><tr><th>Подія</th><th>Час</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($events, 0, 10) as $e): ?>
                        <tr>
                            <td>
                                <?= $e['event_type'] === 'LIGHT_ON' ? '🟢' : '🔴' ?>
                                <?= $e['event_type'] === 'LIGHT_ON' ? 'Увімкнення' : 'Відключення' ?>
                            </td>
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
                <a href="?api=test_connection" class="btn btn-outline" style="width: 100%; justify-content: center;">
                    📡 Тест підключення
                </a>
            </div>
        </div>
    </div>
    
    <!-- Schedule Tab -->
    <div id="tab-schedule" class="tab-content">
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
                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
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
                               value="<?= h($config['schedule_channel_id'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Група/черга</label>
                        <input type="number" name="queue" min="1" max="6" 
                               value="<?= (int)($config['schedule_queue'] ?? 1) ?>">
                    </div>
                    <div class="form-group">
                        <label style="visibility: hidden;">Увімкнено</label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; visibility: visible;">
                            <input type="checkbox" name="enabled" <?= ($config['schedule_parse_enabled'] ?? false) ? 'checked' : '' ?>>
                            Увімкнути автопарсинг
                        </label>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Зберегти</button>
            </form>
            <div style="margin-top: 1rem; padding: 1rem; background: var(--bg); border-radius: 8px; font-size: 0.85rem;">
                <strong>Приклад повідомлення для парсингу:</strong><br>
                <code style="color: var(--muted);">
                    📅 04 лютого 2026<br>
                    Група 4.1<br>
                    ⚫️08:00 відключення<br>
                    🟢15:00 увімкнення
                </code>
            </div>
        </div>
    </div>
    
    <!-- Messages Tab -->
    <div id="tab-messages" class="tab-content">
        <div class="card">
            <div class="card-title">✉️ Масова розсилка</div>
            <form method="POST">
                <input type="hidden" name="action" value="broadcast">
                <div class="form-group">
                    <label>Повідомлення</label>
                    <textarea name="message" rows="5" required placeholder="Введіть текст повідомлення..."></textarea>
                </div>
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 0.5rem;">
                        <input type="checkbox" name="only_active" checked>
                        Тільки активним підписникам
                    </label>
                </div>
                <button type="submit" class="btn btn-warning" onclick="return confirm('Надіслати повідомлення всім підписникам?');">
                    📤 Надіслати всім
                </button>
            </form>
        </div>
    </div>
    
    <!-- Users Tab -->
    <div id="tab-users" class="tab-content">
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
                        <th>Підписано</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($subscribers as $s): ?>
                    <tr>
                        <td><?= h($s['first_name'] ?? '') ?> <?= h($s['last_name'] ?? '') ?></td>
                        <td><?= $s['username'] ? '@' . h($s['username']) : '—' ?></td>
                        <td><code><?= h($s['chat_id']) ?></code></td>
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
                        <td><?= date('d.m.Y', $s['started_ts']) ?></td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="toggle_admin">
                                <input type="hidden" name="chat_id" value="<?= h($s['chat_id']) ?>">
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
    </div>
    
    <!-- Settings Tab -->
    <div id="tab-settings" class="tab-content">
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
                </div>
                <div class="form-group">
                    <label>Повторні сповіщення (хвилини)</label>
                    <input type="number" id="setting_notify_repeat" value="<?= $config['notify_repeat_minutes'] ?? 60 ?>">
                </div>
            </div>
            
            <div class="card">
                <div class="card-title">🔑 Tuya API</div>
                <div class="form-group">
                    <label>Режим</label>
                    <select id="setting_tuya_mode">
                        <option value="cloud" <?= ($config['tuya_mode'] ?? 'cloud') === 'cloud' ? 'selected' : '' ?>>Cloud</option>
                        <option value="local" <?= ($config['tuya_mode'] ?? '') === 'local' ? 'selected' : '' ?>>Local</option>
                        <option value="hybrid" <?= ($config['tuya_mode'] ?? '') === 'hybrid' ? 'selected' : '' ?>>Hybrid</option>
                    </select>
                </div>
            </div>
        </div>
        
        <div style="margin-top: 1rem;">
            <button class="btn btn-success" onclick="saveSettings()">💾 Зберегти налаштування</button>
        </div>
    </div>
    
    <!-- Updates Tab -->
    <div id="tab-updates" class="tab-content">
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
    </div>
</div>

<script>
function showTab(tabName) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    
    document.querySelector(`[onclick="showTab('${tabName}')"]`).classList.add('active');
    document.getElementById(`tab-${tabName}`).classList.add('active');
}

async function checkUpdate() {
    try {
        const res = await fetch('?api=check_update');
        const data = await res.json();
        
        if (data.latest) {
            document.getElementById('latestVersionInfo').innerHTML = 
                `<strong>Остання версія:</strong> ${data.latest.version}`;
            
            if (data.has_update) {
                document.getElementById('updateBanner').classList.add('show');
                document.getElementById('updateVersion').textContent = data.latest.version;
                document.getElementById('btnDownloadUpdate').style.display = 'inline-flex';
            }
            
            if (data.latest.notes) {
                document.getElementById('releaseNotes').innerHTML = 
                    data.latest.notes.replace(/\n/g, '<br>');
            }
        } else {
            document.getElementById('latestVersionInfo').innerHTML = 
                '<strong>Остання версія:</strong> не вдалося перевірити';
        }
    } catch (e) {
        document.getElementById('latestVersionInfo').innerHTML = 
            '<strong>Остання версія:</strong> помилка';
    }
}

async function downloadUpdate() {
    if (!confirm('Завантажити та встановити оновлення? Буде створено резервні копії файлів.')) return;
    
    document.getElementById('updateLog').style.display = 'block';
    const log = document.getElementById('updateLogContent');
    log.textContent = 'Завантаження оновлення...\n';
    
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
        PROJECT_NAME: document.getElementById('setting_project_name').value,
        CHANNEL_BASE_TITLE: document.getElementById('setting_channel_title').value,
        V_WARN_LOW: document.getElementById('setting_v_warn_low').value,
        V_WARN_HIGH: document.getElementById('setting_v_warn_high').value,
        V_CRIT_LOW: document.getElementById('setting_v_crit_low').value,
        V_CRIT_HIGH: document.getElementById('setting_v_crit_high').value,
        CHECK_INTERVAL_SECONDS: document.getElementById('setting_check_interval').value,
        NOTIFY_REPEAT_MINUTES: document.getElementById('setting_notify_repeat').value,
        TUYA_MODE: document.getElementById('setting_tuya_mode').value,
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

// Check for updates on load
checkUpdate();
</script>

<?php endif; ?>

</body>
</html>
