<?php
declare(strict_types=1);

/**
 * VoltPing - Power Monitoring Script
 * Оптимізований скрипт моніторингу електропостачання
 * 
 * Особливості:
 * - Адаптивний інтервал опитування
 * - Підтримка local/cloud/hybrid режимів
 * - Підтримка графіків відключень
 * - Автовизначення Local Key
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tuya_client.php';
require_once __DIR__ . '/tuya_local_client.php';
require_once __DIR__ . '/schedule_parser.php';

header('Content-Type: application/json; charset=utf-8');

$config = getConfig();
$mode = strtolower($config['tuya_mode'] ?? 'cloud');

// ==================== VALIDATE CONFIG ====================

if ($mode === 'local') {
    foreach (['device_id', 'tuya_local_key'] as $k) {
        if (($config[$k] ?? '') === '') {
            echo json_encode(['ok' => false, 'error' => "Missing TUYA LOCAL config: {$k}"], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }
    }
} else {
    foreach (['client_id', 'secret', 'device_id'] as $k) {
        if (($config[$k] ?? '') === '') {
            echo json_encode(['ok' => false, 'error' => "Missing TUYA CLOUD config: {$k}"], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }
    }
}

$pdo = getDatabase($config);

// ==================== LOCK FILE ====================

$lockFp = null;
if (!empty($config['lock_file'])) {
    $lockFp = @fopen((string)$config['lock_file'], 'c+');
    if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
        echo json_encode(['ok' => false, 'error' => 'busy'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit(0);
    }
}

// ==================== HELPERS ====================

function sendMessageToChats(PDO $pdo, array $cfg, array $chatIds, string $text): void {
    if (($cfg['tg_token'] ?? '') === '' || $chatIds === []) return;
    
    $channelId = trim((string)($cfg['tg_chat_id'] ?? ''));
    
    foreach ($chatIds as $chatId) {
        try {
            $payload = [
                'chat_id' => (string)$chatId,
                'text' => $text,
                'disable_web_page_preview' => true,
            ];
            
            // Add keyboard for personal chats (not channels)
            if ($channelId === '' || (string)$chatId !== $channelId) {
                $payload['reply_markup'] = botKeyboardMarkup($cfg, (string)$chatId);
            }
            
            tgRequest((string)$cfg['tg_token'], 'sendMessage', $payload);
        } catch (Throwable $e) {
            // Log but don't fail
        }
    }
}

function botKeyboardMarkup(array $cfg, string $chatId): array {
    $isAdmin = isAdminChat($cfg, (int)$chatId);
    $rows = [
        [['text' => '📌 Поточна інформація']],
        [['text' => '🔌 Перевірити світло'], ['text' => '⚡ Перевірити напругу']],
        [['text' => '📡 Стан зараз'], ['text' => '📊 Статистика']],
        [['text' => '🧾 Історія'], ['text' => '⚙️ Налаштування']],
    ];
    if ($isAdmin) {
        $rows[] = [['text' => '👥 Користувачі (кількість)']];
    }
    return [
        'keyboard' => $rows,
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
        'is_persistent' => true,
        'input_field_placeholder' => 'Оберіть дію…',
    ];
}

function getNotifyChatIds(PDO $pdo, array $cfg): array {
    $ids = [];
    
    // Add channel
    $channelId = trim((string)($cfg['tg_chat_id'] ?? ''));
    if ($channelId !== '') {
        $ids[] = $channelId;
    }
    
    // Add active subscribers
    $st = $pdo->query("SELECT chat_id FROM bot_subscribers WHERE is_active = 1");
    if ($st !== false) {
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $ids[] = (string)$row['chat_id'];
        }
    }
    
    return array_values(array_unique($ids));
}

function getActiveBotSubscribers(PDO $pdo): array {
    $st = $pdo->query("SELECT chat_id FROM bot_subscribers WHERE is_active = 1");
    if ($st === false) return [];
    
    $rows = $st->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    $ids = [];
    foreach ($rows as $id) {
        if ($id === null || $id === '') continue;
        $ids[] = (string)$id;
    }
    return array_values(array_unique($ids));
}

function voltageAdvice(string $vState): string {
    $liftWarn = "🚫 Під час падіння напруги не користуйтеся ліфтом.";
    
    return match ($vState) {
        'CRIT_LOW', 'LOW' =>
            "⚠️ Рекомендація: бажано вимкнути чутливу техніку (ПК/ноутбук без UPS, NAS, ТВ, зарядні, мережеве обладнання без стабілізатора).\n{$liftWarn}",
        'CRIT_HIGH', 'HIGH' =>
            "⚠️ Рекомендація: бажано вимкнути чутливу техніку до стабілізації напруги.",
        default => '',
    };
}

function funMessagePowerOn(): string {
    $messages = [
        "🎉 Ура! Електрика повернулась!",
        "💡 Хай буде світло!",
        "⚡ Електрика знову з нами!",
        "🔌 Живлення відновлено!",
        "✨ І сказав Бог: нехай буде світло!",
        "🌟 Світло повернулось в наші оселі!",
        "💪 Електрика знову працює на повну!",
    ];
    return $messages[array_rand($messages)];
}

function funMessagePowerOff(): string {
    $messages = [
        "🕯 Час діставати свічки...",
        "🔋 Переходимо на автономне живлення!",
        "📱 Заряджайте телефони завчасно!",
        "🌙 Романтичний вечір при свічках?",
        "⏰ Тимчасові незручності...",
    ];
    return $messages[array_rand($messages)];
}

// ==================== AUTO-DETECT LOCAL KEY ====================

function autoDetectLocalKey(PDO $pdo, array $cfg): ?string {
    if (!empty($cfg['tuya_local_key'])) {
        return $cfg['tuya_local_key'];
    }
    
    // Try to get from database
    $savedKey = dbGet($pdo, 'tuya_local_key');
    if ($savedKey) {
        return $savedKey;
    }
    
    // Try to get from Cloud API
    if (!empty($cfg['client_id']) && !empty($cfg['secret']) && !empty($cfg['device_id'])) {
        try {
            $client = new TuyaClient(
                $cfg['tuya_endpoint'],
                $cfg['client_id'],
                $cfg['secret'],
                $cfg['tuya_token_cache']
            );
            
            $localKey = $client->getLocalKey($cfg['device_id']);
            
            if ($localKey) {
                dbSet($pdo, 'tuya_local_key', $localKey);
                dbSet($pdo, 'local_key_detected_ts', (string)time());
                return $localKey;
            }
        } catch (Throwable $e) {
            // Ignore
        }
    }
    
    return null;
}

// ==================== POLL DEVICE ====================

function pollDevice(array $cfg, PDO $pdo): array {
    $mode = strtolower($cfg['tuya_mode'] ?? 'cloud');
    $result = null;
    $connectionMethod = $mode;
    
    // Auto-detect local key if needed
    if ($mode === 'local' || $mode === 'hybrid') {
        $localKey = autoDetectLocalKey($pdo, $cfg);
        if ($localKey) {
            $cfg['tuya_local_key'] = $localKey;
        }
    }
    
    if ($mode === 'local' || $mode === 'hybrid') {
        // Try Python tinytuya first (more reliable)
        $result = pollLocalTuyaPython($cfg);
        
        // Fallback to PHP implementation
        if (!$result || !($result['online'] ?? false)) {
            $host = $cfg['tuya_public_ip'] ?? $cfg['tuya_local_ip'] ?? '';
            $deviceId = $cfg['device_id'] ?? $cfg['tuya_device_id'] ?? '';
            $localKey = $cfg['tuya_local_key'] ?? '';
            $version = (string)($cfg['tuya_local_version'] ?? '3.5');
            $port = (int)($cfg['tuya_local_port'] ?? 6668);
            
            if ($host && $localKey && $deviceId) {
                $client = new TuyaLocalClient($deviceId, $localKey, $host, $port, $version);
                $result = $client->getStatus();
            }
        }
        
        if ($result && ($result['online'] ?? false)) {
            $connectionMethod = 'local';
        }
    }
    
    // Fallback to Cloud API
    if (!$result || (!($result['online'] ?? false) && $mode !== 'local')) {
        if (!empty($cfg['client_id']) && !empty($cfg['secret'])) {
            $client = new TuyaClient(
                $cfg['tuya_endpoint'],
                $cfg['client_id'],
                $cfg['secret'],
                $cfg['tuya_token_cache']
            );
            
            $result = $client->getDeviceData($cfg['device_id']);
            $connectionMethod = 'cloud';
        }
    }
    
    if (!$result) {
        return [
            'online' => false,
            'voltage' => null,
            'error' => 'No connection method available',
            'method' => 'none',
        ];
    }
    
    $result['method'] = $connectionMethod;
    return $result;
}

// ==================== MAIN LOGIC ====================

$now = time();

// Check for force check
$forceCheck = false;
if (isset($argv)) {
    foreach ($argv as $arg) {
        if (str_contains($arg, 'force=1')) {
            $forceCheck = true;
        }
    }
}
if (isset($_GET['force'])) {
    $forceCheck = true;
}

// Check if force_check flag is set in DB
if (dbGet($pdo, 'force_check', '0') === '1') {
    $forceCheck = true;
    dbSet($pdo, 'force_check', '0');
}

// Load previous state
$lastState = loadLastState($pdo, $config);
$lastPowerState = $lastState['power_state'];
$lastVoltState = $lastState['voltage_state'];
$lastCheckTs = $lastState['check_ts'];
$lastPowerTs = $lastState['power_ts'];
$lastVoltTs = $lastState['voltage_ts'];

// Poll device
$result = pollDevice($config, $pdo);

$online = $result['online'] ?? false;
$voltage = $result['voltage'] ?? null;
$power = $result['power'] ?? null;
$current = $result['current'] ?? null;
$latency = $result['latency_ms'] ?? 0;
$method = $result['method'] ?? 'unknown';
$error = $result['error'] ?? null;

// Log request
logRequest($pdo, $now, $method, $online ? 200 : 500, $voltage, $online ? 'ON' : 'OFF', $online, $latency, $error);

// Determine power state
$powerState = 'UNKNOWN';
if ($online) {
    if ($voltage !== null && $voltage >= $config['voltage_on_threshold']) {
        $powerState = 'ON';
    } elseif ($voltage !== null && $voltage < $config['voltage_on_threshold']) {
        $powerState = 'OFF';
    }
}

// Determine voltage state
$voltState = 'UNKNOWN';
if ($voltage !== null) {
    $voltState = voltageStatus($voltage, $config);
}

// Update state in DB
dbSet($pdo, 'last_check_ts', (string)$now);
dbSet($pdo, 'device_online', $online ? '1' : '0');
dbSet($pdo, 'connection_mode', $method);

if ($voltage !== null) {
    dbSet($pdo, 'last_voltage', (string)$voltage);
    dbSet($pdo, 'last_voltage_state', $voltState);
}

// ==================== POWER CHANGE NOTIFICATION ====================

$powerChanged = ($powerState !== 'UNKNOWN' && $powerState !== $lastPowerState && $lastPowerState !== 'UNKNOWN');

if ($powerChanged) {
    dbSet($pdo, 'last_power_state', $powerState);
    dbSet($pdo, 'last_power_change_ts', (string)$now);
    
    logEvent($pdo, $now, 'POWER', $powerState, $voltage, null);
    
    $chatIds = getNotifyChatIds($pdo, $config);
    $title = getBaseTitle($config);
    
    if ($powerState === 'ON') {
        $duration = $lastPowerTs > 0 ? formatDuration($now - $lastPowerTs) : '';
        $durationLine = $duration ? "\n⏱ Було вимкнено: {$duration}" : '';
        
        $msg = "✅ {$title}\n\n"
            . funMessagePowerOn() . "\n\n"
            . "🕒 " . date('Y-m-d H:i:s') . "\n"
            . ($voltage !== null ? "⚡ Напруга: {$voltage}V" : '')
            . $durationLine;
        
        sendMessageToChats($pdo, $config, $chatIds, $msg);
    } else {
        $duration = $lastPowerTs > 0 ? formatDuration($now - $lastPowerTs) : '';
        $durationLine = $duration ? "\n⏱ Було увімкнено: {$duration}" : '';
        
        $msg = "❌ {$title}\n\n"
            . funMessagePowerOff() . "\n\n"
            . "🕒 " . date('Y-m-d H:i:s')
            . $durationLine;
        
        sendMessageToChats($pdo, $config, $chatIds, $msg);
    }
}

// ==================== VOLTAGE CHANGE NOTIFICATION ====================

$voltageChanged = ($voltState !== 'UNKNOWN' && $voltState !== $lastVoltState && $lastVoltState !== 'UNKNOWN');

// Check if need to repeat notification
$notifyRepeatMinutes = (int)($config['notify_repeat_minutes'] ?? 60);
$lastVNotifyTs = dbGetInt($pdo, 'last_voltage_notify_ts', 0);
$needRepeat = false;

if (in_array($voltState, ['LOW', 'HIGH', 'CRIT_LOW', 'CRIT_HIGH'], true)) {
    $needRepeat = ($now - $lastVNotifyTs) >= ($notifyRepeatMinutes * 60);
}

if ($voltageChanged || $needRepeat) {
    dbSet($pdo, 'last_voltage_state', $voltState);
    dbSet($pdo, 'last_voltage_change_ts', (string)$now);
    dbSet($pdo, 'last_voltage_notify_ts', (string)$now);
    
    logEvent($pdo, $now, 'VOLTAGE', $voltState, $voltage, null);
    
    // Don't spam UNKNOWN or ZERO
    if (!in_array($voltState, ['UNKNOWN', 'ZERO'], true)) {
        $chatIds = getNotifyChatIds($pdo, $config);
        $title = getBaseTitle($config);
        
        $emoji = voltageStatusEmoji($voltState);
        $statusText = voltageStatusText($voltState);
        
        $msg = "{$emoji} {$title}\n\n"
            . "⚡ Напруга: {$voltage}V ({$statusText})\n"
            . "🕒 " . date('Y-m-d H:i:s');
        
        $advice = voltageAdvice($voltState);
        if ($advice) {
            $msg .= "\n\n" . $advice;
        }
        
        sendMessageToChats($pdo, $config, $chatIds, $msg);
    }
}

// ==================== SCHEDULE NOTIFICATIONS ====================

$scheduleEnabled = $config['schedule_parse_enabled'] ?? false;
if ($scheduleEnabled) {
    checkScheduleNotifications($pdo, $config, $now);
}

// ==================== UPDATE CHANNEL TITLE ====================

if ($config['channel_updates_enabled'] ?? true) {
    $lastTitleUpdate = dbGetInt($pdo, 'last_title_update_ts', 0);
    $titleInterval = (int)($config['title_update_seconds'] ?? 300);
    
    if ($now - $lastTitleUpdate >= $titleInterval && $voltage !== null) {
        $baseTitle = getBaseTitle($config);
        $emoji = voltageStatusEmoji($voltState);
        $newTitle = "{$baseTitle} {$emoji} {$voltage}V";
        
        if (strlen($newTitle) > 128) {
            $newTitle = mb_substr($newTitle, 0, 128);
        }
        
        $channelId = trim((string)($config['tg_chat_id'] ?? ''));
        if ($channelId !== '' && ($config['tg_token'] ?? '') !== '') {
            try {
                tgRequest((string)$config['tg_token'], 'setChatTitle', [
                    'chat_id' => $channelId,
                    'title' => $newTitle,
                ]);
                dbSet($pdo, 'last_title_update_ts', (string)$now);
            } catch (Throwable $e) {
                // Ignore title update errors
            }
        }
    }
}

// ==================== RESPONSE ====================

$response = [
    'ok' => true,
    'ts' => $now,
    'datetime' => date('Y-m-d H:i:s'),
    'device' => [
        'online' => $online,
        'voltage' => $voltage,
        'power' => $power,
        'current' => $current,
    ],
    'state' => [
        'power' => $powerState,
        'voltage' => $voltState,
    ],
    'connection' => [
        'method' => $method,
        'latency_ms' => $latency,
    ],
    'changes' => [
        'power' => $powerChanged,
        'voltage' => $voltageChanged,
    ],
];

if ($error) {
    $response['error'] = $error;
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// Release lock
if ($lockFp) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
