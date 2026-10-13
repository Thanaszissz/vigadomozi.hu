<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => \App\Http\Middleware\RequirePermission::class,
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);
        // Ha a TRUSTED_PROXIES (vesszővel elválasztott IP-lista) meg van adva, csak azok fejléceit fogadjuk el; egyébként a régi viselkedés marad
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') ? array_map('trim', explode(',', env('TRUSTED_PROXIES'))) : '*');
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);
        $middleware->append(\App\Http\Middleware\CompressResponse::class);
        $middleware->api(append: [\App\Http\Middleware\RunDueBackups::class, \App\Http\Middleware\RunDueNavReports::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
