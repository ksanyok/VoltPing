<?php
declare(strict_types=1);

/**
 * VoltPing - Telegram Bot Webhook Handler
 * Обробник повідомлень від Telegram бота
 * 
 * Підтримує:
 * - Інтерактивні перевірки статусу
 * - Перегляд статистики та історії
 * - Налаштування сповіщень
 * - Адмін-команди
 */

require_once __DIR__ . '/config.php';

$config = getConfig();

if (($config['tg_token'] ?? '') === '') {
    http_response_code(500);
    echo 'Missing TG_BOT_TOKEN';
    exit;
}

$pdo = getDatabase($config);

// ==================== SUBSCRIBER FUNCTIONS ====================

function getSubscriberStats(PDO $pdo): array {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM bot_subscribers")->fetchColumn();
    $active = (int)$pdo->query("SELECT COUNT(*) FROM bot_subscribers WHERE is_active = 1")->fetchColumn();
    return ['activated' => $total, 'active' => $active];
}

function getSubscriberList(PDO $pdo): array {
    $st = $pdo->query("SELECT chat_id, username, first_name, last_name, is_active, started_ts, updated_ts FROM bot_subscribers ORDER BY started_ts ASC, chat_id ASC");
    if ($st === false) return [];
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function upsertSubscriber(PDO $pdo, array $chat, array $from, array $cfg, bool $activate): void {
    $chatId = (int)($chat['id'] ?? 0);
    if ($chatId === 0) return;
    
    $username = (string)($chat['username'] ?? $from['username'] ?? '');
    $firstName = (string)($chat['first_name'] ?? $from['first_name'] ?? '');
    $lastName = (string)($chat['last_name'] ?? $from['last_name'] ?? '');
    $now = time();
    
    $st = $pdo->prepare("INSERT INTO bot_subscribers(chat_id, username, first_name, last_name, is_active, started_ts, updated_ts)
        VALUES(:chat_id, :username, :first_name, :last_name, :is_active, :started_ts, :updated_ts)
        ON CONFLICT(chat_id) DO UPDATE SET 
            username = COALESCE(excluded.username, bot_subscribers.username),
            first_name = COALESCE(excluded.first_name, bot_subscribers.first_name),
            last_name = COALESCE(excluded.last_name, bot_subscribers.last_name),
            is_active = CASE WHEN :activate = 1 THEN 1 ELSE bot_subscribers.is_active END,
            updated_ts = excluded.updated_ts");
    
    $st->execute([
        ':chat_id' => $chatId,
        ':username' => $username ?: null,
        ':first_name' => $firstName ?: null,
        ':last_name' => $lastName ?: null,
        ':is_active' => $activate ? 1 : 0,
        ':started_ts' => $now,
        ':updated_ts' => $now,
        ':activate' => $activate ? 1 : 0,
    ]);
}

function setSubscriberActive(PDO $pdo, int $chatId, bool $active, array $cfg): void {
    $st = $pdo->prepare("UPDATE bot_subscribers SET is_active = :active, updated_ts = :ts WHERE chat_id = :chat_id");
    $st->execute([':active' => $active ? 1 : 0, ':ts' => time(), ':chat_id' => $chatId]);
}

// ==================== DASHBOARD ====================

function getSubscriberDashboardId(PDO $pdo, int $chatId): ?int {
    $st = $pdo->prepare("SELECT dashboard_msg_id FROM bot_subscribers WHERE chat_id = :id");
    $st->execute([':id' => $chatId]);
    $v = $st->fetchColumn();
    return $v ? (int)$v : null;
}

function setSubscriberDashboardId(PDO $pdo, int $chatId, ?int $msgId): void {
    $st = $pdo->prepare("UPDATE bot_subscribers SET dashboard_msg_id = :msg_id, dashboard_updated_ts = :ts, dashboard_msg_ts = :ts WHERE chat_id = :chat_id");
    $st->execute([':msg_id' => $msgId, ':ts' => time(), ':chat_id' => $chatId]);
}

function buildBotDashboardText(array $state, array $stats, string $title, string $notifyLine, PDO $pdo): string {
    $powerEmoji = match ($state['power_state'] ?? 'UNKNOWN') {
        'ON' => '✅',
        'OFF' => '❌',
        default => '❓',
    };
    $powerText = match ($state['power_state'] ?? 'UNKNOWN') {
        'ON' => 'Є',
        'OFF' => 'Немає',
        default => 'Невідомо',
    };
    
    $voltage = $state['voltage'] ?? 0;
    $voltState = $state['voltage_state'] ?? 'UNKNOWN';
    $voltEmoji = voltageStatusEmoji($voltState);
    $voltText = voltageStatusText($voltState);
    
    $checkTs = $state['check_ts'] ?? 0;
    $checkTime = $checkTs > 0 ? date('H:i:s', $checkTs) : '—';
    
    $lastPowerTs = $state['power_ts'] ?? 0;
    $powerDuration = $lastPowerTs > 0 ? formatDuration(time() - $lastPowerTs) : '—';
    
    $method = $state['connection_mode'] ?? 'cloud';
    $methodEmoji = $method === 'local' ? '🏠' : '☁️';
    
    return "📊 {$title}\n"
        . "━━━━━━━━━━━━━━━━━━━━━\n\n"
        . "{$powerEmoji} Світло: {$powerText}\n"
        . "{$voltEmoji} Напруга: {$voltage}V ({$voltText})\n\n"
        . "⏱ Оновлено: {$checkTime}\n"
        . "⏳ Стан триває: {$powerDuration}\n\n"
        . "{$methodEmoji} Підключення: {$method}\n"
        . $notifyLine;
}

function updateSubscriberDashboard(PDO $pdo, array $cfg, int $chatId, bool $forceNew = false): void {
    $state = loadLastState($pdo, $cfg);
    $stats = getSubscriberStats($pdo);
    $title = getBaseTitle($cfg);
    $notifyLine = buildNotifyStatusLine(getNotifyConfig($pdo, $cfg));
    $text = buildBotDashboardText($state, $stats, $title, $notifyLine, $pdo);
    $msgId = getSubscriberDashboardId($pdo, $chatId);
    
    if ($forceNew && $msgId !== null) {
        try {
            tgRequest((string)$cfg['tg_token'], 'deleteMessage', [
                'chat_id' => $chatId,
                'message_id' => $msgId,
            ]);
        } catch (Throwable $e) {}
        $msgId = null;
    }
    
    if ($msgId !== null) {
        try {
            tgRequest((string)$cfg['tg_token'], 'editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $msgId,
                'text' => $text,
                'disable_web_page_preview' => true,
            ]);
            setSubscriberDashboardId($pdo, $chatId, $msgId);
            return;
        } catch (Throwable $e) {
            // Message not found, create new
        }
    }
    
    // Send new dashboard
    try {
        $res = tgRequest((string)$cfg['tg_token'], 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
            'reply_markup' => botKeyboard($cfg, $chatId),
        ]);
        
        $newMsgId = (int)($res['result']['message_id'] ?? 0);
        if ($newMsgId > 0) {
            setSubscriberDashboardId($pdo, $chatId, $newMsgId);
            
            // Try to pin
            try {
                tgRequest((string)$cfg['tg_token'], 'pinChatMessage', [
                    'chat_id' => $chatId,
                    'message_id' => $newMsgId,
                    'disable_notification' => true,
                ]);
            } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}
}

// ==================== KEYBOARDS ====================

function botKeyboard(array $cfg, int $chatId, ?PDO $pdo = null): array {
    $isAdmin = isAdminChat($cfg, $chatId, $pdo);
    $rows = [
        [['text' => '📌 Поточна інформація']],
        [['text' => '🔌 Перевірити світло'], ['text' => '⚡ Перевірити напругу']],
        [['text' => '📊 Статистика'], ['text' => '📅 Графік відключень']],
        [['text' => '🧾 Історія'], ['text' => '⚙️ Налаштування']],
    ];
    if ($isAdmin) {
        $rows[] = [['text' => '👥 Користувачі'], ['text' => '🔧 Адмін-панель']];
    }
    return [
        'keyboard' => $rows,
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
        'is_persistent' => true,
        'input_field_placeholder' => 'Оберіть дію…',
    ];
}

function settingsKeyboard(): array {
    return [
        'keyboard' => [
            [['text' => '🔔 Увімкнути сповіщення'], ['text' => '🔕 Вимкнути сповіщення']],
            [['text' => 'ℹ️ Про бота']],
            [['text' => '◀️ Назад']],
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
        'is_persistent' => true,
        'input_field_placeholder' => 'Оберіть налаштування…',
    ];
}

function historyKeyboard(): array {
    return [
        'keyboard' => [
            [['text' => '📅 Сьогодні'], ['text' => '📅 Вчора']],
            [['text' => '📅 Тиждень'], ['text' => '📅 Місяць']],
            [['text' => '◀️ Назад']],
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
        'is_persistent' => true,
        'input_field_placeholder' => 'Оберіть період…',
    ];
}

// ==================== MESSAGE HELPERS ====================

function sendBotMessage(array $cfg, int $chatId, string $text, bool $withKeyboard = true, ?string $parseMode = null): int {
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true,
    ];
    if ($parseMode !== null) {
        $payload['parse_mode'] = $parseMode;
    }
    if ($withKeyboard) {
        $payload['reply_markup'] = botKeyboard($cfg, $chatId);
    }
    $res = tgRequest((string)$cfg['tg_token'], 'sendMessage', $payload);
    return (int)($res['result']['message_id'] ?? 0);
}

function sendBotMessageWithKeyboard(array $cfg, int $chatId, string $text, array $keyboard, ?string $parseMode = null): int {
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true,
        'reply_markup' => $keyboard,
    ];
    if ($parseMode !== null) {
        $payload['parse_mode'] = $parseMode;
    }
    $res = tgRequest((string)$cfg['tg_token'], 'sendMessage', $payload);
    return (int)($res['result']['message_id'] ?? 0);
}

// ==================== TEXT BUILDERS ====================

function buildWelcomeText(PDO $pdo, array $cfg): string {
    $title = getBaseTitle($cfg);
    $botLink = resolveBotLink($pdo, $cfg);
    $botLine = $botLink !== null ? "\n🤖 {$botLink}" : "";
    $notifyLine = buildNotifyStatusLine(getNotifyConfig($pdo, $cfg));
    
    return "👋 Вітаємо у боті моніторингу!\n\n"
        . "🏘 Об'єкт: {$title}\n\n"
        . "Цей бот допоможе вам:\n"
        . "⚡ Отримувати миттєві сповіщення\n"
        . "📊 Дивитися статистику за день\n\n"
        . "{$notifyLine}\n\n"
        . "Оберіть дію в меню нижче. ⚡"
        . $botLine;
}

function buildAboutText(PDO $pdo, array $cfg): string {
    $title = getBaseTitle($cfg);
    $botLink = resolveBotLink($pdo, $cfg);
    $botLine = $botLink !== null ? "\n🤖 {$botLink}" : "";
    $notifyLine = buildNotifyStatusLine(getNotifyConfig($pdo, $cfg));
    $apiStats = getApiStats($pdo);
    
    return "ℹ️ Про бота\n\n"
        . "🏘 Об'єкт: {$title}\n"
        . "📊 API за сьогодні: {$apiStats['today']}\n"
        . "📊 API за місяць: {$apiStats['month']}/30000\n\n"
        . "Система моніторингу електропостачання VoltPing"
        . $botLine;
}

function buildSettingsText(PDO $pdo, array $cfg): string {
    $notifyCfg = getNotifyConfig($pdo, $cfg);
    $warnLow = (int)round((float)($cfg['v_warn_low'] ?? 207.0));
    $warnHigh = (int)round((float)($cfg['v_warn_high'] ?? 253.0));
    $critLow = (int)round((float)($cfg['v_crit_low'] ?? 190.0));
    $critHigh = (int)round((float)($cfg['v_crit_high'] ?? 260.0));
    
    return "⚙️ Налаштування сповіщень\n\n"
        . buildNotifyStatusLine($notifyCfg) . "\n\n"
        . "📊 Пороги напруги:\n"
        . "  ⚠️ Низька: < {$warnLow}V\n"
        . "  ⚠️ Висока: > {$warnHigh}V\n"
        . "  🆘 Критично низька: < {$critLow}V\n"
        . "  🆘 Критично висока: > {$critHigh}V\n\n"
        . "Використовуйте кнопки нижче для керування сповіщеннями.";
}

function buildStatusText(PDO $pdo, array $cfg): string {
    $state = loadLastState($pdo, $cfg);
    $title = getBaseTitle($cfg);
    
    $powerEmoji = match ($state['power_state'] ?? 'UNKNOWN') {
        'ON' => '✅',
        'OFF' => '❌',
        default => '❓',
    };
    $powerText = match ($state['power_state'] ?? 'UNKNOWN') {
        'ON' => 'Є',
        'OFF' => 'Немає',
        default => 'Невідомо',
    };
    
    $voltage = $state['voltage'] ?? 0;
    $voltState = $state['voltage_state'] ?? 'UNKNOWN';
    $voltEmoji = voltageStatusEmoji($voltState);
    $voltText = voltageStatusText($voltState);
    
    $checkTs = $state['check_ts'] ?? 0;
    $checkTime = $checkTs > 0 ? date('Y-m-d H:i:s', $checkTs) : '—';
    
    $lastPowerTs = $state['power_ts'] ?? 0;
    $powerDuration = $lastPowerTs > 0 ? formatDuration(time() - $lastPowerTs) : '—';
    
    $method = $state['connection_mode'] ?? 'cloud';
    $online = $state['device_online'] ?? false;
    
    return "📡 {$title}\n"
        . "━━━━━━━━━━━━━━━━━━━━━\n\n"
        . "{$powerEmoji} Світло: {$powerText}\n"
        . "{$voltEmoji} Напруга: {$voltage}V ({$voltText})\n\n"
        . "🕒 Перевірено: {$checkTime}\n"
        . "⏱ Стан триває: {$powerDuration}\n\n"
        . "📶 Пристрій: " . ($online ? '🟢 Онлайн' : '🔴 Офлайн') . "\n"
        . "🔗 Метод: {$method}";
}

function buildStatsText(PDO $pdo): string {
    $today = strtotime('today 00:00:00');
    $now = time();
    
    // Count events today
    $powerChanges = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE ts >= {$today} AND type = 'POWER'")->fetchColumn();
    $voltageChanges = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE ts >= {$today} AND type = 'VOLTAGE'")->fetchColumn();
    
    // Calculate uptime today
    $st = $pdo->query("SELECT ts, state FROM events WHERE ts >= {$today} AND type = 'POWER' ORDER BY ts ASC");
    $events = $st->fetchAll(PDO::FETCH_ASSOC);
    
    $onTime = 0;
    $offTime = 0;
    $lastTs = $today;
    $lastState = 'ON'; // Assume was ON at start of day
    
    foreach ($events as $e) {
        $duration = $e['ts'] - $lastTs;
        if ($lastState === 'ON') {
            $onTime += $duration;
        } else {
            $offTime += $duration;
        }
        $lastTs = (int)$e['ts'];
        $lastState = $e['state'];
    }
    
    // Add time until now
    $duration = $now - $lastTs;
    if ($lastState === 'ON') {
        $onTime += $duration;
    } else {
        $offTime += $duration;
    }
    
    $totalTime = $now - $today;
    $uptimePercent = $totalTime > 0 ? round(($onTime / $totalTime) * 100, 1) : 0;
    
    // API stats
    $apiStats = getApiStats($pdo);
    
    return "📊 Статистика за сьогодні\n"
        . "━━━━━━━━━━━━━━━━━━━━━\n\n"
        . "⚡ Зміни стану світла: {$powerChanges}\n"
        . "📈 Зміни напруги: {$voltageChanges}\n\n"
        . "✅ Час зі світлом: " . formatDuration($onTime) . "\n"
        . "❌ Час без світла: " . formatDuration($offTime) . "\n"
        . "📊 Uptime: {$uptimePercent}%\n\n"
        . "🔢 API запитів сьогодні: {$apiStats['today']}\n"
        . "🔢 API запитів за місяць: {$apiStats['month']}/30000";
}

function buildHistoryText(PDO $pdo, int $fromTs, int $toTs, string $period): string {
    $st = $pdo->prepare("SELECT ts, type, state, voltage FROM events WHERE ts >= :from AND ts < :to ORDER BY ts DESC LIMIT 50");
    $st->execute([':from' => $fromTs, ':to' => $toTs]);
    $events = $st->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($events)) {
        return "🧾 Історія ({$period})\n\nПодій не знайдено.";
    }
    
    $lines = ["🧾 Історія ({$period})\n━━━━━━━━━━━━━━━━━━━━━\n"];
    
    foreach ($events as $e) {
        $time = date('d.m H:i:s', $e['ts']);
        $type = $e['type'];
        $state = $e['state'];
        $voltage = $e['voltage'];
        
        $emoji = match ($type) {
            'POWER' => $state === 'ON' ? '✅' : '❌',
            'VOLTAGE' => voltageStatusEmoji($state),
            default => '📝',
        };
        
        $text = match ($type) {
            'POWER' => $state === 'ON' ? 'Світло з\'явилось' : 'Світло зникло',
            'VOLTAGE' => voltageStatusText($state) . ($voltage ? " ({$voltage}V)" : ''),
            default => $state,
        };
        
        $lines[] = "{$emoji} {$time} — {$text}";
    }
    
    return implode("\n", $lines);
}

