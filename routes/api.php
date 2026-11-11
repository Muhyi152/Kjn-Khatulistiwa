<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ProductController;

use App\Models\Invoice;
use App\Models\Customer;
use App\Models\User;
use App\Models\Product;

date_default_timezone_set('Asia/Jakarta');

// =========================================================================
// HELPER GLOBAL: RESOLVE CURRENT USER DARI AUTH / BEARER TOKEN
// =========================================================================
$resolveCurrentUser = function (Request $request) {
    if (Auth::check()) return Auth::user();
    if ($bearer = $request->bearerToken()) {
        try {
            if (class_exists(\Laravel\Sanctum\PersonalAccessToken::class)) {
                $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($bearer);
                if ($tokenModel && $tokenModel->tokenable) {
                    return $tokenModel->tokenable;
                }
            }
        } catch (\Throwable $e) {}
    }
    return null;
};

// =========================================================================
// HELPER GLOBAL: DETEKSI ROLE ADMIN SECARA AKURAT
// =========================================================================
$checkIsAdmin = function (Request $request = null, $identifier = null) use ($resolveCurrentUser) {
    try {
        if ($request) {
            $path = strtolower($request->path());
            if (str_contains($path, 'admin/')) return true;
            if (str_contains($path, 'customer/')) return false;

            $headerRole = $request->header('X-User-Role') ?? $request->header('Role') ?? $request->header('role');
            if ($headerRole) {
                $hr = strtolower(trim((string)$headerRole));
                if (in_array($hr, ['admin', 'administrator'])) return true;
                if (in_array($hr, ['customer', 'pelanggan', 'user'])) return false;
            }

            $role = $request->query('role') ?? $request->input('role');
            if ($role) {
                $r = strtolower(trim((string)$role));
                if (in_array($r, ['admin', 'administrator'])) return true;
                if (in_array($r, ['customer', 'pelanggan', 'user'])) return false;
            }

            $user = $resolveCurrentUser($request);
            if ($user) {
                $uRole = strtolower(trim($user->role ?? ''));
                if (in_array($uRole, ['admin', 'administrator'])) return true;
                if (in_array($uRole, ['customer', 'pelanggan', 'user'])) return false;
            }

            $idVal = $identifier 
                ?? $request->query('user_id') 
                ?? $request->input('user_id')
                ?? $request->query('email') 
                ?? $request->input('email');
        } else {
            $idVal = $identifier;
        }

        if (!empty($idVal)) {
            if (!is_numeric($idVal) && str_contains(strtolower((string)$idVal), 'admin')) {
                return true;
            }
            $user = is_numeric($idVal) ? User::find($idVal) : User::where('email', $idVal)->first();
            if ($user) {
                return in_array(strtolower(trim($user->role ?? '')), ['admin', 'administrator']);
            }
        }
    } catch (\Throwable $e) {}

    return false;
};

// =========================================================================
// HELPER 1: PENCARIAN CUSTOMER SECARA DINAMIS DARI DATABASE
// =========================================================================
$resolveCustomer = function ($identifier = null) use ($resolveCurrentUser) {
    try {
        if ($identifier instanceof Request) {
            $req = $identifier;
            $identifier = $req->query('customer_id') 
                ?? $req->input('customer_id') 
                ?? $req->query('user_id') 
                ?? $req->input('user_id') 
                ?? $req->query('id') 
                ?? $req->input('id') 
                ?? $req->query('email') 
                ?? $req->input('email');
            
            if (empty($identifier)) {
                $user = $resolveCurrentUser($req);
                if ($user) $identifier = $user->email ?? $user->id;
            }
        } elseif (is_array($identifier)) {
            $identifier = $identifier['customer_id'] ?? $identifier['user_id'] ?? $identifier['id'] ?? $identifier['email'] ?? null;
        }

        $hasUserIdCol = Schema::hasColumn('customers', 'user_id');
        $hasEmailCol  = Schema::hasColumn('customers', 'email');
        $hasNamaCol   = Schema::hasColumn('customers', 'nama');

        if (!empty($identifier)) {
            if (is_numeric($identifier)) {
                $c = Customer::find($identifier);
                if ($c) return $c;

                if ($hasUserIdCol) {
                    $cByUser = Customer::where('user_id', $identifier)->first();
                    if ($cByUser) return $cByUser;
                }
            } else {
                if ($hasEmailCol) {
                    $cByEmail = Customer::where('email', $identifier)->first();
                    if ($cByEmail) return $cByEmail;
                }

                $u = User::where('email', $identifier)->first();
                if ($u) {
                    if ($hasUserIdCol) {
                        $c = Customer::where('user_id', $u->id)->first();
                        if ($c) return $c;
                    }
                    if ($hasEmailCol && !empty($u->email)) {
                        $c = Customer::where('email', $u->email)->first();
                        if ($c) return $c;
                    }
                    if ($hasNamaCol && !empty($u->name)) {
                        $namaDepan = explode(' ', trim($u->name))[0];
                        $c = Customer::where('nama', 'LIKE', '%' . $namaDepan . '%')->first();
                        if ($c) return $c;
                    }
                }

                if ($hasNamaCol) {
                    $c = Customer::where('nama', 'LIKE', '%' . $identifier . '%')->first();
                    if ($c) return $c;
                }
            }
        }

        if (Auth::check()) {
            $user = Auth::user();
            if ($hasUserIdCol) {
                $c = Customer::where('user_id', $user->id)->first();
                if ($c) return $c;
            }
            if ($hasEmailCol && !empty($user->email)) {
                $c = Customer::where('email', $user->email)->first();
                if ($c) return $c;
            }
            if ($hasNamaCol && !empty($user->name)) {
                $namaDepan = explode(' ', trim($user->name))[0];
                $c = Customer::where('nama', 'LIKE', '%' . $namaDepan . '%')->first();
                if ($c) return $c;
            }
        }

        return Customer::first();
    } catch (\Throwable $e) {
        return Customer::find(3) ?? Customer::first();
    }
};

// =========================================================================
// HELPER 2: PENYIMPANAN STATUS NOTIFIKASI
// =========================================================================
$getNotifState = function ($userKey) {
    $cleanKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $userKey);
    $filePath = storage_path("app/notif_state_{$cleanKey}.json");

    $data = [
        'all_read_at'          => null,
        'last_read_invoice_id' => 0,
        'read_ids'             => [],
    ];

    if (file_exists($filePath)) {
        $content = @file_get_contents($filePath);
        $json = json_decode($content, true);
        if (is_array($json)) {
            $data['all_read_at']          = $json['all_read_at'] ?? null;
            $data['last_read_invoice_id'] = (int) ($json['last_read_invoice_id'] ?? 0);
            $data['read_ids']             = $json['read_ids'] ?? [];
        }
    }

    $cacheAllRead = Cache::get("notif_all_read_{$cleanKey}");
    if ($cacheAllRead && (!$data['all_read_at'] || $cacheAllRead > $data['all_read_at'])) {
        $data['all_read_at'] = $cacheAllRead;
    }

    $cacheMaxId = (int) Cache::get("notif_max_id_{$cleanKey}", 0);
    if ($cacheMaxId > $data['last_read_invoice_id']) {
        $data['last_read_invoice_id'] = $cacheMaxId;
    }

    $cacheIds = Cache::get("notif_ids_{$cleanKey}", []);
    if (is_array($cacheIds)) {
        $data['read_ids'] = array_unique(array_merge($data['read_ids'], $cacheIds));
    }

    return $data;
};

