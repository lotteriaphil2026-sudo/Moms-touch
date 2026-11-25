<?php
namespace App\Http\Controllers;
use App\Models\{AdminAction, MemberSignIn, User, Wallet, WalletRequest};
use App\Services\{WalletAdministrationService, WalletRequestService};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function overview(): JsonResponse
    {
        $memberWallets = fn () => Wallet::query()->whereIn('user_id', User::query()->where('is_dummy', false)->select('id'));
        $memberRequests = fn () => WalletRequest::query()->whereHas('user', fn ($query) => $query->where('is_dummy', false));

        return response()->json(['stats' => [
            'members' => User::where('is_admin', false)->where('is_dummy', false)->count(),
            'pending_deposits' => $memberRequests()->where('status', 'pending')->where('type', 'deposit')->where('test_only', false)->count(),
            'pending_withdrawals' => $memberRequests()->where('status', 'pending')->where('type', 'withdraw')->where('test_only', false)->count(),
            'wallet_total' => (int) $memberWallets()->sum('balance_cents'), 'held_total' => (int) $memberWallets()->sum('held_cents'),
            'invested_total' => (int) $memberWallets()->sum('invested_cents'),
        ], 'requests' => $memberRequests()->with('user:id,name,email')->where('status', 'pending')->where('test_only', false)->oldest('id')->limit(8)->get()->map(fn ($r) => $this->requestData($r)),
            'financial_operations_enabled' => (bool) config('portal.financial_operations_enabled')]);
    }
    public function users(Request $request): JsonResponse
    {
        $input = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = User::query()->select(['id', 'name', 'email', 'phone', 'is_admin', 'is_dummy', 'created_at']);
        if ($term = trim($input['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%'));
        }
        $realTotal = (clone $query)->where('is_dummy', false)->count();
        $page = $query->latest('id')->paginate(25);
        $wallets = Wallet::whereIn('user_id', $page->getCollection()->pluck('id'))->get()->keyBy('user_id');
        return response()->json(['items' => $page->getCollection()->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'is_admin' => $u->is_admin, 'is_dummy' => $u->is_dummy,
            'created_at' => $u->created_at->toIso8601String(), 'balance' => $wallets->get($u->id)?->balance_cents ?? 0,
            'invested' => $wallets->get($u->id)?->invested_cents ?? 0, 'held' => $wallets->get($u->id)?->held_cents ?? 0,
        ]), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'real_total' => $realTotal]);
    }
    public function classifyUser(Request $request, User $user): JsonResponse
    {
        $configuredKey = config('portal.admin_member_classification_key');
        abort_unless(is_string($configuredKey) && strlen($configuredKey) >= 32, 503, 'Admin member classification is not configured.');

        $input = $request->validate([
            'classification' => ['required', Rule::in(['real', 'dummy'])],
            'secret_key' => ['required', 'string', 'max:256'],
            'request_id' => ['required', 'uuid'],
        ]);
        abort_unless(hash_equals($configuredKey, $input['secret_key']), 403, 'The admin secret key is incorrect.');
        abort_unless(! $user->is_admin, 422, 'Administrator accounts cannot be classified as members.');

        $isDummy = $input['classification'] === 'dummy';
        DB::transaction(function () use ($request, $user, $input, $isDummy): void {
            $member = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless(! $member->is_admin, 422, 'Administrator accounts cannot be classified as members.');

            if ($member->is_dummy === $isDummy) {
                return;
            }

            $member->forceFill(['is_dummy' => $isDummy])->save();
            AdminAction::create([
                'reference' => (string) Str::uuid(),
                'actor_id' => $request->user()->id,
                'user_id' => $member->id,
                'request_id' => $input['request_id'],
                'request_fingerprint' => hash('sha256', 'member-classification|'.$member->id.'|'.($isDummy ? 'dummy' : 'real')),
                'action' => 'member_classified',
                'amount_cents' => 0,
                'note' => 'Classified member as '.($isDummy ? 'dummy' : 'real').'.',
                'metadata' => ['classification' => $isDummy ? 'dummy' : 'real'],
            ]);
        });

        return response()->json([
            'message' => 'Member classification updated.',
            'classification' => $isDummy ? 'dummy' : 'real',
            'is_dummy' => $isDummy,
        ]);
    }
    public function user(User $user, PortalController $portal): JsonResponse
    {
        $payload = $portal->payloadForUser($user);
        $payload['user']['is_dummy'] = $user->is_dummy;
        $payload['wallet_requests'] = WalletRequest::where('user_id', $user->id)
            ->where('test_only', false)
            ->latest('id')
            ->get()
            ->map(fn (WalletRequest $walletRequest) => $walletRequest->adminData());

        return response()->json([
            ...$payload,
            'sign_in_history' => MemberSignIn::where('user_id', $user->id)
                ->latest('created_at')
                ->limit(20)
                ->get(['ip_address', 'user_agent', 'country', 'region', 'city', 'created_at']),
        ]);
    }
    public function requests(Request $request): JsonResponse
    {
        $input = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'all'])],
            'type' => ['nullable', Rule::in(['deposit', 'withdraw', 'all'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = WalletRequest::with('user:id,name,email')->where('test_only', false);
        if (($input['status'] ?? 'pending') !== 'all') { $query->where('status', $input['status'] ?? 'pending'); }
        if (($input['type'] ?? 'all') !== 'all') { $query->where('type', $input['type']); }
        $page = $query->latest('id')->paginate(25);
        return response()->json(['items' => $page->getCollection()->map(fn ($r) => $this->requestData($r)),
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }
    public function depositUsers(Request $request): JsonResponse
    {
        $input = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'all'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = WalletRequest::query()->where('type', 'deposit')->where('test_only', false);
        if (($input['status'] ?? 'pending') !== 'all') {
            $query->where('status', $input['status'] ?? 'pending');
        }
        $page = $query
            ->select('user_id')
            ->selectRaw('COUNT(*) as request_count, SUM(amount_cents) as total_amount, MAX(created_at) as latest_at')
            ->groupBy('user_id')
            ->orderByDesc('latest_at')
            ->orderByDesc('user_id')
            ->paginate(25);
        $users = User::query()
            ->whereIn('id', $page->getCollection()->pluck('user_id'))
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        return response()->json([
            'items' => $page->getCollection()->map(function ($group) use ($users) {
                $user = $users->get($group->user_id);
                if (! $user) {
                    return null;
                }

                return [
                    'user' => $user->only(['id', 'name', 'email']),
                    'request_count' => (int) $group->request_count,
                    'total_amount' => (int) $group->total_amount,
                    'latest_at' => Carbon::parse($group->latest_at)->toIso8601String(),
                ];
            })->filter()->values(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }
    public function review(Request $request, WalletRequest $walletRequest, WalletRequestService $requests): JsonResponse
    {
        $input = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['required', 'string', 'min:3', 'max:500']]);
        $record = $requests->review($request->user(), $walletRequest, $input['decision'], $input['note']);
        return response()->json(['message' => 'Request '.$record->status.'.', 'request' => $record->publicData()]);
    }
    public function funds(Request $request, User $user, WalletAdministrationService $wallets, PortalController $portal): JsonResponse
    {
        $input = $this->validateSend($request);
        $notificationQueued = $wallets->send($request->user(), $user, 'funds_sent', $input['amount'], null, $input['note'], $input['request_id']);
        return response()->json(['message' => 'Wallet credited.', 'notification_queued' => $notificationQueued, 'member' => $portal->payloadForUser($user)]);
    }
    public function plans(Request $request, User $user, WalletAdministrationService $wallets, PortalController $portal): JsonResponse
    {
        $input = $this->validateSend($request, true);
        $notificationQueued = $wallets->send($request->user(), $user, 'plan_assigned', $input['amount'], $input['plan'], $input['note'], $input['request_id']);
        return response()->json(['message' => 'Plan assigned.', 'notification_queued' => $notificationQueued, 'member' => $portal->payloadForUser($user)]);
    }
    public function audit(Request $request): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $page = AdminAction::with(['actor:id,name,email', 'user:id,name,email'])->latest('id')->paginate(25);
        return response()->json(['items' => $page->getCollection()->map(fn ($a) => [
            'reference' => $a->reference, 'action' => $a->action, 'amount' => $a->amount_cents,
            'note' => $a->note, 'metadata' => $a->metadata, 'created_at' => $a->created_at->toIso8601String(),
            'actor' => $a->actor?->only(['id', 'name', 'email']), 'user' => $a->user?->only(['id', 'name', 'email']),
        ]), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }
    private function validateSend(Request $request, bool $plan = false): array
    {
        return $request->validate(['amount' => ['required', 'string', 'max:12', 'regex:/\A\d{1,7}(?:\.\d{1,2})?\z/'],
            'note' => ['required', 'string', 'min:3', 'max:500'], 'request_id' => ['required', 'uuid'],
            ...($plan ? ['plan' => ['required', Rule::in(array_keys(config('portal.plans')))]] : [])]);
    }
    private function requestData(WalletRequest $r): array
    {
        return [...$r->adminData(), 'user' => $r->user?->only(['id', 'name', 'email'])];
    }
}
