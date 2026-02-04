<?php
declare(strict_types=1);

/**
 * TuyaClient - Tuya Cloud OpenAPI Client
 * Підтримує отримання токена, статусу пристрою та Local Key
 */
class TuyaClient {
    private string $endpoint;
    private string $clientId;
    private string $secret;
    private ?string $tokenCacheFile;
    /** @var callable|null */
    private $onRequest;

    public function __construct(
        string $endpoint, 
        string $clientId, 
        string $secret, 
        ?string $tokenCacheFile = null, 
        ?callable $onRequest = null
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->clientId = $clientId;
        $this->secret = $secret;
        $this->tokenCacheFile = $tokenCacheFile ?? __DIR__ . '/tuya_token.json';
        $this->onRequest = $onRequest;
    }

    // ==================== HELPERS ====================
    
    private function uuidv4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function hmacSign(string $data): string {
        return strtoupper(hash_hmac('sha256', $data, $this->secret));
    }

    // ==================== TOKEN ====================
    
    private function getToken(): string {
        // Try cache
        if ($this->tokenCacheFile && file_exists($this->tokenCacheFile)) {
            $cache = json_decode(file_get_contents($this->tokenCacheFile), true);
            if (is_array($cache) && isset($cache['access_token'], $cache['expire_time'])) {
                if (time() < ($cache['expire_time'] - 60)) {
                    return (string)$cache['access_token'];
                }
            }
        }

        // Get new token
        $t = (string)(int)(microtime(true) * 1000);
        $nonce = $this->uuidv4();
        $stringToSign = $this->clientId . $t . $nonce . "GET\n" . hash('sha256', '') . "\n\n/v1.0/token?grant_type=1";
        $sign = $this->hmacSign($stringToSign);

        $headers = [
            "client_id: {$this->clientId}",
            "sign: {$sign}",
            "t: {$t}",
            "sign_method: HMAC-SHA256",
            "nonce: {$nonce}",
        ];

        $url = "{$this->endpoint}/v1.0/token?grant_type=1";
        $response = $this->httpGet($url, $headers);
        
        if ($this->onRequest) {
            call_user_func($this->onRequest, 'token', $url, $response);
        }

        $json = json_decode($response, true);
        if (!is_array($json) || !($json['success'] ?? false)) {
            throw new RuntimeException('Tuya token error: ' . $response);
        }

        $token = (string)($json['result']['access_token'] ?? '');
        $expireIn = (int)($json['result']['expire_time'] ?? 7200);

        // Save to cache
        if ($this->tokenCacheFile) {
            file_put_contents($this->tokenCacheFile, json_encode([
                'access_token' => $token,
                'expire_time' => time() + $expireIn,
            ]));
        }

        return $token;
    }

    // ==================== API REQUESTS ====================
    
    private function apiRequest(string $method, string $path, array $body = []): array {
        $token = $this->getToken();
        $t = (string)(int)(microtime(true) * 1000);
        $nonce = $this->uuidv4();

        $bodyStr = $body ? json_encode($body) : '';
        $bodyHash = hash('sha256', $bodyStr);
        
        $stringToSign = $this->clientId . $token . $t . $nonce . 
            strtoupper($method) . "\n" . $bodyHash . "\n\n" . $path;
        $sign = $this->hmacSign($stringToSign);

        $headers = [
            "client_id: {$this->clientId}",
            "access_token: {$token}",
            "sign: {$sign}",
            "t: {$t}",
            "sign_method: HMAC-SHA256",
            "nonce: {$nonce}",
        ];

        if ($bodyStr) {
            $headers[] = "Content-Type: application/json";
        }

        $url = $this->endpoint . $path;
        
        if (strtoupper($method) === 'GET') {
            $response = $this->httpGet($url, $headers);
        } else {
            $response = $this->httpPost($url, $headers, $bodyStr);
        }
        
        if ($this->onRequest) {
            call_user_func($this->onRequest, 'api', $url, $response);
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            throw new RuntimeException('Invalid Tuya response: ' . $response);
        }

        return $json;
    }

