<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gradient-to-br from-green-50 to-green-100 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-5xl bg-white shadow-lg rounded-2xl overflow-hidden">
        <div class="p-8 sm:p-10">
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <div class="text-center flex-1">
                    <h2 class="text-2xl font-extrabold text-green-800">Profile</h2>
                    <p class="text-sm text-green-500">Kelola informasi profil Anda</p>
                </div>

                <!-- Tombol Kembali -->
                <a href="{{ route('dashboard') }}"
                    class="ml-4 px-4 py-2 bg-green-600 text-white text-sm font-semibold rounded-lg shadow hover:bg-green-700 transition">
                    ⬅ Kembali
                </a>
            </div>

            <!-- Content -->
            <div class="space-y-6">
                <!-- Update Profile Information -->
                <div class="p-6 bg-gradient-to-r from-green-50 to-green-100 border border-green-200 rounded-lg shadow-md">
                    <h3 class="text-lg font-semibold text-green-700 mb-4">Update Profile Information</h3>
                    <div class="max-w-xl">
                        @include('profile.partials.update-profile-information-form')
                    </div>
                </div>

                <!-- Update Password -->
                <div class="p-6 bg-gradient-to-r from-green-50 to-green-100 border border-green-200 rounded-lg shadow-md">
                    <h3 class="text-lg font-semibold text-green-700 mb-4">Update Password</h3>
                    <div class="max-w-xl">
                        @include('profile.partials.update-password-form')
                    </div>
                </div>

                <!-- Delete User -->
                <div class="p-6 bg-gradient-to-r from-green-50 to-green-100 border border-green-200 rounded-lg shadow-md">
                    <h3 class="text-lg font-semibold text-green-700 mb-4">Delete User</h3>
                    <div class="max-w-xl">
                        @include('profile.partials.delete-user-form')
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="bg-green-50 text-center py-4">
            <p class="text-sm text-green-500">
                Kembali ke
                <a href="{{ route('dashboard') }}" class="text-green-600 font-medium hover:underline">Dashboard</a>
            </p>
        </div>
        <footer class="py-3">
            <div class="container small text-center">
                © {{ date('Y') }} • {{ config('app.name', 'Laravel') }}
            </div>
        </footer>
    </div>
</body>

</html>