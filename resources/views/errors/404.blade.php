<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Halaman Tidak Ditemukan (Error 404) - {{ config('app.name', 'Laravel') }}</title>

    {{-- Fonts & CSS vendor (Mengambil dari app.blade.php Anda) --}}
    <link rel="stylesheet" href="{{ asset('vendor/fonts/poppins/poppins.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">

    <style>
        /* CSS Khusus untuk Halaman Error 404 */
        html,
        body {
            height: 100%;
            /* Latar belakang yang cerah, mengacu ke warna dashboard */
            background-color: #ecfdf5;
            font-family: 'Poppins', sans-serif;
            color: #212529;
        }

        .error-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .error-card {
            max-width: 500px;
            width: 100%;
            padding: 3rem;
            border-radius: 1.5rem;
            box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.1);
            background: #ffffff;
            border: 1px solid #d1fae5;
            /* Border hijau muda yang lembut */
        }

        /* --- LOGO OVAL (Konsisten dengan 500/503) --- */
        .logo-wrapper {
            margin-bottom: 0;
            text-align: center;
        }

        .img-oval-wrapper {
            width: 140px;
            height: 140px;
            margin: 0 auto 1.5rem auto;
            border-radius: 50%;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 5px solid #0d9488;
            /* Border KMI Green */
            box-shadow: 0 0.5rem 1rem rgba(13, 148, 136, 0.5);
            background-color: #ffffff;
            padding: 5px;
        }

        .img-oval-wrapper img {
            width: 90%;
            height: 90%;
            object-fit: contain;
            filter: none;
        }

        /* ----------------------------------------------- */

        .error-code {
            font-size: 5.5rem;
            font-weight: 800;
            color: #14b8a6;
            /* Warna Teal/Cyan yang ramah */
            line-height: 1;
            margin-top: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .error-title {
            font-size: 2rem;
            font-weight: 700;
            color: #0f766e;
            /* Warna Teal Tua */
            margin-bottom: 1rem;
        }

        .error-message {
            color: #495057;
            margin-bottom: 2rem;
            font-size: 1rem;
            line-height: 1.6;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-action {
            font-weight: 600;
            border-radius: 0.65rem;
            padding: 0.75rem 1.25rem;
            /* Padding sedikit berbeda agar dua tombol muat */
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            flex: 1 1 auto;
            /* Agar tombol mengisi ruang yang ada */
        }

        /* Tombol Utama: Kembali ke Dashboard */
        .btn-home {
            background-color: #0d9488;
            /* KMI Green */
            border-color: #0d9488;
            color: #fff;
        }

        .btn-home:hover {
            background-color: #0f766e;
            border-color: #0f766e;
            color: #fff;
            transform: translateY(-2px);
        }

        /* Tombol Sekunder: Kembali ke Sebelumnya */
        .btn-back {
            background-color: #f8fafc;
            /* Latar belakang sangat terang */
            border: 1px solid #d1d5db;
            /* Border abu-abu lembut */
            color: #4b5563;
            /* Teks abu-abu tua */
            box-shadow: none;
        }

        .btn-back:hover {
            background-color: #e5e7eb;
            color: #1f2937;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>

<body>
    <div class="error-container">
        <div class="error-card text-center">

            {{-- LOGO OVAL KMI --}}
            <div class="logo-wrapper">
                <div class="img-oval-wrapper">
                    <img src="{{ asset('images/KMI.png') }}" alt="Logo KMI">
                </div>
            </div>

            {{-- KODE ERROR --}}
            <div class="error-code">404</div>

            {{-- JUDUL & PESAN UTAMA --}}
            <h1 class="error-title">Halaman Tidak Ditemukan</h1>

            <p class="error-message">
                <i class="fas fa-route me-2"></i>
                Alamat yang Anda tuju sepertinya tidak ada atau telah dipindahkan.
                Mohon periksa kembali tautan yang Anda masukkan.
            </p>

            {{-- TOMBOL AKSI --}}
            <div class="action-buttons">
                {{-- Tombol Utama: Ke Dashboard (Paling aman) --}}
                <a href="{{ route('dashboard') }}" class="btn btn-action btn-home">
                    <i class="fas fa-home me-2"></i> Ke Dashboard Utama
                </a>

                {{-- Tombol Sekunder: Kembali ke Sebelumnya --}}
                <button onclick="window.history.back()" class="btn btn-action btn-back">
                    <i class="fas fa-arrow-left me-2"></i> Kembali ke Sebelumnya
                </button>
            </div>

            <div class="mt-4">
                <small class="text-muted">PT Kayu Mabel Indonesia &copy; {{ date('Y') }}</small>
            </div>
        </div>
    </div>

    <script>
        // Fungsi JS untuk tombol kembali
        // Tidak diperlukan kode tambahan yang kompleks karena tombol sudah menggunakan history.back()
    </script>
</body>

</html>