function buildAdminStatsText(array $stats): string {
    return "👥 Статистика користувачів\n\n"
        . "📊 Всього зареєстровано: {$stats['activated']}\n"
        . "✅ Активних: {$stats['active']}\n"
        . "🔕 Відключили сповіщення: " . ($stats['activated'] - $stats['active']);
}

function buildScheduleText(PDO $pdo): string {
    $schedules = getUpcomingSchedule($pdo, 7);
    
    if (empty($schedules)) {
        return "📅 Графік відключень\n\n"
            . "Немає запланованих відключень на найближчі 7 днів 🎉\n\n"
            . "Графік оновлюється адміністратором.";
    }
    
    $text = "📅 Графік відключень (7 днів)\n━━━━━━━━━━━━━━━━━━━━━\n";
    $currentDate = '';
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    
    foreach ($schedules as $s) {
        if ($s['date'] !== $currentDate) {
            $currentDate = $s['date'];
            $dayLabel = match ($currentDate) {
                $today => '📍 Сьогодні',
                $tomorrow => '📆 Завтра',
                default => '📆 ' . date('d.m', strtotime($currentDate))
            };
            $text .= "\n{$dayLabel}\n";
        }
        $text .= "⏰ {$s['time_start']} - {$s['time_end']}";
        if (!empty($s['note'])) {
            $text .= " ({$s['note']})";
        }
        $text .= "\n";
    }
    
    // Check if currently in scheduled outage
    $current = isScheduledOutageNow($pdo);
    if ($current) {
        $text .= "\n⚠️ <b>Зараз планове відключення!</b>\n";
        $text .= "До {$current['time_end']}";
    }
    
    return $text;
}

