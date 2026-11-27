<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRequest;
use App\Models\WithdrawalAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WithdrawalAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.financial_operations_enabled' => true]);
    }

    public function test_withdrawals_require_a_saved_account_owned_by_the_member(): void
    {
        $member = User::factory()->create();
        Wallet::create(['user_id' => $member->id, 'balance_cents' => 100000]);

        $this->actingAs($member)->postJson('/portal/transactions', [
            'type' => 'withdraw',
            'amount' => '500',
            'request_id' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('withdrawal_account_id');

        $otherMember = User::factory()->create();
        $otherAccount = $this->createAccount($otherMember, 'e_wallet', 'GCash', '09171234567');

        $this->postJson('/portal/transactions', [
            'type' => 'withdraw',
            'amount' => '500',
            'withdrawal_account_id' => $otherAccount->id,
            'request_id' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('withdrawal_account_id');

        $this->assertSame(100000, Wallet::where('user_id', $member->id)->value('balance_cents'));
        $this->assertDatabaseCount('wallet_requests', 0);
    }

    public function test_multiple_accounts_can_be_saved_and_selected_details_are_snapshotted_for_admins(): void
    {
        $member = User::factory()->create();
        Wallet::create(['user_id' => $member->id, 'balance_cents' => 100000]);
        $first = $this->createAccount($member, 'bank', 'BPI', '1234567890');
        $this->actingAs($member)->postJson('/portal/withdrawal-accounts', [
            'type' => 'e_wallet',
            'provider' => 'GCash',
            'account_holder' => $member->name,
            'account_number' => '09171234567',
        ])->assertCreated()
            ->assertJsonCount(2, 'withdrawal_accounts')
            ->assertJsonPath('withdrawal_accounts.0.provider', 'GCash')
            ->assertJsonPath('withdrawal_accounts.0.last_four', '4567')
            ->assertJsonMissingPath('withdrawal_accounts.0.account_number');
        $second = WithdrawalAccount::where('user_id', $member->id)->where('provider', 'GCash')->firstOrFail();

        $requestId = (string) Str::uuid();
        $this->postJson('/portal/transactions', [
            'type' => 'withdraw',
            'amount' => '500',
            'withdrawal_account_id' => $second->id,
            'request_id' => $requestId,
        ])->assertOk()
            ->assertJsonPath('wallet_requests.0.status', 'pending')
            ->assertJsonMissingPath('wallet_requests.0.withdrawal_destination');

        $walletRequest = WalletRequest::where('request_id', $requestId)->firstOrFail();
        $encryptedNumber = DB::table('wallet_requests')->where('id', $walletRequest->id)->value('withdrawal_account_number');
        $this->assertNotSame('09171234567', $encryptedNumber);
        $this->assertSame('09171234567', $walletRequest->withdrawal_account_number);
        $this->postJson('/portal/transactions', [
            'type' => 'withdraw',
            'amount' => '500',
            'withdrawal_account_id' => $first->id,
            'request_id' => $requestId,
        ])->assertUnprocessable()->assertJsonValidationErrors('request_id');

        $admin = User::factory()->create(['is_admin' => true]);
        Wallet::create(['user_id' => $admin->id]);
        $this->actingAs($admin)->getJson('/portal/admin/requests?type=withdraw')
            ->assertOk()
            ->assertJsonPath('items.0.withdrawal_destination.type', 'e_wallet')
            ->assertJsonPath('items.0.withdrawal_destination.provider', 'GCash')
            ->assertJsonPath('items.0.withdrawal_destination.account_holder', $member->name)
            ->assertJsonPath('items.0.withdrawal_destination.account_number', '09171234567');

        $this->actingAs($member)->deleteJson('/portal/withdrawal-accounts/'.$second->id)->assertOk()
            ->assertJsonCount(1, 'withdrawal_accounts');
        $this->assertSame('09171234567', $walletRequest->fresh()->withdrawal_account_number);
        $this->assertDatabaseHas('withdrawal_accounts', ['id' => $first->id, 'user_id' => $member->id]);
    }

    private function createAccount(User $user, string $type, string $provider, string $number): WithdrawalAccount
    {
        return WithdrawalAccount::create([
            'user_id' => $user->id,
            'type' => $type,
            'provider' => $provider,
            'account_holder' => $user->name,
            'account_number' => $number,
        ]);
    }
}
