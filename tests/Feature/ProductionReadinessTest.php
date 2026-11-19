<?php
namespace Tests\Feature;
use App\Models\{User, Wallet};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Notification, Password};
use Tests\TestCase;
class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;
    public function test_registration_is_closed_until_explicitly_enabled(): void
    {
        config(['portal.registration_enabled' => false]);
        $this->postJson('/portal/register', ['name' => 'Member', 'email' => 'new@example.test', 'password' => 'A-strong-password!9', 'password_confirmation' => 'A-strong-password!9'])->assertForbidden();
        $this->assertDatabaseCount('users', 0);$this->assertDatabaseCount('wallets', 0);
    }
    public function test_new_accounts_have_no_cash_hold_principal_earnings_or_sample_history(): void
    {
        config(['portal.registration_enabled' => true, 'portal.financial_operations_enabled' => false]);
        $response=$this->postJson('/portal/register', ['name' => 'Member', 'email' => 'new@example.test', 'password' => 'A-strong-password!9', 'password_confirmation' => 'A-strong-password!9'])->assertOk();
        foreach(['balance','held','invested','earnings','daily_earnings'] as $key)$response->assertJsonPath('wallet.'.$key,0);
        foreach(['transactions','investments','wallet_requests','franchise_applications'] as $key)$response->assertJsonCount(0,$key);
        $this->assertFalse(User::first()->is_admin);
    }
    public function test_deployment_seeding_preserves_existing_balances_and_records(): void
    {
        $member=User::factory()->create();Wallet::create(['user_id'=>$member->id,'balance_cents'=>12345,'held_cents'=>500,'invested_cents'=>70000,'earnings_cents'=>345]);
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->assertDatabaseCount('users',1);$this->assertDatabaseCount('wallets',1);
        $this->assertDatabaseHas('wallets',['user_id'=>$member->id,'balance_cents'=>12345,'held_cents'=>500,'invested_cents'=>70000,'earnings_cents'=>345]);
    }
    public function test_account_responses_cannot_be_cached_and_script_bootstrap_uses_a_nonce(): void
    {
        $response=$this->get('/')->assertOk()->assertHeader('X-Content-Type-Options','nosniff')->assertHeader('X-Frame-Options','DENY');
        $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
        preg_match('/<script nonce="([^"]+)"/',$response->getContent(),$matches);$this->assertNotEmpty($matches[1]);
        $this->assertStringContainsString("'nonce-{$matches[1]}'",$response->headers->get('Content-Security-Policy'));
        $this->getJson('/portal/state')->assertHeader('Pragma','no-cache');
    }
    public function test_failed_reset_mail_delivery_does_not_reveal_known_accounts(): void
    {
        $member=User::factory()->create();Password::shouldReceive('sendResetLink')->once()->andThrow(new \RuntimeException('Mail delivery unavailable'));
        $this->postJson('/portal/forgot-password',['email'=>$member->email])->assertOk()->assertJsonPath('message','If an account exists for this email, a reset link has been requested.');
    }
    public function test_readonly_deployment_check_reports_missing_settings_and_does_not_change_data(): void
    {
        config(['app.debug'=>true,'app.url'=>'http://localhost','mail.default'=>'log']);
        $member=User::factory()->create();Wallet::create(['user_id'=>$member->id,'balance_cents'=>12345]);
        $this->artisan('portal:check-production')->expectsOutputToContain('FAIL APP_ENV is production')->expectsOutputToContain('FAIL Debug output is disabled')->assertExitCode(1);
        $this->assertDatabaseCount('users',1);$this->assertDatabaseHas('wallets',['user_id'=>$member->id,'balance_cents'=>12345]);
    }
    public function test_config_only_check_requires_real_https_secure_sessions_and_smtp(): void
    {
        $this->app->detectEnvironment(fn()=> 'production');
        config(['app.debug'=>false,'app.url'=>'https://members.momstouch.ph','session.driver'=>'database','session.encrypt'=>true,'session.secure'=>true,'session.http_only'=>true,'session.same_site'=>'lax','database.default'=>'mysql','cache.default'=>'database','mail.default'=>'smtp','mail.mailers.smtp.host'=>'smtp.mail-provider.ph','mail.mailers.smtp.port'=>587,'mail.mailers.smtp.scheme'=>'smtp','mail.mailers.smtp.username'=>'configured','mail.mailers.smtp.password'=>'test-only-secret','mail.from.address'=>'members@momstouch.ph']);
        try { $this->artisan('portal:check-production',['--config-only'=>true])->expectsOutputToContain('Settings-only check: database, migrations, and administrator were not verified.')->assertExitCode(0); }
        finally { config(['database.default'=>'sqlite']); }
    }
}
