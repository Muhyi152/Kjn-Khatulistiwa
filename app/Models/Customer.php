<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $table = 'customers';

    // Sesuaikan HANYA dengan kolom yang benar-benar ada di tabel database Anda
    protected $fillable = [
        'product_id',
        'nama',
        'no_wa',
        'address',
        'pppoe_username',
        'status',
    ];

    // Nilai default untuk kolom jika tidak diisi
    protected $attributes = [
        'status' => 'ACTIVE',
    ];

    /**
     * Relasi ke Model Product (belongsTo)
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Accessor aman: jika address NULL di database, kembalikan '-'
     */
    public function getAddressAttribute($value)
    {
        return !empty($value) ? $value : '-';
    }

    /**
     * Accessor aman: jika pppoe_username NULL di database, kembalikan '-'
     */
    public function getPppoeUsernameAttribute($value)
    {
        return !empty($value) ? $value : '-';
    }

    /**
     * Alias virtual jika ada kode yang memanggil ->phone atau ->no_hp
     */
    public function getPhoneAttribute()
    {
        return $this->no_wa ?? '-';
    }

    /**
     * Alias virtual jika ada kode yang memanggil ->name
     */
    public function getNameAttribute()
    {
        return $this->nama ?? 'Tanpa Nama';
    }

    /**
     * Alias virtual jika ada kode yang memanggil ->alamat
     */
    public function getAlamatAttribute()
    {
        return $this->address ?? '-';
    }
}