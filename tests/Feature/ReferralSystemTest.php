<?php

namespace Tests\Feature;

use App\Models\{User, Wallet, WalletTransaction};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReferralSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.registration_enabled' => true, 'portal.financial_operations_enabled' => true]);
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Member',
            'email' => 'new-member@example.test',
            'phone' => '+639171234567',
            'date_of_birth' => '1990-01-01',
            'address' => '123 Example Street, Makati City',
            'gender' => 'Other',
            'marital_status' => 'Single',
            'password' => 'A-strong-password!9',
            'password_confirmation' => 'A-strong-password!9',
        ], $overrides);
    }

    public function test_referral_code_is_required_for_registration(): void
    {
        $this->postJson('/portal/register', $this->registrationPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('referral_code');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_signup_link_attributes_the_new_member_to_the_referring_member(): void
    {
        $referrer = User::factory()->create(['name' => 'Referring Member']);
        Wallet::create(['user_id' => $referrer->id]);

        $this->postJson('/portal/register', $this->registrationPayload([
            'email' => 'referred-member@example.test',
            'referral_code' => $referrer->referral_code,
        ]))->assertOk()
            ->assertJsonPath('user.referrer.type', 'member')
            ->assertJsonPath('user.referrer.id', $referrer->id)
            ->assertJsonPath('user.referrer.name', 'Referring Member');

        $this->assertDatabaseHas('users', [
            'email' => 'referred-member@example.test',
            'referrer_id' => $referrer->id,
            'referrer_type' => 'member',
        ]);

        $this->actingAs($referrer)->getJson('/portal/state')
            ->assertOk()
            ->assertJsonPath('user.referrals_count', 1);
    }

    public function test_invalid_referral_code_cannot_be_used_to_register(): void
    {
        $this->postJson('/portal/register', $this->registrationPayload([
            'referral_code' => 'NOTREAL12345',
        ]))->assertUnprocessable()->assertJsonValidationErrors('referral_code');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_member_can_edit_referral_code(): void
    {
        $member = User::factory()->create(['name' => 'Editing Member']);
        Wallet::create(['user_id' => $member->id]);

        $this->actingAs($member)
            ->patchJson('/portal/profile', [
                'name' => 'Editing Member',
                'referral_code' => 'My Custom Code',
            ])
            ->assertOk()
            ->assertJsonPath('user.referral_code', 'MY-CUSTOM-CODE');

        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'referral_code' => 'MY-CUSTOM-CODE',
            'referral_code_changed' => true,
        ]);
    }

    public function test_member_cannot_use_another_members_referral_code(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($second)
            ->patchJson('/portal/profile', [
                'name' => $second->name,
                'referral_code' => $first->referral_code,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('referral_code');
    }

    public function test_new_member_receives_a_name_based_referral_code(): void
    {
        $referrer = User::factory()->create(['name' => 'Referring Member']);
        Wallet::create(['user_id' => $referrer->id]);

        $response = $this->postJson('/portal/register', $this->registrationPayload([
            'name' => 'Maria Santos',
            'referral_code' => $referrer->referral_code,
        ]))->assertOk();

        $this->assertSame('MARIA-SANTOS', $response->json('user.referral_code'));
    }

    public function test_referrer_receives_five_percent_of_each_referred_members_investment_once(): void
    {
        $referrer = User::factory()->create(['name' => 'Referring Member']);
        Wallet::create(['user_id' => $referrer->id]);
        $member = User::factory()->create(['name' => 'Referred Member']);
        $member->referrer()->associate($referrer);
        $member->save();
        Wallet::create(['user_id' => $member->id, 'balance_cents' => 2000000]);

        $this->actingAs($member);
        $requestId = (string) Str::uuid();
        $payload = [
            'type' => 'invest',
            'amount' => '5000.10',
            'plan' => 'select',
            'request_id' => $requestId,
        ];

        $this->postJson('/portal/transactions', $payload)
            ->assertOk()
            ->assertJsonPath('wallet.balance', 1499990);

        $referrerWallet = Wallet::where('user_id', $referrer->id)->firstOrFail();
        $this->assertSame(25001, $referrerWallet->balance_cents);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $referrer->id,
            'type' => 'referral_bonus',
            'amount_cents' => 25001,
            'balance_after_cents' => 25001,
            'status' => 'completed',
        ]);
        $this->assertSame(1, WalletTransaction::where('user_id', $referrer->id)->where('type', 'referral_bonus')->count());

        $this->postJson('/portal/transactions', $payload)->assertOk();

        $this->assertSame(25001, $referrerWallet->fresh()->balance_cents);
        $this->assertSame(1, WalletTransaction::where('user_id', $referrer->id)->where('type', 'referral_bonus')->count());

        $this->postJson('/portal/transactions', [
            ...$payload,
            'amount' => '5000',
            'request_id' => (string) Str::uuid(),
        ])->assertOk();

        $this->assertSame(50001, $referrerWallet->fresh()->balance_cents);
        $this->assertSame(2, WalletTransaction::where('user_id', $referrer->id)->where('type', 'referral_bonus')->count());
        $this->actingAs($referrer)->getJson('/portal/state')
            ->assertOk()
            ->assertJsonPath('wallet.referral_earnings', 50001)
            ->assertJsonPath('transactions.0.type', 'referral_bonus');
    }

    public function test_failed_investment_does_not_credit_a_referral_incentive(): void
    {
        $referrer = User::factory()->create();
        Wallet::create(['user_id' => $referrer->id]);
        $member = User::factory()->create();
        $member->referrer()->associate($referrer);
        $member->save();
        Wallet::create(['user_id' => $member->id]);

        $this->actingAs($member)->postJson('/portal/transactions', [
            'type' => 'invest',
            'amount' => '5000',
            'plan' => 'select',
            'request_id' => (string) Str::uuid(),
        ])->assertUnprocessable();

        $this->assertSame(0, Wallet::where('user_id', $referrer->id)->value('balance_cents'));
        $this->assertDatabaseMissing('wallet_transactions', [
            'user_id' => $referrer->id,
            'type' => 'referral_bonus',
        ]);
    }
}
