<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Judul diubah menjadi lebih standar --}}
    <title>Sign In - {{ config('app.name', 'Monitoring SO') }}</title>

    {{-- Google Fonts: Poppins (opsional, tapi disarankan untuk konsistensi) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        /* Menambahkan font Poppins ke body jika belum ada di app.css */
        body {
            font-family: 'Poppins', sans-serif;
        }
    </style>
</head>

{{-- Latar belakang dibuat lebih menarik dengan gradien halus --}}

<body class="bg-gradient-to-br from-green-50 to-emerald-100 text-gray-800 antialiased">

    {{-- HEADER SUDAH DIHILANGKAN --}}

    {{-- Konten utama dipusatkan di tengah layar --}}
    <div class="min-h-screen flex flex-col items-center justify-center py-12 px-4 sm:px-6 lg:px-8">

        {{-- Main card container --}}
        <div class="w-full max-w-5xl mx-auto grid grid-cols-1 lg:grid-cols-2 gap-0 shadow-2xl rounded-2xl overflow-hidden border border-green-200">

            {{-- Kolom Kiri: Ilustrasi / Logo --}}
            <div class="hidden lg:flex flex-col items-center justify-center p-12 bg-white">
                <div class="text-center">
                    <img src="{{ asset('images/KMI.png') }}"
                        alt="Company Logo"
                        class="max-h-64 w-auto object-contain mx-auto transition-transform duration-500 hover:scale-105">
                    <h2 class="mt-8 text-2xl font-bold text-green-800">
                        Outstanding SO Monitoring
                    </h2>
                    <p class="mt-2 text-sm text-gray-600">
                        PT Kayu Mabel Indonesia
                    </p>
                </div>
            </div>

            {{-- Kolom Kanan: Form Login --}}
            <div class="bg-white/80 backdrop-blur-sm p-8 sm:p-12 flex flex-col justify-center">
                {{-- Judul dan subtitle form --}}
                <div class="text-left mb-8">
                    <h1 class="text-3xl font-bold text-green-800">Welcome Back!</h1>
                    <p class="mt-2 text-gray-600">Please sign in to continue to your dashboard.</p>
                </div>

                <form method="POST" action="{{ route('login') }}" class="space-y-6">
                    @csrf
                    {{-- Input Email --}}
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                        <input id="email" type="email" name="email" required autofocus autocomplete="email"
                            class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-green-600 focus:ring-2 focus:ring-green-300 transition">
                    </div>

                    {{-- Input Password --}}
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                        <input id="password" type="password" name="password" required autocomplete="current-password"
                            class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-green-600 focus:ring-2 focus:ring-green-300 transition">
                    </div>

                    {{-- Opsi Remember & Forgot Password --}}
                    <div class="flex items-center justify-between">
                        <label class="flex items-center text-sm text-gray-600">
                            <input type="checkbox" name="remember" class="rounded text-green-600 focus:ring-green-400">
                            <span class="ml-2">Remember me</span>
                        </label>
                        @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}" class="text-sm font-medium text-green-700 hover:text-green-900 transition">
                            Forgot Password?
                        </a>
                        @endif
                    </div>

                    {{-- Tombol Submit --}}
                    <div>
                        <button type="submit"
                            class="w-full py-3 px-4 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg shadow-md hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-all duration-300 transform hover:-translate-y-1">
                            Sign In
                        </button>
                    </div>
                </form>

                @if (Route::has('register'))
                <p class="mt-8 text-center text-sm text-gray-600">
                    Don't have an account?
                    <a href="{{ route('register') }}" class="font-semibold text-green-700 hover:text-green-900 transition">
                        Sign Up for Free
                    </a>
                </p>
                @endif
            </div>
        </div>

        {{-- Footer dipindahkan ke bawah card --}}
        <footer class="mt-12 text-center text-sm text-gray-600">
            <strong>PT Kayu Mabel Indonesia</strong> &copy; {{ date('Y') }}
        </footer>
    </div>

</body>

</html>