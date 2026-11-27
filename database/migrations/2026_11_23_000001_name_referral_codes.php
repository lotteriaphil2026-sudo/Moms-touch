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
            $table->string('referral_code', 80)->nullable(false)->change();
        });

        $used = [];
        DB::table('users')->select('id', 'name')->orderBy('id')->get()->each(function ($user) use (&$used) {
            $base = preg_replace('/[^A-Z0-9]+/', '-', Str::upper(Str::ascii(trim((string) $user->name))));
            $base = trim((string) $base, '-');
            $base = $base !== '' ? substr($base, 0, 74) : 'MEMBER-'.$user->id;

            $code = $base;
            $suffix = 2;
            while (isset($used[$code]) || DB::table('users')->where('referral_code', $code)->where('id', '!=', $user->id)->exists()) {
                $tail = '-'.$suffix++;
                $code = substr($base, 0, 80 - strlen($tail)).$tail;
            }

            $used[$code] = true;
            DB::table('users')->where('id', $user->id)->update(['referral_code' => $code]);
        });
    }

    public function down(): void
    {
        // Name-based referral codes remain compatible with the 80-character column.
    }
};
