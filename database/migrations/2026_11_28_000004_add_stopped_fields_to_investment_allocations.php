<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_allocations', function (Blueprint $table) {
            $table->timestamp('stopped_at')->nullable();
            $table->foreignId('stopped_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('investment_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stopped_by');
            $table->dropColumn('stopped_at');
        });
    }
};
