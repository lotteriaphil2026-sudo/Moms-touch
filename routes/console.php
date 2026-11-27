<?php
use App\Models\{MemberSignIn, User};
use App\Services\{InterestAccrualService, InvestmentReminderService};
use Illuminate\Support\Facades\{Artisan, Schedule};
use Minishlink\WebPush\VAPID;

Artisan::command('portal:push-keys', function () {
    try {
        $keys = VAPID::createVapidKeys();
    } catch (\RuntimeException|\ErrorException $exception) {
        $this->error('Unable to generate VAPID keys. Check the PHP OpenSSL configuration: '.$exception->getMessage());

        return 1;
    }

    $this->line('WEB_PUSH_VAPID_PUBLIC_KEY='.$keys['publicKey']);
    $this->line('WEB_PUSH_VAPID_PRIVATE_KEY='.$keys['privateKey']);
    $this->warn('Store the private key securely. Keep this key pair stable after users subscribe.');
})->purpose('Generate VAPID credentials for opt-in phone push notifications');

Artisan::command('portal:accrue-interest', function (InterestAccrualService $interest) {
    if (!config('portal.financial_operations_enabled')) { $this->info('Wallet operations are paused. No interest credited.'); return; }
    $total = 0;
    User::whereIn('id', fn ($query) => $query->select('user_id')->from('investment_allocations')->where('status', 'active'))
        ->chunkById(100, function ($users) use ($interest, &$total) {
            foreach ($users as $user) { $total += $interest->accrue($user); }
        });
    $this->info('Interest credited: '.number_format($total / 100, 2).' PHP.');
})->purpose('Credit completed 24-hour periods for enabled investment plans');
Schedule::command('portal:accrue-interest')->everyMinute()->withoutOverlapping();

Artisan::command('portal:investment-reminders', function (InvestmentReminderService $reminders) {
    if (! config('portal.financial_operations_enabled')) {
        $this->info('Wallet operations are paused. No investment reminders sent.');

        return;
    }

    $checked = 0;
    User::query()
        ->where('is_admin', false)
        ->whereExists(fn ($query) => $query->selectRaw('1')
            ->from('push_subscriptions')
            ->whereColumn('push_subscriptions.user_id', 'users.id'))
        ->whereNotExists(fn ($query) => $query->selectRaw('1')
            ->from('investment_allocations')
            ->whereColumn('investment_allocations.user_id', 'users.id'))
        ->chunkById(100, function ($users) use ($reminders, &$checked): void {
            foreach ($users as $user) {
                $reminders->sendIfEligible($user);
                $checked++;
            }
        });

    $this->info("Checked {$checked} eligible member accounts.");
})->purpose('Send the daily investment reminder to subscribed members without an investment');
Schedule::command('portal:investment-reminders')->dailyAt('10:00')->timezone('Asia/Manila')->withoutOverlapping();

Artisan::command('portal:prune-sign-ins', function () {
    $deleted = MemberSignIn::where('created_at', '<', now()->subDays(30))->delete();
    $this->info("Deleted {$deleted} expired member sign-in records.");
})->purpose('Delete member sign-in metadata older than 30 days');
Schedule::command('portal:prune-sign-ins')->daily()->withoutOverlapping();

Artisan::command('portal:create-admin {email?} {--name=}', function () {
    $email = strtolower(trim($this->argument('email') ?: $this->ask('Admin email address')));
    $name = trim($this->option('name') ?: $this->ask('Admin full name'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || !$name || mb_strlen($name) > 80) {
        $this->error('Enter a valid email and a name of up to 80 characters.'); return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('An account already uses this email. No account was changed.'); return 1;
    }
    $password = $this->secret('Admin password (at least 12 characters)');
    if (!is_string($password) || mb_strlen($password) < 12 || mb_strlen($password) > 128 || $password !== $this->secret('Confirm admin password')) {
        $this->error('Passwords must match and contain 12–128 characters.'); return 1;
    }
    \Illuminate\Support\Facades\DB::transaction(function () use ($email, $name, $password) {
        $user = User::create(['email' => $email, 'name' => $name, 'password' => \Illuminate\Support\Facades\Hash::make($password)]);
        $user->forceFill(['is_admin' => true])->save();
        \App\Models\Wallet::create(['user_id' => $user->id]);
    });
    $this->info('Administrator created. Sign in through the portal login page.');
    return 0;
})->purpose('Create a new administrator securely; password is entered privately at the console');
