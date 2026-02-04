<?php
declare(strict_types=1);

/**
 * VoltPing Configuration
 * Універсальна система моніторингу електропостачання
 * 
 * Конфігурація завантажується з .env файлу
 */

// ==================== VERSION ====================
define('VOLTPING_VERSION', '1.3.0');

// ==================== ENV LOADER ====================

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
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

function envInt(string $key, int $default): int {
    $v = env($key);
    return $v !== null ? (int)$v : $default;
}

function envFloat(string $key, float $default): float {
    $v = env($key);
    return $v !== null ? (float)$v : $default;
}

function envBool(string $key, bool $default): bool {
    $v = env($key);
    if ($v === null) return $default;
    return in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
}

// Load .env file (try both src/ and parent directory)
if (file_exists(__DIR__ . '/.env')) {
    loadEnvFile(__DIR__ . '/.env');
} else {
    loadEnvFile(dirname(__DIR__) . '/.env');
}

// Timezone
date_default_timezone_set((string)env('TIMEZONE', 'Europe/Kyiv'));

// ==================== CONFIGURATION ====================

function getConfig(): array {
    return [
        // ═══════════════════════════════════════════════════════════════
        // PROJECT
        // ═══════════════════════════════════════════════════════════════
        'project_name' => (string)env('PROJECT_NAME', 'VoltPing'),
        'channel_base_title' => (string)env('CHANNEL_BASE_TITLE', 'Моніторинг — Світло ⚡ Напруга'),
        'channel_updates_enabled' => envBool('CHANNEL_UPDATES_ENABLED', true),
        
        // ═══════════════════════════════════════════════════════════════
        // ADMIN
        // ═══════════════════════════════════════════════════════════════
        'admin_password' => (string)env('ADMIN_PASSWORD', ''),
        
        // ═══════════════════════════════════════════════════════════════
        // TUYA CLOUD API
        // ═══════════════════════════════════════════════════════════════
        'tuya_endpoint' => (string)env('TUYA_ENDPOINT', 'https://openapi.tuyaeu.com'),
        'tuya_client_id' => (string)env('TUYA_ACCESS_ID', ''),
        'tuya_secret' => (string)env('TUYA_ACCESS_SECRET', ''),
        'tuya_device_id' => (string)env('TUYA_DEVICE_ID', ''),
        'tuya_token_cache' => __DIR__ . '/tuya_token.json',
        
        // Backward compatibility aliases
        'endpoint' => (string)env('TUYA_ENDPOINT', 'https://openapi.tuyaeu.com'),
        'client_id' => (string)env('TUYA_ACCESS_ID', ''),
        'secret' => (string)env('TUYA_ACCESS_SECRET', ''),
        'device_id' => (string)env('TUYA_DEVICE_ID', ''),
        
        // ═══════════════════════════════════════════════════════════════
        // TUYA LOCAL CONNECTION
        // ═══════════════════════════════════════════════════════════════
        'tuya_mode' => (string)env('TUYA_MODE', 'cloud'), // cloud | local | hybrid
        'tuya_local_key' => (string)env('TUYA_LOCAL_KEY', ''),
        'tuya_local_ip' => (string)env('TUYA_LOCAL_IP', ''),
        'tuya_public_ip' => (string)env('TUYA_PUBLIC_IP', ''),
        'tuya_local_version' => envFloat('TUYA_LOCAL_VERSION', 3.5),
        'tuya_local_port' => envInt('TUYA_LOCAL_PORT', 6668),
        
        // ═══════════════════════════════════════════════════════════════
        // TELEGRAM
        // ═══════════════════════════════════════════════════════════════
        'tg_token' => (string)env('TG_BOT_TOKEN', ''),
        'tg_chat_id' => (string)env('TG_CHAT_ID', ''),
        'tg_admin_id' => (string)env('TG_ADMIN_ID', ''),
        'tg_bot_link' => (string)env('TG_BOT_LINK', ''),
        'tg_bot_username' => (string)env('TG_BOT_USERNAME', ''),
        
        // ═══════════════════════════════════════════════════════════════
        // MONITORING INTERVALS
        // ═══════════════════════════════════════════════════════════════
        'check_interval_seconds' => envInt('CHECK_INTERVAL_SECONDS', 60),
        'notify_repeat_minutes' => envInt('NOTIFY_REPEAT_MINUTES', 60),
        'offline_confirm_polls' => envInt('OFFLINE_CONFIRM_POLLS', 2),
        'burst_mode_duration' => envInt('BURST_MODE_DURATION', 300),
        'burst_mode_interval' => envInt('BURST_MODE_INTERVAL', 5),
        
        // Dashboard updates
        'bot_dashboard_update_seconds' => max(30, envInt('BOT_DASHBOARD_UPDATE_SECONDS', 60)),
        'bot_dashboard_max_edits' => max(1, envInt('BOT_DASHBOARD_MAX_EDITS', 10)),
        'bot_dashboard_edit_sleep_ms' => max(0, envInt('BOT_DASHBOARD_EDIT_SLEEP_MS', 100)),
        'bot_dashboard_max_age_hours' => max(1, envInt('BOT_DASHBOARD_MAX_AGE_HOURS', 40)),
        'bot_dashboard_auto_recreate' => envBool('BOT_DASHBOARD_AUTO_RECREATE', true),
        'description_update_seconds' => max(60, envInt('DESCRIPTION_UPDATE_SECONDS', 120)),
        'title_update_seconds' => max(60, envInt('TITLE_UPDATE_SECONDS', 300)),
        
        // ═══════════════════════════════════════════════════════════════
        // VOLTAGE THRESHOLDS
        // ═══════════════════════════════════════════════════════════════
        'voltage_on_threshold' => envFloat('VOLTAGE_ON_THRESHOLD', 50.0),
        'v_warn_low' => envFloat('V_WARN_LOW', 207.0),
        'v_warn_high' => envFloat('V_WARN_HIGH', 253.0),
        'v_crit_low' => envFloat('V_CRIT_LOW', 190.0),
        'v_crit_high' => envFloat('V_CRIT_HIGH', 260.0),
        
        // ═══════════════════════════════════════════════════════════════
        // SCHEDULE PARSING
        // ═══════════════════════════════════════════════════════════════
        'schedule_channel_id' => (string)env('SCHEDULE_CHANNEL_ID', ''),
        'schedule_queue' => envInt('SCHEDULE_QUEUE', 1),
        'schedule_parse_enabled' => envBool('SCHEDULE_PARSE_ENABLED', false),
        
        // ═══════════════════════════════════════════════════════════════
        // STORAGE
        // ═══════════════════════════════════════════════════════════════
        'db_file' => __DIR__ . '/voltping.sqlite',
        'token_cache_file' => __DIR__ . '/tuya_token.json',
        'lock_file' => __DIR__ . '/voltping.lock',
        'bot_stats_file' => __DIR__ . '/logs/bot_stats.txt',
    ];
}

