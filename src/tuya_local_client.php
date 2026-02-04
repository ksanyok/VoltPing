<?php
declare(strict_types=1);

/**
 * TuyaLocalClient - пряме підключення до пристрою Tuya без Cloud API
 * 
 * Протокол Tuya Local v3.3/3.4/3.5:
 * - TCP з'єднання на порт 6668
 * - AES-128-ECB шифрування з local_key
 * - Бінарний протокол із заголовками
 * 
 * Переваги:
 * - Без лімітів API (30k запитів/місяць)
 * - Миттєвий відгук (1-2 сек замість 5-10)
 * - Працює при відключенні інтернету (якщо є VPN/тунель)
 */
class TuyaLocalClient {
    private string $deviceId;
    private string $localKey;
    private string $host;
    private int $port;
    private string $version;
    private int $timeout;
    
    // Protocol constants
    private const PREFIX = "\x00\x00\x55\xAA";
    private const SUFFIX = "\x00\x00\xAA\x55";
    
    private const DP_QUERY = 0x0a;      // 10 - query status
    private const STATUS = 0x0d;        // 13 - status response
    private const HEART_BEAT = 0x09;    // 9 - heartbeat
    
    public function __construct(
        string $deviceId,
        string $localKey,
        string $host,
        int $port = 6668,
        string $version = '3.5',
        int $timeout = 5
    ) {
        $this->deviceId = $deviceId;
        $this->localKey = $localKey;
        $this->host = $host;
        $this->port = $port;
        $this->version = $version;
        $this->timeout = $timeout;
    }
    
    // ==================== ENCRYPTION ====================
    
