<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
// ⬇️ Tambahan import untuk handler
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Auth\AuthenticationException;

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
        /**
         * Redirect otomatis saat CSRF token / sesi habis (419)
         * - Form/Blade (non-JSON): redirect ke /login + simpan intended URL
         * - AJAX/JSON: balas 419 agar front-end bisa handle (mis. redirect ke login)
         */
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Sesi Anda telah berakhir. Silakan login kembali.'
                ], 419);
            }

            // Simpan intended supaya setelah login bisa balik ke halaman sebelumnya
            if ($request->method() === 'GET') {
                session()->put('url.intended', url()->current());
            } else {
                session()->put('url.intended', url()->previous());
            }

            return redirect()
                ->route('login')
                ->with('message', 'Sesi Anda telah berakhir. Silakan login kembali.');
        });

        /**
         * (Opsional) Jika ada 401 dari middleware auth untuk non-JSON,
         * paksa redirect ke login agar konsisten.
         */
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect()->route('login');
        });
    })
    ->create();
