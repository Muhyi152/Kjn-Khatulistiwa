<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. BUAT AKUN ADMIN
        User::updateOrCreate(
            ['email' => 'admin@kjn.id'],
            [
                'name'              => 'Administrator KJN',
                'password'          => Hash::make('password123'),
                'role'              => 'admin',
                'email_verified_at' => now(),
            ]
        );

        // 2. BUAT AKUN CUSTOMER (Budi Santoso)
        User::updateOrCreate(
            ['email' => 'customer@kjn.id'],
            [
                'name'              => 'Budi Santoso',
                'password'          => Hash::make('password123'),
                'role'              => 'customer',
                'email_verified_at' => now(),
            ]
        );

        // 3. JALANKAN DEMO SEEDER
        $this->call([
            DemoSeeder::class,
        ]);
    }
}