// ==================== ACTION MAPPING ====================

function actionFromText(string $text): string {
    $lower = mb_strtolower(trim($text), 'UTF-8');
    
    // Match exact button texts first (case-insensitive)
    $buttonMap = [
        '📌 поточна інформація' => 'dashboard',
        '🔌 перевірити світло' => 'check_power',
        '⚡ перевірити напругу' => 'check_voltage',
        '📡 стан зараз' => 'status',
        '📊 статистика' => 'stats',
        '🧾 історія' => 'history',
        '📅 графік відключень' => 'schedule',
        '⚙️ налаштування' => 'settings',
        '🔔 увімкнути сповіщення' => 'notify_on',
        '🔕 вимкнути сповіщення' => 'notify_off',
        'ℹ️ про бота' => 'about',
        '◀️ назад' => 'back',
        '👥 користувачі' => 'admin_users',
        '🔧 адмін-панель' => 'admin_panel',
        '📅 сьогодні' => 'history_today',
        '📅 вчора' => 'history_yesterday',
        '📅 тиждень' => 'history_week',
        '📅 місяць' => 'history_month',
    ];
    
    if (isset($buttonMap[$lower])) {
        return $buttonMap[$lower];
    }
    
    // Fallback to partial matching
    return match (true) {
        str_contains($lower, 'поточна інформація') => 'dashboard',
        str_contains($lower, 'перевірити світло') => 'check_power',
        str_contains($lower, 'перевірити напругу') => 'check_voltage',
        str_contains($lower, 'стан зараз') => 'status',
        str_contains($lower, 'статистика') => 'stats',
        str_contains($lower, 'історія') => 'history',
        str_contains($lower, 'графік') => 'schedule',
        str_contains($lower, 'налаштування') => 'settings',
        str_contains($lower, 'увімкнути') => 'notify_on',
        str_contains($lower, 'вимкнути') => 'notify_off',
        str_contains($lower, 'про бота') => 'about',
        str_contains($lower, 'назад') => 'back',
        str_contains($lower, 'користувачі') => 'admin_users',
        str_contains($lower, 'сьогодні') => 'history_today',
        str_contains($lower, 'вчора') => 'history_yesterday',
        str_contains($lower, 'тиждень') => 'history_week',
        str_contains($lower, 'місяць') => 'history_month',
        str_contains($lower, 'адмін') => 'admin_panel',
        default => '',
    };
}

