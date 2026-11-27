<?php
namespace App\Services;
use App\Models\{AdminAction, InvestmentAllocation, User, Wallet, WalletRequest, WalletTransaction};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletAdministrationService
{
    public function send(User $actor, User $member, string $action, string $amount, ?string $plan, string $note, string $requestId): ?bool
    {
        abort_unless($actor->is_admin, 403, 'Administrator access is required.');
        abort_unless(config('portal.financial_operations_enabled'), 403, 'Wallet operations are temporarily paused.');
        abort_if($member->is_admin, 422, 'Choose a member account as the recipient.');
        abort_unless(in_array($action, ['funds_sent', 'plan_assigned'], true), 422, 'Invalid admin action.');
        $cents = PortalMoney::cents($amount);
        $details = $action === 'plan_assigned' ? config('portal.plans.'.$plan) : null;
        if ($action === 'plan_assigned' && (!$details || $cents < $details['minimum_cents'])) {
            throw ValidationException::withMessages(['amount' => 'Enter at least the selected plan’s minimum amount.']);
        }
        $note = trim($note);
        $fingerprint = hash('sha256', $action.'|'.$member->id.'|'.$cents.'|'.($details ? $plan : '').'|'.$note);
        app(InterestAccrualService::class)->accrue($member);
        $created = DB::transaction(function () use ($actor, $member, $action, $cents, $details, $plan, $note, $requestId, $fingerprint) {
            $wallet = Wallet::where('user_id', $member->id)->lockForUpdate()->firstOrFail();
            $existing = AdminAction::where('actor_id', $actor->id)->where('request_id', $requestId)->first();
            if ($existing) {
                if (!hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['request_id' => 'This admin reference was already used with different details.']);
                }
                return false;
            }
            if (WalletRequest::where('user_id', $member->id)->where('request_id', $requestId)->exists()) {
                throw ValidationException::withMessages(['request_id' => 'This reference is already in use.']);
            }
            if ($details) {
                $at = now()->toImmutable();
                $allocation = InvestmentAllocation::create(['reference' => (string) Str::uuid(), 'user_id' => $member->id,
                    'plan' => $plan, 'plan_name' => $details['name'], 'amount_cents' => $cents, 'status' => 'active',
                    'rate_basis_points' => $details['daily_rate_bps'],
                    'daily_interest_rate' => sprintf('%d.%04d00', intdiv($details['daily_rate_bps'], 10000), $details['daily_rate_bps'] % 10000),
                    'term_days' => $details['term_days'], 'credited_days' => 0, 'started_at' => $at, 'matures_at' => $at->addDays($details['term_days'])]);
                $wallet->invested_cents += $cents;
            } else {
                $wallet->balance_cents += $cents;
            }
            $wallet->save();
            WalletTransaction::create(['reference' => (string) Str::uuid(), 'user_id' => $member->id,
                'request_id' => $requestId, 'request_fingerprint' => $fingerprint, 'type' => $details ? 'plan_grant' : 'deposit',
                'description' => $details ? $details['name'].' assigned by admin' : 'Funds sent by admin',
                'amount_cents' => $cents, 'balance_after_cents' => $wallet->balance_cents, 'status' => 'completed']);
            AdminAction::create(['reference' => (string) Str::uuid(), 'actor_id' => $actor->id, 'user_id' => $member->id,
                'request_id' => $requestId, 'request_fingerprint' => $fingerprint, 'action' => $action,
                'amount_cents' => $cents, 'note' => $note, 'metadata' => $details ? ['plan' => $plan,
                    'plan_name' => $details['name'], 'rate_bps' => $details['daily_rate_bps'], 'term_days' => $details['term_days'], 'allocation_reference' => $allocation->reference] : []]);
            return true;
        }, 3);

        if (! $created) {
            return null;
        }

        $message = $details
            ? 'Your '.$details['name'].' plan has been activated on your account. Open Investments to view its terms and daily interest.'
            : 'Funds were credited to your wallet.';

        $notifications = app(WebPushNotificationService::class);
        $notifications->notify(
            $actor->id,
            $details
                ? 'A package was assigned to a member. Open the activity log to review the action.'
                : 'Funds were sent to a member. Open the activity log to review the action.',
            '/#/admin?tab=audit',
            $details ? 'Package assigned to member' : 'Funds sent to member'
        );

        return $notifications->notify(
            $member->id,
            $message,
            $details ? '/#/investments' : '/#/wallet',
            $details ? 'Your investment plan is active' : 'Mom’s Touch account update'
        );
    }
}
