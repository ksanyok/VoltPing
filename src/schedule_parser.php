<?php
declare(strict_types=1);

/**
 * VoltPing - Schedule Parser v1.2.0
 * Парсер графіків відключень з Telegram каналів
 * 
 * Підтримувані формати:
 * 
 * === Формат ДТЕК ===
 * Групи 4.1 і 4.2
 * ⚫️08:00 відкл. (4.1)
 * 🟢10:00 увімк.
 * ⚫️17:00 відкл.
 * 🟢24:00 увімк.
 * 
 * === Формат з чергами ===
 * 🔴 Черга 1: 00:00-06:00, 12:00-18:00
 * 🟡 Черга 2: 06:00-12:00
 */

/**
 * Parse schedule from Telegram channel
 */
function parseChannelSchedule(PDO $pdo, string $botToken, string $channelId, string $targetQueue): array {
    // Normalize channel ID
    $channelId = ltrim($channelId, '@');
    
    // Get channel messages using Telegram API
    // We need to use getUpdates or forward messages to bot
    // For public channels, we can use web scraping or t.me API
    
    $messages = getChannelMessages($botToken, $channelId, 20);
    
    if (empty($messages)) {
        return ['ok' => false, 'error' => 'Не вдалося отримати повідомлення з каналу'];
    }
    
    $foundSchedules = [];
    $date = null;
    
    foreach ($messages as $msg) {
        $text = $msg['text'] ?? '';
        if (empty($text)) continue;
        
        // Extract date from message
        $msgDate = extractDateFromText($text);
        if ($msgDate) {
            $date = $msgDate;
        }
        
        // Parse schedules for target queue
        $schedules = parseScheduleText($text, $targetQueue);
        
        if (!empty($schedules)) {
            $foundSchedules = array_merge($foundSchedules, $schedules);
            if (!$date) {
                $date = date('Y-m-d'); // Default to today
            }
            break; // Found schedules, stop searching
        }
    }
    
    if (empty($foundSchedules)) {
        return ['ok' => false, 'error' => "Графік для групи {$targetQueue} не знайдено"];
    }
    
    // Save to database
    $saved = 0;
    foreach ($foundSchedules as $schedule) {
        $scheduleDate = $date ?? date('Y-m-d');
        
        // Check if already exists
        $stmt = $pdo->prepare("SELECT id FROM schedule WHERE date = ? AND time_start = ? AND time_end = ?");
        $stmt->execute([$scheduleDate, $schedule['start'], $schedule['end']]);
        
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO schedule (date, time_start, time_end, note, source) VALUES (?, ?, ?, ?, 'parsed')");
            $stmt->execute([$scheduleDate, $schedule['start'], $schedule['end'], "Група {$targetQueue}"]);
            $saved++;
        }
    }
    
    return [
        'ok' => true,
        'found' => count($foundSchedules),
        'saved' => $saved,
        'date' => $date,
        'schedules' => $foundSchedules,
    ];
}

/**
 * Get messages from Telegram channel
 */
function getChannelMessages(string $botToken, string $channelId, int $limit = 20): array {
    // Try to get messages using Bot API (only works if bot is admin in channel)
    // If not, try to use t.me/s/{channel} (public channels only)
    
    $messages = [];
    
    // Method 1: Try t.me/s/ for public channels
    $url = "https://t.me/s/{$channelId}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code === 200 && $html) {
        // Parse HTML to extract messages
        preg_match_all('/<div class="tgme_widget_message_text[^"]*"[^>]*>(.*?)<\/div>/s', $html, $matches);
        
        if (!empty($matches[1])) {
            foreach (array_slice($matches[1], 0, $limit) as $msgHtml) {
                // Clean HTML
                $text = strip_tags($msgHtml);
                $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                $text = preg_replace('/\s+/', ' ', $text);
                $text = trim($text);
                
                if ($text) {
                    $messages[] = ['text' => $text];
                }
            }
        }
    }
    
    return $messages;
}

/**
 * Extract date from text
 */
