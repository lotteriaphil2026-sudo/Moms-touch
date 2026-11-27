<?php
namespace App\Services;
use App\Models\{InvestmentAllocation, User, Wallet, WalletTransaction};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class InterestAccrualService
{
    public function accrue(User $user, ?CarbonImmutable $asOf = null): int
    {
        if (!config('portal.financial_operations_enabled')) { return 0; }
        $asOf ??= CarbonImmutable::now();
        $credited = DB::transaction(function () use ($user, $asOf) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
            if (!$wallet) { return 0; }
            $credited = 0;
            $allocations = InvestmentAllocation::where('user_id', $user->id)->where('status', 'active')
                ->whereNotNull('started_at')->whereNotNull('term_days')->whereNotNull('rate_basis_points')
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($allocations as $allocation) {
                $elapsed = max(0, $asOf->getTimestamp() - $allocation->started_at->getTimestamp());
                $completedDays = min($allocation->term_days, intdiv($elapsed, 86400));
                $dueDays = max(0, $completedDays - $allocation->credited_days);
                if ($dueDays > 0) {
                    $amount = $allocation->dailyInterestCents() * $dueDays;
                    $firstDay = $allocation->credited_days + 1;
                    $wallet->balance_cents += $amount;
                    $wallet->earnings_cents += $amount;
                    WalletTransaction::create([
                        'reference' => (string) Str::uuid(), 'user_id' => $user->id,
                        'request_id' => (string) Str::uuid(),
                        'request_fingerprint' => hash('sha256', 'interest|'.$allocation->id.'|'.$completedDays),
                        'type' => 'interest',
                        'description' => $allocation->plan_name.' interest · days '.$firstDay.'–'.$completedDays,
                        'amount_cents' => $amount, 'balance_after_cents' => $wallet->balance_cents, 'status' => 'completed',
                    ]);
                    $allocation->credited_days = $completedDays;
                    $credited += $amount;
                }
                if ($completedDays >= $allocation->term_days) { $allocation->status = 'completed'; }
                $allocation->save();
            }
            $wallet->save();
            return $credited;
        }, 3);

        if ($credited > 0) {
            app(WebPushNotificationService::class)->notify(
                $user->id,
                'Daily interest was credited to your wallet. Sign in to view the details.'
            );
        }

        return $credited;
    }
}
