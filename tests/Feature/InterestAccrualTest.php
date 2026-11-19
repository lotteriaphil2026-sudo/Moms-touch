<?php
namespace Tests\Feature;
use App\Models\{InvestmentAllocation, User, Wallet, WalletTransaction};
use App\Services\{InterestAccrualService, WalletService};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
class InterestAccrualTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp(); config(['portal.financial_operations_enabled' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-11-17 08:00:00', 'UTC'));
    }
    private function investment(string $plan = 'select', string $amount = '5000'): array
    {
        $user = User::factory()->create(); Wallet::create(['user_id' => $user->id]);
        $wallets = app(WalletService::class);
        $wallets->transact($user, 'deposit', '500000', null, (string) Str::uuid());
        $wallets->transact($user, 'invest', $amount, $plan, (string) Str::uuid());
        return [$user, InvestmentAllocation::where('user_id', $user->id)->first()];
    }
    public function test_all_plan_minimums_rates_terms_and_daily_amounts_match_the_requested_values(): void
    {
        foreach ([['select', '5000', 60, 160, 3000], ['prime', '50000', 70, 130, 35000], ['signature', '250000', 90, 100, 225000]] as [$id, $amount, $bps, $days, $daily]) {
            [$user, $inv] = $this->investment($id, $amount);
            $this->assertSame((int) $amount * 100, config('portal.plans.'.$id.'.minimum_cents'));
            $this->assertSame($bps, $inv->rate_basis_points); $this->assertSame($days, $inv->term_days);
            $this->assertSame($daily, $inv->dailyInterestCents());
            $this->assertTrue($inv->matures_at->equalTo($inv->started_at->addDays($days)));
            $this->assertSame($daily, app(InterestAccrualService::class)->accrue($user, $inv->started_at->addDay()));
        }
    }
    public function test_first_credit_requires_a_full_24_hours_and_repeated_processing_is_idempotent(): void
    {
        [$user, $inv] = $this->investment(); $interest = app(InterestAccrualService::class);
        $this->assertSame(0, $interest->accrue($user, $inv->started_at->addHours(23)->addMinutes(59)->addSeconds(59)));
        $this->assertSame(3000, $interest->accrue($user, $inv->started_at->addDay()));
        $this->assertSame(0, $interest->accrue($user, $inv->started_at->addDay()));
        $this->assertSame(3000, Wallet::where('user_id', $user->id)->first()->earnings_cents);
        $this->assertSame(1, $inv->fresh()->credited_days);
        $this->assertDatabaseCount('wallet_transactions', 3);
    }
    public function test_missed_days_are_credited_without_compounding(): void
    {
        [$user, $inv] = $this->investment(); $interest = app(InterestAccrualService::class);
        $this->assertSame(9000, $interest->accrue($user, $inv->started_at->addDays(3)->addHours(12)));
        $this->assertSame(3000, $interest->accrue($user, $inv->started_at->addDays(4)));
        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertSame(12000, $wallet->earnings_cents); $this->assertSame(49512000, $wallet->balance_cents);
        $this->assertSame(500000, $wallet->invested_cents); $this->assertSame(4, $inv->fresh()->credited_days);
    }
    public function test_each_plan_stops_at_its_term_and_does_not_release_principal(): void
    {
        foreach ([['select','5000',480000],['prime','50000',4550000],['signature','250000',22500000]] as [$id,$amount,$total]) {
            [$user, $inv] = $this->investment($id, $amount); $interest = app(InterestAccrualService::class);
            $this->assertSame($total, $interest->accrue($user, $inv->started_at->addDays(300)));
            $this->assertSame(0, $interest->accrue($user, $inv->started_at->addDays(301)));
            $this->assertSame('completed', $inv->fresh()->status); $this->assertSame($inv->term_days, $inv->fresh()->credited_days);
            $this->assertSame((int) $amount*100, Wallet::where('user_id',$user->id)->first()->invested_cents);
        }
    }
    public function test_existing_investments_keep_their_rate_and_duration_after_configuration_changes(): void
    {
        [$user, $inv] = $this->investment();
        config(['portal.plans.select.daily_rate_bps' => 999, 'portal.plans.select.term_days' => 1]);
        $this->assertSame(6000, app(InterestAccrualService::class)->accrue($user, $inv->started_at->addDays(2)));
        $this->assertSame(60, $inv->fresh()->rate_basis_points); $this->assertSame(160, $inv->fresh()->term_days);
    }
    public function test_daily_interest_rounds_half_up_to_centavos_before_multiplying_days(): void
    {
        [$user, $inv] = $this->investment('select','5000.84');
        $this->assertSame(3001, $inv->dailyInterestCents());
        $this->assertSame(9003, app(InterestAccrualService::class)->accrue($user, $inv->started_at->addDays(3)));
    }
    public function test_accrual_only_touches_the_selected_member_and_is_disabled_outside_simulation(): void
    {
        [$first, $a] = $this->investment(); [$second, $b] = $this->investment();
        $interest = app(InterestAccrualService::class); $interest->accrue($first, $a->started_at->addDay());
        $this->assertSame(0, Wallet::where('user_id', $second->id)->first()->earnings_cents);
        config(['portal.financial_operations_enabled' => false]);
        $this->assertSame(0, $interest->accrue($second, $b->started_at->addDays(2)));
        $this->assertSame(0, $b->fresh()->credited_days);
    }
    public function test_legacy_allocations_without_terms_are_not_retroactively_activated(): void
    {
        [$user, $inv] = $this->investment();
        $inv->update(['status'=>'pending_rate','rate_basis_points'=>null,'term_days'=>null,'started_at'=>null,'matures_at'=>null,'daily_interest_rate'=>null]);
        $this->assertSame(0, app(InterestAccrualService::class)->accrue($user, CarbonImmutable::now()->addDays(300)));
        $this->assertSame('pending_rate', $inv->fresh()->status);
    }
    public function test_server_rejects_client_supplied_rates_and_scheduler_does_not_duplicate_credits(): void
    {
        [$user, $inv] = $this->investment(); $this->actingAs($user);
        $this->postJson('/portal/transactions', ['type'=>'invest','amount'=>'5000','plan'=>'select','rate_basis_points'=>999,'term_days'=>1,'request_id'=>(string)Str::uuid()])
            ->assertOk()->assertJsonPath('investments.0.rate_bps',60)->assertJsonPath('investments.0.term_days',160);
        $this->travel(1)->days();
        $this->artisan('portal:accrue-interest')->assertSuccessful();
        $this->artisan('portal:accrue-interest')->assertSuccessful();
        $this->assertSame(6000, Wallet::where('user_id',$user->id)->first()->earnings_cents);
        $this->assertSame(2, WalletTransaction::where('type','interest')->count());
    }
}
