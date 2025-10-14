<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;
use Illuminate\Support\Str; // <-- TAMBAH INI

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        // Helper: normalisasi string untuk mendeteksi "guest" dalam berbagai variasi
        $normalize = function (string $v): string {
            return (string) Str::of($v)
                ->ascii()                        // hilangkan aksen/karakter non ASCII
                ->lower()                        // abaikan kapitalisasi
                ->replaceMatches('/[^a-z0-9]/', '') // buang spasi/tanda baca
                ->toString();
        };

        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, $value, \Closure $fail) use ($normalize) {
                    if (Str::contains($normalize((string) $value), 'guest')) {
                        $fail('Nama tidak boleh mengandung kata "guest".');
                    }
                },
            ],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                'unique:' . User::class,
                function (string $attribute, $value, \Closure $fail) use ($normalize) {
                    // Larang email yang mengandung "guest" di mana pun (local part / domain)
                    if (Str::contains($normalize((string) $value), 'guest')) {
                        $fail('Email tidak boleh mengandung kata "guest".');
                    }
                },
            ],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'password' => Hash::make($request->string('password')),
        ]);

        event(new Registered($user));
        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
