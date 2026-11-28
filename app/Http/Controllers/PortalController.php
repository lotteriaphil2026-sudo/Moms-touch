<?php
namespace App\Http\Controllers;
use App\Models\{FranchiseApplication, InvestmentAllocation, User, Wallet, WalletRequest, WalletTransaction, WithdrawalAccount};
use App\Services\{HomeScreenBonusService, InterestAccrualService, PortalMoney, WalletRequestService, WalletService, WebPushNotificationService};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class PortalController extends Controller
{
    public function state(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['csrf' => csrf_token(), 'user' => null, 'wallet' => ['balance' => 0, 'held' => 0, 'invested' => 0, 'earnings' => 0, 'referral_earnings' => 0, 'daily_earnings' => 0, 'unread_notifications' => 0], 'investments' => [], 'transactions' => [], 'wallet_requests' => [], 'withdrawal_accounts' => [], 'franchise_applications' => []]);
        }
        return response()->json($this->payloadForUser($user));
    }
    public function presence(Request $request): JsonResponse
    {
        $now = now();
        $user = $request->user();
        $wasOffline = ! $user->last_seen_at || $user->last_seen_at->lte($now->copy()->subMinutes(2));
        $updated = DB::table('users')
            ->where('id', $user->id)
            ->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<=', $now->copy()->subSeconds(30)))
            ->update(['last_seen_at' => $now]);

        if ($updated === 1 && $wasOffline && ! $user->is_admin && ! $user->is_dummy) {
            app(WebPushNotificationService::class)->notifyAdmins(
                'A member has come online. Open the member directory to see who is active.',
                '/#/admin?tab=members',
                'Member came online'
            );
        }

        return response()->json(['ok' => true]);
    }
    public function payloadForUser(User $user): array
    {
        app(InterestAccrualService::class)->accrue($user);
        $dailyEarnings = InvestmentAllocation::where('user_id', $user->id)->where('status', 'active')->get()->sum(fn ($i) => $i->dailyInterestCents());
        $wallet = Wallet::where('user_id', $user->id)->firstOrFail();
        $referrer = $user->referrer;
        $referrerData = $referrer
            ? ['type' => 'member', 'id' => $referrer->id, 'name' => $referrer->name]
            : ['type' => 'platform', 'name' => 'Mom’s Touch Platform'];
        return [
            'csrf' => csrf_token(),
            'user' => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone,
                'date_of_birth' => $user->date_of_birth?->format('Y-m-d'), 'address' => $user->address,
                'gender' => $user->gender, 'marital_status' => $user->marital_status,
                'is_admin' => $user->is_admin, 'is_dummy' => $user->is_dummy,
                'referral_preview_type' => $user->referral_preview_type,
                'created_at' => $user->created_at->toIso8601String(),
                'referral_code' => $user->referral_code, 'referrals_count' => $user->referrals()->count(),
                'referral_code_changed' => (bool) $user->referral_code_changed,
                'referrer' => $referrerData,
                'home_screen_bonus_claimed' => app(HomeScreenBonusService::class)->hasClaimed($user),
            ],
            'wallet' => [
                'balance' => $wallet->balance_cents, 'held' => $wallet->held_cents,
                'invested' => $wallet->invested_cents, 'earnings' => $wallet->earnings_cents,
                'referral_earnings' => WalletTransaction::where('user_id', $user->id)
                    ->where('type', 'referral_bonus')->sum('amount_cents'),
                'daily_earnings' => $dailyEarnings,
                'unread_notifications' => WalletTransaction::where('user_id', $user->id)->whereNull('read_at')->count()
                    + WalletRequest::where('user_id', $user->id)->whereNull('read_at')->count(),
            ],
            'wallet_requests' => WalletRequest::where('user_id', $user->id)->latest('id')->get()->map(fn ($r) => $r->publicData()),
            'withdrawal_accounts' => WithdrawalAccount::where('user_id', $user->id)->latest('id')->get()->map(fn ($account) => $account->publicData()),
            'investments' => InvestmentAllocation::where('user_id', $user->id)->latest('id')->get()->map(fn ($i) => [
                'allocation_id' => $i->id, 'id' => 'MT-'.strtoupper(substr($i->reference, 0, 8)), 'plan' => $i->plan,
                'name' => $i->plan_name ?? ucfirst($i->plan).' (legacy)', 'amount' => $i->amount_cents,
                'rate_bps' => $i->rate_basis_points, 'term_days' => $i->term_days, 'credited_days' => $i->credited_days,
                'started_at' => $i->started_at?->toIso8601String(), 'matures_at' => $i->matures_at?->toIso8601String(),
                'daily_amount' => $i->dailyInterestCents(), 'earned' => $i->dailyInterestCents() * $i->credited_days,
                'status' => $i->status, 'created_at' => $i->created_at->toIso8601String(),
            ]),
            'franchise_applications' => FranchiseApplication::where('user_id', $user->id)->latest('id')->get()->map(fn ($a) => [
                'reference' => $a->reference, 'contact_name' => $a->contact_name, 'contact_email' => $a->contact_email,
                'phone' => $a->phone, 'country' => $a->country, 'city' => $a->city, 'region' => $a->region,
                'site_status' => $a->site_status, 'budget_cents' => $a->budget_cents, 'target_opening' => $a->target_opening,
                'experience' => $a->experience, 'status' => $a->status, 'created_at' => $a->created_at->toIso8601String(),
            ]),
            'transactions' => WalletTransaction::where('user_id', $user->id)->latest('id')->get()->map(fn ($t) => [
                'id' => $t->reference, 'type' => $t->type, 'description' => $t->description,
                'amount' => $t->amount_cents, 'status' => $t->status, 'balance_after' => $t->balance_after_cents,
                'read_at' => $t->read_at?->toIso8601String(), 'created_at' => $t->created_at->toIso8601String(),
            ]),
        ];
    }

    public function markNotificationsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        app(InterestAccrualService::class)->accrue($user);
        $readAt = now();

        DB::transaction(function () use ($user, $readAt): void {
            WalletTransaction::where('user_id', $user->id)->whereNull('read_at')
                ->update(['read_at' => $readAt, 'updated_at' => $readAt]);
            WalletRequest::where('user_id', $user->id)->whereNull('read_at')
                ->update(['read_at' => $readAt, 'updated_at' => $readAt]);
        });

        return response()->json($this->payloadForUser($user));
    }

    public function transact(Request $request, WalletService $wallets, WalletRequestService $requests): JsonResponse
    {
        $input = $request->validate([
            'type' => ['required', Rule::in(['deposit', 'withdraw', 'invest'])],
            'amount' => ['required', 'string', 'max:17', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'deposit_plan' => ['nullable', 'required_if:type,deposit', 'prohibited_unless:type,deposit', Rule::in(array_keys(config('portal.plans')))],
            'plan' => ['nullable', 'required_if:type,invest', Rule::in(array_keys(config('portal.plans')))],
            'payment_method' => ['nullable', 'required_if:type,deposit', 'prohibited_unless:type,deposit', Rule::in(['landbank', 'bpi', 'security_bank', 'palawan_pay'])],
            'withdrawal_account_id' => ['nullable', 'required_if:type,withdraw', 'prohibited_unless:type,withdraw', 'integer', 'min:1'],
            'request_id' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
            'receipt' => [Rule::requiredIf(fn () => $request->input('type') === 'deposit' && ! $request->user()->is_dummy),
                'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'prohibited_unless:type,deposit'],
        ]);
        if ($input['type'] === 'invest') {
            $wallets->transact($request->user(), 'invest', $input['amount'], $input['plan'], $input['request_id']);
            $transaction = WalletTransaction::where('user_id', $request->user()->id)
                ->where('request_id', $input['request_id'])
                ->firstOrFail();

            return response()->json([
                ...$this->payloadForUser($request->user()),
                'completed_transaction' => [
                    'reference' => $transaction->reference,
                    'description' => $transaction->description,
                    'amount' => $transaction->amount_cents,
                    'status' => $transaction->status,
                    'created_at' => $transaction->created_at->toIso8601String(),
                ],
            ]);
        } elseif ($input['type'] === 'deposit') {
            $this->validateDepositPlanAmount($input['deposit_plan'], $input['amount']);
            $depositRequest = $requests->submit($request->user(), 'deposit', $input['amount'], $input['request_id'], $input['note'] ?? null, $input['payment_method'], null, $request->file('receipt'));
            return response()->json([
                ...$this->payloadForUser($request->user()),
                'submitted_request' => $depositRequest->publicData(),
            ]);
        } else {
            $withdrawalAccount = WithdrawalAccount::where('user_id', $request->user()->id)->find($input['withdrawal_account_id']);
            if (!$withdrawalAccount) {
                throw \Illuminate\Validation\ValidationException::withMessages(['withdrawal_account_id' => 'Choose one of your saved withdrawal accounts.']);
            }
            $requests->submit($request->user(), $input['type'], $input['amount'], $input['request_id'], $input['note'] ?? null, null, $withdrawalAccount);
        }
        return $this->state($request);
    }

    private function validateDepositPlanAmount(string $plan, string $amount): void
    {
        $plans = config('portal.plans');
        $planIds = array_keys($plans);
        $planIndex = array_search($plan, $planIds, true);
        $minimum = $plans[$plan]['minimum_cents'];
        $nextPlanId = $planIds[$planIndex + 1] ?? null;
        $maximum = $nextPlanId === null ? null : $plans[$nextPlanId]['minimum_cents'] - 1;
        $amountCents = PortalMoney::cents($amount, null);

        if ($amountCents < $minimum || ($maximum !== null && $amountCents > $maximum)) {
            $name = str_replace('Mom’s ', '', $plans[$plan]['name']);
            $minimumText = '₱'.number_format($minimum / 100, 2, '.', ',');
            $range = $maximum === null
                ? 'at least '.$minimumText
                : 'between '.$minimumText.' and ₱'.number_format($maximum / 100, 2, '.', ',');

            throw \Illuminate\Validation\ValidationException::withMessages([
                'amount' => $name.' deposits must be '.$range.'.',
            ]);
        }
    }

    public function profile(Request $request): JsonResponse
    {
        $submittedReferralCode = trim((string) $request->input('referral_code'));
        $normalizedReferralCode = strtoupper(preg_replace('/[\s-]+/', '-', $submittedReferralCode));
        $request->merge(['referral_code' => $normalizedReferralCode]);

        $input = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'referral_code' => [
                'nullable',
                'string',
                'max:80',
                'regex:/\A[A-Z0-9][A-Z0-9-]*[A-Z0-9]\z/',
                \Illuminate\Validation\Rule::unique('users', 'referral_code')->ignore($request->user()->id),
            ],
        ]);
        $updates = ['name' => trim($input['name'])];
        if (!empty($input['referral_code'])) {
            $updatedReferralCode = strtoupper(trim($input['referral_code']));
            $updates['referral_code'] = $updatedReferralCode;
            if ($updatedReferralCode !== $request->user()->referral_code) {
                $updates['referral_code_changed'] = true;
            }
        }
        $request->user()->forceFill($updates);
        $request->user()->save();
        return $this->state($request);
    }
}
