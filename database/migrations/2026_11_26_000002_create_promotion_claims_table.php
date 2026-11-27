<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('promotion_key', 64);
            $table->foreignId('wallet_transaction_id')->unique()->constrained('wallet_transactions')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'promotion_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_claims');
    }
};
