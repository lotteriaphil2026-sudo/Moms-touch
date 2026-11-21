<?php

namespace Tests\Feature;

use App\Models\{User, Wallet, WalletRequest};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DepositBankFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.financial_operations_enabled' => true, 'portal.deposit_qr_demo_mode' => true]);
    }

    private function account(bool $admin = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => $admin])->save();
        Wallet::create(['user_id' => $user->id]);

        return $user;
    }

    public function test_demo_bank_deposit_is_saved_as_test_only_and_cannot_be_approved(): void
    {
        $member = $this->account();
        $admin = $this->account(true);

        $response = $this->actingAs($member)->postJson('/portal/transactions', [
            'type' => 'deposit',
            'amount' => '500',
            'payment_method' => 'bpi',
            'request_id' => (string) Str::uuid(),
        ]);

        $response->assertOk()
            ->assertJsonPath('submitted_request.payment_method', 'bpi')
            ->assertJsonPath('submitted_request.test_only', true)
            ->assertJsonPath('submitted_request.status', 'pending');

        $request = WalletRequest::firstOrFail();
        $this->actingAs($admin)->postJson('/portal/admin/requests/'.$request->id.'/review', [
            'decision' => 'approve',
            'note' => 'Test transfer',
        ])->assertUnprocessable();

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(0, Wallet::where('user_id', $member->id)->firstOrFail()->balance_cents);
    }

    public function test_deposit_requests_reject_unsupported_banks(): void
    {
        $member = $this->account();
        $request = [
            'type' => 'deposit',
            'amount' => '500',
            'request_id' => (string) Str::uuid(),
        ];

        $this->actingAs($member)->postJson('/portal/transactions', $request)
            ->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->postJson('/portal/transactions', [...$request, 'payment_method' => 'other-bank'])
            ->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        $this->assertDatabaseCount('wallet_requests', 0);
    }

    public function test_configured_bank_deposit_uses_real_qr_mode(): void
    {
        config(['portal.deposit_qr_demo_mode' => false]);
        $member = $this->account();

        $this->actingAs($member)->postJson('/portal/transactions', [
            'type' => 'deposit',
            'amount' => '500',
            'payment_method' => 'bpi',
            'request_id' => (string) Str::uuid(),
        ])
            ->assertOk()
            ->assertJsonPath('submitted_request.payment_method', 'bpi')
            ->assertJsonPath('submitted_request.test_only', false);

        $this->assertDatabaseHas('wallet_requests', [
            'payment_method' => 'bpi',
            'test_only' => false,
        ]);
    }
}
