<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        // optional: route untuk health check
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        /**
         * GLOBAL middleware (opsional):
         * $middleware->use([
         *     \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
         * ]);
         */

        /**
         * WEB group — WAJIB untuk login berbasis session/CSRF.
         * (Jika project-mu sudah punya default, biarkan; ini contoh lengkapnya.)
         */
        $middleware->web([
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        /**
         * API group (throttle + bindings).
         */
        $middleware->api([
            \Illuminate\Routing\Middleware\ThrottleRequests::class . ':api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        /**
         * ALIAS middleware — agar bisa pakai 'auth', 'guest', dst di routes.
         */
        $middleware->alias([
            'auth'          => \App\Http\Middleware\Authenticate::class,
            'guest'         => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'nocache'       => \App\Http\Middleware\NoCache::class,
            'nocache.after' => \App\Http\Middleware\NoCacheAfterLogout::class,
        ]);


        // Jika ingin menambah ke group yang sudah ada:
        // $middleware->appendToGroup('web', [\App\Http\Middleware\Something::class]);
        // $middleware->prependToGroup('api', [\App\Http\Middleware\Something::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
