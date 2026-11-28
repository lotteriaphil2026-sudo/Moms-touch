<?php

namespace Tests\Feature;

use App\Jobs\SendWebPushNotification;
use App\Models\{InvestmentAllocation, KoreaRaffleTicket, PushSubscription, User, Wallet};
use App\Services\KoreaRaffleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Artisan, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

class KoreaRaffleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'webpush.vapid.subject' => 'mailto:push@example.test',
            'webpush.vapid.public_key' => 'public',
            'webpush.vapid.private_key' => 'private',
        ]);
    }

    public function test_guest_can_read_only_public_raffle_status_and_cannot_claim(): void
    {
        $this->travelTo(Carbon::parse('2026-11-28 12:00:00', KoreaRaffleService::TIMEZONE));

        $this->getJson('/portal/promotions/korea-raffle')
            ->assertOk()
            ->assertJsonPath('authenticated', false)
            ->assertJsonPath('eligible', false)
            ->assertJsonCount(0, 'tickets')
            ->assertJsonMissingPath('user');
        $this->postJson('/portal/promotions/korea-raffle/claim')->assertUnauthorized();
    }

    public function test_active_member_can_claim_one_ticket_each_day_and_only_sees_their_own_tickets(): void
    {
        $this->travelTo(Carbon::parse('2026-11-28 12:00:00', KoreaRaffleService::TIMEZONE));
        $member = $this->memberWithActiveInvestment();
        $otherMember = $this->memberWithActiveInvestment();

        $firstClaim = $this->actingAs($member)->postJson('/portal/promotions/korea-raffle/claim')
            ->assertCreated()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('eligible', true)
            ->assertJsonPath('claimedToday', true)
            ->assertJsonCount(1, 'tickets')
            ->json('ticket.id');

        $this->postJson('/portal/promotions/korea-raffle/claim')->assertConflict();

        $this->actingAs($otherMember)->getJson('/portal/promotions/korea-raffle')
            ->assertOk()
            ->assertJsonCount(0, 'tickets')
            ->assertJsonPath('claimedToday', false);

        $this->travelTo(Carbon::parse('2026-11-29 12:00:00', KoreaRaffleService::TIMEZONE));
        $this->actingAs($member)->postJson('/portal/promotions/korea-raffle/claim')
            ->assertCreated()
            ->assertJsonCount(2, 'tickets')
            ->assertJsonPath('claimedToday', true);

        $this->assertDatabaseCount('korea_raffle_tickets', 2);
        $this->assertDatabaseHas('korea_raffle_tickets', [
            'user_id' => $member->id,
            'ticket_code' => $firstClaim,
            'claim_date' => '2026-11-28',
        ]);
    }

    public function test_only_members_with_an_active_investment_can_claim(): void
    {
        $this->travelTo(Carbon::parse('2026-11-28 12:00:00', KoreaRaffleService::TIMEZONE));
        $member = User::factory()->create();
        Wallet::create(['user_id' => $member->id]);

        $this->actingAs($member)->getJson('/portal/promotions/korea-raffle')
            ->assertOk()
            ->assertJsonPath('eligible', false)
            ->assertJsonPath('message', 'A verified active investment is required to claim a raffle ticket.');
        $this->postJson('/portal/promotions/korea-raffle/claim')->assertForbidden();
        $this->assertDatabaseCount('korea_raffle_tickets', 0);
    }

    public function test_claims_are_open_through_january_27_but_not_afterwards(): void
    {
        $member = $this->memberWithActiveInvestment();

        $this->travelTo(Carbon::parse('2027-01-27 23:59:00', KoreaRaffleService::TIMEZONE));
        $this->actingAs($member)->postJson('/portal/promotions/korea-raffle/claim')->assertCreated();

        $this->travelTo(Carbon::parse('2027-01-28 00:01:00', KoreaRaffleService::TIMEZONE));
        $this->getJson('/portal/promotions/korea-raffle')
            ->assertOk()
            ->assertJsonPath('phase', 'closed')
            ->assertJsonPath('claimedToday', false);
        $this->postJson('/portal/promotions/korea-raffle/claim')->assertConflict();
        $this->assertDatabaseCount('korea_raffle_tickets', 1);
    }

    public function test_twice_daily_reminders_only_queue_for_subscribed_eligible_members_without_a_ticket_today(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-11-28 10:00:00', KoreaRaffleService::TIMEZONE));

        $stillUnclaimed = $this->memberWithActiveInvestment();
        $claimsAfterMorning = $this->memberWithActiveInvestment();
        $alreadyClaimed = $this->memberWithActiveInvestment();
        $notInvested = User::factory()->create();
        Wallet::create(['user_id' => $notInvested->id]);

        $this->subscribe($stillUnclaimed);
        $this->subscribe($claimsAfterMorning);
        $this->subscribe($alreadyClaimed);
        $this->subscribe($notInvested);
        KoreaRaffleTicket::create([
            'user_id' => $alreadyClaimed->id,
            'ticket_code' => 'MTK-20261128-CLAIMED',
            'claim_date' => '2026-11-28',
        ]);

        Artisan::call('portal:korea-raffle-reminders');
        Queue::assertPushed(SendWebPushNotification::class, 2);
        Queue::assertPushed(SendWebPushNotification::class, fn (SendWebPushNotification $job) => $job->userId === $stillUnclaimed->id
            && $job->url === '/moms-touch-korea-raffle.html'
            && $job->title === 'Your Korea raffle ticket is waiting'
            && $job->koreaRaffleReminderDate === '2026-11-28');
        Queue::assertNotPushed(SendWebPushNotification::class, fn (SendWebPushNotification $job) => $job->userId === $alreadyClaimed->id);
        Queue::assertNotPushed(SendWebPushNotification::class, fn (SendWebPushNotification $job) => $job->userId === $notInvested->id);

        $this->actingAs($claimsAfterMorning)->postJson('/portal/promotions/korea-raffle/claim')->assertCreated();
        $this->travelTo(Carbon::parse('2026-11-28 18:00:00', KoreaRaffleService::TIMEZONE));
        Artisan::call('portal:korea-raffle-reminders');

        Queue::assertPushed(SendWebPushNotification::class, 3);
        Queue::assertPushed(SendWebPushNotification::class, fn (SendWebPushNotification $job) => $job->userId === $claimsAfterMorning->id, 1);
        Queue::assertPushed(SendWebPushNotification::class, fn (SendWebPushNotification $job) => $job->userId === $stillUnclaimed->id, 2);
    }

    public function test_queued_raffle_reminder_is_discarded_if_the_member_claims_before_delivery(): void
    {
        $this->travelTo(Carbon::parse('2026-11-28 10:00:00', KoreaRaffleService::TIMEZONE));
        $member = $this->memberWithActiveInvestment();
        $today = now(KoreaRaffleService::TIMEZONE)->toDateString();

        $this->actingAs($member)->postJson('/portal/promotions/korea-raffle/claim')->assertCreated();

        $job = new SendWebPushNotification(
            $member->id,
            'Reminder',
            '/moms-touch-korea-raffle.html',
            'Your Korea raffle ticket is waiting',
            false,
            null,
            $today
        );
        $job->handle(app(\App\Services\WebPushNotificationService::class));

        $this->assertDatabaseCount('korea_raffle_tickets', 1);
    }

    private function memberWithActiveInvestment(): User
    {
        $member = User::factory()->create();
        Wallet::create(['user_id' => $member->id]);
        InvestmentAllocation::create([
            'reference' => (string) Str::uuid(),
            'user_id' => $member->id,
            'plan' => 'select',
            'amount_cents' => 500000,
            'status' => 'active',
        ]);

        return $member;
    }

    private function subscribe(User $user): void
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/raffle-'.$user->id;
        PushSubscription::create([
            'user_id' => $user->id,
            'endpoint_hash' => hash('sha256', $endpoint),
            'endpoint' => $endpoint,
            'public_key' => str_repeat('A', 87),
            'auth_token' => str_repeat('B', 22),
            'content_encoding' => 'aes128gcm',
        ]);
    }
}
