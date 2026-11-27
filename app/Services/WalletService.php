<?php
namespace App\Services;
use App\Models\{InvestmentAllocation, User, Wallet, WalletRequest, WalletTransaction};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class WalletService
{
    public function transact(User $user, string $type, string $amount, ?string $plan, string $requestId): void
    {
        abort_unless(config('portal.financial_operations_enabled'), 403, 'Wallet operations are temporarily paused.');
        app(InterestAccrualService::class)->accrue($user);
        // All money is represented as integer centavos; no floating-point arithmetic.
        [$pesos, $centavos] = array_pad(explode('.', $amount, 2), 2, '');
        $cents = ((int) $pesos * 100) + (int) str_pad($centavos, 2, '0');
        if ($cents < 10000 || $cents > 100000000) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount between ₱100 and ₱1,000,000.']);
        }
        $planDetails = config('portal.plans.'.$plan);
        if ($type === 'invest' && (!$planDetails || $cents < $planDetails['minimum_cents'])) {
            throw ValidationException::withMessages(['amount' => 'This amount is below the plan’s minimum allocation.']);
        }
        $fingerprint = hash('sha256', $type.'|'.$cents.'|'.($type === 'invest' ? $plan : ''));
        $created = DB::transaction(function () use ($user, $type, $cents, $plan, $planDetails, $requestId, $fingerprint) {
            // Every registered account gets exactly one wallet at account creation.
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $existing = WalletTransaction::where('user_id', $user->id)->where('request_id', $requestId)->first();
            if ($existing) {
                if (!hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['request_id' => 'This transaction reference has already been used with different details.']);
                }
                return false;
            }
            if (WalletRequest::where('user_id', $user->id)->where('request_id', $requestId)->exists()) {
                throw ValidationException::withMessages(['request_id' => 'This reference is already in use.']);
            }
            if ($type !== 'deposit' && $wallet->balance_cents < $cents) {
                throw ValidationException::withMessages(['amount' => 'This amount is more than your available wallet balance.']);
            }
            if ($type === 'deposit') {
                $wallet->balance_cents += $cents;
            } else {
                $wallet->balance_cents -= $cents;
            }
            if ($type === 'invest') {
                $wallet->invested_cents += $cents;
                $startedAt = now()->toImmutable();
                InvestmentAllocation::create([
                    'reference' => (string) Str::uuid(), 'user_id' => $user->id,
                    'plan' => $plan, 'amount_cents' => $cents,
                    'plan_name' => $planDetails['name'],
                    'status' => 'active',
                    'rate_basis_points' => $planDetails['daily_rate_bps'],
                    'daily_interest_rate' => sprintf('%d.%04d00', intdiv($planDetails['daily_rate_bps'], 10000), $planDetails['daily_rate_bps'] % 10000),
                    'term_days' => $planDetails['term_days'], 'credited_days' => 0,
                    'started_at' => $startedAt, 'matures_at' => $startedAt->addDays($planDetails['term_days']),
                ]);
            }
            $wallet->save();
            WalletTransaction::create([
                'reference' => (string) Str::uuid(), 'user_id' => $user->id,
                'request_id' => $requestId, 'request_fingerprint' => $fingerprint,
                'type' => $type,
                'description' => match ($type) {
                    'deposit' => 'Wallet credit', 'withdraw' => 'Wallet debit',
                    default => $planDetails['name'].' allocation',
                },
                'amount_cents' => $cents, 'balance_after_cents' => $wallet->balance_cents,
                'status' => 'completed',
            ]);

            if ($type === 'invest' && $user->referrer_id) {
                $referralBonusCents = intdiv(
                    $cents * config('portal.referral_incentive_basis_points') + 5000,
                    10000
                );

                if ($referralBonusCents > 0) {
                    $referrerWallet = Wallet::where('user_id', $user->referrer_id)->lockForUpdate()->firstOrFail();
                    $referrerWallet->balance_cents += $referralBonusCents;
                    $referrerWallet->save();

                    WalletTransaction::create([
                        'reference' => (string) Str::uuid(),
                        'user_id' => $user->referrer_id,
                        'request_id' => (string) Str::uuid(),
                        'request_fingerprint' => hash('sha256', 'referral_bonus|'.$user->id.'|'.$requestId),
                        'type' => 'referral_bonus',
                        'description' => '5% referral incentive · '.$planDetails['name'].' allocation',
                        'amount_cents' => $referralBonusCents,
                        'balance_after_cents' => $referrerWallet->balance_cents,
                        'status' => 'completed',
                    ]);
                }
            }

            return true;
        }, 3);

        if ($created) {
            $message = match ($type) {
                'deposit' => 'Funds were credited to your wallet.',
                'withdraw' => 'A wallet withdrawal was completed. Sign in to view the details.',
                default => 'Your investment was created. Sign in to view the plan details.',
            };
            app(WebPushNotificationService::class)->notify($user->id, $message);

            if ($type === 'invest' && $user->referrer_id) {
                app(WebPushNotificationService::class)->notify(
                    $user->referrer_id,
                    'A 5% referral incentive was credited to your wallet.'
                );
            }
        }
    }
}
