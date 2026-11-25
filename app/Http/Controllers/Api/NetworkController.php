<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MikrotikService;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class NetworkController extends Controller
{
    private const ALLOWED_CONN_TYPES = ['api_v6', 'api_ssl', 'rest_v7', 'ssh'];
    private const ALLOWED_AUTH_MODES = ['campuran', 'pppoe', 'hotspot', 'dhcp', 'radius', 'ipoe'];

    public function __construct(private MikrotikService $mikrotik)
    {
    }

    private function safeErrorMessage(\Throwable $e, string $fallback = 'Terjadi kesalahan pada server.'): string
    {
        return config('app.debug') ? $e->getMessage() : $fallback;
    }

    private function currentAdminName(): string
    {
        $user = Auth::user();
        return ($user && !empty($user->name)) ? (string) $user->name : 'Admin';
    }

    private function validateHostNotInternal(string $rawHost): void
    {
        $clean = trim($rawHost);
        if ($clean === '' || preg_match('/[\s@?#\\\\]/', $clean)) {
            throw new \InvalidArgumentException('Format IP/Host tidak valid.');
        }

        $bareHost = preg_replace('#^https?://#i', '', $clean);
        $bareHost = explode('/', $bareHost)[0];
        $bareHost = explode(':', $bareHost)[0];

        $lower = strtolower($bareHost);
        if ($bareHost === '' || in_array($lower, ['localhost', 'metadata.google.internal', 'metadata'], true) || str_ends_with($lower, '.local')) {
            throw new \InvalidArgumentException('Host lokal atau metadata cloud tidak diizinkan.');
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
    }

    private function ensureTables(): void
    {
        if (!Schema::hasTable('routers')) {
            Schema::create('routers', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('location')->nullable();
                $table->string('ip_host');
                $table->integer('port')->default(8728);
                $table->string('username')->default('admin');
                $table->text('password_encrypted')->nullable();
                $table->string('connection_type', 30)->default('api_v6');
                $table->string('auth_mode', 30)->default('pppoe');
                $table->string('model')->nullable();
                $table->string('os_version')->nullable();
                $table->string('cpu_info')->nullable();
                $table->string('memory_info')->nullable();
                $table->boolean('is_online')->default(false);
                $table->longText('meta_json')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('network_activity_logs')) {
            Schema::create('network_activity_logs', function ($table) {
                $table->id();
                $table->unsignedBigInteger('router_id')->nullable();
                $table->string('router_name')->nullable();
                $table->string('admin_name')->default('Admin');
                $table->string('action', 60);
                $table->string('target')->nullable();
                $table->text('description');
                $table->string('status', 20)->default('SUCCESS');
                $table->timestamps();
            });
        }

        try {
            DB::table('routers')->where('connection_type', 'simulasi')->delete();
        } catch (\Throwable $e) {}
    }

    private function defaultPortForType(string $connType): int
    {
        return match ($connType) {
            'api_ssl' => 8729,
            'rest_v7' => 443,
            'ssh'     => 22,
            default   => 8728,
        };
    }

    private function encryptPassword(?string $plain): string
    {
        if ($plain === null || $plain === '') return '';
        return Crypt::encryptString($plain);
    }

    private function decryptPassword(?string $enc): string
    {
        if (empty($enc)) return '';
        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable $e) {
            // Fallback hanya untuk membaca data lama sebelum enkripsi aktif
            $b = base64_decode($enc, true);
            return $b !== false ? $b : '';
        }
    }

    private function routerRowToArray($row): array
    {
        return [
            'id'              => (int) $row->id,
            'name'            => $row->name,
            'location'        => $row->location,
            'ip_host'         => $row->ip_host,
            'port'            => (int) $row->port,
            'username'        => $row->username,
            'password'        => $this->decryptPassword($row->password_encrypted),
            'connection_type' => $row->connection_type,
            'auth_mode'       => $row->auth_mode,
            'model'           => $row->model,
            'os_version'      => $row->os_version,
            'cpu_info'        => $row->cpu_info,
            'memory_info'     => $row->memory_info,
            'is_online'       => (bool) $row->is_online,
            'meta_json'       => $row->meta_json,
        ];
    }

    /**
     * GET /api/admin/routers -> Daftar Multi-Router + Ringkasan Global (REAL ONLY)
     */
    public function index()
    {
        $this->ensureTables();
        $rows = DB::table('routers')->orderBy('id', 'asc')->get();

        $list = [];
        $totalActiveUsers = 0;
        $totalBwIn = 0.0;
        $totalBwOut = 0.0;
        $onlineCount = 0;

        foreach ($rows as $row) {
            $arr = $this->routerRowToArray($row);

            $isOnline = false;
            $internetEnabled = false;
            $activeUsers = 0;
            $bwIn = 0.0;
            $bwOut = 0.0;
            $uptime = '-';
            $cpuLoad = 0;

            try {
                $mon = $this->mikrotik->getMonitoringData($arr);
                $isOnline = (bool) ($mon['router_info']['online'] ?? false);
                $internetEnabled = (bool) ($mon['internet_enabled'] ?? $isOnline);
                $activeUsers = (int) ($mon['active_users'] ?? 0);
                $bwIn = (float) ($mon['bandwidth_in'] ?? 0);
                $bwOut = (float) ($mon['bandwidth_out'] ?? 0);
                $uptime = $mon['router_info']['uptime'] ?? '-';
                $cpuLoad = (int) ($mon['router_info']['cpu_load'] ?? 0);

                if ((bool) $row->is_online !== $isOnline) {
                    DB::table('routers')->where('id', $row->id)->update([
                        'is_online'  => $isOnline ? 1 : 0,
                        'updated_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {
                $isOnline = false;
                $internetEnabled = false;
                if ((bool) $row->is_online) {
                    DB::table('routers')->where('id', $row->id)->update([
                        'is_online'  => 0,
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($isOnline) $onlineCount++;
            $totalActiveUsers += $activeUsers;
            $totalBwIn += $bwIn;
            $totalBwOut += $bwOut;

            $list[] = [
                'id'               => (int) $row->id,
                'name'             => $row->name,
                'location'         => $row->location ?? '-',
                'ip_host'          => $row->ip_host,
                'port'             => (int) $row->port,
                'username'         => $row->username,
                'connection_type'  => $row->connection_type,
                'auth_mode'        => $row->auth_mode,
                'model'            => $row->model ?? 'MikroTik',
                'os_version'       => $row->os_version ?? '-',
                'cpu_info'         => $row->cpu_info ?? '-',
                'memory_info'      => $row->memory_info ?? '-',
                'is_online'        => $isOnline,
                'internet_enabled' => $internetEnabled,
                'uptime'           => $uptime,
                'cpu_load'         => $cpuLoad,
                'active_users'     => $activeUsers,
                'bandwidth_in'     => round($bwIn, 1),
                'bandwidth_out'    => round($bwOut, 1),
            ];
        }

        return response()->json([
            'success' => true,
            'summary' => [
                'total_routers'       => count($list),
                'online_routers'      => $onlineCount,
                'offline_routers'     => max(0, count($list) - $onlineCount),
                'total_active_users'  => $totalActiveUsers,
                'total_bandwidth_in'  => round($totalBwIn, 1),
                'total_bandwidth_out' => round($totalBwOut, 1),
            ],
            'data' => $list,
        ]);
    }

    /**
     * POST /api/admin/routers -> Tambah Router Baru (REAL ONLY)
     */
    public function store(Request $request)
    {
        $this->ensureTables();

        $name = mb_substr(trim((string) $request->input('name', '')), 0, 150);
        $ipHost = mb_substr(trim((string) $request->input('ip_host', '')), 0, 255);

        if ($name === '' || $ipHost === '') {
            return response()->json([
                'success' => false,
                'message' => 'Nama router dan IP Publik / DDNS wajib diisi.',
            ], 422);
        }

        try {
            $this->validateHostNotInternal($ipHost);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $connTypeInput = strtolower(trim((string) $request->input('connection_type', 'api_v6')));
        if (!in_array($connTypeInput, self::ALLOWED_CONN_TYPES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Protokol API tidak valid. Pilihan: ' . implode(', ', self::ALLOWED_CONN_TYPES),
            ], 422);
        }

        $authModeInput = strtolower(trim((string) $request->input('auth_mode', 'pppoe')));
        if (!in_array($authModeInput, self::ALLOWED_AUTH_MODES, true)) {
            $authModeInput = 'pppoe';
        }

        $port = (int) ($request->input('port') ?: $this->defaultPortForType($connTypeInput));
        if ($port < 1 || $port > 65535) {
            return response()->json(['success' => false, 'message' => 'Port harus antara 1 - 65535.'], 422);
        }

        $username = mb_substr(trim((string) $request->input('username', 'admin')), 0, 100);
        $passwordPlain = (string) $request->input('password', '');

        $payload = [
            'name'               => $name,
            'location'           => mb_substr(trim((string) $request->input('location', 'Pusat')), 0, 150),
            'ip_host'            => $ipHost,
            'port'               => $port,
            'username'           => $username,
            'password_encrypted' => $this->encryptPassword($passwordPlain),
            'connection_type'    => $connTypeInput,
            'auth_mode'          => $authModeInput,
            'model'              => mb_substr((string) $request->input('model', 'MikroTik'), 0, 100),
            'os_version'         => mb_substr((string) $request->input('os_version', '-'), 0, 100),
            'cpu_info'           => mb_substr((string) $request->input('cpu_info', '-'), 0, 100),
            'memory_info'        => mb_substr((string) $request->input('memory_info', '-'), 0, 100),
            'is_online'          => 0,
            'meta_json'          => json_encode([
                'online'           => false,
                'internet_enabled' => false,
                'bandwidth_in'     => 0.0,
                'bandwidth_out'    => 0.0,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $id = DB::table('routers')->insertGetId($payload);

        $statusNote = 'disimpan (menunggu koneksi)';
        try {
            $info = $this->mikrotik->testAndFetchResource([
                'id'              => $id,
                'name'            => $name,
                'ip_host'         => $ipHost,
                'port'            => $port,
                'username'        => $username,
                'password'        => $passwordPlain,
                'connection_type' => $connTypeInput,
                'auth_mode'       => $authModeInput,
            ]);

            if (!empty($info['online'])) {
                DB::table('routers')->where('id', $id)->update([
                    'is_online'   => 1,
                    'model'       => $info['model'] ?? 'MikroTik',
                    'os_version'  => $info['os_version'] ?? '-',
                    'cpu_info'    => $info['cpu'] ?? '-',
                    'memory_info' => $info['memory'] ?? '-',
                    'meta_json'   => json_encode([
                        'online'           => true,
                        'internet_enabled' => true,
                    ]),
                    'updated_at'  => now(),
                ]);
                $statusNote = 'terhubung Online';
            }
        } catch (\Throwable $e) {
            Log::warning("Router #{$id} belum terhubung saat store: " . $e->getMessage());
            $statusNote = 'disimpan, namun router belum dapat dihubungi'
                . (config('app.debug') ? ' (' . $e->getMessage() . ')' : '');
        }

        $this->writeAuditLog(
            $id,
            $name,
            $this->currentAdminName(),
            'add_router',
            $ipHost,
            "Menambahkan router {$name} ({$ipHost}:{$port} • {$connTypeInput} • {$authModeInput}) — {$statusNote}."
        );

        return response()->json([
            'success' => true,
            'message' => "Router {$statusNote}!",
            'id'      => $id,
        ], 201);
    }

    /**
     * PUT /api/admin/routers/{id} -> Edit Router
     */
    public function update(Request $request, $id)
    {
        $this->ensureTables();
        $row = DB::table('routers')->where('id', $id)->first();
        if (!$row) return response()->json(['success' => false, 'message' => 'Router tidak ditemukan.'], 404);

        $update = ['updated_at' => now()];

        if ($request->has('ip_host')) {
            $newHost = mb_substr(trim((string) $request->input('ip_host', '')), 0, 255);
            try {
                $this->validateHostNotInternal($newHost);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            $update['ip_host'] = $newHost;
        }

        foreach (['name', 'location', 'username', 'model', 'os_version'] as $f) {
            if ($request->has($f)) {
                $update[$f] = mb_substr(trim((string) $request->input($f)), 0, 150);
            }
        }

        if ($request->has('connection_type')) {
            $ct = strtolower(trim((string) $request->input('connection_type')));
            if (!in_array($ct, self::ALLOWED_CONN_TYPES, true)) {
                return response()->json(['success' => false, 'message' => 'Protokol API tidak valid.'], 422);
            }
            $update['connection_type'] = $ct;
        }

        if ($request->has('auth_mode')) {
            $am = strtolower(trim((string) $request->input('auth_mode')));
            if (in_array($am, self::ALLOWED_AUTH_MODES, true)) {
                $update['auth_mode'] = $am;
            }
        }

        if ($request->has('port')) {
            $port = (int) $request->input('port');
            if ($port < 1 || $port > 65535) {
                return response()->json(['success' => false, 'message' => 'Port harus antara 1 - 65535.'], 422);
            }
            $update['port'] = $port;
        }

        if ($request->filled('password')) {
            $update['password_encrypted'] = $this->encryptPassword((string) $request->input('password'));
        }

        DB::table('routers')->where('id', $id)->update($update);
        return response()->json(['success' => true, 'message' => 'Konfigurasi router diperbarui!']);
    }

    /**
     * DELETE /api/admin/routers/{id}
     */
    public function destroy($id)
    {
        $this->ensureTables();
        DB::table('routers')->where('id', $id)->delete();
        return response()->json(['success' => true, 'message' => 'Router dihapus.']);
    }

    /**
     * POST /api/admin/routers/{id}/test -> Test Koneksi ke MikroTik
     */
    public function testConnection($id)
    {
        $this->ensureTables();
        $row = DB::table('routers')->where('id', $id)->first();
        if (!$row) return response()->json(['success' => false, 'message' => 'Router tidak ditemukan.'], 404);

        try {
            $info = $this->mikrotik->testAndFetchResource($this->routerRowToArray($row));
            DB::table('routers')->where('id', $id)->update([
                'is_online'   => !empty($info['online']) ? 1 : 0,
                'model'       => $info['model'] ?? $row->model,
                'os_version'  => $info['os_version'] ?? $row->os_version,
                'cpu_info'    => $info['cpu'] ?? $row->cpu_info,
                'memory_info' => $info['memory'] ?? $row->memory_info,
                'updated_at'  => now(),
            ]);
            return response()->json(['success' => true, 'data' => $info]);
        } catch (\Throwable $e) {
            Log::warning("Test koneksi router #{$id} gagal: " . $e->getMessage());
            DB::table('routers')->where('id', $id)->update(['is_online' => 0, 'updated_at' => now()]);
            return response()->json([
                'success' => false,
                'message' => $this->safeErrorMessage($e, 'Gagal terhubung ke router. Periksa IP, port, dan kredensial.'),
            ], 400);
        }
    }

    /**
     * POST /api/admin/routers/discover -> Router Discovery (Probe IP/Port MikroTik Real)
     */
    public function discover(Request $request)
    {
        $host = trim((string) $request->input('ip_host', ''));
        $found = [];

        if ($host !== '') {
            try {
                $this->validateHostNotInternal($host);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            $bareHost = preg_replace('#^https?://#i', '', $host);
            $bareHost = explode('/', explode(':', $bareHost)[0])[0];

            $probePorts = [
                8729 => 'api_ssl',
                8728 => 'api_v6',
                443  => 'rest_v7',
                80   => 'rest_v7',
                22   => 'ssh',
            ];

            foreach ($probePorts as $port => $type) {
                $fp = @fsockopen($bareHost, $port, $errno, $errstr, 1.5);
                if ($fp) {
                    fclose($fp);
                    $found[] = [
                        'name'            => "MikroTik ({$bareHost}:{$port})",
                        'ip_host'         => $bareHost,
                        'port'            => $port,
                        'connection_type' => $type,
                        'reachable'       => true,
                    ];
                }
            }
        }

        return response()->json(['success' => true, 'data' => $found]);
    }

    /**
     * GET /api/admin/routers/{id}/monitoring -> Detail Monitoring Lengkap (REAL ONLY)
     */
    public function monitoring($id)
    {
        $this->ensureTables();
        $row = DB::table('routers')->where('id', $id)->first();
        if (!$row) return response()->json(['success' => false, 'message' => 'Router tidak ditemukan.'], 404);

        $logs = DB::table('network_activity_logs')
            ->where('router_id', $id)
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $routerMeta = [
            'id'              => (int) $row->id,
            'name'            => $row->name,
            'location'        => $row->location,
            'ip_host'         => $row->ip_host,
            'port'            => (int) $row->port,
            'connection_type' => $row->connection_type,
            'auth_mode'       => $row->auth_mode,
        ];

        try {
            $arr = $this->routerRowToArray($row);
            $data = $this->mikrotik->getMonitoringData($arr);

            if (!empty($data['meta_json'])) {
                DB::table('routers')->where('id', $id)->update([
                    'meta_json'  => json_encode($data['meta_json']),
                    'is_online'  => ($data['router_info']['online'] ?? false) ? 1 : 0,
                    'updated_at' => now(),
                ]);
            }

            $data['admin_logs']  = $logs;
            $data['router_meta'] = $routerMeta;

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            Log::warning("Monitoring router #{$id} gagal: " . $e->getMessage());
            DB::table('routers')->where('id', $id)->update([
                'is_online'  => 0,
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'data'    => [
                    'router_info' => [
                        'online'     => false,
                        'model'      => $row->model ?? 'MikroTik',
                        'os_version' => $row->os_version ?? '-',
                        'cpu'        => $row->cpu_info ?? '-',
                        'cpu_load'   => 0,
                        'memory'     => $row->memory_info ?? '-',
                        'free_mem'   => '-',
                        'uptime'     => '-',
                    ],
                    'internet_enabled' => false,
                    'active_users'     => 0,
                    'bandwidth_in'     => 0.0,
                    'bandwidth_out'    => 0.0,
                    'traffic_history'  => [],
                    'pppoe_users'      => [],
                    'dhcp_leases'      => [],
                    'hotspot_active'   => [],
                    'interfaces'       => [],
                    'queues'           => [],
                    'ping'             => ['target' => '-', 'avg_ms' => 0, 'packet_loss' => 100],
                    'system_logs'      => [
                        [
                            'time'    => now()->format('H:i:s'),
                            'topics'  => 'error',
                            'message' => $this->safeErrorMessage($e, 'Router tidak dapat diakses saat ini.'),
                        ],
                    ],
                    'blocked_sites'    => [],
                    'admin_logs'       => $logs,
                    'router_meta'      => $routerMeta,
                ],
            ]);
        }
    }

    /**
     * POST /api/admin/routers/{id}/action -> Eksekusi Kontrol Jaringan + Audit Log
     */
    public function action(Request $request, $id)
    {
        $this->ensureTables();
        $row = DB::table('routers')->where('id', $id)->first();
        if (!$row) return response()->json(['success' => false, 'message' => 'Router tidak ditemukan.'], 404);

        $action = trim((string) $request->input('action', ''));
        $adminName = $this->currentAdminName();
        $params = $request->all();

        if ($action === '') {
            return response()->json(['success' => false, 'message' => 'Parameter action wajib diisi.'], 422);
        }

        try {
            $arr = $this->routerRowToArray($row);
            $res = $this->mikrotik->executeAction($arr, $action, $params);

            if (isset($res['meta_json'])) {
                DB::table('routers')->where('id', $id)->update([
                    'meta_json'  => json_encode($res['meta_json']),
                    'updated_at' => now(),
                ]);
            }

            $targetUser = trim((string) ($params['username'] ?? $params['target'] ?? ''));
            if ($targetUser !== '' && in_array($action, ['disable_pppoe', 'block_customer', 'enable_pppoe', 'activate_customer'], true)) {
                $isBlock = in_array($action, ['disable_pppoe', 'block_customer'], true);
                try {
                    $q = Customer::query();
                    if (Schema::hasColumn('customers', 'pppoe_username')) {
                        $q->where('pppoe_username', $targetUser);
                    }
                    if (Schema::hasColumn('customers', 'username_pppoe')) {
                        $q->orWhere('username_pppoe', $targetUser);
                    }
                    $cust = $q->first();
                    if ($cust) {
                        $upd = [];
                        if (Schema::hasColumn('customers', 'status')) $upd['status'] = $isBlock ? 'ISOLIR' : 'ACTIVE';
                        if (Schema::hasColumn('customers', 'status_koneksi')) $upd['status_koneksi'] = $isBlock ? 'Offline' : 'Online';
                        if (!empty($upd)) $cust->update($upd);
                    }
                } catch (\Throwable $e) {}
            }

            $targetDesc = $targetUser ?: ($params['interface_name'] ?? ($params['domain'] ?? ($params['target'] ?? $row->name)));
            $this->writeAuditLog($row->id, $row->name, $adminName, $action, (string) $targetDesc, $res['message'], 'SUCCESS');

            return response()->json([
                'success' => true,
                'message' => $res['message'],
                'ping'    => $res['ping'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error("Action router #{$id} ({$action}) gagal: " . $e->getMessage());
            $this->writeAuditLog($row->id, $row->name, $adminName, $action, '-', 'Gagal: ' . $e->getMessage(), 'FAILED');
            return response()->json([
                'success' => false,
                'message' => $this->safeErrorMessage($e, 'Gagal menjalankan perintah ke router.'),
            ], 500);
        }
    }

    /**
     * GET /api/admin/network-logs -> Semua Catatan Aktivitas Admin
     */
    public function logs()
    {
        $this->ensureTables();
        $rows = DB::table('network_activity_logs')->orderByDesc('id')->limit(100)->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }

    private function writeAuditLog(?int $routerId, string $routerName, string $adminName, string $action, string $target, string $desc, string $status = 'SUCCESS'): void
    {
        try {
            DB::table('network_activity_logs')->insert([
                'router_id'   => $routerId,
                'router_name' => $routerName,
                'admin_name'  => $adminName,
                'action'      => $action,
                'target'      => $target,
                'description' => $desc,
                'status'      => $status,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        } catch (\Throwable $e) {}
    }
}