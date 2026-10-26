<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', health: '/up')
    ->withCommands([\App\Console\Commands\RemixWork::class])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [App\Http\Middleware\HandleInertiaRequests::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {})
    ->create();
