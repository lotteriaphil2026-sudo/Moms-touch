<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('investment_allocations', function (Blueprint $table) {
            $table->string('plan_name')->nullable();
            $table->unsignedSmallInteger('rate_basis_points')->nullable();
            $table->unsignedSmallInteger('term_days')->nullable();
            $table->unsignedSmallInteger('credited_days')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('matures_at')->nullable();
        });
        // Existing pending allocations retain their original principal and unset terms.
    }
    public function down(): void
    {
        Schema::table('investment_allocations', fn (Blueprint $table) => $table->dropColumn([
            'plan_name', 'rate_basis_points', 'term_days', 'credited_days', 'started_at', 'matures_at',
        ]));
    }
};
