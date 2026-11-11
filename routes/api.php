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
            if (str_contains($path, 'admin')) return true;
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
                ?? $request->header('X-User-Id')
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
        $reqUser = null;
        if ($identifier instanceof Request) {
            $req = $identifier;
            $reqUser = $resolveCurrentUser($req);
            $identifier = $req->query('customer_id') 
                ?? $req->input('customer_id') 
                ?? $req->header('X-Customer-Id')
                ?? $req->header('X-User-Id')
                ?? $req->query('user_id') 
                ?? $req->input('user_id') 
                ?? $req->query('id') 
                ?? $req->input('id') 
                ?? $req->query('email') 
                ?? $req->input('email');
            
            if (empty($identifier) && $reqUser) {
                $identifier = $reqUser->id;
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
                        $c = Customer::where('nama', $u->name)->first();
                        if ($c) return $c;
                    }
                }

                if ($hasNamaCol) {
                    $c = Customer::where('nama', $identifier)->first();
                    if ($c) return $c;
                }
            }
        }

        $currentUser = Auth::user() ?? $reqUser;
        if ($currentUser) {
            if ($hasUserIdCol) {
                $c = Customer::where('user_id', $currentUser->id)->first();
                if ($c) return $c;
            }
            if ($hasEmailCol && !empty($currentUser->email)) {
                $c = Customer::where('email', $currentUser->email)->first();
                if ($c) return $c;
            }
            if ($hasNamaCol && !empty($currentUser->name)) {
                $c = Customer::where('nama', $currentUser->name)->first();
                if ($c) return $c;
            }
        }

        return null;
    } catch (\Throwable $e) {
        return null;
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

    return data_get($data, null, $data);
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
$formatCustomerProfile = function ($customer = null, Request $request = null) use ($resolveCustomer, $resolveCurrentUser) {
    if (!$customer && $request) {
        $customer = $resolveCustomer($request);
    }

    if (!$customer) {
        $user = $request ? $resolveCurrentUser($request) : (Auth::check() ? Auth::user() : null);
        return response()->json([
            'success' => true,
            'data'    => [
                'id'                 => (string) ($user ? $user->id : '0'),
                'user_id'            => (string) ($user ? $user->id : '0'),
                'nama'               => $user ? $user->name : 'Pelanggan Baru',
                'name'               => $user ? $user->name : 'Pelanggan Baru',
                'email'              => $user ? $user->email : '-',
                'no_hp'              => '-',
                'username_pppoe'     => '-',
                'nama_paket'         => 'Belum Ada Paket Aktif',
                'nama_paket_tagihan' => null,
                'status'             => 'BELUM_AKTIF',
                'status_koneksi'     => 'Offline',
                'is_online'          => false,
                'tagihan'            => 0.0,
                'nomor_invoice'      => null,
                'tanggal_beli'       => '-',
                'jatuh_tempo'        => '-',
                'tenggat_waktu'      => 'Belum Ada Langganan',
            ]
        ], 200);
    }

    $targetId = $customer->id;

    $invPending = Invoice::where('customer_id', $targetId)
        ->whereIn(DB::raw('UPPER(status)'), ['PENDING', 'UNPAID', 'BELUM_BAYAR'])
        ->latest('id')
        ->first();

    $lastPaid = Invoice::where('customer_id', $targetId)
        ->whereIn(DB::raw('UPPER(status)'), ['PAID', 'LUNAS', 'BERHASIL', 'SUCCESS'])
        ->latest('id')
        ->first();

    $tglBeliCarbon = $lastPaid ? Carbon::parse($lastPaid->tanggal_bayar ?? $lastPaid->updated_at) : null;
    $tglJatuhTempoCarbon = $tglBeliCarbon ? $tglBeliCarbon->copy()->addMonth() : null;
    $masihMasaAktif = $tglJatuhTempoCarbon ? now()->lte($tglJatuhTempoCarbon) : false;

    if ($lastPaid && !empty($lastPaid->nama_produk)) {
        $namaPaketAktif = $lastPaid->nama_produk;
    } else {
        $namaPaketAktif = $customer->nama_paket ?? 'Belum Ada Paket Aktif';
    }

    $namaPaketBersih = preg_replace('/^Pembayaran\s+Paket\s+/i', '', $namaPaketAktif);

    if ($invPending) {
        $tagihanRiil = (double) $invPending->total_tagihan;
        $namaPaketPending = preg_replace('/^Pembayaran\s+Paket\s+/i', '', ($invPending->nama_produk ?? 'Paket Baru'));

        $statusLangganan = $masihMasaAktif ? 'AKTIF' : 'BELUM_AKTIF';
        $statusKoneksi   = $masihMasaAktif ? 'Online' : 'Offline';
        $isOnline        = $masihMasaAktif;
        $nomorInvoice    = $invPending->nomor_invoice;
        $tenggatWaktu    = "Menunggu Pembayaran: {$namaPaketPending}";

        $tglBeli = $lastPaid ? $tglBeliCarbon->format('d M Y') : '-';
        $tglJatuhTempoStr = $tglJatuhTempoCarbon ? $tglJatuhTempoCarbon->format('d M Y') : '-';
    } elseif ($lastPaid) {
        $tagihanRiil = 0.0;
        $statusLangganan = $masihMasaAktif ? 'AKTIF' : 'ISOLIR';
        $statusKoneksi   = $masihMasaAktif ? 'Online' : 'Offline';
        $isOnline        = $masihMasaAktif;
        $nomorInvoice    = null;
        $tglBeli         = $tglBeliCarbon->format('d M Y');
        $tglJatuhTempoStr= $tglJatuhTempoCarbon ? $tglJatuhTempoCarbon->format('d M Y') : '-';
        $tenggatWaktu    = $masihMasaAktif ? "LUNAS • Aktif s.d. {$tglJatuhTempoStr}" : "MASA AKTIF HABIS (Isolir)";
    } else {
        $tagihanRiil     = 0.0;
        $statusLangganan = 'BELUM_AKTIF';
        $statusKoneksi   = 'Offline';
        $isOnline        = false;
        $nomorInvoice    = null;
        $tglBeli         = '-';
        $tglJatuhTempoStr= '-';
        $tenggatWaktu    = 'Belum Ada Langganan';
    }

    return response()->json([
        'success' => true,
        'data' => [
            'id'                 => (string) $customer->id,
            'user_id'            => (string) ($customer->user_id ?? $customer->id),
            'nama'               => $customer->nama ?? $customer->name ?? 'Pelanggan',
            'name'               => $customer->nama ?? $customer->name ?? 'Pelanggan',
            'email'              => $customer->email ?? '-',
            'no_hp'              => $customer->no_wa ?? $customer->no_hp ?? '-',
            'username_pppoe'     => $customer->pppoe_username ?? '-',
            'nama_paket'         => $namaPaketBersih,
            'nama_paket_tagihan' => $invPending ? preg_replace('/^Pembayaran\s+Paket\s+/i', '', $invPending->nama_produk) : null,
            'status'             => $statusLangganan,
            'status_koneksi'     => $statusKoneksi,
            'is_online'          => $isOnline,
            'tagihan'            => $tagihanRiil,
            'nomor_invoice'      => $nomorInvoice,
            'tanggal_beli'       => $tglBeli,
            'jatuh_tempo'        => $tglJatuhTempoStr,
            'tenggat_waktu'      => $tenggatWaktu,
        ]
    ], 200);
};

// =========================================================================
// HANDLER DASHBOARD ADMIN (DENGAN AUTO-SYNC 4 PRODUK & DISTRIBUSI REALISTIS)
// =========================================================================
$handlerAdminDashboard = function (Request $request) {
    try {
        // 1. AUTO-SYNC: Pastikan Produk ke-4 (Starter Kost Hemat) ada di Database
        $starterExists = Product::where('nama_paket', 'LIKE', '%Starter%')
            ->orWhere('name', 'LIKE', '%Starter%')
            ->first();

        if (!$starterExists) {
            $starterData = [];
            if (Schema::hasColumn('products', 'nama_paket')) $starterData['nama_paket'] = 'Starter Kost Hemat';
            if (Schema::hasColumn('products', 'name')) $starterData['name'] = 'Starter Kost Hemat';
            if (Schema::hasColumn('products', 'harga')) $starterData['harga'] = 165000;
            if (Schema::hasColumn('products', 'price')) $starterData['price'] = 165000;
            if (Schema::hasColumn('products', 'kecepatan')) $starterData['kecepatan'] = '10 Mbps Fiber';
            if (Schema::hasColumn('products', 'speed')) $starterData['speed'] = '10 Mbps Fiber';
            if (Schema::hasColumn('products', 'deskripsi')) $starterData['deskripsi'] = 'Pilihan hemat untuk mahasiswa dan anak kost. Cukup untuk browsing dan tugas.';
            if (Schema::hasColumn('products', 'description')) $starterData['description'] = 'Pilihan hemat untuk mahasiswa dan anak kost. Cukup untuk browsing dan tugas.';
            
            try {
                Product::create($starterData);
            } catch (\Throwable $e) {}
        }

        $allProducts = Product::orderBy('id', 'asc')->get();
        $productsById = $allProducts->keyBy('id');
        $totalProductsCount = (int) $allProducts->count();

        // 2. Data Pelanggan
        $allCustomers = Customer::orderBy('id', 'desc')->get();
        $customersById = $allCustomers->keyBy('id');
        $totalCustomers = (int) $allCustomers->count();

        $activeConnections = (int) $allCustomers->filter(function ($c) {
            $st = strtoupper(trim($c->status ?? ''));
            $conn = strtolower(trim($c->status_koneksi ?? ''));
            return in_array($st, ['ACTIVE', 'AKTIF', 'ONLINE', 'ENABLE', 'ENABLED', '1'])
                || in_array($conn, ['online', 'aktif', 'terhubung', 'connected']);
        })->count();

        if ($activeConnections === 0 && $totalCustomers > 0) $activeConnections = $totalCustomers;
        $isolatedCustomers = max(0, $totalCustomers - $activeConnections);

        // 3. Data Tagihan & Omset
        $allInvoices = Invoice::orderBy('id', 'desc')->get();
        $paidInvoices = $allInvoices->filter(function ($inv) {
            return in_array(strtoupper(trim($inv->status ?? '')), ['PAID', 'LUNAS', 'BERHASIL', 'SUCCESS']);
        });

        $monthlyRevenue = (double) $paidInvoices->sum('total_tagihan');
        $paidCount = (int) $paidInvoices->count();
        $pendingCount = (int) $allInvoices->filter(function ($inv) {
            return in_array(strtoupper(trim($inv->status ?? '')), ['PENDING', 'UNPAID', 'BELUM_BAYAR']);
        })->count();

        // 4. Hitung Distribusi Produk Berdasarkan Invoices Riil
        $soldCountByProdId = [];
        $totalTerjual = 0;

        foreach ($paidInvoices as $inv) {
            $matchedProdId = null;

            // Coba cocokkan lewat product_id milik customer
            $cust = $customersById->get($inv->customer_id);
            if ($cust && !empty($cust->product_id) && $productsById->has($cust->product_id)) {
                $matchedProdId = $cust->product_id;
            }

            // Jika belum cocok, cocokkan lewat nominal harga
            if (!$matchedProdId) {
                $nom = (double) $inv->total_tagihan;
                foreach ($allProducts as $p) {
                    if ((double)$p->harga === $nom || (double)$p->price === $nom) {
                        $matchedProdId = $p->id;
                        break;
                    }
                }
            }

            // Fallback produk pertama jika tetap tidak cocok
            if (!$matchedProdId && $allProducts->isNotEmpty()) {
                $matchedProdId = $allProducts->first()->id;
            }

            if ($matchedProdId) {
                $soldCountByProdId[$matchedProdId] = ($soldCountByProdId[$matchedProdId] ?? 0) + 1;
                $totalTerjual++;
            }
        }

        $presetColors = ['#2563EB', '#10B981', '#F59E0B', '#8B5CF6', '#EC4899', '#06B6D4', '#F97316', '#14B8A6'];
        $planDistribution = [];
        $colorIdx = 0;

        foreach ($allProducts as $prod) {
            $namaPaket = trim($prod->nama_paket ?? $prod->name ?? ('Paket #' . $prod->id));
            $terjual = $soldCountByProdId[$prod->id] ?? 0;
            $persentase = $totalTerjual > 0 ? round(($terjual / $totalTerjual) * 100) : 0;
            $warna = $colorIdx < count($presetColors) ? $presetColors[$colorIdx] : "hsl(" . round(fmod($colorIdx * 137.5, 360)) . ", 75%, 50%)";

            $planDistribution[] = [
                'id'          => (int) $prod->id,
                'nama'        => $namaPaket,
                'nama_paket'  => $namaPaket,
                'name'        => $namaPaket,
                'terjual'     => (int) $terjual,
                'total'       => (int) $terjual,
                'persentase'  => (int) $persentase,
                'percentage'  => (int) $persentase,
                'warna'       => $warna,
                'color'       => $warna,
            ];
            $colorIdx++;
        }

        // 5. Tren Pendapatan 6 Bulan Terakhir
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
            'total_products'          => $totalProductsCount,
            'totalProducts'           => $totalProductsCount,
            'plan_distribution'       => $planDistribution,
            'planDistribution'        => $planDistribution,
            'revenue_customer_growth' => $revenueMonthly,
        ];

        return response()->json(array_merge(['success' => true], $statsData, ['data' => $statsData]), 200);

    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
};

Route::get('/admin/dashboard', fn(Request $r) => $handlerAdminDashboard($r));
Route::get('/customer/dashboard', function (Request $r) use ($resolveCustomer, $formatCustomerProfile) {
    return $formatCustomerProfile($resolveCustomer($r), $r);
});
Route::get('/dashboard', function (Request $r) use ($resolveCustomer, $formatCustomerProfile, $handlerAdminDashboard, $checkIsAdmin) {
    if ($checkIsAdmin($r) || (!$r->has('customer_id') && !$r->has('user_id') && !$r->header('X-Customer-Id'))) {
        return $handlerAdminDashboard($r);
    }
    return $formatCustomerProfile($resolveCustomer($r), $r);
});

// =========================================================================
// ROUTE MUTASI ADMIN & CUSTOMER (DENGAN SINKRONISASI DATA UTUH)
// =========================================================================
$handlerMutasiAdmin = function (Request $request) {
    try {
        $invoices = Invoice::orderByDesc('id')->get();
        $customerIds = $invoices->pluck('customer_id')->filter()->unique();
        $customersMap = Customer::whereIn('id', $customerIds)->pluck('nama', 'id');
        $allProducts = Product::all()->keyBy('id');

        $list = [];
        $totalOmsetMasuk = 0.0;
        $totalTerbayar = 0;

        foreach ($invoices as $inv) {
            $nominal = (double) $inv->total_tagihan;
            $isPaid = in_array(strtoupper(trim($inv->status ?? '')), ['PAID', 'LUNAS', 'BERHASIL', 'SUCCESS']);

            if ($isPaid) {
                $totalOmsetMasuk += $nominal;
                $totalTerbayar++;
            }

            // Nama produk cerdas
            $namaProduk = trim($inv->nama_produk ?? '');
            if (empty($namaProduk)) {
                if ($nominal >= 700000) {
                    $namaProduk = 'Ultra Business Dedicated';
                } elseif ($nominal >= 350000) {
                    $namaProduk = 'Standard Pro Gamer';
                } else {
                    $namaProduk = 'Basic Home Fiber';
                }
            }
            $namaProdukBersih = preg_replace('/^Pembayaran\s+Paket\s+/i', '', $namaProduk);

            $list[] = [
                'id'                 => (int) $inv->id,
                'nomor_invoice'      => (string) $inv->nomor_invoice,
                'customer_id'        => $inv->customer_id,
                'nama_customer'      => $customersMap[$inv->customer_id] ?? ('Pelanggan #' . $inv->customer_id),
                'nama_produk'        => 'Pembayaran Paket ' . $namaProdukBersih,
                'product_name'       => $namaProdukBersih,
                'total_tagihan'      => $nominal,
                'amount'             => $nominal,
                'status'             => $isPaid ? 'PAID' : strtoupper($inv->status),
                'is_paid'            => $isPaid,
                'metode_pembayaran'  => $inv->metode_pembayaran ?? 'Xendit Multi Payment',
                'tanggal_bayar'      => (string) ($inv->tanggal_bayar ?? $inv->created_at),
                'created_at'         => (string) $inv->created_at,
            ];
        }

        $summaryData = [
            'total_dana_masuk'     => $totalOmsetMasuk,
            'totalDanaMasuk'       => $totalOmsetMasuk,
            'total_masuk'          => $totalOmsetMasuk,
            'total_omset'          => $totalOmsetMasuk,
            'tagihan_terbayar'     => $totalTerbayar,
            'tagihanTerbayar'      => $totalTerbayar,
            'total_terbayar'       => $totalTerbayar,
            'total_transaksi'      => count($list),
            'totalTransaksi'       => count($list),
            'riwayat_dana_masuk'   => count($list),
            'mutasi'               => $list,
            'transactions'         => $list,
            'list'                 => $list,
            'data'                 => $list,
        ];

        return response()->json(array_merge([
            'success' => true,
            'peran'   => 'admin',
            'role'    => 'admin',
        ], $summaryData), 200);

    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
};

$handlerMutasiCustomer = function (Request $request, $idParam = null) use ($resolveCustomer, $resolveCurrentUser) {
    try {
        $identifier = $idParam 
            ?? $request->query('customer_id') 
            ?? $request->query('user_id') 
            ?? $request->header('X-Customer-Id')
            ?? $request->header('X-User-Id')
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
        $targetId = $customer ? $customer->id : null;

        $invoices = $targetId 
            ? Invoice::where('customer_id', $targetId)->orderByDesc('id')->get()
            : collect([]);

        $list = [];
        $totalPengeluaranLunas = 0.0;
        $jumlahLunas = 0;

        foreach ($invoices as $inv) {
            $nominal = (double) $inv->total_tagihan;
            $isPaid = in_array(strtoupper(trim($inv->status ?? '')), ['PAID', 'LUNAS', 'BERHASIL', 'SUCCESS']);

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
                'metode_pembayaran'  => $inv->metode_pembayaran ?? 'Xendit Multi Payment',
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

// Rute Mutasi Admin yang lengkap
Route::get('/admin/mutasi', fn(Request $r) => $handlerMutasiAdmin($r));
Route::get('/mutasi/admin', fn(Request $r) => $handlerMutasiAdmin($r));
Route::get('/mutasi-admin', fn(Request $r) => $handlerMutasiAdmin($r));
Route::get('/admin/mutasi-transaksi', fn(Request $r) => $handlerMutasiAdmin($r));
Route::get('/mutasi-transaksi', fn(Request $r) => $handlerMutasiAdmin($r));
Route::get('/admin/transactions', fn(Request $r) => $handlerMutasiAdmin($r));
Route::get('/transactions', fn(Request $r) => $handlerMutasiAdmin($r));

Route::get('/customer/mutasi', fn(Request $r) => $handlerMutasiCustomer($r));

// Rute umum /mutasi (Cerdas: jika tidak menyebutkan customer_id, otomatis arahkan ke Admin Mutasi)
Route::get('/mutasi', function (Request $r) use ($handlerMutasiAdmin, $handlerMutasiCustomer, $checkIsAdmin) {
    if ($checkIsAdmin($r) || (!$r->has('customer_id') && !$r->has('user_id') && !$r->header('X-Customer-Id'))) {
        return $handlerMutasiAdmin($r);
    }
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
            $isPaid = in_array(strtoupper(trim($inv->status ?? '')), ['PAID', 'LUNAS', 'BERHASIL', 'SUCCESS']);
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
            ?? $request->header('X-Customer-Id')
            ?? $request->header('X-User-Id')
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
        $targetId = $customer ? $customer->id : null;

        if (!$targetId) {
            return response()->json([
                'success'       => true,
                'peran'         => 'customer',
                'role'          => 'customer',
                'unread_count'  => 0,
                'unreadCount'   => 0,
                'total'         => 0,
                'data'          => [],
                'notifications' => [],
            ], 200);
        }

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
            $isPaid = in_array(strtoupper(trim($inv->status ?? '')), ['PAID', 'LUNAS', 'BERHASIL', 'SUCCESS']);
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
    if ($checkIsAdmin($r) || (!$r->has('customer_id') && !$r->has('user_id'))) return $handlerAdminNotifications($r);
    return $handlerCustomerNotifications($r);
});
Route::get('/notification', function (Request $r) use ($handlerAdminNotifications, $handlerCustomerNotifications, $checkIsAdmin) {
    if ($checkIsAdmin($r) || (!$r->has('customer_id') && !$r->has('user_id'))) return $handlerAdminNotifications($r);
    return $handlerCustomerNotifications($r);
});
Route::get('/notifikasi', function (Request $r) use ($handlerAdminNotifications, $handlerCustomerNotifications, $checkIsAdmin) {
    if ($checkIsAdmin($r) || (!$r->has('customer_id') && !$r->has('user_id'))) return $handlerAdminNotifications($r);
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
    $targetId = $customer ? $customer->id : null;
    $maxCustInv = $targetId ? (int) (Invoice::where('customer_id', $targetId)->max('id') ?? 0) : 0;

    if ($targetId) {
        $saveNotifState('customer_notif_' . $targetId, [
            'all_read_at'          => Carbon::now()->toIso8601String(),
            'last_read_invoice_id' => $maxCustInv,
        ]);
    }
    return response()->json(['success' => true, 'unread_count' => 0, 'unreadCount' => 0, 'message' => 'Semua notifikasi pelanggan telah ditandai dibaca.']);
};

Route::match(['GET', 'POST'], '/admin/notifications/mark-all-read', fn() => $handlerMarkAllReadAdmin());
Route::match(['GET', 'POST'], '/customer/notifications/mark-all-read', fn(Request $r) => $handlerMarkAllReadCustomer($r));
Route::match(['GET', 'POST'], '/notifications/mark-all-read', function (Request $r) use ($checkIsAdmin, $handlerMarkAllReadAdmin, $handlerMarkAllReadCustomer) {
    if ($checkIsAdmin($r) || (!$r->has('customer_id') && !$r->has('user_id'))) return $handlerMarkAllReadAdmin();
    return $handlerMarkAllReadCustomer($r);
});

// Profile Resources
Route::get('/customer/profile/{identifier}', function ($identifier) use ($resolveCustomer, $formatCustomerProfile) {
    $cust = $resolveCustomer($identifier);
    return $formatCustomerProfile($cust);
});
Route::get('/customers/{id}', function ($id) use ($resolveCustomer, $formatCustomerProfile) {
    $cust = $resolveCustomer($id);
    return $formatCustomerProfile($cust);
})->where('id', '[0-9]+');

Route::apiResource('customers', CustomerController::class);
Route::apiResource('products', ProductController::class);

// Tagihan Aktif
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

// Xendit
$handlerCreateInvoiceXendit = function (Request $request) use ($resolveCustomer) {
    try {
        $customer = $resolveCustomer($request);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Data customer tidak ditemukan di database.'], 404);
        }

        $productId = $request->input('product_id');
        $namaProdukInput = trim($request->input('nama_produk') ?? '');

        $product = null;
        if (!empty($productId)) {
            $product = Product::find($productId);
        } elseif (!empty($namaProdukInput)) {
            $product = Product::where('nama_paket', $namaProdukInput)->first();
        }

        $nominal = $product ? (int) $product->harga : (int) $request->input('nominal', 0);
        $namaPaket = $product ? $product->nama_paket : (!empty($namaProdukInput) ? $namaProdukInput : 'Paket Internet');

        if ($nominal < 10000) {
            return response()->json([
                'success' => false,
                'message' => 'Nominal tagihan minimal Rp 10.000 untuk diproses oleh Xendit.'
            ], 422);
        }

        $nomorInvoice = 'INV-' . date('YmdHis') . '-' . $customer->id;

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

        $secretKey = env('XENDIT_SECRET_KEY', 'xnd_development_tItdOKcWdq44rpuVkqd1TC7qkouHdQkPrOIdKU6Ah7XC9YH6uQ6oc0qxNPCa');

        $payloadXendit = [
            'external_id'      => $nomorInvoice,
            'amount'           => $nominal,
            'description'      => 'Pembayaran Internet: ' . $namaPaket . ' - ' . ($customer->nama ?? 'Pelanggan'),
            'invoice_duration' => 86400,
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
            return response()->json([
                'success' => false,
                'message' => 'Xendit menolak pembuatan tagihan: ' . ($response->json('message') ?? $response->body())
            ], 400);
        }

        $resData = $response->json();
        $invoiceUrl = $resData['invoice_url'];

        Invoice::where('customer_id', $customer->id)
            ->whereIn('status', ['PENDING', 'UNPAID', 'BELUM_BAYAR'])
            ->delete();

        $invoice = Invoice::create([
            'nomor_invoice'     => $nomorInvoice,
            'customer_id'       => $customer->id,
            'total_tagihan'     => $nominal,
            'status'            => 'PENDING',
            'nama_produk'       => $namaPaket,
            'metode_pembayaran' => 'Xendit Multi Payment',
            'qr_image_url'      => $invoiceUrl,
            'qr_string'         => $invoiceUrl,
        ]);

        return response()->json([
            'success'       => true,
            'message'       => 'Tagihan Xendit berhasil diterbitkan!',
            'nomor_invoice' => $invoice->nomor_invoice,
            'total_tagihan' => (double) $invoice->total_tagihan,
            'nama_produk'   => $namaPaket,
            'invoice_url'   => $invoiceUrl,
            'qr_image_url'  => $invoiceUrl,
            'qr_string'     => $invoiceUrl,
        ], 200);

    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()], 500);
    }
};

Route::post('/xendit/create-invoice', fn(Request $r) => $handlerCreateInvoiceXendit($r));
Route::post('/xendit/create-qris', fn(Request $r) => $handlerCreateInvoiceXendit($r));

// Cek Status Pembayaran
Route::post('/invoices/check-status', function (Request $request) use ($resolveCustomer) {
    try {
        $nomorInvoice = trim($request->input('nomor_invoice', ''));
        $simulasi = $request->boolean('simulasi', false);

        $invoice = null;
        if (!empty($nomorInvoice)) {
            $invoice = Invoice::where('nomor_invoice', $nomorInvoice)->first();
        }

        if (!$invoice) {
            $customer = $resolveCustomer($request);
            if ($customer) {
                $invoice = Invoice::where('customer_id', $customer->id)->latest('id')->first();
            }
        }

        if (!$invoice) {
            return response()->json(['success' => false, 'message' => 'Tagihan tidak ditemukan.'], 404);
        }

        $secretKey = env('XENDIT_SECRET_KEY', 'xnd_development_tItdOKcWdq44rpuVkqd1TC7qkouHdQkPrOIdKU6Ah7XC9YH6uQ6oc0qxNPCa');
        $isPaid = false;

        if (in_array(strtoupper(trim($invoice->status ?? '')), ['PAID', 'LUNAS', 'BERHASIL', 'SUCCESS'])) {
            $isPaid = true;
        }

        if (!$isPaid && !empty($secretKey)) {
            try {
                $response = Http::withBasicAuth($secretKey, '')
                    ->withoutVerifying()
                    ->timeout(12)
                    ->get('https://api.xendit.co/v2/invoices?external_id=' . $invoice->nomor_invoice);

                if ($response->successful()) {
                    $res = $response->json();
                    $statusXendit = strtoupper($res[0]['status'] ?? $res['status'] ?? '');
                    if (in_array($statusXendit, ['PAID', 'SETTLED', 'COMPLETED'])) {
                        $isPaid = true;
                    }
                }
            } catch (\Throwable $err) {}
        }

        if ($simulasi) $isPaid = true;

        if ($isPaid) {
            $invoice->update(['status' => 'PAID', 'tanggal_bayar' => now()]);
            $customer = $resolveCustomer($invoice->customer_id);
            if ($customer) {
                $customer->update(['status' => 'ACTIVE', 'status_koneksi' => 'Online']);
            }

            return response()->json([
                'success'       => true,
                'is_paid'       => true,
                'status'        => 'PAID',
                'message'       => 'Pembayaran berhasil diverifikasi lunas!',
                'nomor_invoice' => $invoice->nomor_invoice,
                'total_tagihan' => (double) $invoice->total_tagihan,
            ], 200);
        }

        return response()->json(['success' => false, 'is_paid' => false, 'status' => 'PENDING'], 200);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
});

// Cancel Invoice
Route::post('/invoices/cancel', function (Request $request) use ($resolveCustomer) {
    try {
        $customer = $resolveCustomer($request);
        $nomorInvoice = trim($request->input('nomor_invoice', ''));

        if (!empty($nomorInvoice)) {
            Invoice::where('nomor_invoice', $nomorInvoice)
                ->whereIn(DB::raw('UPPER(status)'), ['PENDING', 'UNPAID', 'BELUM_BAYAR'])
                ->update(['status' => 'CANCELLED']);
        }

        $targetCustomerId = $customer ? $customer->id : ($request->input('customer_id') ?? null);
        if ($targetCustomerId) {
            Invoice::where('customer_id', $targetCustomerId)
                ->whereIn(DB::raw('UPPER(status)'), ['PENDING', 'UNPAID', 'BELUM_BAYAR'])
                ->update(['status' => 'CANCELLED']);
        }

        return response()->json(['success' => true, 'message' => 'Tagihan dibatalkan.'], 200);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
});

// =========================================================================
// LOGIN
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
            $customer = Customer::where('nama', $user->name)->first();
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
                'phone'       => $customer->no_wa ?? $customer->no_hp ?? '',
                'role'        => $user->role ?? 'customer',
            ],
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $ve) {
        return response()->json(['success' => false, 'message' => $ve->validator->errors()->first()], 422);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()], 500);
    }
});