// ==================== DATABASE ====================

function getDatabase(array $config): PDO {
    $pdo = new PDO('sqlite:' . $config['db_file']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=5000');
    ensureDb($pdo);
    return $pdo;
}

function ensureDb(PDO $pdo): void {
    // State storage (key-value)
    $pdo->exec("CREATE TABLE IF NOT EXISTS state (
        k TEXT PRIMARY KEY, 
        v TEXT NOT NULL
    )");
    
    // Events log
    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts INTEGER NOT NULL,
        type TEXT NOT NULL,
        state TEXT NULL,
        voltage REAL NULL,
        note TEXT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_ts ON events(ts)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_type ON events(type)");
    
    // Bot subscribers
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_subscribers (
        chat_id INTEGER PRIMARY KEY,
        is_active INTEGER NOT NULL DEFAULT 1,
        username TEXT NULL,
        first_name TEXT NULL,
        last_name TEXT NULL,
        started_ts INTEGER NULL,
        updated_ts INTEGER NULL,
        dashboard_msg_id INTEGER NULL,
        dashboard_updated_ts INTEGER NULL,
        dashboard_msg_ts INTEGER NULL,
        notify_power INTEGER DEFAULT 1,
        notify_voltage INTEGER DEFAULT 1
    )");
    
    // Bot messages tracking
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_messages (
        chat_id INTEGER NOT NULL,
        action TEXT NOT NULL,
        message_id INTEGER NOT NULL,
        updated_ts INTEGER NULL,
        PRIMARY KEY(chat_id, action)
    )");
    
    // Power schedule
    $pdo->exec("CREATE TABLE IF NOT EXISTS power_schedule (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT NOT NULL,
        queue INTEGER NOT NULL,
        start_time TEXT NOT NULL,
        end_time TEXT NOT NULL,
        is_active INTEGER DEFAULT 1,
        notified_start INTEGER DEFAULT 0,
        notified_end INTEGER DEFAULT 0,
        created_ts INTEGER NOT NULL,
        source TEXT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_schedule_date ON power_schedule(date)");
    
    // API request logs
    $pdo->exec("CREATE TABLE IF NOT EXISTS request_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts INTEGER NOT NULL,
        request_type TEXT NOT NULL,
        response_code INTEGER NULL,
        voltage REAL NULL,
        power_state TEXT NULL,
        device_online INTEGER NULL,
        latency_ms INTEGER NULL,
        error_msg TEXT NULL,
        note TEXT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_request_logs_ts ON request_logs(ts)");
    
    // Notification templates
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_templates (
        id TEXT PRIMARY KEY,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        enabled INTEGER DEFAULT 1
    )");
    
    // Schedule table (simplified)
    $pdo->exec("CREATE TABLE IF NOT EXISTS schedule (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT NOT NULL,
        time_start TEXT NOT NULL,
        time_end TEXT NOT NULL,
        note TEXT NULL,
        created_ts INTEGER NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_schedule_date ON schedule(date)");
    
    // Voltage log for charts
    $pdo->exec("CREATE TABLE IF NOT EXISTS voltage_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts INTEGER NOT NULL,
        voltage REAL NOT NULL,
        power_state TEXT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_voltage_log_ts ON voltage_log(ts)");
    
    // Ensure columns exist (for upgrades)
    ensureColumn($pdo, 'bot_subscribers', 'dashboard_msg_id', 'INTEGER NULL');
    ensureColumn($pdo, 'bot_subscribers', 'dashboard_updated_ts', 'INTEGER NULL');
    ensureColumn($pdo, 'bot_subscribers', 'dashboard_msg_ts', 'INTEGER NULL');
    ensureColumn($pdo, 'bot_subscribers', 'notify_power', 'INTEGER DEFAULT 1');
    ensureColumn($pdo, 'bot_subscribers', 'notify_voltage', 'INTEGER DEFAULT 1');
    ensureColumn($pdo, 'bot_subscribers', 'is_admin', 'INTEGER DEFAULT 0');
    
    // Initialize default templates
    initDefaultTemplates($pdo);
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void {
    try {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    } catch (PDOException $e) {
        // Column already exists - ignore
    }
}

function initDefaultTemplates(PDO $pdo): void {
    $templates = [
        ['power_on', '✅ Світло з\'явилося!', "🕒 {time}\n⚡ Напруга: {voltage}V"],
        ['power_off', '❌ Світло зникло!', "🕒 {time}\n⏱ Було увімкнено: {duration}"],
        ['voltage_normal', '✅ Напруга нормалізувалась', "🕒 {time}\n⚡ Напруга: {voltage}V"],
        ['voltage_low', '⚠️ Занижена напруга', "🕒 {time}\n⚡ Напруга: {voltage}V"],
        ['voltage_high', '⚠️ Завищена напруга', "🕒 {time}\n⚡ Напруга: {voltage}V"],
        ['voltage_crit_low', '🆘 Критично низька напруга!', "🕒 {time}\n⚡ Напруга: {voltage}V\n\n⚠️ Рекомендація: вимкніть чутливу техніку"],
        ['voltage_crit_high', '🆘 Критично висока напруга!', "🕒 {time}\n⚡ Напруга: {voltage}V\n\n⚠️ Рекомендація: вимкніть чутливу техніку"],
    ];
    
    $st = $pdo->prepare("INSERT OR IGNORE INTO notification_templates (id, title, body) VALUES (?, ?, ?)");
    foreach ($templates as $t) {
        $st->execute($t);
    }
}

// ==================== STATE HELPERS ====================

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

function dbGetInt(PDO $pdo, string $key, int $default = 0): int {
    return (int)dbGet($pdo, $key, (string)$default);
}

function dbGetFloat(PDO $pdo, string $key, float $default = 0.0): float {
    return (float)dbGet($pdo, $key, (string)$default);
}

// ==================== LOGGING ====================

function logEvent(PDO $pdo, int $ts, string $type, ?string $state = null, ?float $voltage = null, ?string $note = null): void {
    $st = $pdo->prepare("INSERT INTO events(ts, type, state, voltage, note) VALUES(:ts, :type, :state, :voltage, :note)");
    $st->execute([
        ':ts' => $ts,
        ':type' => $type,
        ':state' => $state,
        ':voltage' => $voltage,
        ':note' => $note,
    ]);
}

function logRequest(PDO $pdo, int $ts, string $type, ?int $code, ?float $voltage, ?string $powerState, ?bool $online, ?int $latency, ?string $error, ?string $note = null): void {
    $st = $pdo->prepare("INSERT INTO request_logs(ts, request_type, response_code, voltage, power_state, device_online, latency_ms, error_msg, note) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $st->execute([$ts, $type, $code, $voltage, $powerState, $online ? 1 : 0, $latency, $error, $note]);
}

// ==================== VOLTAGE STATUS ====================

function voltageStatus(float $voltage, array $cfg): string {
    if ($voltage <= 0) return 'ZERO';
    if ($voltage < $cfg['voltage_on_threshold']) return 'OFF';
    if ($voltage < $cfg['v_crit_low']) return 'CRIT_LOW';
    if ($voltage < $cfg['v_warn_low']) return 'LOW';
    if ($voltage > $cfg['v_crit_high']) return 'CRIT_HIGH';
    if ($voltage > $cfg['v_warn_high']) return 'HIGH';
    return 'NORMAL';
}

function voltageStatusEmoji(string $status): string {
    return match ($status) {
        'NORMAL' => '✅',
        'LOW', 'HIGH' => '⚠️',
        'CRIT_LOW', 'CRIT_HIGH' => '🆘',
        'ZERO', 'OFF' => '❌',
        default => '❓',
    };
}

function voltageStatusText(string $status): string {
    return match ($status) {
        'NORMAL' => 'Норма',
        'LOW' => 'Занижена',
        'HIGH' => 'Завищена',
        'CRIT_LOW' => 'Критично низька',
        'CRIT_HIGH' => 'Критично висока',
        'ZERO', 'OFF' => 'Немає',
        default => 'Невідомо',
    };
}

// ==================== LOAD STATE ====================

function loadLastState(PDO $pdo, array $cfg): array {
    return [
        'power_state' => dbGet($pdo, 'last_power_state', 'UNKNOWN'),
        'power_ts' => dbGetInt($pdo, 'last_power_change_ts', 0),
        'voltage' => dbGetFloat($pdo, 'last_voltage', 0.0),
        'voltage_state' => dbGet($pdo, 'last_voltage_state', 'UNKNOWN'),
        'voltage_ts' => dbGetInt($pdo, 'last_voltage_change_ts', 0),
        'check_ts' => dbGetInt($pdo, 'last_check_ts', 0),
        'device_online' => dbGet($pdo, 'device_online', '1') === '1',
        'connection_mode' => dbGet($pdo, 'connection_mode', 'cloud'),
    ];
}

// ==================== TELEGRAM ====================

function tgRequest(string $token, string $method, array $payload): array {
    $url = "https://api.telegram.org/bot{$token}/{$method}";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response === false) {
        throw new RuntimeException("Telegram cURL error: {$error}");
    }
    
    $json = json_decode($response, true);
    if (!is_array($json)) {
        throw new RuntimeException("Invalid Telegram response: {$response}");
    }
    
    if (!($json['ok'] ?? false)) {
        throw new RuntimeException('Telegram API error: ' . ($json['description'] ?? $response));
    }
    
    return $json;
}

function tgErrorDescription(Throwable $e): string {
    $msg = $e->getMessage();
    if (preg_match('/description["\s:]+([^"]+)/i', $msg, $m)) {
        return $m[1];
    }
    return $msg;
}

function isTooManyRequestsError(Throwable $e): bool {
    $msg = strtolower($e->getMessage());
    return str_contains($msg, 'too many requests') || str_contains($msg, 'error_code":429');
}

// ==================== HELPERS ====================

/**
 * Check if chat is admin (config or database)
 */
function isAdminChat(array $cfg, int $chatId, ?PDO $pdo = null): bool {
    // Check config first
    $adminId = trim((string)($cfg['tg_admin_id'] ?? ''));
    if ($adminId !== '') {
        $adminIds = array_map('trim', explode(',', $adminId));
        if (in_array((string)$chatId, $adminIds, true)) {
            return true;
        }
    }
    
    // Check database
    if ($pdo !== null) {
        try {
            $stmt = $pdo->prepare("SELECT is_admin FROM bot_subscribers WHERE chat_id = :chatId");
            $stmt->execute([':chatId' => $chatId]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            // Ignore
        }
    }
    
    return false;
}

function getBaseTitle(array $cfg): string {
    $base = trim((string)($cfg['channel_base_title'] ?? ''));
    if ($base === '') $base = trim((string)($cfg['project_name'] ?? 'VoltPing')) . ' — Світло ⚡ Напруга';
    return $base;
}

function resolveBotLink(PDO $pdo, array $cfg): ?string {
    $link = trim((string)($cfg['tg_bot_link'] ?? ''));
    if ($link !== '') return $link;
    
    $username = trim((string)($cfg['tg_bot_username'] ?? ''));
    if ($username === '') {
        $username = trim((string)dbGet($pdo, 'tg_bot_username', ''));
        if ($username === '' && ($cfg['tg_token'] ?? '') !== '') {
            try {
                $res = tgRequest((string)$cfg['tg_token'], 'getMe', []);
                $username = (string)($res['result']['username'] ?? '');
                if ($username !== '') dbSet($pdo, 'tg_bot_username', $username);
            } catch (Throwable $e) {}
        }
    }
    
    $username = ltrim($username, '@');
    if ($username === '') return null;
    return 'https://t.me/' . $username;
}

function formatDuration(int $seconds): string {
    if ($seconds < 60) return "{$seconds} сек";
    if ($seconds < 3600) {
        $m = (int)floor($seconds / 60);
        $s = $seconds % 60;
        return $s > 0 ? "{$m} хв {$s} сек" : "{$m} хв";
    }
    $h = (int)floor($seconds / 3600);
    $m = (int)floor(($seconds % 3600) / 60);
    return $m > 0 ? "{$h} год {$m} хв" : "{$h} год";
}

function getNotifyConfig(PDO $pdo, array $cfg): array {
    return [
        'power' => dbGet($pdo, 'global_notify_power', '1') === '1',
        'voltage' => dbGet($pdo, 'global_notify_voltage', '1') === '1',
    ];
}

function buildNotifyStatusLine(array $notifyCfg): string {
    $power = $notifyCfg['power'] ?? true;
    $volt = $notifyCfg['voltage'] ?? true;
    
    if ($power && $volt) return '🔔 Сповіщення: світло + напруга';
    if ($power) return '🔔 Сповіщення: тільки світло';
    if ($volt) return '🔔 Сповіщення: тільки напруга';
    return '🔕 Сповіщення: вимкнені';
}

// ==================== SCHEDULE ====================

function getUpcomingSchedule(PDO $pdo, int $days = 7): array {
    $today = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime("+{$days} days"));
    
    $stmt = $pdo->prepare("
        SELECT * FROM schedule 
        WHERE date >= :today AND date <= :end 
        ORDER BY date ASC, time_start ASC
    ");
    $stmt->execute([':today' => $today, ':end' => $endDate]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function isScheduledOutageNow(PDO $pdo): ?array {
    $today = date('Y-m-d');
    $now = date('H:i');
    
    $stmt = $pdo->prepare("
        SELECT * FROM schedule 
        WHERE date = :today AND time_start <= :now AND time_end >= :now 
        LIMIT 1
    ");
    $stmt->execute([':today' => $today, ':now' => $now]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $schedule ?: null;
}

// ==================== STATS ====================

function getTodayStats(PDO $pdo): array {
    $dayStart = strtotime('today 00:00:00');
    
    $stmt = $pdo->prepare("SELECT type, ts FROM events WHERE ts >= :start ORDER BY ts ASC");
    $stmt->execute([':start' => $dayStart]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $onSeconds = 0;
    $offSeconds = 0;
    $prevTs = $dayStart;
    $prevState = 'LIGHT_ON';
    
    foreach ($events as $e) {
        $duration = (int)$e['ts'] - $prevTs;
        if ($prevState === 'LIGHT_ON') {
            $onSeconds += $duration;
        } else {
            $offSeconds += $duration;
        }
        $prevState = $e['type'];
        $prevTs = (int)$e['ts'];
    }
    
    $duration = time() - $prevTs;
    if ($prevState === 'LIGHT_ON') {
        $onSeconds += $duration;
    } else {
        $offSeconds += $duration;
    }
    
    return ['on' => $onSeconds, 'off' => $offSeconds];
}

function fmtDur(int $seconds): string {
    if ($seconds < 60) return "{$seconds} сек";
    if ($seconds < 3600) {
        $m = (int)floor($seconds / 60);
        $s = $seconds % 60;
        return $s > 0 ? "{$m} хв {$s} сек" : "{$m} хв";
    }
    if ($seconds < 86400) {
        $h = (int)floor($seconds / 3600);
        $m = (int)floor(($seconds % 3600) / 60);
        return $m > 0 ? "{$h} год {$m} хв" : "{$h} год";
    }
    $d = (int)floor($seconds / 86400);
    $h = (int)floor(($seconds % 86400) / 3600);
    return $h > 0 ? "{$d} д {$h} год" : "{$d} д";
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ==================== API STATS ====================

function getApiStats(PDO $pdo): array {
    $now = time();
    $dayStart = strtotime('today 00:00:00');
    $monthStart = strtotime('first day of this month 00:00:00');
    
    $dayCount = (int)$pdo->query("SELECT COUNT(*) FROM request_logs WHERE ts >= {$dayStart}")->fetchColumn();
    $monthCount = (int)$pdo->query("SELECT COUNT(*) FROM request_logs WHERE ts >= {$monthStart}")->fetchColumn();
    $success = (int)$pdo->query("SELECT COUNT(*) FROM request_logs WHERE response_code = 200")->fetchColumn();
    
    return [
        'today' => $dayCount,
        'month' => $monthCount,
        'success' => $success,
    ];
}
