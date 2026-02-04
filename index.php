<?php
declare(strict_types=1);

/**
 * VoltPing - Power Monitoring System
 * Головна сторінка з красивим статусом та кардіограмою напруги
 */

// Перевіряємо чи встановлено систему
if (!file_exists(__DIR__ . '/src/.env') && !file_exists(__DIR__ . '/src/config.php')) {
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/src/config.php';

$config = getConfig();
$pdo = getDatabase($config);

// ==================== API ENDPOINTS ====================
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    
    switch ($_GET['api']) {
        case 'status':
            $state = loadLastState($pdo, $config);
            $schedule = isScheduledOutageNow($pdo);
            
            echo json_encode([
                'power' => $state['power_state'] ?? 'UNKNOWN',
                'voltage' => $state['voltage'] ?? null,
                'voltage_state' => $state['voltage_state'] ?? 'UNKNOWN',
                'since' => $state['state_since_ts'] ?? null,
                'last_poll' => $state['last_poll_ts'] ?? null,
                'scheduled' => $schedule ? [
                    'start' => $schedule['time_start'],
                    'end' => $schedule['time_end'],
                ] : null,
            ], JSON_UNESCAPED_UNICODE);
            exit;
            
        case 'voltage_history':
            $hours = min(72, max(1, (int)($_GET['hours'] ?? 24)));
            $since = time() - ($hours * 3600);
            
            $stmt = $pdo->prepare("SELECT ts, voltage, power_state FROM voltage_log WHERE ts >= :since ORDER BY ts ASC");
            $stmt->execute([':since' => $since]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE);
            exit;
            
        case 'stats':
            $stats = getTodayStats($pdo);
            $weekStats = getWeekStats($pdo);
            
            echo json_encode([
                'today' => $stats,
                'week' => $weekStats,
            ], JSON_UNESCAPED_UNICODE);
            exit;
            
        case 'version':
            echo json_encode([
                'current' => VOLTPING_VERSION ?? '1.0.0',
                'latest' => getLatestVersion(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
    }
}

// ==================== HELPERS ====================
function getWeekStats(PDO $pdo): array {
    $weekAgo = time() - (7 * 24 * 3600);
    
    $stmt = $pdo->prepare("SELECT event_type, ts FROM events WHERE ts >= :weekAgo ORDER BY ts ASC");
    $stmt->execute([':weekAgo' => $weekAgo]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $onSeconds = 0;
    $offSeconds = 0;
    $prevTs = $weekAgo;
    $prevState = 'LIGHT_ON';
    
    foreach ($events as $e) {
        $duration = (int)$e['ts'] - $prevTs;
        if ($prevState === 'LIGHT_ON') {
            $onSeconds += $duration;
        } else {
            $offSeconds += $duration;
        }
        $prevState = $e['event_type'];
        $prevTs = (int)$e['ts'];
    }
    
    $duration = time() - $prevTs;
    if ($prevState === 'LIGHT_ON') {
        $onSeconds += $duration;
    } else {
        $offSeconds += $duration;
    }
    
    $total = $onSeconds + $offSeconds;
    
    return [
        'on' => $onSeconds,
        'off' => $offSeconds,
        'percent' => $total > 0 ? round(($onSeconds / $total) * 100, 1) : 0,
    ];
}

function getLatestVersion(): ?string {
    static $cached = null;
    if ($cached !== null) return $cached;
    
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
        $cached = $data['tag_name'] ?? null;
        return $cached;
    }
    
    return null;
}

// ==================== LOAD DATA ====================
$state = loadLastState($pdo, $config);
$powerState = $state['power_state'] ?? 'UNKNOWN';
$voltage = $state['voltage'] ?? null;
$voltageState = $state['voltage_state'] ?? 'NORMAL';
$lastPollTs = $state['last_poll_ts'] ?? 0;
$stateSinceTs = $state['state_since_ts'] ?? time();

$isPowerOn = $powerState === 'LIGHT_ON';
$schedule = isScheduledOutageNow($pdo);
$todayStats = getTodayStats($pdo);
$weekStats = getWeekStats($pdo);

$projectName = $config['project_name'] ?? 'VoltPing';
$title = $config['channel_base_title'] ?? $projectName;

$duration = time() - (int)$stateSinceTs;
$durText = fmtDur($duration);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="60">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚡</text></svg>">
    <style>
        :root {
            --bg-dark: #0a0a0f;
            --bg-card: #12121a;
            --bg-card-hover: #1a1a25;
            --border: #2a2a3a;
            --text: #e8e8f0;
            --text-muted: #888899;
            --green: #22c55e;
            --green-glow: rgba(34, 197, 94, 0.3);
            --red: #ef4444;
            --red-glow: rgba(239, 68, 68, 0.3);
            --yellow: #eab308;
            --blue: #3b82f6;
            --cyan: #06b6d4;
            --purple: #a855f7;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            background: var(--bg-dark);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.5;
        }
        
        .container { max-width: 900px; margin: 0 auto; padding: 1.5rem; }
        
        header { text-align: center; padding: 2rem 0; }
        
        .logo { font-size: 3rem; margin-bottom: 0.5rem; animation: pulse 2s infinite; }
        
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }
        
        h1 {
            font-size: 1.8rem;
            font-weight: 600;
            background: linear-gradient(135deg, var(--cyan), var(--purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .subtitle { color: var(--text-muted); font-size: 0.95rem; margin-top: 0.3rem; }
        
        .status-hero {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 2.5rem;
            margin: 1.5rem 0;
            text-align: center;
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }
        
        .status-hero::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: var(--green);
            box-shadow: 0 0 20px var(--green-glow);
        }
        
        .status-hero.off::before {
            background: var(--red);
            box-shadow: 0 0 20px var(--red-glow);
        }
        
        .status-icon { font-size: 4rem; margin-bottom: 1rem; }
        .status-text { font-size: 2rem; font-weight: 700; margin-bottom: 0.5rem; }
        .status-text.on { color: var(--green); }
        .status-text.off { color: var(--red); }
        .status-duration { color: var(--text-muted); font-size: 1.1rem; }
        .status-duration strong { color: var(--text); }
        
        .voltage-display {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }
        
        .voltage-value { font-size: 2.5rem; font-weight: 700; font-family: 'SF Mono', 'Consolas', monospace; }
        .voltage-unit { font-size: 1.5rem; color: var(--text-muted); }
        
        .voltage-state {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            margin-left: 1rem;
        }
        
        .voltage-state.normal { background: rgba(34, 197, 94, 0.2); color: var(--green); }
        .voltage-state.warn { background: rgba(234, 179, 8, 0.2); color: var(--yellow); }
        .voltage-state.crit { background: rgba(239, 68, 68, 0.2); color: var(--red); }
        
        .schedule-alert {
            background: rgba(234, 179, 8, 0.15);
            border: 1px solid rgba(234, 179, 8, 0.3);
            border-radius: 12px;
            padding: 1rem 1.5rem;
            margin: 1.5rem 0;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .schedule-alert .icon { font-size: 1.5rem; }
        .schedule-alert .text { color: var(--yellow); }
        .schedule-alert strong { color: var(--text); }
        
        .chart-section {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 1.5rem;
            margin: 1.5rem 0;
            border: 1px solid var(--border);
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .chart-tabs { display: flex; gap: 0.5rem; }
        
        .chart-tab {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-muted);
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        
        .chart-tab:hover { background: var(--bg-card-hover); color: var(--text); }
        .chart-tab.active { background: var(--blue); border-color: var(--blue); color: white; }
        
        #voltageChart {
            width: 100%;
            height: 200px;
            background: var(--bg-dark);
            border-radius: 8px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1.5rem 0;
        }
        
        .stat-card {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 1.25rem;
            border: 1px solid var(--border);
            text-align: center;
        }
        
        .stat-icon { font-size: 1.5rem; margin-bottom: 0.5rem; }
        .stat-value { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.25rem; }
        .stat-label { font-size: 0.85rem; color: var(--text-muted); }
        
        .links {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }
        
        .link-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            text-decoration: none;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        
        .link-btn:hover {
            background: var(--bg-card-hover);
            border-color: var(--blue);
        }
        
        footer {
            text-align: center;
            padding: 2rem 0;
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        
        footer a { color: var(--blue); text-decoration: none; }
        
        .update-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.25rem 0.5rem;
            background: rgba(59, 130, 246, 0.2);
            color: var(--blue);
            border-radius: 4px;
            font-size: 0.8rem;
            margin-top: 0.5rem;
        }
        
        .last-update {
            text-align: center;
            color: var(--text-muted);
            font-size: 0.85rem;
            margin-top: 1rem;
        }
        
        @media (max-width: 600px) {
            .container { padding: 1rem; }
            h1 { font-size: 1.4rem; }
            .status-text { font-size: 1.6rem; }
            .voltage-value { font-size: 2rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .chart-tabs { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="logo"><?= $isPowerOn ? '💡' : '🌙' ?></div>
            <h1><?= htmlspecialchars($projectName) ?></h1>
            <div class="subtitle"><?= htmlspecialchars($title) ?></div>
        </header>
        
        <div class="status-hero <?= $isPowerOn ? '' : 'off' ?>">
            <div class="status-icon"><?= $isPowerOn ? '✅' : '❌' ?></div>
            <div class="status-text <?= $isPowerOn ? 'on' : 'off' ?>">
                <?= $isPowerOn ? 'Світло є' : 'Світла немає' ?>
            </div>
            <div class="status-duration">
                <?= $isPowerOn ? 'Зі світлом' : 'Без світла' ?>: <strong><?= $durText ?></strong>
            </div>
            
            <?php if ($voltage !== null && $isPowerOn): ?>
            <div class="voltage-display">
                <span class="voltage-value"><?= number_format($voltage, 0) ?></span>
                <span class="voltage-unit">V</span>
                <?php
                $vClass = 'normal';
                $vLabel = 'Норма';
                if (in_array($voltageState, ['CRIT_LOW', 'CRIT_HIGH'])) {
                    $vClass = 'crit';
                    $vLabel = $voltageState === 'CRIT_LOW' ? '⚠️ Критично низька' : '⚠️ Критично висока';
                } elseif (in_array($voltageState, ['LOW', 'HIGH'])) {
                    $vClass = 'warn';
                    $vLabel = $voltageState === 'LOW' ? '⚡ Понижена' : '⚡ Підвищена';
                }
                ?>
                <span class="voltage-state <?= $vClass ?>"><?= $vLabel ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($schedule): ?>
        <div class="schedule-alert">
            <span class="icon">⚠️</span>
            <div class="text">
                <strong>Планове відключення</strong><br>
                <?= htmlspecialchars($schedule['time_start']) ?> - <?= htmlspecialchars($schedule['time_end']) ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="chart-section">
            <div class="chart-header">
                <span class="chart-title">⚡ Графік напруги</span>
                <div class="chart-tabs">
                    <button class="chart-tab active" data-hours="6">6г</button>
                    <button class="chart-tab" data-hours="12">12г</button>
                    <button class="chart-tab" data-hours="24">24г</button>
                    <button class="chart-tab" data-hours="48">48г</button>
                </div>
            </div>
            <canvas id="voltageChart"></canvas>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">📅</div>
                <div class="stat-value" style="color: var(--green);"><?= round($todayStats['on'] / 3600, 1) ?>г</div>
                <div class="stat-label">Зі світлом сьогодні</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-value"><?= $weekStats['percent'] ?>%</div>
                <div class="stat-label">Uptime за тиждень</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">❌</div>
                <div class="stat-value" style="color: var(--red);"><?= round($todayStats['off'] / 3600, 1) ?>г</div>
                <div class="stat-label">Без світла сьогодні</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🔌</div>
                <div class="stat-value"><?= round($weekStats['off'] / 3600, 1) ?>г</div>
                <div class="stat-label">Відключень за тиждень</div>
            </div>
        </div>
        
        <div class="links">
            <?php if (!empty($config['tg_bot_link']) || !empty($config['tg_bot_username'])): ?>
            <a href="<?= htmlspecialchars($config['tg_bot_link'] ?: 'https://t.me/' . $config['tg_bot_username']) ?>" class="link-btn" target="_blank">
                <span>🤖</span> Telegram Bot
            </a>
            <?php endif; ?>
            <a href="src/admin.php" class="link-btn">
                <span>⚙️</span> Адміністрування
            </a>
        </div>
        
        <div class="last-update">
            Останнє оновлення: <?= $lastPollTs ? date('H:i:s', $lastPollTs) : '—' ?>
        </div>
        
        <footer>
            <a href="https://github.com/ksanyok/VoltPing" target="_blank">VoltPing</a> v<?= VOLTPING_VERSION ?? '1.0.0' ?>
            <div class="update-badge" id="updateBadge" style="display: none;">
                🆕 Доступна нова версія
            </div>
        </footer>
    </div>
    
    <script>
    class VoltageChart {
        constructor(canvas) {
            this.canvas = canvas;
            this.ctx = canvas.getContext('2d');
            this.data = [];
            this.hours = 6;
            this.resize();
            window.addEventListener('resize', () => this.resize());
        }
        
        resize() {
            const rect = this.canvas.parentElement.getBoundingClientRect();
            this.canvas.width = rect.width;
            this.canvas.height = 200;
            this.draw();
        }
        
        async loadData(hours) {
            this.hours = hours;
            try {
                const res = await fetch(`?api=voltage_history&hours=${hours}`);
                const json = await res.json();
                this.data = json.data || [];
                this.draw();
            } catch (e) {
                console.error('Failed to load voltage data', e);
            }
        }
        
        draw() {
            const { ctx, canvas, data } = this;
            const w = canvas.width;
            const h = canvas.height;
            const pad = { top: 20, right: 15, bottom: 30, left: 50 };
            
            ctx.fillStyle = '#0a0a0f';
            ctx.fillRect(0, 0, w, h);
            
            if (data.length < 2) {
                ctx.fillStyle = '#888899';
                ctx.font = '14px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('Недостатньо даних', w/2, h/2);
                return;
            }
            
            const voltages = data.map(d => d.voltage).filter(v => v > 0);
            let minV = Math.min(...voltages);
            let maxV = Math.max(...voltages);
            
            const range = maxV - minV;
            minV = Math.floor(minV - range * 0.1);
            maxV = Math.ceil(maxV + range * 0.1);
            if (minV > 200) minV = 200;
            if (maxV < 240) maxV = 240;
            
            const timeStart = data[0].ts;
            const timeEnd = data[data.length - 1].ts;
            const timeRange = timeEnd - timeStart || 1;
            
            ctx.strokeStyle = '#2a2a3a';
            ctx.lineWidth = 1;
            
            const vStep = 10;
            for (let v = Math.ceil(minV / vStep) * vStep; v <= maxV; v += vStep) {
                const y = pad.top + (1 - (v - minV) / (maxV - minV)) * (h - pad.top - pad.bottom);
                ctx.beginPath();
                ctx.moveTo(pad.left, y);
                ctx.lineTo(w - pad.right, y);
                ctx.stroke();
                
                ctx.fillStyle = '#888899';
                ctx.font = '11px sans-serif';
                ctx.textAlign = 'right';
                ctx.fillText(v + 'V', pad.left - 5, y + 4);
            }
            
            ctx.setLineDash([4, 4]);
            ctx.strokeStyle = 'rgba(34, 197, 94, 0.5)';
            [207, 253].forEach(v => {
                if (v >= minV && v <= maxV) {
                    const y = pad.top + (1 - (v - minV) / (maxV - minV)) * (h - pad.top - pad.bottom);
                    ctx.beginPath();
                    ctx.moveTo(pad.left, y);
                    ctx.lineTo(w - pad.right, y);
                    ctx.stroke();
                }
            });
            
            ctx.strokeStyle = 'rgba(239, 68, 68, 0.5)';
            [190, 260].forEach(v => {
                if (v >= minV && v <= maxV) {
                    const y = pad.top + (1 - (v - minV) / (maxV - minV)) * (h - pad.top - pad.bottom);
                    ctx.beginPath();
                    ctx.moveTo(pad.left, y);
                    ctx.lineTo(w - pad.right, y);
                    ctx.stroke();
                }
            });
            
            ctx.setLineDash([]);
            
            ctx.beginPath();
            ctx.strokeStyle = '#06b6d4';
            ctx.lineWidth = 2;
            
            let prevOff = false;
            data.forEach((d, i) => {
                const x = pad.left + ((d.ts - timeStart) / timeRange) * (w - pad.left - pad.right);
                const isOff = d.power_state === 'LIGHT_OFF' || d.voltage < 50;
                
                if (isOff) {
                    if (!prevOff && i > 0) ctx.stroke();
                    prevOff = true;
                    return;
                }
                
                const y = pad.top + (1 - (d.voltage - minV) / (maxV - minV)) * (h - pad.top - pad.bottom);
                
                if (i === 0 || prevOff) {
                    ctx.beginPath();
                    ctx.moveTo(x, y);
                } else {
                    ctx.lineTo(x, y);
                }
                prevOff = false;
            });
            ctx.stroke();
            
            ctx.fillStyle = 'rgba(239, 68, 68, 0.15)';
            let offStart = null;
            
            data.forEach((d, i) => {
                const isOff = d.power_state === 'LIGHT_OFF' || d.voltage < 50;
                const x = pad.left + ((d.ts - timeStart) / timeRange) * (w - pad.left - pad.right);
                
                if (isOff && offStart === null) {
                    offStart = x;
                } else if (!isOff && offStart !== null) {
                    ctx.fillRect(offStart, pad.top, x - offStart, h - pad.top - pad.bottom);
                    offStart = null;
                }
            });
            
            if (offStart !== null) {
                ctx.fillRect(offStart, pad.top, w - pad.right - offStart, h - pad.top - pad.bottom);
            }
            
            ctx.fillStyle = '#888899';
            ctx.font = '11px sans-serif';
            ctx.textAlign = 'center';
            
            const labelCount = Math.min(8, this.hours);
            for (let i = 0; i <= labelCount; i++) {
                const t = timeStart + (timeRange / labelCount) * i;
                const x = pad.left + (i / labelCount) * (w - pad.left - pad.right);
                const date = new Date(t * 1000);
                ctx.fillText(date.getHours().toString().padStart(2, '0') + ':' + date.getMinutes().toString().padStart(2, '0'), x, h - 8);
            }
        }
    }
    
    const chart = new VoltageChart(document.getElementById('voltageChart'));
    chart.loadData(6);
    
    document.querySelectorAll('.chart-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.chart-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            chart.loadData(parseInt(tab.dataset.hours));
        });
    });
    
    fetch('?api=version')
        .then(r => r.json())
        .then(data => {
            if (data.latest && data.current !== data.latest) {
                document.getElementById('updateBadge').style.display = 'inline-flex';
            }
        })
        .catch(() => {});
    
    setInterval(async () => {
        try {
            const res = await fetch('?api=status');
            const data = await res.json();
        } catch (e) {}
    }, 30000);
    </script>
</body>
</html>
