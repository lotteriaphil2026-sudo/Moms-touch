<?php
namespace App\Http\Controllers;
use App\Models\{AdminAction, MemberSignIn, User, Wallet, WalletRequest};
use App\Services\{WalletAdministrationService, WalletRequestService};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        $query = User::query()->with('referrer:id,name,email')->select(['id', 'name', 'email', 'phone', 'referrer_id', 'is_admin', 'is_dummy', 'admin_label', 'referral_preview_type', 'created_at']);
        if ($term = trim($input['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%'));
        }
        $realTotal = (clone $query)->where('is_dummy', false)->count();
        $page = $query->latest('id')->paginate(25);
        $wallets = Wallet::whereIn('user_id', $page->getCollection()->pluck('id'))->get()->keyBy('user_id');
        return response()->json(['items' => $page->getCollection()->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'is_admin' => $u->is_admin, 'is_dummy' => $u->is_dummy,
            'admin_label' => $u->admin_label,
            'referral_preview_type' => $u->referral_preview_type,
            'referrer' => $u->referrer ? ['id' => $u->referrer->id, 'name' => $u->referrer->name, 'email' => $u->referrer->email] : null,
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
    public function assignReferralPreview(Request $request, User $user): JsonResponse
    {
        abort_unless(! $user->is_admin, 422, 'Administrator accounts cannot receive member referral links.');
        $input = $request->validate(['preview_type' => ['required', Rule::in(['standard', 'special'])]]);

        $changed = DB::transaction(function () use ($request, $user, $input): bool {
            $member = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless(! $member->is_admin, 422, 'Administrator accounts cannot receive member referral links.');
            if ($member->referral_preview_type === $input['preview_type']) {
                return false;
            }

            $member->forceFill(['referral_preview_type' => $input['preview_type']])->save();
            AdminAction::create([
                'reference' => (string) Str::uuid(),
                'actor_id' => $request->user()->id,
                'user_id' => $member->id,
                'request_id' => (string) Str::uuid(),
                'request_fingerprint' => hash('sha256', 'referral-preview|'.$member->id.'|'.$input['preview_type']),
                'action' => 'referral_preview_assigned',
                'amount_cents' => 0,
                'note' => 'Assigned the '.($input['preview_type'] === 'special' ? 'special seminar' : 'standard').' referral preview.',
                'metadata' => ['preview_type' => $input['preview_type']],
            ]);

            return true;
        });

        return response()->json([
            'message' => $changed
                ? ($input['preview_type'] === 'special' ? 'Special preview link assigned.' : 'Standard referral preview assigned.')
                : 'This member already uses that referral preview.',
            'referral_preview_type' => $input['preview_type'],
        ]);
    }
    public function updateMemberLabel(Request $request, User $user): JsonResponse
    {
        abort_unless(! $user->is_admin, 422, 'Administrator accounts cannot receive member labels.');
        $input = $request->validate(['label' => ['present', 'nullable', 'string', 'max:80']]);
        $label = isset($input['label']) ? trim($input['label']) : null;
        $label = $label === '' ? null : $label;

        $changed = DB::transaction(function () use ($request, $user, $label): bool {
            $member = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless(! $member->is_admin, 422, 'Administrator accounts cannot receive member labels.');
            if ($member->admin_label === $label) {
                return false;
            }

            $member->forceFill(['admin_label' => $label])->save();
            AdminAction::create([
                'reference' => (string) Str::uuid(),
                'actor_id' => $request->user()->id,
                'user_id' => $member->id,
                'request_id' => (string) Str::uuid(),
                'request_fingerprint' => hash('sha256', 'member-label|'.$member->id.'|'.($label ?? '')),
                'action' => 'member_label_updated',
                'amount_cents' => 0,
                'note' => $label === null ? 'Removed member label.' : 'Updated member label.',
                'metadata' => ['label' => $label],
            ]);

            return true;
        });

        return response()->json([
            'message' => $changed ? ($label === null ? 'Member label removed.' : 'Member label updated.') : 'Member label is unchanged.',
            'admin_label' => $label,
        ]);
    }
    public function user(User $user, PortalController $portal): JsonResponse
    {
        $payload = $portal->payloadForUser($user);
        $payload['user']['is_dummy'] = $user->is_dummy;
        $payload['user']['admin_label'] = $user->admin_label;
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
    public function receiptLogs(Request $request): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $page = WalletRequest::with('user:id,name,email')
            ->where('type', 'deposit')
            ->whereNotNull('receipt_path')
            ->latest('id')
            ->paginate(25);

        return response()->json([
            'items' => $page->getCollection()->map(fn (WalletRequest $r) => [
                'id' => $r->id,
                'reference' => $r->reference,
                'amount' => $r->amount_cents,
                'payment_method' => $r->payment_method,
                'status' => $r->status,
                'test_only' => (bool) $r->test_only,
                'created_at' => $r->created_at->toIso8601String(),
                'receipt_url' => url('/portal/admin/requests/'.$r->id.'/receipt'),
                'user' => $r->user?->only(['id', 'name', 'email']),
            ]),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }
    public function receipt(WalletRequest $walletRequest)
    {
        abort_unless($walletRequest->type === 'deposit' && $walletRequest->receipt_path, 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($walletRequest->receipt_path), 404);

        return $disk->response(
            $walletRequest->receipt_path,
            'deposit-receipt-'.$walletRequest->reference.'.'.pathinfo($walletRequest->receipt_path, PATHINFO_EXTENSION),
            ['Cache-Control' => 'private, no-store, max-age=0'],
            'inline'
        );
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
