<?php

namespace Tests\Feature;

use App\Models\{User, Wallet, WalletTransaction};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeScreenBonusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.financial_operations_enabled' => true]);
    }

    public function test_standalone_member_can_claim_the_bonus_once_and_receives_a_wallet_ledger_entry(): void
    {
        $member = $this->member();

        $this->actingAs($member)
            ->withHeader('X-Home-Screen-Standalone', 'true')
            ->postJson('/portal/promotions/home-screen-bonus/claim')
            ->assertOk()
            ->assertJsonPath('user.home_screen_bonus_claimed', true)
            ->assertJsonPath('wallet.balance', 20000)
            ->assertJsonPath('transactions.0.type', 'promotion_bonus')
            ->assertJsonPath('transactions.0.amount', 20000);

        $this->assertDatabaseHas('promotion_claims', [
            'user_id' => $member->id,
            'promotion_key' => 'home-screen-200',
        ]);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $member->id,
            'balance_cents' => 20000,
        ]);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_browser_tab_cannot_claim_the_bonus_without_standalone_signal(): void
    {
        $member = $this->member();

        $this->actingAs($member)
            ->postJson('/portal/promotions/home-screen-bonus/claim')
            ->assertForbidden();

        $this->assertDatabaseHas('wallets', ['user_id' => $member->id, 'balance_cents' => 0]);
        $this->assertDatabaseCount('promotion_claims', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_repeated_claim_does_not_credit_the_member_twice(): void
    {
        $member = $this->member();
        $request = $this->actingAs($member)->withHeader('X-Home-Screen-Standalone', 'true');

        $request->postJson('/portal/promotions/home-screen-bonus/claim')->assertOk();
        $request->postJson('/portal/promotions/home-screen-bonus/claim')->assertConflict();

        $this->assertDatabaseHas('wallets', ['user_id' => $member->id, 'balance_cents' => 20000]);
        $this->assertDatabaseCount('promotion_claims', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_bonus_claim_is_blocked_while_wallet_credits_are_paused_and_for_non_members(): void
    {
        $member = $this->member();
        config(['portal.financial_operations_enabled' => false]);
        $this->actingAs($member)
            ->withHeader('X-Home-Screen-Standalone', 'true')
            ->postJson('/portal/promotions/home-screen-bonus/claim')
            ->assertForbidden();

        config(['portal.financial_operations_enabled' => true]);
        $dummy = User::factory()->create(['is_dummy' => true]);
        Wallet::create(['user_id' => $dummy->id]);
        $this->actingAs($dummy)
            ->withHeader('X-Home-Screen-Standalone', 'true')
            ->postJson('/portal/promotions/home-screen-bonus/claim')
            ->assertForbidden();

        $this->assertDatabaseCount('promotion_claims', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    private function member(): User
    {
        $member = User::factory()->create();
        Wallet::create(['user_id' => $member->id]);

        return $member;
    }
}
