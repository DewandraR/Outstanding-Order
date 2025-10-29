<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mode Pemeliharaan (Error 503) - {{ config('app.name', 'Laravel') }}</title>

    {{-- Fonts & CSS vendor (Mengambil dari app.blade.php Anda) --}}
    <link rel="stylesheet" href="{{ asset('vendor/fonts/poppins/poppins.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">

    <style>
        /* CSS Khusus untuk Halaman Error 503/Maintenance */
        html,
        body {
            height: 100%;
            /* Warna latar belakang: Biru tua yang menenangkan (Deep Corporate Blue) */
            background: linear-gradient(135deg, #1e40af 0%, #0d9488 100%);
            font-family: 'Poppins', sans-serif;
            color: #ffffff;
        }

        .maintenance-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .maintenance-card {
            max-width: 500px;
            width: 100%;
            padding: 3rem;
            border-radius: 1.5rem;
            /* Border radius lebih besar */
            box-shadow: 0 1.5rem 4rem rgba(0, 0, 0, 0.25);
            /* Shadow lebih dalam */
            background: rgba(255, 255, 255, 0.98);
            /* Kartu semi-transparan putih */
            color: #212529;
            animation: fadeIn 1s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* --- LOGO OVAL (Menggunakan style dari 419) --- */
        .logo-wrapper {
            margin-bottom: 0;
            text-align: center;
        }

        .img-oval-wrapper {
            width: 140px;
            /* Sedikit lebih besar dari 419 */
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
            /* Green Shadow */
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
            color: #0d9488;
            /* Warna hijau KMI */
            line-height: 1;
            margin-top: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .error-title {
            font-size: 2rem;
            font-weight: 700;
            color: #1e40af;
            /* Warna Biru Korporat */
            margin-bottom: 1rem;
        }

        .error-message {
            color: #495057;
            margin-bottom: 2.5rem;
            font-size: 1rem;
            line-height: 1.6;
        }

        .contact-info {
            background-color: #e9f5f5;
            /* Latar belakang lembut */
            border-radius: 0.75rem;
            padding: 1rem;
            border-left: 5px solid #0d9488;
        }

        .contact-info p {
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .contact-info strong {
            color: #0d9488;
        }
    </style>
</head>

<body>
    <div class="maintenance-container">
        <div class="maintenance-card text-center">

            {{-- LOGO OVAL KMI --}}
            <div class="logo-wrapper">
                <div class="img-oval-wrapper">
                    <img src="{{ asset('images/KMI.png') }}" alt="Logo KMI">
                </div>
            </div>

            {{-- KODE ERROR --}}
            <div class="error-code">503</div>

            {{-- JUDUL & PESAN UTAMA --}}
            <h1 class="error-title">Sistem Sedang Dalam Pemeliharaan</h1>

            <p class="error-message">
                <i class="fas fa-tools me-2 text-warning"></i>
                Kami sedang melakukan pembaruan dan optimasi pada sistem kami untuk memberikan layanan yang lebih baik.
                Kami perkirakan proses ini akan selesai dalam waktu singkat.
                Mohon maaf atas ketidaknyamanan yang ditimbulkan.
            </p>

            {{-- INFORMASI KONTAK DARURAT (Opsional) --}}
            <div class="contact-info text-start">
                <p><strong><i class="fas fa-clock me-2"></i> Perkiraan Akses Kembali:</strong> <span
                        class="text-secondary">Segera (Silakan coba refresh beberapa saat lagi).</span></p>
                <p><strong><i class="fas fa-headset me-2"></i> Butuh Bantuan Mendesak?</strong></p>
                <p class="mb-0 ms-4">Hubungi Tim IT
                </p>
            </div>

            <div class="mt-4">
                <small class="text-muted">PT Kayu Mabel Indonesia &copy; {{ date('Y') }}</small>
            </div>
        </div>
    </div>
</body>

</html>
