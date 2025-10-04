<?php

// app/Http/Middleware/Authenticate.php
namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    protected function redirectTo($request): ?string
    {
        return $request->expectsJson() ? null : route('login');
    }
}
