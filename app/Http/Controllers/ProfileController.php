<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Tambahkan Flash Message yang berisi JavaScript untuk pembersihan klien
        $request->session()->flash('js_script', '
        // 1. Membersihkan Session Storage
        sessionStorage.clear();
        
        // 2. Membersihkan Local Storage (Opsional, jika Anda menggunakannya)
        // localStorage.clear(); 

        // 3. Menghapus Cookies yang Mungkin Tersisa
        // Catatan: Ini HANYA akan menghapus cookies yang tidak memiliki flag "HttpOnly".
        // Cookies sesi Laravel yang utama sudah dihapus oleh Auth::logout() dan invalidate().
        // Ini berguna untuk menghapus cookies lain (misalnya, "remember me" atau kustom).
        document.cookie.split(";").forEach(function(c) {
            document.cookie = c.replace(/^ +/, "").replace(/=.*/, "=;expires=" + new Date().toUTCString() + ";path=/");
        });
    ');

        return Redirect::to('/');
    }
}
