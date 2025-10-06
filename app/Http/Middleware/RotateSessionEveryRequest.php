<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class RotateSessionEveryRequest
{
    public function handle(Request $request, Closure $next)
    {
        // Biarkan VerifyCsrfToken memverifikasi token yang DIBAWA request ini dulu
        $response = $next($request);

        // === Mulai di sini rotasi untuk REQUEST BERIKUTNYA ===

        // 1) Ganti session ID tapi tetap mempertahankan isi session (user tetap login)
        //    (Jika ingin wipe total setiap kali, ganti ke ->invalidate(); lihat catatan di bawah)
        $request->session()->migrate(true);

        // 2) CSRF token baru untuk next request
        $request->session()->regenerateToken();

        // 3) Sinkronkan cookie XSRF agar JS (fetch/axios) ikut mendapat token baru
        Cookie::queue('XSRF-TOKEN', csrf_token(), config('session.lifetime'));

        return $response;
    }
}
