<?php
// app/Http/Controllers/AuthController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Tampilkan form login (guest only).
     * Kompatibel dengan route GET /login bernama 'login'.
     */
    public function showLoginForm()
    {
        if (Auth::check()) {
            return redirect()->intended(route('dashboard'));
        }
        return view('auth.login'); // resources/views/auth/login.blade.php
    }

    /**
     * Alias/kompatibilitas ke showLoginForm() jika ada yang memanggil showLogin().
     */
    public function showLogin()
    {
        return $this->showLoginForm();
    }

    /**
     * Proses submit login (POST /login).
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');

        if (! Auth::attempt($credentials, $remember)) {
            // Jangan bocorkan field mana yang salah
            throw ValidationException::withMessages([
                'email' => __('Email atau password salah.'),
            ]);
        }

        // Cegah session fixation
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'))
            ->with('ok', 'Welcome back, ' . (Auth::user()->name ?? 'user') . '!');
    }

    /**
     * Logout aman + cegah cache.
     */
    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // (Opsional) bersihkan localStorage yang dipakai dashboard
        $request->session()->flash(
            'js_script',
            "try{localStorage.removeItem('poCurrency');localStorage.removeItem('soTopCustomerCurrency');}catch(e){}"
        );

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }
}
