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
        'nama_produk', // <-- Sudah tepat di sini
        'total_tagihan',
        'status',
        'tanggal_bayar',
    ];

    // Opsional: Relasi ke tabel Customer
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}