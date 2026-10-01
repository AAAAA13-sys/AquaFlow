<?php

use App\Http\Middleware\EnsureUserIsOwner;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'owner' => EnsureUserIsOwner::class,
        ]);

        // Guests hitting a protected page go to the correct login screen.
        $middleware->redirectGuestsTo(
            fn (Request $request): string => $request->is('admin*')
                ? route('owner.login')
                : route('cashier.login')
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API clients always receive JSON, even for unauthenticated requests.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
