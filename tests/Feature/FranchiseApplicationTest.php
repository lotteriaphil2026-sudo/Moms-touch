<?php
namespace Tests\Feature;
use App\Models\{FranchiseApplication, User, Wallet};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
class FranchiseApplicationTest extends TestCase
{
    use RefreshDatabase;
    private function member(): User
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id]);
        return $user;
    }
    private function input(array $overrides = []): array
    {
        return array_replace([
            'request_id' => (string) Str::uuid(), 'contact_name' => 'Jamie Test',
            'contact_email' => 'jamie@example.test', 'phone' => '+63 912 345 6789',
            'country' => 'Philippines', 'city' => 'Taguig', 'region' => 'Metro Manila',
            'site_status' => 'proposed', 'budget' => '2500000.50',
            'target_opening' => '2027-06', 'experience' => 'I would like to discuss a neighborhood restaurant.',
            'consent' => true,
        ], $overrides);
    }
    public function test_application_submission_requires_sign_in(): void
    {
        $this->postJson('/portal/franchise-applications', $this->input())->assertUnauthorized();
        $this->assertDatabaseCount('franchise_applications', 0);
    }
    public function test_members_can_submit_and_retrieve_their_application(): void
    {
        $user = $this->member(); $this->actingAs($user);
        $this->postJson('/portal/franchise-applications', $this->input())->assertOk()
            ->assertJsonCount(1, 'franchise_applications')
            ->assertJsonPath('franchise_applications.0.city', 'Taguig')
            ->assertJsonPath('franchise_applications.0.budget_cents', 250000050)
            ->assertJsonPath('franchise_applications.0.status', 'submitted');
        $application = FranchiseApplication::first();
        $this->assertSame($user->id, $application->user_id);
        $this->assertStringStartsWith('MTF-', $application->reference);
        $this->assertNotNull($application->consent_at);
        $this->getJson('/portal/state')->assertOk()->assertJsonPath('franchise_applications.0.reference', $application->reference);
        $this->assertSame(0, Wallet::first()->balance_cents);
    }
    public function test_optional_budget_timeline_and_experience_can_be_omitted(): void
    {
        $this->actingAs($this->member());
        $input = $this->input(); unset($input['budget'], $input['target_opening'], $input['experience']);
        $this->postJson('/portal/franchise-applications', $input)->assertOk()
            ->assertJsonPath('franchise_applications.0.budget_cents', null)
            ->assertJsonPath('franchise_applications.0.target_opening', null);
    }
    public function test_contact_location_and_consent_are_validated(): void
    {
        $this->actingAs($this->member());
        $this->postJson('/portal/franchise-applications', $this->input([
            'contact_email' => 'not-email', 'phone' => '123', 'city' => '',
            'region' => '', 'site_status' => 'invalid', 'consent' => false,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['contact_email', 'phone', 'city', 'region', 'site_status', 'consent']);
        $this->assertDatabaseCount('franchise_applications', 0);
    }
    public function test_invalid_budget_and_month_are_rejected(): void
    {
        $this->actingAs($this->member());
        $this->postJson('/portal/franchise-applications', $this->input(['budget' => '1e6', 'target_opening' => 'June 2027']))
            ->assertUnprocessable()->assertJsonValidationErrors(['budget', 'target_opening']);
        $this->postJson('/portal/franchise-applications', $this->input(['budget' => '100000001']))
            ->assertUnprocessable()->assertJsonValidationErrors(['budget']);
        $this->postJson('/portal/franchise-applications', $this->input(['budget' => '0']))
            ->assertUnprocessable()->assertJsonValidationErrors(['budget']);
    }
    public function test_application_records_are_isolated_and_client_cannot_set_owner_or_status(): void
    {
        $first = $this->member(); $second = $this->member();
        $this->actingAs($first)->postJson('/portal/franchise-applications', $this->input(['user_id' => $second->id, 'status' => 'approved']))->assertOk();
        $this->assertSame($first->id, FranchiseApplication::first()->user_id);
        $this->assertSame('submitted', FranchiseApplication::first()->status);
        $this->actingAs($second)->getJson('/portal/state')->assertOk()->assertJsonCount(0, 'franchise_applications');
    }
    public function test_application_retries_are_idempotent_and_modified_replays_are_rejected(): void
    {
        $this->actingAs($this->member()); $input = $this->input();
        $first = $this->postJson('/portal/franchise-applications', $input)->assertOk()->json('franchise_applications.0.reference');
        $this->postJson('/portal/franchise-applications', $input)->assertOk()->assertJsonPath('franchise_applications.0.reference', $first)->assertJsonCount(1, 'franchise_applications');
        $input['city'] = 'Pasig';
        $this->postJson('/portal/franchise-applications', $input)->assertUnprocessable()->assertJsonValidationErrors('request_id');
        $this->assertDatabaseCount('franchise_applications', 1);
    }
    public function test_guest_state_never_exposes_applications(): void
    {
        $this->getJson('/portal/state')->assertOk()->assertJsonPath('franchise_applications', []);
    }
}
