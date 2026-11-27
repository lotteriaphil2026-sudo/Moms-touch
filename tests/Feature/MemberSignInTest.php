<?php

namespace Tests\Feature;

use App\Models\{MemberSignIn, User, Wallet};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberSignInTest extends TestCase
{
    use RefreshDatabase;

    private function account(bool $admin = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => $admin])->save();
        Wallet::create(['user_id' => $user->id]);

        return $user;
    }

    private function signInRecord(User $user, array $metadata = []): MemberSignIn
    {
        return $user->signIns()->create($metadata + [
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Test browser',
            'country' => 'PH',
            'region' => 'Metro Manila',
            'city' => 'Makati',
        ]);
    }

    public function test_successful_member_sign_in_records_request_metadata_and_available_location_headers(): void
    {
        $member = $this->account();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->withHeaders([
                'User-Agent' => 'Test device browser',
                'CF-IPCountry' => 'ph',
                'CF-Region' => 'Metro Manila',
                'CF-IPCity' => 'Makati',
            ])
            ->postJson('/portal/login', ['email' => $member->email, 'password' => 'password'])
            ->assertOk();

        $this->assertDatabaseHas('member_sign_ins', [
            'user_id' => $member->id,
            'ip_address' => '203.0.113.42',
            'user_agent' => 'Test device browser',
            'country' => 'PH',
            'region' => 'Metro Manila',
            'city' => 'Makati',
        ]);
    }

    public function test_registration_records_the_initial_member_sign_in(): void
    {
        config(['portal.registration_enabled' => true]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.43'])
            ->withHeaders(['User-Agent' => 'New member browser'])
            ->postJson('/portal/register', [
                'name' => 'New Member',
                'email' => 'new-member@example.test',
                'phone' => '+639171234567',
                'date_of_birth' => '1990-01-01',
                'address' => '123 Example Street, Makati City',
                'gender' => 'Other',
                'marital_status' => 'Single',
                'password' => 'A-strong-password!9',
                'password_confirmation' => 'A-strong-password!9',
            ])
            ->assertOk();

        $this->assertDatabaseHas('member_sign_ins', [
            'ip_address' => '203.0.113.43',
            'user_agent' => 'New member browser',
            'country' => null,
            'region' => null,
            'city' => null,
        ]);
    }

    public function test_failed_logins_and_administrator_sign_ins_are_not_recorded_as_member_sign_ins(): void
    {
        $member = $this->account();
        $admin = $this->account(true);

        $this->postJson('/portal/login', ['email' => $member->email, 'password' => 'incorrect'])->assertUnprocessable();
        $this->postJson('/portal/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();

        $this->assertDatabaseCount('member_sign_ins', 0);
    }

    public function test_only_administrators_can_view_member_sign_in_history(): void
    {
        $member = $this->account();
        $admin = $this->account(true);
        $this->signInRecord($member);

        $this->actingAs($member)->getJson('/portal/state')
            ->assertOk()
            ->assertJsonMissingPath('sign_in_history');

        $this->actingAs($admin)->getJson('/portal/admin/users/'.$member->id)
            ->assertOk()
            ->assertJsonPath('sign_in_history.0.ip_address', '203.0.113.10')
            ->assertJsonPath('sign_in_history.0.city', 'Makati')
            ->assertJsonMissingPath('sign_in_history.0.password');

        $this->actingAs($member)->getJson('/portal/admin/users/'.$member->id)->assertForbidden();
    }

    public function test_sign_in_records_older_than_thirty_days_are_pruned(): void
    {
        $member = $this->account();
        $expired = $this->signInRecord($member);
        $expired->forceFill(['created_at' => now()->subDays(31)])->saveQuietly();
        $recent = $this->signInRecord($member);

        $this->artisan('portal:prune-sign-ins')->assertExitCode(0);

        $this->assertDatabaseMissing('member_sign_ins', ['id' => $expired->id]);
        $this->assertDatabaseHas('member_sign_ins', ['id' => $recent->id]);
    }
}
