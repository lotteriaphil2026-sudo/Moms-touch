<?php

namespace App\Jobs;

use App\Services\WebPushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWebPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(
        public int $userId,
        public string $message,
        public string $url = '/#/wallet',
        public string $title = 'Mom’s Touch account update',
        public bool $onlyIfNoInvestment = false,
        public ?string $investmentReminderDay = null,
    ) {}

    public function handle(WebPushNotificationService $notifications): void
    {
        $notifications->send(
            $this->userId,
            $this->message,
            $this->url,
            $this->title,
            $this->onlyIfNoInvestment,
            $this->investmentReminderDay
        );
    }
}
