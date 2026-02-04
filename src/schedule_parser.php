<?php
declare(strict_types=1);

/**
 * VoltPing - Schedule Parser
 * Парсер графіків відключень з Telegram каналів
 * 
 * Підтримувані формати повідомлень:
 * 
 * === ПРИКЛАД 1: Стандартний формат ===
 * 📅 Графік відключень на 05.02.2026
 * 
 * 🔴 Черга 1: 00:00-06:00, 12:00-18:00
 * 🟡 Черга 2: 06:00-12:00, 18:00-24:00
 * 🟢 Черга 3: без відключень
 * 
 * ⚡ Ваша черга: 1
 * 
 * === ПРИКЛАД 2: Короткий формат ===
 * Графік на 05.02:
 * 1 черга: 8-12, 20-24
 * 2 черга: 4-8, 16-20
 * 
 * === ПРИКЛАД 3: Текстовий формат ===
 * Сьогодні відключення:
 * - з 08:00 до 12:00
 * - з 18:00 до 22:00
 */

/**
 * Parse schedule from text message
 */
function parseScheduleMessage(string $text): array {
    $result = [
        'date' => null,
        'queues' => [],
        'raw' => $text,
    ];
    
    // Try to extract date
    $result['date'] = extractDate($text);
    
    // Try to parse queues
    $result['queues'] = parseQueues($text);
    
    return $result;
}

/**
 * Extract date from text
 */
function extractDate(string $text): ?string {
    // Pattern: DD.MM.YYYY or DD.MM.YY
    if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{2,4})/', $text, $m)) {
        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = (int)$m[3];
        if ($year < 100) $year += 2000;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
    
    // Pattern: DD.MM (current year)
    if (preg_match('/(\d{1,2})\.(\d{1,2})(?!\.\d)/', $text, $m)) {
        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = (int)date('Y');
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
    
    // Keywords
    $lower = mb_strtolower($text, 'UTF-8');
    if (str_contains($lower, 'сьогодні') || str_contains($lower, 'сегодня')) {
        return date('Y-m-d');
    }
    if (str_contains($lower, 'завтра') || str_contains($lower, 'tomorrow')) {
        return date('Y-m-d', strtotime('+1 day'));
    }
    
    return null;
}

/**
 * Parse queues and their schedules
 */
function parseQueues(string $text): array {
    $queues = [];
    $lines = explode("\n", $text);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        
        $lower = mb_strtolower($line, 'UTF-8');
        
        // Skip if "без відключень" / "немає" / "нет"
        if (preg_match('/без\s*відключень|немає|нет\s*отключений|no\s*outages/ui', $lower)) {
            // Check if this is for a specific queue
            if (preg_match('/черг[аи]?\s*(\d+)/ui', $lower, $qm)) {
                $queues[(int)$qm[1]] = [];
            }
            continue;
        }
        
        // Try to find queue number
        $queueNum = null;
        if (preg_match('/черг[аи]?\s*(\d+)/ui', $line, $qm)) {
            $queueNum = (int)$qm[1];
        } elseif (preg_match('/(\d+)\s*черг/ui', $line, $qm)) {
            $queueNum = (int)$qm[1];
        } elseif (preg_match('/^(\d+)[:\s]/u', $line, $qm)) {
            $queueNum = (int)$qm[1];
        }
        
        // Parse time intervals
        $intervals = parseTimeIntervals($line);
        
        if ($queueNum !== null && !empty($intervals)) {
            if (!isset($queues[$queueNum])) {
                $queues[$queueNum] = [];
            }
            $queues[$queueNum] = array_merge($queues[$queueNum], $intervals);
        } elseif (!empty($intervals) && empty($queues)) {
            // No queue number found, assume it's queue 1
            if (!isset($queues[1])) {
                $queues[1] = [];
            }
            $queues[1] = array_merge($queues[1], $intervals);
        }
    }
    
    return $queues;
}

/**
 * Parse time intervals from a line
 */
function parseTimeIntervals(string $line): array {
    $intervals = [];
    
    // Pattern: HH:MM-HH:MM or HH:MM - HH:MM
    preg_match_all('/(\d{1,2}):(\d{2})\s*[-–—]\s*(\d{1,2}):(\d{2})/', $line, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $intervals[] = [
            'start' => sprintf('%02d:%02d', (int)$m[1], (int)$m[2]),
            'end' => sprintf('%02d:%02d', (int)$m[3], (int)$m[4]),
        ];
    }
    
    // Pattern: HH-HH (hours only)
    if (empty($intervals)) {
        preg_match_all('/(\d{1,2})\s*[-–—]\s*(\d{1,2})(?!\d|:)/', $line, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $start = (int)$m[1];
            $end = (int)$m[2];
            if ($start >= 0 && $start <= 24 && $end >= 0 && $end <= 24) {
                $intervals[] = [
                    'start' => sprintf('%02d:00', $start),
                    'end' => sprintf('%02d:00', $end),
                ];
            }
        }
    }
    
    // Pattern: "з HH:MM до HH:MM" (Ukrainian)
    preg_match_all('/з\s*(\d{1,2}):?(\d{2})?\s*до\s*(\d{1,2}):?(\d{2})?/ui', $line, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $startH = (int)$m[1];
        $startM = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
        $endH = (int)$m[3];
        $endM = isset($m[4]) && $m[4] !== '' ? (int)$m[4] : 0;
        
        $intervals[] = [
            'start' => sprintf('%02d:%02d', $startH, $startM),
            'end' => sprintf('%02d:%02d', $endH, $endM),
        ];
    }
    
    return $intervals;
}

