<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Invoice;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. DATA PRODUK (Diselaraskan dengan Chart Plan Distribution Dashboard)
        $p1 = Product::updateOrCreate(
            ['nama_paket' => 'Standard Pro'],
            ['kecepatan_mbps' => 50, 'harga' => 525000]
        );

        $p2 = Product::updateOrCreate(
            ['nama_paket' => 'Basic Home'],
            ['kecepatan_mbps' => 20, 'harga' => 750000]
        );

        $p3 = Product::updateOrCreate(
            ['nama_paket' => 'Ultra Business'],
            ['kecepatan_mbps' => 100, 'harga' => 1200000]
        );

        // 2. DATA CUSTOMERS (2 Pelanggan Aktif -> Sinkron dengan angka "2" di Dashboard)
        $c1 = Customer::updateOrCreate(
            ['nama' => 'Budi Santoso'],
            [
                'product_id' => $p1->id,
                'no_wa'      => '08123456789',
                'status'     => 'ACTIVE',
            ]
        );

        $c2 = Customer::updateOrCreate(
            ['nama' => 'Siti Rahma'],
            [
                'product_id' => $p2->id,
                'no_wa'      => '08987654321',
                'status'     => 'ACTIVE',
            ]
        );

        // 3. DATA INVOICES (Total: Rp 525.000 + Rp 750.000 = Rp 1.275.000 Pas sesuai Dashboard!)
        // Keduanya berstatus 'PAID' agar Pending Invoices tetap bernilai 0
        Invoice::updateOrCreate(
            ['nomor_invoice' => 'INV-202611-001'],
            [
                'customer_id'   => $c1->id,
                'total_tagihan' => 525000,
                'status'        => 'PAID',
                'tanggal_bayar' => now()->subDay(),
            ]
        );

        Invoice::updateOrCreate(
            ['nomor_invoice' => 'INV-202611-002'],
            [
                'customer_id'   => $c2->id,
                'total_tagihan' => 750000,
                'status'        => 'PAID',
                'tanggal_bayar' => now(),
            ]
        );
    }
}