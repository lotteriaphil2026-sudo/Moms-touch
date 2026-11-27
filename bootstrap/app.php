<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurePortalResponse::class);
        $middleware->trustHosts(at: fn () => ['^'.preg_quote((string) parse_url(config('app.url'), PHP_URL_HOST), '/').'$'], subdomains: false);
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\EnsureAdministrator::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