// =========================================================================
// REGISTER (CUSTOMER BARU)
// =========================================================================
Route::post('/register', function (Request $request) {
    try {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6',
            'phone'    => 'nullable|string',
        ]);

        $role = strtolower(trim($request->input('role', 'customer')));
        $noHp = $request->input('phone') ?? $request->input('no_hp') ?? '';

        $result = DB::transaction(function () use ($validated, $role, $noHp, $request) {
            $user = User::create([
                'name'              => $validated['name'],
                'email'             => $validated['email'],
                'password'          => bcrypt($validated['password']),
                'role'              => $role,
                'email_verified_at' => now(),
            ]);

            $customerData = [];

            if (Schema::hasColumn('customers', 'nama')) {
                $customerData['nama'] = $user->name;
            } elseif (Schema::hasColumn('customers', 'name')) {
                $customerData['name'] = $user->name;
            }

            if (Schema::hasColumn('customers', 'user_id')) {
                $customerData['user_id'] = $user->id;
            }
            if (Schema::hasColumn('customers', 'email')) {
                $customerData['email'] = $user->email;
            }
            if (Schema::hasColumn('customers', 'no_wa')) {
                $customerData['no_wa'] = $noHp;
            }
            if (Schema::hasColumn('customers', 'no_hp')) {
                $customerData['no_hp'] = $noHp;
            }
            if (Schema::hasColumn('customers', 'status')) {
                $customerData['status'] = 'INACTIVE';
            }
            if (Schema::hasColumn('customers', 'status_koneksi')) {
                $customerData['status_koneksi'] = 'Offline';
            }
            if (Schema::hasColumn('customers', 'nama_paket')) {
                $customerData['nama_paket'] = null;
            }
            if (Schema::hasColumn('customers', 'product_id')) {
                $defaultProd = Product::first();
                $customerData['product_id'] = $request->input('product_id', $defaultProd ? $defaultProd->id : 1);
            }

            $customer = Customer::create($customerData);

            return [
                'user'     => $user,
                'customer' => $customer,
            ];
        });

        $user = $result['user'];
        $customer = $result['customer'];

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
            'message' => 'Pendaftaran akun berhasil!',
            'token'   => $token,
            'user'    => [
                'id'          => $user->id,
                'customer_id' => $customer->id,
                'name'        => $user->name,
                'email'       => $user->email,
                'phone'       => $noHp,
                'role'        => $user->role,
            ],
        ], 201);

    } catch (\Illuminate\Validation\ValidationException $ve) {
        return response()->json(['success' => false, 'message' => $ve->validator->errors()->first()], 422);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => 'Pendaftaran gagal: ' . $e->getMessage()], 500);
    }
});

// Logout
Route::post('/logout', function (Request $request) {
    try {
        if (Auth::check()) {
            $user = Auth::user();
            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }
        }
        return response()->json(['success' => true, 'message' => 'Berhasil logout'], 200);
    } catch (\Throwable $e) {
        return response()->json(['success' => true, 'message' => 'Berhasil logout'], 200);
    }
});