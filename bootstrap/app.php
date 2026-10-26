<?php

use App\Http\Middleware\ActiveClient;
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
        $middleware->redirectUsersTo(fn () => route('dashboard.overview'));
        $middleware->web(append: [ActiveClient::class]);

        // Signed provider callbacks have no browser session; authenticity is
        // enforced by the HMAC signature and timestamp in PaymentProvider.
        $middleware->validateCsrfTokens(except: ['payments/webhook/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'code', 'cf-turnstile-response']);
    })->create();
