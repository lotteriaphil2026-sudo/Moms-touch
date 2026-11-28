<?php

namespace App\Services;

use App\Jobs\SendWebPushNotification;
use App\Models\{InvestmentAllocation, PushSubscription, User, WalletRequest, WalletTransaction};
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushNotificationService
{
    public function notify(
        int $userId,
        string $message,
        string $url = '/#/wallet',
        string $title = 'Mom’s Touch account update',
        bool $onlyIfNoInvestment = false,
        ?string $investmentReminderDay = null,
        ?string $koreaRaffleReminderDate = null,
    ): bool
    {
        if (! $this->isConfigured() || ! PushSubscription::where('user_id', $userId)->exists()) {
            return false;
        }

        SendWebPushNotification::dispatch(
            $userId,
            $message,
            $url,
            $title,
            $onlyIfNoInvestment,
            $investmentReminderDay,
            $koreaRaffleReminderDate
        )->afterCommit();

        return true;
    }

    public function notifyAdmins(string $message, string $url, string $title): int
    {
        if (! $this->isConfigured()) {
            return 0;
        }

        $adminIds = PushSubscription::query()
            ->whereIn('user_id', User::query()->where('is_admin', true)->select('id'))
            ->distinct()
            ->pluck('user_id');

        $queued = 0;
        foreach ($adminIds as $adminId) {
            if ($this->notify((int) $adminId, $message, $url, $title)) {
                $queued++;
            }
        }

        return $queued;
    }

    public function send(
        int $userId,
        string $message,
        string $url = '/#/wallet',
        string $title = 'Mom’s Touch account update',
        bool $onlyIfNoInvestment = false,
        ?string $investmentReminderDay = null,
        ?string $koreaRaffleReminderDate = null,
    ): void
    {
        if ($koreaRaffleReminderDate !== null
            && ! app(KoreaRaffleService::class)->isEligibleForReminder($userId, $koreaRaffleReminderDate)) {
            return;
        }

        if ($onlyIfNoInvestment) {
            $member = User::find($userId);
            $today = now('Asia/Manila')->toDateString();
            $lastReminderDay = $member?->investment_nudge_sent_at
                ?->timezone('Asia/Manila')
                ->toDateString();

            if (! $member
                || ! $investmentReminderDay
                || $investmentReminderDay !== $today
                || $lastReminderDay !== $investmentReminderDay
                || InvestmentAllocation::where('user_id', $userId)->exists()) {
                return;
            }
        }

        if (! $this->isConfigured()) {
            Log::error('Web Push delivery was skipped because VAPID credentials are not configured.');

            return;
        }

        try {
            $push = new WebPush([
                'VAPID' => [
                    'subject' => config('webpush.vapid.subject'),
                    'publicKey' => config('webpush.vapid.public_key'),
                    'privateKey' => config('webpush.vapid.private_key'),
                ],
            ], ['TTL' => 300], 8, [
                'allow_redirects' => false,
                'connect_timeout' => 3,
            ]);
        } catch (\ErrorException $exception) {
            Log::error('Web Push could not start because the VAPID configuration is invalid.', [
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $badgeCount = WalletTransaction::where('user_id', $userId)->whereNull('read_at')->count()
            + WalletRequest::where('user_id', $userId)->whereNull('read_at')->count();

        $payload = json_encode([
            'title' => $title,
            'body' => $message,
            'url' => $url,
            'count' => max(1, $badgeCount),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $subscriptions = PushSubscription::where('user_id', $userId)->get();
        foreach ($subscriptions as $storedSubscription) {
            try {
                $report = $push->sendOneNotification(Subscription::create([
                    'endpoint' => $storedSubscription->endpoint,
                    'publicKey' => $storedSubscription->public_key,
                    'authToken' => $storedSubscription->auth_token,
                    'contentEncoding' => $storedSubscription->content_encoding,
                ]), $payload, ['TTL' => 300]);
            } catch (\ErrorException|GuzzleException $exception) {
                Log::error('Web Push delivery failed.', [
                    'user_id' => $userId,
                    'subscription_id' => $storedSubscription->id,
                    'error' => str_replace($storedSubscription->endpoint, '[push endpoint]', $exception->getMessage()),
                ]);

                continue;
            }

            if ($report->isSubscriptionExpired()) {
                $storedSubscription->delete();
            }

            if (! $report->isSuccess()) {
                Log::warning('Web Push provider rejected a notification.', [
                    'user_id' => $userId,
                    'subscription_id' => $storedSubscription->id,
                    'reason' => str_replace($storedSubscription->endpoint, '[push endpoint]', $report->getReason()),
                ]);
            }
        }
    }

    private function isConfigured(): bool
    {
        return filled(config('webpush.vapid.subject'))
            && filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }
}
