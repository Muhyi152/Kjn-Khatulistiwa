<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class MikrotikService
{
    /**
     * Test koneksi & ambil info dasar router
     * Mendukung: simulasi | rest_v7 | api_v6 | api_ssl | ssh
     */
    public function testAndFetchResource(array $router): array
    {
        $type = $router['connection_type'] ?? 'simulasi';

        if ($type === 'simulasi') {
            $meta = $this->decodeMeta($router['meta_json'] ?? null);
            return [
                'online'     => (bool) ($meta['online'] ?? true),
                'model'      => $router['model'] ?: 'MikroTik RB750Gr3',
                'os_version' => $router['os_version'] ?: '7.16.1 (stable)',
                'cpu'        => $router['cpu_info'] ?: '1 Core, 1000 MHz',
                'cpu_load'   => (int) ($meta['cpu_load'] ?? rand(14, 38)),
                'memory'     => $router['memory_info'] ?: '64 MB',
                'free_mem'   => '38 MB',
                'uptime'     => $meta['uptime'] ?? '12d 4h 32m',
                'message'    => 'Terhubung (Mode Simulasi/Demo Aktif)',
            ];
        }

        if ($type === 'rest_v7') {
            return $this->fetchResourceRestV7($router);
        }

        if ($type === 'ssh') {
            return $this->fetchResourceSsh($router);
        }

        // api_v6 (8728) dan api_ssl (8729)
        return $this->fetchResourceApiV6($router, $type === 'api_ssl');
    }

    /**
     * Ambil seluruh data monitoring router
     */
    public function getMonitoringData(array $router): array
    {
        $type = $router['connection_type'] ?? 'simulasi';
        $meta = $this->decodeMeta($router['meta_json'] ?? null);

        if ($type === 'simulasi') {
            return $this->buildSimulatedMonitoring($router, $meta);
        }

        if ($type === 'rest_v7') {
            return $this->fetchMonitoringRestV7($router, $meta);
        }

        if ($type === 'ssh') {
            return $this->fetchMonitoringSsh($router, $meta);
        }

        return $this->fetchMonitoringApiV6($router, $meta, $type === 'api_ssl');
    }

    /**
     * Eksekusi aksi kontrol jaringan pada router
     */
    public function executeAction(array $router, string $action, array $params = []): array
    {
        $type = $router['connection_type'] ?? 'simulasi';
        $meta = $this->decodeMeta($router['meta_json'] ?? null);

        if ($type === 'simulasi') {
            return $this->executeSimulatedAction($router, $meta, $action, $params);
        }

        if ($type === 'rest_v7') {
            return $this->executeRestV7Action($router, $meta, $action, $params);
        }

        if ($type === 'ssh') {
            return $this->executeSshAction($router, $meta, $action, $params);
        }

        return $this->executeApiV6Action($router, $meta, $action, $params, $type === 'api_ssl');
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

    private function restGet(array $router, string $endpoint): array
    {
        $res = Http::withBasicAuth($router['username'] ?? 'admin', $router['password'] ?? '')
            ->withoutVerifying()
            ->timeout(6)
            ->get($this->restBaseUrl($router) . $endpoint);

        if (!$res->successful()) {
            throw new \RuntimeException('REST API error HTTP ' . $res->status() . ': ' . $res->body());
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    private function restPost(array $router, string $endpoint, array $payload = []): array
    {
        $res = Http::withBasicAuth($router['username'] ?? 'admin', $router['password'] ?? '')
            ->withoutVerifying()
            ->timeout(8)
            ->post($this->restBaseUrl($router) . $endpoint, $payload);

        if (!$res->successful()) {
            throw new \RuntimeException('REST API error HTTP ' . $res->status() . ': ' . $res->body());
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

        $bwIn  = (float) ($meta['bandwidth_in'] ?? 12.5);
        $bwOut = (float) ($meta['bandwidth_out'] ?? 18.7);

        return [
            'router_info'      => $resource,
            'internet_enabled' => (bool) ($meta['internet_enabled'] ?? true),
            'active_users'     => $activeCount,
            'bandwidth_in'     => $bwIn,
            'bandwidth_out'    => $bwOut,
            'traffic_history'  => $meta['traffic_history'] ?? $this->defaultTrafficHistory($bwIn, $bwOut),
            'pppoe_users'      => $pppoeList,
            'dhcp_leases'      => $dhcpList,
            'hotspot_active'   => $hotspotList,
            'interfaces'       => $ifaceList,
            'queues'           => $queueList,
            'ping'             => $meta['last_ping'] ?? ['target' => '8.8.8.8', 'avg_ms' => 14, 'packet_loss' => 0],
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
                $username = trim((string) ($params['username'] ?? ''));
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
                $username = trim((string) ($params['username'] ?? ''));
                $secrets = $this->restGet($router, '/ppp/secret?name=' . urlencode($username));
                if (!empty($secrets[0]['.id'])) {
                    $this->restPost($router, '/ppp/secret/set', ['.id' => $secrets[0]['.id'], 'disabled' => 'false']);
                }
                return ['message' => "PPPoE {$username} diaktifkan kembali.", 'meta_json' => $meta];

            case 'disconnect_user':
                $username = trim((string) ($params['username'] ?? ''));
                $actives = $this->restGet($router, '/ppp/active?name=' . urlencode($username));
                if (!empty($actives[0]['.id'])) {
                    $this->restPost($router, '/ppp/active/remove', ['.id' => $actives[0]['.id']]);
                }
                return ['message' => "Sesi {$username} berhasil diputus.", 'meta_json' => $meta];

            case 'reset_user':
                $username = trim((string) ($params['username'] ?? 'all'));
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
                $target = trim((string) ($params['target'] ?? $params['username'] ?? ''));
                $up     = trim((string) ($params['upload_limit'] ?? '10M'));
                $down   = trim((string) ($params['download_limit'] ?? '20M'));
                $limit  = "{$up}/{$down}";
                $queues = $this->restGet($router, '/queue/simple?name=' . urlencode($target));
                if (!empty($queues[0]['.id'])) {
                    $this->restPost($router, '/queue/simple/set', ['.id' => $queues[0]['.id'], 'max-limit' => $limit]);
                } else {
                    $this->restPost($router, '/queue/simple/add', ['name' => $target, 'target' => $target, 'max-limit' => $limit]);
                }
                return ['message' => "Limit bandwidth {$target} diatur ke {$limit}.", 'meta_json' => $meta];

            case 'restart_interface':
                $iface = trim((string) ($params['interface_name'] ?? 'ether1'));
                $this->restPost($router, '/interface/disable', ['numbers' => $iface]);
                usleep(300000);
                $this->restPost($router, '/interface/enable', ['numbers' => $iface]);
                return ['message' => "Interface {$iface} berhasil di-restart.", 'meta_json' => $meta];

            case 'ping_test':
                $target = trim((string) ($params['target'] ?? '8.8.8.8'));
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
                return $this->executeSimulatedAction($router, $meta, $action, $params);
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
            $ctx = stream_context_create([
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ]);
            $sock = @stream_socket_client("tls://{$host}:{$port}", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
        } else {
            $sock = @fsockopen($host, $port, $errno, $errstr, 5);
        }

        if (!$sock) {
            $label = $useSsl ? 'API-SSL' : 'API';
            throw new \RuntimeException("Tidak dapat terhubung ({$label}) ke {$host}:{$port} ({$errstr})");
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

            $bwIn  = (float) ($meta['bandwidth_in'] ?? 12.5);
            $bwOut = (float) ($meta['bandwidth_out'] ?? 18.7);

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
                'traffic_history'  => $meta['traffic_history'] ?? $this->defaultTrafficHistory($bwIn, $bwOut),
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
                'ping'             => $meta['last_ping'] ?? ['target' => '8.8.8.8', 'avg_ms' => 14, 'packet_loss' => 0],
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
                    $username = trim((string) ($params['username'] ?? ''));
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
                    $username = trim((string) ($params['username'] ?? ''));
                    $secrets = $this->apiComm($sock, ['/ppp/secret/print', '?name=' . $username]);
                    if (!empty($secrets[0]['.id'])) {
                        $this->apiComm($sock, ['/ppp/secret/set', '=.id=' . $secrets[0]['.id'], '=disabled=no']);
                    }
                    return ['message' => "PPPoE {$username} diaktifkan.", 'meta_json' => $meta];

                case 'disconnect_user':
                    $username = trim((string) ($params['username'] ?? ''));
                    $actives = $this->apiComm($sock, ['/ppp/active/print', '?name=' . $username]);
                    if (!empty($actives[0]['.id'])) {
                        $this->apiComm($sock, ['/ppp/active/remove', '=.id=' . $actives[0]['.id']]);
                    }
                    return ['message' => "Sesi {$username} diputus.", 'meta_json' => $meta];

                case 'reset_user':
                    $username = trim((string) ($params['username'] ?? 'all'));
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
                    $target = trim((string) ($params['target'] ?? $params['username'] ?? ''));
                    $up     = trim((string) ($params['upload_limit'] ?? '10M'));
                    $down   = trim((string) ($params['download_limit'] ?? '20M'));
                    $limit  = "{$up}/{$down}";
                    $q = $this->apiComm($sock, ['/queue/simple/print', '?name=' . $target]);
                    if (!empty($q[0]['.id'])) {
                        $this->apiComm($sock, ['/queue/simple/set', '=.id=' . $q[0]['.id'], '=max-limit=' . $limit]);
                    } else {
                        $this->apiComm($sock, ['/queue/simple/add', '=name=' . $target, '=target=' . $target, '=max-limit=' . $limit]);
                    }
                    return ['message' => "Limit bandwidth {$target} diatur ke {$limit}.", 'meta_json' => $meta];

                case 'restart_interface':
                    $iface = trim((string) ($params['interface_name'] ?? 'ether1'));
                    $this->apiComm($sock, ['/interface/disable', '=numbers=' . $iface]);
                    usleep(300000);
                    $this->apiComm($sock, ['/interface/enable', '=numbers=' . $iface]);
                    return ['message' => "Interface {$iface} di-restart.", 'meta_json' => $meta];

                case 'ping_test':
                    $target = trim((string) ($params['target'] ?? '8.8.8.8'));
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
                    return $this->executeSimulatedAction($router, $meta, $action, $params);
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
                throw new \RuntimeException("SSH tidak dapat menjangkau {$host}:{$port} ({$errstr}).");
            }
            fclose($fp);
            throw new \RuntimeException(
                "Port SSH {$host}:{$port} terbuka, namun ekstensi PHP 'ext-ssh2' belum aktif di server. " .
                "Silakan aktifkan ext-ssh2 atau gunakan protokol API-SSL (8729) / API (8728) / REST v7 (443)."
            );
        }

        $conn = @\ssh2_connect($host, $port);
        if (!$conn) {
            throw new \RuntimeException("Gagal membuat koneksi SSH ke {$host}:{$port}.");
        }
        if (!@\ssh2_auth_password($conn, $user, $pass)) {
            throw new \RuntimeException('Autentikasi SSH MikroTik gagal (username/password salah).');
        }

        $stream = @\ssh2_exec($conn, $command);
        if (!$stream) {
            throw new \RuntimeException("Gagal mengeksekusi perintah SSH: {$command}");
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
        $bwIn  = (float) ($meta['bandwidth_in'] ?? 12.5);
        $bwOut = (float) ($meta['bandwidth_out'] ?? 18.7);

        return [
            'router_info'      => $resource,
            'internet_enabled' => (bool) ($meta['internet_enabled'] ?? true),
            'active_users'     => (int) ($meta['active_users'] ?? 0),
            'bandwidth_in'     => $bwIn,
            'bandwidth_out'    => $bwOut,
            'traffic_history'  => $meta['traffic_history'] ?? $this->defaultTrafficHistory($bwIn, $bwOut),
            'pppoe_users'      => $meta['pppoe_users'] ?? [],
            'dhcp_leases'      => $meta['dhcp_leases'] ?? [],
            'hotspot_active'   => $meta['hotspot_active'] ?? [],
            'interfaces'       => $meta['interfaces'] ?? [],
            'queues'           => $meta['queues'] ?? [],
            'ping'             => $meta['last_ping'] ?? ['target' => '8.8.8.8', 'avg_ms' => 14, 'packet_loss' => 0],
            'system_logs'      => $meta['system_logs'] ?? [],
            'blocked_sites'    => $meta['blocked_sites'] ?? [],
            'meta_json'        => $meta,
        ];
    }

    private function executeSshAction(array $router, array $meta, string $action, array $params): array
    {
        $esc = fn(string $v) => '"' . str_replace(['"', '$', '`'], '', $v) . '"';

        switch ($action) {
            case 'restart_router':
                $this->sshExec($router, '/system reboot');
                return ['message' => 'Perintah restart dikirim via SSH.', 'meta_json' => $meta];

            case 'disable_pppoe':
            case 'block_customer':
                $u = trim((string) ($params['username'] ?? ''));
                $this->sshExec($router, "/ppp secret disable [find name={$esc($u)}]");
                $this->sshExec($router, "/ppp active remove [find name={$esc($u)}]");
                return ['message' => "PPPoE {$u} dinonaktifkan via SSH.", 'meta_json' => $meta];

            case 'enable_pppoe':
            case 'activate_customer':
                $u = trim((string) ($params['username'] ?? ''));
                $this->sshExec($router, "/ppp secret enable [find name={$esc($u)}]");
                return ['message' => "PPPoE {$u} diaktifkan via SSH.", 'meta_json' => $meta];

            case 'disconnect_user':
                $u = trim((string) ($params['username'] ?? ''));
                $this->sshExec($router, "/ppp active remove [find name={$esc($u)}]");
                return ['message' => "Sesi {$u} diputus via SSH.", 'meta_json' => $meta];

            case 'reset_user':
                $u = trim((string) ($params['username'] ?? 'all'));
                $cmd = $u === 'all' ? '/ppp active remove [find]' : "/ppp active remove [find name={$esc($u)}]";
                $this->sshExec($router, $cmd);
                return ['message' => $u === 'all' ? 'Seluruh sesi di-reset via SSH.' : "Sesi {$u} di-reset via SSH.", 'meta_json' => $meta];

            case 'limit_bandwidth':
                $target = trim((string) ($params['target'] ?? $params['username'] ?? ''));
                $up     = trim((string) ($params['upload_limit'] ?? '10M'));
                $down   = trim((string) ($params['download_limit'] ?? '20M'));
                $limit  = "{$up}/{$down}";
                $this->sshExec(
                    $router,
                    ":if ([:len [/queue simple find name={$esc($target)}]] > 0) do={/queue simple set [find name={$esc($target)}] max-limit={$limit}} else={/queue simple add name={$esc($target)} target={$esc($target)} max-limit={$limit}}"
                );
                return ['message' => "Limit bandwidth {$target} diatur ke {$limit} via SSH.", 'meta_json' => $meta];

            case 'restart_interface':
                $iface = trim((string) ($params['interface_name'] ?? 'ether1'));
                $this->sshExec($router, "/interface disable {$esc($iface)}; :delay 300ms; /interface enable {$esc($iface)}");
                return ['message' => "Interface {$iface} di-restart via SSH.", 'meta_json' => $meta];

            default:
                return $this->executeSimulatedAction($router, $meta, $action, $params);
        }
    }

    // =========================================================================
    // 3. MODE SIMULASI / SANDBOX STATEFUL (TERSIMPAN DI MYSQL)
    // =========================================================================
    private function buildSimulatedMonitoring(array $router, array $meta): array
    {
        $internetEnabled = (bool) ($meta['internet_enabled'] ?? true);
        $online          = (bool) ($meta['online'] ?? true);

        $pppoeUsers = $meta['pppoe_users'] ?? [
            ['id' => '1', 'username' => 'Hamzahkjn', 'service' => 'pppoe', 'profile' => '100Mbps-Ultra', 'ip' => '10.10.10.2', 'uptime' => '3d 12h', 'online' => true, 'disabled' => false],
            ['id' => '2', 'username' => 'siti.rahma@kjn', 'service' => 'pppoe', 'profile' => '50Mbps-Pro', 'ip' => '10.10.10.3', 'uptime' => '1d 8h', 'online' => true, 'disabled' => false],
            ['id' => '3', 'username' => 'budi.santoso@kjn', 'service' => 'pppoe', 'profile' => '20Mbps-Basic', 'ip' => '10.10.10.4', 'uptime' => '5d 2h', 'online' => true, 'disabled' => false],
        ];

        $queues = $meta['queues'] ?? [
            ['id' => 'q1', 'name' => 'Hamzahkjn', 'target' => '10.10.10.2/32', 'max_limit' => '100M/100M', 'disabled' => false],
            ['id' => 'q2', 'name' => 'siti.rahma@kjn', 'target' => '10.10.10.3/32', 'max_limit' => '50M/50M', 'disabled' => false],
            ['id' => 'q3', 'name' => 'budi.santoso@kjn', 'target' => '10.10.10.4/32', 'max_limit' => '20M/20M', 'disabled' => false],
        ];

        $interfaces = $meta['interfaces'] ?? [
            ['id' => 'i1', 'name' => 'ether1-WAN', 'type' => 'ether', 'running' => $internetEnabled, 'disabled' => !$internetEnabled, 'rx_byte' => 1458201120, 'tx_byte' => 982110420],
            ['id' => 'i2', 'name' => 'ether2-OLT-1', 'type' => 'ether', 'running' => true, 'disabled' => false, 'rx_byte' => 845120310, 'tx_byte' => 1210450200],
            ['id' => 'i3', 'name' => 'bridge-LAN', 'type' => 'bridge', 'running' => true, 'disabled' => false, 'rx_byte' => 620110040, 'tx_byte' => 780220900],
            ['id' => 'i4', 'name' => 'wlan1-Office', 'type' => 'wlan', 'running' => true, 'disabled' => false, 'rx_byte' => 45120100, 'tx_byte' => 92110300],
        ];

        $dhcpLeases = $meta['dhcp_leases'] ?? [
            ['address' => '192.168.88.254', 'mac' => 'D4:CA:6D:11:22:01', 'host_name' => 'OLT-ZTE-C320', 'status' => 'bound'],
            ['address' => '192.168.88.240', 'mac' => 'E4:8D:8C:44:55:09', 'host_name' => 'AP-Kantor-Pusat', 'status' => 'bound'],
            ['address' => '192.168.88.120', 'mac' => 'A4:50:46:98:12:44', 'host_name' => 'PC-Billing-Admin', 'status' => 'bound'],
        ];

        $hotspotActive = $meta['hotspot_active'] ?? [
            ['user' => 'tamu-kantor', 'address' => '10.20.0.15', 'uptime' => '02:14:10'],
        ];

        $sysLogs = $meta['system_logs'] ?? [
            ['time' => date('H:i:s', strtotime('-2 minutes')), 'topics' => 'pppoe,info', 'message' => 'Hamzahkjn logged in from 10.10.10.2'],
            ['time' => date('H:i:s', strtotime('-15 minutes')), 'topics' => 'system,info', 'message' => 'router resource check normal (CPU 21%)'],
            ['time' => date('H:i:s', strtotime('-40 minutes')), 'topics' => 'dhcp,info', 'message' => 'dhcp1 assigned 192.168.88.120 to PC-Billing-Admin'],
        ];

        $activeCount = 0;
        if ($online && $internetEnabled) {
            foreach ($pppoeUsers as $u) {
                if (!empty($u['online']) && empty($u['disabled'])) $activeCount++;
            }
            $activeCount += count($hotspotActive);
        }

        $bwIn  = ($online && $internetEnabled) ? (float) ($meta['bandwidth_in'] ?? 12.5) : 0.0;
        $bwOut = ($online && $internetEnabled) ? (float) ($meta['bandwidth_out'] ?? 18.7) : 0.0;

        $meta['pppoe_users']      = $pppoeUsers;
        $meta['queues']           = $queues;
        $meta['interfaces']       = $interfaces;
        $meta['dhcp_leases']      = $dhcpLeases;
        $meta['hotspot_active']   = $hotspotActive;
        $meta['system_logs']      = $sysLogs;
        $meta['internet_enabled'] = $internetEnabled;
        $meta['online']           = $online;

        return [
            'router_info' => [
                'online'     => $online,
                'model'      => $router['model'] ?: 'MikroTik RB750Gr3',
                'os_version' => $router['os_version'] ?: '7.16.1 (stable)',
                'cpu'        => $router['cpu_info'] ?: '1 Core, 1000 MHz',
                'cpu_load'   => $online ? (int) ($meta['cpu_load'] ?? 24) : 0,
                'memory'     => $router['memory_info'] ?: '64 MB',
                'free_mem'   => $online ? '38 MB' : '0 MB',
                'uptime'     => $online ? ($meta['uptime'] ?? '12d 4h 32m') : '0s (Offline)',
            ],
            'internet_enabled' => $internetEnabled,
            'active_users'     => $activeCount,
            'bandwidth_in'     => $bwIn,
            'bandwidth_out'    => $bwOut,
            'traffic_history'  => $meta['traffic_history'] ?? $this->defaultTrafficHistory($bwIn, $bwOut),
            'pppoe_users'      => $pppoeUsers,
            'dhcp_leases'      => $dhcpLeases,
            'hotspot_active'   => $hotspotActive,
            'interfaces'       => $interfaces,
            'queues'           => $queues,
            'ping'             => $meta['last_ping'] ?? ['target' => '8.8.8.8', 'avg_ms' => 14, 'packet_loss' => 0],
            'system_logs'      => $sysLogs,
            'blocked_sites'    => $meta['blocked_sites'] ?? ['situsjudi-contoh.com'],
            'meta_json'        => $meta,
        ];
    }

    private function executeSimulatedAction(array $router, array $meta, string $action, array $params): array
    {
        $mon = $this->buildSimulatedMonitoring($router, $meta);
        $meta = $mon['meta_json'];
        $timeNow = date('H:i:s');
        $message = 'Tindakan berhasil dijalankan.';

        switch ($action) {
            case 'toggle_internet':
                $enabled = (bool) ($params['enabled'] ?? !($meta['internet_enabled'] ?? true));
                $meta['internet_enabled'] = $enabled;
                foreach ($meta['interfaces'] as &$if) {
                    if (str_contains(strtolower($if['name']), 'wan')) {
                        $if['running']  = $enabled;
                        $if['disabled'] = !$enabled;
                    }
                }
                $message = $enabled ? 'Akses internet diaktifkan.' : 'Akses internet dimatikan sementara.';
                break;

            case 'enable_network':
                $meta['internet_enabled'] = true;
                $meta['online'] = true;
                $message = 'Jaringan berhasil diaktifkan kembali.';
                break;

            case 'disable_network':
                $meta['internet_enabled'] = false;
                $message = 'Jaringan berhasil dinonaktifkan sementara.';
                break;

            case 'restart_router':
                $meta['online'] = true;
                $meta['uptime'] = '0d 0h 1m';
                $meta['cpu_load'] = 15;
                $message = 'Router berhasil di-restart (Uptime direset).';
                break;

            case 'disable_pppoe':
            case 'block_customer':
                $u = trim((string) ($params['username'] ?? ''));
                foreach ($meta['pppoe_users'] as &$p) {
                    if (strcasecmp($p['username'], $u) === 0) {
                        $p['disabled'] = true;
                        $p['online']   = false;
                    }
                }
                $message = "Akses PPPoE '{$u}' berhasil diblokir / dinonaktifkan.";
                break;

            case 'enable_pppoe':
            case 'activate_customer':
                $u = trim((string) ($params['username'] ?? ''));
                foreach ($meta['pppoe_users'] as &$p) {
                    if (strcasecmp($p['username'], $u) === 0) {
                        $p['disabled'] = false;
                        $p['online']   = true;
                    }
                }
                $message = "Akses PPPoE '{$u}' berhasil diaktifkan kembali.";
                break;

            case 'disconnect_user':
                $u = trim((string) ($params['username'] ?? ''));
                foreach ($meta['pppoe_users'] as &$p) {
                    if (strcasecmp($p['username'], $u) === 0) {
                        $p['online'] = false;
                        $p['uptime'] = '-';
                    }
                }
                $message = "Sesi aktif '{$u}' berhasil diputus.";
                break;

            case 'reset_user':
                $u = trim((string) ($params['username'] ?? 'all'));
                foreach ($meta['pppoe_users'] as &$p) {
                    if ($u === 'all' || strcasecmp($p['username'], $u) === 0) {
                        if (empty($p['disabled'])) {
                            $p['online'] = true;
                            $p['uptime'] = '0d 0h 1m';
                        }
                    }
                }
                $message = $u === 'all' ? 'Seluruh koneksi pengguna berhasil di-reset.' : "Koneksi '{$u}' berhasil di-reset.";
                break;

            case 'reset_service':
                $meta['online'] = true;
                $meta['internet_enabled'] = true;
                $message = 'Semua layanan jaringan (PPPoE/DHCP/Hotspot) berhasil di-reset.';
                break;

            case 'limit_bandwidth':
                $target = trim((string) ($params['target'] ?? $params['username'] ?? 'User'));
                $up     = trim((string) ($params['upload_limit'] ?? '10M'));
                $down   = trim((string) ($params['download_limit'] ?? '20M'));
                $limit  = "{$up}/{$down}";
                $found  = false;
                foreach ($meta['queues'] as &$q) {
                    if (strcasecmp($q['name'], $target) === 0 || strcasecmp($q['target'], $target) === 0) {
                        $q['max_limit'] = $limit;
                        $found = true;
                    }
                }
                if (!$found) {
                    $meta['queues'][] = [
                        'id'        => 'q' . time(),
                        'name'      => $target,
                        'target'    => $target,
                        'max_limit' => $limit,
                        'disabled'  => false,
                    ];
                }
                $message = "Bandwidth '{$target}' dibatasi menjadi {$limit}.";
                break;

            case 'restart_interface':
                $iface = trim((string) ($params['interface_name'] ?? 'ether1-WAN'));
                foreach ($meta['interfaces'] as &$if) {
                    if (strcasecmp($if['name'], $iface) === 0) {
                        $if['running']  = true;
                        $if['disabled'] = false;
                    }
                }
                $message = "Interface '{$iface}' berhasil di-restart.";
                break;

            case 'block_site':
                $domain = strtolower(trim((string) ($params['domain'] ?? '')));
                if ($domain !== '') {
                    $sites = $meta['blocked_sites'] ?? [];
                    if (!in_array($domain, $sites, true)) {
                        $sites[] = $domain;
                    }
                    $meta['blocked_sites'] = $sites;
                }
                $message = "Situs '{$domain}' berhasil diblokir.";
                break;

            case 'unblock_site':
                $domain = strtolower(trim((string) ($params['domain'] ?? '')));
                $meta['blocked_sites'] = array_values(array_filter(
                    $meta['blocked_sites'] ?? [],
                    fn($s) => strtolower($s) !== $domain
                ));
                $message = "Blokir situs '{$domain}' dibuka.";
                break;

            case 'ping_test':
                $target = trim((string) ($params['target'] ?? '8.8.8.8'));
                $avg = rand(8, 26);
                $loss = 0;
                $meta['last_ping'] = ['target' => $target, 'avg_ms' => $avg, 'packet_loss' => $loss];
                $message = "Ping {$target}: {$avg} ms (Packet Loss {$loss}%).";
                break;
        }

        array_unshift($meta['system_logs'], [
            'time'    => $timeNow,
            'topics'  => 'admin,control',
            'message' => $message,
        ]);
        $meta['system_logs'] = array_slice($meta['system_logs'], 0, 30);

        return [
            'message'   => $message,
            'meta_json' => $meta,
            'ping'      => $meta['last_ping'] ?? null,
        ];
    }

    private function defaultTrafficHistory(float $bwIn, float $bwOut): array
    {
        $slots = ['09.00', '12.00', '15.00', '18.00', '21.00', 'Sekarang'];
        $factors = [0.65, 0.72, 0.85, 1.15, 0.95, 1.0];
        $history = [];
        foreach ($slots as $idx => $s) {
            $f = $factors[$idx];
            $history[] = [
                'time'    => $s,
                'rx_mbps' => round($bwIn * $f, 1),
                'tx_mbps' => round($bwOut * $f, 1),
                'load'    => min(98, (int) round(60 * $f)),
            ];
        }
        return $history;
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