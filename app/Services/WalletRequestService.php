<?php
namespace App\Services;
use App\Models\{AdminAction, User, Wallet, WalletRequest, WalletTransaction};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletRequestService
{
    public function submit(User $user, string $type, string $amount, string $requestId, ?string $note = null): WalletRequest
    {
        abort_unless(config('portal.financial_operations_enabled'), 403, 'Wallet operations are temporarily paused.');
        abort_unless(in_array($type, ['deposit', 'withdraw'], true), 422, 'Invalid request type.');
        $cents = PortalMoney::cents($amount);
        $note = trim($note ?? '');
        $fingerprint = hash('sha256', $type.'|'.$cents.'|'.$note);
        app(InterestAccrualService::class)->accrue($user);
        return DB::transaction(function () use ($user, $type, $cents, $requestId, $note, $fingerprint) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $existing = WalletRequest::where('user_id', $user->id)->where('request_id', $requestId)->first();
            if ($existing) {
                if (!hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['request_id' => 'This reference was already used with different details.']);
                }
                return $existing;
            }
            if (WalletTransaction::where('user_id', $user->id)->where('request_id', $requestId)->exists()) {
                throw ValidationException::withMessages(['request_id' => 'This reference is already in use.']);
            }
            if ($type === 'withdraw') {
                if ($wallet->balance_cents < $cents) {
                    throw ValidationException::withMessages(['amount' => 'This amount exceeds your available balance.']);
                }
                $wallet->balance_cents -= $cents;
                $wallet->held_cents += $cents;
                $wallet->save();
            }
            return WalletRequest::create(['reference' => (string) Str::uuid(), 'user_id' => $user->id,
                'request_id' => $requestId, 'request_fingerprint' => $fingerprint, 'type' => $type,
                'amount_cents' => $cents, 'member_note' => $note ?: null, 'status' => 'pending']);
        }, 3);
    }

    public function review(User $actor, WalletRequest $request, string $decision, string $note): WalletRequest
    {
        abort_unless($actor->is_admin, 403, 'Administrator access is required.');
        abort_unless(config('portal.financial_operations_enabled'), 403, 'Wallet operations are temporarily paused.');
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422, 'Invalid decision.');
        return DB::transaction(function () use ($actor, $request, $decision, $note) {
            // All financial workflows acquire the wallet lock before dependent records.
            $wallet = Wallet::where('user_id', $request->user_id)->lockForUpdate()->firstOrFail();
            $request = WalletRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $status = $decision === 'approve' ? 'approved' : 'rejected';
            if ($request->status === $status) { return $request; }
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'This request has already been reviewed.']);
            }
            if ($request->type === 'withdraw') {
                if ($wallet->held_cents < $request->amount_cents) {
                    throw ValidationException::withMessages(['decision' => 'The reserved balance needs reconciliation before this request can be reviewed.']);
                }
                $wallet->held_cents -= $request->amount_cents;
                if ($decision === 'reject') { $wallet->balance_cents += $request->amount_cents; }
            } elseif ($decision === 'approve') {
                $wallet->balance_cents += $request->amount_cents;
            }
            $wallet->save();
            if ($decision === 'approve') {
                WalletTransaction::create(['reference' => (string) Str::uuid(), 'user_id' => $request->user_id,
                    'request_id' => $request->request_id, 'request_fingerprint' => $request->request_fingerprint,
                    'type' => $request->type, 'description' => $request->type === 'deposit' ? 'Deposit approved by admin' : 'Withdrawal approved by admin',
                    'amount_cents' => $request->amount_cents, 'balance_after_cents' => $wallet->balance_cents, 'status' => 'completed']);
            }
            $request->update(['status' => $status, 'review_note' => trim($note), 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            AdminAction::create(['reference' => (string) Str::uuid(), 'actor_id' => $actor->id, 'user_id' => $request->user_id,
                'wallet_request_id' => $request->id, 'request_id' => (string) Str::uuid(),
                'request_fingerprint' => hash('sha256', $request->reference.'|'.$status),
                'action' => $decision === 'reject' ? 'request_rejected' : ($request->type === 'deposit' ? 'deposit_approved' : 'withdrawal_approved'),
                'amount_cents' => $request->amount_cents, 'note' => trim($note), 'metadata' => ['type' => $request->type, 'request_reference' => $request->reference]]);
            return $request;
        }, 3);
    }
}
