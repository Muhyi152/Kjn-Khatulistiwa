<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

   protected $fillable = [
    'nomor_invoice',
    'customer_id',
    'total_tagihan',
    'status',
    'nama_produk',
    'metode_pembayaran',
    'qr_image_url',
    'qr_string',
    'tanggal_bayar',
];

    // Opsional: Relasi ke tabel Customer
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}