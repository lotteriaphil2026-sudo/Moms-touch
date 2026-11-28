<?php

namespace App\Services;

use App\Models\{InvestmentAllocation, KoreaRaffleTicket, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KoreaRaffleService
{
    public const START_DATE = '2026-11-27';
    public const END_DATE = '2027-01-27';
    public const TIMEZONE = 'Asia/Manila';

    public function status(User $user): array
    {
        $today = now(self::TIMEZONE)->toDateString();
        $tickets = KoreaRaffleTicket::query()
            ->where('user_id', $user->id)
            ->orderByDesc('claim_date')
            ->orderByDesc('id')
            ->get();
        $eligible = $this->isEligible($user);

        return [
            'authenticated' => true,
            'eligible' => $eligible,
            'phase' => $this->phase($today),
            'today' => $today,
            'claimedToday' => $tickets->contains(fn (KoreaRaffleTicket $ticket) => $ticket->claim_date->format('Y-m-d') === $today),
            'message' => $eligible ? null : 'A verified active investment is required to claim a raffle ticket.',
            'tickets' => $tickets->map(fn (KoreaRaffleTicket $ticket) => [
                'id' => $ticket->ticket_code,
                'date' => $ticket->claim_date->format('Y-m-d'),
            ])->values(),
        ];
    }

    public function claim(User $user): KoreaRaffleTicket
    {
        abort_unless($this->isMember($user), 403, 'Raffle tickets are only available to member accounts.');

        $today = now(self::TIMEZONE)->toDateString();
        abort_unless($this->phase($today) === 'open', 409, 'Raffle ticket claims are not open today.');

        return DB::transaction(function () use ($user, $today): KoreaRaffleTicket {
            $member = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->isEligible($member), 403, 'A verified active investment is required to claim a raffle ticket.');
            abort_unless(
                ! KoreaRaffleTicket::query()->where('user_id', $member->id)->where('claim_date', $today)->exists(),
                409,
                'You have already claimed today’s raffle ticket.'
            );

            return KoreaRaffleTicket::create([
                'user_id' => $member->id,
                'ticket_code' => 'MTK-'.$today.'-'.Str::upper(Str::random(8)),
                'claim_date' => $today,
            ]);
        }, 3);
    }

    public function isEligibleForReminder(int $userId, string $reminderDate): bool
    {
        $today = now(self::TIMEZONE)->toDateString();
        if ($reminderDate !== $today || $this->phase($today) !== 'open') {
            return false;
        }

        $user = User::query()->find($userId);

        return $user !== null
            && $this->isEligible($user)
            && ! KoreaRaffleTicket::query()->where('user_id', $userId)->where('claim_date', $today)->exists();
    }

    public function isEligible(User $user): bool
    {
        return $this->isMember($user)
            && InvestmentAllocation::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();
    }

    public function phase(string $date): string
    {
        if ($date < self::START_DATE) {
            return 'before';
        }

        return $date > self::END_DATE ? 'closed' : 'open';
    }

    private function isMember(User $user): bool
    {
        return ! $user->is_admin && ! $user->is_dummy;
    }
}
