<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('withdrawal_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('provider', 80);
            $table->text('account_holder');
            $table->text('account_number');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('wallet_requests', function (Blueprint $table) {
            $table->foreignId('withdrawal_account_id')->nullable()->constrained('withdrawal_accounts')->nullOnDelete();
            $table->string('withdrawal_account_type', 16)->nullable();
            $table->string('withdrawal_account_provider', 80)->nullable();
            $table->text('withdrawal_account_holder')->nullable();
            $table->text('withdrawal_account_number')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('withdrawal_account_id');
            $table->dropColumn([
                'withdrawal_account_type',
                'withdrawal_account_provider',
                'withdrawal_account_holder',
                'withdrawal_account_number',
            ]);
        });

        Schema::dropIfExists('withdrawal_accounts');
    }
};
