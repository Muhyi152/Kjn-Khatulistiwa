<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;

use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\NetworkController;

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
        $explicitUserId = null;

        if ($identifier instanceof Request) {
            $req = $identifier;
            $reqUser = $resolveCurrentUser($req);

            $custId = $req->query('customer_id')
                ?? $req->input('customer_id')
                ?? $req->header('X-Customer-Id');

            $explicitUserId = $req->query('user_id')
                ?? $req->input('user_id')
                ?? $req->header('X-User-Id');

            $identifier = $custId
                ?? $req->query('id')
                ?? $req->input('id')
                ?? $req->query('email')
                ?? $req->input('email');

            if (empty($identifier) && empty($explicitUserId) && $reqUser) {
                $explicitUserId = $reqUser->id;
            }
        } elseif (is_array($identifier)) {
            $explicitUserId = $identifier['user_id'] ?? null;
            $identifier = $identifier['customer_id'] ?? $identifier['id'] ?? $identifier['email'] ?? null;
        }

        $hasUserIdCol = Schema::hasColumn('customers', 'user_id');
        $hasEmailCol  = Schema::hasColumn('customers', 'email');
        $hasNamaCol   = Schema::hasColumn('customers', 'nama');

        // 1. Jika user_id diketahui, prioritaskan pencarian via user_id/email/nama User
        if (!empty($explicitUserId)) {
            if ($hasUserIdCol) {
                $cByUser = Customer::where('user_id', $explicitUserId)->first();
                if ($cByUser) return $cByUser;
            }
            $u = User::find($explicitUserId);
            if ($u) {
                if ($hasEmailCol && !empty($u->email)) {
                    $c = Customer::where('email', $u->email)->first();
                    if ($c) return $c;
                }
                if ($hasNamaCol && !empty($u->name)) {
                    $c = Customer::where('nama', $u->name)->first();
                    if ($c) return $c;
                }
            }
        }

        // 2. Pencarian via customer_id / id / email / nama
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

        // 3. Fallback ke user yang sedang login
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
// HELPER GLOBAL: SINKRONISASI PRODUK DARI INVOICE (NAMA -> HARGA -> CUSTOMER)
// =========================================================================
$resolveProductFromInvoice = function ($invoice, $customer = null, $allProducts = null) {
    try {
        $products = $allProducts ?? Product::orderBy('id', 'asc')->get();
        if ($products->isEmpty()) return null;

        $rawName = trim((string) ($invoice->nama_produk ?? ''));
        $cleanName = strtolower(trim(preg_replace('/^Pembayaran\s+Paket\s+/i', '', $rawName)));

        // 1. Cocokkan nama_produk pada invoice dengan tabel products
        if (!empty($cleanName)) {
            foreach ($products as $p) {
                $pName = strtolower(trim((string) ($p->nama_paket ?? $p->name ?? '')));
                if ($pName !== '' && ($pName === $cleanName || str_contains($cleanName, $pName) || str_contains($pName, $cleanName))) {
                    return $p;
                }
            }
        }

        // 2. Cocokkan nominal tagihan dengan harga produk
        $nom = (double) ($invoice->total_tagihan ?? 0);
        if ($nom > 0) {
            foreach ($products as $p) {
                $pHarga = (double) ($p->harga ?? $p->price ?? 0);
                if ($pHarga > 0 && abs($pHarga - $nom) < 1) {
                    return $p;
                }
            }
        }

        // 3. Fallback ke product_id milik customer
        if ($customer && !empty($customer->product_id)) {
            $byId = $products->firstWhere('id', $customer->product_id);
            if ($byId) return $byId;
        }
    } catch (\Throwable $e) {}

    return null;
};

// =========================================================================
// HELPER 2: PENYIMPANAN NOTIFIKASI LANGSUNG KE MYSQL (`notifications`)
// =========================================================================
$resolveCustomerUserId = function ($customer) {
    if (!$customer) return null;
    if (!empty($customer->user_id)) return (int) $customer->user_id;
    if (!empty($customer->email)) {
        $u = User::where('email', $customer->email)->first();
        if ($u) return (int) $u->id;
    }
    return (int) $customer->id;
};

$createNotification = function (?int $userId, string $roleTarget, string $title, string $message, string $type = 'INFO', $createdAt = null) {
    try {
        if (!Schema::hasTable('notifications')) return;

        $timestamp = $createdAt ? Carbon::parse($createdAt) : now();
        $payload = [
            'user_id'     => $userId,
            'role_target' => strtolower(trim($roleTarget)),
            'title'       => $title,
            'message'     => $message,
            'type'        => strtoupper(trim($type)),
            'is_read'     => 0,
            'created_at'  => $timestamp,
            'updated_at'  => $timestamp,
        ];

        try {
            DB::table('notifications')->insert($payload);
        } catch (\Throwable $insertErr) {
            // Fallback jika kolom user_id di DB diset NOT NULL (tidak nullable)
            if ($userId === null) {
                $adminUserId = User::whereIn(DB::raw('LOWER(role)'), ['admin', 'administrator'])->value('id') ?? 1;
                $payload['user_id'] = $adminUserId;
                DB::table('notifications')->insert($payload);
            } else {
                throw $insertErr;
            }
        }
    } catch (\Throwable $e) {
        Log::error('Gagal simpan notifikasi: ' . $e->getMessage());
    }
};

// Sinkronisasi otomatis satu kali jika tabel notifications masih kosong tapi sudah ada riwayat invoice
$syncOldInvoicesIfEmpty = function () use ($createNotification, $resolveCustomerUserId, $resolveProductFromInvoice) {
    try {
        if (!Schema::hasTable('notifications')) return;
        if (DB::table('notifications')->count() > 0) return;

        $invoices = Invoice::orderBy('id', 'asc')->get();
        if ($invoices->isEmpty()) return;

        $allProducts = Product::orderBy('id', 'asc')->get();

        foreach ($invoices as $inv) {
            $cust = Customer::find($inv->customer_id);
            $namaCust = $cust ? ($cust->nama ?? $cust->name ?? ('Pelanggan #' . $inv->customer_id)) : ('Pelanggan #' . $inv->customer_id);

            $rawProduk = trim((string) ($inv->nama_produk ?? ''));
            if (empty($rawProduk)) {
                $matchedProd = $resolveProductFromInvoice($inv, $cust, $allProducts);
                $rawProduk = $matchedProd
                    ? ($matchedProd->nama_paket ?? $matchedProd->name ?? 'Paket Internet')
                    : ($cust->nama_paket ?? 'Paket Internet');
            }

            $namaPaket   = preg_replace('/^Pembayaran\s+Paket\s+/i', '', $rawProduk);
            $nominalFmt  = 'Rp ' . number_format((double) $inv->total_tagihan, 0, ',', '.');
            $statusUpper = strtoupper(trim($inv->status ?? ''));
            $isPaid      = in_array($statusUpper, ['PAID', 'LUNAS', 'BERHASIL', 'SUCCESS']);
            $isCancelled = in_array($statusUpper, ['CANCELLED', 'BATAL']);
            $custUserId  = $resolveCustomerUserId($cust);
            $eventTime   = $inv->tanggal_bayar ?? $inv->created_at ?? now();

            if ($isPaid) {
                $createNotification(
                    $custUserId,
                    'customer',
                    'Pembayaran Berhasil Diverifikasi',
                    "Pembayaran {$inv->nomor_invoice} paket {$namaPaket} sebesar {$nominalFmt} telah berhasil diverifikasi lunas.",
                    'SUCCESS',
                    $eventTime
                );
                $createNotification(
                    null,
                    'admin',
                    'Pembayaran Berhasil Diterima',
                    "Pembayaran {$inv->nomor_invoice} dari {$namaCust} paket {$namaPaket} sebesar {$nominalFmt} telah lunas.",
                    'SUCCESS',
                    $eventTime
                );
            } elseif (!$isCancelled) {
                $createNotification(
                    $custUserId,
                    'customer',
                    'Tagihan Internet Baru Diterbitkan',
                    "Tagihan {$inv->nomor_invoice} untuk paket {$namaPaket} sebesar {$nominalFmt} telah diterbitkan. Silakan lakukan pembayaran.",
                    'WARNING',
                    $inv->created_at ?? now()
                );
                $createNotification(
                    null,
                    'admin',
                    'Tagihan Baru Menunggu Pembayaran',
                    "Tagihan {$inv->nomor_invoice} dari {$namaCust} untuk paket {$namaPaket} sebesar {$nominalFmt} menunggu konfirmasi bayar.",
                    'WARNING',
                    $inv->created_at ?? now()
                );
            }
        }
    } catch (\Throwable $e) {
        Log::error('Gagal sync notifikasi lama: ' . $e->getMessage());
    }
};

