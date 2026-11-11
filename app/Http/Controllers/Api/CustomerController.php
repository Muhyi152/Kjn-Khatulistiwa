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
                    : 'Paket Belum Diatur';

                $tarifPaket = $product ? (double) ($product->harga ?? 0) : 0.0;

                // 2. LOGIKA MUTASI RIIL: Cek apakah ada invoice PENDING / BELUM DIBAYAR
                $pendingInvoice = Invoice::where('customer_id', $c->id)
                    ->whereIn(DB::raw('UPPER(status)'), ['PENDING', 'UNPAID', 'BELUM_BAYAR'])
                    ->orderBy('id', 'desc')
                    ->first();

                // Jika ada invoice pending -> Tampilkan nominal tagihannya
                // Jika tidak ada pending (sudah PAID / tidak ada tunggakan) -> Tagihan = 0 (Lunas)
                $tagihanRiil = $pendingInvoice ? (double) $pendingInvoice->total_tagihan : 0.0;

                return [
                    'id'                => (int) $c->id,
                    'nama'              => $c->nama ?? $c->name ?? 'Pelanggan #' . $c->id,
                    'name'              => $c->nama ?? $c->name ?? 'Pelanggan #' . $c->id,
                    'no_wa'             => $c->no_wa ?? $c->phone ?? '-',
                    'phone'             => $c->no_wa ?? $c->phone ?? '-',
                    'address'           => $c->address ?? $c->alamat ?? '-',
                    'alamat'            => $c->address ?? $c->alamat ?? '-',
                    'pppoe_username'    => $c->pppoe_username ?? '-',
                    'username_pppoe'    => $c->pppoe_username ?? '-',
                    'status'            => strtoupper($c->status ?? 'ACTIVE'),
                    'product_id'        => $product ? (int) $product->id : (int) $c->product_id,

                    // Nama Paket
                    'nama_paket'        => $namaPaket,
                    'paket_internet'    => $namaPaket,
                    'paket'             => $namaPaket,

                    // TAGIHAN RIIL (Dari Mutasi Invoice)
                    'tagihan'           => $tagihanRiil,          // 0 jika lunas, >0 jika ada invoice pending
                    'total_tagihan'     => $tagihanRiil,
                    'nominal'           => $tagihanRiil,
                    'is_lunas'          => ($tagihanRiil <= 0),
                    'status_tagihan'    => $pendingInvoice ? 'BELUM_BAYAR' : 'LUNAS',
                    'nomor_invoice'     => $pendingInvoice ? $pendingInvoice->nomor_invoice : null,

                    // Tarif Asli Paket Bulanan (sebagai info pendukung)
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
            $nama      = $request->input('nama') ?? $request->input('name');
            $noWa      = $request->input('no_wa') ?? $request->input('phone') ?? '-';
            $alamat    = $request->input('address') ?? $request->input('alamat') ?? '-';
            
            $namaDepan = strtolower(explode(' ', trim($nama))[0]);
            $email     = $request->input('email') ?? ($namaDepan . rand(10, 99) . '@kjn.id');
            $username  = $request->input('pppoe_username') ?? ($namaDepan . '@kjn');
            $password  = $request->input('password') ?? '12345678';

            // 1. Cek Paket
            $productId = $request->input('product_id') ?? $request->input('paket_id');
            if (empty($productId) && ($request->filled('paket') || $request->filled('nama_paket'))) {
                $namaCari = trim($request->input('paket') ?? $request->input('nama_paket'));
                $p = Product::where('nama_paket', $namaCari)->orWhere('name', $namaCari)->first();
                if ($p) $productId = $p->id;
            }
            if (empty($productId)) {
                $pFirst = Product::first();
                $productId = $pFirst ? $pFirst->id : 1;
            }

            // 2. Buat Akun Login di tabel users
            $user = User::where('email', $email)->first();
            if (!$user) {
                User::create([
                    'name'              => $nama,
                    'email'             => $email,
                    'password'          => Hash::make($password),
                    'role'              => 'customer',
                    'email_verified_at' => now(),
                ]);
            }

            // 3. Buat Data di tabel customers
            $customer = Customer::create([
                'nama'           => $nama,
                'no_wa'          => $noWa,
                'address'        => $alamat,
                'pppoe_username' => $username,
                'product_id'     => (int) $productId,
                'status'         => strtoupper($request->input('status', 'ACTIVE')),
            ]);

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
     * Update Pelanggan
     */
    public function update(Request $request, $id)
    {
        try {
            $customer = Customer::find($id);
            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => "Pelanggan #$id tidak ditemukan"
                ], 404);
            }

            if ($request->filled('nama') || $request->filled('name')) {
                $customer->nama = $request->input('nama') ?? $request->input('name');
            }
            if ($request->filled('no_wa') || $request->filled('phone')) {
                $customer->no_wa = $request->input('no_wa') ?? $request->input('phone');
            }
            if ($request->filled('address') || $request->filled('alamat')) {
                $customer->address = $request->input('address') ?? $request->input('alamat');
            }
            if ($request->filled('pppoe_username')) {
                $customer->pppoe_username = $request->input('pppoe_username');
            }
            if ($request->filled('status')) {
                $customer->status = strtoupper($request->input('status'));
            }

            if ($request->filled('product_id')) {
                $customer->product_id = (int) $request->input('product_id');
            } elseif ($request->filled('paket') || $request->filled('nama_paket')) {
                $namaCari = trim($request->input('paket') ?? $request->input('nama_paket'));
                $p = Product::where('nama_paket', $namaCari)->orWhere('name', $namaCari)->first();
                if ($p) $customer->product_id = $p->id;
            }

            $customer->save();

            return response()->json([
                'success' => true,
                'message' => 'Data pelanggan berhasil diperbarui',
                'data'    => $customer,
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
}