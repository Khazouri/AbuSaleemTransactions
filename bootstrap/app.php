<?php

use App\Http\Middleware\CheckScreenPermission;
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
    ->withMiddleware(function (Middleware $middleware) {
        // Stage 9 — screen x action permission enforcement, reading
        // screen_role_permissions. Applied per-route as
        // 'screen.permission:<screen_code>,<action>'.
        $middleware->alias([
            'screen.permission' => CheckScreenPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