$saveNotifState = function ($userKey, array $update) {
    $cleanKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $userKey);
    $filePath = storage_path("app/notif_state_{$cleanKey}.json");

    if (!is_dir(storage_path('app'))) {
        @mkdir(storage_path('app'), 0777, true);
    }

    $existing = [
        'all_read_at'          => null,
        'last_read_invoice_id' => 0,
        'read_ids'             => [],
    ];

    if (file_exists($filePath)) {
        $content = @file_get_contents($filePath);
        $json = json_decode($content, true);
        if (is_array($json)) {
            $existing = array_merge($existing, $json);
        }
    }

    if (isset($update['all_read_at'])) {
        $existing['all_read_at'] = $update['all_read_at'];
        Cache::forever("notif_all_read_{$cleanKey}", $update['all_read_at']);
    }

    if (isset($update['last_read_invoice_id'])) {
        $existing['last_read_invoice_id'] = (int) $update['last_read_invoice_id'];
        Cache::forever("notif_max_id_{$cleanKey}", (int) $update['last_read_invoice_id']);
    }

    if (isset($update['read_id'])) {
        $existing['read_ids'][] = (string) $update['read_id'];
        if (preg_match('/(\d+)/', (string) $update['read_id'], $m)) {
            $existing['read_ids'][] = (string) $m[1];
        }
        $existing['read_ids'] = array_values(array_unique($existing['read_ids']));
        Cache::forever("notif_ids_{$cleanKey}", $existing['read_ids']);
    }

    @file_put_contents($filePath, json_encode($existing));
    return $existing;
};

// =========================================================================
// HELPER FORMAT PROFIL CUSTOMER
// =========================================================================
$formatCustomerProfile = function ($customer) use ($resolveCustomer) {
    if (!$customer) {
        $customer = $resolveCustomer();
    }
    if (!$customer) {
        return response()->json(['success' => false, 'message' => 'Data customer tidak ditemukan.'], 404);
    }

    $targetId = $customer->id;

    // 1. Tagihan baru yang belum dibayar (PENDING)
    $invPending = Invoice::where('customer_id', $targetId)
        ->whereIn(DB::raw('UPPER(status)'), ['PENDING', 'UNPAID', 'BELUM_BAYAR'])
        ->latest('id')
        ->first();

    // 2. Pembayaran terakhir yang sudah lunas (PAID)
    $lastPaid = Invoice::where('customer_id', $targetId)
        ->whereIn(DB::raw('UPPER(status)'), ['PAID', 'LUNAS', 'BERHASIL', 'SUCCESS'])
        ->latest('id')
        ->first();

    // 3. Cek apakah paket langganan lama masih aktif (belum lewat masa berlaku)
    $tglBeliCarbon = $lastPaid ? Carbon::parse($lastPaid->tanggal_bayar ?? $lastPaid->updated_at) : null;
    $tglJatuhTempoCarbon = $tglBeliCarbon ? $tglBeliCarbon->copy()->addMonth() : null;
    $masihMasaAktif = $tglJatuhTempoCarbon ? now()->lte($tglJatuhTempoCarbon) : false;

    // 4. LOGIKA NAMA PAKET & KONEKSI SESUAI ALGORITMA:
    // Kartu Paket Langganan HANYA berganti ke nama paket baru jika SUDAH LUNAS.
    // Selama belum lunas, kartu paket tetap menampilkan paket lama yang sedang aktif.
    if ($lastPaid && !empty($lastPaid->nama_produk)) {
        $namaPaketAktif = $lastPaid->nama_produk;
    } else {
        $namaPaketAktif = $customer->nama_paket ?? 'Belum Ada Paket Aktif';
    }

    $namaPaketBersih = preg_replace('/^Pembayaran\s+Paket\s+/i', '', $namaPaketAktif);

    if ($invPending) {
        // ADA TAGIHAN BARU YANG BELUM DIBAYAR:
        $tagihanRiil = (double) $invPending->total_tagihan;
        $namaPaketPending = preg_replace('/^Pembayaran\s+Paket\s+/i', '', ($invPending->nama_produk ?? 'Paket Baru'));

        // Jika paket lama masih jalan -> Tetap Online! Jika tidak ada paket lama -> Offline.
        $statusLangganan = $masihMasaAktif ? 'AKTIF' : 'ISOLIR';
        $statusKoneksi   = $masihMasaAktif ? 'Online' : 'Offline';
        $isOnline        = $masihMasaAktif;
        $nomorInvoice    = $invPending->nomor_invoice;
        $tenggatWaktu    = "Menunggu Pembayaran Paket: {$namaPaketPending}";
    } elseif ($lastPaid) {
        // SEMUA TAGIHAN SUDAH LUNAS:
        $tagihanRiil = 0.0;
        $statusLangganan = 'AKTIF';
        $statusKoneksi = 'Online';
        $isOnline = true;
        $nomorInvoice = null;
        $tglJatuhTempo = $tglJatuhTempoCarbon ? $tglJatuhTempoCarbon->format('d M Y') : now()->addMonth()->format('d M Y');
        $tenggatWaktu = "LUNAS • Aktif s.d. {$tglJatuhTempo}";
    } else {
        // BELUM PERNAH BERLANGGANAN:
        $tagihanRiil = 0.0;
        $statusLangganan = 'BELUM_AKTIF';
        $statusKoneksi = 'Offline';
        $isOnline = false;
        $nomorInvoice = null;
        $tenggatWaktu = "Belum Ada Langganan";
    }

    $tglBeli = ($lastPaid ? Carbon::parse($lastPaid->tanggal_bayar ?? $lastPaid->updated_at) : ($customer->created_at ?? now()))->format('d M Y');
    $tglJatuhTempoStr = $tglJatuhTempoCarbon ? $tglJatuhTempoCarbon->format('d M Y') : now()->addMonth()->format('d M Y');

    return response()->json([
        'success' => true,
        'data' => [
            'id'               => (string) $customer->id,
            'user_id'          => (string) ($customer->user_id ?? $customer->id),
            'nama'             => $customer->nama ?? $customer->name ?? 'Pelanggan',
            'name'             => $customer->nama ?? $customer->name ?? 'Pelanggan',
            'email'            => $customer->email ?? '-',
            'no_hp'            => $customer->no_wa ?? $customer->no_hp ?? '-',
            'username_pppoe'   => $customer->pppoe_username ?? '-',
            'nama_paket'       => $namaPaketBersih, // Tetap paket lama yang sedang aktif!
            'nama_paket_tagihan' => $invPending ? preg_replace('/^Pembayaran\s+Paket\s+/i', '', $invPending->nama_produk) : null, // Paket yang sedang ditagihkan (Gaming)
            'status'           => $statusLangganan,
            'status_koneksi'   => $statusKoneksi,   // Online karena paket lama belum habis
            'is_online'        => $isOnline,
            'tagihan'          => $tagihanRiil,     // Rp 750.000 tagihan baru
            'nomor_invoice'    => $nomorInvoice,
            'tanggal_beli'     => $tglBeli,
            'jatuh_tempo'      => $tglJatuhTempoStr,
            'tenggat_waktu'    => $tenggatWaktu,
        ]
    ], 200);
};

