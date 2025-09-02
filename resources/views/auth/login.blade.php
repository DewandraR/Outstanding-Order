<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Login - {{ config('app.name', 'YPPR079') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-green-50 text-gray-800 antialiased">

    {{-- Navbar --}}
    <header class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <a href="{{ url('/') }}" class="flex items-center gap-2 font-bold text-green-700">
                
                {{ config('app.name', 'YPPR079') }}
            </a>
        </div>
    </header>

    {{-- Login Form --}}
    <section class="relative py-16">
        <div class="max-w-7xl mx-auto px-6 grid grid-cols-1 lg:grid-cols-2 gap-12 items-stretch">

            {{-- Card Logo --}}
            <div class="bg-white rounded-2xl shadow-lg border border-green-200 flex items-center justify-center p-8">
                <img src="{{ asset('images/KMI.png') }}"
                    alt="Logo PT Kayu Mabel"
                    class="max-h-72 w-auto object-contain">
            </div>

            {{-- Card Form --}}
            <div class="bg-white rounded-2xl shadow-lg border border-green-200 p-8 flex flex-col justify-center">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-2xl font-bold text-green-800">Masuk ke Akun</h1>
                    <a href="{{ url('/') }}"
                        class="px-3 py-2 text-sm font-semibold bg-green-100 text-green-700 rounded-lg hover:bg-green-200 transition">
                        ⬅ Kembali
                    </a>
                </div>

                <form method="POST" action="{{ route('login') }}" class="space-y-5">
                    @csrf
                    {{-- Email --}}
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                        <input id="email" type="email" name="email" required autofocus
                            class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-green-600 focus:ring-green-600">
                    </div>

                    {{-- Password --}}
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                        <input id="password" type="password" name="password" required
                            class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-green-600 focus:ring-green-600">
                    </div>

                    {{-- Remember & Forgot --}}
                    <div class="flex items-center justify-between">
                        <label class="flex items-center text-sm text-gray-600">
                            <input type="checkbox" name="remember" class="rounded text-green-600">
                            <span class="ml-2">Ingat saya</span>
                        </label>
                        @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}" class="text-sm font-medium text-green-700 hover:text-green-900">
                            Lupa Password?
                        </a>
                        @endif
                    </div>

                    {{-- Submit --}}
                    <div>
                        <button type="submit"
                            class="w-full py-3 px-4 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg shadow">
                            Masuk
                        </button>
                    </div>
                </form>

                @if (Route::has('register'))
                <p class="mt-6 text-center text-sm text-gray-600">
                    Belum punya akun?
                    <a href="{{ route('register') }}" class="font-semibold text-green-700 hover:text-green-900">
                        Daftar Gratis
                    </a>
                </p>
                @endif
            </div>
        </div>
    </section>

    {{-- Footer --}}
    <footer class="bg-white border-t border-green-200 mt-16">
        <div class="max-w-7xl mx-auto px-6 py-8 text-center text-sm text-gray-600">
            © {{ now()->year }} {{ config('app.name') }}.
        </div>
    </footer>

</body>

</html>