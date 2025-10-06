<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * Cookie yang TIDAK akan dienkripsi.
     *
     * Penting: biarkan XSRF-TOKEN tidak terenkripsi
     * agar bisa dibaca JS (axios/fetch) dari document.cookie.
     */
    protected $except = [
        'XSRF-TOKEN',
    ];
}
