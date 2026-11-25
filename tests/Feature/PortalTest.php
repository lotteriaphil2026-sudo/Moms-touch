<?php
namespace Tests\Feature;
use App\Models\{InvestmentAllocation, User, Wallet, WalletRequest, WalletTransaction, WithdrawalAccount};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Hash, Notification, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;
class PortalTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.financial_operations_enabled' => true, 'portal.registration_enabled' => true, 'portal.deposit_qr_demo_mode' => false]);
        Storage::fake('local');
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
        if ($type === 'deposit') {
            $payload['payment_method'] = 'bpi';
            $payload['receipt'] = UploadedFile::fake()->createWithContent(
                'receipt.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lGkAAAAASUVORK5CYII=')
            );
        }
        if ($type === 'withdraw' && auth()->check()) {
            $account = WithdrawalAccount::create([
                'user_id' => auth()->id(),
                'type' => 'bank',
                'provider' => 'BPI',
                'account_holder' => auth()->user()->name,
                'account_number' => '1234567890',
            ]);
            $payload['withdrawal_account_id'] = $account->id;
        }
        $response = $type === 'deposit'
            ? $this->post('/portal/transactions', $payload, ['Accept' => 'application/json'])
            : $this->postJson('/portal/transactions', $payload);
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
            ->assertSee('rel="apple-touch-icon" sizes="512x512" href="/unnamed.png"', false)
            ->assertSee('rel="manifest" href="/site.webmanifest"', false)
            ->assertSee('PORTAL_CONFIG')
            ->assertSee('property="og:title"', false)
            ->assertSee('property="og:image"', false)
            ->assertSee('/assets/social-preview.jpg', false)
            ->assertSee('name="twitter:card"', false);
        $this->getJson('/portal/state')->assertOk()->assertJsonPath('user', null)->assertJsonPath('wallet.balance', 0);
    }
    public function test_referral_links_include_social_preview_metadata(): void
    {
        $referralCode = 'MT-ABC123';
        $this->get('/?ref='.$referralCode)
            ->assertOk()
            ->assertSee('<title>You’re invited to join Mom’s Touch</title>', false)
            ->assertSee('property="og:title" content="You’re invited to join Mom’s Touch"', false)
            ->assertSee('property="og:description" content="Explore the Mom’s Touch Members Club, member updates, events, and more through this referral link."', false)
            ->assertSee('property="og:url" content="'.url('/').'?ref='.$referralCode.'"', false)
            ->assertSee('name="twitter:title" content="You’re invited to join Mom’s Touch"', false);
    }
    public function test_registration_creates_a_zero_balance_wallet_and_hashes_password(): void
    {
        $this->postJson('/portal/register', [
            'name' => 'Jamie Lee',
            'email' => 'JAMIE@example.test',
            'phone' => '+639171234567',
            'date_of_birth' => '1995-03-08',
            'address' => '123 Example Street, Makati City',
            'gender' => 'Female',
            'marital_status' => 'Single',
            'password' => 'A-strong-password!9',
            'password_confirmation' => 'A-strong-password!9',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'jamie@example.test')
            ->assertJsonPath('user.phone', '+639171234567')
            ->assertJsonPath('user.date_of_birth', '1995-03-08')
            ->assertJsonPath('user.address', '123 Example Street, Makati City')
            ->assertJsonPath('user.gender', 'Female')
            ->assertJsonPath('user.marital_status', 'Single')
            ->assertJsonPath('wallet.balance', 0)
            ->assertJsonPath('wallet.earnings', 0)
            ->assertJsonStructure(['csrf']);
        $this->assertAuthenticated();
        $this->assertTrue(Hash::check('A-strong-password!9', User::first()->password));
        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseHas('users', [
            'email' => 'jamie@example.test',
            'phone' => '+639171234567',
            'date_of_birth' => '1995-03-08',
            'address' => '123 Example Street, Makati City',
            'gender' => 'Female',
            'marital_status' => 'Single',
        ]);
    }

    public function test_registration_requires_a_valid_phone_number(): void
    {
        foreach (['not-a-phone', '123---', '1234567890123456'] as $phone) {
            $this->postJson('/portal/register', [
                'name' => 'Jamie Lee',
                'email' => 'jamie@example.test',
                'phone' => $phone,
                'date_of_birth' => '1995-03-08',
                'address' => '123 Example Street, Makati City',
                'gender' => 'Female',
                'marital_status' => 'Single',
                'password' => 'A-strong-password!9',
                'password_confirmation' => 'A-strong-password!9',
            ])->assertUnprocessable()->assertJsonValidationErrors('phone');
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_requires_valid_profile_details(): void
    {
        $payload = [
            'name' => 'Jamie Lee',
            'email' => 'jamie@example.test',
            'phone' => '+639171234567',
            'password' => 'A-strong-password!9',
            'password_confirmation' => 'A-strong-password!9',
        ];

        $this->postJson('/portal/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_of_birth', 'address', 'gender', 'marital_status']);

        $this->postJson('/portal/register', [
            ...$payload,
            'date_of_birth' => now()->addDay()->toDateString(),
            'address' => '123 Example Street, Makati City',
            'gender' => 'not-listed',
            'marital_status' => 'Divorced',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_of_birth', 'gender', 'marital_status']);

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
        $this->approvedTransaction('deposit', '5000.25')->assertOk()->assertJsonPath('wallet.balance', 500025);
        $this->approvedTransaction('deposit', '5000.10')->assertOk()->assertJsonPath('wallet.balance', 1000035);
        $this->approvedTransaction('withdraw', '100.30')->assertOk()->assertJsonPath('wallet.balance', 990005);
        $this->assertDatabaseHas('wallet_transactions', ['amount_cents' => 10030, 'balance_after_cents' => 990005]);
    }

    public function test_member_can_mark_all_wallet_activity_as_read_and_new_activity_remains_unread(): void
    {
        $member = $this->member();
        app(\App\Services\WalletService::class)->transact($member, 'deposit', '100', null, (string) Str::uuid());
        app(\App\Services\WalletRequestService::class)->submit(
            $member,
            'deposit',
            '5000',
            (string) Str::uuid(),
            'Bank deposit',
            'bpi'
        );

        $this->actingAs($member)->getJson('/portal/state')
            ->assertOk()
            ->assertJsonPath('wallet.unread_notifications', 2)
            ->assertJsonPath('transactions.0.read_at', null)
            ->assertJsonPath('wallet_requests.0.read_at', null);

        $response = $this->postJson('/portal/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('wallet.unread_notifications', 0);
        $this->assertNotNull($response->json('transactions.0.read_at'));
        $this->assertNotNull($response->json('wallet_requests.0.read_at'));

        app(\App\Services\WalletService::class)->transact($member, 'deposit', '100', null, (string) Str::uuid());
        $this->getJson('/portal/state')->assertOk()->assertJsonPath('wallet.unread_notifications', 1);
    }

    public function test_overspending_fails_without_changing_wallet_or_ledger(): void
    {
        $this->actingAs($this->member());
        $this->approvedTransaction('deposit', '5000')->assertOk();
        $this->approvedTransaction('withdraw', '5000.01')->assertUnprocessable();
        $this->approvedTransaction('invest', '5000.01', 'prime')->assertUnprocessable();
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('investment_allocations', 0);
        $this->assertSame(500000, Wallet::first()->balance_cents);
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
        $investment = $this->approvedTransaction('invest', '5000.25', 'select')->assertOk()
            ->assertJsonPath('wallet.balance', 499975)->assertJsonPath('wallet.invested', 500025)
            ->assertJsonPath('wallet.earnings', 0)->assertJsonPath('investments.0.status', 'active')
            ->assertJsonPath('investments.0.term_days', 160)->assertJsonPath('wallet.daily_earnings', 3000)
            ->assertJsonPath('completed_transaction.amount', 500025)
            ->assertJsonPath('completed_transaction.description', 'Mom’s Select allocation')
            ->assertJsonPath('completed_transaction.status', 'completed')
            ->assertJsonStructure(['completed_transaction' => ['reference', 'created_at']]);
        $this->assertSame($investment->json('transactions.0.id'), $investment->json('completed_transaction.reference'));
        $this->assertSame('0.006000', InvestmentAllocation::first()->daily_interest_rate);
        $this->travel(3)->days();
        $this->getJson('/portal/state')->assertOk()
            ->assertJsonPath('wallet.earnings', 9000)
            ->assertJsonPath('wallet.balance', 508975)
            ->assertJsonPath('transactions.0.type', 'interest')
            ->assertJsonStructure(['transactions' => [['created_at']]]);
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
        $this->approvedTransaction('deposit', '5000')->assertOk();
        $this->withHeader('X-CSRF-TOKEN', $before);
        $this->approvedTransaction('deposit', '5000')->assertStatus(419);
    }
}
