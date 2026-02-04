<?php
declare(strict_types=1);

/**
 * VoltPing - Schedule Parser v1.3.1
 * Парсер графіків відключень з Telegram каналів
 * 
 * Підтримуваний формат (ДТЕК/ElectroNews):
 * 
 * Групи 4.1 і 4.2
 * 🟢00:00 увімк. (4.2)
 * ⚫️01:00 відкл. (4.2)
 * 🟢03:00 увімк.
 * ⚫️06:30 відкл.
 * 🟢13:30 увімк.
 * ⚫️17:00 відкл.
 * 🟢24:00 увімк.
 */

/**
 * Parse schedule from Telegram channel
 */
function parseChannelSchedule(PDO $pdo, string $botToken, string $channelId, string $targetQueue): array {
    // Normalize channel ID
    $channelId = ltrim($channelId, '@');
    
    // Get channel messages
    $messages = getChannelMessages($channelId, 30);
    
    if (empty($messages)) {
        return ['ok' => false, 'error' => 'Не вдалося отримати повідомлення з каналу', 'debug' => 'No messages found'];
    }
    
    $foundSchedules = [];
    $date = null;
    $debugInfo = [];
    $candidates = [];
    $today = date('Y-m-d');
    
    // Collect all messages with schedules for our group
    foreach ($messages as $msg) {
        $text = normalizeScheduleText($msg['text'] ?? '');
        if (empty($text)) continue;
        
        // Check if this message contains our group
        $targetMain = explode('.', $targetQueue)[0];
        $groupPattern = '/Груп(?:и|а)\s+' . preg_quote($targetMain, '/') . '\.\d/ui';
        
        if (!preg_match($groupPattern, $text)) {
            continue;
        }
        
        $debugInfo[] = "Found message with group {$targetMain}";
        
        // Extract date from message
        $msgDate = extractDateFromText($text);
        
        // Parse schedules for target queue
        $schedules = parseScheduleText($text, $targetQueue);
        
        if (!empty($schedules)) {
            $candidates[] = [
                'date' => $msgDate ?: $today,
                'schedules' => $schedules,
            ];
        }
    }
    
    // Pick the best candidate: prefer the most recent future date (latest date >= today)
    if (!empty($candidates)) {
        usort($candidates, function($a, $b) use ($today) {
            // Filter: only future/today dates have priority over past dates
            $aIsFuture = $a['date'] >= $today;
            $bIsFuture = $b['date'] >= $today;
            
            if ($aIsFuture !== $bIsFuture) {
                return $bIsFuture ? 1 : -1; // future wins
            }
            
            // Among future dates: pick the LATEST (farthest from today but still relevant)
            // Among past dates: also pick the latest (most recent)
            return strcmp($b['date'], $a['date']); // reverse: b > a means b comes first
        });
        
        $best = $candidates[0];
        $foundSchedules = $best['schedules'];
        $date = $best['date'];
    }
    
    if (empty($foundSchedules)) {
        return [
            'ok' => false, 
            'error' => "Графік для групи {$targetQueue} не знайдено",
            'debug' => $debugInfo,
            'messages_count' => count($messages),
        ];
    }
    
    // Save to database
    $saved = 0;
    foreach ($foundSchedules as $schedule) {
        $scheduleDate = $date ?? date('Y-m-d');
        
        // Check if already exists
        $stmt = $pdo->prepare("SELECT id FROM schedule WHERE date = ? AND time_start = ? AND time_end = ?");
        $stmt->execute([$scheduleDate, $schedule['start'], $schedule['end']]);
        
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO schedule (date, time_start, time_end, note, created_ts) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$scheduleDate, $schedule['start'], $schedule['end'], "Група {$targetQueue}", time()]);
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
 * Normalize schedule text for robust regex matching.
 * Telegram web view may include NBSP / narrow NBSP / ZWSP.
 */
function normalizeScheduleText(string $text): string {
    if ($text === '') return '';

    $replacements = [
        "\u{00A0}" => ' ', // NBSP
        "\u{202F}" => ' ', // narrow NBSP
        "\u{2007}" => ' ', // figure space
        "\u{200B}" => '',  // zero width space
        "\u{FEFF}" => '',  // BOM/zero width no-break
    ];
    $text = strtr($text, $replacements);
    // Normalize newlines and trim
    $text = preg_replace("/\r\n?/u", "\n", $text);
    return trim((string)$text);
}

/**
 * Get messages from Telegram channel using t.me/s/ (public channels)
 */
