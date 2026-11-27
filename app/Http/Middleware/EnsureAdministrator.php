<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class EnsureAdministrator
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->is_admin === true, 403, 'Administrator access is required.');
        return $next($request);
    }
}
