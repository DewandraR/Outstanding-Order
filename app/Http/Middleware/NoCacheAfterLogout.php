<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NoCacheAfterLogout
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var \Symfony\Component\HttpFoundation\Response $response */
        $response = $next($request);

        // Jangan cache halaman web mana pun
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
