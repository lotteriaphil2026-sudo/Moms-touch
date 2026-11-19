<?php
namespace App\Console\Commands;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\{DB, Schema};
class CheckProduction extends Command
{
    protected $signature = 'portal:check-production {--config-only : Check settings only; skip database and administrator checks}';
    protected $description = 'Read-only deployment checks; never prints secrets or changes balances';
    public function handle(): int
    {
        $failures = 0;
        $check = function (bool $ok, string $label) use (&$failures): void {
            $this->line(($ok ? 'PASS ' : 'FAIL ').$label);
            if (!$ok) { $failures++; }
        };
        $check(app()->environment('production'), 'APP_ENV is production');
        $check(config('app.debug') === false, 'Debug output is disabled');
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) { $key = base64_decode(substr($key, 7), true) ?: ''; }
        $check(Encrypter::supported($key, config('app.cipher')), 'Application encryption key is valid');
        $url = parse_url((string) config('app.url'));
        $host = strtolower($url['host'] ?? '');
        $realHost = $host !== '' && !in_array($host, ['localhost', '127.0.0.1', '::1']) && !preg_match('/(?:^|\.)(?:example\.(?:com|org|net)|test|invalid|localhost)$/', $host);
        $check(($url['scheme'] ?? '') === 'https' && $realHost && !isset($url['user']) && !isset($url['pass']) && empty($url['query']) && empty($url['fragment']), 'APP_URL uses the actual HTTPS portal address');
        $check(in_array(config('session.driver'), ['database', 'redis'], true), 'Sessions are stored on the server');
        $check(config('session.encrypt') === true && config('session.secure') === true && config('session.http_only') === true && in_array(config('session.same_site'), ['lax', 'strict'], true), 'Session encryption and secure cookie settings are enabled');
        $check(in_array(config('database.default'), ['mysql', 'mariadb', 'pgsql'], true), 'Wallet database supports durable row locking');
        $check(in_array(config('cache.default'), ['database', 'redis'], true), 'Rate limits use a shared durable cache');
        $smtp = config('mail.mailers.smtp', []);
        $mailHost = strtolower((string) ($smtp['host'] ?? ''));
        $from = (string) config('mail.from.address');
        $check(config('mail.default') === 'smtp' && $mailHost !== '' && !in_array($mailHost, ['localhost', '127.0.0.1', 'mailpit']) && !str_contains($mailHost, 'example.') && in_array((int) ($smtp['port'] ?? 0), [465, 587]) && in_array($smtp['scheme'] ?? '', ['smtp', 'smtps'], true) && !empty($smtp['username']) && !empty($smtp['password']) && filter_var($from, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/@(?:.*\.)?(?:example\.(?:com|org|net)|[^@]+\.(?:test|invalid))$/i', $from), 'Authenticated SMTP and a real sender address are configured');
        if ($this->option('config-only')) {
            $this->warn('Settings-only check: database, migrations, and administrator were not verified.');
        } else {
            try {
                DB::connection()->getPdo();
                $tables = ['users', 'wallets', 'wallet_transactions', 'investment_allocations', 'wallet_requests', 'admin_actions', 'franchise_applications', 'password_reset_tokens'];
                if (config('session.driver') === 'database') { $tables[] = 'sessions'; }
                if (config('cache.default') === 'database') { $tables = [...$tables, 'cache', 'cache_locks']; }
                if (config('queue.default') === 'database') { $tables = [...$tables, 'jobs', 'failed_jobs']; }
                $complete = collect($tables)->every(fn ($table) => Schema::hasTable($table));
                $check($complete, 'Database is reachable and required tables exist');
                $migrator = app('migrator');
                $pending = $migrator->repositoryExists() ? array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan()) : ['missing'];
                $check(count($pending) === 0, 'All migrations have been applied');
                $check(Schema::hasColumn('users', 'is_admin') && User::where('is_admin', true)->exists(), 'A privately created administrator exists');
                $check(!User::whereIn('email', ['admin@momstouch.test', 'demo@momstouch.test'])->exists(), 'Legacy sample accounts are absent');
            } catch (\Throwable $exception) {
                $check(false, 'Database verification failed; inspect private server logs and connection settings');
                report($exception);
            }
        }
        if (config('portal.registration_enabled')) { $this->warn('Registration is enabled. Confirm the final operator policies are published.'); }
        if (config('portal.financial_operations_enabled')) { $this->warn('Internal wallet operations are enabled. Verify deposit evidence and external payouts separately.'); }
        else { $this->info('Internal wallet operations are paused.'); }
        $this->line('Also verify HTTPS, mail delivery, backups and restore, cron, and external payment reconciliation on the host.');
        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
