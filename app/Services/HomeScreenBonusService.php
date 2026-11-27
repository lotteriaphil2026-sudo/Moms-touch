<?php

namespace App\Services;

use App\Models\{User, Wallet, WalletTransaction};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HomeScreenBonusService
{
    public const PROMOTION_KEY = 'home-screen-200';
    public const AMOUNT_CENTS = 20000;

    public function hasClaimed(User $user): bool
    {
        return DB::table('promotion_claims')
            ->where('user_id', $user->id)
            ->where('promotion_key', self::PROMOTION_KEY)
            ->exists();
    }

    public function claim(User $user): void
    {
        abort_unless(config('portal.financial_operations_enabled'), 403, 'Wallet credits are temporarily paused.');

        DB::transaction(function () use ($user): void {
            $wallet = Wallet::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            abort_if($this->hasClaimed($user), 409, 'You have already claimed this Home Screen bonus.');

            $wallet->balance_cents += self::AMOUNT_CENTS;
            $wallet->save();

            $transaction = WalletTransaction::create([
                'reference' => (string) Str::uuid(),
                'user_id' => $user->id,
                'request_id' => (string) Str::uuid(),
                'request_fingerprint' => hash('sha256', self::PROMOTION_KEY.'|'.$user->id),
                'type' => 'promotion_bonus',
                'description' => '₱200 Home Screen promotion bonus',
                'amount_cents' => self::AMOUNT_CENTS,
                'balance_after_cents' => $wallet->balance_cents,
                'status' => 'completed',
            ]);

            DB::table('promotion_claims')->insert([
                'user_id' => $user->id,
                'promotion_key' => self::PROMOTION_KEY,
                'wallet_transaction_id' => $transaction->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }
}
