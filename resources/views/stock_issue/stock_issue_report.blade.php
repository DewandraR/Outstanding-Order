@extends('layouts.app')

@section('title', $title)

@section('content')

    @php
        // Tambahkan import Crypt di sini untuk memastikan fungsinya tersedia di Blade
        use Illuminate\Support\Facades\Crypt;

        // 1. Helper Functions (Dipertahankan)
        $fmtNumber = fn($n, $d = 0) => number_format((float) $n, $d, ',', '.');
        $fmtMoney = function ($value, $currency = 'USD') {
            $n = (float) $value;
            return '$' . number_format($n, 0, '.', ',');
        };

        // 2. LOGIKA BARU UNTUK GROUPING (TABEL 1)
        $customerSummary = $stockData->groupBy('NAME1')->map(function ($group) {
            return [
                'total_qty' => $group->sum('STOCK3'),
                'total_value' => $group->sum('TPRC'),
                'detail_count' => $group->count(),
            ];
        });

        $totalStockQty = $stockData->sum('STOCK3');
        $totalValue = $stockData->sum('TPRC');

        // Data konfigurasi Nav Pills (Dipertahankan)
        $pills = [
            'assy' => ['label' => 'Level ASSY', 'param' => 'assy', 'werks' => $werks],
            'ptg' => ['label' => 'Level PTG', 'param' => 'ptg', 'werks' => $werks],
            'pkg' => ['label' => 'Level PKG', 'param' => 'pkg', 'werks' => $werks],
        ];

        // Fungsi helper untuk membuat rute terenkripsi (Diperbaiki di jawaban sebelumnya)
        // ASUMSI: Route untuk halaman ini bernama 'stock.issue'
        $createEncryptedRoute = function ($params) use ($werks) {
            $q_params = [
                'werks' => $werks ?? '3000',
                'level' => $params['param'],
            ];
            return route('stock.issue', ['q' => Crypt::encrypt($q_params)]);
        };

    @endphp

    {{-- =========================================================
    NAV BAR (Pills: Level ASSY, PTG, PKG)
    ========================================================= --}}
    <div class="card nav-pill-card shadow-sm mb-4">
        <div class="card-body p-2">
            <ul class="nav nav-pills pills-issue p-1 flex-wrap">
                @foreach ($pills as $key => $pill)
                    <li class="nav-item mb-1 me-2">
                        {{-- 🚨 GANTI: pill-issue menjadi pill-level --}}
                        <a class="nav-link pill-level {{ strtolower($level) == $key ? 'active' : '' }}"
                            href="{{ $createEncryptedRoute($pill) }}">
                            {{ $pill['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>


    <div class="header-container">
        <h1 class="title">{{ $title }}</h1>
        <p class="subtitle">Daftar item Stock Issue level **{{ strtoupper($level) }}** di lokasi Semarang.</p>
        <span class="total-items-badge">
            <i class="fas fa-boxes me-2"></i> Total Items: {{ $stockData->count() }}
        </span>
    </div>

    @if ($stockData->isEmpty())
        <div class="report-card shadow-lg p-5">
            <div class="empty-state text-center">
                <i class="fas fa-box-open fa-4x mb-3 text-muted"></i>
                <h5 class="text-muted">Data tidak ditemukan</h5>
                <p>Tidak ada data Stock Issue untuk level **{{ strtoupper($level) }}** saat ini.</p>
            </div>
        </div>
    @else
        {{-- =========================================================
        TABEL 1: CUSTOMER OVERVIEW (RINGKASAN)
        ========================================================= --}}
        <div class="card yz-card shadow-sm mb-4">
            <div class="card-body p-0 p-md-2">
                <div class="p-3 mx-md-3 mt-md-3 yz-main-title-wrapper">
                    <h5 class="yz-table-title mb-0">
                        <i class="fas fa-users me-2"></i>
                        Ringkasan Stok Berdasarkan Customer
                    </h5>
                </div>

                <div class="yz-customer-list px-md-3 pt-3">
                    <div class="d-grid gap-0 mb-4" id="customer-list-container">
                        @foreach ($customerSummary as $customerName => $summary)
                            @php
                                $kid = 'krow_' . Str::slug($customerName);
                            @endphp
                            {{-- Customer Card (Level 1) --}}
                            <div class="yz-customer-card" data-kid="{{ $kid }}"
                                title="Klik untuk melihat detail item">
                                <div class="d-flex align-items-center justify-content-between p-3">

                                    {{-- KIRI: Customer Name & Caret --}}
                                    <div class="d-flex align-items-center flex-grow-1 me-3">
                                        <span class="kunnr-caret me-3"><i class="fas fa-chevron-right"></i></span>
                                        <div class="customer-info">
                                            <div class="fw-bold fs-5 text-truncate">{{ $customerName }}</div>
                                            <div class="metric-label text-muted small">{{ $summary['detail_count'] }} Item
                                                Detail</div>
                                        </div>
                                    </div>

                                    {{-- KANAN: Metrik & Nilai --}}
                                    <div id="metric-columns"
                                        class="d-flex align-items-center text-center flex-wrap flex-md-nowrap">

                                        {{-- Total Stock Qty --}}
                                        <div class="metric-box mx-4" style="min-width: 100px;">
                                            <div class="metric-value fs-4 fw-bold text-primary text-end">
                                                {{ $fmtNumber($summary['total_qty']) }}
                                            </div>
                                            <div class="metric-label">Total Qty</div>
                                        </div>

                                        {{-- Total Stock Value --}}
                                        <div class="metric-box mx-4 text-end" style="min-width: 180px;">
                                            {{-- Karena mata uang di Stock Issue hanya USD, kita bisa langsung panggil fmtMoney --}}
                                            <div class="metric-value fs-4 fw-bold text-dark">
                                                {{ $fmtMoney($summary['total_value']) }}</div>
                                            <div class="metric-label">Total Value</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Detail Row (Nested Table Container - Level 2) --}}
                            <div id="{{ $kid }}" class="yz-nest-card" style="display:none;">
                                <div class="yz-nest-wrap">
                                    {{-- Tempat untuk menyisipkan Tabel 2 (Detail Item) --}}
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Global Totals Card (Grand Total) --}}
                    <div class="card shadow-sm yz-global-total-card mb-4">
                        <div class="card-body p-3 d-flex justify-content-between align-items-center flex-wrap">
                            <h6 class="mb-0 text-dark-emphasis"><i class="fas fa-chart-pie me-2"></i>Total Keseluruhan
                            </h6>

                            <div id="footer-metric-columns"
                                class="d-flex align-items-center text-center flex-wrap flex-md-nowrap">

                                {{-- Grand Total Stock Qty --}}
                                <div class="metric-box mx-4"
                                    style="min-width: 100px; border-left: none !important; padding-left: 0 !important;">
                                    <div class="fw-bold fs-4 text-primary text-end">
                                        {{ $fmtNumber($totalStockQty) }}
                                    </div>
                                    <div class="text-end">Total Qty</div>
                                </div>

                                {{-- Grand Total Stock Value --}}
                                <div class="metric-box fs-5 mx-4 text-end" style="min-width: 180px;">
                                    <div class="fw-bold fs-4 text-dark">{{ $fmtMoney($totalValue) }}</div>
                                    <div class="small text-muted">Total Value</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('styles')
    {{-- Memanggil style dari Stock Report agar tampilan konsisten --}}
    <link rel="stylesheet" href="{{ asset('css/dashboard-style.css') }}">
    <style>
        /* Tambahkan style khusus jika diperlukan, misal untuk card issue */
        .yz-customer-card.is-open+.yz-nest-card .yz-nest-wrap {
            /* Pastikan latar belakang nested table kontras */
            background: #fff;
        }

        /* Tambahkan style untuk Level 2 jika diperlukan */
        .yz-nest-wrap .table-wrapper {
            max-height: 50vh;
            /* Batasi tinggi container tabel agar bisa di-scroll */
            overflow-y: auto;
        }

        .title {
            font-size: 2.25rem;
            font-weight: 800;
            color: #1e40af;
            /* Warna biru gelap yang kuat */
            margin-bottom: 0.25rem;
        }

        .subtitle {
            font-size: 1rem;
            color: #6b7280;
            margin-bottom: 0.75rem;
        }

        .total-items-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            background-color: #dbeafe;
            /* Latar belakang biru sangat lembut */
            color: #1e40af;
            font-weight: 600;
            border-radius: 9999px;
            font-size: 0.8rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        :root {
            /* Warna kustom untuk Stock Issue Navigasi (misal: Biru/Ungu) */
            --level-blue: #4f46e5;
            /* Biru Indigo Kuat */
            --level-blue-light: #e0e7ff;
            /* Latar Belakang Sangat Lembut */
            --level-shadow: rgba(79, 70, 229, 0.4);
        }

        .nav-pills .nav-link.pill-level {
            /* Gaya Dasar: Mirip tombol yang terangkat (Elevated) */
            background: #fff;
            color: #4f46e5;
            /* Teks warna level blue */
            border: 1px solid #c7d2fe;
            font-weight: 600;
            border-radius: 0.75rem;
            /* Sudut lebih membulat */
            transition: all 0.2s ease-in-out;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05),
                /* Shadow lembut */
                0 2px 4px -2px rgba(0, 0, 0, 0.05);
            padding: 0.5rem 1.2rem;
        }

        .nav-pills .nav-link.pill-level:hover {
            /* Hover: Angkat sedikit dan ubah border/shadow */
            background: #f8f9ff;
            border-color: #a5b4fc;
            transform: translateY(-2px);
            box-shadow: 0 8px 10px -3px rgba(0, 0, 0, 0.1),
                0 4px 6px -4px rgba(0, 0, 0, 0.1);
        }

        .nav-pills .nav-link.pill-level.active {
            /* Aktif: Warna latar penuh, teks putih, shadow menonjol */
            background: var(--level-blue);
            color: #fff;
            border-color: var(--level-blue);
            transform: translateY(0);
            /* Kembali ke posisi normal */
            box-shadow: 0 4px 10px -2px var(--level-shadow),
                /* Shadow kuat */
                0 0 0 3px var(--level-blue-light);
            /* Ring Light */
        }

        .nav-pills .nav-link.pill-level.active:hover {
            /* Pastikan aktif hover tetap indah */
            filter: brightness(1.05);
        }

        /* Container Card (untuk Pill) sedikit disamarkan karena pill-nya sudah menonjol */
        .card.nav-pill-card {
            background-color: transparent !important;
            box-shadow: none !important;
            border: none !important;
            /* Hapus border card container */
        }

        /* Hilangkan padding default pada card body agar pill menempel ke card parent di blade */
        .card.nav-pill-card .card-body {
            padding: 0 !important;
        }

        /* Container untuk pills itu sendiri harus bersih dari padding */
        .nav-pills.pills-issue.p-1 {
            padding: 0 !important;
        }

        /* ... Sisa Style Kustom Anda ... */
        .yz-customer-card.is-open+.yz-nest-card .yz-nest-wrap {
            /* Pastikan latar belakang nested table kontras */
            background: #fff;
        }

        /* Tambahkan style untuk Level 2 jika diperlukan */
        .yz-nest-wrap .table-wrapper {
            max-height: 50vh;
            /* Batasi tinggi container tabel agar bisa di-scroll */
            overflow-y: auto;
        }

        .title {
            font-size: 2.25rem;
            font-weight: 800;
            color: #1e40af;
            /* Warna biru gelap yang kuat */
            margin-bottom: 0.25rem;
        }

        .subtitle {
            font-size: 1rem;
            color: #6b7280;
            margin-bottom: 0.75rem;
        }

        .total-items-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            background-color: #dbeafe;
            /* Latar belakang biru sangat lembut */
            color: #1e40af;
            font-weight: 600;
            border-radius: 9999px;
            font-size: 0.8rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
    </style>
