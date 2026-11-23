<?php

namespace App\Services;

use App\Models\InvestmentAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InvestmentReminderService
{
    public function sendIfEligible(User $user): void
    {
        if ($user->is_admin || ! config('portal.financial_operations_enabled')) {
            return;
        }

        DB::transaction(function () use ($user): void {
            $member = User::query()->lockForUpdate()->findOrFail($user->id);
            $reminderDay = now('Asia/Manila')->toDateString();
            $lastReminderDay = $member->investment_nudge_sent_at
                ?->timezone('Asia/Manila')
                ->toDateString();

            if ($lastReminderDay === $reminderDay
                || InvestmentAllocation::where('user_id', $member->id)->exists()) {
                return;
            }

            $queued = app(WebPushNotificationService::class)->notify(
                $member->id,
                'Ready to take a look? Explore the investment plans available in your account.',
                '/#/investments',
                'Explore your investment plans',
                true,
                $reminderDay
            );

            if ($queued) {
                $member->investment_nudge_sent_at = now();
                $member->save();
            }
        }, 3);
    }
}
