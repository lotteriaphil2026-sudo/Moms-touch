<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('referral_code_changed')->default(false);
        });

        DB::table('users')->select('id', 'name', 'referral_code')->orderBy('id')->get()->each(function ($user): void {
            $base = preg_replace('/[^A-Z0-9]+/', '-', Str::upper(Str::ascii(trim((string) $user->name))));
            $base = trim((string) $base, '-');
            $nameBasedCode = $base !== '' ? substr($base, 0, 80) : 'MEMBER';

            DB::table('users')->where('id', $user->id)->update([
                'referral_code_changed' => (string) $user->referral_code !== $nameBasedCode,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('referral_code_changed');
        });
    }
};