    private function httpGet(string $url, array $headers): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("cURL error: {$error}");
        }

        return $response;
    }

    private function httpPost(string $url, array $headers, string $body): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("cURL error: {$error}");
        }

        return $response;
    }

    // ==================== PUBLIC API ====================
    
    /**
     * Get device status
     */
    public function getDeviceStatus(string $deviceId): array {
        return $this->apiRequest('GET', "/v1.0/devices/{$deviceId}/status");
    }

    /**
     * Get device info (includes local_key!)
     */
    public function getDeviceInfo(string $deviceId): array {
        return $this->apiRequest('GET', "/v1.0/devices/{$deviceId}");
    }

    /**
     * Get device specifications
     */
    public function getDeviceSpec(string $deviceId): array {
        return $this->apiRequest('GET', "/v1.0/devices/{$deviceId}/specification");
    }

    /**
     * Execute device command
     */
    public function sendCommand(string $deviceId, array $commands): array {
        return $this->apiRequest('POST', "/v1.0/devices/{$deviceId}/commands", [
            'commands' => $commands,
        ]);
    }

    /**
     * Get voltage from device status
     */
    public function getVoltage(string $deviceId): ?float {
        $response = $this->getDeviceStatus($deviceId);
        
        if (!($response['success'] ?? false)) {
            throw new RuntimeException('Tuya API error: ' . json_encode($response));
        }

        $status = $response['result'] ?? [];
        
        // Look for voltage in different DPS codes
        $voltageKeys = ['cur_voltage', 'voltage', '20'];
        
        foreach ($status as $item) {
            if (!is_array($item)) continue;
            $code = $item['code'] ?? '';
            $value = $item['value'] ?? null;
            
            if (in_array($code, $voltageKeys, true) && is_numeric($value)) {
                $v = (float)$value;
                // Tuya often returns voltage * 10
                if ($v > 1000) $v /= 10;
                return round($v, 1);
            }
        }

        return null;
    }

    /**
     * Check if device is online
     */
    public function isOnline(string $deviceId): bool {
        $response = $this->getDeviceInfo($deviceId);
        return (bool)($response['result']['online'] ?? false);
    }

    /**
     * Get Local Key for local connection
     */
    public function getLocalKey(string $deviceId): ?string {
        $response = $this->getDeviceInfo($deviceId);
        
        if (!($response['success'] ?? false)) {
            return null;
        }
        
        return $response['result']['local_key'] ?? null;
    }

    /**
     * Get full device data with voltage and online status
     */
    public function getDeviceData(string $deviceId): array {
        $startTime = microtime(true);
        
        try {
            // Get device info (includes online status)
            $infoResponse = $this->getDeviceInfo($deviceId);
            $online = (bool)($infoResponse['result']['online'] ?? false);
            $localKey = $infoResponse['result']['local_key'] ?? null;
            
            // Get device status (includes voltage)
            $statusResponse = $this->getDeviceStatus($deviceId);
            
            $voltage = null;
            $power = null;
            $current = null;
            $switch = null;
            
            if ($statusResponse['success'] ?? false) {
                foreach ($statusResponse['result'] ?? [] as $item) {
                    if (!is_array($item)) continue;
                    $code = $item['code'] ?? '';
                    $value = $item['value'] ?? null;
                    
                    // Voltage
                    if (in_array($code, ['cur_voltage', 'voltage', '20'], true) && is_numeric($value)) {
                        $v = (float)$value;
                        if ($v > 1000) $v /= 10;
                        $voltage = round($v, 1);
                    }
                    
                    // Power
                    if (in_array($code, ['cur_power', 'power', '19'], true) && is_numeric($value)) {
                        $p = (float)$value;
                        if ($p > 100) $p /= 10;
                        $power = round($p, 1);
                    }
                    
                    // Current
                    if (in_array($code, ['cur_current', 'current', '18'], true) && is_numeric($value)) {
                        $c = (float)$value;
                        if ($c > 10) $c /= 1000;
                        $current = round($c, 3);
                    }
                    
                    // Switch
                    if (in_array($code, ['switch', 'switch_1', '1'], true)) {
                        $switch = (bool)$value;
                    }
                }
            }
            
            $latency = (int)((microtime(true) - $startTime) * 1000);
            
            return [
                'online' => $online,
                'voltage' => $voltage,
                'power' => $power,
                'current' => $current,
                'switch' => $switch,
                'local_key' => $localKey,
                'latency_ms' => $latency,
                'method' => 'cloud',
                'error' => null,
            ];
            
        } catch (Throwable $e) {
            $latency = (int)((microtime(true) - $startTime) * 1000);
            
            return [
                'online' => false,
                'voltage' => null,
                'power' => null,
                'current' => null,
                'switch' => null,
                'local_key' => null,
                'latency_ms' => $latency,
                'method' => 'cloud',
                'error' => $e->getMessage(),
            ];
        }
    }
}
