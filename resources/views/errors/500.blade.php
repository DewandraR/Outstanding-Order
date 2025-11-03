<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kesalahan Server (Error 500) - {{ config('app.name', 'Laravel') }}</title>

    {{-- Fonts & CSS vendor (Mengambil dari app.blade.php Anda) --}}
    <link rel="stylesheet" href="{{ asset('vendor/fonts/poppins/poppins.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">

    <style>
        /* CSS Khusus untuk Halaman Error 500 */
        html,
        body {
            height: 100%;
            /* Latar belakang yang lebih netral namun modern */
            background-color: #f0f4f7;
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
            border: 1px solid #dee2e6;
        }

        /* --- LOGO OVAL (Replikasi dari 503) --- */
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
            color: #d97706;
            /* Warna Oranye-Cokelat untuk server error */
            line-height: 1;
            margin-top: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .error-title {
            font-size: 2rem;
            font-weight: 700;
            color: #dc2626;
            /* Merah untuk penekanan */
            margin-bottom: 1rem;
        }

        .error-message {
            color: #495057;
            margin-bottom: 2rem;
            font-size: 1rem;
            line-height: 1.6;
            border-left: 4px solid #fcd34d;
            /* Garis kuning/oranye sebagai highlight pesan */
            padding-left: 1rem;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            /* Pastikan tombol tetap responsif */
        }

        .btn-action {
            font-weight: 600;
            border-radius: 0.65rem;
            padding: 0.75rem 1.5rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        /* Tombol Utama: Kembali */
        .btn-back {
            background-color: #4f46e5;
            /* Biru Indigo */
            border-color: #4f46e5;
            color: #fff;
        }

        .btn-back:hover {
            background-color: #3730a3;
            border-color: #3730a3;
            color: #fff;
            transform: translateY(-2px);
        }

        /* Tombol Sekunder: Hubungi (Jika ada) */
        .btn-contact {
            background-color: #0d9488;
            /* KMI Green */
            border-color: #0d9488;
            color: #fff;
        }

        .btn-contact:hover {
            background-color: #0f766e;
            border-color: #0f766e;
            color: #fff;
            transform: translateY(-2px);
        }
    </style>
</head>

<body>
    <div class="error-container">
        <div class="error-card text-center">

            {{-- LOGO OVAL KMI --}}
            <div class="logo-wrapper">
                <div class="img-oval-wrapper">
                    <img src="{{ asset('Images/KMI.png') }}" alt="Logo KMI">
                </div>
            </div>

            {{-- KODE ERROR --}}
            <div class="error-code">500</div>

            {{-- JUDUL & PESAN UTAMA --}}
            <h1 class="error-title">Terjadi Kesalahan Server Internal</h1>

            <p class="error-message text-start">
                <i class="fas fa-exclamation-circle me-2 text-danger"></i>
                Ada masalah teknis yang tidak terduga di pihak server kami.
                <br>
                Solusi sementara: Coba kembali ke halaman sebelumnya atau hubungi tim support jika masalah ini terus
                berlanjut.
            </p>

            {{-- TOMBOL AKSI --}}
            <div class="action-buttons">
                {{-- Tombol untuk Kembali ke Halaman Sebelumnya (Menggunakan History Back) --}}
                <button onclick="window.history.back()" class="btn btn-action btn-back">
                    <i class="fas fa-arrow-left me-2"></i> Kembali ke Halaman Sebelumnya
                </button>
            </div>

            <div class="mt-4">
                <small class="text-muted">PT Kayu Mabel Indonesia &copy; {{ date('Y') }}</small>
            </div>
        </div>
    </div>

    <script>
        // Fungsi JS untuk memastikan tombol kembali berfungsi
        // Meskipun ini adalah halaman error, history.back() adalah cara termudah
        // untuk mencoba kembali ke halaman yang mungkin gagal dimuat.
        // Tidak perlu skrip yang kompleks untuk halaman 500.
        // window.history.back() sudah terikat pada tombol.
    </script>
</body>

</html>
