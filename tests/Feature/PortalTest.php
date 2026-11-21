<?php
namespace Tests\Feature;
use App\Models\{InvestmentAllocation, User, Wallet, WalletRequest, WalletTransaction};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Hash, Notification};
use Illuminate\Support\Str;
use Tests\TestCase;
class PortalTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.financial_operations_enabled' => true, 'portal.registration_enabled' => true, 'portal.deposit_qr_demo_mode' => false]);
    }
    private function member(): User
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id]);
        return $user;
    }
    private function approvedTransaction(string $type, string $amount, ?string $plan = null, ?string $requestId = null)
    {
        $requestId ??= (string) Str::uuid();
        $payload = [
            'type' => $type, 'amount' => $amount, 'plan' => $plan,
            'request_id' => $requestId,
        ];
        if ($type === 'deposit') { $payload['payment_method'] = 'bpi'; }
        $response = $this->postJson('/portal/transactions', $payload);
        // Ledger verification includes admin approval; separate tests verify pending states and permissions.
        if ($response->status() === 200 && in_array($type, ['deposit', 'withdraw'], true)) {
            $record = WalletRequest::where('user_id', auth()->id())->where('request_id', $requestId)->firstOrFail();
            $admin = User::where('is_admin', true)->first();
            if (!$admin) { $admin = User::factory()->create(); $admin->forceFill(['is_admin' => true])->save(); Wallet::create(['user_id' => $admin->id]); }
            app(\App\Services\WalletRequestService::class)->review($admin, $record, 'approve', 'Approved for ledger verification');
            return $this->getJson('/portal/state');
        }
        return $response;
    }
    public function test_english_portal_and_guest_state_are_available(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('minimum-scale=1,maximum-scale=1,user-scalable=no', false)
            ->assertSee('PORTAL_CONFIG')
            ->assertSee('property="og:title"', false)
            ->assertSee('property="og:image"', false)
            ->assertSee('/assets/social-preview.jpg', false)
            ->assertSee('name="twitter:card"', false);
        $this->getJson('/portal/state')->assertOk()->assertJsonPath('user', null)->assertJsonPath('wallet.balance', 0);
    }
    public function test_registration_creates_a_zero_balance_wallet_and_hashes_password(): void
    {
        $this->postJson('/portal/register', ['name' => 'Jamie Lee', 'email' => 'JAMIE@example.test', 'phone' => '+639171234567', 'password' => 'A-strong-password!9', 'password_confirmation' => 'A-strong-password!9'])
            ->assertOk()->assertJsonPath('user.email', 'jamie@example.test')->assertJsonPath('user.phone', '+639171234567')->assertJsonPath('wallet.balance', 0)->assertJsonPath('wallet.earnings', 0)->assertJsonStructure(['csrf']);
        $this->assertAuthenticated();
        $this->assertTrue(Hash::check('A-strong-password!9', User::first()->password));
        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseHas('users', ['email' => 'jamie@example.test', 'phone' => '+639171234567']);
    }

    public function test_registration_requires_a_valid_phone_number(): void
    {
        foreach (['not-a-phone', '123---', '1234567890123456'] as $phone) {
            $this->postJson('/portal/register', [
                'name' => 'Jamie Lee',
                'email' => 'jamie@example.test',
                'phone' => $phone,
                'password' => 'A-strong-password!9',
                'password_confirmation' => 'A-strong-password!9',
            ])->assertUnprocessable()->assertJsonValidationErrors('phone');
        }

        $this->assertDatabaseCount('users', 0);
    }
    public function test_login_validates_credentials_and_logout_ends_the_session(): void
    {
        $member = $this->member();
        $this->postJson('/portal/login', ['email' => $member->email, 'password' => 'incorrect'])->assertUnprocessable();
        $this->postJson('/portal/login', ['email' => $member->email, 'password' => 'password'])->assertOk()->assertJsonPath('user.id', $member->id);
        $this->postJson('/portal/logout')->assertOk()->assertJsonStructure(['csrf']);
        $this->assertGuest();
    }
    public function test_wallet_mutations_require_authentication(): void
    {
        $this->approvedTransaction('deposit', '1000')->assertUnauthorized();
        $this->assertDatabaseCount('wallet_transactions', 0);
    }
    public function test_money_is_stored_in_exact_centavos(): void
    {
        $this->actingAs($this->member());
        $this->approvedTransaction('deposit', '1000.25')->assertOk()->assertJsonPath('wallet.balance', 100025);
        $this->approvedTransaction('deposit', '100.10')->assertOk()->assertJsonPath('wallet.balance', 110035);
        $this->approvedTransaction('withdraw', '100.30')->assertOk()->assertJsonPath('wallet.balance', 100005);
        $this->assertDatabaseHas('wallet_transactions', ['amount_cents' => 10030, 'balance_after_cents' => 100005]);
    }
    public function test_overspending_fails_without_changing_wallet_or_ledger(): void
    {
        $this->actingAs($this->member());
        $this->approvedTransaction('deposit', '1000')->assertOk();
        $this->approvedTransaction('withdraw', '1001')->assertUnprocessable();
        $this->approvedTransaction('invest', '5000', 'prime')->assertUnprocessable();
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('investment_allocations', 0);
        $this->assertSame(100000, Wallet::first()->balance_cents);
    }
    public function test_plan_minimum_and_amount_validation_are_enforced_on_server(): void
    {
        $this->actingAs($this->member());
        $this->approvedTransaction('deposit', '20000')->assertOk();
        $this->approvedTransaction('invest', '4999', 'prime')->assertUnprocessable();
        $this->approvedTransaction('invest', '5000', 'invalid')->assertUnprocessable();
        foreach (['-100', '1e4', '100.001', '1000001', '0', '99.99'] as $amount) {
            $this->approvedTransaction('deposit', $amount)->assertUnprocessable();
        }
        $this->assertDatabaseCount('wallet_transactions', 1);
    }
    public function test_investment_moves_principal_and_credits_configured_simple_interest(): void
    {
        $this->actingAs($this->member());
        $this->approvedTransaction('deposit', '10000')->assertOk();
        $this->approvedTransaction('invest', '5000.25', 'select')->assertOk()
            ->assertJsonPath('wallet.balance', 499975)->assertJsonPath('wallet.invested', 500025)
            ->assertJsonPath('wallet.earnings', 0)->assertJsonPath('investments.0.status', 'active')
            ->assertJsonPath('investments.0.term_days', 160)->assertJsonPath('wallet.daily_earnings', 3000);
        $this->assertSame('0.006000', InvestmentAllocation::first()->daily_interest_rate);
        $this->travel(3)->days();
        $this->getJson('/portal/state')->assertOk()->assertJsonPath('wallet.earnings', 9000)->assertJsonPath('wallet.balance', 508975);
    }
    public function test_repeating_a_transaction_does_not_double_charge_and_modified_replay_fails(): void
    {
        $this->actingAs($this->member());
        $id = (string) Str::uuid();
        $this->approvedTransaction('deposit', '5000', null, $id)->assertOk();
        $this->approvedTransaction('deposit', '5000.00', null, $id)->assertOk()->assertJsonPath('wallet.balance', 500000);
        $this->approvedTransaction('deposit', '6000', null, $id)->assertUnprocessable();
        $this->assertDatabaseCount('wallet_transactions', 1);
    }
    public function test_members_only_see_their_own_records(): void
    {
        $first = $this->member();
        $second = $this->member();
        $this->actingAs($first);
        $this->approvedTransaction('deposit', '10000')->assertOk();
        $this->approvedTransaction('invest', '5000', 'select')->assertOk();
        $this->actingAs($second);
        $this->getJson('/portal/state')->assertOk()->assertJsonPath('wallet.balance', 0)->assertJsonCount(0, 'transactions')->assertJsonCount(0, 'investments');
        $this->approvedTransaction('withdraw', '100')->assertUnprocessable();
        $this->assertSame(500000, Wallet::where('user_id', $first->id)->first()->balance_cents);
    }
    public function test_financial_mutations_are_blocked_when_wallet_operations_are_disabled(): void
    {
        $this->actingAs($this->member());
        config(['portal.financial_operations_enabled' => false]);
        $this->approvedTransaction('deposit', '5000')->assertForbidden();
        $this->assertDatabaseCount('wallet_transactions', 0);
    }
    public function test_password_reset_request_uses_generic_response(): void
    {
        Notification::fake();
        $member = $this->member();
        $known = $this->postJson('/portal/forgot-password', ['email' => $member->email])->assertOk()->json('message');
        $unknown = $this->postJson('/portal/forgot-password', ['email' => 'absent@example.test'])->assertOk()->json('message');
        $this->assertSame($known, $unknown);
    }
    public function test_csrf_tokens_refresh_after_login_and_protect_wallet_requests(): void
    {
        $this->app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, fn ($app) =>
            new class($app, $app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken {
                protected function runningUnitTests() { return false; }
            }
        );
        $member = $this->member();
        $before = Str::random(40);
        $this->withSession(['_token' => $before]);
        $login = $this->withHeader('X-CSRF-TOKEN', $before)->postJson('/portal/login', ['email' => $member->email, 'password' => 'password'])->assertOk();
        $after = $login->json('csrf');
        $this->assertNotSame($before, $after);
        $this->withHeader('X-CSRF-TOKEN', $after);
        $this->approvedTransaction('deposit', '500')->assertOk();
        $this->withHeader('X-CSRF-TOKEN', $before);
        $this->approvedTransaction('deposit', '500')->assertStatus(419);
    }
}