function extractDateFromText(string $text): ?string {
    $months = [
        'січня' => 1, 'лютого' => 2, 'березня' => 3, 'квітня' => 4,
        'травня' => 5, 'червня' => 6, 'липня' => 7, 'серпня' => 8,
        'вересня' => 9, 'жовтня' => 10, 'листопада' => 11, 'грудня' => 12,
        'января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4,
        'мая' => 5, 'июня' => 6, 'июля' => 7, 'августа' => 8,
        'сентября' => 9, 'октября' => 10, 'ноября' => 11, 'декабря' => 12,
    ];
    
    // Pattern: "4 лютого" or "05.02.2026"
    foreach ($months as $monthName => $monthNum) {
        if (preg_match('/(\d{1,2})\s+' . preg_quote($monthName, '/') . '/ui', $text, $m)) {
            $day = (int)$m[1];
            $year = (int)date('Y');
            return sprintf('%04d-%02d-%02d', $year, $monthNum, $day);
        }
    }
    
    // Pattern: DD.MM.YYYY or DD.MM
    if (preg_match('/(\d{1,2})\.(\d{1,2})(?:\.(\d{2,4}))?/', $text, $m)) {
        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = isset($m[3]) ? (int)$m[3] : (int)date('Y');
        if ($year < 100) $year += 2000;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
    
    // Keywords
    $lower = mb_strtolower($text, 'UTF-8');
    if (str_contains($lower, 'сьогодні') || str_contains($lower, 'сегодня')) {
        return date('Y-m-d');
    }
    if (str_contains($lower, 'завтра')) {
        return date('Y-m-d', strtotime('+1 day'));
    }
    
    return null;
}

/**
 * Parse schedule text for specific queue/group
 * 
 * Supports formats:
 * - Групи 4.1 і 4.2 / ⚫️08:00 відкл. (4.1)
 * - Черга 1: 00:00-06:00, 12:00-18:00
 */
function parseScheduleText(string $text, string $targetQueue): array {
    $schedules = [];
    
    // Normalize target queue (4.1, 4.2, etc.)
    $targetQueue = trim($targetQueue);
    $targetMain = explode('.', $targetQueue)[0]; // "4" from "4.1"
    
    // Check if message contains our group
    $groupPattern = '/групи?\s*' . preg_quote($targetMain, '/') . '\.\d/ui';
    $queuePattern = '/черг[аи]?\s*' . preg_quote($targetMain, '/') . '/ui';
    
    $hasGroup = preg_match($groupPattern, $text) || preg_match($queuePattern, $text);
    
    if (!$hasGroup) {
        return [];
    }
    
    // Find the section for our group
    $lines = preg_split('/\n/', $text);
    $inOurSection = false;
    $events = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Check if this is a group header
        if (preg_match('/групи?\s*(\d+\.\d+)\s*(і|и|,)\s*(\d+\.\d+)/ui', $line, $m)) {
            $group1 = $m[1];
            $group2 = $m[3];
            $inOurSection = ($group1 === $targetQueue || $group2 === $targetQueue || 
                            explode('.', $group1)[0] === $targetMain);
            continue;
        }
        
        // Check for queue format: "Черга 1: 00:00-06:00"
        if (preg_match('/черг[аи]?\s*(\d+)\s*:\s*(.+)/ui', $line, $m)) {
            if ($m[1] === $targetMain) {
                // Parse time ranges
                preg_match_all('/(\d{1,2}):?(\d{2})?\s*[-–]\s*(\d{1,2}):?(\d{2})?/', $m[2], $ranges, PREG_SET_ORDER);
                foreach ($ranges as $r) {
                    $start = sprintf('%02d:%02d', (int)$r[1], (int)($r[2] ?? 0));
                    $end = sprintf('%02d:%02d', (int)$r[3], (int)($r[4] ?? 0));
                    if ($end === '24:00') $end = '23:59';
                    $schedules[] = ['start' => $start, 'end' => $end, 'type' => 'off'];
                }
            }
            continue;
        }
        
        // Parse DTEK format: ⚫️08:00 відкл. (4.1)
        if ($inOurSection) {
            // Check if line has specific group marker that is NOT ours
            if (preg_match('/\((\d+\.\d+)\)/u', $line, $specificGroup)) {
                if ($specificGroup[1] !== $targetQueue) {
                    continue; // Skip this line, it's for different subgroup
                }
            }
            
            // Parse time and event
            if (preg_match('/(⚫️?|🔴|черн|відкл|откл).*?(\d{1,2}):(\d{2})/ui', $line, $m)) {
                $events[] = [
                    'time' => sprintf('%02d:%02d', (int)$m[2], (int)$m[3]),
                    'type' => 'off',
                ];
            } elseif (preg_match('/(\d{1,2}):(\d{2}).*(⚫️?|🔴|черн|відкл|откл)/ui', $line, $m)) {
                $events[] = [
                    'time' => sprintf('%02d:%02d', (int)$m[1], (int)$m[2]),
                    'type' => 'off',
                ];
            } elseif (preg_match('/(🟢|зелен|увімк|включ).*?(\d{1,2}):(\d{2})/ui', $line, $m)) {
                $events[] = [
                    'time' => sprintf('%02d:%02d', (int)$m[2], (int)$m[3]),
                    'type' => 'on',
                ];
            } elseif (preg_match('/(\d{1,2}):(\d{2}).*(🟢|зелен|увімк|включ)/ui', $line, $m)) {
                $events[] = [
                    'time' => sprintf('%02d:%02d', (int)$m[1], (int)$m[2]),
                    'type' => 'on',
                ];
            }
        }
    }
    
    // Convert events to schedules (off periods)
    if (!empty($events)) {
        $currentOff = null;
        
        foreach ($events as $event) {
            if ($event['type'] === 'off' && $currentOff === null) {
                $currentOff = $event['time'];
            } elseif ($event['type'] === 'on' && $currentOff !== null) {
                $end = $event['time'];
                if ($end === '24:00') $end = '23:59';
                $schedules[] = ['start' => $currentOff, 'end' => $end, 'type' => 'off'];
                $currentOff = null;
            }
        }
        
        // If still off at end of day
        if ($currentOff !== null) {
            $schedules[] = ['start' => $currentOff, 'end' => '23:59', 'type' => 'off'];
        }
    }
    
    return $schedules;
}

/**
 * Parse simple time range format
 * "8-12, 20-24" => [['start' => '08:00', 'end' => '12:00'], ...]
 */
function parseTimeRanges(string $rangeStr): array {
    $schedules = [];
    
    preg_match_all('/(\d{1,2}):?(\d{2})?\s*[-–]\s*(\d{1,2}):?(\d{2})?/', $rangeStr, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $m) {
        $startH = (int)$m[1];
        $startM = isset($m[2]) ? (int)$m[2] : 0;
        $endH = (int)$m[3];
        $endM = isset($m[4]) ? (int)$m[4] : 0;
        
        // Handle 24:00 as 23:59
        if ($endH === 24) {
            $endH = 23;
            $endM = 59;
        }
        
        $schedules[] = [
            'start' => sprintf('%02d:%02d', $startH, $startM),
            'end' => sprintf('%02d:%02d', $endH, $endM),
            'type' => 'off',
        ];
    }
    
    return $schedules;
}
