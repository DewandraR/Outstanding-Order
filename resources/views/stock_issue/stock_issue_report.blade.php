@extends('layouts.app')

@section('title', $title)

@section('content')

    @php
        use Illuminate\Support\Facades\Crypt;
        use Illuminate\Support\Str;

        // 1. Helper Functions
        $fmtNumber = fn($n, $d = 0) => number_format((float) $n, $d, ',', '.');
        $fmtMoney = function ($value, $currency = 'USD') {
            $n = (float) $value;
            return '$' . number_format($n, 0, '.', ',');
        };
        $formatUom = fn($uom) => strtoupper(trim((string) $uom)) === 'ST' ? 'PC' : (string) $uom;

        // 2. GROUPING (TABEL 1)
        $customerSummary = $stockData->groupBy('NAME1')->map(function ($group) {
            return [
                'total_qty' => $group->sum('STOCK3'),
                'total_value' => $group->sum('TPRC'),
                'detail_count' => $group->count(),
            ];
        });

        $totalStockQty = $stockData->sum('STOCK3');
        $totalValue = $stockData->sum('TPRC');

        // Nav Pills
        $pills = [
            'assy' => ['label' => 'Level ASSY', 'param' => 'assy', 'werks' => $werks],
            'ptg'  => ['label' => 'Level PTG',  'param' => 'ptg',  'werks' => $werks, 'sub_level' => 'wood'], // default sub_level
            'pkg'  => ['label' => 'Level PKG',  'param' => 'pkg',  'werks' => $werks],
        ];

        // Sub-nav PTG
        $ptg_sub_pills = [
            'wood'  => ['label' => 'Wood',  'param' => 'ptg', 'sub_level' => 'wood',  'werks' => $werks],
            'metal' => ['label' => 'Metal', 'param' => 'ptg', 'sub_level' => 'metal', 'werks' => $werks],
        ];

        // Helper route terenkripsi
        $createEncryptedRoute = function ($params) use ($werks) {
            $q_params = [
                'werks'     => $werks ?? '3000',
                'level'     => $params['param'],
                'sub_level' => $params['sub_level'] ?? null,
            ];
            return route('stock.issue', ['q' => Crypt::encrypt($q_params)]);
        };

        // ==========================
        // ✅ URL EXPORT (PDF + EXCEL) mengikuti level/sublevel aktif
        // ==========================
        $current_level = strtolower($level ?? '');

        $current_sub_level = ($current_level === 'ptg')
            ? (strtolower($sub_level ?? '') ?: 'wood')
            : null;

        // jika tidak ada level (akses tanpa q), export disabled
        $exportPdfUrl = $current_level
            ? route('stock.issue.exportPdf', [
                'q' => Crypt::encrypt([
                    'werks'     => $werks ?? '3000',
                    'level'     => $current_level,
                    'sub_level' => $current_sub_level,
                ])
            ])
            : '#';

        $exportExcelUrl = $current_level
            ? route('stock.issue.exportExcel', [
                'q' => Crypt::encrypt([
                    'werks'     => $werks ?? '3000',
                    'level'     => $current_level,
                    'sub_level' => $current_sub_level,
                ])
            ])
            : '#';

        $exportDisabled = ($exportPdfUrl === '#');
    @endphp

    {{-- =========================================================
    NAV BAR (Pills: Level ASSY, PTG, PKG)
    ========================================================= --}}
    <div class="card nav-pill-card shadow-sm mb-4">
        <div class="card-body p-2">
            <ul class="nav nav-pills pills-issue p-1 flex-wrap">
                @foreach ($pills as $key => $pill)
                    <li class="nav-item mb-1 me-2">
                        <a class="nav-link pill-level {{ strtolower($level ?? '') == $key ? 'active' : '' }}"
                           href="{{ $createEncryptedRoute($pill) }}">
                            {{ $pill['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    {{-- =========================================================
    HEADER: Judul kiri, Export dropdown kanan
    ========================================================= --}}
    <div class="header-container">

        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
            {{-- KIRI: TITLE + SUBTITLE --}}
            <div>
                <h1 class="title mb-1">{{ $title }}</h1>
                <p class="subtitle mb-0">Daftar item Stock Issue level **{{ strtoupper($level ?? '') }}** di lokasi Semarang.</p>
            </div>

            {{-- KANAN: EXPORT DROPDOWN --}}
            <div class="dropdown">
                <button
                    class="btn btn-danger btn-sm dropdown-toggle {{ $exportDisabled ? 'disabled' : '' }}"
                    type="button"
                    data-toggle="dropdown"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                    @if($exportDisabled) aria-disabled="true" @endif
                >
                    <i class="fas fa-download me-1"></i> Export
                </button>

                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <a class="dropdown-item" href="{{ $exportPdfUrl }}">
                            <i class="fas fa-file-pdf me-2 text-danger"></i> Export PDF
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="{{ $exportExcelUrl }}">
                            <i class="fas fa-file-excel me-2 text-success"></i> Export Excel
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        {{-- =========================================================
        SUB NAV PTG (WOOD/METAL) - hanya muncul jika level = PTG
        ========================================================= --}}
        @if (strtolower($level ?? '') == 'ptg')
            <div class="sub-nav-container mt-3 mb-2">
                <ul class="nav nav-pills sub-pills-issue p-1 flex-wrap me-3">
                    @foreach ($ptg_sub_pills as $key => $pill)
                        @php
                            $current_sub_level_ui = strtolower($sub_level ?? '') ?: 'wood';
                            $isActive = $current_sub_level_ui == $key;
                        @endphp
                        <li class="nav-item me-2">
                            <a class="nav-link sub-pill-level {{ $isActive ? 'active' : '' }}"
                               href="{{ $createEncryptedRoute($pill) }}">
                                {{ $pill['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>

                <span class="total-items-badge">
                    <i class="fas fa-boxes me-2"></i> Total Items: {{ $stockData->count() }}
                </span>
            </div>
        @else
            <div class="mt-3 mb-2">
                <span class="total-items-badge">
                    <i class="fas fa-boxes me-2"></i> Total Items: {{ $stockData->count() }}
                </span>
            </div>
        @endif
        {{-- =========================================================
        END SUB NAV PTG
        ========================================================= --}}

    </div>

    @if ($stockData->isEmpty())
        <div class="report-card shadow-lg p-5">
            <div class="empty-state text-center">
                <i class="fas fa-box-open fa-4x mb-3 text-muted"></i>
                <h5 class="text-muted">Data tidak ditemukan</h5>
                <p>Tidak ada data Stock Issue untuk level **{{ strtoupper($level ?? '') }}** saat ini.</p>
            </div>
        </div>
    @else

        {{-- =========================================================
        TABEL 1: CUSTOMER OVERVIEW
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
                            <div class="yz-customer-card"
                                 data-kid="{{ $kid }}"
                                 data-customer="{{ $customerName }}"
                                 title="Klik untuk melihat detail item">

                                <div class="d-flex align-items-center justify-content-between p-3">

                                    {{-- KIRI: Customer Name & Caret --}}
                                    <div class="d-flex align-items-center flex-grow-1 me-3">
                                        <span class="kunnr-caret me-3"><i class="fas fa-chevron-right"></i></span>
                                        <div class="customer-info">
                                            <div class="fw-bold fs-5 text-truncate">{{ $customerName }}</div>
                                            <div class="metric-label text-muted small">{{ $summary['detail_count'] }} Item Detail</div>
                                        </div>
                                    </div>

                                    {{-- KANAN: Metrik & Nilai --}}
                                    <div class="metric-columns d-flex align-items-center text-center flex-wrap flex-md-nowrap">

                                        {{-- Total Stock Qty --}}
                                        <div class="metric-box mx-4" style="min-width: 100px;">
                                            <div class="metric-value fs-4 fw-bold text-primary text-end">
                                                {{ $fmtNumber($summary['total_qty']) }}
                                            </div>
                                            <div class="metric-label">Total Qty</div>
                                        </div>

                                        {{-- Total Stock Value --}}
                                        <div class="metric-box mx-4 text-end" style="min-width: 180px;">
                                            <div class="metric-value fs-4 fw-bold text-dark">
                                                {{ $fmtMoney($summary['total_value']) }}
                                            </div>
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
                            <h6 class="mb-0 text-dark-emphasis">
                                <i class="fas fa-chart-pie me-2"></i>Total Keseluruhan
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

                </div> {{-- yz-customer-list --}}
            </div>
        </div>

    @endif

@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard-style.css') }}">
    <style>
        /* ========================================================= */
        /* START: STYLE UNTUK ISSUE REPORT */
        /* ========================================================= */
        .yz-customer-card.is-open+.yz-nest-card .yz-nest-wrap { background: #fff; }

        .yz-nest-wrap .table-wrapper {
            max-height: 50vh;
            overflow-y: auto;
        }

        .title {
            font-size: 2.25rem;
            font-weight: 800;
            color: #1e40af;
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
            color: #1e40af;
            font-weight: 600;
            border-radius: 9999px;
            font-size: 0.8rem;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        /* ========================================================= */
        /* START: NAVIGASI LEVEL ESTETIK (PILL-LEVEL) */
        /* ========================================================= */
        :root {
            --level-blue: #4f46e5;
            --level-blue-light: #e0e7ff;
            --level-shadow: rgba(79, 70, 229, 0.4);

            --sub-level-green: #059669;
            --sub-level-green-light: #d1fae5;
        }

        .nav-pills .nav-link.pill-level {
            background: #fff;
            color: #4f46e5;
            border: 1px solid #c7d2fe;
            font-weight: 600;
            border-radius: 0.75rem;
            transition: all 0.2s ease-in-out;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05),
                        0 2px 4px -2px rgba(0,0,0,0.05);
            padding: 0.5rem 1.2rem;
        }

        .nav-pills .nav-link.pill-level:hover {
            background: #f8f9ff;
            border-color: #a5b4fc;
            transform: translateY(-2px);
            box-shadow: 0 8px 10px -3px rgba(0,0,0,0.1),
                        0 4px 6px -4px rgba(0,0,0,0.1);
        }

        .nav-pills .nav-link.pill-level.active {
            background: var(--level-blue);
            color: #fff;
            border-color: var(--level-blue);
            transform: translateY(0);
            box-shadow: 0 4px 10px -2px var(--level-shadow),
                        0 0 0 3px var(--level-blue-light);
        }

        .nav-pills .nav-link.pill-level.active:hover { filter: brightness(1.05); }

        .card.nav-pill-card {
            background-color: transparent !important;
            box-shadow: none !important;
            border: none !important;
        }

        .card.nav-pill-card .card-body { padding: 0 !important; }
        .nav-pills.pills-issue.p-1 { padding: 0 !important; }

        .sub-nav-container {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .sub-pills-issue {
            padding: 0 !important;
            background-color: transparent !important;
        }

        .nav-pills .nav-link.sub-pill-level {
            background: #fff;
            color: #334155;
            border: 1px solid #cbd5e1;
            font-weight: 500;
            border-radius: 0.5rem;
            transition: all 0.2s ease-in-out;
            padding: 0.4rem 1rem;
            box-shadow: none;
            font-size: 0.9rem;
        }

        .nav-pills .nav-link.sub-pill-level:hover { background: #f1f5f9; }

        .nav-pills .nav-link.sub-pill-level.active {
            background: var(--sub-level-green);
            color: #fff;
            border-color: var(--sub-level-green);
            box-shadow: 0 2px 4px rgba(5, 150, 105, 0.3);
        }

        .sub-nav-container .total-items-badge { margin-top: 0; }
        /* ========================================================= */
        /* END: NAVIGASI LEVEL ESTETIK */
        /* ========================================================= */
    </style>
@endpush

@push('scripts')
    <script>
        // ✅ UoM aman & konsisten
        function formatUom(uom) {
            if (uom === null || uom === undefined) return '';
            const clean = String(uom).toUpperCase().trim();
            return clean === 'ST' ? 'PC' : clean;
        }

        // Render Tabel 2 (Detail Item)
        function renderLevel2_Items(rows) {
            if (!rows || rows.length === 0) {
                return `<div class="p-3 text-muted">Tidak ada detail item untuk Customer ini.</div>`;
            }

            const formatNumber = (num, d = 0) => {
                const n = parseFloat(num);
                if (!Number.isFinite(n)) return '';
                return n.toLocaleString('id-ID', { minimumFractionDigits: d, maximumFractionDigits: d });
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
                        <td class="text-center">${formatUom(item.MEINS)}</td>
                        <td class="value-col text-end fw-bold">${formatMoney(item.TPRC)}</td>
                    </tr>
                `;
            });

            html += `</tbody></table></div></div>`;
            return html;
        }

        document.addEventListener('DOMContentLoaded', () => {
            const stockData = @json($stockData);

            const stockByCustomer = {};
            stockData.forEach(item => {
                const customerName = item.NAME1;
                if (!stockByCustomer[customerName]) stockByCustomer[customerName] = [];
                stockByCustomer[customerName].push(item);
            });

            const globalFooter = document.querySelector('.yz-global-total-card');
            const customerListContainer = document.getElementById('customer-list-container');

            document.querySelectorAll('.yz-customer-card').forEach(row => {
                row.addEventListener('click', async () => {
                    const customerName = row.dataset.customer;
                    const kid = row.dataset.kid;

                    const slot = document.getElementById(kid);
                    const wrap = slot.querySelector('.yz-nest-wrap');

                    const wasOpen = row.classList.contains('is-open');

                    // tutup yang lain
                    document.querySelectorAll('.yz-customer-card.is-open').forEach(r => {
                        if (r !== row) {
                            r.classList.remove('is-open', 'is-focused');
                            r.querySelector('.kunnr-caret')?.classList.remove('rot');
                            document.getElementById(r.dataset.kid).style.display = 'none';
                        }
                    });

                    row.classList.toggle('is-open');
                    row.querySelector('.kunnr-caret')?.classList.toggle('rot', !wasOpen);

                    if (!wasOpen) {
                        customerListContainer.classList.add('customer-focus-mode');
                        row.classList.add('is-focused');
                        if (globalFooter) globalFooter.style.display = 'none';

                        wrap.innerHTML = renderLevel2_Items(stockByCustomer[customerName]);
                        slot.style.display = 'block';
                    } else {
                        customerListContainer.classList.remove('customer-focus-mode');
                        row.classList.remove('is-focused');
                        if (globalFooter) globalFooter.style.display = '';

                        slot.style.display = 'none';
                    }
                });
            });

            // stop propagation saat klik metric
            document.querySelectorAll('.yz-customer-card .metric-columns').forEach(col => {
                col.addEventListener('click', (e) => e.stopPropagation());
            });
        });
    </script>
@endpush