// =========================================================================
// HELPER FORMAT PROFIL CUSTOMER
// =========================================================================
$formatCustomerProfile = function ($customer = null, Request $request = null) use ($resolveCustomer, $resolveCurrentUser, $resolveProductFromInvoice) {
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
                'alamat'             => '-',
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

    $tglBeliCarbon = $lastPaid ? Carbon::parse($lastPaid->tanggal_bayar ?? $lastPaid->updated_at ?? $lastPaid->created_at) : null;
    $tglJatuhTempoCarbon = $tglBeliCarbon ? $tglBeliCarbon->copy()->addMonth() : null;
    $masihMasaAktif = $tglJatuhTempoCarbon ? now()->lte($tglJatuhTempoCarbon) : false;

    if ($lastPaid) {
        if (!empty($lastPaid->nama_produk)) {
            $namaPaketAktif = $lastPaid->nama_produk;
        } else {
            $matchedProd = $resolveProductFromInvoice($lastPaid, $customer);
            $namaPaketAktif = $matchedProd
                ? ($matchedProd->nama_paket ?? $matchedProd->name ?? 'Paket Aktif')
                : ($customer->nama_paket ?? 'Belum Ada Paket Aktif');
        }
    } else {
        $namaPaketAktif = $customer->nama_paket ?? 'Belum Ada Paket Aktif';
    }

    $namaPaketBersih = preg_replace('/^Pembayaran\s+Paket\s+/i', '', $namaPaketAktif);

    if ($invPending) {
        $tagihanRiil = (double) $invPending->total_tagihan;
        $pendingProd = empty($invPending->nama_produk) ? $resolveProductFromInvoice($invPending, $customer) : null;
        $rawPendingName = !empty($invPending->nama_produk)
            ? $invPending->nama_produk
            : ($pendingProd ? ($pendingProd->nama_paket ?? $pendingProd->name) : 'Paket Baru');
        $namaPaketPending = preg_replace('/^Pembayaran\s+Paket\s+/i', '', $rawPendingName);

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
            'no_hp'              => ($customer->no_wa ?? null) ?: (($customer->no_hp ?? null) ?: '-'),
            'alamat'             => ($customer->alamat ?? null) ?: (($customer->address ?? null) ?: (($customer->alamat_pemasangan ?? null) ?: '-')),
            'username_pppoe'     => ($customer->pppoe_username ?? null) ?: (($customer->username_pppoe ?? null) ?: '-'),
            'nama_paket'         => $namaPaketBersih,
            'nama_paket_tagihan' => $invPending ? $namaPaketPending : null,
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
// HANDLER DASHBOARD ADMIN (AUTO-SYNC PRODUK, HARIAN & BULANAN RIIL WIB)
// =========================================================================
$handlerAdminDashboard = function (Request $request) use ($resolveProductFromInvoice) {
    try {
        // 1. AUTO-SYNC: Pastikan Produk ke-4 (Starter Kost Hemat) ada di Database
        $starterQuery = Product::query();
        $hasNamaPaket = Schema::hasColumn('products', 'nama_paket');
        $hasName      = Schema::hasColumn('products', 'name');

        if ($hasNamaPaket && $hasName) {
            $starterExists = $starterQuery->where('nama_paket', 'LIKE', '%Starter%')
                ->orWhere('name', 'LIKE', '%Starter%')->first();
        } elseif ($hasNamaPaket) {
            $starterExists = $starterQuery->where('nama_paket', 'LIKE', '%Starter%')->first();
        } elseif ($hasName) {
            $starterExists = $starterQuery->where('name', 'LIKE', '%Starter%')->first();
        } else {
            $starterExists = null;
        }

        if (!$starterExists) {
            $starterData = [];
            if ($hasNamaPaket) $starterData['nama_paket'] = 'Starter Kost Hemat';
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

        // 4. Distribusi Produk Berdasarkan Invoice Lunas
        $soldCountByProdId = [];
        $totalTerjual = 0;

        foreach ($paidInvoices as $inv) {
            $cust = $customersById->get($inv->customer_id);
            $matchedProd = $resolveProductFromInvoice($inv, $cust, $allProducts);
            $matchedProdId = $matchedProd ? $matchedProd->id : ($allProducts->isNotEmpty() ? $allProducts->first()->id : null);

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

        // 5. Tren Pendapatan 6 Bulan Terakhir (WIB)
        $now = Carbon::now('Asia/Jakarta');
        $groupedByMonth = [];

        for ($i = 5; $i >= 0; $i--) {
            $dateCursor = $now->copy()->startOfMonth()->subMonths($i);
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
            $carbon = $tgl ? Carbon::parse($tgl)->timezone('Asia/Jakarta') : $now->copy();
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

        // 6. Pendapatan Hari Ini & Grafik Harian (Senin - Minggu, Reset Otomatis Ganti Hari 00:00 WIB)
        $todayStr = $now->toDateString();
        $startOfWeek = $now->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $labelHari = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
        $harianMap = [];

        for ($i = 0; $i < 7; $i++) {
            $tglLoop = $startOfWeek->copy()->addDays($i)->toDateString();
            $harianMap[$tglLoop] = [
                'hari'    => $labelHari[$i],
                'bulan'   => $labelHari[$i],
                'tanggal' => $tglLoop,
                'total'   => 0.0,
            ];
        }

        $pendapatanHariIni = 0.0;

        foreach ($paidInvoices as $inv) {
            $tglRaw = $inv->tanggal_bayar ?? $inv->updated_at ?? $inv->created_at;
            if (!$tglRaw) continue;

            $carbonTgl = Carbon::parse($tglRaw)->timezone('Asia/Jakarta');
            $tglStr = $carbonTgl->toDateString();
            $nominal = (double) $inv->total_tagihan;

            if ($tglStr === $todayStr) {
                $pendapatanHariIni += $nominal;
            }
            if (isset($harianMap[$tglStr])) {
                $harianMap[$tglStr]['total'] += $nominal;
            }
        }

        $grafikHarian = array_values($harianMap);

        $statsData = [
            'total_customers'         => $totalCustomers,
            'totalCustomers'          => $totalCustomers,
            'active_connections'      => $activeConnections,
            'activeConnections'       => $activeConnections,
            'isolated_pending'        => $isolatedCustomers,
            'monthly_revenue'         => $monthlyRevenue,
            'monthlyRevenue'          => $monthlyRevenue,
            'pendapatan_hari_ini'     => $pendapatanHariIni,
            'grafik_harian'           => $grafikHarian,
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
Route::get('/dashboard', function (Request $r) use ($resolveCustomer, $formatCustomerProfile, $handlerAdminDashboard, $checkIsAdmin, $resolveCurrentUser) {
    if ($checkIsAdmin($r)) {
        return $handlerAdminDashboard($r);
    }
    if ($resolveCurrentUser($r) || $r->has('customer_id') || $r->has('user_id') || $r->header('X-Customer-Id') || $r->header('X-User-Id')) {
        return $formatCustomerProfile($resolveCustomer($r), $r);
    }
    return $handlerAdminDashboard($r);
});

// =========================================================================
// ROUTE MUTASI ADMIN & CUSTOMER
// =========================================================================
$handlerMutasiAdmin = function (Request $request) use ($resolveProductFromInvoice) {
    try {
        $invoices = Invoice::orderByDesc('id')->get();
        $customerIds = $invoices->pluck('customer_id')->filter()->unique();
        $customersMap = Customer::whereIn('id', $customerIds)->get()->keyBy('id');
        $allProducts = Product::orderBy('id', 'asc')->get();

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

            $cust = $customersMap->get($inv->customer_id);
            $namaProduk = trim($inv->nama_produk ?? '');
            if (empty($namaProduk)) {
                $matchedProd = $resolveProductFromInvoice($inv, $cust, $allProducts);
                $namaProduk = $matchedProd
                    ? ($matchedProd->nama_paket ?? $matchedProd->name ?? 'Basic Home Fiber')
                    : ($nominal >= 700000 ? 'Ultra Business Dedicated' : ($nominal >= 350000 ? 'Standard Pro Gamer' : 'Basic Home Fiber'));
            }
            $namaProdukBersih = preg_replace('/^Pembayaran\s+Paket\s+/i', '', $namaProduk);

            $tglBayarRaw = $inv->tanggal_bayar ?? $inv->created_at;
            $tglBayarIso = $tglBayarRaw
                ? Carbon::parse($tglBayarRaw)->timezone('Asia/Jakarta')->toIso8601String()
                : '';
            $createdAtIso = $inv->created_at
                ? Carbon::parse($inv->created_at)->timezone('Asia/Jakarta')->toIso8601String()
                : '';

            $list[] = [
                'id'                 => (int) $inv->id,
                'nomor_invoice'      => (string) $inv->nomor_invoice,
                'customer_id'        => $inv->customer_id,
                'nama_customer'      => $cust ? ($cust->nama ?? $cust->name ?? ('Pelanggan #' . $inv->customer_id)) : ('Pelanggan #' . $inv->customer_id),
                'nama_produk'        => 'Pembayaran Paket ' . $namaProdukBersih,
                'product_name'       => $namaProdukBersih,
                'total_tagihan'      => $nominal,
                'amount'             => $nominal,
                'status'             => $isPaid ? 'PAID' : strtoupper($inv->status),
                'is_paid'            => $isPaid,
                'metode_pembayaran'  => $inv->metode_pembayaran ?? 'Xendit Multi Payment',
                'tanggal_bayar'      => $tglBayarIso,
                'created_at'         => $createdAtIso,
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

$handlerMutasiCustomer = function (Request $request, $idParam = null) use ($resolveCustomer, $resolveProductFromInvoice) {
    try {
        $customer = !empty($idParam) ? $resolveCustomer($idParam) : null;
        if (!$customer) {
            $customer = $resolveCustomer($request);
        }

        $targetId = $customer ? $customer->id : null;

        $invoices = $targetId
            ? Invoice::where('customer_id', $targetId)->orderByDesc('id')->get()
            : collect([]);

        $allProducts = Product::orderBy('id', 'asc')->get();

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

            $rawProduk = trim($inv->nama_produk ?? '');
            if (empty($rawProduk)) {
                $matchedProd = $resolveProductFromInvoice($inv, $customer, $allProducts);
                $rawProduk = $matchedProd
                    ? ($matchedProd->nama_paket ?? $matchedProd->name ?? 'Basic Home Fiber')
                    : ($customer->nama_paket ?? 'Basic Home Fiber');
            }
            $namaProdukBersih = preg_replace('/^Pembayaran\s+Paket\s+/i', '', $rawProduk);

            $tglBayarRaw = $inv->tanggal_bayar ?? $inv->created_at;
            $tglBayarIso = $tglBayarRaw
                ? Carbon::parse($tglBayarRaw)->timezone('Asia/Jakarta')->toIso8601String()
                : '';
            $createdAtIso = $inv->created_at
                ? Carbon::parse($inv->created_at)->timezone('Asia/Jakarta')->toIso8601String()
                : '';

            $list[] = [
                'id'                 => (int) $inv->id,
                'nomor_invoice'      => (string) $inv->nomor_invoice,
                'customer_id'        => $inv->customer_id,
                'nama_customer'      => $customer ? ($customer->nama ?? $customer->name ?? 'Pelanggan') : 'Pelanggan',
                'nama_produk'        => 'Pembayaran Paket ' . $namaProdukBersih,
                'product_name'       => $namaProdukBersih,
                'total_tagihan'      => $nominal,
                'amount'             => $nominal,
                'status'             => strtoupper($inv->status),
                'is_paid'            => $isPaid,
                'metode_pembayaran'  => $inv->metode_pembayaran ?? 'Xendit Multi Payment',
                'tanggal_bayar'      => $tglBayarIso,
                'created_at'         => $createdAtIso,
            ];
        }

        return response()->json([
            'success'            => true,
            'peran'              => 'customer',
            'role'               => 'customer',
            'total_dana_masuk'   => $totalPengeluaranLunas,
            'total_pengeluaran'  => $totalPengeluaranLunas,
            'tagihan_terbayar'   => $jumlahLunas,
            'tagihan_lunas'      => $jumlahLunas,
            'total_transaksi'    => count($list),
            'data'               => $list,
            'mutasi'             => $list,
        ], 200);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
};

Route::get('/admin/mutasi', fn(Request $r) => $handlerMutasiAdmin($r));
Route::get('/mutasi/admin', fn(Request $r) => $handlerMutasiAdmin($r));
Route::get('/mutasi-admin', fn(Request $r) => $handlerMutasiAdmin($r));
Route::get('/admin/mutasi-transaksi', fn(Request $r) => $handlerMutasiAdmin($r));
Route::get('/mutasi-transaksi', fn(Request $r) => $handlerMutasiAdmin($r));
Route::get('/admin/transactions', fn(Request $r) => $handlerMutasiAdmin($r));
Route::get('/transactions', fn(Request $r) => $handlerMutasiAdmin($r));

Route::get('/customer/mutasi', fn(Request $r) => $handlerMutasiCustomer($r));
Route::get('/customer/mutasi/{id}', fn(Request $r, $id) => $handlerMutasiCustomer($r, $id));
Route::get('/customer/invoices', fn(Request $r) => $handlerMutasiCustomer($r));
Route::get('/customer/tagihan', fn(Request $r) => $handlerMutasiCustomer($r));

Route::get('/mutasi', function (Request $r) use ($handlerMutasiAdmin, $handlerMutasiCustomer, $checkIsAdmin, $resolveCurrentUser) {
    if ($checkIsAdmin($r)) {
        return $handlerMutasiAdmin($r);
    }
    if ($resolveCurrentUser($r) || $r->has('customer_id') || $r->has('user_id') || $r->header('X-Customer-Id') || $r->header('X-User-Id')) {
        return $handlerMutasiCustomer($r);
    }
    return $handlerMutasiAdmin($r);
});

// =========================================================================
// HANDLER NOTIFIKASI (TERHUBUNG LANGSUNG KE MYSQL `notifications`)
// =========================================================================
$extractTicketMapFromRows = function ($rows) {
    $ticketNumbers = [];
    foreach ($rows as $row) {
        $haystack = ($row->title ?? '') . ' ' . ($row->message ?? '');
        if (preg_match_all('/TKT-[A-Za-z0-9_-]+/', $haystack, $matches)) {
            foreach ($matches[0] as $tktNo) {
                $ticketNumbers[] = $tktNo;
            }
        }
    }
    $ticketNumbers = array_values(array_unique($ticketNumbers));
    if (empty($ticketNumbers) || !Schema::hasTable('tickets')) {
        return collect([]);
    }
    return DB::table('tickets')
        ->whereIn('nomor_tiket', $ticketNumbers)
        ->get()
        ->keyBy('nomor_tiket');
};

$formatNotifRow = function ($row, $ticketsMap = null) {
    $createdAt = $row->created_at
        ? Carbon::parse($row->created_at)->timezone('Asia/Jakarta')->toIso8601String()
        : Carbon::now('Asia/Jakarta')->toIso8601String();
    $isRead = (bool) $row->is_read;

    $nomorTiket = null;
    $ticketObj = null;
    $haystack = ($row->title ?? '') . ' ' . ($row->message ?? '');
    if (preg_match('/TKT-[A-Za-z0-9_-]+/', $haystack, $m)) {
        $nomorTiket = $m[0];
        if ($ticketsMap && $ticketsMap->has($nomorTiket)) {
            $ticketObj = $ticketsMap->get($nomorTiket);
        }
    }

    return [
        'id'              => (string) $row->id,
        'notif_id'        => (string) $row->id,
        'notification_id' => (string) $row->id,
        'user_id'         => $row->user_id,
        'role_target'     => $row->role_target,
        'tipe'            => strtoupper($row->type ?? 'INFO'),
        'type'            => strtoupper($row->type ?? 'INFO'),
        'judul'           => $row->title ?? 'Pemberitahuan',
        'title'           => $row->title ?? 'Pemberitahuan',
        'pesan'           => $row->message ?? '',
        'message'         => $row->message ?? '',
        'body'            => $row->message ?? '',
        'nomor_tiket'     => $nomorTiket,
        'status_tiket'    => $ticketObj ? strtoupper((string) $ticketObj->status) : null,
        'kategori_tiket'  => $ticketObj ? (string) $ticketObj->kategori : null,
        'tanggal'         => $createdAt,
        'waktu'           => $createdAt,
        'created_at'      => $createdAt,
        'timestamp'       => Carbon::parse($createdAt)->timestamp,
        'is_read'         => $isRead,
        'sudah_dibaca'    => $isRead,
    ];
};

$handlerAdminNotifications = function (Request $request) use ($formatNotifRow, $extractTicketMapFromRows, $syncOldInvoicesIfEmpty) {
    try {
        $syncOldInvoicesIfEmpty();

        $rows = DB::table('notifications')
            ->where('role_target', 'admin')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $ticketsMap = $extractTicketMapFromRows($rows);
        $notifications = $rows->map(fn($r) => $formatNotifRow($r, $ticketsMap))->values()->all();
        $unreadCount = $rows->where('is_read', 0)->count();

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

$handlerCustomerNotifications = function (Request $request) use ($resolveCustomer, $handlerAdminNotifications, $checkIsAdmin, $formatNotifRow, $extractTicketMapFromRows, $syncOldInvoicesIfEmpty) {
    try {
        if ($checkIsAdmin($request)) {
            return $handlerAdminNotifications($request);
        }

        $syncOldInvoicesIfEmpty();

        $customer = $resolveCustomer($request);
        if (!$customer) {
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

        $possibleIds = array_values(array_unique(array_filter([
            $customer->user_id ?? null,
            $customer->id ?? null,
        ])));

        $rows = DB::table('notifications')
            ->where('role_target', 'customer')
            ->whereIn('user_id', $possibleIds)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $ticketsMap = $extractTicketMapFromRows($rows);
        $notifications = $rows->map(fn($r) => $formatNotifRow($r, $ticketsMap))->values()->all();
        $unreadCount = $rows->where('is_read', 0)->count();

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

$routeNotifSmart = function (Request $r) use ($handlerAdminNotifications, $handlerCustomerNotifications, $checkIsAdmin, $resolveCurrentUser) {
    if ($checkIsAdmin($r)) return $handlerAdminNotifications($r);
    if ($resolveCurrentUser($r) || $r->has('customer_id') || $r->has('user_id') || $r->header('X-Customer-Id') || $r->header('X-User-Id')) {
        return $handlerCustomerNotifications($r);
    }
    return $handlerAdminNotifications($r);
};

Route::get('/notifications', $routeNotifSmart);
Route::get('/notification', $routeNotifSmart);
Route::get('/notifikasi', $routeNotifSmart);

// Tandai SATU notifikasi dibaca
Route::match(['GET', 'POST'], '/admin/notifications/{id}/read', function ($id) {
    DB::table('notifications')
        ->where('id', $id)
        ->where('role_target', 'admin')
        ->update(['is_read' => 1, 'updated_at' => now()]);

    return response()->json(['success' => true, 'message' => 'Notifikasi admin ditandai dibaca.']);
});

Route::match(['GET', 'POST'], '/customer/notifications/{id}/read', function (Request $r, $id) use ($resolveCustomer, $checkIsAdmin) {
    if ($checkIsAdmin($r)) {
        DB::table('notifications')
            ->where('id', $id)
            ->where('role_target', 'admin')
            ->update(['is_read' => 1, 'updated_at' => now()]);
        return response()->json(['success' => true, 'message' => 'Notifikasi admin ditandai dibaca.']);
    }

    $customer = $resolveCustomer($r);
    if ($customer) {
        $possibleIds = array_values(array_unique(array_filter([
            $customer->user_id ?? null,
            $customer->id ?? null,
        ])));
        DB::table('notifications')
            ->where('id', $id)
            ->where('role_target', 'customer')
            ->whereIn('user_id', $possibleIds)
            ->update(['is_read' => 1, 'updated_at' => now()]);
    }

    return response()->json(['success' => true, 'message' => 'Notifikasi pelanggan ditandai dibaca.']);
});

Route::match(['GET', 'POST'], '/notifications/{id}/read', function (Request $r, $id) use ($resolveCustomer, $checkIsAdmin) {
    if ($checkIsAdmin($r)) {
        DB::table('notifications')->where('id', $id)->where('role_target', 'admin')->update(['is_read' => 1, 'updated_at' => now()]);
        return response()->json(['success' => true]);
    }
    $customer = $resolveCustomer($r);
    if ($customer) {
        $possibleIds = array_values(array_unique(array_filter([$customer->user_id ?? null, $customer->id ?? null])));
        DB::table('notifications')->where('id', $id)->where('role_target', 'customer')->whereIn('user_id', $possibleIds)->update(['is_read' => 1, 'updated_at' => now()]);
    }
    return response()->json(['success' => true]);
});

// Tandai SEMUA notifikasi dibaca
$handlerMarkAllReadAdmin = function () {
    DB::table('notifications')
        ->where('role_target', 'admin')
        ->where('is_read', 0)
        ->update(['is_read' => 1, 'updated_at' => now()]);

    return response()->json(['success' => true, 'unread_count' => 0, 'unreadCount' => 0, 'message' => 'Semua notifikasi admin telah dibaca.']);
};

$handlerMarkAllReadCustomer = function (Request $r) use ($resolveCustomer, $handlerMarkAllReadAdmin, $checkIsAdmin) {
    if ($checkIsAdmin($r)) {
        return $handlerMarkAllReadAdmin();
    }
    $customer = $resolveCustomer($r);
    if ($customer) {
        $possibleIds = array_values(array_unique(array_filter([
            $customer->user_id ?? null,
            $customer->id ?? null,
        ])));
        DB::table('notifications')
            ->where('role_target', 'customer')
            ->whereIn('user_id', $possibleIds)
            ->where('is_read', 0)
            ->update(['is_read' => 1, 'updated_at' => now()]);
    }
    return response()->json(['success' => true, 'unread_count' => 0, 'unreadCount' => 0, 'message' => 'Semua notifikasi pelanggan telah ditandai dibaca.']);
};

Route::match(['GET', 'POST'], '/admin/notifications/mark-all-read', fn() => $handlerMarkAllReadAdmin());
Route::match(['GET', 'POST'], '/customer/notifications/mark-all-read', fn(Request $r) => $handlerMarkAllReadCustomer($r));
Route::match(['GET', 'POST'], '/notifications/mark-all-read', function (Request $r) use ($checkIsAdmin, $handlerMarkAllReadAdmin, $handlerMarkAllReadCustomer, $resolveCurrentUser) {
    if ($checkIsAdmin($r)) return $handlerMarkAllReadAdmin();
    if ($resolveCurrentUser($r) || $r->has('customer_id') || $r->has('user_id') || $r->header('X-Customer-Id') || $r->header('X-User-Id')) {
        return $handlerMarkAllReadCustomer($r);
    }
    return $handlerMarkAllReadAdmin();
});

// =========================================================================
// HANDLER TIKET PENGADUAN & BANTUAN (CUSTOMER & ADMIN + AUTO NOTIFIKASI)
// =========================================================================
$ensureTicketsTable = function () {
    if (!Schema::hasTable('tickets')) {
        Schema::create('tickets', function ($table) {
            $table->id();
            $table->string('nomor_tiket', 50)->unique();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('nama_customer')->nullable();
            $table->string('no_hp', 30)->nullable();
            $table->string('nama_paket')->nullable();
            $table->string('kategori', 100);
            $table->text('pesan');
            $table->string('status', 30)->default('MENUNGGU'); // MENUNGGU, DIPROSES, SELESAI
            $table->text('balasan_admin')->nullable();
            $table->timestamps();
        });
    }
};

// GET: Daftar Tiket Customer
Route::get('/customer/tickets', function (Request $request) use ($ensureTicketsTable, $resolveCustomer) {
    try {
        $ensureTicketsTable();
        $customer = $resolveCustomer($request);
        if (!$customer) {
            return response()->json(['success' => true, 'data' => []], 200);
        }

        $possibleUserIds = array_values(array_unique(array_filter([
            $customer->user_id ?? null,
            $customer->id ?? null,
        ])));

        $rows = DB::table('tickets')
            ->where(function ($q) use ($customer, $possibleUserIds) {
                $q->where('customer_id', $customer->id);
                if (!empty($possibleUserIds)) {
                    $q->orWhereIn('user_id', $possibleUserIds);
                }
            })
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $rows], 200);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
});

// POST: Customer Membuat Tiket Baru -> Otomatis Notifikasi ke Admin
Route::post('/customer/tickets', function (Request $request) use ($ensureTicketsTable, $resolveCustomer, $resolveCustomerUserId, $createNotification) {
    try {
        $ensureTicketsTable();
        $customer = $resolveCustomer($request);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Data pelanggan tidak ditemukan.'], 404);
        }

        $kategori = trim((string) $request->input('kategori', 'Kendala Jaringan'));
        $pesan    = trim((string) $request->input('pesan', ''));

        if ($pesan === '') {
            return response()->json(['success' => false, 'message' => 'Deskripsi keluhan/pengajuan wajib diisi.'], 422);
        }

        $nomorTiket = 'TKT-' . date('YmdHis') . '-' . $customer->id;
        $namaCust   = $customer->nama ?? $customer->name ?? 'Pelanggan';
        $noHp       = $customer->no_wa ?? $customer->no_hp ?? '-';
        $namaPaket  = $customer->nama_paket ?? '-';
        $custUserId = $resolveCustomerUserId($customer);

        $id = DB::table('tickets')->insertGetId([
            'nomor_tiket'   => $nomorTiket,
            'customer_id'   => $customer->id,
            'user_id'       => $custUserId,
            'nama_customer' => $namaCust,
            'no_hp'         => $noHp,
            'nama_paket'    => $namaPaket,
            'kategori'      => $kategori,
            'pesan'         => $pesan,
            'status'        => 'MENUNGGU',
            'balasan_admin' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Kirim notifikasi ke Admin
        $createNotification(
            null,
            'admin',
            "Tiket Bantuan Baru ({$nomorTiket})",
            "{$namaCust} mengajukan bantuan kategori \"{$kategori}\": {$pesan}",
            'WARNING'
        );

        return response()->json([
            'success' => true,
            'message' => 'Tiket pengaduan berhasil dikirim ke Admin!',
            'data'    => DB::table('tickets')->where('id', $id)->first(),
        ], 201);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
});

// GET: Daftar Semua Tiket untuk Admin
Route::get('/admin/tickets', function () use ($ensureTicketsTable) {
    try {
        $ensureTicketsTable();
        $rows = DB::table('tickets')->orderByDesc('id')->get();
        $pendingCount = $rows->where('status', 'MENUNGGU')->count();

        return response()->json([
            'success'       => true,
            'pending_count' => $pendingCount,
            'data'          => $rows,
        ], 200);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
});

// POST: Admin Update Status & Balas Tiket -> Otomatis Notifikasi ke Customer
Route::post('/admin/tickets/{id}/update', function (Request $request, $id) use ($ensureTicketsTable, $createNotification) {
    try {
        $ensureTicketsTable();
        $ticket = DB::table('tickets')->where('id', $id)->first();
        if (!$ticket) {
            return response()->json(['success' => false, 'message' => 'Tiket tidak ditemukan.'], 404);
        }

        $status = strtoupper(trim((string) $request->input('status', 'DIPROSES')));
        if (!in_array($status, ['MENUNGGU', 'DIPROSES', 'SELESAI'])) {
            $status = 'DIPROSES';
        }

        $balasan = trim((string) $request->input('balasan_admin', ''));

        DB::table('tickets')->where('id', $id)->update([
            'status'        => $status,
            'balasan_admin' => $balasan !== '' ? $balasan : $ticket->balasan_admin,
            'updated_at'    => now(),
        ]);

        // Kirim notifikasi update ke Customer terkait
        $notifType = $status === 'SELESAI' ? 'SUCCESS' : 'INFO';
        $pesanNotif = "Status tiket {$ticket->nomor_tiket} ({$ticket->kategori}) diperbarui menjadi {$status}."
            . ($balasan !== '' ? " Catatan Admin: {$balasan}" : '');

        $createNotification(
            $ticket->user_id ?? $ticket->customer_id,
            'customer',
            "Update Tiket Bantuan ({$status})",
            $pesanNotif,
            $notifType
        );

        return response()->json([
            'success' => true,
            'message' => 'Tiket berhasil diperbarui & notifikasi dikirim ke pelanggan.',
            'data'    => DB::table('tickets')->where('id', $id)->first(),
        ], 200);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
});

// Profile Resources
Route::get('/customer/profile/{identifier}', function ($identifier) use ($resolveCustomer, $formatCustomerProfile) {
    $cust = $resolveCustomer($identifier);
    return $formatCustomerProfile($cust);
});

// Update Kontak Customer (Hanya Nama & No. WhatsApp/HP)
Route::post('/customer/profile/update', function (Request $request) use ($resolveCustomer) {
    try {
        $customer = $resolveCustomer($request);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Data pelanggan tidak ditemukan.'], 404);
        }

        $nama = trim((string) $request->input('nama', ''));
        $noHp = trim((string) ($request->input('no_hp') ?? $request->input('no_wa') ?? ''));

        if ($nama === '' || $noHp === '') {
            return response()->json(['success' => false, 'message' => 'Nama dan No. WhatsApp wajib diisi.'], 422);
        }

        $custUpdate = [];
        if (Schema::hasColumn('customers', 'nama')) $custUpdate['nama'] = $nama;
        if (Schema::hasColumn('customers', 'name')) $custUpdate['name'] = $nama;
        if (Schema::hasColumn('customers', 'no_hp')) $custUpdate['no_hp'] = $noHp;
        if (Schema::hasColumn('customers', 'no_wa')) $custUpdate['no_wa'] = $noHp;

        if (!empty($custUpdate)) {
            $customer->update($custUpdate);
        }

        // Sinkronkan nama ke tabel users juga jika terhubung
        if (!empty($customer->user_id)) {
            User::where('id', $customer->user_id)->update(['name' => $nama]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profil kontak berhasil diperbarui!',
        ], 200);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
});

Route::get('/customers/{id}', function ($id) use ($resolveCustomer, $formatCustomerProfile) {
    $cust = $resolveCustomer($id);
    return $formatCustomerProfile($cust);
})->where('id', '[0-9]+');

// =========================================================================
// HANDLER UPDATE PELANGGAN (ADMIN) - FIX USERNAME PPPoE, ALAMAT & STATUS
// =========================================================================
$handlerUpdateCustomer = function (Request $request, $id = null) {
    try {
        $targetId = $id ?? $request->input('id') ?? $request->input('customer_id');
        if (empty($targetId)) {
            return response()->json([
                'success' => false,
                'message' => 'ID pelanggan tidak ditemukan.',
            ], 422);
        }

        $customer = Customer::find($targetId);
        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Data pelanggan tidak ditemukan di database.',
            ], 404);
        }

        // Pastikan kolom pppoe_username & alamat tersedia jika sebelumnya belum ada di tabel
        try {
            if (!Schema::hasColumn('customers', 'pppoe_username') && !Schema::hasColumn('customers', 'username_pppoe')) {
                Schema::table('customers', function ($table) {
                    $table->string('pppoe_username')->nullable();
                });
            }
            if (!Schema::hasColumn('customers', 'alamat') && !Schema::hasColumn('customers', 'address') && !Schema::hasColumn('customers', 'alamat_pemasangan')) {
                Schema::table('customers', function ($table) {
                    $table->text('alamat')->nullable();
                });
            }
        } catch (\Throwable $schemaErr) {}

        $updateData = [];
        $namaClean = null;

        // 1. Nama Pelanggan
        if ($request->hasAny(['nama', 'name', 'nama_pelanggan'])) {
            $namaClean = trim((string) ($request->input('nama') ?? $request->input('name') ?? $request->input('nama_pelanggan') ?? ''));
            if ($namaClean === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Nama pelanggan wajib diisi.',
                ], 422);
            }
            if (Schema::hasColumn('customers', 'nama')) $updateData['nama'] = $namaClean;
            if (Schema::hasColumn('customers', 'name')) $updateData['name'] = $namaClean;
        }

        // 2. No. WhatsApp / HP
        if ($request->hasAny(['no_hp', 'no_wa', 'phone', 'telepon', 'no_telp'])) {
            $hpInput = trim((string) ($request->input('no_hp') ?? $request->input('no_wa') ?? $request->input('phone') ?? $request->input('telepon') ?? $request->input('no_telp') ?? ''));
            if (Schema::hasColumn('customers', 'no_wa')) $updateData['no_wa'] = $hpInput;
            if (Schema::hasColumn('customers', 'no_hp')) $updateData['no_hp'] = $hpInput;
            if (Schema::hasColumn('customers', 'phone')) $updateData['phone'] = $hpInput;
        }

        // 3. Username PPPoE
        if ($request->hasAny(['username_pppoe', 'pppoe_username', 'pppoe', 'username'])) {
            $pppoeInput = trim((string) ($request->input('username_pppoe') ?? $request->input('pppoe_username') ?? $request->input('pppoe') ?? $request->input('username') ?? ''));
            $pppoeVal = ($pppoeInput === '' || $pppoeInput === '-') ? null : $pppoeInput;

            if (Schema::hasColumn('customers', 'pppoe_username')) $updateData['pppoe_username'] = $pppoeVal;
            if (Schema::hasColumn('customers', 'username_pppoe')) $updateData['username_pppoe'] = $pppoeVal;
        }

        // 4. Alamat Pemasangan
        if ($request->hasAny(['alamat', 'address', 'alamat_pemasangan'])) {
            $alamatInput = trim((string) ($request->input('alamat') ?? $request->input('address') ?? $request->input('alamat_pemasangan') ?? ''));
            $alamatVal = ($alamatInput === '' || $alamatInput === '-') ? null : $alamatInput;

            if (Schema::hasColumn('customers', 'alamat')) $updateData['alamat'] = $alamatVal;
            if (Schema::hasColumn('customers', 'address')) $updateData['address'] = $alamatVal;
            if (Schema::hasColumn('customers', 'alamat_pemasangan')) $updateData['alamat_pemasangan'] = $alamatVal;
        }

        // 5. Email (jika dikirim)
        if ($request->has('email') && Schema::hasColumn('customers', 'email')) {
            $emailInput = trim((string) $request->input('email', ''));
            if ($emailInput !== '' && $emailInput !== '-') {
                $updateData['email'] = $emailInput;
            }
        }

        // 6. Pilihan Paket Internet (product_id & nama_paket)
        $productIdInput = $request->input('product_id') ?? $request->input('paket_id');
        $namaPaketInput = $request->input('nama_paket') ?? $request->input('paket');

        $matchedProduct = null;
        if (!empty($productIdInput)) {
            $matchedProduct = Product::find($productIdInput);
        } elseif (!empty($namaPaketInput)) {
            $cleanPaket = trim((string) $namaPaketInput);
            if (Schema::hasColumn('products', 'nama_paket')) {
                $matchedProduct = Product::where('nama_paket', $cleanPaket)->first();
            }
            if (!$matchedProduct && Schema::hasColumn('products', 'name')) {
                $matchedProduct = Product::where('name', $cleanPaket)->first();
            }
        }

        if ($matchedProduct) {
            if (Schema::hasColumn('customers', 'product_id')) {
                $updateData['product_id'] = $matchedProduct->id;
            }
            if (Schema::hasColumn('customers', 'nama_paket')) {
                $updateData['nama_paket'] = $matchedProduct->nama_paket ?? $matchedProduct->name ?? $namaPaketInput;
            }
        } else {
            if (!empty($productIdInput) && Schema::hasColumn('customers', 'product_id')) {
                $updateData['product_id'] = (int) $productIdInput;
            }
            if (!empty($namaPaketInput) && Schema::hasColumn('customers', 'nama_paket')) {
                $updateData['nama_paket'] = trim((string) $namaPaketInput);
            }
        }

        // 7. Status Langganan
        if ($request->hasAny(['status', 'status_langganan'])) {
            $rawStatus = strtoupper(trim((string) ($request->input('status') ?? $request->input('status_langganan') ?? '')));
            $isActiveStatus = (
                str_contains($rawStatus, 'AKTIF')
                && !str_contains($rawStatus, 'NONAKTIF')
                && !str_contains($rawStatus, 'NON-AKTIF')
                && !str_contains($rawStatus, 'TIDAK')
                && !str_contains($rawStatus, 'BELUM')
            ) || in_array($rawStatus, ['ACTIVE', 'ONLINE', 'ENABLE', 'ENABLED', '1']);
            $isIsolirStatus = str_contains($rawStatus, 'ISOLIR') || str_contains($rawStatus, 'SUSPEND') || str_contains($rawStatus, 'BLOKIR');

            if (Schema::hasColumn('customers', 'status')) {
                $finalStatus = $isActiveStatus ? 'ACTIVE' : ($isIsolirStatus ? 'ISOLIR' : 'INACTIVE');

                // Sesuaikan otomatis jika kolom status di MySQL bertipe ENUM
                try {
                    $colInfo = DB::select("SHOW COLUMNS FROM customers LIKE 'status'");
                    if (!empty($colInfo)) {
                        $colType = $colInfo[0]->Type ?? '';
                        if (preg_match("/^enum\((.*)\)$/i", $colType, $matches)) {
                            $allowed = array_map(fn($v) => trim($v, " '\""), explode(',', $matches[1]));
                            $allowedUpper = array_map('strtoupper', $allowed);

                            $candidates = $isActiveStatus
                                ? ['ACTIVE', 'AKTIF', 'ONLINE', 'ENABLED']
                                : ($isIsolirStatus ? ['ISOLIR', 'SUSPENDED', 'INACTIVE', 'NONAKTIF'] : ['INACTIVE', 'BELUM_AKTIF', 'NONAKTIF', 'ISOLIR']);

                            foreach ($candidates as $cand) {
                                $idx = array_search($cand, $allowedUpper, true);
                                if ($idx !== false) {
                                    $finalStatus = $allowed[$idx];
                                    break;
                                }
                            }
                        }
                    }
                } catch (\Throwable $enumErr) {}

                $updateData['status'] = $finalStatus;
            }

            if (Schema::hasColumn('customers', 'status_koneksi')) {
                $updateData['status_koneksi'] = $isActiveStatus ? 'Online' : 'Offline';
            }
        }

        if (Schema::hasColumn('customers', 'updated_at')) {
            $updateData['updated_at'] = now();
        }

        // Update langsung via Query Builder agar tidak gagal karena $fillable pada Model Customer
        if (!empty($updateData)) {
            DB::table('customers')->where('id', $customer->id)->update($updateData);
        }

        // Sinkronkan nama ke tabel users jika terhubung
        if (!empty($customer->user_id) && $namaClean !== null) {
            try {
                User::where('id', $customer->user_id)->update(['name' => $namaClean]);
            } catch (\Throwable $userErr) {}
        }

        $fresh = Customer::find($customer->id);

        return response()->json([
            'success' => true,
            'message' => 'Data pelanggan berhasil diperbarui!',
            'data'    => [
                'id'             => (int) $fresh->id,
                'nama'           => $fresh->nama ?? $fresh->name ?? '',
                'name'           => $fresh->nama ?? $fresh->name ?? '',
                'no_hp'          => $fresh->no_wa ?? $fresh->no_hp ?? '',
                'no_wa'          => $fresh->no_wa ?? $fresh->no_hp ?? '',
                'username_pppoe' => $fresh->pppoe_username ?? $fresh->username_pppoe ?? '',
                'pppoe_username' => $fresh->pppoe_username ?? $fresh->username_pppoe ?? '',
                'alamat'         => $fresh->alamat ?? $fresh->address ?? $fresh->alamat_pemasangan ?? '',
                'address'        => $fresh->alamat ?? $fresh->address ?? $fresh->alamat_pemasangan ?? '',
                'product_id'     => $fresh->product_id ?? null,
                'nama_paket'     => $fresh->nama_paket ?? null,
                'status'         => $fresh->status ?? 'ACTIVE',
                'status_koneksi' => $fresh->status_koneksi ?? 'Online',
            ],
        ], 200);

    } catch (\Throwable $e) {
        Log::error('Gagal update customer: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Gagal memperbarui data pelanggan: ' . $e->getMessage(),
        ], 500);
    }
};

Route::match(['PUT', 'PATCH', 'POST'], '/customers/{id}', $handlerUpdateCustomer)->where('id', '[0-9]+');
Route::post('/customers/{id}/update', $handlerUpdateCustomer)->where('id', '[0-9]+');
Route::post('/customers/update', $handlerUpdateCustomer);
Route::match(['PUT', 'PATCH', 'POST'], '/admin/customers/{id}', $handlerUpdateCustomer)->where('id', '[0-9]+');
Route::post('/admin/customers/{id}/update', $handlerUpdateCustomer)->where('id', '[0-9]+');
Route::post('/admin/customers/update', $handlerUpdateCustomer);

Route::apiResource('customers', CustomerController::class);
Route::apiResource('products', ProductController::class);

// =========================================================================
// MANAJEMEN JARINGAN & MULTI-ROUTER MIKROTIK (v6 / v7 / SIMULASI)
// =========================================================================
Route::get('/admin/routers', [NetworkController::class, 'index']);
Route::post('/admin/routers', [NetworkController::class, 'store']);
Route::post('/admin/routers/discover', [NetworkController::class, 'discover']);
Route::match(['PUT', 'PATCH'], '/admin/routers/{id}', [NetworkController::class, 'update']);
Route::delete('/admin/routers/{id}', [NetworkController::class, 'destroy']);
Route::post('/admin/routers/{id}/test', [NetworkController::class, 'testConnection']);
Route::get('/admin/routers/{id}/monitoring', [NetworkController::class, 'monitoring']);
Route::post('/admin/routers/{id}/action', [NetworkController::class, 'action']);
Route::get('/admin/network-logs', [NetworkController::class, 'logs']);

// Tagihan Aktif
Route::get('/customer/invoice/active', function (Request $request) use ($resolveCustomer, $resolveProductFromInvoice) {
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

        $namaProduk = $inv->nama_produk;
        if (empty($namaProduk)) {
            $matchedProd = $resolveProductFromInvoice($inv, $cust);
            $namaProduk = $matchedProd ? ($matchedProd->nama_paket ?? $matchedProd->name ?? 'Paket Internet') : 'Paket Internet';
        }

        return response()->json([
            'success'     => true,
            'has_tagihan' => true,
            'data'        => [
                'id'            => $inv->id,
                'nomor_invoice' => $inv->nomor_invoice,
                'customer_id'   => $inv->customer_id,
                'total_tagihan' => (double) $inv->total_tagihan,
                'status'        => $inv->status,
                'nama_produk'   => $namaProduk,
                'invoice_url'   => $inv->qr_image_url ?? null,
                'qr_image_url'  => $inv->qr_image_url ?? null,
                'qr_string'     => $inv->qr_string ?? null,
            ],
        ], 200);
    } catch (\Throwable $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
});

// Xendit Create Invoice
$handlerCreateInvoiceXendit = function (Request $request) use ($resolveCustomer, $createNotification, $resolveCustomerUserId) {
    try {
        $customer = $resolveCustomer($request);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Data customer tidak ditemukan di database.'], 404);
        }

        $productId = $request->input('product_id');
        $namaProdukInput = trim((string) ($request->input('nama_produk') ?? ''));
        $nominalInput = (int) $request->input('nominal', 0);

        $product = null;
        if (!empty($productId)) {
            $product = Product::find($productId);
        } elseif (!empty($namaProdukInput)) {
            $cleanInput = trim(preg_replace('/^Pembayaran\s+Paket\s+/i', '', $namaProdukInput));
            if (Schema::hasColumn('products', 'nama_paket')) {
                $product = Product::where('nama_paket', $cleanInput)
                    ->orWhere('nama_paket', $namaProdukInput)
                    ->first();
            }
            if (!$product && Schema::hasColumn('products', 'name')) {
                $product = Product::where('name', $cleanInput)
                    ->orWhere('name', $namaProdukInput)
                    ->first();
            }
        }

        if (!$product && empty($namaProdukInput) && $nominalInput > 0) {
            foreach (Product::all() as $p) {
                if ((int) ($p->harga ?? $p->price ?? 0) === $nominalInput) {
                    $product = $p;
                    break;
                }
            }
        }

        $nominal = $product ? (int) ($product->harga ?? $product->price ?? $nominalInput) : $nominalInput;
        $namaPaket = $product
            ? trim((string) ($product->nama_paket ?? $product->name ?? 'Paket Internet'))
            : (!empty($namaProdukInput) ? $namaProdukInput : 'Paket Internet');

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
            ->whereIn(DB::raw('UPPER(status)'), ['PENDING', 'UNPAID', 'BELUM_BAYAR'])
            ->delete();

        $invoiceData = [
            'nomor_invoice' => $nomorInvoice,
            'customer_id'   => $customer->id,
            'total_tagihan' => $nominal,
            'status'        => 'PENDING',
        ];
        if (Schema::hasColumn('invoices', 'nama_produk')) $invoiceData['nama_produk'] = $namaPaket;
        if (Schema::hasColumn('invoices', 'metode_pembayaran')) $invoiceData['metode_pembayaran'] = 'Xendit Multi Payment';
        if (Schema::hasColumn('invoices', 'qr_image_url')) $invoiceData['qr_image_url'] = $invoiceUrl;
        if (Schema::hasColumn('invoices', 'qr_string')) $invoiceData['qr_string'] = $invoiceUrl;

        $invoice = Invoice::create($invoiceData);

        // Simpan notifikasi tagihan baru untuk Customer & Admin ke MySQL
        $nominalFmt = 'Rp ' . number_format($nominal, 0, ',', '.');
        $namaCust   = $customer->nama ?? $customer->name ?? 'Pelanggan';
        $custUserId = $resolveCustomerUserId($customer);

        $createNotification(
            $custUserId,
            'customer',
            'Tagihan Internet Baru Diterbitkan',
            "Tagihan {$nomorInvoice} untuk paket {$namaPaket} sebesar {$nominalFmt} telah diterbitkan. Silakan lakukan pembayaran.",
            'WARNING'
        );

        $createNotification(
            null,
            'admin',
            'Tagihan Baru Menunggu Pembayaran',
            "Tagihan {$nomorInvoice} untuk {$namaCust} paket {$namaPaket} sebesar {$nominalFmt} menunggu konfirmasi bayar.",
            'WARNING'
        );

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

// Cek Status Pembayaran (Sekaligus sinkronkan paket aktif & buat notifikasi lunas)
Route::post('/invoices/check-status', function (Request $request) use ($resolveCustomer, $resolveProductFromInvoice, $createNotification, $resolveCustomerUserId) {
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
        $alreadyPaid = in_array(strtoupper(trim($invoice->status ?? '')), ['PAID', 'LUNAS', 'BERHASIL', 'SUCCESS']);
        $isPaid = $alreadyPaid;

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
            $baruSajaLunas = !$alreadyPaid;

            // Update langsung via DB::table agar tanggal_bayar pasti tersimpan walau tidak di $fillable
            if (!$alreadyPaid || empty($invoice->tanggal_bayar)) {
                $nowWib = Carbon::now('Asia/Jakarta');
                $updateInv = ['status' => 'PAID'];
                if (Schema::hasColumn('invoices', 'tanggal_bayar')) {
                    $updateInv['tanggal_bayar'] = $invoice->tanggal_bayar ?? $nowWib;
                }
                if (Schema::hasColumn('invoices', 'updated_at')) {
                    $updateInv['updated_at'] = $nowWib;
                }
                DB::table('invoices')->where('id', $invoice->id)->update($updateInv);
                $invoice = Invoice::find($invoice->id) ?? $invoice;
            }

            $customer = Customer::find($invoice->customer_id) ?? $resolveCustomer($invoice->customer_id);
            if ($customer) {
                $custUpdate = [];
                if (Schema::hasColumn('customers', 'status')) $custUpdate['status'] = 'ACTIVE';
                if (Schema::hasColumn('customers', 'status_koneksi')) $custUpdate['status_koneksi'] = 'Online';

                $matchedProd = $resolveProductFromInvoice($invoice, $customer);
                if ($matchedProd) {
                    if (Schema::hasColumn('customers', 'product_id')) {
                        $custUpdate['product_id'] = $matchedProd->id;
                    }
                    if (Schema::hasColumn('customers', 'nama_paket')) {
                        $custUpdate['nama_paket'] = $matchedProd->nama_paket ?? $matchedProd->name;
                    }
                } elseif (!empty($invoice->nama_produk) && Schema::hasColumn('customers', 'nama_paket')) {
                    $custUpdate['nama_paket'] = preg_replace('/^Pembayaran\s+Paket\s+/i', '', $invoice->nama_produk);
                }

                if (!empty($custUpdate)) {
                    $customer->update($custUpdate);
                }
            }

            // Simpan notifikasi lunas ke MySQL hanya sekali saat status berubah menjadi PAID
            if ($baruSajaLunas) {
                $namaPaketClean = preg_replace(
                    '/^Pembayaran\s+Paket\s+/i',
                    '',
                    (string) ($invoice->nama_produk ?: ($customer->nama_paket ?? 'Internet'))
                );
                $nominalFmt = 'Rp ' . number_format((double) $invoice->total_tagihan, 0, ',', '.');
                $namaCust   = $customer ? ($customer->nama ?? $customer->name ?? 'Pelanggan') : 'Pelanggan';
                $custUserId = $resolveCustomerUserId($customer);

                $createNotification(
                    $custUserId,
                    'customer',
                    'Pembayaran Berhasil Diverifikasi',
                    "Pembayaran {$invoice->nomor_invoice} paket {$namaPaketClean} sebesar {$nominalFmt} telah berhasil diverifikasi lunas.",
                    'SUCCESS'
                );

                $createNotification(
                    null,
                    'admin',
                    'Pembayaran Berhasil Diterima',
                    "Pembayaran {$invoice->nomor_invoice} dari {$namaCust} paket {$namaPaketClean} sebesar {$nominalFmt} berhasil diverifikasi lunas.",
                    'SUCCESS'
                );
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

/// =========================================================================
// LUPA PASSWORD (CARI USERNAME / PPPOE / EMAIL -> KIRIM PASSWORD VIA BREVO API)
// =========================================================================
Route::post('/forgot-password', function (Request $request) {
    try {
        $identifier = trim((string) (
            $request->input('identifier')
            ?? $request->input('email')
            ?? $request->input('username')
            ?? ''
        ));

        if ($identifier === '') {
            return response()->json([
                'success' => false,
                'message' => 'Username, ID PPPoE, atau Email wajib diisi.',
            ], 422);
        }

        // Pastikan kolom recovery_email tersedia di tabel users jika belum dimigrasi
        try {
            if (!Schema::hasColumn('users', 'recovery_email')) {
                Schema::table('users', function ($table) {
                    $table->string('recovery_email')->nullable()->after('email');
                });
            }
        } catch (\Throwable $schemaErr) {}

        $idLower  = strtolower($identifier);
        $user     = null;
        $customer = null;

        // 1. Cari di tabel users: email -> recovery_email -> username (jika ada)
        $user = User::whereRaw('LOWER(email) = ?', [$idLower])->first();

        if (!$user && Schema::hasColumn('users', 'recovery_email')) {
            $user = User::whereRaw('LOWER(recovery_email) = ?', [$idLower])->first();
        }

        if (!$user && Schema::hasColumn('users', 'username')) {
            $user = User::whereRaw('LOWER(username) = ?', [$idLower])->first();
        }

        // 2. Jika belum ketemu, cari di tabel customers: pppoe_username / username_pppoe / email
        if (!$user && Schema::hasTable('customers')) {
            if (Schema::hasColumn('customers', 'pppoe_username')) {
                $customer = Customer::whereRaw('LOWER(pppoe_username) = ?', [$idLower])->first();
            }
            if (!$customer && Schema::hasColumn('customers', 'username_pppoe')) {
                $customer = Customer::whereRaw('LOWER(username_pppoe) = ?', [$idLower])->first();
            }
            if (!$customer && Schema::hasColumn('customers', 'email')) {
                $customer = Customer::whereRaw('LOWER(email) = ?', [$idLower])->first();
            }
        }

        // 3. Fallback terakhir: cari berdasarkan nama di users atau customers
        if (!$user && !$customer) {
            $user = User::whereRaw('LOWER(name) = ?', [$idLower])->first();
        }
        if (!$user && !$customer && Schema::hasTable('customers')) {
            if (Schema::hasColumn('customers', 'nama')) {
                $customer = Customer::whereRaw('LOWER(nama) = ?', [$idLower])->first();
            }
            if (!$customer && Schema::hasColumn('customers', 'name')) {
                $customer = Customer::whereRaw('LOWER(name) = ?', [$idLower])->first();
            }
        }

        // Hubungkan customer -> user jika yang ketemu baru data customer
        if (!$user && $customer) {
            if (!empty($customer->user_id)) {
                $user = User::find($customer->user_id);
            }
            if (!$user && !empty($customer->email)) {
                $user = User::whereRaw('LOWER(email) = ?', [strtolower($customer->email)])->first();
            }
            if (!$user) {
                $custName = $customer->nama ?? $customer->name ?? null;
                if (!empty($custName)) {
                    $user = User::whereRaw('LOWER(name) = ?', [strtolower($custName)])->first();
                }
            }
        }

        // Hubungkan user -> customer (untuk fallback email jika diperlukan)
        if ($user && !$customer && Schema::hasTable('customers')) {
            if (Schema::hasColumn('customers', 'user_id')) {
                $customer = Customer::where('user_id', $user->id)->first();
            }
            if (!$customer && Schema::hasColumn('customers', 'email') && !empty($user->email)) {
                $customer = Customer::where('email', $user->email)->first();
            }
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => "Akun dengan identitas \"{$identifier}\" tidak ditemukan.",
            ], 404);
        }

        // Tentukan email tujuan (prioritaskan recovery_email, lalu email utama)
        $kandidatEmail = [
            $user->recovery_email ?? null,
            $customer->recovery_email ?? null,
            $user->email ?? null,
            $customer->email ?? null,
        ];

        $targetEmail = null;
        foreach ($kandidatEmail as $cand) {
            $cand = trim((string) $cand);
            if ($cand !== '' && filter_var($cand, FILTER_VALIDATE_EMAIL)) {
                $targetEmail = $cand;
                break;
            }
        }

        if (!$targetEmail) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini belum memiliki alamat email pemulihan yang valid.',
            ], 422);
        }

        $brevoApiKey = trim((string) env('BREVO_API_KEY', ''));

        // Jika BREVO_API_KEY belum disetting, cek apakah SMTP biasa aktif
        if ($brevoApiKey === '' && in_array(config('mail.default'), ['log', 'array'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Layanan email server belum dikonfigurasi (tambahkan BREVO_API_KEY di Variables Railway).',
            ], 500);
        }

        // Generate password baru acak 8 karakter (mudah dibaca, tanpa karakter mirip O/0/l/1)
        $karakter = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $passwordBaru = '';
        for ($i = 0; $i < 8; $i++) {
            $passwordBaru .= $karakter[random_int(0, strlen($karakter) - 1)];
        }

        $fromEmail = trim((string) env('MAIL_FROM_ADDRESS', ''));
        if ($fromEmail === '' || $fromEmail === 'hello@example.com') {
            $fromEmail = 'kjnkhatulistiwa@gmail.com';
        }
        $fromName = trim((string) env('MAIL_FROM_NAME', 'BUMDES Khatulistiwa'));
        if ($fromName === '' || str_contains($fromName, '${')) {
            $fromName = 'BUMDES Khatulistiwa';
        }

        // Gunakan transaction: password hanya tersimpan jika email benar-benar terkirim
        DB::beginTransaction();
        try {
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'password'   => Hash::make($passwordBaru),
                    'updated_at' => now(),
                ]);

            $isiPesan = "Halo {$user->name},\n\n"
                . "Kami menerima permintaan reset password untuk akun BUMDES Khatulistiwa / KJN Billing Anda.\n\n"
                . "Email Login : {$user->email}\n"
                . "Password Baru : {$passwordBaru}\n\n"
                . "Silakan gunakan password baru di atas untuk masuk ke aplikasi, lalu segera ganti password Anda jika diperlukan.\n\n"
                . "Terima kasih,\nNusantara Teknologi - Billing System";

            if ($brevoApiKey !== '') {
                $mailResponse = Http::withHeaders([
                    'api-key'      => $brevoApiKey,
                    'accept'       => 'application/json',
                    'content-type' => 'application/json',
                ])->timeout(10)->post('https://api.brevo.com/v3/smtp/email', [
                    'sender' => [
                        'name'  => $fromName,
                        'email' => $fromEmail,
                    ],
                    'to' => [
                        [
                            'email' => $targetEmail,
                            'name'  => !empty($user->name) ? $user->name : 'Pelanggan',
                        ],
                    ],
                    'subject'     => 'Password Baru Akun - BUMDES Khatulistiwa Billing',
                    'textContent' => $isiPesan,
                ]);

                if (!$mailResponse->successful()) {
                    $errDetail = $mailResponse->json('message') ?? $mailResponse->body();
                    throw new \RuntimeException('Brevo API error (' . $mailResponse->status() . '): ' . $errDetail);
                }
            } else {
                Mail::raw($isiPesan, function ($mail) use ($targetEmail) {
                    $mail->to($targetEmail)
                        ->subject('Password Baru Akun - BUMDES Khatulistiwa Billing');
                });
            }

            DB::commit();
        } catch (\Throwable $mailErr) {
            DB::rollBack();
            Log::error('Gagal kirim email forgot-password: ' . $mailErr->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim email ke pengguna: ' . $mailErr->getMessage(),
            ], 500);
        }

        // Sensor alamat email di response untuk keamanan (contoh: nu***@gmail.com)
        [$local, $domain] = array_pad(explode('@', $targetEmail, 2), 2, '');
        $maskedLocal = strlen($local) > 2
            ? substr($local, 0, 2) . str_repeat('*', max(3, strlen($local) - 2))
            : substr($local, 0, 1) . '***';
        $maskedEmail = "{$maskedLocal}@{$domain}";

        return response()->json([
            'success' => true,
            'message' => "Password baru telah dikirim ke {$maskedEmail}. Silakan cek kotak masuk atau folder Spam.",
        ], 200);

    } catch (\Throwable $e) {
        Log::error('Error forgot-password: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage(),
        ], 500);
    }
});

// =========================================================================
// REGISTER (CUSTOMER BARU + NOTIFIKASI KE ADMIN & CUSTOMER)
// =========================================================================
Route::post('/register', function (Request $request) use ($createNotification) {
    try {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'email'          => 'required|email|max:255|unique:users,email',
            'recovery_email' => 'nullable|email|max:255',
            'password'       => 'required|string|min:6',
            'phone'          => 'nullable|string',
        ]);

        // Otomatis pastikan kolom recovery_email tersedia di tabel users
        try {
            if (!Schema::hasColumn('users', 'recovery_email')) {
                Schema::table('users', function ($table) {
                    $table->string('recovery_email')->nullable()->after('email');
                });
            }
        } catch (\Throwable $schemaErr) {}

        $role = strtolower(trim($request->input('role', 'customer')));
        $noHp = $request->input('phone') ?? $request->input('no_hp') ?? '';

        $result = DB::transaction(function () use ($validated, $role, $noHp, $request) {
            $userData = [
                'name'              => $validated['name'],
                'email'             => $validated['email'],
                'password'          => bcrypt($validated['password']),
                'role'              => $role,
                'email_verified_at' => now(),
            ];
            if (Schema::hasColumn('users', 'recovery_email')) {
                $userData['recovery_email'] = $validated['recovery_email'] ?? null;
            }

            $user = User::create($userData);

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

        // Simpan notifikasi pendaftaran untuk Customer & Admin
        $createNotification(
            $user->id,
            'customer',
            'Selamat Datang di Portal Pelanggan',
            "Halo {$user->name}, akun Anda berhasil terdaftar. Silakan pilih paket internet untuk mulai berlangganan.",
            'INFO'
        );

        $createNotification(
            null,
            'admin',
            'Pelanggan Baru Mendaftar',
            "Pelanggan baru {$user->name} ({$user->email}) baru saja mendaftarkan akun.",
            'INFO'
        );

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