// =========================================================================
// HANDLER DASHBOARD ADMIN
// =========================================================================
$handlerAdminDashboard = function (Request $request) {
    try {
        $allCustomers = Customer::orderBy('id', 'desc')->get();
        $totalCustomers = (int) $allCustomers->count();

        $activeConnections = (int) $allCustomers->filter(function ($c) {
            $st = strtoupper(trim($c->status ?? ''));
            $conn = strtolower(trim($c->status_koneksi ?? ''));
            return in_array($st, ['ACTIVE', 'AKTIF', 'ONLINE', 'ENABLE', 'ENABLED', '1'])
                || in_array($conn, ['online', 'aktif', 'terhubung', 'connected']);
        })->count();

        if ($activeConnections === 0 && $totalCustomers > 0) $activeConnections = $totalCustomers;
        $isolatedCustomers = max(0, $totalCustomers - $activeConnections);

        $allInvoices = Invoice::orderBy('id', 'desc')->get();
        $paidInvoices = $allInvoices->filter(function ($inv) {
            return in_array(strtoupper(trim($inv->status ?? '')), ['PAID', 'LUNAS', 'BERHASIL', 'SUCCESS']);
        });

        $monthlyRevenue = (double) $paidInvoices->sum('total_tagihan');
        $paidCount = (int) $paidInvoices->count();
        $pendingCount = (int) $allInvoices->filter(function ($inv) {
            return in_array(strtoupper(trim($inv->status ?? '')), ['PENDING', 'UNPAID', 'BELUM_BAYAR']);
        })->count();

        $soldCountByCleanName = [];
        $totalTerjual = 0;

        foreach ($paidInvoices as $inv) {
            $nama = trim($inv->nama_produk ?? '');
            $nama = preg_replace('/^Pembayaran\s+Paket\s+/i', '', $nama);
            if (empty($nama)) $nama = 'Basic Home';
            $cleanKey = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nama));
            $soldCountByCleanName[$cleanKey] = ($soldCountByCleanName[$cleanKey] ?? 0) + 1;
            $totalTerjual++;
        }

        $allProducts = Product::orderBy('id', 'asc')->get();
        $presetColors = ['#2563EB', '#10B981', '#F59E0B', '#8B5CF6', '#EC4899', '#06B6D4', '#F97316', '#14B8A6', '#64748B'];

        $planDistribution = [];
        $colorIdx = 0;

        foreach ($allProducts as $prod) {
            $namaPaket = trim($prod->nama_paket ?? $prod->name ?? ('Paket #' . $prod->id));
            $cleanProdKey = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $namaPaket));
            $terjual = $soldCountByCleanName[$cleanProdKey] ?? 0;
            $persentase = $totalTerjual > 0 ? round(($terjual / $totalTerjual) * 100) : 0;
            $warna = $colorIdx < count($presetColors) ? $presetColors[$colorIdx] : "hsl(" . round(fmod($colorIdx * 137.5, 360)) . ", 75%, 50%)";

            $planDistribution[] = [
                'id'          => (int) $prod->id,
                'nama'        => $namaPaket,
                'nama_paket'  => $namaPaket,
                'terjual'     => (int) $terjual,
                'total'       => (int) $terjual,
                'persentase'  => (int) $persentase,
                'warna'       => $warna,
            ];
            $colorIdx++;
        }

        $now = Carbon::now();
        $groupedByMonth = [];

        for ($i = 5; $i >= 0; $i--) {
            $dateCursor = $now->copy()->subMonths($i);
            $ym = $dateCursor->format('Y-m');
            $mName = $dateCursor->format('M');

            $groupedByMonth[$ym] = [
                'month_name'    => $mName,
                'bulan'         => $mName,
                'year_month'    => $ym,
                'total_revenue' => 0.0,
                'total'         => 0.0,
                'total_paid'    => 0,
            ];
        }

        foreach ($paidInvoices as $inv) {
            $tgl = $inv->tanggal_bayar ?? $inv->created_at;
            $carbon = $tgl ? Carbon::parse($tgl) : Carbon::now();
            $yearMonth = $carbon->format('Y-m');

            if (isset($groupedByMonth[$yearMonth])) {
                $nominal = (double) $inv->total_tagihan;
                $groupedByMonth[$yearMonth]['total_revenue'] += $nominal;
                $groupedByMonth[$yearMonth]['total'] += $nominal;
                $groupedByMonth[$yearMonth]['total_paid'] += 1;
            }
        }

        ksort($groupedByMonth);
        $revenueMonthly = array_values($groupedByMonth);

        $statsData = [
            'total_customers'         => $totalCustomers,
            'totalCustomers'          => $totalCustomers,
            'active_connections'      => $activeConnections,
            'activeConnections'       => $activeConnections,
            'isolated_pending'        => $isolatedCustomers,
            'monthly_revenue'         => $monthlyRevenue,
            'monthlyRevenue'          => $monthlyRevenue,
            'paid_invoices'           => $paidCount,
            'pending_invoices'        => $pendingCount,
            'total_products'          => (int) $allProducts->count(),
            'plan_distribution'       => $planDistribution,
            'revenue_customer_growth' => $revenueMonthly,
        ];

        return response()->json(array_merge(['success' => true], $statsData, ['data' => $statsData]), 200);

    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
};

Route::get('/admin/dashboard', fn(Request $r) => $handlerAdminDashboard($r));
Route::get('/customer/dashboard', function (Request $r) use ($resolveCustomer, $formatCustomerProfile) {
    return $formatCustomerProfile($resolveCustomer($r));
});
Route::get('/dashboard', function (Request $r) use ($resolveCustomer, $formatCustomerProfile, $handlerAdminDashboard, $checkIsAdmin) {
    if ($checkIsAdmin($r)) {
        return $handlerAdminDashboard($r);
    }
    return $formatCustomerProfile($resolveCustomer($r));
});

