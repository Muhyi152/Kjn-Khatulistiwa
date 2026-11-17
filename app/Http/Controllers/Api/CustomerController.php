<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerController extends Controller
{
    /**
     * Tampilkan semua pelanggan beserta status TAGIHAN DARI MUTASI RIIL (INVOICE)
     */
    public function index()
    {
        try {
            $allProducts = Product::all()->keyBy('id');
            $customers = Customer::orderBy('id', 'desc')->get();

            $data = $customers->map(function ($c) use ($allProducts) {
                // 1. Ambil info paket langganan
                $product = $c->product_id ? $allProducts->get($c->product_id) : null;
                if (!$product && $allProducts->isNotEmpty()) {
                    $product = $allProducts->first();
                }

                $namaPaket = $product
                    ? ($product->nama_paket ?? $product->name ?? 'Paket #' . $product->id)
                    : ($c->nama_paket ?? 'Paket Belum Diatur');

                $tarifPaket = $product ? (double) ($product->harga ?? $product->price ?? 0) : 0.0;

                // 2. LOGIKA MUTASI RIIL: Cek apakah ada invoice PENDING / BELUM DIBAYAR
                $pendingInvoice = Invoice::where('customer_id', $c->id)
                    ->whereIn(DB::raw('UPPER(status)'), ['PENDING', 'UNPAID', 'BELUM_BAYAR'])
                    ->orderBy('id', 'desc')
                    ->first();

                $tagihanRiil = $pendingInvoice ? (double) $pendingInvoice->total_tagihan : 0.0;

                $namaVal   = ($c->nama ?? null) ?: (($c->name ?? null) ?: 'Pelanggan #' . $c->id);
                $noWaVal   = ($c->no_wa ?? null) ?: (($c->no_hp ?? null) ?: (($c->phone ?? null) ?: '-'));
                $alamatVal = ($c->address ?? null) ?: (($c->alamat ?? null) ?: (($c->alamat_pemasangan ?? null) ?: '-'));
                $pppoeVal  = ($c->pppoe_username ?? null) ?: (($c->username_pppoe ?? null) ?: '-');

                return [
                    'id'                => (int) $c->id,
                    'nama'              => $namaVal,
                    'name'              => $namaVal,
                    'no_wa'             => $noWaVal,
                    'no_hp'             => $noWaVal,
                    'phone'             => $noWaVal,
                    'address'           => $alamatVal,
                    'alamat'            => $alamatVal,
                    'pppoe_username'    => $pppoeVal,
                    'username_pppoe'    => $pppoeVal,
                    'status'            => strtoupper($c->status ?? 'ACTIVE'),
                    'product_id'        => $product ? (int) $product->id : (int) $c->product_id,

                    // Nama Paket
                    'nama_paket'        => $namaPaket,
                    'paket_internet'    => $namaPaket,
                    'paket'             => $namaPaket,

                    // TAGIHAN RIIL (Dari Mutasi Invoice)
                    'tagihan'           => $tagihanRiil,
                    'total_tagihan'     => $tagihanRiil,
                    'nominal'           => $tagihanRiil,
                    'is_lunas'          => ($tagihanRiil <= 0),
                    'status_tagihan'    => $pendingInvoice ? 'BELUM_BAYAR' : 'LUNAS',
                    'nomor_invoice'     => $pendingInvoice ? $pendingInvoice->nomor_invoice : null,

                    // Tarif Asli Paket Bulanan
                    'tarif_paket'       => $tarifPaket,
                    'harga_paket'       => $tarifPaket,

                    'created_at'        => $c->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $data,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data pelanggan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Tambah Pelanggan Baru (Otomatis dibuatkan akun di tabel 'users')
     */
    public function store(Request $request)
    {
        try {
            $nama     = trim((string) ($request->input('nama') ?? $request->input('name') ?? ''));
            $noWa     = trim((string) ($request->input('no_wa') ?? $request->input('no_hp') ?? $request->input('phone') ?? '-'));
            $alamat   = trim((string) ($request->input('address') ?? $request->input('alamat') ?? $request->input('alamat_pemasangan') ?? '-'));

            $namaDepan = strtolower(explode(' ', $nama)[0] ?: 'user');
            $email     = trim((string) ($request->input('email') ?? ($namaDepan . rand(10, 99) . '@kjn.id')));
            $username  = trim((string) ($request->input('pppoe_username') ?? $request->input('username_pppoe') ?? ($namaDepan . '@kjn')));
            $password  = $request->input('password') ?? '12345678';

            // 1. Cek Paket
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

            // 2. Buat Akun Login di tabel users
            $user = User::where('email', $email)->first();
            if (!$user) {
                $user = User::create([
                    'name'              => $nama,
                    'email'             => $email,
                    'password'          => Hash::make($password),
                    'role'              => 'customer',
                    'email_verified_at' => now(),
                ]);
            }

            // 3. Buat Data di tabel customers sesuai kolom yang tersedia di DB
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
                $customerData['status'] = $this->normalizeCustomerStatus($request->input('status', 'ACTIVE'));
            }
            if (Schema::hasColumn('customers', 'status_koneksi')) {
                $customerData['status_koneksi'] = 'Online';
            }
            if (Schema::hasColumn('customers', 'created_at')) $customerData['created_at'] = now();
            if (Schema::hasColumn('customers', 'updated_at')) $customerData['updated_at'] = now();

            $newId = DB::table('customers')->insertGetId($customerData);
            $customer = Customer::find($newId);

            return response()->json([
                'success' => true,
                'message' => 'Pelanggan berhasil ditambahkan',
                'data'    => $customer,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambah pelanggan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update Pelanggan (Mendukung semua variasi nama field & tipe kolom DB)
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

            // Pastikan kolom PPPoE dan Alamat tersedia jika sebelumnya belum ada di tabel
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

            // 1. Nama Pelanggan
            if ($request->hasAny(['nama', 'name', 'nama_pelanggan'])) {
                $namaBaru = trim((string) ($request->input('nama') ?? $request->input('name') ?? $request->input('nama_pelanggan') ?? ''));
                if ($namaBaru === '') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Nama pelanggan wajib diisi.',
                    ], 422);
                }
                if (Schema::hasColumn('customers', 'nama')) $updateData['nama'] = $namaBaru;
                if (Schema::hasColumn('customers', 'name')) $updateData['name'] = $namaBaru;
            }

            // 2. No. WhatsApp / HP
            if ($request->hasAny(['no_wa', 'no_hp', 'phone', 'telepon', 'no_telp'])) {
                $noHp = trim((string) ($request->input('no_wa') ?? $request->input('no_hp') ?? $request->input('phone') ?? $request->input('telepon') ?? $request->input('no_telp') ?? ''));
                if (Schema::hasColumn('customers', 'no_wa')) $updateData['no_wa'] = $noHp;
                if (Schema::hasColumn('customers', 'no_hp')) $updateData['no_hp'] = $noHp;
                if (Schema::hasColumn('customers', 'phone')) $updateData['phone'] = $noHp;
            }

            // 3. Alamat Pemasangan
            if ($request->hasAny(['address', 'alamat', 'alamat_pemasangan'])) {
                $alamatInput = trim((string) ($request->input('address') ?? $request->input('alamat') ?? $request->input('alamat_pemasangan') ?? ''));
                $alamatVal = ($alamatInput === '' || $alamatInput === '-') ? null : $alamatInput;

                if (Schema::hasColumn('customers', 'address')) $updateData['address'] = $alamatVal;
                if (Schema::hasColumn('customers', 'alamat')) $updateData['alamat'] = $alamatVal;
                if (Schema::hasColumn('customers', 'alamat_pemasangan')) $updateData['alamat_pemasangan'] = $alamatVal;
            }

            // 4. Username PPPoE
            if ($request->hasAny(['pppoe_username', 'username_pppoe', 'pppoe', 'username'])) {
                $pppoeInput = trim((string) ($request->input('pppoe_username') ?? $request->input('username_pppoe') ?? $request->input('pppoe') ?? $request->input('username') ?? ''));
                $pppoeVal = ($pppoeInput === '' || $pppoeInput === '-') ? null : $pppoeInput;

                if (Schema::hasColumn('customers', 'pppoe_username')) $updateData['pppoe_username'] = $pppoeVal;
                if (Schema::hasColumn('customers', 'username_pppoe')) $updateData['username_pppoe'] = $pppoeVal;
            }

            // 5. Status Langganan
            if ($request->hasAny(['status', 'status_langganan'])) {
                $rawStatus = (string) ($request->input('status') ?? $request->input('status_langganan') ?? 'ACTIVE');
                $normalizedStatus = $this->normalizeCustomerStatus($rawStatus);

                if (Schema::hasColumn('customers', 'status')) {
                    $updateData['status'] = $normalizedStatus;
                }
                if (Schema::hasColumn('customers', 'status_koneksi')) {
                    $isOnline = in_array(strtoupper($normalizedStatus), ['ACTIVE', 'AKTIF', 'ONLINE', 'ENABLED']);
                    $updateData['status_koneksi'] = $isOnline ? 'Online' : 'Offline';
                }
            }

            // 6. Pilihan Paket Internet
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

            // Update langsung via DB Query Builder agar tidak terhambat $fillable pada Model
            if (!empty($updateData)) {
                DB::table('customers')->where('id', $customer->id)->update($updateData);
            }

            // Sinkronkan nama ke tabel users jika terhubung
            if ($namaBaru !== null && !empty($customer->user_id)) {
                try {
                    User::where('id', $customer->user_id)->update(['name' => $namaBaru]);
                } catch (\Throwable $e) {}
            }

            $fresh = Customer::find($customer->id);

            return response()->json([
                'success' => true,
                'message' => 'Data pelanggan berhasil diperbarui',
                'data'    => $fresh,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengubah pelanggan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Hapus Pelanggan
     */
    public function destroy($id)
    {
        try {
            $customer = Customer::findOrFail($id);
            $customer->delete();

            return response()->json([
                'success' => true,
                'message' => 'Pelanggan berhasil dihapus',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus pelanggan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ganti Password Customer
     */
    public function gantiPassword(Request $request)
    {
        try {
            $userId       = $request->input('user_id');
            $passwordLama = $request->input('password_lama');
            $passwordBaru = $request->input('password_baru');

            if (empty($userId) || empty($passwordLama) || empty($passwordBaru)) {
                return response()->json(['success' => false, 'message' => 'Semua data wajib diisi!'], 422);
            }

            $user = is_numeric($userId) ? User::find($userId) : User::where('email', $userId)->first();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User tidak ditemukan.'], 404);
            }

            if (!Hash::check($passwordLama, $user->password)) {
                return response()->json(['success' => false, 'message' => 'Kata sandi lama salah!'], 400);
            }

            $user->password = Hash::make($passwordBaru);
            $user->save();

            return response()->json(['success' => true, 'message' => 'Kata sandi berhasil diperbarui.'], 200);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Helper: Normalisasi status agar selalu cocok dengan tipe kolom (VARCHAR / ENUM) di MySQL
     */
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