/**
 * Check if current time is in outage period
 */
function isInOutagePeriod(array $schedule, int $queue, ?int $timestamp = null): bool {
    $timestamp = $timestamp ?? time();
    $date = date('Y-m-d', $timestamp);
    $time = date('H:i', $timestamp);
    
    if (!isset($schedule['queues'][$queue])) {
        return false;
    }
    
    foreach ($schedule['queues'][$queue] as $interval) {
        $start = $interval['start'];
        $end = $interval['end'];
        
        // Handle midnight crossing (e.g., 22:00-02:00)
        if ($end < $start) {
            if ($time >= $start || $time < $end) {
                return true;
            }
        } else {
            if ($time >= $start && $time < $end) {
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Get next outage start time
 */
function getNextOutageStart(array $schedule, int $queue, ?int $timestamp = null): ?string {
    $timestamp = $timestamp ?? time();
    $time = date('H:i', $timestamp);
    
    if (!isset($schedule['queues'][$queue])) {
        return null;
    }
    
    $intervals = $schedule['queues'][$queue];
    usort($intervals, fn($a, $b) => strcmp($a['start'], $b['start']));
    
    foreach ($intervals as $interval) {
        if ($interval['start'] > $time) {
            return $interval['start'];
        }
    }
    
    // No more outages today
    return null;
}

/**
 * Get next outage end time (power restoration)
 */
function getNextOutageEnd(array $schedule, int $queue, ?int $timestamp = null): ?string {
    $timestamp = $timestamp ?? time();
    $time = date('H:i', $timestamp);
    
    if (!isset($schedule['queues'][$queue])) {
        return null;
    }
    
    foreach ($schedule['queues'][$queue] as $interval) {
        $start = $interval['start'];
        $end = $interval['end'];
        
        // Handle midnight crossing
        if ($end < $start) {
            if ($time >= $start || $time < $end) {
                return $end;
            }
        } else {
            if ($time >= $start && $time < $end) {
                return $end;
            }
        }
    }
    
    return null;
}

/**
 * Save schedule to database
 */
function saveSchedule(PDO $pdo, array $schedule, int $queue, string $source = 'telegram'): int {
    $date = $schedule['date'] ?? date('Y-m-d');
    $now = time();
    $inserted = 0;
    
    if (!isset($schedule['queues'][$queue])) {
        return 0;
    }
    
    // Clear existing schedule for this date and queue
    $st = $pdo->prepare("DELETE FROM power_schedule WHERE date = :date AND queue = :queue AND source = :source");
    $st->execute([':date' => $date, ':queue' => $queue, ':source' => $source]);
    
    // Insert new intervals
    $st = $pdo->prepare("INSERT INTO power_schedule (date, queue, start_time, end_time, is_active, created_ts, source) VALUES (?, ?, ?, ?, 1, ?, ?)");
    
    foreach ($schedule['queues'][$queue] as $interval) {
        $st->execute([$date, $queue, $interval['start'], $interval['end'], $now, $source]);
        $inserted++;
    }
    
    return $inserted;
}

/**
 * Load schedule from database
 */
function loadSchedule(PDO $pdo, string $date, int $queue): array {
    $st = $pdo->prepare("SELECT start_time, end_time FROM power_schedule WHERE date = :date AND queue = :queue AND is_active = 1 ORDER BY start_time");
    $st->execute([':date' => $date, ':queue' => $queue]);
    
    $intervals = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $intervals[] = [
            'start' => $row['start_time'],
            'end' => $row['end_time'],
        ];
    }
    
    return [
        'date' => $date,
        'queues' => [$queue => $intervals],
    ];
}

/**
 * Check schedule notifications
 */
function checkScheduleNotifications(PDO $pdo, array $cfg, int $now): void {
    if (!($cfg['schedule_parse_enabled'] ?? false)) {
        return;
    }
    
    $queue = (int)($cfg['schedule_queue'] ?? 1);
    $date = date('Y-m-d', $now);
    $time = date('H:i', $now);
    
    // Load today's schedule
    $st = $pdo->prepare("SELECT id, start_time, end_time, notified_start, notified_end FROM power_schedule WHERE date = :date AND queue = :queue AND is_active = 1");
    $st->execute([':date' => $date, ':queue' => $queue]);
    $schedules = $st->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($schedules as $s) {
        $start = $s['start_time'];
        $end = $s['end_time'];
        
        // Check if we need to notify about upcoming outage (15 minutes before)
        if (!$s['notified_start']) {
            $startTs = strtotime($date . ' ' . $start);
            $warningTime = $startTs - (15 * 60);
            
            if ($now >= $warningTime && $now < $startTs) {
                // Send warning notification
                $chatIds = getNotifyChatIds($pdo, $cfg);
                $title = getBaseTitle($cfg);
                $msg = "⚠️ {$title}\n\n"
                    . "📅 Увага! За 15 хвилин планове відключення\n"
                    . "⏰ Початок: {$start}\n"
                    . "⏰ Кінець: {$end}\n\n"
                    . "Рекомендуємо підготуватися.";
                
                sendMessageToChats($pdo, $cfg, $chatIds, $msg);
                
                $st = $pdo->prepare("UPDATE power_schedule SET notified_start = 1 WHERE id = :id");
                $st->execute([':id' => $s['id']]);
            }
        }
        
        // Check if outage should have ended
        if (!$s['notified_end']) {
            $endTs = strtotime($date . ' ' . $end);
            
            if ($now >= $endTs) {
                // Mark as notified (actual power on notification handled by watch_power.php)
                $st = $pdo->prepare("UPDATE power_schedule SET notified_end = 1 WHERE id = :id");
                $st->execute([':id' => $s['id']]);
            }
        }
    }
}

/**
 * Build schedule text for bot
 */
function buildScheduleText(PDO $pdo, ?int $queue = null): string {
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    
    $text = "📅 Графік відключень\n━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    // Today
    $st = $pdo->prepare("SELECT queue, start_time, end_time FROM power_schedule WHERE date = :date AND is_active = 1 ORDER BY queue, start_time");
    $st->execute([':date' => $today]);
    $todaySchedule = $st->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($todaySchedule)) {
        $text .= "📆 Сьогодні: немає даних\n";
    } else {
        $text .= "📆 Сьогодні (" . date('d.m') . "):\n";
        $grouped = [];
        foreach ($todaySchedule as $s) {
            $q = $s['queue'];
            if (!isset($grouped[$q])) $grouped[$q] = [];
            $grouped[$q][] = $s['start_time'] . '-' . $s['end_time'];
        }
        foreach ($grouped as $q => $times) {
            $highlight = ($queue !== null && $q === $queue) ? '👉 ' : '';
            $text .= "{$highlight}Черга {$q}: " . implode(', ', $times) . "\n";
        }
    }
    
    // Tomorrow
    $st->execute([':date' => $tomorrow]);
    $tomorrowSchedule = $st->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($tomorrowSchedule)) {
        $text .= "\n📆 Завтра: немає даних\n";
    } else {
        $text .= "\n📆 Завтра (" . date('d.m', strtotime('+1 day')) . "):\n";
        $grouped = [];
        foreach ($tomorrowSchedule as $s) {
            $q = $s['queue'];
            if (!isset($grouped[$q])) $grouped[$q] = [];
            $grouped[$q][] = $s['start_time'] . '-' . $s['end_time'];
        }
        foreach ($grouped as $q => $times) {
            $highlight = ($queue !== null && $q === $queue) ? '👉 ' : '';
            $text .= "{$highlight}Черга {$q}: " . implode(', ', $times) . "\n";
        }
    }
    
    if ($queue !== null) {
        $text .= "\n⚡ Ваша черга: {$queue}";
    }
    
    return $text;
}

// ==================== HELPER (if not defined elsewhere) ====================

if (!function_exists('getNotifyChatIds')) {
    function getNotifyChatIds(PDO $pdo, array $cfg): array {
        $ids = [];
        $channelId = trim((string)($cfg['tg_chat_id'] ?? ''));
        if ($channelId !== '') $ids[] = $channelId;
        
        $st = $pdo->query("SELECT chat_id FROM bot_subscribers WHERE is_active = 1");
        if ($st !== false) {
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $ids[] = (string)$row['chat_id'];
            }
        }
        return array_values(array_unique($ids));
    }
}

if (!function_exists('sendMessageToChats')) {
    function sendMessageToChats(PDO $pdo, array $cfg, array $chatIds, string $text): void {
        if (($cfg['tg_token'] ?? '') === '' || $chatIds === []) return;
        foreach ($chatIds as $chatId) {
            try {
                tgRequest((string)$cfg['tg_token'], 'sendMessage', [
                    'chat_id' => (string)$chatId,
                    'text' => $text,
                    'disable_web_page_preview' => true,
                ]);
            } catch (Throwable $e) {}
        }
    }
}