function getChannelMessages(string $channelId, int $limit = 30): array {
    $url = "https://t.me/s/{$channelId}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $messages = [];
    
    if ($code !== 200 || !$html) {
        return $messages;
    }
    
    // Parse HTML to extract messages
    // Messages are in: <div class="tgme_widget_message_text js-message_text" dir="auto">...</div>
    preg_match_all('/<div class="tgme_widget_message_text[^"]*"[^>]*>(.*?)<\/div>/s', $html, $matches);
    
    if (!empty($matches[1])) {
        foreach (array_slice($matches[1], 0, $limit) as $msgHtml) {
            // Clean HTML but preserve line breaks
            $text = preg_replace('/<br\s*\/?>/i', "\n", $msgHtml);
            $text = strip_tags($text);
            $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
            $text = trim($text);
            
            if ($text) {
                $messages[] = ['text' => normalizeScheduleText($text)];
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
    ];
    
    // Pattern: "на 4 лютого" or "4 лютого"
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
    if (str_contains($lower, 'сьогодні') || str_contains($lower, 'на сьогодні')) {
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
 * Format example:
 * Групи 4.1 і 4.2
 * 🟢00:00 увімк. (4.2)
 * ⚫️01:00 відкл. (4.2)
 * 🟢03:00 увімк.
 * ⚫️06:30 відкл.
 * 🟢13:30 увімк.
 * ⚫️17:00 відкл.
 * 🟢24:00 увімк.
 */
function parseScheduleText(string $text, string $targetQueue): array {
    $schedules = [];

    $text = normalizeScheduleText($text);
    
    // Normalize target queue (4.1, 4.2, etc.)
    $targetQueue = trim($targetQueue);
    $targetMain = explode('.', $targetQueue)[0];
    $targetSub = $targetQueue; // Full queue like "4.1"
    
    // Find the section for our group (we don't hard-require "X.1 і X.2" because channels vary)
    
    // Extract the section for our group
    // Split by group headers
    $sections = preg_split('/(?=Груп(?:и|а)\s+\d+\.\d)/ui', $text);
    
    $ourSection = '';
    foreach ($sections as $section) {
        if (preg_match('/^Груп(?:и|а)\s+' . preg_quote($targetMain, '/') . '\.\d/ui', $section)) {
            $ourSection = $section;
            break;
        }
    }
    
    if (empty($ourSection)) {
        return [];
    }
    
    // Parse events from our section
    // Format: ⚫️HH:MM відкл. or 🟢HH:MM увімк.
    // With optional (X.X) subgroup marker
    
    $events = [];
    
    // Match all time events
    // NOTE: do not allow the match to span multiple lines; otherwise one match can swallow many events.
    preg_match_all('/(⚫️|🟢|⚫|🔴)?\s*(\d{1,2}):(\d{2})\s*(відкл|відключ|увімк|увімкн|откл|вкл)[^\(\r\n]*(?:\((\d+\.\d+)\))?/ui', $ourSection, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $m) {
        $emoji = $m[1] ?? '';
        $hour = (int)$m[2];
        $minute = (int)$m[3];
        $action = mb_strtolower($m[4], 'UTF-8');
        $specificGroup = $m[5] ?? null;
        
        // Determine if this is "off" event
        $isOff = str_contains($action, 'відкл') || str_contains($action, 'откл') || $emoji === '⚫️' || $emoji === '⚫' || $emoji === '🔴';
        
        // Filter by subgroup if specified
        if ($specificGroup !== null && $specificGroup !== $targetSub) {
            continue;
        }
        
        $time = sprintf('%02d:%02d', $hour, $minute);
        if ($time === '24:00') $time = '23:59';
        
        $events[] = [
            'time' => $time,
            'type' => $isOff ? 'off' : 'on',
        ];
    }
    
    // Sort by time
    usort($events, fn($a, $b) => strcmp($a['time'], $b['time']));
    
    // Convert events to schedules (off periods)
    $offStart = null;
    
    foreach ($events as $event) {
        if ($event['type'] === 'off') {
            if ($offStart === null) {
                $offStart = $event['time'];
            }
        } elseif ($event['type'] === 'on') {
            if ($offStart !== null) {
                $schedules[] = [
                    'start' => $offStart,
                    'end' => $event['time'],
                ];
                $offStart = null;
            }
        }
    }
    
    // If still in "off" state at end, close it at 23:59
    if ($offStart !== null) {
        $schedules[] = [
            'start' => $offStart,
            'end' => '23:59',
        ];
    }
    
    return $schedules;
}

/**
 * Test parser with sample text
 */
function testParser(): void {
    $sample = "Прогноз на 4 лютого, середа
Протягом доби діють ГПВ до 4,5 черг
Розклад відключень за даними ДТЕК станом на 15:25
Групи 1.1 і 1.2
⚫️03:00 відкл.
🟢10:00 увімк.
⚫️13:30 відкл.
🟢20:30 увімк. (1.2)
⚫️22:00 відкл. (1.2)
🟢22:00 увімк. (1.1)
⚫️24:00 відкл. (1.1)
Групи 4.1 і 4.2
🟢00:00 увімк. (4.2)
⚫️01:00 відкл. (4.2)
🟢03:00 увімк.
⚫️06:30 відкл.
🟢11:00 увімк. (4.2)
⚫️11:30 відкл. (4.2)
🟢13:30 увімк.
⚫️17:00 відкл.
🟢24:00 увімк.";
    
    echo "Testing parser for group 4.1:\n";
    $result = parseScheduleText($sample, '4.1');
    print_r($result);
    
    echo "\nTesting parser for group 4.2:\n";
    $result = parseScheduleText($sample, '4.2');
    print_r($result);
}
