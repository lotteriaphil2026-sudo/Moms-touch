<?php
namespace App\Http\Controllers;
use App\Models\{AdminAction, MemberSignIn, User, Wallet, WalletRequest};
use App\Services\{WalletAdministrationService, WalletRequestService};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function overview(): JsonResponse
    {
        return response()->json(['stats' => [
            'members' => User::where('is_admin', false)->count(),
            'pending_deposits' => WalletRequest::where('status', 'pending')->where('type', 'deposit')->count(),
            'pending_withdrawals' => WalletRequest::where('status', 'pending')->where('type', 'withdraw')->count(),
            'wallet_total' => (int) Wallet::sum('balance_cents'), 'held_total' => (int) Wallet::sum('held_cents'),
            'invested_total' => (int) Wallet::sum('invested_cents'),
        ], 'requests' => WalletRequest::with('user:id,name,email')->where('status', 'pending')->oldest('id')->limit(8)->get()->map(fn ($r) => $this->requestData($r)),
            'financial_operations_enabled' => (bool) config('portal.financial_operations_enabled')]);
    }
    public function users(Request $request): JsonResponse
    {
        $input = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = User::query()->select(['id', 'name', 'email', 'phone', 'is_admin', 'created_at']);
        if ($term = trim($input['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%'));
        }
        $page = $query->latest('id')->paginate(25);
        $wallets = Wallet::whereIn('user_id', $page->getCollection()->pluck('id'))->get()->keyBy('user_id');
        return response()->json(['items' => $page->getCollection()->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'is_admin' => $u->is_admin,
            'created_at' => $u->created_at->toIso8601String(), 'balance' => $wallets->get($u->id)?->balance_cents ?? 0,
            'invested' => $wallets->get($u->id)?->invested_cents ?? 0, 'held' => $wallets->get($u->id)?->held_cents ?? 0,
        ]), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }
    public function user(User $user, PortalController $portal): JsonResponse
    {
        $payload = $portal->payloadForUser($user);
        $payload['wallet_requests'] = WalletRequest::where('user_id', $user->id)
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
        $query = WalletRequest::with('user:id,name,email');
        if (($input['status'] ?? 'pending') !== 'all') { $query->where('status', $input['status'] ?? 'pending'); }
        if (($input['type'] ?? 'all') !== 'all') { $query->where('type', $input['type']); }
        $page = $query->latest('id')->paginate(25);
        return response()->json(['items' => $page->getCollection()->map(fn ($r) => $this->requestData($r)),
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
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
        $wallets->send($request->user(), $user, 'funds_sent', $input['amount'], null, $input['note'], $input['request_id']);
        return response()->json(['message' => 'Wallet credited.', 'member' => $portal->payloadForUser($user)]);
    }
    public function plans(Request $request, User $user, WalletAdministrationService $wallets, PortalController $portal): JsonResponse
    {
        $input = $this->validateSend($request, true);
        $wallets->send($request->user(), $user, 'plan_assigned', $input['amount'], $input['plan'], $input['note'], $input['request_id']);
        return response()->json(['message' => 'Plan assigned.', 'member' => $portal->payloadForUser($user)]);
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
