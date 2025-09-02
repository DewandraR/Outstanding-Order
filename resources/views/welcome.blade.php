<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name', 'yppr') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-green-50 text-gray-800 antialiased">

    {{-- Navbar --}}
    <header class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <a href="{{ url('/') }}" class="flex items-center gap-2 font-bold text-green-700">
                {{ config('app.name', 'yppr') }}
            </a>
            <nav class="flex items-center gap-4">
                @auth
                <a href="{{ url('/dashboard') }}" class="text-sm text-green-700 hover:text-green-900 font-semibold">Dashboard</a>
                @else
                <a href="{{ route('login') }}" class="text-sm text-green-700 hover:text-green-900 font-semibold">Log in</a>
                @if (Route::has('register'))
                <a href="{{ route('register') }}" class="px-4 py-2 text-sm font-semibold rounded-lg bg-green-600 text-white hover:bg-green-700 shadow">
                    Register
                </a>
                @endif
                @endauth
            </nav>
        </div>
    </header>

    {{-- Hero Section --}}
    <section class="relative bg-gradient-to-b from-green-100 to-green-50 py-20">
        <div class="max-w-7xl mx-auto px-6 grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
            <div>
                <h1 class="text-4xl font-extrabold text-green-800 sm:text-5xl">
                    Selamat datang di <span class="text-green-600">{{ config('app.name', 'Welcome Page') }}</span>
                </h1>
                <div class="mt-6 flex gap-4">
                    @auth
                    <a href="{{ url('/dashboard') }}" class="px-6 py-3 rounded-lg bg-green-600 text-white font-semibold hover:bg-green-700 shadow">
                        Dashboard
                    </a>
                    @else
                    <a href="{{ route('register') }}" class="px-6 py-3 rounded-lg bg-green-600 text-white font-semibold hover:bg-green-700 shadow">
                        Register
                    </a>
                    <a href="{{ route('login') }}" class="px-6 py-3 rounded-lg border border-green-600 text-green-700 font-semibold hover:bg-green-50">
                        Masuk
                    </a>
                    @endauth
                </div>
            </div>
            <div class="relative">
                <div class="rounded-2xl border border-green-200 bg-white p-6 shadow-lg">
                    <img src="https://images.unsplash.com/photo-1501004318641-b39e6451bec6?auto=format&fit=crop&w=800&q=80"
                        alt="Nature illustration"
                        class="rounded-xl shadow-md">
                </div>
                <div class="absolute -bottom-6 -left-6 bg-green-600 text-white px-4 py-2 rounded-lg shadow">
                    PT. Kayu Mabel
                </div>
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