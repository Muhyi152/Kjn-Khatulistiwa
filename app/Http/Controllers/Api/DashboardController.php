<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Customer;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        // =========================================================================
        // 1. DATA RINGKASAN KARTU STATISTIK (DIPERBAIKI)
        // =========================================================================
        $totalPelanggan = class_exists(Customer::class) ? Customer::count() : 2;

        // PERBAIKAN: Cari status 'ACTIVE', 'active', 'aktif', maupun 'Aktif'
        $koneksiAktif = class_exists(Customer::class) 
            ? Customer::whereIn('status', ['ACTIVE', 'active', 'aktif', 'Aktif'])->count() 
            : $totalPelanggan;
        
        $pendapatanBulanan = (double) Invoice::where('status', 'PAID')->sum('total_tagihan');
        $tagihanPending    = Invoice::where('status', 'PENDING')->count();

        // =========================================================================
        // 2. GRAFIK 6 BULAN TERAKHIR (Mundur sampai Bulan Berjalan)
        // =========================================================================
        $grafikPendapatan = [];
        for ($i = 5; $i >= 0; $i--) {
            // Gunakan startOfMonth() agar aman dari bug loncat bulan di tanggal 29-31
            $bulan = Carbon::now()->startOfMonth()->subMonths($i);
            $namaBulan = $bulan->translatedFormat('M'); // Misal: Jun, Jul, Agu, Sep, Okt, Nov

            // Ambil invoice berstatus PAID pada bulan & tahun tersebut
            $totalBulanIni = Invoice::where('status', 'PAID')
                ->where(function ($q) use ($bulan) {
                    $q->where(function ($sq) use ($bulan) {
                        $sq->whereMonth('tanggal_bayar', $bulan->month)
                           ->whereYear('tanggal_bayar', $bulan->year);
                    })->orWhere(function ($sq) use ($bulan) {
                        $sq->whereNull('tanggal_bayar')
                           ->whereMonth('created_at', $bulan->month)
                           ->whereYear('created_at', $bulan->year);
                    });
                })
                ->sum('total_tagihan');

            $grafikPendapatan[] = [
                'bulan' => $namaBulan,
                'total' => (double) $totalBulanIni,
            ];
        }

        // =========================================================================
        // 3. DAFTAR MAPPING NAMA RESMI KATALOG (Termasuk nominal 525k & 750k Anda)
        // =========================================================================
        $paketMapping = [
            100000  => 'Basic Home Fiber',
            150000  => 'Starter Kost Hemat',
            165000  => 'Starter Kost Hemat',
            250000  => 'Basic Home Fiber',
            375000  => 'Standard Pro Gamer',
            480000  => 'Family Stream 4K',
            525000  => 'Standard Pro Gamer',        // Menyesuaikan seeder Anda
            750000  => 'Basic Home Fiber',          // Menyesuaikan seeder Anda
            890000  => 'Content Creator Turbo',
            1200000 => 'Ultra Business Dedicated',
            1650000 => 'Warnet & Esport Arena',
            3500000 => 'Corporate Enterprise Giga',
        ];

        // 4. DAFTARKAN SEMUA 8 PAKET KATALOG RESMI (Default nilai = 0)
        $semuaKatalogPaket = [
            'Basic Home Fiber'          => 0,
            'Standard Pro Gamer'        => 0,
            'Ultra Business Dedicated'  => 0,
            'Starter Kost Hemat'        => 0,
            'Family Stream 4K'          => 0,
            'Content Creator Turbo'     => 0,
            'Warnet & Esport Arena'     => 0,
            'Corporate Enterprise Giga' => 0,
        ];

        // Warna tema masing-masing paket (konsisten)
        $warnaPaket = [
            'Basic Home Fiber'          => '#2563EB', // Biru
            'Standard Pro Gamer'        => '#10B981', // Hijau
            'Ultra Business Dedicated'  => '#F59E0B', // Oranye / Amber
            'Starter Kost Hemat'        => '#06B6D4', // Cyan
            'Family Stream 4K'          => '#8B5CF6', // Ungu
            'Content Creator Turbo'     => '#EC4899', // Pink
            'Warnet & Esport Arena'     => '#EF4444', // Merah
            'Corporate Enterprise Giga' => '#475569', // Slate / Abu Gelap
        ];

        // =========================================================================
        // 5. HITUNG TRANSAKSI YANG SUDAH DIBELI (PAID)
        // =========================================================================
        // Eager load customer & product agar nama paket terbaca akurat
        $invoicesPaid = Invoice::with('customer.product')->where('status', 'PAID')->get();
        $totalPaidCount = $invoicesPaid->count();

        foreach ($invoicesPaid as $inv) {
            $nominal = (int) $inv->total_tagihan;

            // Prioritaskan relasi produk pelanggan, lalu kolom nama_produk, lalu mapping nominal
            $nama = null;
            if (!empty($inv->nama_produk)) {
                $nama = $inv->nama_produk;
            } elseif ($inv->customer && $inv->customer->product) {
                $nama = $inv->customer->product->nama_paket ?? $inv->customer->product->name;
            } else {
                $nama = $paketMapping[$nominal] ?? 'Paket Internet';
            }

            // Normalisasi nama jika disingkat (misal: "Standard Pro" -> "Standard Pro Gamer")
            if (str_contains($nama, 'Standard Pro')) $nama = 'Standard Pro Gamer';
            if (str_contains($nama, 'Basic Home')) $nama = 'Basic Home Fiber';
            if (str_contains($nama, 'Ultra Business')) $nama = 'Ultra Business Dedicated';

            if (array_key_exists($nama, $semuaKatalogPaket)) {
                $semuaKatalogPaket[$nama]++;
            } else {
                $semuaKatalogPaket[$nama] = ($semuaKatalogPaket[$nama] ?? 0) + 1;
            }
        }

        // =========================================================================
        // 6. SUSUN ARRAY DISTRIBUSI PAKET UNTUK FLUTTER
        // =========================================================================
        $distribusiPaket = [];
        foreach ($semuaKatalogPaket as $nama => $jumlah) {
            $persentase = $totalPaidCount > 0 ? round(($jumlah / $totalPaidCount) * 100) : 0;

            $distribusiPaket[] = [
                'nama'       => $nama,
                'persentase' => (int) $persentase,
                'warna'      => $warnaPaket[$nama] ?? '#3B82F6',
            ];
        }

        return response()->json([
            'total_pelanggan'    => $totalPelanggan,
            'koneksi_aktif'      => $koneksiAktif,
            'pendapatan_bulanan' => $pendapatanBulanan,
            'tagihan_pending'    => $tagihanPending,
            'grafik_pendapatan'  => $grafikPendapatan,
            'distribusi_paket'   => $distribusiPaket,
        ], 200);
    }
}