<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('balance_cents')->default(0);
            $table->unsignedBigInteger('invested_cents')->default(0);
            $table->unsignedBigInteger('earnings_cents')->default(0);
            $table->timestamps();
        });
        Schema::create('investment_allocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('plan');
            $table->unsignedBigInteger('amount_cents');
            $table->string('status')->default('pending_rate');
            $table->decimal('daily_interest_rate', 10, 6)->nullable();
            $table->timestamps();
        });
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('request_fingerprint', 64);
            $table->string('type');
            $table->string('description');
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('balance_after_cents');
            $table->string('status')->default('completed');
            $table->timestamps();
            $table->unique(['user_id', 'request_id']);
            $table->index(['user_id', 'created_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('investment_allocations');
        Schema::dropIfExists('wallets');
    }
};