// ==================== MAIN HANDLER ====================

// Read webhook input
$input = file_get_contents('php://input');
if ($input === false || $input === '') {
    echo 'ok';
    exit;
}

$update = json_decode($input, true);
if (!is_array($update)) {
    echo 'ok';
    exit;
}

// Handle callback queries
$callback = $update['callback_query'] ?? null;
if (is_array($callback)) {
    $callbackId = (string)($callback['id'] ?? '');
    if ($callbackId !== '') {
        try {
            tgRequest((string)$config['tg_token'], 'answerCallbackQuery', ['callback_query_id' => $callbackId]);
        } catch (Throwable $e) {}
    }
    echo 'ok';
    exit;
}

// Handle messages
$message = $update['message'] ?? null;
if (!is_array($message)) {
    echo 'ok';
    exit;
}

$chat = $message['chat'] ?? [];
$from = $message['from'] ?? [];
$chatId = (int)($chat['id'] ?? 0);
if ($chatId === 0) {
    echo 'ok';
    exit;
}

$text = trim((string)($message['text'] ?? ''));
$lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
$command = $lower !== '' && $lower[0] === '/' ? explode(' ', $lower)[0] : '';

try {
    // /start command
    if ($command === '/start') {
        upsertSubscriber($pdo, $chat, $from, $config, true);
        sendBotMessage($config, $chatId, buildWelcomeText($pdo, $config));
        updateSubscriberDashboard($pdo, $config, $chatId, true);
        echo 'ok';
        exit;
    }
    
    // /stop command
    if ($command === '/stop') {
        setSubscriberActive($pdo, $chatId, false, $config);
        sendBotMessage($config, $chatId, "🔕 Сповіщення вимкнені.\n\nПовернутися — /start");
        echo 'ok';
        exit;
    }
    
    // /status command
    if ($command === '/status') {
        sendBotMessage($config, $chatId, buildStatusText($pdo, $config));
        echo 'ok';
        exit;
    }
    
    // /stats command
    if ($command === '/stats') {
        sendBotMessage($config, $chatId, buildStatsText($pdo));
        echo 'ok';
        exit;
    }
    
    // /help command
    if ($command === '/help') {
        $helpText = "📖 Доступні команди:\n\n"
            . "/start — почати роботу з ботом\n"
            . "/stop — вимкнути сповіщення\n"
            . "/status — поточний статус\n"
            . "/stats — статистика за сьогодні\n"
            . "/help — ця довідка\n\n"
            . "Або використовуйте кнопки меню нижче.";
        sendBotMessage($config, $chatId, $helpText);
        echo 'ok';
        exit;
    }
    
    // Update subscriber (but don't reactivate if not /start)
    if ($command !== '/stop') {
        upsertSubscriber($pdo, $chat, $from, $config, false);
    }
    
    // Handle button actions
    $action = actionFromText($text);
    
    switch ($action) {
        case 'dashboard':
            updateSubscriberDashboard($pdo, $config, $chatId, true);
            break;
            
        case 'check_power':
        case 'check_voltage':
        case 'status':
            dbSet($pdo, 'force_check', '1');
            sendBotMessage($config, $chatId, buildStatusText($pdo, $config) . "\n\n✅ Запит на оновлення відправлено!");
            break;
            
        case 'stats':
            sendBotMessage($config, $chatId, buildStatsText($pdo));
            break;
            
        case 'history':
            sendBotMessageWithKeyboard($config, $chatId, "Оберіть період для перегляду історії:", historyKeyboard());
            break;
            
        case 'history_today':
            $from = strtotime('today 00:00:00');
            $to = time();
            sendBotMessage($config, $chatId, buildHistoryText($pdo, $from, $to, 'сьогодні'));
            break;
            
        case 'history_yesterday':
            $from = strtotime('yesterday 00:00:00');
            $to = strtotime('today 00:00:00');
            sendBotMessage($config, $chatId, buildHistoryText($pdo, $from, $to, 'вчора'));
            break;
            
        case 'history_week':
            $from = strtotime('-7 days 00:00:00');
            $to = time();
            sendBotMessage($config, $chatId, buildHistoryText($pdo, $from, $to, 'тиждень'));
            break;
            
        case 'history_month':
            $from = strtotime('-30 days 00:00:00');
            $to = time();
            sendBotMessage($config, $chatId, buildHistoryText($pdo, $from, $to, 'місяць'));
            break;
            
        case 'settings':
            sendBotMessageWithKeyboard($config, $chatId, buildSettingsText($pdo, $config), settingsKeyboard());
            break;
            
        case 'notify_on':
            upsertSubscriber($pdo, $chat, $from, $config, true);
            sendBotMessage($config, $chatId, "🔔 Сповіщення увімкнені!\n\nВи будете отримувати повідомлення про зміни світла та напруги.");
            break;
            
        case 'notify_off':
            setSubscriberActive($pdo, $chatId, false, $config);
            sendBotMessage($config, $chatId, "🔕 Сповіщення вимкнені.\n\nЩоб повернутися — /start");
            break;
            
        case 'about':
            sendBotMessage($config, $chatId, buildAboutText($pdo, $config));
            break;
            
        case 'back':
            sendBotMessage($config, $chatId, "Оберіть дію:", true);
            break;
            
        case 'schedule':
            sendBotMessage($config, $chatId, buildScheduleText($pdo), true, 'HTML');
            break;
            
        case 'admin_users':
            if (isAdminChat($config, $chatId, $pdo)) {
                $stats = getSubscriberStats($pdo);
                sendBotMessage($config, $chatId, buildAdminStatsText($stats), false);
            } else {
                sendBotMessage($config, $chatId, "⛔ Команда недоступна.");
            }
            break;
            
        case 'admin_panel':
            if (isAdminChat($config, $chatId, $pdo)) {
                $baseUrl = $_SERVER['HTTP_HOST'] ?? '';
                $adminUrl = "https://{$baseUrl}/src/admin.php";
                sendBotMessage($config, $chatId, "🔧 Адмін-панель\n\n"
                    . "Перейдіть за посиланням:\n{$adminUrl}\n\n"
                    . "Або використовуйте команди:\n"
                    . "👥 Користувачі — /users\n"
                    . "📊 Статистика — /stats", false);
            } else {
                sendBotMessage($config, $chatId, "⛔ Команда недоступна.");
            }
            break;
            
        default:
            // Unknown command - just ignore or send help
            if ($text !== '') {
                sendBotMessage($config, $chatId, "❓ Невідома команда.\n\nВикористовуйте кнопки меню нижче або надішліть /help");
            }
            break;
    }
} catch (Throwable $e) {
    // Log error but don't fail webhook
    error_log("Bot error: " . $e->getMessage());
}

echo 'ok';
