<?php

namespace Tests\Feature;

use App\Jobs\SendWebPushNotification;
use App\Models\InvestmentAllocation;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalAccount;
use App\Services\InterestAccrualService;
use App\Services\WalletRequestService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Artisan, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'portal.financial_operations_enabled' => true,
            'webpush.vapid.subject' => 'mailto:push@example.test',
            'webpush.vapid.public_key' => 'public',
            'webpush.vapid.private_key' => 'private',
        ]);
    }

    private function subscriptionPayload(string $endpoint = 'https://fcm.googleapis.com/fcm/send/test-endpoint'): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => str_repeat('A', 87),
                'auth' => str_repeat('B', 22),
            ],
        ];
    }

    public function test_subscriptions_are_owned_by_the_signed_in_user_and_reject_untrusted_endpoints(): void
    {
        $member = User::factory()->create();
        $otherMember = User::factory()->create();
        $endpoint = $this->subscriptionPayload()['endpoint'];

        $this->actingAs($member)->postJson('/portal/push/subscriptions', $this->subscriptionPayload())
            ->assertCreated()
            ->assertJsonPath('subscribed', true);
        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $member->id]);

        $this->actingAs($otherMember)->deleteJson('/portal/push/subscriptions', ['endpoint' => $endpoint])
            ->assertOk()
            ->assertJsonPath('removed', false);
        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $member->id]);

        $this->actingAs($member)->deleteJson('/portal/push/subscriptions', ['endpoint' => $endpoint])
            ->assertOk()
            ->assertJsonPath('removed', true);
        $this->assertDatabaseCount('push_subscriptions', 0);

        $this->actingAs($member)->postJson('/portal/push/subscriptions', $this->subscriptionPayload('https://127.0.0.1/internal'))
            ->assertUnprocessable();
        $invalidKey = $this->subscriptionPayload();
        $invalidKey['keys']['p256dh'] = str_repeat('A', 80);
        $this->actingAs($member)->postJson('/portal/push/subscriptions', $invalidKey)
            ->assertUnprocessable();
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_interest_investment_and_request_events_queue_only_after_successful_changes(): void
    {
        Queue::fake();
        $member = User::factory()->create();
        Wallet::create(['user_id' => $member->id, 'balance_cents' => 1000000]);
        PushSubscription::create([
            'user_id' => $member->id,
            'endpoint_hash' => hash('sha256', 'https://fcm.googleapis.com/fcm/send/test-endpoint'),
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
            'public_key' => str_repeat('A', 87),
            'auth_token' => str_repeat('B', 22),
            'content_encoding' => 'aes128gcm',
        ]);

        $investmentRequestId = (string) Str::uuid();
        app(WalletService::class)->transact($member, 'invest', '5000', 'select', $investmentRequestId);
        app(WalletService::class)->transact($member, 'invest', '5000', 'select', $investmentRequestId);
        $investment = InvestmentAllocation::where('user_id', $member->id)->firstOrFail();
        app(InterestAccrualService::class)->accrue($member, $investment->started_at->addDay());

        $requests = app(WalletRequestService::class);
        $deposit = $requests->submit($member, 'deposit', '5000', (string) Str::uuid(), null, 'bpi');
        $admin = User::factory()->create(['is_admin' => true]);
        Wallet::create(['user_id' => $admin->id]);
        $requests->review($admin, $deposit, 'approve', 'Approved');

        $account = WithdrawalAccount::create([
            'user_id' => $member->id,
            'type' => 'bank',
            'provider' => 'BPI',
            'account_holder' => $member->name,
            'account_number' => '1234567890',
        ]);
        $withdrawal = $requests->submit($member, 'withdraw', '100', (string) Str::uuid(), null, null, $account);
        $requests->review($admin, $withdrawal, 'reject', 'Declined');

        Queue::assertPushed(SendWebPushNotification::class, 6);
        Queue::assertPushed(SendWebPushNotification::class, fn (SendWebPushNotification $job) => $job->userId === $member->id
            && str_contains($job->message, 'investment'));
        Queue::assertPushed(SendWebPushNotification::class, fn (SendWebPushNotification $job) => $job->userId === $member->id
            && str_contains($job->message, 'interest'));
        Queue::assertPushed(SendWebPushNotification::class, fn (SendWebPushNotification $job) => str_contains($job->message, 'deposit request was submitted'));
        Queue::assertPushed(SendWebPushNotification::class, fn (SendWebPushNotification $job) => str_contains($job->message, 'deposit request was approved'));
        Queue::assertPushed(SendWebPushNotification::class, fn (SendWebPushNotification $job) => str_contains($job->message, 'withdrawal request was submitted'));
        Queue::assertPushed(SendWebPushNotification::class, fn (SendWebPushNotification $job) => str_contains($job->message, 'withdrawal request was declined'));
    }

    public function test_uninvested_member_gets_one_investment_prompt_per_day_with_an_investment_deep_link(): void
    {
        Queue::fake();
        $member = User::factory()->create();
        Wallet::create(['user_id' => $member->id]);
        PushSubscription::create([
            'user_id' => $member->id,
            'endpoint_hash' => hash('sha256', 'https://fcm.googleapis.com/fcm/send/investment-prompt'),
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/investment-prompt',
            'public_key' => str_repeat('A', 87),
            'auth_token' => str_repeat('B', 22),
            'content_encoding' => 'aes128gcm',
        ]);

        $this->postJson('/portal/login', ['email' => $member->email, 'password' => 'password'])->assertOk();

        Queue::assertPushed(SendWebPushNotification::class, 1);
        Queue::assertPushed(SendWebPushNotification::class, fn (SendWebPushNotification $job) => $job->userId === $member->id
            && $job->url === '/#/investments'
            && $job->title === 'Explore your investment plans'
            && $job->onlyIfNoInvestment
            && str_contains($job->message, 'investment plans'));
        $this->assertNotNull($member->fresh()->investment_nudge_sent_at);

        $this->postJson('/portal/logout')->assertOk();
        $this->postJson('/portal/login', ['email' => $member->email, 'password' => 'password'])->assertOk();
        Queue::assertPushed(SendWebPushNotification::class, 1);

        $this->travel(1)->days();
        $this->postJson('/portal/logout')->assertOk();
        $this->postJson('/portal/login', ['email' => $member->email, 'password' => 'password'])->assertOk();
        Queue::assertPushed(SendWebPushNotification::class, 2);
    }

    public function test_invested_member_does_not_get_an_investment_prompt_when_enabling_push(): void
    {
        Queue::fake();
        $member = User::factory()->create();
        Wallet::create(['user_id' => $member->id, 'balance_cents' => 1000000]);
        app(WalletService::class)->transact($member, 'invest', '5000', 'select', (string) Str::uuid());

        $this->actingAs($member)->postJson('/portal/push/subscriptions', $this->subscriptionPayload())
            ->assertCreated();

        Queue::assertNotPushed(SendWebPushNotification::class, fn (SendWebPushNotification $job) => $job->onlyIfNoInvestment);
        $this->assertNull($member->fresh()->investment_nudge_sent_at);
    }

    public function test_uninvested_member_gets_an_investment_prompt_when_enabling_push(): void
    {
        Queue::fake();
        $member = User::factory()->create();
        Wallet::create(['user_id' => $member->id]);

        $this->actingAs($member)->postJson('/portal/push/subscriptions', $this->subscriptionPayload())
            ->assertCreated();

        Queue::assertPushed(SendWebPushNotification::class, fn (SendWebPushNotification $job) => $job->userId === $member->id
            && $job->url === '/#/investments'
            && $job->onlyIfNoInvestment);
        $this->assertNotNull($member->fresh()->investment_nudge_sent_at);
    }

    public function test_daily_command_sends_once_per_day_only_to_subscribed_uninvested_members(): void
    {
        Queue::fake();
        $uninvested = User::factory()->create();
        Wallet::create(['user_id' => $uninvested->id]);
        $invested = User::factory()->create();
        Wallet::create(['user_id' => $invested->id, 'balance_cents' => 1000000]);
        app(WalletService::class)->transact($invested, 'invest', '5000', 'select', (string) Str::uuid());

        foreach ([$uninvested, $invested] as $index => $member) {
            $endpoint = "https://fcm.googleapis.com/fcm/send/daily-reminder-{$index}";
            PushSubscription::create([
                'user_id' => $member->id,
                'endpoint_hash' => hash('sha256', $endpoint),
                'endpoint' => $endpoint,
                'public_key' => str_repeat('A', 87),
                'auth_token' => str_repeat('B', 22),
                'content_encoding' => 'aes128gcm',
            ]);
        }

        Artisan::call('portal:investment-reminders');
        Queue::assertPushed(SendWebPushNotification::class, 1);
        Queue::assertPushed(SendWebPushNotification::class, fn (SendWebPushNotification $job) => $job->userId === $uninvested->id
            && $job->url === '/#/investments');

        Artisan::call('portal:investment-reminders');
        Queue::assertPushed(SendWebPushNotification::class, 1);

        $this->travel(1)->days();
        Artisan::call('portal:investment-reminders');
        Queue::assertPushed(SendWebPushNotification::class, 2);
    }
}
