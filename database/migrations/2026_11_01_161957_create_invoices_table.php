<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('invoices', function (Blueprint $table) {
        $table->id();
        $table->string('nomor_invoice')->unique(); // misal: INV-202410-001
        $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
        $table->bigInteger('total_tagihan');
        $table->enum('status', ['PENDING', 'PAID', 'EXPIRED'])->default('PENDING');
        $table->timestamp('tanggal_bayar')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
