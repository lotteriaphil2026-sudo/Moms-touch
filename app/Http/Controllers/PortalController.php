<?php
namespace App\Http\Controllers;
use App\Models\{FranchiseApplication, InvestmentAllocation, User, Wallet, WalletRequest, WalletTransaction};
use App\Services\{InterestAccrualService, WalletRequestService, WalletService};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;
class PortalController extends Controller
{
    public function state(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['csrf' => csrf_token(), 'user' => null, 'wallet' => ['balance' => 0, 'held' => 0, 'invested' => 0, 'earnings' => 0, 'daily_earnings' => 0], 'investments' => [], 'transactions' => [], 'wallet_requests' => [], 'franchise_applications' => []]);
        }
        return response()->json($this->payloadForUser($user));
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
                'is_admin' => $user->is_admin, 'created_at' => $user->created_at->toIso8601String(),
                'referral_code' => $user->referral_code, 'referrals_count' => $user->referrals()->count(),
                'referrer' => $referrerData,
            ],
            'wallet' => ['balance' => $wallet->balance_cents, 'held' => $wallet->held_cents, 'invested' => $wallet->invested_cents, 'earnings' => $wallet->earnings_cents, 'daily_earnings' => $dailyEarnings],
            'wallet_requests' => WalletRequest::where('user_id', $user->id)->latest('id')->get()->map(fn ($r) => $r->publicData()),
            'investments' => InvestmentAllocation::where('user_id', $user->id)->latest('id')->get()->map(fn ($i) => [
                'id' => 'MT-'.strtoupper(substr($i->reference, 0, 8)), 'plan' => $i->plan,
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
                'amount' => $t->amount_cents, 'status' => $t->status, 'balance_after' => $t->balance_after_cents, 'created_at' => $t->created_at->toIso8601String(),
            ]),
        ];
    }
    public function transact(Request $request, WalletService $wallets, WalletRequestService $requests): JsonResponse
    {
        $input = $request->validate([
            'type' => ['required', Rule::in(['deposit', 'withdraw', 'invest'])],
            'amount' => ['required', 'string', 'max:12', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'plan' => ['nullable', 'required_if:type,invest', Rule::in(array_keys(config('portal.plans')))],
            'payment_method' => ['nullable', 'required_if:type,deposit', 'prohibited_unless:type,deposit', Rule::in(['landbank', 'bpi', 'security_bank'])],
            'request_id' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        if ($input['type'] === 'invest') {
            $wallets->transact($request->user(), 'invest', $input['amount'], $input['plan'], $input['request_id']);
        } elseif ($input['type'] === 'deposit') {
            $depositRequest = $requests->submit($request->user(), 'deposit', $input['amount'], $input['request_id'], $input['note'] ?? null, $input['payment_method']);
            return response()->json([
                ...$this->payloadForUser($request->user()),
                'submitted_request' => $depositRequest->publicData(),
            ]);
        } else {
            $requests->submit($request->user(), $input['type'], $input['amount'], $input['request_id'], $input['note'] ?? null);
        }
        return $this->state($request);
    }
    public function profile(Request $request): JsonResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $request->user()->update(['name' => $input['name']]);
        return $this->state($request);
    }
}
