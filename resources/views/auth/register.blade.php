<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Create Account - {{ config('app.name', 'Monitoring SO') }}</title>

    {{-- Google Fonts: Poppins --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body {
            font-family: 'Poppins', sans-serif;
        }
    </style>
</head>

{{-- Latar belakang yang konsisten dengan halaman login --}}

<body class="bg-gradient-to-br from-green-50 to-emerald-100 text-gray-800 antialiased">

    {{-- Konten utama dipusatkan di tengah layar --}}
    <div class="min-h-screen flex flex-col items-center justify-center py-12 px-4 sm:px-6 lg:px-8">

        {{-- Judul Aplikasi di atas kartu --}}
        <div class="text-center mb-8">
            <h2 class="text-3xl font-bold text-green-800">
                Outstanding SO Monitoring
            </h2>
            <p class="mt-1 text-gray-600">
                PT Kayu Mabel Indonesia
            </p>
        </div>

        {{-- Kartu Form Registrasi Tunggal --}}
        <div class="w-full max-w-lg mx-auto bg-white/90 backdrop-blur-sm shadow-2xl rounded-2xl overflow-hidden border border-green-200">
            <div class="p-8 sm:p-12">
                {{-- Judul dan subtitle form --}}
                <div class="text-center mb-8">
                    <h1 class="text-3xl font-bold text-green-800">Create Your Account</h1>
                    <p class="mt-2 text-gray-600">Join us and start monitoring your sales orders.</p>
                </div>

                <form method="POST" action="{{ route('register') }}" class="space-y-5">
                    @csrf

                    {{-- Input Name --}}
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">Full Name</label>
                        <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name"
                            class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-green-600 focus:ring-2 focus:ring-green-300 transition">
                        @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Input Email --}}
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email"
                            class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-green-600 focus:ring-2 focus:ring-green-300 transition">
                        @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Input Password --}}
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                        <input id="password" type="password" name="password" required autocomplete="new-password"
                            class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-green-600 focus:ring-2 focus:ring-green-300 transition">
                        @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Input Confirm Password --}}
                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Confirm Password</label>
                        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                            class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-green-600 focus:ring-2 focus:ring-green-300 transition">
                    </div>

                    {{-- Tombol Submit --}}
                    <div>
                        <button type="submit"
                            class="w-full py-3 px-4 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg shadow-md hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-all duration-300 transform hover:-translate-y-1">
                            Create Account
                        </button>
                    </div>
                </form>

                <p class="mt-8 text-center text-sm text-gray-600">
                    Already have an account?
                    <a href="{{ route('login') }}" class="font-semibold text-green-700 hover:text-green-900 transition">
                        Sign In
                    </a>
                </p>
            </div>
        </div>

        {{-- Footer yang konsisten --}}
        <footer class="mt-12 text-center text-sm text-gray-600">
            <strong>PT Kayu Mabel Indonesia</strong> &copy; {{ date('Y') }}
        </footer>
    </div>

</body>

</html>