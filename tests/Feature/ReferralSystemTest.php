<?php

namespace Tests\Feature;

use App\Models\{User, Wallet};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.registration_enabled' => true]);
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

    public function test_unlinked_signup_receives_a_platform_referrer_and_unique_share_link(): void
    {
        $response = $this->postJson('/portal/register', $this->registrationPayload());

        $response->assertOk()
            ->assertJsonPath('user.referrer.type', 'platform')
            ->assertJsonPath('user.referrer.name', 'Mom’s Touch Platform')
            ->assertJsonPath('user.referrals_count', 0);

        $code = $response->json('user.referral_code');
        $this->assertMatchesRegularExpression('/\A[A-Z0-9]{12}\z/', $code);
        $this->assertDatabaseHas('users', [
            'id' => $response->json('user.id'),
            'referral_code' => $code,
            'referrer_id' => null,
            'referrer_type' => 'platform',
        ]);
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
}
