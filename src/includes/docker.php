<?php
/**
 * Handles Docker Management via CLI wrapper.
 * @package Dockyard
 * @author 10ij
 */

class Docker
{
    public function __construct()
    {
        if (!file_exists('/var/run/docker.sock')) {
            error_log('Warning: /var/run/docker.sock not found. The application may not function correctly.');
        }
    }

    private function validateName(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $name)) {
            throw new InvalidArgumentException('Invalid container name');
        }
        return $name;
    }

    private function apiRequest(string $method, string $endpoint, string $query = ''): array
    {
        $ch = curl_init();
        $url = "http://localhost" . $endpoint . ($query ? '?' . $query : '');
        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, "/var/run/docker.sock");
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $httpCode, 'body' => (string)$response];
    }

    /** @return array{output: string, success: bool} */
    public function start(string $name): array
    {
        $name = $this->validateName($name);
        $res = $this->apiRequest('POST', "/containers/$name/start");
        return ['output' => $res['body'], 'success' => in_array($res['code'], [204, 304])];
    }
    
    /** @return array{output: string, success: bool} */
    public function stop(string $name): array
    {
        $name = $this->validateName($name);
        $res = $this->apiRequest('POST', "/containers/$name/stop");
        return ['output' => $res['body'], 'success' => in_array($res['code'], [204, 304])];
    }

    /** @return array{output: string, success: bool} */
    public function logs(string $name, int $lines = 30): array
    {
        $name = $this->validateName($name);
        $lines = max(1, min($lines, 500));
        $res = $this->apiRequest('GET', "/containers/$name/logs", "stdout=true&stderr=true&tail=$lines");
        
        $body = $res['body'];
        if ($res['code'] !== 200) {
            return ['output' => $body, 'success' => false];
        }

        // Multiplexing header: 8 bytes (1 byte type, 3 bytes 0, 4 bytes size)
        $output = '';
        $len = strlen($body);
        $i = 0;
        $isMultiplexed = false;

        if ($len >= 8 && (ord($body[0]) === 1 || ord($body[0]) === 2)) {
            $isMultiplexed = true;
            while ($i <= $len - 8) {
                $type = ord($body[$i]);
                if ($type !== 1 && $type !== 2) {
                    $isMultiplexed = false; 
                    break;
                }
                $size = unpack('N', substr($body, $i + 4, 4))[1];
                if ($i + 8 + $size > $len) {
                    $isMultiplexed = false; 
                    break;
                }
                $output .= substr($body, $i + 8, $size);
                $i += 8 + $size;
            }
        }

        if (!$isMultiplexed) {
            $output = $body;
        }

        return ['output' => trim($output), 'success' => true];
    }

    /** @return array{output: string, success: bool} */
    public function status(string $name): array
    {
        $name = $this->validateName($name);
        $res = $this->apiRequest('GET', "/containers/$name/json");
        if ($res['code'] === 200) {
            $data = json_decode($res['body'], true);
            return ['output' => $data['State']['Status'] ?? 'unknown', 'success' => true];
        }
        return ['output' => 'unknown', 'success' => false];
    }

    public function inspect(string $name): array
    {
        $name = $this->validateName($name);
        $res = $this->apiRequest('GET', "/containers/$name/json");
        if ($res['code'] === 200) {
            return [json_decode($res['body'], true)];
        }
        throw new RuntimeException('Failed to inspect container: ' . $res['body']);
    }

    public function list(): array {
        $res = $this->apiRequest('GET', "/containers/json", "all=true");
        if ($res['code'] !== 200) {
            return [];
        }
        $data = json_decode($res['body'], true);
        if (!is_array($data)) return [];
        
        $containers = [];
        foreach ($data as $c) {
            $nameStr = ltrim($c['Names'][0] ?? '', '/');
            $statusStr = $c['Status'] ?? 'unknown';
            $imageStr = $c['Image'] ?? '';
            
            $portsArr = [];
            foreach ($c['Ports'] ?? [] as $p) {
                if (isset($p['PublicPort'])) {
                    $portsArr[] = "0.0.0.0:{$p['PublicPort']}->{$p['PrivatePort']}/{$p['Type']}";
                } else {
                    $portsArr[] = "{$p['PrivatePort']}/{$p['Type']}";
                }
            }
            $portStr = implode(', ', $portsArr);
            
            $containers[] = "$nameStr,$statusStr,$imageStr,$portStr";
        }
        return $containers;
    }
}