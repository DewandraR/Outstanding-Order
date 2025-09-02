<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register - {{ config('app.name', 'OSO (Out Standing Order)') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-green-50 text-gray-800 antialiased">

    {{-- Navbar --}}
    <header class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <a href="{{ url('/') }}" class="flex items-center gap-2 font-bold text-green-700">
                {{ config('app.name', 'OSO (Out Standing Order)') }}
            </a>
        </div>
    </header>

    {{-- Register Form --}}
    <section class="relative py-16">
        <div class="max-w-md mx-auto px-6">
            <div class="bg-white rounded-2xl shadow-lg border border-green-200 p-8">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-2xl font-bold text-green-800">Daftar Akun</h1>
                    <a href="{{ url('/') }}"
                        class="px-3 py-2 text-sm font-semibold bg-green-100 text-green-700 rounded-lg hover:bg-green-200 transition">
                        ⬅ Kembali
                    </a>
                </div>

                <form method="POST" action="{{ route('register') }}" class="space-y-5">
                    @csrf

                    {{-- Name --}}
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">Nama</label>
                        <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus
                            class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-green-600 focus:ring-green-600">
                        @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Email --}}
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required
                            class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-green-600 focus:ring-green-600">
                        @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Password --}}
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                        <input id="password" type="password" name="password" required
                            class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-green-600 focus:ring-green-600">
                        @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Confirm Password --}}
                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Konfirmasi Password</label>
                        <input id="password_confirmation" type="password" name="password_confirmation" required
                            class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-green-600 focus:ring-green-600">
                    </div>

                    {{-- Submit --}}
                    <div>
                        <button type="submit"
                            class="w-full py-3 px-4 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg shadow">
                            Daftar
                        </button>
                    </div>
                </form>

                <p class="mt-6 text-center text-sm text-gray-600">
                    Sudah punya akun?
                    <a href="{{ route('login') }}" class="font-semibold text-green-700 hover:text-green-900">
                        Masuk
                    </a>
                </p>
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