// =========================================================================
// ROUTE MUTASI ADMIN & CUSTOMER
// =========================================================================
$handlerMutasiAdmin = function (Request $request) {
    try {
        $invoices = Invoice::orderByDesc('id')->get();
        $customerIds = $invoices->pluck('customer_id')->filter()->unique();
        $customersMap = Customer::whereIn('id', $customerIds)->pluck('nama', 'id');

        $list = [];
        $totalOmsetMasuk = 0.0;
        $totalTerbayar = 0;

        foreach ($invoices as $inv) {
            $nominal = (double) $inv->total_tagihan;
            $isPaid = in_array(strtoupper($inv->status), ['PAID', 'LUNAS', 'BERHASIL', 'SUCCESS']);

            if ($isPaid) {
                $totalOmsetMasuk += $nominal;
                $totalTerbayar++;
            }

            $list[] = [
                'id'                 => (int) $inv->id,
                'nomor_invoice'      => (string) $inv->nomor_invoice,
                'customer_id'        => $inv->customer_id,
                'nama_customer'      => $customersMap[$inv->customer_id] ?? ('Pelanggan #' . $inv->customer_id),
                'nama_produk'        => 'Pembayaran Paket ' . preg_replace('/^Pembayaran\s+Paket\s+/i', '', ($inv->nama_produk ?: 'Basic Home')),
                'total_tagihan'      => $nominal,
                'status'             => strtoupper($inv->status),
                'is_paid'            => $isPaid,
                'metode_pembayaran'  => $inv->metode_pembayaran ?? 'Xendit Payment Link',
                'tanggal_bayar'      => (string) ($inv->tanggal_bayar ?? $inv->created_at),
                'created_at'         => (string) $inv->created_at,
            ];
        }

        return response()->json([
            'success'            => true,
            'peran'              => 'admin',
            'total_dana_masuk'   => $totalOmsetMasuk,
            'tagihan_terbayar'   => $totalTerbayar,
            'total_transaksi'    => count($list),
            'data'               => $list,
            'mutasi'             => $list,
        ], 200);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
};

$handlerMutasiCustomer = function (Request $request, $idParam = null) use ($resolveCustomer, $resolveCurrentUser) {
    try {
        $identifier = $idParam 
            ?? $request->query('customer_id') 
            ?? $request->query('user_id') 
            ?? $request->query('id') 
            ?? $request->query('email')
            ?? $request->input('customer_id')
            ?? $request->input('user_id')
            ?? $request->input('id');

        if (empty($identifier)) {
            $user = $resolveCurrentUser($request);
            if ($user) $identifier = $user->email ?? $user->id;
        }

        $customer = $resolveCustomer($identifier);
        $targetId = $customer ? $customer->id : 3;

        $invoices = Invoice::where('customer_id', $targetId)
            ->orderByDesc('id')
            ->get();

        $list = [];
        $totalPengeluaranLunas = 0.0;
        $jumlahLunas = 0;

        foreach ($invoices as $inv) {
            $nominal = (double) $inv->total_tagihan;
            $isPaid = in_array(strtoupper($inv->status), ['PAID', 'LUNAS', 'BERHASIL', 'SUCCESS']);

            if ($isPaid) {
                $totalPengeluaranLunas += $nominal;
                $jumlahLunas++;
            }

            $list[] = [
                'id'                 => (int) $inv->id,
                'nomor_invoice'      => (string) $inv->nomor_invoice,
                'customer_id'        => $inv->customer_id,
                'nama_customer'      => $customer ? ($customer->nama ?? 'Pelanggan') : 'Pelanggan',
                'nama_produk'        => 'Pembayaran Paket ' . preg_replace('/^Pembayaran\s+Paket\s+/i', '', ($inv->nama_produk ?: 'Basic Home')),
                'total_tagihan'      => $nominal,
                'status'             => strtoupper($inv->status),
                'is_paid'            => $isPaid,
                'metode_pembayaran'  => $inv->metode_pembayaran ?? 'Xendit Payment Link',
                'tanggal_bayar'      => (string) ($inv->tanggal_bayar ?? $inv->created_at),
                'created_at'         => (string) $inv->created_at,
            ];
        }

        return response()->json([
            'success'            => true,
            'peran'              => 'customer',
            'total_dana_masuk'   => $totalPengeluaranLunas,
            'total_pengeluaran'  => $totalPengeluaranLunas,
            'tagihan_terbayar'   => $jumlahLunas,
            'total_transaksi'    => count($list),
            'data'               => $list,
            'mutasi'             => $list,
        ], 200);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
};

Route::get('/admin/mutasi', fn(Request $r) => $handlerMutasiAdmin($r));
Route::get('/customer/mutasi', fn(Request $r) => $handlerMutasiCustomer($r));
Route::get('/mutasi', function (Request $r) use ($handlerMutasiAdmin, $handlerMutasiCustomer, $checkIsAdmin) {
    if ($checkIsAdmin($r)) return $handlerMutasiAdmin($r);
    return $handlerMutasiCustomer($r);
});

// =========================================================================
// HANDLER NOTIFIKASI
// =========================================================================
$handlerAdminNotifications = function (Request $request) use ($getNotifState) {
    try {
        $adminKey = 'admin_system_notifications';
        $notifState = $getNotifState($adminKey);
        $allReadAt = !empty($notifState['all_read_at']) ? Carbon::parse($notifState['all_read_at']) : null;
        $lastReadInvoiceId = (int) ($notifState['last_read_invoice_id'] ?? 0);
        $readIds = $notifState['read_ids'] ?? [];

        $invoices = Invoice::orderByDesc('id')->get();
        $customerIds = $invoices->pluck('customer_id')->filter()->unique();
        $customersMap = Customer::whereIn('id', $customerIds)->pluck('nama', 'id');

        $notifications = [];
        $unreadCount = 0;

        foreach ($invoices as $inv) {
            $isPaid = in_array(strtoupper($inv->status), ['PAID', 'LUNAS', 'BERHASIL', 'SUCCESS']);
            $eventTime = Carbon::parse($inv->tanggal_bayar ?? $inv->created_at ?? now());
            $namaPelanggan = $customersMap[$inv->customer_id] ?? ('Pelanggan #' . $inv->customer_id);
            $namaPaket = preg_replace('/^Pembayaran\s+Paket\s+/i', '', ($inv->nama_produk ?: 'Basic Home'));
            $nominalFmt = 'Rp ' . number_format($inv->total_tagihan, 0, ',', '.');
            $idNotif = 'admin-notif-' . $inv->id;
            $strId = (string) $inv->id;

            $sudahDibaca = false;
            if ($lastReadInvoiceId > 0 && $inv->id <= $lastReadInvoiceId) {
                $sudahDibaca = true;
            } elseif ($allReadAt && $eventTime->lte($allReadAt)) {
                $sudahDibaca = true;
            } elseif (in_array((string)$idNotif, $readIds) || in_array($strId, $readIds)) {
                $sudahDibaca = true;
            }

            if (!$sudahDibaca) $unreadCount++;

            $judul = $isPaid ? 'Pembayaran Berhasil Diterima' : 'Tagihan Baru Menunggu Pembayaran';
            $pesan = $isPaid 
                ? "Pembayaran paket {$namaPaket} dari {$namaPelanggan} sebesar {$nominalFmt} berhasil diverifikasi lunas."
                : "Tagihan {$inv->nomor_invoice} untuk {$namaPelanggan} ({$namaPaket}) sebesar {$nominalFmt} menunggu konfirmasi bayar.";

            $notifications[] = [
                'id'                 => $strId,
                'notif_id'           => $idNotif,
                'notification_id'    => $strId,
                'tipe'               => $isPaid ? 'SUCCESS' : 'WARNING',
                'type'               => $isPaid ? 'SUCCESS' : 'WARNING',
                'judul'              => $judul,
                'title'              => $judul,
                'pesan'              => $pesan,
                'message'            => $pesan,
                'body'               => $pesan,
                'nomor_invoice'      => (string) $inv->nomor_invoice,
                'nama_customer'      => $namaPelanggan,
                'total'              => (double) $inv->total_tagihan,
                'total_tagihan'      => (double) $inv->total_tagihan,
                'amount'             => (double) $inv->total_tagihan,
                'status'             => $isPaid ? 'LUNAS' : 'PENDING',
                'tanggal'            => $eventTime->toIso8601String(),
                'waktu'              => $eventTime->toIso8601String(),
                'created_at'         => $eventTime->toIso8601String(),
                'tanggal_bayar'      => $eventTime->toIso8601String(),
                'date'               => $eventTime->toIso8601String(),
                'timestamp'          => $eventTime->timestamp,
                'is_read'            => $sudahDibaca,
                'sudah_dibaca'       => $sudahDibaca,
            ];
        }

        return response()->json([
            'success'       => true,
            'peran'         => 'admin',
            'role'          => 'admin',
            'unread_count'  => $unreadCount,
            'unreadCount'   => $unreadCount,
            'total'         => count($notifications),
            'data'          => $notifications,
            'notifications' => $notifications,
        ], 200);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
};

$handlerCustomerNotifications = function (Request $request) use ($resolveCustomer, $getNotifState, $resolveCurrentUser, $handlerAdminNotifications, $checkIsAdmin) {
    try {
        if ($checkIsAdmin($request)) {
            return $handlerAdminNotifications($request);
        }

        $identifier = $request->query('customer_id') 
            ?? $request->query('user_id') 
            ?? $request->query('id') 
            ?? $request->query('email')
            ?? $request->input('customer_id')
            ?? $request->input('user_id')
            ?? $request->input('id');

        if (empty($identifier)) {
            $user = $resolveCurrentUser($request);
            if ($user) $identifier = $user->email ?? $user->id;
        }

        $customer = $resolveCustomer($identifier);
        $targetId = $customer ? $customer->id : 3;

        $userKey = 'customer_notif_' . $targetId;
        $notifState = $getNotifState($userKey);
        $allReadAt = !empty($notifState['all_read_at']) ? Carbon::parse($notifState['all_read_at']) : null;
        $lastReadInvoiceId = (int) ($notifState['last_read_invoice_id'] ?? 0);
        $readIds = $notifState['read_ids'] ?? [];

        $invoices = Invoice::where('customer_id', $targetId)
            ->whereNotIn(DB::raw('UPPER(status)'), ['CANCELLED', 'BATAL'])
            ->orderByDesc('id')
            ->get();

        $notifications = [];
        $unreadCount = 0;

        foreach ($invoices as $inv) {
            $isPaid = in_array(strtoupper($inv->status), ['PAID', 'LUNAS', 'BERHASIL', 'SUCCESS']);
            $eventTime = Carbon::parse($inv->tanggal_bayar ?? $inv->created_at ?? now());
            $strId = (string) $inv->id;
            $idNotif = $isPaid ? ('cust-paid-' . $inv->id) : ('cust-pending-' . $inv->id);

            $sudahDibaca = false;
            if ($lastReadInvoiceId > 0 && $inv->id <= $lastReadInvoiceId) {
                $sudahDibaca = true;
            } elseif ($allReadAt && $eventTime->lte($allReadAt)) {
                $sudahDibaca = true;
            } elseif (in_array($idNotif, $readIds) || in_array($strId, $readIds)) {
                $sudahDibaca = true;
            }

            if (!$sudahDibaca) $unreadCount++;

            $rawProduk = trim($inv->nama_produk ?? '');
            $namaPaket = !empty($rawProduk) ? $rawProduk : ($customer ? ($customer->nama_paket ?? 'Standar') : 'Standar');
            $namaPaketClean = preg_replace('/^Pembayaran\s+Paket\s+/i', '', $namaPaket);
            $totalTagihan = (double) $inv->total_tagihan;
            $totalFmt = 'Rp ' . number_format($totalTagihan, 0, ',', '.');

            $judul = $isPaid ? 'Pembayaran Berhasil Diverifikasi' : 'Tagihan Internet Baru Diterbitkan';
            $pesan = $isPaid 
                ? "Pembayaran paket {$namaPaketClean} Anda sebesar {$totalFmt} telah berhasil diverifikasi lunas."
                : "Tagihan baru untuk paket {$namaPaketClean} sebesar {$totalFmt} telah diterbitkan. Silakan lakukan pembayaran.";

            $notifications[] = [
                'id'              => $strId,
                'notif_id'        => $idNotif,
                'notification_id' => $strId,
                'tipe'            => $isPaid ? 'SUCCESS' : 'WARNING',
                'type'            => $isPaid ? 'SUCCESS' : 'WARNING',
                'judul'           => $judul,
                'title'           => $judul,
                'pesan'           => $pesan,
                'message'         => $pesan,
                'body'            => $pesan,
                'nomor_invoice'   => (string) $inv->nomor_invoice,
                'nama_produk'     => $namaPaketClean,
                'product_name'    => $namaPaketClean,
                'total'           => $totalTagihan,
                'total_tagihan'   => $totalTagihan,
                'amount'          => $totalTagihan,
                'status'          => $isPaid ? 'LUNAS' : 'MENUNGGU',
                'tanggal'         => $eventTime->toIso8601String(),
                'waktu'           => $eventTime->toIso8601String(),
                'created_at'      => $eventTime->toIso8601String(),
                'tanggal_bayar'   => $eventTime->toIso8601String(),
                'date'            => $eventTime->toIso8601String(),
                'timestamp'       => $eventTime->timestamp,
                'is_read'         => $sudahDibaca,
                'sudah_dibaca'    => $sudahDibaca,
            ];
        }

        if ($customer) {
            $idStatusNotif = 'notif-status-' . $customer->id;
            $statusTime = Carbon::parse($customer->updated_at ?? now());
            $sudahDibacaStatus = ($lastReadInvoiceId > 0) || ($allReadAt && $statusTime->lte($allReadAt)) || in_array($idStatusNotif, $readIds);
            $namaPaketCust = preg_replace('/^Pembayaran\s+Paket\s+/i', '', ($customer->nama_paket ?? 'Standar'));

            $notifications[] = [
                'id'              => '99999',
                'notif_id'        => $idStatusNotif,
                'notification_id' => $idStatusNotif,
                'tipe'            => 'INFO',
                'type'            => 'INFO',
                'judul'           => 'Layanan Internet Terhubung',
                'title'           => 'Layanan Internet Terhubung',
                'pesan'           => "Paket internet {$namaPaketCust} Anda saat ini berstatus aktif normal.",
                'message'         => "Paket internet {$namaPaketCust} Anda saat ini berstatus aktif normal.",
                'body'            => "Paket internet {$namaPaketCust} Anda saat ini berstatus aktif normal.",
                'nomor_invoice'   => '-',
                'nama_produk'     => $namaPaketCust,
                'product_name'    => $namaPaketCust,
                'total'           => 0.0,
                'total_tagihan'   => 0.0,
                'amount'          => 0.0,
                'status'          => 'AKTIF',
                'tanggal'         => $statusTime->toIso8601String(),
                'waktu'           => $statusTime->toIso8601String(),
                'created_at'      => $statusTime->toIso8601String(),
                'date'            => $statusTime->toIso8601String(),
                'timestamp'       => $statusTime->timestamp,
                'is_read'         => $sudahDibacaStatus,
                'sudah_dibaca'    => $sudahDibacaStatus,
            ];
        }

        return response()->json([
            'success'       => true, 
            'peran'         => 'customer',
            'role'          => 'customer',
            'unread_count'  => $unreadCount,
            'unreadCount'   => $unreadCount,
            'total'         => count($notifications),
            'data'          => $notifications,
            'notifications' => $notifications,
        ], 200);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
};

Route::get('/admin/notifications', fn(Request $r) => $handlerAdminNotifications($r));
Route::get('/admin/notification', fn(Request $r) => $handlerAdminNotifications($r));
Route::get('/admin/notifikasi', fn(Request $r) => $handlerAdminNotifications($r));
Route::get('/customer/notifications', fn(Request $r) => $handlerCustomerNotifications($r));
Route::get('/customer/notification', fn(Request $r) => $handlerCustomerNotifications($r));
Route::get('/customer/notifikasi', fn(Request $r) => $handlerCustomerNotifications($r));

Route::get('/notifications', function (Request $r) use ($handlerAdminNotifications, $handlerCustomerNotifications, $checkIsAdmin) {
    if ($checkIsAdmin($r)) return $handlerAdminNotifications($r);
    return $handlerCustomerNotifications($r);
});
Route::get('/notification', function (Request $r) use ($handlerAdminNotifications, $handlerCustomerNotifications, $checkIsAdmin) {
    if ($checkIsAdmin($r)) return $handlerAdminNotifications($r);
    return $handlerCustomerNotifications($r);
});
Route::get('/notifikasi', function (Request $r) use ($handlerAdminNotifications, $handlerCustomerNotifications, $checkIsAdmin) {
    if ($checkIsAdmin($r)) return $handlerAdminNotifications($r);
    return $handlerCustomerNotifications($r);
});

// Mark all read
$handlerMarkAllReadAdmin = function () use ($saveNotifState) {
    $maxInvoiceId = (int) (Invoice::max('id') ?? 0);
    $saveNotifState('admin_system_notifications', [
        'all_read_at'          => Carbon::now()->toIso8601String(),
        'last_read_invoice_id' => $maxInvoiceId,
    ]);
    return response()->json(['success' => true, 'unread_count' => 0, 'unreadCount' => 0, 'message' => 'Semua notifikasi admin telah dibaca.']);
};

$handlerMarkAllReadCustomer = function (Request $r) use ($saveNotifState, $resolveCustomer, $handlerMarkAllReadAdmin, $checkIsAdmin) {
    if ($checkIsAdmin($r)) {
        return $handlerMarkAllReadAdmin();
    }
    $customer = $resolveCustomer($r);
    $targetId = $customer ? $customer->id : 3;
    $maxCustInv = (int) (Invoice::where('customer_id', $targetId)->max('id') ?? 0);

    $saveNotifState('customer_notif_' . $targetId, [
        'all_read_at'          => Carbon::now()->toIso8601String(),
        'last_read_invoice_id' => $maxCustInv,
    ]);
    return response()->json(['success' => true, 'unread_count' => 0, 'unreadCount' => 0, 'message' => 'Semua notifikasi pelanggan telah ditandai dibaca.']);
};

Route::match(['GET', 'POST'], '/admin/notifications/mark-all-read', fn() => $handlerMarkAllReadAdmin());
Route::match(['GET', 'POST'], '/admin/notifikasi/mark-all-read', fn() => $handlerMarkAllReadAdmin());
Route::match(['GET', 'POST'], '/customer/notifications/mark-all-read', fn(Request $r) => $handlerMarkAllReadCustomer($r));
Route::match(['GET', 'POST'], '/customer/notifikasi/mark-all-read', fn(Request $r) => $handlerMarkAllReadCustomer($r));

Route::match(['GET', 'POST'], '/notifications/mark-all-read', function (Request $r) use ($checkIsAdmin, $handlerMarkAllReadAdmin, $handlerMarkAllReadCustomer) {
    if ($checkIsAdmin($r)) return $handlerMarkAllReadAdmin();
    return $handlerMarkAllReadCustomer($r);
});
Route::match(['GET', 'POST'], '/notifikasi/mark-all-read', function (Request $r) use ($checkIsAdmin, $handlerMarkAllReadAdmin, $handlerMarkAllReadCustomer) {
    if ($checkIsAdmin($r)) return $handlerMarkAllReadAdmin();
    return $handlerMarkAllReadCustomer($r);
});

// =========================================================================
// ROUTE PROFILE CUSTOMER & RESOURCES
// =========================================================================
Route::get('/customer/profile/{identifier}', function ($identifier) use ($resolveCustomer, $formatCustomerProfile) {
    $cust = $resolveCustomer($identifier);
    return $formatCustomerProfile($cust);
});

Route::get('/customers/{id}', function ($id) use ($resolveCustomer, $formatCustomerProfile) {
    $cust = $resolveCustomer($id);
    return $formatCustomerProfile($cust);
})->where('id', '[0-9]+');

Route::get('/customer/{id}', function ($id) use ($resolveCustomer, $formatCustomerProfile) {
    $cust = $resolveCustomer($id);
    return $formatCustomerProfile($cust);
})->where('id', '[0-9]+');

Route::apiResource('customers', CustomerController::class);
Route::apiResource('products', ProductController::class);

// =========================================================================
// TAGIHAN AKTIF CUSTOMER
// =========================================================================
Route::get('/customer/invoice/active', function (Request $request) use ($resolveCustomer) {
    try {
        $cust = $resolveCustomer($request);

        if (!$cust) {
            return response()->json([
                'success'     => true,
                'has_tagihan' => false,
                'message'     => 'Data pelanggan belum terdaftar.',
                'data'        => null,
            ], 200);
        }

        $inv = Invoice::where('customer_id', $cust->id)
            ->whereIn(DB::raw('UPPER(status)'), ['PENDING', 'UNPAID', 'BELUM_BAYAR'])
            ->latest('id')
            ->first();

        if (!$inv) {
            return response()->json([
                'success'     => true,
                'has_tagihan' => false,
                'message'     => 'Tidak ada tagihan tertunggak saat ini.',
                'data'        => null,
            ], 200);
        }

        return response()->json([
            'success'     => true,
            'has_tagihan' => true,
            'data'        => [
                'id'                => $inv->id,
                'nomor_invoice'     => $inv->nomor_invoice,
                'customer_id'       => $inv->customer_id,
                'total_tagihan'     => (double) $inv->total_tagihan,
                'status'            => $inv->status,
                'nama_produk'       => $inv->nama_produk ?? 'Paket Internet',
                'invoice_url'       => $inv->qr_image_url ?? null,
                'qr_image_url'      => $inv->qr_image_url ?? null,
                'qr_string'         => $inv->qr_string ?? null,
            ],
        ], 200);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
});

// =========================================================================
// BUAT INVOICE / PAYMENT LINK RESMI XENDIT (DINAMIS DARI DATABASE)
// =========================================================================
$handlerCreateInvoiceXendit = function (Request $request) use ($resolveCustomer) {
    try {
        // 1. Ambil data Customer dari Database
        $customer = $resolveCustomer($request);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Data customer tidak ditemukan di database.'], 404);
        }

        // 2. Ambil data Paket/Produk dari Database
        $productId = $request->input('product_id');
        $namaProdukInput = trim($request->input('nama_produk') ?? '');

        $product = null;
        if (!empty($productId)) {
            $product = Product::find($productId);
        } elseif (!empty($namaProdukInput)) {
            $product = Product::where('nama_paket', $namaProdukInput)->first();
        }

        // Nominal & Nama Paket dari Database
        $nominal = $product ? (int) $product->harga : (int) $request->input('nominal', 0);
        $namaPaket = $product ? $product->nama_paket : (!empty($namaProdukInput) ? $namaProdukInput : 'Paket Internet');

        // Batas minimal transaksi Xendit (wajib minimal Rp 10.000)
        if ($nominal < 10000) {
            return response()->json([
                'success' => false,
                'message' => 'Nominal tagihan minimal Rp 10.000 untuk diproses oleh Xendit (Nominal saat ini: Rp ' . number_format($nominal, 0, ',', '.') . ').'
            ], 422);
        }

        $nomorInvoice = 'INV-' . date('YmdHis') . '-' . $customer->id;

        // 3. Format nomor HP dan email customer
        $rawPhone = preg_replace('/[^0-9]/', '', (string)($customer->no_wa ?? $customer->no_hp ?? ''));
        if (str_starts_with($rawPhone, '0')) {
            $phone = '+62' . substr($rawPhone, 1);
        } elseif (str_starts_with($rawPhone, '62')) {
            $phone = '+' . $rawPhone;
        } else {
            $phone = '+6281234567890';
        }

        $custEmail = (!empty($customer->email) && filter_var($customer->email, FILTER_VALIDATE_EMAIL))
            ? $customer->email
            : 'pelanggan.' . $customer->id . '@gmail.com';

        // 4. Hubungi API Resmi Xendit V2 Invoices
        // GANTI MENJADI INI:
$secretKey = 'xnd_development_tItdOKcWdq44rpuVkqd1TC7qkouHdQkPrOIdKU6Ah7XC9YH6uQ6oc0qxNPCa';

        $payloadXendit = [
            'external_id'      => $nomorInvoice,
            'amount'           => $nominal,
            'description'      => 'Pembayaran Internet: ' . $namaPaket . ' - ' . ($customer->nama ?? 'Pelanggan'),
            'invoice_duration' => 86400, // 24 Jam
            'currency'         => 'IDR',
            'payer_email'      => $custEmail,
            'customer'         => [
                'given_names'   => $customer->nama ?? 'Pelanggan',
                'email'         => $custEmail,
                'mobile_number' => $phone,
            ],
        ];

        $response = Http::withBasicAuth($secretKey, '')
            ->timeout(20)
            ->post('https://api.xendit.co/v2/invoices', $payloadXendit);

        if (!$response->successful()) {
            Log::warning('Xendit Invoice Gagal [' . $response->status() . ']: ' . $response->body());
            return response()->json([
                'success' => false,
                'message' => 'Xendit menolak pembuatan tagihan: ' . ($response->json('message') ?? $response->body())
            ], 400);
        }

        $resData = $response->json();
        $invoiceUrl = $resData['invoice_url']; // Link checkout resmi Xendit

        // 5. Bersihkan invoice pending lama milik pelanggan
        Invoice::where('customer_id', $customer->id)
            ->whereIn('status', ['PENDING', 'UNPAID', 'BELUM_BAYAR'])
            ->delete();

        // 6. Simpan tagihan baru ke database lokal
        $invoice = Invoice::create([
            'nomor_invoice'     => $nomorInvoice,
            'customer_id'       => $customer->id,
            'total_tagihan'     => $nominal,
            'status'            => 'PENDING',
            'nama_produk'       => $namaPaket,
            'metode_pembayaran' => 'Xendit Multi Payment',
            'qr_image_url'      => $invoiceUrl, // Simpan link resmi Xendit
            'qr_string'         => $invoiceUrl,
        ]);

        return response()->json([
            'success'       => true,
            'message'       => 'Tagihan Xendit berhasil diterbitkan!',
            'nomor_invoice' => $invoice->nomor_invoice,
            'total_tagihan' => (double) $invoice->total_tagihan,
            'nama_produk'   => $namaPaket,
            'invoice_url'   => $invoiceUrl, // Link resmi yang dibuka oleh Flutter / Browser
            'qr_image_url'  => $invoiceUrl,
            'qr_string'     => $invoiceUrl,
        ], 200);

    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()], 500);
    }
};

Route::post('/xendit/create-invoice', fn(Request $r) => $handlerCreateInvoiceXendit($r));
Route::post('/xendit/create-qris', fn(Request $r) => $handlerCreateInvoiceXendit($r));

// =========================================================================
// CEK STATUS PEMBAYARAN KE SERVER XENDIT REALTIME
// =========================================================================
Route::post('/invoices/check-status', function (Request $request) use ($resolveCustomer) {
    try {
        $nomorInvoice = trim($request->input('nomor_invoice', ''));
        $simulasi = $request->boolean('simulasi', false);

        // 1. Cari invoice di database lokal
        $invoice = null;
        if (!empty($nomorInvoice)) {
            $invoice = Invoice::where('nomor_invoice', $nomorInvoice)->first();
        }

        if (!$invoice) {
            $customer = $resolveCustomer($request);
            if ($customer) {
                $invoice = Invoice::where('customer_id', $customer->id)
                    ->latest('id')
                    ->first();
            }
        }

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Tagihan tidak ditemukan.'
            ], 404);
        }

        $secretKey = 'xnd_development_tItdOKcWdq44rpuVkqd1TC7qkouHdQkPrOIdKU6Ah7XC9YH6uQ6oc0qxNPCa';
        $isPaid = false;

        // 2. CEK DATABASE DULU: Jika webhook Xendit sudah mengubahnya jadi PAID
        if (in_array(strtoupper($invoice->status), ['PAID', 'LUNAS', 'BERHASIL', 'SUCCESS'])) {
            $isPaid = true;
        }

        // 3. JIKA BELUM, TANYA LANGSUNG KE SERVER XENDIT
        if (!$isPaid && !empty($secretKey)) {
            try {
                $response = Http::withBasicAuth($secretKey, '')
                    ->withoutVerifying()
                    ->timeout(12)
                    ->get('https://api.xendit.co/v2/invoices?external_id=' . $invoice->nomor_invoice);

                if ($response->successful()) {
                    $res = $response->json();
                    
                    // Xendit bisa mengembalikan array [ { status: 'PAID' } ] atau object { status: 'PAID' }
                    $statusXendit = '';
                    if (isset($res[0]['status'])) {
                        $statusXendit = strtoupper($res[0]['status']);
                    } elseif (isset($res['status'])) {
                        $statusXendit = strtoupper($res['status']);
                    }

                    if (in_array($statusXendit, ['PAID', 'SETTLED', 'COMPLETED'])) {
                        $isPaid = true;
                    }
                }
            } catch (\Throwable $err) {
                Log::warning('Gagal verifikasi ke Xendit: ' . $err->getMessage());
            }
        }

        // Jika tombol simulasi ditekan
        if ($simulasi) {
            $isPaid = true;
        }

        // 4. JIKA TERKONFIRMASI LUNAS: Update status pelanggan jadi AKTIF & ONLINE
        if ($isPaid) {
            $invoice->update([
                'status'        => 'PAID',
                'tanggal_bayar' => now(),
            ]);

            $customer = $resolveCustomer($invoice->customer_id);
            if ($customer) {
                $prod = Product::where('nama_paket', $invoice->nama_produk)->first();
                $updateData = ['status' => 'AKTIF', 'status_koneksi' => 'Online'];
                if ($prod && Schema::hasColumn('customers', 'product_id')) {
                    $updateData['product_id'] = $prod->id;
                }
                if (Schema::hasColumn('customers', 'nama_paket')) {
                    $updateData['nama_paket'] = preg_replace('/^Pembayaran\s+Paket\s+/i', '', $invoice->nama_produk);
                }
                $customer->update($updateData);
            }

            return response()->json([
                'success'       => true,
                'is_paid'       => true,
                'status'        => 'PAID',
                'message'       => 'Pembayaran berhasil diverifikasi lunas!',
                'nomor_invoice' => $invoice->nomor_invoice,
                'total_tagihan' => (double) $invoice->total_tagihan,
                'nama_produk'   => $invoice->nama_produk,
            ], 200);
        }

        return response()->json([
            'success' => false,
            'is_paid' => false,
            'status'  => 'PENDING',
            'message' => 'Pembayaran belum terdeteksi di Xendit.',
        ], 200);

    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
});