    private function encrypt(string $data): string {
        // Pad to 16 bytes
        $padLen = 16 - (strlen($data) % 16);
        $data .= str_repeat(chr($padLen), $padLen);
        
        return openssl_encrypt($data, 'AES-128-ECB', $this->localKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    }
    
    private function decrypt(string $data): string {
        $decrypted = openssl_decrypt($data, 'AES-128-ECB', $this->localKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
        
        if ($decrypted === false) {
            return '';
        }
        
        // Remove PKCS7 padding
        $padLen = ord($decrypted[strlen($decrypted) - 1]);
        if ($padLen > 0 && $padLen <= 16) {
            $decrypted = substr($decrypted, 0, -$padLen);
        }
        
        return $decrypted;
    }
    
    // ==================== PROTOCOL ====================
    
    private function crc32Custom(string $data): int {
        return crc32($data) & 0xFFFFFFFF;
    }
    
    private function buildPayload(int $command, array $data = []): string {
        $seqNo = random_int(1, 65535);
        
        // Build JSON payload
        $payload = json_encode([
            'gwId' => $this->deviceId,
            'devId' => $this->deviceId,
            'uid' => $this->deviceId,
            't' => (string)time(),
            'dps' => $data ?: new stdClass(),
        ], JSON_UNESCAPED_SLASHES);
        
        // Encrypt payload for v3.4+
        if (version_compare($this->version, '3.4', '>=')) {
            $payload = $this->encrypt($payload);
            $payload = $this->version . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" . $payload;
        } elseif (version_compare($this->version, '3.3', '>=')) {
            $payload = $this->encrypt($payload);
        }
        
        // Build packet
        $header = self::PREFIX;
        $header .= pack('N', $seqNo);      // Sequence number
        $header .= pack('N', $command);    // Command
        $header .= pack('N', strlen($payload) + 8);  // Length (payload + suffix + CRC)
        
        // CRC
        $crc = $this->crc32Custom($header . $payload);
        
        $packet = $header . $payload . pack('N', $crc) . self::SUFFIX;
        
        return $packet;
    }
    
    private function parseResponse(string $data): ?array {
        if (strlen($data) < 20) {
            return null;
        }
        
        // Check prefix
        if (substr($data, 0, 4) !== self::PREFIX) {
            return null;
        }
        
        // Parse header
        $seqNo = unpack('N', substr($data, 4, 4))[1];
        $command = unpack('N', substr($data, 8, 4))[1];
        $length = unpack('N', substr($data, 12, 4))[1];
        
        // Extract payload
        $payload = substr($data, 16, $length - 8);
        
        // Try to decrypt
        if (version_compare($this->version, '3.4', '>=')) {
            // Skip version header (15 bytes)
            $payload = substr($payload, 15);
            $payload = $this->decrypt($payload);
        } elseif (version_compare($this->version, '3.3', '>=')) {
            $payload = $this->decrypt($payload);
        }
        
        // Try to parse JSON
        $json = json_decode($payload, true);
        
        return [
            'seqNo' => $seqNo,
            'command' => $command,
            'payload' => $json ?? $payload,
        ];
    }
    
    // ==================== CONNECTION ====================
    
    private function connect(): mixed {
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new RuntimeException('Cannot create socket: ' . socket_strerror(socket_last_error()));
        }
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
        
        if (@socket_connect($socket, $this->host, $this->port) === false) {
            $error = socket_strerror(socket_last_error($socket));
            socket_close($socket);
            throw new RuntimeException("Cannot connect to {$this->host}:{$this->port}: {$error}");
        }
        
        return $socket;
    }
    
    private function sendReceive($socket, string $data): string {
        $sent = socket_send($socket, $data, strlen($data), 0);
        if ($sent === false) {
            throw new RuntimeException('Send failed: ' . socket_strerror(socket_last_error($socket)));
        }
        
        $response = '';
        $buffer = '';
        
        while (($bytes = @socket_recv($socket, $buffer, 4096, 0)) > 0) {
            $response .= $buffer;
            // Check if we have complete packet (ends with suffix)
            if (str_contains($response, self::SUFFIX)) {
                break;
            }
        }
        
        return $response;
    }
    
    // ==================== PUBLIC API ====================
    
    /**
     * Get device status (DPS values)
     */
    public function getStatus(): array {
        $startTime = microtime(true);
        
        try {
            $socket = $this->connect();
            
            // Send query command
            $packet = $this->buildPayload(self::DP_QUERY);
            $response = $this->sendReceive($socket, $packet);
            
            socket_close($socket);
            
            if (empty($response)) {
                return [
                    'online' => false,
                    'error' => 'Empty response',
                    'voltage' => null,
                    'dps' => [],
                    'latency_ms' => (int)((microtime(true) - $startTime) * 1000),
                    'method' => 'local',
                ];
            }
            
            $parsed = $this->parseResponse($response);
            
            if (!$parsed || !is_array($parsed['payload'])) {
                return [
                    'online' => false,
                    'error' => 'Cannot parse response',
                    'voltage' => null,
                    'dps' => [],
                    'latency_ms' => (int)((microtime(true) - $startTime) * 1000),
                    'method' => 'local',
                ];
            }
            
            $dps = $parsed['payload']['dps'] ?? [];
            
            // Extract values
            $voltage = null;
            $power = null;
            $current = null;
            $switch = null;
            
            // DPS 20 = voltage (often * 10)
            if (isset($dps['20']) || isset($dps[20])) {
                $v = (float)($dps['20'] ?? $dps[20]);
                if ($v > 1000) $v /= 10;
                $voltage = round($v, 1);
            }
            
            // DPS 19 = power (often * 10)
            if (isset($dps['19']) || isset($dps[19])) {
                $p = (float)($dps['19'] ?? $dps[19]);
                if ($p > 100) $p /= 10;
                $power = round($p, 1);
            }
            
            // DPS 18 = current (often * 1000)
            if (isset($dps['18']) || isset($dps[18])) {
                $c = (float)($dps['18'] ?? $dps[18]);
                if ($c > 10) $c /= 1000;
                $current = round($c, 3);
            }
            
            // DPS 1 = switch
            if (isset($dps['1']) || isset($dps[1])) {
                $switch = (bool)($dps['1'] ?? $dps[1]);
            }
            
            return [
                'online' => true,
                'voltage' => $voltage,
                'power' => $power,
                'current' => $current,
                'switch' => $switch,
                'dps' => $dps,
                'error' => null,
                'latency_ms' => (int)((microtime(true) - $startTime) * 1000),
                'method' => 'local',
            ];
            
        } catch (Throwable $e) {
            return [
                'online' => false,
                'error' => $e->getMessage(),
                'voltage' => null,
                'power' => null,
                'current' => null,
                'switch' => null,
                'dps' => [],
                'latency_ms' => (int)((microtime(true) - $startTime) * 1000),
                'method' => 'local',
            ];
        }
    }
    
    /**
     * Test connection
     */
    public function testConnection(): bool {
        try {
            $socket = $this->connect();
            socket_close($socket);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
    
    /**
     * Get connection info
     */
    public function getInfo(): array {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'device_id' => $this->deviceId,
            'version' => $this->version,
        ];
    }
}

/**
 * Alternative: Use Python tinytuya for more reliable local polling
 */
function pollLocalTuyaPython(array $cfg): ?array {
    $host = $cfg['tuya_public_ip'] ?? $cfg['tuya_local_ip'] ?? '';
    $deviceId = $cfg['device_id'] ?? $cfg['tuya_device_id'] ?? '';
    $localKey = $cfg['tuya_local_key'] ?? '';
    $version = (string)($cfg['tuya_local_version'] ?? '3.5');
    $timeout = '5';
    
    if (empty($host) || empty($localKey) || empty($deviceId)) {
        return null;
    }
    
    // Check if tuya_local_poll.py exists
    $pythonScript = __DIR__ . '/tuya_local_poll.py';
    if (!file_exists($pythonScript)) {
        return null;
    }
    
    // Build command with proper escaping
    $cmd = sprintf(
        "TUYA_HOST=%s TUYA_DEVICE_ID=%s TUYA_LOCAL_KEY=%s TUYA_VERSION=%s TUYA_TIMEOUT=%s python3 %s 2>&1",
        escapeshellarg($host),
        escapeshellarg($deviceId),
        escapeshellarg($localKey),
        escapeshellarg($version),
        escapeshellarg($timeout),
        escapeshellarg($pythonScript)
    );
    
    $startTime = microtime(true);
    $output = shell_exec($cmd);
    $latency = (int)((microtime(true) - $startTime) * 1000);
    
    if ($output === null) {
        return null;
    }
    
    $result = json_decode(trim($output), true);
    if (!is_array($result)) {
        return null;
    }
    
    $result['latency_ms'] = $latency;
    $result['method'] = 'local_python';
    
    return $result;
}
