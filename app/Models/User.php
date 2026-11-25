<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Atribut yang boleh diisi secara mass-assignment.
     * 'role' sengaja TIDAK dimasukkan agar tidak bisa dimanipulasi lewat request API.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'recovery_email',
        'password',
    ];

    /**
     * Default role untuk user baru.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'customer',
    ];

    public function isAdmin(): bool
    {
        return in_array(strtolower(trim((string) ($this->role ?? ''))), ['admin', 'administrator'], true);
    }

    public function isCustomer(): bool
    {
        return strtolower(trim((string) ($this->role ?? ''))) === 'customer';
    }

    public function customer()
    {
        return $this->hasOne(Customer::class, 'user_id');
    }

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];
}