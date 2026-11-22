<?php

namespace App\Services;

use App\Jobs\SendWebPushNotification;
use App\Models\PushSubscription;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushNotificationService
{
    public function notify(int $userId, string $message): void
    {
        if (! $this->isConfigured() || ! PushSubscription::where('user_id', $userId)->exists()) {
            return;
        }

        SendWebPushNotification::dispatch($userId, $message)->afterCommit();
    }

    public function send(int $userId, string $message): void
    {
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

        $payload = json_encode([
            'title' => 'Mom’s Touch account update',
            'body' => $message,
            'url' => '/#/wallet',
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
