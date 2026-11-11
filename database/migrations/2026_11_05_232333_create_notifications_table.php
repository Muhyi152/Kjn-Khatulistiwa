<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable(); // null jika untuk broadcast admin
            $table->string('role_target')->default('customer'); // 'admin' atau 'customer'
            $table->string('title');
            $table->text('message');
            $table->string('type')->default('pembayaran'); // pembayaran, tagihan, sistem
            $table->boolean('is_read')->default(false);    // status permanen di DB
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};