<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Exceptions\GuildException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'admin.identity' => \App\Http\Middleware\EnsureAdminIdentity::class,
            'user.identity' => \App\Http\Middleware\EnsureUserIdentity::class,
            'user.auth' => \App\Http\Middleware\EnsureUserAuth::class,
            'user.visit' => \App\Http\Middleware\RecordDailyUserVisit::class,
            'super.admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (GuildException $exception) {
            return response()->json([
                'status' => 1,
                'code' => $exception->httpStatus(),
                'msg' => $exception->getMessage(),
                'data' => $exception->data(),
            ], $exception->httpStatus());
        });
    })->create();
