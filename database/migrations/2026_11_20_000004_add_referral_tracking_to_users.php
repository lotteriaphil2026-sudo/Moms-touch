<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 12)->nullable()->unique();
            $table->foreignId('referrer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('referrer_type', 16)->default('platform');
        });

        $usedCodes = [];
        DB::table('users')->select('id')->orderBy('id')->chunkById(100, function ($users) use (&$usedCodes) {
            foreach ($users as $user) {
                do {
                    $code = Str::upper(Str::random(12));
                } while (isset($usedCodes[$code]));

                $usedCodes[$code] = true;
                DB::table('users')->where('id', $user->id)->update(['referral_code' => $code]);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 12)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['referrer_id']);
            $table->dropUnique(['referral_code']);
            $table->dropColumn(['referral_code', 'referrer_id', 'referrer_type']);
        });
    }
};
