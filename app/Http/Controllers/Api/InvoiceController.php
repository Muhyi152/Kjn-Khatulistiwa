<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Models\Notification;

class InvoiceController extends Controller
{
    /**
     * 1. AMBIL TAGIHAN AKTIF / PENDING CUSTOMER YANG SEDANG LOGIN
     * Endpoint: GET /api/customer/invoice/active ATAU GET /api/invoices/active
     * (INI YANG MEMBUAT DASHBOARD HAMZAH & SCAN QRIS MENJADI MUNCUL TAGIHANNYA)
     */
    public function getActiveInvoice(Request $request)
    {
        try {
            // Ambil id dari user login Sanctum atau dari parameter request Flutter
            $userId = Auth::id() ?? $request->query('user_id') ?? $request->query('customer_id');

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak teridentifikasi',
                ], 401);
            }

            // Temukan ID customer di tabel customers yang cocok dengan user ini
            $customer = $this->cariCustomerAman($userId);
            $customerId = $customer ? $customer->id : $userId;

            // Cari invoice dengan status PENDING / UNPAID milik customer ini
            $invoice = Invoice::where(function ($query) use ($customerId, $userId) {
                    $query->where('customer_id', $customerId)
                          ->orWhere('customer_id', $userId);
                })
                ->whereIn('status', ['PENDING', 'UNPAID', 'BELUM_BAYAR'])
                ->orderBy('id', 'desc')
                ->first();

            if (!$invoice) {
                return response()->json([
                    'success' => true,
                    'message' => 'Tidak ada tagihan aktif',
                    'data'    => null,
                ], 200);
            }

            // Ambil nominal tagihan dan nama paket
            $totalTagihan = (double) ($invoice->total_tagihan ?? $invoice->nominal ?? 0);
            $namaPaket = $invoice->nama_produk ?? null;

            if (!$namaPaket && $customer && $customer->product_id) {
                $prod = Product::find($customer->product_id);
                $namaPaket = $prod ? ($prod->nama_paket ?? $prod->name) : 'Paket Internet';
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'id'                => $invoice->id,
                    'nomor_invoice'     => $invoice->nomor_invoice,
                    'total_tagihan'     => $totalTagihan,
                    'nominal'           => $totalTagihan,
                    'tagihan'           => $totalTagihan,
                    'status'            => strtoupper($invoice->status),
                    'nama_produk'       => $namaPaket ?? 'Paket Internet',
                    'paket'             => $namaPaket ?? 'Paket Internet',
                    'metode_pembayaran' => $invoice->metode_pembayaran ?? 'QRIS',
                    'created_at'        => $invoice->created_at,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil tagihan aktif: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 2. RIWAYAT INVOICE CUSTOMER
     * Endpoint: GET /api/customer/mutasi/{customerId}
     */
    public function myInvoices(Request $request, $customerId)
    {
        try {
            $customer = $this->cariCustomerAman($customerId);
            $targetId = $customer ? $customer->id : $customerId;

            $invoices = Invoice::where('customer_id', $targetId)
                ->orWhere('customer_id', $customerId)
                ->orderBy('id', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $invoices,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil riwayat invoice: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 3. CEK STATUS PEMBAYARAN (VALIDASI LUNAS, KURANG BAYAR, & PENDING)
     * Endpoint: POST /api/invoices/check-status
     */
    public function checkStatus(Request $request)
    {
        try {
            $nomorInvoice = $request->input('nomor_invoice');
            $totalTagihan = (int) $request->input('total_tagihan');
            $customerId   = $request->input('customer_id');
            $namaProduk   = $request->input('nama_produk') ?? 'Paket Internet';

            // 1. Cari data invoice di database
            $invoice = Invoice::where('nomor_invoice', $nomorInvoice)->first();

            // 2. Baca dana yang sudah masuk
            $danaMasuk = 0;
            if ($invoice) {
                if (isset($invoice->dana_masuk)) {
                    $danaMasuk = (int) $invoice->dana_masuk;
                } elseif (strtoupper($invoice->status) === 'PAID') {
                    $danaMasuk = (int) $invoice->total_tagihan;
                }
            }

            // 3. Logika Penentuan Status Pembayaran
            if ($invoice && (strtoupper($invoice->status) === 'PAID' || ($danaMasuk >= $totalTagihan && $totalTagihan > 0))) {
                // KASUS A: LUNAS PAS / LEBIH
                $status    = 'PAID';
                $danaMasuk = $totalTagihan;

                // Update invoice jika belum berstatus PAID
                if (strtoupper($invoice->status) !== 'PAID') {
                    $invoice->update([
                        'status'        => 'PAID',
                        'tanggal_bayar' => now(),
                    ]);

                    $targetId = $customerId ?? $invoice->customer_id;
                    $this->buatNotifikasiPembayaranLunas($nomorInvoice, $totalTagihan, $targetId, $namaProduk);
                }

                // Update status customer menjadi ACTIVE
                $targetId = $customerId ?? $invoice->customer_id;
                if ($targetId) {
                    $cust = $this->cariCustomerAman($targetId);
                    if ($cust) {
                        $updateData = ['status' => 'ACTIVE'];
                        if (Schema::hasColumn('customers', 'nama_paket') && $namaProduk) {
                            $updateData['nama_paket'] = $namaProduk;
                        }
                        $cust->update($updateData);
                    }
                }
            } elseif ($danaMasuk > 0 && $danaMasuk < $totalTagihan) {
                // KASUS B: DANA MASUK TAPI KURANG (UNDERPAID)
                $status = 'UNDERPAID';
            } else {
                // KASUS C: BELUM ADA DANA MASUK SAMA SEKALI
                $status = 'PENDING';
            }

            $kekurangan = max(0, $totalTagihan - $danaMasuk);

            return response()->json([
                'success'       => true,
                'nomor_invoice' => $nomorInvoice,
                'total_tagihan' => $totalTagihan,
                'dana_masuk'    => $danaMasuk,
                'kekurangan'    => $kekurangan,
                'status'        => $status,
                'message'       => $status === 'UNDERPAID' 
                    ? 'Pembayaran kurang dari total tagihan.' 
                    : ($status === 'PAID' ? 'Pembayaran telah lunas.' : 'Pembayaran belum terdeteksi.'),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses validasi invoice: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 4. SIMPAN / TERBITKAN INVOICE BARU (BELI QRIS)
     * Endpoint: POST /api/invoices
     */
    public function store(Request $request)
    {
        try {
            $nomorInvoice = $request->input('nomor_invoice') ?? ('INV-' . date('Ymd') . '-' . rand(1000, 9999));
            $customerId   = $request->input('customer_id');
            $totalTagihan = (int) ($request->input('total_tagihan') ?? $request->input('nominal') ?? $request->input('harga') ?? 0);
            $namaProduk   = $request->input('nama_produk') ?? $request->input('paket') ?? 'Paket Internet';
            $statusInput  = strtoupper($request->input('status', 'PENDING'));
            $metodeBayar  = $request->input('metode_pembayaran', 'QRIS');

            $cust = $this->cariCustomerAman($customerId);
            $targetCustomerId = $cust ? $cust->id : ($customerId ?? 1);

            $dataSimpan = [
                'customer_id'   => $targetCustomerId,
                'total_tagihan' => $totalTagihan,
                'status'        => $statusInput,
            ];

            if ($statusInput === 'PAID') {
                $dataSimpan['tanggal_bayar'] = now();
            }

            if ($namaProduk && Schema::hasColumn('invoices', 'nama_produk')) {
                $dataSimpan['nama_produk'] = $namaProduk;
            }

            if (Schema::hasColumn('invoices', 'metode_pembayaran')) {
                $dataSimpan['metode_pembayaran'] = $metodeBayar;
            }

            if (Schema::hasColumn('invoices', 'dana_masuk')) {
                $dataSimpan['dana_masuk'] = ($statusInput === 'PAID') ? $totalTagihan : 0;
            }

            $invoice = Invoice::updateOrCreate(
                ['nomor_invoice' => $nomorInvoice],
                $dataSimpan
            );

            // Jika status lunas, aktifkan status customer
            if ($statusInput === 'PAID' && $cust) {
                $updateCust = ['status' => 'ACTIVE'];
                if (Schema::hasColumn('customers', 'nama_paket') && $namaProduk) {
                    $updateCust['nama_paket'] = $namaProduk;
                }
                $cust->update($updateCust);

                $this->buatNotifikasiPembayaranLunas($nomorInvoice, $totalTagihan, $targetCustomerId, $namaProduk);
            }

            return response()->json([
                'success'    => true,
                'message'    => 'Invoice tagihan berhasil dibuat!',
                'status'     => $statusInput,
                'dana_masuk' => $dataSimpan['dana_masuk'] ?? 0,
                'data'       => $invoice,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan invoice: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 5. DETAIL INVOICE TUNGGAL
     * Endpoint: GET /api/invoice-status/{invoiceId}
     */
    public function show($invoiceId)
    {
        try {
            $invoice = Invoice::where('nomor_invoice', $invoiceId)->first();

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'status'  => 'NOT_FOUND',
                    'message' => 'Invoice tidak ditemukan',
                ], 404);
            }

            $danaMasuk = isset($invoice->dana_masuk) 
                ? (double) $invoice->dana_masuk 
                : (strtoupper($invoice->status) === 'PAID' ? (double) $invoice->total_tagihan : 0.0);

            return response()->json([
                'success'       => true,
                'status'        => strtoupper($invoice->status),
                'nomor_invoice' => $invoice->nomor_invoice,
                'total_tagihan' => (double) $invoice->total_tagihan,
                'dana_masuk'    => $danaMasuk,
                'nama_produk'   => $invoice->nama_produk ?? null,
                'tanggal_bayar' => $invoice->tanggal_bayar,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil invoice: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper pencarian customer yang akurat mencocokkan id tabel users & customers
     */
    private function cariCustomerAman($idOrEmail)
    {
        if (empty($idOrEmail)) return null;

        // 1. Coba cari langsung by ID di customers
        if (is_numeric($idOrEmail)) {
            $cust = Customer::find($idOrEmail);
            if ($cust) return $cust;
        }

        // 2. Jika ID adalah user_id dari tabel users
        $user = is_numeric($idOrEmail) 
            ? User::find($idOrEmail) 
            : User::where('email', $idOrEmail)->first();

        if ($user) {
            // Ambil kata pertama nama (misal "Hamzah" dari "Hamzah Ganteng")
            $namaDepan = explode(' ', trim($user->name))[0];
            $cust = Customer::where('nama', 'LIKE', '%' . $namaDepan . '%')->first();
            if ($cust) return $cust;
        }

        return null;
    }

    /**
     * Helper membuat notifikasi
     */
    private function buatNotifikasiPembayaranLunas($nomorInvoice, $totalTagihan, $customerId, $namaProduk)
    {
        if (Schema::hasTable('notifications')) {
            Notification::create([
                'role_target' => 'admin',
                'title'       => 'Pembayaran Masuk',
                'message'     => "Invoice {$nomorInvoice} sebesar Rp " . number_format($totalTagihan, 0, ',', '.') . " telah lunas via QRIS.",
                'type'        => 'pembayaran',
                'is_read'     => false,
            ]);

            if ($customerId) {
                Notification::create([
                    'user_id'     => $customerId,
                    'role_target' => 'customer',
                    'title'       => 'Pembayaran Berhasil',
                    'message'     => "Paket internet {$namaProduk} telah aktif. Terima kasih atas pembayaran Anda.",
                    'type'        => 'pembayaran',
                    'is_read'     => false,
                ]);
            }
        }
    }
}