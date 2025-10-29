<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sesi Kedaluwarsa (Error 419) - {{ config('app.name', 'Laravel') }}</title>

    {{-- Fonts & CSS vendor (Mengambil dari app.blade.php Anda) --}}
    <link rel="stylesheet" href="{{ asset('vendor/fonts/poppins/poppins.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">

    <style>
        /* CSS Khusus untuk Halaman Error 419 */
        html,
        body {
            height: 100%;
            background-color: #f8f9fa;
            /* Latar belakang abu-abu terang */
            font-family: 'Poppins', sans-serif;
        }

        .error-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .error-card {
            max-width: 450px;
            width: 100%;
            padding: 2.5rem;
            border-radius: 1.25rem;
            /* Border radius besar */
            box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.15);
            /* Shadow lebih menonjol */
            border: 1px solid #e0e7ff;
            /* Border lembut dari warna dashboard Anda */
            background: #ffffff;
        }

        .logo-wrapper {
            margin-bottom: 2rem;
            text-align: center;
        }

        .logo-wrapper img {
            max-width: 100px;
            /* Ukuran yang pas untuk logo */
            height: auto;
            filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.1));
        }

        .error-code {
            font-size: 5rem;
            font-weight: 800;
            color: #dc3545;
            /* Merah untuk Error/Danger */
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .error-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #212529;
            margin-bottom: 1rem;
        }

        .error-message {
            color: #6c757d;
            margin-bottom: 2rem;
            font-size: 0.95rem;
        }

        .btn-kmi {
            /* Menggunakan warna utama dari KMI (hijau) yang sudah ada di dashboard-style.css */
            background-color: #0d9488;
            /* Teal/Hijau tua */
            border-color: #0d9488;
            color: #fff;
            font-weight: 600;
            border-radius: 0.65rem;
            padding: 0.75rem 1.5rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(13, 148, 136, 0.3);
        }

        .btn-kmi:hover {
            background-color: #0f766e;
            border-color: #0f766e;
            color: #fff;
            transform: translateY(-2px);
        }

        .btn-kmi:active,
        .btn-kmi:focus {
            box-shadow: 0 0 0 0.25rem rgba(13, 148, 136, 0.5);
        }

        .img-oval-wrapper {
            width: 120px;
            /* Lebar total wrapper */
            height: 120px;
            /* Tinggi total wrapper, buat sama untuk lingkaran */
            margin: 0 auto 1.5rem auto;
            /* Atur margin di sini untuk memposisikan */
            border-radius: 50%;
            /* Membuat bentuk lingkaran */
            overflow: hidden;
            /* Penting untuk menyembunyikan bagian gambar yang keluar dari lingkaran */
            display: flex;
            /* Untuk memusatkan gambar di dalam wrapper */
            align-items: center;
            /* Pusat vertikal */
            justify-content: center;
            /* Pusat horizontal */
            border: 4px solid #0d9488;
            /* Border dengan warna KMI */
            box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.1);
            /* Tambahkan shadow ringan pada border */
            background-color: #ffffff;
            /* Latar belakang jika gambar tidak menutupi penuh */
            padding: 5px;
            /* Sedikit padding di dalam border jika diperlukan */
        }

        .img-oval-wrapper img {
            width: 90%;
            /* Sesuaikan ukuran gambar di dalam lingkaran */
            height: 90%;
            /* Sesuaikan ukuran gambar di dalam lingkaran */
            object-fit: contain;
            /* Memastikan gambar tidak terpotong dan tetap proporsional */
            filter: none;
            /* Hapus filter drop-shadow sebelumnya jika ada di logo-wrapper img */
            border-radius: 50%;
            /* Pastikan gambar juga memiliki sedikit kelengkungan jika perlu */
        }

        /* Sesuaikan margin-bottom pada logo-wrapper jika perlu, atau hapus jika sudah di img-oval-wrapper */
        .logo-wrapper {
            margin-bottom: 0;
            /* Ubah ini jika Anda telah mengatur margin di img-oval-wrapper */
            /* text-align: center; (ini masih bisa dipertahankan) */
        }

        /* Sesuaikan ukuran font error-code agar tidak terlalu berdekatan dengan logo */
        .error-code {
            margin-top: 1.5rem;
            /* Tambahkan sedikit jarak dari logo */
            margin-bottom: 0.5rem;
        }
    </style>
</head>

<body>
    <div class="error-container">
        <div class="error-card text-center">

            {{-- LOGO PERUSAHAAN --}}
            <div class="logo-wrapper">
                <div class="img-oval-wrapper"> {{-- Tambahkan wrapper baru --}}
                    <img src="{{ asset('images/KMI.png') }}" alt="Logo KMI">
                </div>
            </div>

            {{-- KODE ERROR --}}
            <div class="error-code">419</div>

            {{-- JUDUL & PESAN --}}
            <h1 class="error-title">Sesi Kedaluwarsa</h1>
            <p class="error-message">
                Kami mohon maaf, sesi Anda telah kedaluwarsa karena tidak ada aktivitas dalam waktu lama.
                Untuk melanjutkan, silakan masuk kembali ke sistem.
            </p>

            {{-- TOMBOL AKSI --}}
            <a href="{{ route('login') }}" class="btn btn-kmi w-100">
                <i class="fas fa-sign-in-alt me-2"></i> Kembali ke Halaman Login
            </a>

            <div class="mt-3">
                <small class="text-muted">PT Kayu Mabel Indonesia &copy; {{ date('Y') }}</small>
            </div>
        </div>
    </div>
</body>

</html>
