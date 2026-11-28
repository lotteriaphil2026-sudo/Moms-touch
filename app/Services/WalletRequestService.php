<?php
namespace App\Services;
use App\Models\{AdminAction, User, Wallet, WalletRequest, WalletTransaction};
use App\Models\WithdrawalAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class WalletRequestService
{
    public function submit(User $user, string $type, string $amount, string $requestId, ?string $note = null, ?string $paymentMethod = null, ?WithdrawalAccount $withdrawalAccount = null, ?UploadedFile $receipt = null): WalletRequest
    {
        abort_unless(config('portal.financial_operations_enabled'), 403, 'Wallet operations are temporarily paused.');
        abort_unless(in_array($type, ['deposit', 'withdraw'], true), 422, 'Invalid request type.');
        if ($paymentMethod !== null && !in_array($paymentMethod, ['landbank', 'bpi', 'security_bank', 'palawan_pay'], true)) {
            throw ValidationException::withMessages(['payment_method' => 'Choose a supported payment method.']);
        }
        $cents = PortalMoney::cents($amount, $type === 'deposit' ? null : 100000000);
        if ($type === 'deposit' && $cents < 500000) {
            throw ValidationException::withMessages(['amount' => 'The minimum add-funds amount is ₱5,000.']);
        }
        if ($type === 'withdraw' && (!$withdrawalAccount || (int) $withdrawalAccount->user_id !== $user->id)) {
            throw ValidationException::withMessages(['withdrawal_account_id' => 'Choose a saved withdrawal account.']);
        }
        $note = trim($note ?? '');
        $fingerprintData = $type.'|'.$cents.'|'.$note.($paymentMethod === null ? '' : '|'.$paymentMethod);
        if ($withdrawalAccount) {
            $fingerprintData .= '|'.$withdrawalAccount->id;
        }
        $fingerprint = hash('sha256', $fingerprintData);
        app(InterestAccrualService::class)->accrue($user);
        $storedReceiptPath = null;
        try {
            [$request, $created] = DB::transaction(function () use ($user, $type, $cents, $requestId, $note, $fingerprint, $paymentMethod, $withdrawalAccount, $receipt, &$storedReceiptPath) {
                if ($storedReceiptPath !== null) {
                    if (is_string($storedReceiptPath) && Storage::disk('local')->exists($storedReceiptPath)) {
                        Storage::disk('local')->delete($storedReceiptPath);
                    }
                    $storedReceiptPath = null;
                }
                $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                $existing = WalletRequest::where('user_id', $user->id)->where('request_id', $requestId)->first();
                if ($existing) {
                    if (!hash_equals($existing->request_fingerprint, $fingerprint)) {
                        throw ValidationException::withMessages(['request_id' => 'This reference was already used with different details.']);
                    }
                    if ($receipt && !$existing->receipt_path) {
                        $storedReceiptPath = $receipt->store('deposit-receipts/'.$user->id, 'local');
                        if (!is_string($storedReceiptPath) || $storedReceiptPath === '') {
                            throw new RuntimeException('The receipt image could not be saved. Please try again.');
                        }
                        if (!Storage::disk('local')->exists($storedReceiptPath)) {
                            $storedReceiptPath = null;
                            throw new RuntimeException('The receipt image could not be persisted. Please try again.');
                        }
                        $existing->update(['receipt_path' => $storedReceiptPath]);
                    }
                    return [$existing, false];
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
                $request = WalletRequest::create(['reference' => (string) Str::uuid(), 'user_id' => $user->id,
                    'request_id' => $requestId, 'request_fingerprint' => $fingerprint, 'type' => $type,
                    'amount_cents' => $cents, 'member_note' => $note ?: null, 'status' => 'pending',
                    'payment_method' => $paymentMethod,
                    'withdrawal_account_id' => $withdrawalAccount?->id,
                    'withdrawal_account_type' => $withdrawalAccount?->type,
                    'withdrawal_account_provider' => $withdrawalAccount?->provider,
                    'withdrawal_account_holder' => $withdrawalAccount?->account_holder,
                    'withdrawal_account_number' => $withdrawalAccount?->account_number,
                    'test_only' => $type === 'deposit' && $paymentMethod !== null && config('portal.deposit_qr_demo_mode')]);
                if ($receipt) {
                    $storedReceiptPath = $receipt->store('deposit-receipts/'.$user->id, 'local');
                    if (!is_string($storedReceiptPath) || $storedReceiptPath === '') {
                        throw new RuntimeException('The receipt image could not be saved. Please try again.');
                    }
                    if (!Storage::disk('local')->exists($storedReceiptPath)) {
                        $storedReceiptPath = null;
                        throw new RuntimeException('The receipt image could not be persisted. Please try again.');
                    }
                    $request->update(['receipt_path' => $storedReceiptPath]);
                }
                return [$request, true];
            }, 3);
        } catch (Throwable $error) {
                if (is_string($storedReceiptPath) && Storage::disk('local')->exists($storedReceiptPath)) {
                Storage::disk('local')->delete($storedReceiptPath);
            }
            throw $error;
        }

        if ($created && !$request->test_only) {
            $message = $type === 'deposit'
                ? 'Your deposit request was submitted for review.'
                : 'Your withdrawal request was submitted for review.';
            $notifications = app(WebPushNotificationService::class);
            $notifications->notify($user->id, $message);
            if (! $user->is_dummy) {
                $requestType = $type === 'deposit' ? 'deposit' : 'withdrawal';
                $notifications->notifyAdmins(
                    'A member submitted a new '.$requestType.' request. Sign in to review it.',
                    $type === 'deposit' ? '/#/admin?tab=deposits' : '/#/admin?tab=withdrawals',
                    'New '.$requestType.' request'
                );
            }
        }

        return $request;
    }

    public function review(User $actor, WalletRequest $request, string $decision, string $note): WalletRequest
    {
        abort_unless($actor->is_admin, 403, 'Administrator access is required.');
        abort_unless(config('portal.financial_operations_enabled'), 403, 'Wallet operations are temporarily paused.');
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422, 'Invalid decision.');
        [$request, $changed] = DB::transaction(function () use ($actor, $request, $decision, $note) {
            // All financial workflows acquire the wallet lock before dependent records.
            $wallet = Wallet::where('user_id', $request->user_id)->lockForUpdate()->firstOrFail();
            $request = WalletRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $status = $decision === 'approve' ? 'approved' : 'rejected';
            if ($request->test_only && $decision === 'approve') {
                throw ValidationException::withMessages(['decision' => 'Demo deposit requests cannot be approved.']);
            }
            if ($request->status === $status) { return [$request, false]; }
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
            return [$request, true];
        }, 3);

        if ($changed && !$request->test_only) {
            $type = $request->type === 'withdraw' ? 'withdrawal' : 'deposit';
            $message = $request->status === 'approved'
                ? 'Your '.$type.' request was approved. Sign in to view the account update.'
                : 'Your '.$type.' request was declined. Sign in to view the account update.';
            app(WebPushNotificationService::class)->notify($request->user_id, $message);

            if ($request->type === 'withdraw' && $request->status === 'approved') {
                try {
                    $member = User::findOrFail($request->user_id);
                    Mail::to($member->email)->send(new \App\Mail\WithdrawalApproved($request->fresh(), $member));
                } catch (Throwable $mailError) {
                    report($mailError);
                }
            }
        }

        return $request;
    }
}
