<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            $demoRequests = DB::table('wallet_requests')
                ->select('id')
                ->where('type', 'deposit')
                ->where('test_only', true);

            DB::table('admin_actions')
                ->whereIn('wallet_request_id', $demoRequests)
                ->update(['wallet_request_id' => null]);

            DB::table('wallet_requests')
                ->where('type', 'deposit')
                ->where('test_only', true)
                ->delete();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Deleted demo deposit requests cannot be restored.');
    }
};
