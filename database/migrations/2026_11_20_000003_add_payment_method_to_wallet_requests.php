<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('wallet_requests', function (Blueprint $table) {
            $table->string('payment_method', 24)->nullable();
            $table->boolean('test_only')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('wallet_requests', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'test_only']);
        });
    }
};
