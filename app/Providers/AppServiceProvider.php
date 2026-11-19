<?php
namespace App\Providers;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}
    public function boot(): void
    {
        RateLimiter::for('portal-login', fn (Request $request) => [
            Limit::perMinute(30)->by('portal-ip:'.$request->ip()),
            Limit::perMinute(5)->by('portal-login:'.strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);
    }
}
