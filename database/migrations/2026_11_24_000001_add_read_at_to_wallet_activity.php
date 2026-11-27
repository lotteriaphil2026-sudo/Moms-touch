<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable();
            $table->index(['user_id', 'read_at'], 'wallet_transactions_user_read_index');
        });

        Schema::table('wallet_requests', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable();
            $table->index(['user_id', 'read_at'], 'wallet_requests_user_read_index');
        });

        $readAt = now();
        DB::table('wallet_transactions')->whereNull('read_at')->update(['read_at' => $readAt]);
        DB::table('wallet_requests')->whereNull('read_at')->update(['read_at' => $readAt]);
    }

    public function down(): void
    {
        Schema::table('wallet_requests', function (Blueprint $table) {
            $table->dropIndex('wallet_requests_user_read_index');
            $table->dropColumn('read_at');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex('wallet_transactions_user_read_index');
            $table->dropColumn('read_at');
        });
    }
};