@endpush

@push('scripts')
    <script>
        // Gunakan fungsi untuk membuat Tabel 2 (Detail Item) dalam bentuk HTML
        function renderLevel2_Items(rows) {
            if (!rows || rows.length === 0) {
                return `<div class="p-3 text-muted">Tidak ada detail item untuk Customer ini.</div>`;
            }

            const formatNumber = (num, d = 0) => {
                const n = parseFloat(num);
                if (!Number.isFinite(n)) return '';
                return n.toLocaleString('id-ID', {
                    minimumFractionDigits: d,
                    maximumFractionDigits: d
                });
            };
            const formatMoney = (value) => {
                const n = parseFloat(value);
                if (!Number.isFinite(n)) return '';
                return `$${n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
            };

            let html = `<div class="table-wrapper">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 yz-mini">
                        <thead class="yz-header-item">
                            <tr>
                                <th>Sales Order</th>
                                <th>Item</th>
                                <th>Material Finish</th>
                                <th>Description</th>
                                <th class="text-end">Stock On Hand</th>
                                <th class="text-center">Uom</th>
                                <th class="text-end">Total Value</th>
                            </tr>
                        </thead>
                        <tbody>`;

            rows.forEach((item, index) => {
                html += `
                    <tr class="${index % 2 === 0 ? 'odd-row' : 'even-row'}">
                        <td>${item.VBELN ?? ''}</td>
                        <td class="text-center">${item.POSNR ?? ''}</td>
                        <td class="material-col">${item.MATNH ?? ''}</td>
                        <td class="description-col text-start">${item.MAKTXH ?? ''}</td>
                        <td class="qty-col text-end fw-bold">${formatNumber(item.STOCK3)}</td>
                        <td class="text-center">${item.MEINS ?? ''}</td>
                        <td class="value-col text-end fw-bold">${formatMoney(item.TPRC)}</td>
                    </tr>
                `;
            });

            html += `</tbody></table></div></div>`;
            return html;
        }

        document.addEventListener('DOMContentLoaded', () => {
            // Ambil semua data stok mentah yang dikirim dari controller
            const stockData = @json($stockData);

            // Kelompokkan data stok berdasarkan nama pelanggan
            const stockByCustomer = {};
            stockData.forEach(item => {
                const customerName = item.NAME1;
                if (!stockByCustomer[customerName]) {
                    stockByCustomer[customerName] = [];
                }
                stockByCustomer[customerName].push(item);
            });

            const globalFooter = document.querySelector('.yz-global-total-card');
            const customerListContainer = document.getElementById('customer-list-container');

            // ===== Expand/collapse Customer Card (Level 1) =====
            document.querySelectorAll('.yz-customer-card').forEach(row => {
                row.addEventListener('click', async () => {
                    const customerName = row.querySelector('.customer-info .fw-bold')
                        .textContent.trim();
                    const kid = row.dataset.kid;
                    const slot = document.getElementById(kid);
                    const wrap = slot.querySelector('.yz-nest-wrap');

                    const wasOpen = row.classList.contains('is-open');

                    // 1. Exclusive Toggle & Focus Mode — pastikan customer lain ditutup
                    document.querySelectorAll('.yz-customer-card.is-open').forEach(r => {
                        if (r !== row) {
                            r.classList.remove('is-open', 'is-focused');
                            r.querySelector('.kunnr-caret')?.classList.remove('rot');
                        }
                    });

                    // 2. Toggle status kartu saat ini
                    row.classList.toggle('is-open');
                    row.querySelector('.kunnr-caret')?.classList.toggle('rot', !wasOpen);

                    // 3. Keluar/masuk Customer Focus Mode & Global Footer
                    if (!wasOpen) {
                        customerListContainer.classList.add('customer-focus-mode');
                        row.classList.add('is-focused');
                        if (globalFooter) globalFooter.style.display = 'none';

                        // 4. Render data detail (Level 2)
                        wrap.innerHTML = renderLevel2_Items(stockByCustomer[customerName]);
                        slot.style.display = 'block'; // Tampilkan slot
                    } else {
                        customerListContainer.classList.remove('customer-focus-mode');
                        row.classList.remove('is-focused');
                        if (globalFooter) globalFooter.style.display = '';

                        slot.style.display = 'none'; // Sembunyikan slot
                    }
                });
            });

            // Mencegah klik pada metrik box memicu toggle baris, jika Anda menambahkan tombol di masa depan
            document.querySelectorAll('.yz-customer-card #metric-columns').forEach(col => {
                col.addEventListener('click', (e) => {
                    e.stopPropagation();
                });
            });
        });
    </script>
@endpush