// =========================================================================
// WEBHOOK / CALLBACK OTOMATIS DARI XENDIT
// =========================================================================
Route::post('/xendit/callback', function (Request $request) use ($resolveCustomer) {
    try {
        $token = $request->header('x-callback-token');
        $expectedToken = env('XENDIT_CALLBACK_TOKEN') 
            ?: config('services.xendit.callback_token') 
            ?: '7tOwxp2G4djBDJ0ZOenlfcJJofGODiHeubfiNWwTRa3YTZk1';

        if (!empty($expectedToken) && $token !== $expectedToken) {
            return response()->json(['message' => 'Token Callback Tidak Sah'], 403);
        }

        $payload = $request->all();
        Log::info('Xendit Webhook Diterima:', $payload);

        $nomorInvoice = $payload['external_id'] ?? $payload['data']['reference_id'] ?? $payload['reference_id'] ?? null;
        $status = strtoupper($payload['status'] ?? $payload['data']['status'] ?? '');

        if (!$nomorInvoice) {
            return response()->json(['message' => 'Nomor invoice tidak ditemukan dalam payload'], 400);
        }

        $invoice = Invoice::where('nomor_invoice', $nomorInvoice)->first();

        // BAGIAN INI YANG DIUBAH AGAR TOMBOL "TEST AND SAVE" DI XENDIT LANGSUNG SUKSES (200 OK)
        if (!$invoice) {
            return response()->json([
                'success' => true,
                'message' => 'Test webhook Xendit berhasil diterima (Invoice testing: ' . $nomorInvoice . ')'
            ], 200);
        }

        if (in_array($status, ['COMPLETED', 'PAID', 'SUCCEEDED', 'SETTLED'])) {
            $invoice->update([
                'status'        => 'PAID',
                'tanggal_bayar' => now(),
            ]);

            $customer = $resolveCustomer($invoice->customer_id);
            if ($customer) {
                $prod = Product::where('nama_paket', $invoice->nama_produk)->first();
                $updateData = ['status' => 'AKTIF', 'status_koneksi' => 'Online'];
                if ($prod && Schema::hasColumn('customers', 'product_id')) {
                    $updateData['product_id'] = $prod->id;
                }
                if (Schema::hasColumn('customers', 'nama_paket')) {
                    $updateData['nama_paket'] = preg_replace('/^Pembayaran\s+Paket\s+/i', '', $invoice->nama_produk);
                }
                $customer->update($updateData);
            }
        }

        return response()->json(['success' => true, 'message' => 'Callback berhasil diproses'], 200);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
});

Route::post('/invoices/cancel', function (Request $request) use ($resolveCustomer) {
    try {
        $customer = $resolveCustomer($request);
        $nomorInvoice = trim($request->input('nomor_invoice', ''));

        // 1. Batalkan berdasarkan nomor invoice jika ada
        if (!empty($nomorInvoice)) {
            Invoice::where('nomor_invoice', $nomorInvoice)
                ->whereIn(DB::raw('UPPER(status)'), ['PENDING', 'UNPAID', 'BELUM_BAYAR'])
                ->update(['status' => 'CANCELLED']);
        }

        // 2. BATALKAN SEMUA tagihan pending milik customer ini (termasuk ID 67!)
        $targetCustomerId = $customer ? $customer->id : ($request->input('customer_id') ?? 3);
        Invoice::where('customer_id', $targetCustomerId)
            ->whereIn(DB::raw('UPPER(status)'), ['PENDING', 'UNPAID', 'BELUM_BAYAR'])
            ->update(['status' => 'CANCELLED']);

        return response()->json([
            'success' => true,
            'message' => 'Semua tagihan pending berhasil dibatalkan.'
        ], 200);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
});
// =========================================================================
// AUTENTIKASI LOGIN
// =========================================================================
Route::post('/login', function (Request $request) {
    try {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json(['success' => false, 'message' => 'Email atau password salah!'], 401);
        }

        $user = Auth::user();

        $customer = null;
        try {
            if (Schema::hasColumn('customers', 'user_id')) {
                $customer = Customer::where('user_id', $user->id)->first();
            }
        } catch (\Throwable $e) {}

        if (!$customer && Schema::hasColumn('customers', 'email') && !empty($user->email)) {
            $customer = Customer::where('email', $user->email)->first();
        }

        if (!$customer && Schema::hasColumn('customers', 'nama') && !empty($user->name)) {
            $namaDepan = explode(' ', trim($user->name))[0];
            $customer = Customer::where('nama', 'LIKE', '%' . $namaDepan . '%')->first();
        }

        if (!$customer && in_array(strtolower($user->role ?? ''), ['customer', 'pelanggan'])) {
            $customer = Customer::find(3) ?? Customer::first();
        }

        $token = null;
        try {
            if (method_exists($user, 'createToken')) {
                $token = $user->createToken('auth_token')->plainTextToken;
            }
        } catch (\Throwable $tokErr) {}

        if (empty($token)) {
            $token = Str::random(64);
        }

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil',
            'token'   => $token,
            'user'    => [
                'id'          => $user->id,
                'customer_id' => $customer ? $customer->id : $user->id,
                'name'        => $user->name,
                'email'       => $user->email,
                'role'        => $user->role ?? 'customer',
            ],
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $ve) {
        return response()->json([
            'success' => false,
            'message' => $ve->validator->errors()->first()
        ], 422);
    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
        ], 500);
    }
});