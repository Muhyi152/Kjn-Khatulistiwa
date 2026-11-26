<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    private const UNPAID_INVOICE_STATUSES = [
        'PENDING',
        'UNPAID',
        'BELUM_BAYAR',
        'BELUM LUNAS',
        'BELUM_LUNAS',
        'OVERDUE',
        'MENUNGGAK',
        'EXPIRED',
        'OPEN',
    ];

    private function safeErrorMessage(\Throwable $e, string $fallback = 'Terjadi kesalahan pada server.'): string
    {
        return config('app.debug') ? $e->getMessage() : $fallback;
    }

    private function isStatusAktif(?string $status): bool
    {
        $upper = strtoupper(trim((string) ($status ?? 'ACTIVE')));
        return in_array($upper, ['ACTIVE', 'AKTIF', 'ONLINE', 'ENABLED'], true);
    }

    /**
     * Tampilkan semua pelanggan beserta status TAGIHAN DARI MUTASI RIIL (INVOICE)
     */
    public function index()
    {
        try {
            $allProducts = Product::all()->keyBy('id');
            $customers = Customer::orderBy('id', 'desc')->get();

            // Ambil semua invoice belum lunas dalam 1 query (hindari N+1 query)
            $customerIds = $customers->pluck('id')->all();
            $pendingInvoicesByCustomer = collect();

            if (!empty($customerIds) && Schema::hasTable('invoices')) {
                $pendingInvoicesByCustomer = Invoice::whereIn('customer_id', $customerIds)
                    ->whereIn(DB::raw('UPPER(TRIM(status))'), self::UNPAID_INVOICE_STATUSES)
                    ->orderBy('id', 'desc')
                    ->get()
                    ->groupBy('customer_id');
            }

            $data = $customers->map(function ($c) use ($allProducts, $pendingInvoicesByCustomer) {
                $product = $c->product_id ? $allProducts->get($c->product_id) : null;
                if (!$product && $allProducts->isNotEmpty()) {
                    $product = $allProducts->first();
                }

                $namaPaket = $product
                    ? ($product->nama_paket ?? $product->name ?? 'Paket #' . $product->id)
                    : ($c->nama_paket ?? 'Paket Belum Diatur');

                $tarifPaket = $product ? (double) ($product->harga ?? $product->price ?? 0) : 0.0;

                $statusUpper = strtoupper(trim((string) ($c->status ?? 'ACTIVE')));
                $isAktif = $this->isStatusAktif($statusUpper);

                $customerPendingInvoices = $pendingInvoicesByCustomer->get($c->id, collect());
                $pendingInvoice = $customerPendingInvoices->first();

                // Jumlahkan seluruh invoice yang belum dibayar
                $tagihanRiil = (double) $customerPendingInvoices->sum(function ($inv) {
                    return (double) ($inv->total_tagihan ?? $inv->amount ?? $inv->total ?? $inv->nominal ?? 0);
                });

                // Sinkronisasi: Jika status ISOLIR / NONAKTIF tapi belum ada record invoice unpaid,
                // pastikan tagihan tidak terbaca 0 (LUNAS)
                if (!$isAktif && $tagihanRiil <= 0 && $tarifPaket > 0) {
                    $tagihanRiil = $tarifPaket;
                }

                $isLunas = $isAktif && ($tagihanRiil <= 0) && !$pendingInvoice;
                $statusTagihan = $isLunas ? 'LUNAS' : 'BELUM_BAYAR';

                $namaVal   = ($c->nama ?? null) ?: (($c->name ?? null) ?: 'Pelanggan #' . $c->id);
                $noWaVal   = ($c->no_wa ?? null) ?: (($c->no_hp ?? null) ?: (($c->phone ?? null) ?: '-'));
                $alamatVal = ($c->address ?? null) ?: (($c->alamat ?? null) ?: (($c->alamat_pemasangan ?? null) ?: '-'));
                $pppoeVal  = ($c->pppoe_username ?? null) ?: (($c->username_pppoe ?? null) ?: '-');

                return [
                    'id'                => (int) $c->id,
                    'user_id'           => $c->user_id ?? null,
                    'nama'              => $namaVal,
                    'name'              => $namaVal,
                    'no_wa'             => $noWaVal,
                    'no_hp'             => $noWaVal,
                    'phone'             => $noWaVal,
                    'address'           => $alamatVal,
                    'alamat'            => $alamatVal,
                    'pppoe_username'    => $pppoeVal,
                    'username_pppoe'    => $pppoeVal,
                    'status'            => $statusUpper,
                    'product_id'        => $product ? (int) $product->id : (int) $c->product_id,
                    'product'           => $product ? [
                        'id'         => (int) $product->id,
                        'name'       => $namaPaket,
                        'nama_paket' => $namaPaket,
                        'price'      => $tarifPaket,
                        'harga'      => $tarifPaket,
                    ] : null,
                    'nama_paket'        => $namaPaket,
                    'paket_internet'    => $namaPaket,
                    'paket'             => $namaPaket,
                    'tagihan'           => $tagihanRiil,
                    'total_tagihan'     => $tagihanRiil,
                    'nominal'           => $tagihanRiil,
                    'is_lunas'          => $isLunas,
                    'status_tagihan'    => $statusTagihan,
                    'nomor_invoice'     => $pendingInvoice ? ($pendingInvoice->nomor_invoice ?? null) : null,
                    'jatuh_tempo'       => $pendingInvoice ? ($pendingInvoice->jatuh_tempo ?? null) : null,
                    'tarif_paket'       => $tarifPaket,
                    'harga_paket'       => $tarifPaket,
                    'created_at'        => $c->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $data,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Gagal mengambil data pelanggan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data pelanggan: ' . $this->safeErrorMessage($e),
            ], 500);
        }
    }

    /**
     * Tambah Pelanggan Baru (Otomatis dibuatkan akun di tabel 'users')
     */
    public function store(Request $request)
    {
        try {
            $nama   = mb_substr(trim((string) ($request->input('nama') ?? $request->input('name') ?? '')), 0, 150);
            $noWa   = mb_substr(trim((string) ($request->input('no_wa') ?? $request->input('no_hp') ?? $request->input('phone') ?? '-')), 0, 30);
            $alamat = mb_substr(trim((string) ($request->input('address') ?? $request->input('alamat') ?? $request->input('alamat_pemasangan') ?? '-')), 0, 500);

            if ($nama === '') {
                return response()->json(['success' => false, 'message' => 'Nama pelanggan wajib diisi.'], 422);
            }

            $namaDepan = preg_replace('/[^a-z0-9]/', '', strtolower(explode(' ', $nama)[0] ?: 'user')) ?: 'user';
            $emailInput = trim((string) $request->input('email', ''));

            if ($emailInput !== '') {
                if (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
                    return response()->json(['success' => false, 'message' => 'Format email tidak valid.'], 422);
                }
                if (User::whereRaw('LOWER(email) = ?', [strtolower($emailInput)])->exists()) {
                    return response()->json(['success' => false, 'message' => 'Email sudah terdaftar pada akun lain.'], 422);
                }
                $email = strtolower($emailInput);
            } else {
                do {
                    $email = $namaDepan . '.' . strtolower(Str::random(5)) . '@kjn.id';
                } while (User::where('email', $email)->exists());
            }

            $username = mb_substr(trim((string) ($request->input('pppoe_username') ?? $request->input('username_pppoe') ?? ($namaDepan . '@kjn'))), 0, 100);
            $passwordInput = (string) $request->input('password', '');
            if ($passwordInput !== '' && strlen($passwordInput) < 6) {
                return response()->json(['success' => false, 'message' => 'Password minimal 6 karakter.'], 422);
            }
            $password = $passwordInput !== '' ? $passwordInput : Str::random(12);

            $productId = $request->input('product_id') ?? $request->input('paket_id');
            $product   = $productId ? Product::find($productId) : null;

            if (!$product && ($request->filled('paket') || $request->filled('nama_paket'))) {
                $namaCari = trim((string) ($request->input('paket') ?? $request->input('nama_paket')));
                if (Schema::hasColumn('products', 'nama_paket')) {
                    $product = Product::where('nama_paket', $namaCari)->first();
                }
                if (!$product && Schema::hasColumn('products', 'name')) {
                    $product = Product::where('name', $namaCari)->first();
                }
            }
            if (!$product) {
                $product = Product::first();
            }
            $productId = $product ? $product->id : 1;

            $customer = DB::transaction(function () use ($nama, $email, $password, $noWa, $alamat, $username, $productId, $product, $request) {
                $user = new User([
                    'name'     => $nama,
                    'email'    => $email,
                    'password' => Hash::make($password),
                ]);
                $user->role = 'customer';
                $user->email_verified_at = now();
                $user->save();

                $customerData = [];
                if (Schema::hasColumn('customers', 'user_id')) $customerData['user_id'] = $user->id;
                if (Schema::hasColumn('customers', 'email')) $customerData['email'] = $email;
                if (Schema::hasColumn('customers', 'nama')) $customerData['nama'] = $nama;
                if (Schema::hasColumn('customers', 'name')) $customerData['name'] = $nama;
                if (Schema::hasColumn('customers', 'no_wa')) $customerData['no_wa'] = $noWa;
                if (Schema::hasColumn('customers', 'no_hp')) $customerData['no_hp'] = $noWa;
                if (Schema::hasColumn('customers', 'phone')) $customerData['phone'] = $noWa;
                if (Schema::hasColumn('customers', 'address')) $customerData['address'] = $alamat;
                if (Schema::hasColumn('customers', 'alamat')) $customerData['alamat'] = $alamat;
                if (Schema::hasColumn('customers', 'pppoe_username')) $customerData['pppoe_username'] = $username;
                if (Schema::hasColumn('customers', 'username_pppoe')) $customerData['username_pppoe'] = $username;
                if (Schema::hasColumn('customers', 'product_id')) $customerData['product_id'] = (int) $productId;
                if (Schema::hasColumn('customers', 'nama_paket') && $product) {
                    $customerData['nama_paket'] = $product->nama_paket ?? $product->name ?? null;
                }
                if (Schema::hasColumn('customers', 'status')) {
                    $customerData['status'] = $this->normalizeCustomerStatus((string) $request->input('status', 'ACTIVE'));
                }
                if (Schema::hasColumn('customers', 'status_koneksi')) {
                    $customerData['status_koneksi'] = 'Online';
                }
                if (Schema::hasColumn('customers', 'created_at')) $customerData['created_at'] = now();
                if (Schema::hasColumn('customers', 'updated_at')) $customerData['updated_at'] = now();

                $newId = DB::table('customers')->insertGetId($customerData);
                return Customer::find($newId);
            });

            return response()->json([
                'success' => true,
                'message' => 'Pelanggan berhasil ditambahkan',
                'data'    => $customer,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Gagal menambah pelanggan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambah pelanggan: ' . $this->safeErrorMessage($e),
            ], 500);
        }
    }

    /**
     * Update Pelanggan
     */
    public function update(Request $request, $id)
    {
        try {
            $customer = Customer::find($id);
            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => "Pelanggan #$id tidak ditemukan",
                ], 404);
            }

            try {
                if (!Schema::hasColumn('customers', 'pppoe_username') && !Schema::hasColumn('customers', 'username_pppoe')) {
                    Schema::table('customers', function ($table) {
                        $table->string('pppoe_username')->nullable();
                    });
                }
                if (!Schema::hasColumn('customers', 'address') && !Schema::hasColumn('customers', 'alamat') && !Schema::hasColumn('customers', 'alamat_pemasangan')) {
                    Schema::table('customers', function ($table) {
                        $table->text('alamat')->nullable();
                    });
                }
            } catch (\Throwable $schemaErr) {}

            $updateData = [];
            $namaBaru = null;
            $statusBaru = null;

            if ($request->hasAny(['nama', 'name', 'nama_pelanggan'])) {
                $namaBaru = mb_substr(trim((string) ($request->input('nama') ?? $request->input('name') ?? $request->input('nama_pelanggan') ?? '')), 0, 150);
                if ($namaBaru === '') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Nama pelanggan wajib diisi.',
                    ], 422);
                }
                if (Schema::hasColumn('customers', 'nama')) $updateData['nama'] = $namaBaru;
                if (Schema::hasColumn('customers', 'name')) $updateData['name'] = $namaBaru;
            }

            if ($request->hasAny(['no_wa', 'no_hp', 'phone', 'telepon', 'no_telp'])) {
                $noHp = mb_substr(trim((string) ($request->input('no_wa') ?? $request->input('no_hp') ?? $request->input('phone') ?? $request->input('telepon') ?? $request->input('no_telp') ?? '')), 0, 30);
                if (Schema::hasColumn('customers', 'no_wa')) $updateData['no_wa'] = $noHp;
                if (Schema::hasColumn('customers', 'no_hp')) $updateData['no_hp'] = $noHp;
                if (Schema::hasColumn('customers', 'phone')) $updateData['phone'] = $noHp;
            }

            if ($request->hasAny(['address', 'alamat', 'alamat_pemasangan'])) {
                $alamatInput = mb_substr(trim((string) ($request->input('address') ?? $request->input('alamat') ?? $request->input('alamat_pemasangan') ?? '')), 0, 500);
                $alamatVal = ($alamatInput === '' || $alamatInput === '-') ? null : $alamatInput;

                if (Schema::hasColumn('customers', 'address')) $updateData['address'] = $alamatVal;
                if (Schema::hasColumn('customers', 'alamat')) $updateData['alamat'] = $alamatVal;
                if (Schema::hasColumn('customers', 'alamat_pemasangan')) $updateData['alamat_pemasangan'] = $alamatVal;
            }

            if ($request->hasAny(['pppoe_username', 'username_pppoe', 'pppoe', 'username'])) {
                $pppoeInput = mb_substr(trim((string) ($request->input('pppoe_username') ?? $request->input('username_pppoe') ?? $request->input('pppoe') ?? $request->input('username') ?? '')), 0, 100);
                $pppoeVal = ($pppoeInput === '' || $pppoeInput === '-') ? null : $pppoeInput;

                if (Schema::hasColumn('customers', 'pppoe_username')) $updateData['pppoe_username'] = $pppoeVal;
                if (Schema::hasColumn('customers', 'username_pppoe')) $updateData['username_pppoe'] = $pppoeVal;
            }

            if ($request->hasAny(['status', 'status_langganan'])) {
                $rawStatus = (string) ($request->input('status') ?? $request->input('status_langganan') ?? 'ACTIVE');
                $normalizedStatus = $this->normalizeCustomerStatus($rawStatus);
                $statusBaru = $normalizedStatus;

                if (Schema::hasColumn('customers', 'status')) {
                    $updateData['status'] = $normalizedStatus;
                }
                if (Schema::hasColumn('customers', 'status_koneksi')) {
                    $isOnline = $this->isStatusAktif($normalizedStatus);
                    $updateData['status_koneksi'] = $isOnline ? 'Online' : 'Offline';
                }
            }

            $productIdInput = $request->input('product_id') ?? $request->input('paket_id');
            $namaPaketInput = $request->input('paket') ?? $request->input('nama_paket');
            $product = null;

            if (!empty($productIdInput)) {
                $product = Product::find($productIdInput);
            } elseif (!empty($namaPaketInput)) {
                $namaCari = trim((string) $namaPaketInput);
                if (Schema::hasColumn('products', 'nama_paket')) {
                    $product = Product::where('nama_paket', $namaCari)->first();
                }
                if (!$product && Schema::hasColumn('products', 'name')) {
                    $product = Product::where('name', $namaCari)->first();
                }
            }

            if ($product) {
                if (Schema::hasColumn('customers', 'product_id')) $updateData['product_id'] = (int) $product->id;
                if (Schema::hasColumn('customers', 'nama_paket')) {
                    $updateData['nama_paket'] = $product->nama_paket ?? $product->name ?? $namaPaketInput;
                }
            } else {
                if (!empty($productIdInput) && Schema::hasColumn('customers', 'product_id')) {
                    $updateData['product_id'] = (int) $productIdInput;
                }
                if (!empty($namaPaketInput) && Schema::hasColumn('customers', 'nama_paket')) {
                    $updateData['nama_paket'] = trim((string) $namaPaketInput);
                }
            }

            if (Schema::hasColumn('customers', 'updated_at')) {
                $updateData['updated_at'] = now();
            }

            if (!empty($updateData)) {
                DB::table('customers')->where('id', $customer->id)->update($updateData);
            }

            if ($namaBaru !== null && !empty($customer->user_id)) {
                try {
                    User::where('id', $customer->user_id)->update(['name' => $namaBaru]);
                } catch (\Throwable $e) {}
            }

            $fresh = Customer::find($customer->id);

            // Sinkronisasi invoice saat status diubah menjadi ISOLIR
            if ($statusBaru !== null && !$this->isStatusAktif($statusBaru) && $fresh) {
                $this->pastikanInvoiceTunggakanAda($fresh, $product);
            }

            return response()->json([
                'success' => true,
                'message' => 'Data pelanggan berhasil diperbarui',
                'data'    => $fresh,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Gagal mengubah pelanggan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengubah pelanggan: ' . $this->safeErrorMessage($e),
            ], 500);
        }
    }

    /**
     * Buat invoice UNPAID otomatis jika pelanggan di-isolir namun belum punya invoice tertunggak
     */
    private function pastikanInvoiceTunggakanAda(Customer $customer, ?Product $product = null): void
    {
        try {
            if (!Schema::hasTable('invoices')) return;

            $sudahAdaTunggakan = Invoice::where('customer_id', $customer->id)
                ->whereIn(DB::raw('UPPER(TRIM(status))'), self::UNPAID_INVOICE_STATUSES)
                ->exists();

            if ($sudahAdaTunggakan) return;

            $paket = $product ?? ($customer->product_id ? Product::find($customer->product_id) : null);
            $tarif = $paket ? (double) ($paket->harga ?? $paket->price ?? 0) : 0.0;
            if ($tarif <= 0) return;

            $invoiceData = [
                'customer_id' => $customer->id,
                'status'      => 'UNPAID',
            ];

            if (Schema::hasColumn('invoices', 'user_id') && !empty($customer->user_id)) {
                $invoiceData['user_id'] = $customer->user_id;
            }
            if (Schema::hasColumn('invoices', 'product_id') && $paket) {
                $invoiceData['product_id'] = $paket->id;
            }
            if (Schema::hasColumn('invoices', 'nomor_invoice')) {
                $invoiceData['nomor_invoice'] = 'INV-' . now()->format('Ymd') . '-' . str_pad((string) $customer->id, 3, '0', STR_PAD_LEFT) . strtoupper(Str::random(3));
            }
            if (Schema::hasColumn('invoices', 'total_tagihan')) {
                $invoiceData['total_tagihan'] = $tarif;
            }
            if (Schema::hasColumn('invoices', 'amount')) {
                $invoiceData['amount'] = $tarif;
            }
            if (Schema::hasColumn('invoices', 'nominal')) {
                $invoiceData['nominal'] = $tarif;
            }
            if (Schema::hasColumn('invoices', 'jatuh_tempo')) {
                $invoiceData['jatuh_tempo'] = now()->toDateString();
            }
            if (Schema::hasColumn('invoices', 'created_at')) {
                $invoiceData['created_at'] = now();
            }
            if (Schema::hasColumn('invoices', 'updated_at')) {
                $invoiceData['updated_at'] = now();
            }

            DB::table('invoices')->insert($invoiceData);
        } catch (\Throwable $e) {
            Log::warning('Auto-create invoice isolir dilewati: ' . $e->getMessage());
        }
    }

    /**
     * Hapus Pelanggan
     */
    public function destroy($id)
    {
        try {
            $customer = Customer::find($id);
            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pelanggan tidak ditemukan.',
                ], 404);
            }

            $customer->delete();

            return response()->json([
                'success' => true,
                'message' => 'Pelanggan berhasil dihapus',
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Gagal menghapus pelanggan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus pelanggan: ' . $this->safeErrorMessage($e),
            ], 500);
        }
    }

    /**
     * Ganti Password User yang Sedang Login (Anti-IDOR)
     */
    public function gantiPassword(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Silakan login terlebih dahulu.'], 401);
            }

            $passwordLama = (string) $request->input('password_lama', '');
            $passwordBaru = (string) $request->input('password_baru', '');

            if ($passwordLama === '' || $passwordBaru === '') {
                return response()->json(['success' => false, 'message' => 'Semua data wajib diisi!'], 422);
            }

            if (strlen($passwordBaru) < 6) {
                return response()->json(['success' => false, 'message' => 'Kata sandi baru minimal 6 karakter!'], 422);
            }

            if (!Hash::check($passwordLama, $user->password)) {
                return response()->json(['success' => false, 'message' => 'Kata sandi lama salah!'], 400);
            }

            $user->password = Hash::make($passwordBaru);
            $user->save();

            // Putuskan token lama selain token aktif saat ini jika ada
            if (method_exists($user, 'tokens')) {
                $currentTokenId = $user->currentAccessToken()?->id;
                $query = $user->tokens();
                if ($currentTokenId) {
                    $query->where('id', '!=', $currentTokenId);
                }
                $query->delete();
            }

            return response()->json(['success' => true, 'message' => 'Kata sandi berhasil diperbarui.'], 200);
        } catch (\Throwable $e) {
            Log::error('Gagal ganti password: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $this->safeErrorMessage($e)], 500);
        }
    }

    private function normalizeCustomerStatus(string $rawStatus): string
    {
        $upper = strtoupper(trim($rawStatus));

        $isActive = (
            str_contains($upper, 'AKTIF')
            && !str_contains($upper, 'NONAKTIF')
            && !str_contains($upper, 'NON-AKTIF')
            && !str_contains($upper, 'TIDAK')
            && !str_contains($upper, 'BELUM')
        ) || in_array($upper, ['ACTIVE', 'ONLINE', 'ENABLE', 'ENABLED', '1']);

        $isIsolir = str_contains($upper, 'ISOLIR') || str_contains($upper, 'SUSPEND') || str_contains($upper, 'BLOKIR');

        $finalStatus = $isActive ? 'ACTIVE' : ($isIsolir ? 'ISOLIR' : 'INACTIVE');

        try {
            $colInfo = DB::select("SHOW COLUMNS FROM customers LIKE 'status'");
            if (!empty($colInfo)) {
                $colType = $colInfo[0]->Type ?? '';
                if (preg_match("/^enum\((.*)\)$/i", $colType, $matches)) {
                    $allowed = array_map(fn($v) => trim($v, " '\""), explode(',', $matches[1]));
                    $allowedUpper = array_map('strtoupper', $allowed);

                    $candidates = $isActive
                        ? ['ACTIVE', 'AKTIF', 'ONLINE', 'ENABLED']
                        : ($isIsolir ? ['ISOLIR', 'SUSPENDED', 'INACTIVE', 'NONAKTIF'] : ['INACTIVE', 'BELUM_AKTIF', 'NONAKTIF', 'ISOLIR']);

                    foreach ($candidates as $cand) {
                        $idx = array_search($cand, $allowedUpper, true);
                        if ($idx !== false) {
                            return $allowed[$idx];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {}

        return $finalStatus;
    }
}