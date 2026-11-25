<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class MikrotikService
{
    /**
     * Test koneksi & ambil info dasar router
     * Mendukung: rest_v7 | api_v6 | api_ssl | ssh
     */
    public function testAndFetchResource(array $router): array
    {
        $type = $router['connection_type'] ?? '';

        if ($type === 'simulasi' || $type === '') {
            throw new \RuntimeException('Mode simulasi telah dinonaktifkan. Gunakan koneksi router MikroTik asli (REST v7, API v6, API-SSL, atau SSH).');
        }

        $this->validateRouterTarget($router);

        if ($type === 'rest_v7') {
            return $this->fetchResourceRestV7($router);
        }

        if ($type === 'ssh') {
            return $this->fetchResourceSsh($router);
        }

        if (in_array($type, ['api_v6', 'api_ssl'], true)) {
            return $this->fetchResourceApiV6($router, $type === 'api_ssl');
        }

        throw new \InvalidArgumentException("Tipe koneksi router '{$type}' tidak dikenali.");
    }

    /**
     * Ambil seluruh data monitoring router asli
     */
    public function getMonitoringData(array $router): array
    {
        $type = $router['connection_type'] ?? '';
        $meta = $this->decodeMeta($router['meta_json'] ?? null);

        if ($type === 'simulasi' || $type === '') {
            throw new \RuntimeException('Mode simulasi telah dinonaktifkan. Gunakan koneksi router MikroTik asli (REST v7, API v6, API-SSL, atau SSH).');
        }

        $this->validateRouterTarget($router);

        if ($type === 'rest_v7') {
            return $this->fetchMonitoringRestV7($router, $meta);
        }

        if ($type === 'ssh') {
            return $this->fetchMonitoringSsh($router, $meta);
        }

        if (in_array($type, ['api_v6', 'api_ssl'], true)) {
            return $this->fetchMonitoringApiV6($router, $meta, $type === 'api_ssl');
        }

        throw new \InvalidArgumentException("Tipe koneksi router '{$type}' tidak dikenali.");
    }

    /**
     * Eksekusi aksi kontrol jaringan pada router asli
     */
    public function executeAction(array $router, string $action, array $params = []): array
    {
        $type = $router['connection_type'] ?? '';
        $meta = $this->decodeMeta($router['meta_json'] ?? null);

        if ($type === 'simulasi' || $type === '') {
            throw new \RuntimeException('Mode simulasi telah dinonaktifkan. Gunakan koneksi router MikroTik asli (REST v7, API v6, API-SSL, atau SSH).');
        }

        $this->validateRouterTarget($router);

        if ($type === 'rest_v7') {
            return $this->executeRestV7Action($router, $meta, $action, $params);
        }

        if ($type === 'ssh') {
            return $this->executeSshAction($router, $meta, $action, $params);
        }

        if (in_array($type, ['api_v6', 'api_ssl'], true)) {
            return $this->executeApiV6Action($router, $meta, $action, $params, $type === 'api_ssl');
        }

        throw new \InvalidArgumentException("Tipe koneksi router '{$type}' tidak dikenali.");
    }

    // =========================================================================
    // VALIDASI & SANITASI KEAMANAN (ANTI-SSRF & ANTI-COMMAND INJECTION)
    // =========================================================================
    private function validateRouterTarget(array $router): void
    {
        $rawHost = trim((string) ($router['ip_host'] ?? ''));
        if ($rawHost === '') {
            throw new \InvalidArgumentException('Alamat IP/Host router tidak boleh kosong.');
        }

        if (preg_match('/[\s@?#\\\\]/', $rawHost)) {
            throw new \InvalidArgumentException('Format IP/Host router tidak valid.');
        }

        $bareHost = preg_replace('#^https?://#i', '', $rawHost);
        $bareHost = explode('/', $bareHost)[0];
        $bareHost = explode(':', $bareHost)[0];

        if ($bareHost === '') {
            throw new \InvalidArgumentException('Host router tidak valid.');
        }

        $lowerHost = strtolower($bareHost);
        if (in_array($lowerHost, ['localhost', 'metadata.google.internal', 'metadata'], true) || str_ends_with($lowerHost, '.local')) {
            throw new \InvalidArgumentException('Host lokal/metadata tidak diizinkan untuk keamanan server.');
        }

        $ipToCheck = filter_var($bareHost, FILTER_VALIDATE_IP) ? $bareHost : @gethostbyname($bareHost);
        if (filter_var($ipToCheck, FILTER_VALIDATE_IP)) {
            if (
                $ipToCheck === '0.0.0.0'
                || $ipToCheck === '::1'
                || str_starts_with($ipToCheck, '127.')
                || str_starts_with($ipToCheck, '169.254.')
            ) {
                throw new \InvalidArgumentException('IP loopback atau metadata cloud tidak diizinkan.');
            }
        }

        if (isset($router['port']) && $router['port'] !== null && $router['port'] !== '') {
            $port = (int) $router['port'];
            if ($port < 1 || $port > 65535) {
                throw new \InvalidArgumentException('Port router harus berada di rentang 1 - 65535.');
            }
        }
    }

    private function sanitizeIdentifier(string $val, string $label = 'Parameter', bool $required = true): string
    {
        $clean = trim($val);
        if ($clean === '') {
            if ($required) {
                throw new \InvalidArgumentException("{$label} wajib diisi.");
            }
            return '';
        }
        if (strlen($clean) > 100 || !preg_match('/^[a-zA-Z0-9._@\/:\-\s]+$/', $clean)) {
            throw new \InvalidArgumentException("{$label} mengandung karakter yang tidak diizinkan.");
        }
        return $clean;
    }

    private function sanitizeBandwidth(string $val, string $default): string
    {
        $clean = trim($val);
        if ($clean === '') {
            return $default;
        }
        if (!preg_match('/^\d{1,6}[kKmMgG]?$/', $clean)) {
            throw new \InvalidArgumentException('Format limit bandwidth tidak valid (contoh: 10M, 512k).');
        }
        return $clean;
    }

    private function sanitizeHostOrIp(string $val, string $default = '8.8.8.8'): string
    {
        $clean = trim($val);
        if ($clean === '') {
            return $default;
        }
        if (!filter_var($clean, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9.\-]{1,100}$/', $clean)) {
            throw new \InvalidArgumentException('Target IP/Hostname tidak valid.');
        }
        return $clean;
    }

    private function shouldVerifySsl(): bool
    {
        return filter_var(env('MIKROTIK_VERIFY_SSL', false), FILTER_VALIDATE_BOOLEAN);
    }

    // =========================================================================
    // 1. ROUTEROS V7 REST API (HTTP / HTTPS JSON)
    // =========================================================================
    private function restBaseUrl(array $router): string
    {
        $host = trim((string) ($router['ip_host'] ?? ''));
        $port = (int) ($router['port'] ?? 443);
        $scheme = ($port === 80 || $port === 8080) ? 'http' : 'https';
        if (str_starts_with($host, 'http://') || str_starts_with($host, 'https://')) {
            return rtrim($host, '/') . '/rest';
        }
        return "{$scheme}://{$host}:{$port}/rest";
    }

    private function restHttpClient(array $router, int $timeout)
    {
        $client = Http::withBasicAuth($router['username'] ?? 'admin', $router['password'] ?? '')
            ->timeout($timeout);

        if (!$this->shouldVerifySsl()) {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    private function restGet(array $router, string $endpoint): array
    {
        $res = $this->restHttpClient($router, 6)->get($this->restBaseUrl($router) . $endpoint);

        if (!$res->successful()) {
            throw new \RuntimeException('REST API error HTTP ' . $res->status());
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    private function restPost(array $router, string $endpoint, array $payload = []): array
    {
        $res = $this->restHttpClient($router, 8)->post($this->restBaseUrl($router) . $endpoint, $payload);

        if (!$res->successful()) {
            throw new \RuntimeException('REST API error HTTP ' . $res->status());
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    private function fetchResourceRestV7(array $router): array
    {
        $res = $this->restGet($router, '/system/resource');
        $totalMemMb = isset($res['total-memory']) ? round(((float) $res['total-memory']) / 1048576) : 0;
        $freeMemMb  = isset($res['free-memory']) ? round(((float) $res['free-memory']) / 1048576) : 0;

        return [
            'online'     => true,
            'model'      => $res['board-name'] ?? ($router['model'] ?: 'MikroTik'),
            'os_version' => $res['version'] ?? ($router['os_version'] ?: '7.x'),
            'cpu'        => trim(($res['cpu-count'] ?? '1') . ' Core, ' . ($res['cpu-frequency'] ?? '-') . ' MHz'),
            'cpu_load'   => (int) ($res['cpu-load'] ?? 0),
            'memory'     => $totalMemMb > 0 ? "{$totalMemMb} MB" : ($router['memory_info'] ?: '-'),
            'free_mem'   => $freeMemMb > 0 ? "{$freeMemMb} MB" : '-',
            'uptime'     => $res['uptime'] ?? '-',
            'message'    => 'Terhubung ke RouterOS v7 REST API',
        ];
    }

    private function fetchMonitoringRestV7(array $router, array $meta): array
    {
        $resource = $this->fetchResourceRestV7($router);

        $pppoeActive = $this->safeCall(fn() => $this->restGet($router, '/ppp/active'), []);
        $pppoeSecret = $this->safeCall(fn() => $this->restGet($router, '/ppp/secret'), []);
        $hotspot     = $this->safeCall(fn() => $this->restGet($router, '/ip/hotspot/active'), []);
        $dhcp        = $this->safeCall(fn() => $this->restGet($router, '/ip/dhcp-server/lease'), []);
        $interfaces  = $this->safeCall(fn() => $this->restGet($router, '/interface'), []);
        $queues      = $this->safeCall(fn() => $this->restGet($router, '/queue/simple'), []);
        $logs        = $this->safeCall(fn() => $this->restGet($router, '/log'), []);

        $pppoeList = [];
        $activeByName = [];
        foreach ($pppoeActive as $a) {
            $name = $a['name'] ?? '';
            if ($name !== '') $activeByName[$name] = $a;
        }
        foreach ($pppoeSecret as $s) {
            $name = $s['name'] ?? '-';
            $isDisabled = ($s['disabled'] ?? 'false') === 'true' || ($s['disabled'] ?? false) === true;
            $act = $activeByName[$name] ?? null;
            $pppoeList[] = [
                'id'       => $s['.id'] ?? $name,
                'username' => $name,
                'service'  => $s['service'] ?? 'pppoe',
                'profile'  => $s['profile'] ?? 'default',
                'ip'       => $act['address'] ?? ($s['remote-address'] ?? '-'),
                'uptime'   => $act['uptime'] ?? '-',
                'online'   => $act !== null,
                'disabled' => $isDisabled,
            ];
        }

        $ifaceList = array_map(fn($i) => [
            'id'       => $i['.id'] ?? ($i['name'] ?? ''),
            'name'     => $i['name'] ?? '-',
            'type'     => $i['type'] ?? 'ether',
            'running'  => ($i['running'] ?? 'false') === 'true' || ($i['running'] ?? false) === true,
            'disabled' => ($i['disabled'] ?? 'false') === 'true' || ($i['disabled'] ?? false) === true,
            'rx_byte'  => (int) ($i['rx-byte'] ?? 0),
            'tx_byte'  => (int) ($i['tx-byte'] ?? 0),
        ], $interfaces);

        $dhcpList = array_map(fn($d) => [
            'address'   => $d['address'] ?? '-',
            'mac'       => $d['mac-address'] ?? '-',
            'host_name' => $d['host-name'] ?? ($d['comment'] ?? 'Perangkat'),
            'status'    => $d['status'] ?? 'bound',
        ], array_slice($dhcp, 0, 50));

        $hotspotList = array_map(fn($h) => [
            'user'    => $h['user'] ?? '-',
            'address' => $h['address'] ?? '-',
            'uptime'  => $h['uptime'] ?? '-',
        ], array_slice($hotspot, 0, 50));

        $queueList = array_map(fn($q) => [
            'id'        => $q['.id'] ?? ($q['name'] ?? ''),
            'name'      => $q['name'] ?? '-',
            'target'    => $q['target'] ?? '-',
            'max_limit' => $q['max-limit'] ?? 'unlimited',
            'disabled'  => ($q['disabled'] ?? 'false') === 'true',
        ], array_slice($queues, 0, 50));

        $logList = array_map(fn($l) => [
            'time'    => $l['time'] ?? '-',
            'topics'  => $l['topics'] ?? 'system',
            'message' => $l['message'] ?? '',
        ], array_slice(array_reverse($logs), 0, 30));

        $activeCount = count($pppoeActive) + count($hotspot);
        if ($activeCount === 0) $activeCount = count($dhcpList);

        $bwIn  = (float) ($meta['bandwidth_in'] ?? 0);
        $bwOut = (float) ($meta['bandwidth_out'] ?? 0);

        return [
            'router_info'      => $resource,
            'internet_enabled' => (bool) ($meta['internet_enabled'] ?? true),
            'active_users'     => $activeCount,
            'bandwidth_in'     => $bwIn,
            'bandwidth_out'    => $bwOut,
            'traffic_history'  => $meta['traffic_history'] ?? [],
            'pppoe_users'      => $pppoeList,
            'dhcp_leases'      => $dhcpList,
            'hotspot_active'   => $hotspotList,
            'interfaces'       => $ifaceList,
            'queues'           => $queueList,
            'ping'             => $meta['last_ping'] ?? ['target' => '-', 'avg_ms' => 0, 'packet_loss' => 0],
            'system_logs'      => $logList,
            'blocked_sites'    => $meta['blocked_sites'] ?? [],
            'meta_json'        => $meta,
        ];
    }

    private function executeRestV7Action(array $router, array $meta, string $action, array $params): array
    {
        switch ($action) {
            case 'restart_router':
                $this->restPost($router, '/system/reboot');
                return ['message' => 'Perintah restart dikirim ke MikroTik (REST v7).', 'meta_json' => $meta];

            case 'disable_pppoe':
            case 'block_customer':
                $username = $this->sanitizeIdentifier((string) ($params['username'] ?? ''), 'Username PPPoE');
                $secrets = $this->restGet($router, '/ppp/secret?name=' . urlencode($username));
                if (!empty($secrets[0]['.id'])) {
                    $this->restPost($router, '/ppp/secret/set', ['.id' => $secrets[0]['.id'], 'disabled' => 'true']);
                }
                $actives = $this->restGet($router, '/ppp/active?name=' . urlencode($username));
                if (!empty($actives[0]['.id'])) {
                    $this->restPost($router, '/ppp/active/remove', ['.id' => $actives[0]['.id']]);
                }
                return ['message' => "PPPoE {$username} dinonaktifkan & sesi diputus.", 'meta_json' => $meta];

            case 'enable_pppoe':
            case 'activate_customer':
                $username = $this->sanitizeIdentifier((string) ($params['username'] ?? ''), 'Username PPPoE');
                $secrets = $this->restGet($router, '/ppp/secret?name=' . urlencode($username));
                if (!empty($secrets[0]['.id'])) {
                    $this->restPost($router, '/ppp/secret/set', ['.id' => $secrets[0]['.id'], 'disabled' => 'false']);
                }
                return ['message' => "PPPoE {$username} diaktifkan kembali.", 'meta_json' => $meta];

            case 'disconnect_user':
                $username = $this->sanitizeIdentifier((string) ($params['username'] ?? ''), 'Username PPPoE');
                $actives = $this->restGet($router, '/ppp/active?name=' . urlencode($username));
                if (!empty($actives[0]['.id'])) {
                    $this->restPost($router, '/ppp/active/remove', ['.id' => $actives[0]['.id']]);
                }
                return ['message' => "Sesi {$username} berhasil diputus.", 'meta_json' => $meta];

            case 'reset_user':
                $rawUser = trim((string) ($params['username'] ?? 'all'));
                $username = $rawUser === 'all' ? 'all' : $this->sanitizeIdentifier($rawUser, 'Username PPPoE');
                $actives = $username === 'all'
                    ? $this->restGet($router, '/ppp/active')
                    : $this->restGet($router, '/ppp/active?name=' . urlencode($username));
                foreach ($actives as $act) {
                    if (!empty($act['.id'])) {
                        $this->restPost($router, '/ppp/active/remove', ['.id' => $act['.id']]);
                    }
                }
                return ['message' => $username === 'all' ? 'Seluruh sesi pengguna berhasil di-reset.' : "Sesi {$username} berhasil di-reset.", 'meta_json' => $meta];

            case 'limit_bandwidth':
                $target = $this->sanitizeIdentifier((string) ($params['target'] ?? $params['username'] ?? ''), 'Target');
                $up     = $this->sanitizeBandwidth((string) ($params['upload_limit'] ?? ''), '10M');
                $down   = $this->sanitizeBandwidth((string) ($params['download_limit'] ?? ''), '20M');
                $limit  = "{$up}/{$down}";
                $queues = $this->restGet($router, '/queue/simple?name=' . urlencode($target));
                if (!empty($queues[0]['.id'])) {
                    $this->restPost($router, '/queue/simple/set', ['.id' => $queues[0]['.id'], 'max-limit' => $limit]);
                } else {
                    $this->restPost($router, '/queue/simple/add', ['name' => $target, 'target' => $target, 'max-limit' => $limit]);
                }
                return ['message' => "Limit bandwidth {$target} diatur ke {$limit}.", 'meta_json' => $meta];

            case 'restart_interface':
                $iface = $this->sanitizeIdentifier((string) ($params['interface_name'] ?? 'ether1'), 'Nama Interface');
                $this->restPost($router, '/interface/disable', ['numbers' => $iface]);
                usleep(300000);
                $this->restPost($router, '/interface/enable', ['numbers' => $iface]);
                return ['message' => "Interface {$iface} berhasil di-restart.", 'meta_json' => $meta];

            case 'ping_test':
                $target = $this->sanitizeHostOrIp((string) ($params['target'] ?? '8.8.8.8'));
                $pingRes = $this->restPost($router, '/ping', ['address' => $target, 'count' => '3']);
                $avg = 0;
                $loss = 0;
                if (!empty($pingRes)) {
                    $times = [];
                    $sent = count($pingRes);
                    $ok = 0;
                    foreach ($pingRes as $row) {
                        if (!empty($row['time'])) {
                            $ok++;
                            $times[] = (int) filter_var($row['time'], FILTER_SANITIZE_NUMBER_INT);
                        }
                    }
                    $avg = !empty($times) ? (int) round(array_sum($times) / count($times)) : 0;
                    $loss = $sent > 0 ? (int) round((($sent - $ok) / $sent) * 100) : 0;
                }
                $meta['last_ping'] = ['target' => $target, 'avg_ms' => $avg, 'packet_loss' => $loss];
                return ['message' => "Ping {$target}: {$avg} ms (Loss {$loss}%)", 'meta_json' => $meta, 'ping' => $meta['last_ping']];

            default:
                throw new \RuntimeException("Aksi '{$action}' belum didukung pada protokol RouterOS v7 REST API.");
        }
    }

    // =========================================================================
    // 2. ROUTEROS V6 & V7 BINARY API (PLAIN 8728 & API-SSL TLS 8729)
    // =========================================================================
    private function apiConnect(array $router, bool $useSsl = false)
    {
        $host = trim((string) ($router['ip_host'] ?? ''));
        $port = (int) ($router['port'] ?? ($useSsl ? 8729 : 8728));
        $user = (string) ($router['username'] ?? 'admin');
        $pass = (string) ($router['password'] ?? '');

        if ($useSsl) {
            $verifySsl = $this->shouldVerifySsl();
            $ctx = stream_context_create([
                'ssl' => [
                    'verify_peer'       => $verifySsl,
                    'verify_peer_name'  => $verifySsl,
                    'allow_self_signed' => !$verifySsl,
                ],
            ]);
            $sock = @stream_socket_client("tls://{$host}:{$port}", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
        } else {
            $sock = @fsockopen($host, $port, $errno, $errstr, 5);
        }

        if (!$sock) {
            $label = $useSsl ? 'API-SSL' : 'API';
            throw new \RuntimeException("Tidak dapat terhubung ({$label}) ke router ({$errstr}).");
        }
        stream_set_timeout($sock, 5);

        $this->apiWrite($sock, ['/login', '=name=' . $user, '=password=' . $pass]);
        $resp = $this->apiRead($sock);

        if (!empty($resp[0]['ret'])) {
            $challenge = pack('H*', $resp[0]['ret']);
            $hash = '00' . md5(chr(0) . $pass . $challenge);
            $this->apiWrite($sock, ['/login', '=name=' . $user, '=response=' . $hash]);
            $resp = $this->apiRead($sock);
        }

        if (isset($resp['!trap']) || isset($resp['!fatal'])) {
            fclose($sock);
            throw new \RuntimeException('Login MikroTik gagal: ' . ($resp['!trap'][0]['message'] ?? 'Invalid credentials'));
        }

        return $sock;
    }

    private function apiComm($sock, array $words): array
    {
        $this->apiWrite($sock, $words);
        return $this->apiRead($sock);
    }

    private function apiWrite($sock, array $words): void
    {
        foreach ($words as $w) {
            $len = strlen($w);
            fwrite($sock, $this->encodeLen($len) . $w);
        }
        fwrite($sock, chr(0));
    }

    private function apiRead($sock): array
    {
        $parsed = [];
        $current = [];
        $tag = null;

        while (true) {
            $len = $this->decodeLen($sock);
            if ($len === null) break;
            if ($len === 0) {
                if (!empty($current)) {
                    if ($tag === '!trap' || $tag === '!fatal') {
                        $parsed[$tag][] = $current;
                    } else {
                        $parsed[] = $current;
                    }
                    $current = [];
                }
                if ($tag === '!done' || $tag === '!fatal') break;
                $tag = null;
                continue;
            }
            $word = '';
            while (strlen($word) < $len) {
                $chunk = fread($sock, $len - strlen($word));
                if ($chunk === false || $chunk === '') break;
                $word .= $chunk;
            }
            if (str_starts_with($word, '!')) {
                $tag = $word;
            } elseif (str_starts_with($word, '=')) {
                $parts = explode('=', substr($word, 1), 2);
                $current[$parts[0]] = $parts[1] ?? '';
            }
        }
        return $parsed;
    }

    private function encodeLen(int $len): string
    {
        if ($len < 0x80) return chr($len);
        if ($len < 0x4000) {
            $len |= 0x8000;
            return chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
        if ($len < 0x200000) {
            $len |= 0xC00000;
            return chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
        $len |= 0xE0000000;
        return chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
    }

    private function decodeLen($sock): ?int
    {
        $byte = fread($sock, 1);
        if ($byte === false || $byte === '') return null;
        $c = ord($byte);
        if (($c & 0x80) === 0x00) return $c;
        if (($c & 0xC0) === 0x80) {
            $b2 = ord(fread($sock, 1));
            return (($c & ~0xC0) << 8) + $b2;
        }
        if (($c & 0xE0) === 0xC0) {
            $b2 = ord(fread($sock, 1));
            $b3 = ord(fread($sock, 1));
            return (($c & ~0xE0) << 16) + ($b2 << 8) + $b3;
        }
        if (($c & 0xF0) === 0xE0) {
            $b2 = ord(fread($sock, 1));
            $b3 = ord(fread($sock, 1));
            $b4 = ord(fread($sock, 1));
            return (($c & ~0xF0) << 24) + ($b2 << 16) + ($b3 << 8) + $b4;
        }
        return 0;
    }

    private function fetchResourceApiV6(array $router, bool $useSsl = false): array
    {
        $sock = $this->apiConnect($router, $useSsl);
        try {
            $rows = $this->apiComm($sock, ['/system/resource/print']);
            $res = $rows[0] ?? [];
            $totalMemMb = isset($res['total-memory']) ? round(((float) $res['total-memory']) / 1048576) : 0;
            $freeMemMb  = isset($res['free-memory']) ? round(((float) $res['free-memory']) / 1048576) : 0;
            $protoLabel = $useSsl ? 'API-SSL' : 'API';

            return [
                'online'     => true,
                'model'      => $res['board-name'] ?? ($router['model'] ?: 'MikroTik'),
                'os_version' => $res['version'] ?? ($router['os_version'] ?: '6.x'),
                'cpu'        => trim(($res['cpu-count'] ?? '1') . ' Core, ' . ($res['cpu-frequency'] ?? '-') . ' MHz'),
                'cpu_load'   => (int) ($res['cpu-load'] ?? 0),
                'memory'     => $totalMemMb > 0 ? "{$totalMemMb} MB" : ($router['memory_info'] ?: '-'),
                'free_mem'   => $freeMemMb > 0 ? "{$freeMemMb} MB" : '-',
                'uptime'     => $res['uptime'] ?? '-',
                'message'    => "Terhubung ke MikroTik {$protoLabel} Port " . ($router['port'] ?? ($useSsl ? 8729 : 8728)),
            ];
        } finally {
            fclose($sock);
        }
    }

    private function fetchMonitoringApiV6(array $router, array $meta, bool $useSsl = false): array
    {
        $sock = $this->apiConnect($router, $useSsl);
        try {
            $resRows     = $this->apiComm($sock, ['/system/resource/print']);
            $res         = $resRows[0] ?? [];
            $pppoeActive = $this->apiComm($sock, ['/ppp/active/print']);
            $pppoeSecret = $this->apiComm($sock, ['/ppp/secret/print']);
            $dhcp        = $this->apiComm($sock, ['/ip/dhcp-server/lease/print']);
            $hotspot     = $this->apiComm($sock, ['/ip/hotspot/active/print']);
            $interfaces  = $this->apiComm($sock, ['/interface/print']);
            $queues      = $this->apiComm($sock, ['/queue/simple/print']);
            $logs        = $this->apiComm($sock, ['/log/print']);

            $totalMemMb = isset($res['total-memory']) ? round(((float) $res['total-memory']) / 1048576) : 0;
            $freeMemMb  = isset($res['free-memory']) ? round(((float) $res['free-memory']) / 1048576) : 0;

            $activeByName = [];
            foreach ($pppoeActive as $a) {
                if (!empty($a['name'])) $activeByName[$a['name']] = $a;
            }
            $pppoeList = [];
            foreach ($pppoeSecret as $s) {
                $name = $s['name'] ?? '-';
                $act  = $activeByName[$name] ?? null;
                $pppoeList[] = [
                    'id'       => $s['.id'] ?? $name,
                    'username' => $name,
                    'service'  => $s['service'] ?? 'pppoe',
                    'profile'  => $s['profile'] ?? 'default',
                    'ip'       => $act['address'] ?? ($s['remote-address'] ?? '-'),
                    'uptime'   => $act['uptime'] ?? '-',
                    'online'   => $act !== null,
                    'disabled' => ($s['disabled'] ?? 'false') === 'true',
                ];
            }

            $bwIn  = (float) ($meta['bandwidth_in'] ?? 0);
            $bwOut = (float) ($meta['bandwidth_out'] ?? 0);

            return [
                'router_info' => [
                    'online'     => true,
                    'model'      => $res['board-name'] ?? ($router['model'] ?: 'MikroTik'),
                    'os_version' => $res['version'] ?? ($router['os_version'] ?: '6.x'),
                    'cpu'        => trim(($res['cpu-count'] ?? '1') . ' Core, ' . ($res['cpu-frequency'] ?? '-') . ' MHz'),
                    'cpu_load'   => (int) ($res['cpu-load'] ?? 0),
                    'memory'     => $totalMemMb > 0 ? "{$totalMemMb} MB" : '-',
                    'free_mem'   => $freeMemMb > 0 ? "{$freeMemMb} MB" : '-',
                    'uptime'     => $res['uptime'] ?? '-',
                ],
                'internet_enabled' => (bool) ($meta['internet_enabled'] ?? true),
                'active_users'     => count($pppoeActive) + count($hotspot),
                'bandwidth_in'     => $bwIn,
                'bandwidth_out'    => $bwOut,
                'traffic_history'  => $meta['traffic_history'] ?? [],
                'pppoe_users'      => $pppoeList,
                'dhcp_leases'      => array_map(fn($d) => [
                    'address'   => $d['address'] ?? '-',
                    'mac'       => $d['mac-address'] ?? '-',
                    'host_name' => $d['host-name'] ?? ($d['comment'] ?? 'Perangkat'),
                    'status'    => $d['status'] ?? 'bound',
                ], array_slice($dhcp, 0, 50)),
                'hotspot_active'   => array_map(fn($h) => [
                    'user'    => $h['user'] ?? '-',
                    'address' => $h['address'] ?? '-',
                    'uptime'  => $h['uptime'] ?? '-',
                ], array_slice($hotspot, 0, 50)),
                'interfaces'       => array_map(fn($i) => [
                    'id'       => $i['.id'] ?? ($i['name'] ?? ''),
                    'name'     => $i['name'] ?? '-',
                    'type'     => $i['type'] ?? 'ether',
                    'running'  => ($i['running'] ?? 'false') === 'true',
                    'disabled' => ($i['disabled'] ?? 'false') === 'true',
                    'rx_byte'  => (int) ($i['rx-byte'] ?? 0),
                    'tx_byte'  => (int) ($i['tx-byte'] ?? 0),
                ], $interfaces),
                'queues'           => array_map(fn($q) => [
                    'id'        => $q['.id'] ?? ($q['name'] ?? ''),
                    'name'      => $q['name'] ?? '-',
                    'target'    => $q['target'] ?? '-',
                    'max_limit' => $q['max-limit'] ?? 'unlimited',
                    'disabled'  => ($q['disabled'] ?? 'false') === 'true',
                ], array_slice($queues, 0, 50)),
                'ping'             => $meta['last_ping'] ?? ['target' => '-', 'avg_ms' => 0, 'packet_loss' => 0],
                'system_logs'      => array_map(fn($l) => [
                    'time'    => $l['time'] ?? '-',
                    'topics'  => $l['topics'] ?? 'system',
                    'message' => $l['message'] ?? '',
                ], array_slice(array_reverse($logs), 0, 30)),
                'blocked_sites'    => $meta['blocked_sites'] ?? [],
                'meta_json'        => $meta,
            ];
        } finally {
            fclose($sock);
        }
    }

    private function executeApiV6Action(array $router, array $meta, string $action, array $params, bool $useSsl = false): array
    {
        $sock = $this->apiConnect($router, $useSsl);
        try {
            $protoLabel = $useSsl ? 'API-SSL' : 'API v6';
            switch ($action) {
                case 'restart_router':
                    $this->apiComm($sock, ['/system/reboot']);
                    return ['message' => "Router sedang restart ({$protoLabel}).", 'meta_json' => $meta];

                case 'disable_pppoe':
                case 'block_customer':
                    $username = $this->sanitizeIdentifier((string) ($params['username'] ?? ''), 'Username PPPoE');
                    $secrets = $this->apiComm($sock, ['/ppp/secret/print', '?name=' . $username]);
                    if (!empty($secrets[0]['.id'])) {
                        $this->apiComm($sock, ['/ppp/secret/set', '=.id=' . $secrets[0]['.id'], '=disabled=yes']);
                    }
                    $actives = $this->apiComm($sock, ['/ppp/active/print', '?name=' . $username]);
                    if (!empty($actives[0]['.id'])) {
                        $this->apiComm($sock, ['/ppp/active/remove', '=.id=' . $actives[0]['.id']]);
                    }
                    return ['message' => "PPPoE {$username} dinonaktifkan.", 'meta_json' => $meta];

                case 'enable_pppoe':
                case 'activate_customer':
                    $username = $this->sanitizeIdentifier((string) ($params['username'] ?? ''), 'Username PPPoE');
                    $secrets = $this->apiComm($sock, ['/ppp/secret/print', '?name=' . $username]);
                    if (!empty($secrets[0]['.id'])) {
                        $this->apiComm($sock, ['/ppp/secret/set', '=.id=' . $secrets[0]['.id'], '=disabled=no']);
                    }
                    return ['message' => "PPPoE {$username} diaktifkan.", 'meta_json' => $meta];

                case 'disconnect_user':
                    $username = $this->sanitizeIdentifier((string) ($params['username'] ?? ''), 'Username PPPoE');
                    $actives = $this->apiComm($sock, ['/ppp/active/print', '?name=' . $username]);
                    if (!empty($actives[0]['.id'])) {
                        $this->apiComm($sock, ['/ppp/active/remove', '=.id=' . $actives[0]['.id']]);
                    }
                    return ['message' => "Sesi {$username} diputus.", 'meta_json' => $meta];

                case 'reset_user':
                    $rawUser = trim((string) ($params['username'] ?? 'all'));
                    $username = $rawUser === 'all' ? 'all' : $this->sanitizeIdentifier($rawUser, 'Username PPPoE');
                    $actives = $username === 'all'
                        ? $this->apiComm($sock, ['/ppp/active/print'])
                        : $this->apiComm($sock, ['/ppp/active/print', '?name=' . $username]);
                    foreach ($actives as $act) {
                        if (!empty($act['.id'])) {
                            $this->apiComm($sock, ['/ppp/active/remove', '=.id=' . $act['.id']]);
                        }
                    }
                    return ['message' => $username === 'all' ? 'Seluruh sesi pengguna berhasil di-reset.' : "Sesi {$username} berhasil di-reset.", 'meta_json' => $meta];

                case 'limit_bandwidth':
                    $target = $this->sanitizeIdentifier((string) ($params['target'] ?? $params['username'] ?? ''), 'Target');
                    $up     = $this->sanitizeBandwidth((string) ($params['upload_limit'] ?? ''), '10M');
                    $down   = $this->sanitizeBandwidth((string) ($params['download_limit'] ?? ''), '20M');
                    $limit  = "{$up}/{$down}";
                    $q = $this->apiComm($sock, ['/queue/simple/print', '?name=' . $target]);
                    if (!empty($q[0]['.id'])) {
                        $this->apiComm($sock, ['/queue/simple/set', '=.id=' . $q[0]['.id'], '=max-limit=' . $limit]);
                    } else {
                        $this->apiComm($sock, ['/queue/simple/add', '=name=' . $target, '=target=' . $target, '=max-limit=' . $limit]);
                    }
                    return ['message' => "Limit bandwidth {$target} diatur ke {$limit}.", 'meta_json' => $meta];

                case 'restart_interface':
                    $iface = $this->sanitizeIdentifier((string) ($params['interface_name'] ?? 'ether1'), 'Nama Interface');
                    $this->apiComm($sock, ['/interface/disable', '=numbers=' . $iface]);
                    usleep(300000);
                    $this->apiComm($sock, ['/interface/enable', '=numbers=' . $iface]);
                    return ['message' => "Interface {$iface} di-restart.", 'meta_json' => $meta];

                case 'ping_test':
                    $target = $this->sanitizeHostOrIp((string) ($params['target'] ?? '8.8.8.8'));
                    $pingRes = $this->apiComm($sock, ['/ping', '=address=' . $target, '=count=3']);
                    $avg = 0;
                    $loss = 0;
                    if (!empty($pingRes)) {
                        $times = [];
                        $sent = count($pingRes);
                        $ok = 0;
                        foreach ($pingRes as $row) {
                            if (!empty($row['time'])) {
                                $ok++;
                                $times[] = (int) filter_var($row['time'], FILTER_SANITIZE_NUMBER_INT);
                            }
                        }
                        $avg = !empty($times) ? (int) round(array_sum($times) / count($times)) : 0;
                        $loss = $sent > 0 ? (int) round((($sent - $ok) / $sent) * 100) : 0;
                    }
                    $meta['last_ping'] = ['target' => $target, 'avg_ms' => $avg, 'packet_loss' => $loss];
                    return ['message' => "Ping {$target}: {$avg} ms (Loss {$loss}%)", 'meta_json' => $meta, 'ping' => $meta['last_ping']];

                default:
                    throw new \RuntimeException("Aksi '{$action}' belum didukung pada protokol {$protoLabel}.");
            }
        } finally {
            fclose($sock);
        }
    }

    // =========================================================================
    // 2B. SSH REMOTE CLI (PORT 22 - MENGGUNAKAN EXT-SSH2 JIKA TERPASANG)
    // =========================================================================
    private function sshExec(array $router, string $command): string
    {
        $host = trim((string) ($router['ip_host'] ?? ''));
        $port = (int) ($router['port'] ?? 22);
        $user = (string) ($router['username'] ?? 'admin');
        $pass = (string) ($router['password'] ?? '');

        if (!function_exists('ssh2_connect')) {
            $fp = @fsockopen($host, $port, $errno, $errstr, 3);
            if (!$fp) {
                throw new \RuntimeException("SSH tidak dapat menjangkau router ({$errstr}).");
            }
            fclose($fp);
            throw new \RuntimeException(
                "Port SSH terbuka, namun ekstensi PHP 'ext-ssh2' belum aktif di server. " .
                "Silakan aktifkan ext-ssh2 atau gunakan protokol API-SSL (8729) / API (8728) / REST v7 (443)."
            );
        }

        $conn = @\ssh2_connect($host, $port);
        if (!$conn) {
            throw new \RuntimeException('Gagal membuat koneksi SSH ke router.');
        }
        if (!@\ssh2_auth_password($conn, $user, $pass)) {
            throw new \RuntimeException('Autentikasi SSH MikroTik gagal (username/password salah).');
        }

        $stream = @\ssh2_exec($conn, $command);
        if (!$stream) {
            throw new \RuntimeException('Gagal mengeksekusi perintah SSH pada router.');
        }
        stream_set_blocking($stream, true);
        $output = stream_get_contents($stream);
        fclose($stream);

        return trim((string) $output);
    }

    private function parseSshKeyValue(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (preg_match('/^\s*([a-zA-Z0-9\-]+)\s*:\s*(.+)$/', $line, $m)) {
                $out[strtolower(trim($m[1]))] = trim($m[2]);
            }
        }
        return $out;
    }

    private function fetchResourceSsh(array $router): array
    {
        $raw = $this->sshExec($router, '/system resource print without-paging');
        $kv = $this->parseSshKeyValue($raw);

        return [
            'online'     => true,
            'model'      => $kv['board-name'] ?? ($router['model'] ?: 'MikroTik'),
            'os_version' => $kv['version'] ?? ($router['os_version'] ?: 'RouterOS'),
            'cpu'        => trim(($kv['cpu-count'] ?? '1') . ' Core, ' . ($kv['cpu-frequency'] ?? '-')),
            'cpu_load'   => (int) filter_var($kv['cpu-load'] ?? '0', FILTER_SANITIZE_NUMBER_INT),
            'memory'     => $kv['total-memory'] ?? ($router['memory_info'] ?: '-'),
            'free_mem'   => $kv['free-memory'] ?? '-',
            'uptime'     => $kv['uptime'] ?? '-',
            'message'    => 'Terhubung ke MikroTik via SSH Port ' . ($router['port'] ?? 22),
        ];
    }

    private function fetchMonitoringSsh(array $router, array $meta): array
    {
        $resource = $this->fetchResourceSsh($router);
        $bwIn  = (float) ($meta['bandwidth_in'] ?? 0);
        $bwOut = (float) ($meta['bandwidth_out'] ?? 0);

        return [
            'router_info'      => $resource,
            'internet_enabled' => (bool) ($meta['internet_enabled'] ?? true),
            'active_users'     => (int) ($meta['active_users'] ?? 0),
            'bandwidth_in'     => $bwIn,
            'bandwidth_out'    => $bwOut,
            'traffic_history'  => $meta['traffic_history'] ?? [],
            'pppoe_users'      => [],
            'dhcp_leases'      => [],
            'hotspot_active'   => [],
            'interfaces'       => [],
            'queues'           => [],
            'ping'             => $meta['last_ping'] ?? ['target' => '-', 'avg_ms' => 0, 'packet_loss' => 0],
            'system_logs'      => [],
            'blocked_sites'    => $meta['blocked_sites'] ?? [],
            'meta_json'        => $meta,
        ];
    }

    private function executeSshAction(array $router, array $meta, string $action, array $params): array
    {
        $quote = fn(string $v) => '"' . str_replace(['"', '$', '`', ';', '[', ']', '\\', "\r", "\n"], '', $v) . '"';

        switch ($action) {
            case 'restart_router':
                $this->sshExec($router, '/system reboot');
                return ['message' => 'Perintah restart dikirim via SSH.', 'meta_json' => $meta];

            case 'disable_pppoe':
            case 'block_customer':
                $u = $this->sanitizeIdentifier((string) ($params['username'] ?? ''), 'Username PPPoE');
                $this->sshExec($router, "/ppp secret disable [find name={$quote($u)}]");
                $this->sshExec($router, "/ppp active remove [find name={$quote($u)}]");
                return ['message' => "PPPoE {$u} dinonaktifkan via SSH.", 'meta_json' => $meta];

            case 'enable_pppoe':
            case 'activate_customer':
                $u = $this->sanitizeIdentifier((string) ($params['username'] ?? ''), 'Username PPPoE');
                $this->sshExec($router, "/ppp secret enable [find name={$quote($u)}]");
                return ['message' => "PPPoE {$u} diaktifkan via SSH.", 'meta_json' => $meta];

            case 'disconnect_user':
                $u = $this->sanitizeIdentifier((string) ($params['username'] ?? ''), 'Username PPPoE');
                $this->sshExec($router, "/ppp active remove [find name={$quote($u)}]");
                return ['message' => "Sesi {$u} diputus via SSH.", 'meta_json' => $meta];

            case 'reset_user':
                $rawUser = trim((string) ($params['username'] ?? 'all'));
                $u = $rawUser === 'all' ? 'all' : $this->sanitizeIdentifier($rawUser, 'Username PPPoE');
                $cmd = $u === 'all' ? '/ppp active remove [find]' : "/ppp active remove [find name={$quote($u)}]";
                $this->sshExec($router, $cmd);
                return ['message' => $u === 'all' ? 'Seluruh sesi di-reset via SSH.' : "Sesi {$u} di-reset via SSH.", 'meta_json' => $meta];

            case 'limit_bandwidth':
                $target = $this->sanitizeIdentifier((string) ($params['target'] ?? $params['username'] ?? ''), 'Target');
                $up     = $this->sanitizeBandwidth((string) ($params['upload_limit'] ?? ''), '10M');
                $down   = $this->sanitizeBandwidth((string) ($params['download_limit'] ?? ''), '20M');
                $limit  = "{$up}/{$down}";
                $this->sshExec(
                    $router,
                    ":if ([:len [/queue simple find name={$quote($target)}]] > 0) do={/queue simple set [find name={$quote($target)}] max-limit={$limit}} else={/queue simple add name={$quote($target)} target={$quote($target)} max-limit={$limit}}"
                );
                return ['message' => "Limit bandwidth {$target} diatur ke {$limit} via SSH.", 'meta_json' => $meta];

            case 'restart_interface':
                $iface = $this->sanitizeIdentifier((string) ($params['interface_name'] ?? 'ether1'), 'Nama Interface');
                $this->sshExec($router, "/interface disable {$quote($iface)}; :delay 300ms; /interface enable {$quote($iface)}");
                return ['message' => "Interface {$iface} di-restart via SSH.", 'meta_json' => $meta];

            default:
                throw new \RuntimeException("Aksi '{$action}' belum didukung pada protokol SSH.");
        }
    }

    private function decodeMeta(?string $json): array
    {
        if (empty($json)) return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function safeCall(callable $fn, $fallback)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return $fallback;
        }
    }
}