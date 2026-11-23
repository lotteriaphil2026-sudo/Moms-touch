<?php

namespace App\Http\Controllers;

use App\Models\WithdrawalAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WithdrawalAccountController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['bank', 'e_wallet'])],
            'provider' => ['required', 'string', 'min:2', 'max:80'],
            'account_holder' => ['required', 'string', 'min:2', 'max:120'],
            'account_number' => ['required', 'string', 'regex:/^[A-Za-z0-9+(). -]{5,64}$/'],
        ]);

        WithdrawalAccount::create([
            ...$data,
            'user_id' => $request->user()->id,
            'provider' => trim($data['provider']),
            'account_holder' => trim($data['account_holder']),
            'account_number' => trim($data['account_number']),
        ]);

        return response()->json([
            'csrf' => csrf_token(),
            'withdrawal_accounts' => $this->accountsFor($request->user()->id),
        ], 201);
    }

    public function destroy(Request $request, int $withdrawalAccount): JsonResponse
    {
        WithdrawalAccount::where('user_id', $request->user()->id)
            ->findOrFail($withdrawalAccount)
            ->delete();

        return response()->json([
            'csrf' => csrf_token(),
            'withdrawal_accounts' => $this->accountsFor($request->user()->id),
        ]);
    }

    private function accountsFor(int $userId): array
    {
        return WithdrawalAccount::where('user_id', $userId)
            ->latest('id')
            ->get()
            ->map(fn (WithdrawalAccount $account) => $account->publicData())
            ->all();
    }
}
