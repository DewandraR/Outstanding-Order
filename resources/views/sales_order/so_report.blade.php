@extends('layouts.app')

@section('title', 'Outstanding SO')

@section('content')

    @php
        // Ambil nilai dari controller / query
        $selectedWerks = $selected['werks'] ?? null;
        $selectedAuart = trim((string) ($selected['auart'] ?? ''));
        $mapping = $mapping ?? [];
        $typesForPlant = collect($mapping[$selectedWerks] ?? []);

        $locationMap = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locName = $locationMap[$selectedWerks] ?? $selectedWerks;

        // Data Small Qty diinisialisasi dari controller
        $smallQtyByCustomer = $smallQtyByCustomer ?? collect();

        // Helper URL terenkripsi ke /so-report (digunakan untuk pill navigation)
        $encReport = function (array $params) {
            $payload = array_filter($params, fn($v) => !is_null($v) && $v !== '');
            return route('so.index', ['q' => \Crypt::encrypt($payload)]);
        };

        // Helper total untuk FOOTER Tabel-1
        $rowsCol = collect($rows ?? []);

        // Total SO Count Keseluruhan
        $totalSOTotal = (float) $rowsCol->sum(fn($r) => (float) ($r->SO_TOTAL_COUNT ?? 0));
        // Total Overdue SO Count Keseluruhan
        $totalOverdueSOTotal = (float) $rowsCol->sum(fn($r) => (float) ($r->SO_LATE_COUNT ?? 0));
        // Global Overdue Ratio
        $globalOverdueRatio = $totalSOTotal > 0 ? ($totalOverdueSOTotal / $totalSOTotal) * 100 : 0;
        $globalOverdueColor = $totalOverdueSOTotal > 0 ? 'bg-danger' : 'bg-success';

        // total "Outs. Value" (semua outstanding value) per currency
        $pageTotalsAll = [
            'USD' => (float) $rowsCol->sum(fn($r) => (float) ($r->TOTAL_ALL_VALUE_USD ?? 0)),
            'IDR' => (float) $rowsCol->sum(fn($r) => (float) ($r->TOTAL_ALL_VALUE_IDR ?? 0)),
        ];

        // total "Overdue Value" (hanya yang telat) per currency
        $pageTotalsOverdue = [
            'USD' => (float) $rowsCol->sum(fn($r) => (float) ($r->TOTAL_OVERDUE_VALUE_USD ?? 0)),
            'IDR' => (float) $rowsCol->sum(fn($r) => (float) ($r->TOTAL_OVERDUE_VALUE_IDR ?? 0)),
        ];

        // Logika $formatTotals (tidak berubah)
        $formatTotals = function (array $totals) {
            $sumUsd = $totals['USD'] ?? 0;
            $sumIdr = $totals['IDR'] ?? 0;

            if ($sumUsd == 0 && $sumIdr == 0) {
                return 'Rp ' . number_format(0, 0, ',', '.');
            }

            $parts = [];

            if ($sumUsd > 0) {
                $parts[] = '$' . number_format($sumUsd, 0, '.', ',');
            }
            if ($sumIdr > 0) {
                $parts[] = 'Rp ' . number_format($sumIdr, 0, ',', '.');
            }

            return implode(' | ', $parts);
        };
    @endphp

    {{-- ROOT STATE --}}
    <div id="so-root" data-werks="{{ $selectedWerks ?? '' }}" data-auart="{{ $selectedAuart }}"
        data-hkunnr="{{ request('highlight_kunnr', '') }}" data-hvbeln="{{ request('highlight_vbeln', '') }}"
        data-hposnr="{{ request('highlight_posnr', '') }}" data-auto="{{ request('auto', '0') ? '1' : '0' }}"
        style="display:none"></div>

    {{-- HEADER --}}
    <div class="card yz-card shadow-sm mb-3 overflow-visible">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <div class="py-1">
                @if ($selectedWerks && $typesForPlant->count())
                    <ul class="nav nav-pills yz-auart-pills p-1 flex-wrap" style="border-radius:.75rem;">
                        @foreach ($typesForPlant as $t)
                            @php
                                $auartCode = trim((string) $t->IV_AUART);
                                $isActive = $selectedAuart === $auartCode;
                                // Menggunakan URL terenkripsi untuk filter
                                $filterUrl = $encReport(['werks' => $selectedWerks, 'auart' => $auartCode]);
                            @endphp
                            <li class="nav-item mb-2 me-2">
                                <a href="{{ $filterUrl }}" class="nav-link pill-green {{ $isActive ? 'active' : '' }}">
                                    {{ $t->pill_label }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <i class="fas fa-info-circle me-2"></i>
                    Pilih Plant dulu dari sidebar untuk menampilkan pilihan SO Type.
                @endif
            </div>

            {{-- Kanan: Export Items --}}
            <div class="py-1 d-flex align-items-center gap-2">
                <div class="dropdown" id="export-dropdown-container" style="display:none;">
                    <button class="btn btn-primary dropdown-toggle" type="button" id="export-btn" data-bs-toggle="dropdown"
                        aria-expanded="false">
                        <i class="fas fa-file-export me-2"></i>
                        Export Items (<span id="selected-count">0</span>)
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="export-btn">
                        <li><a class="dropdown-item export-option" href="#" data-type="pdf"><i
                                    class="fas fa-file-pdf text-danger me-2"></i>Export to PDF</a></li>
                        <li><a class="dropdown-item export-option" href="#" data-type="excel"><i
                                    class="fas fa-file-excel text-success me-2"></i>Export to Excel</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {{-- Info jika auart belum dipilih --}}
    @if ($selectedWerks && empty($selectedAuart))
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            Silakan pilih <strong>Type</strong> pada tombol hijau di atas.
        </div>
    @endif

    {{-- TABEL LEVEL-1 (Customer Card-Row BARU) --}}
    @if (isset($rows) && $rows->count())
        <div class="card yz-card shadow-sm">
            <div class="card-body p-0 p-md-2">

                {{-- Judul Utama --}}
                <div class="p-3 mx-md-3 mt-md-3 yz-main-title-wrapper">
                    <h5 class="yz-table-title mb-0"><i class="fas fa-file-invoice me-2"></i>Outstanding SO</h5>
                </div>

                <div class="yz-customer-list px-md-3 pt-3">

                    {{-- Customer Cards Container --}}
                    <div class="d-grid gap-0 mb-4">
                        @forelse ($rows as $r)
                            @php
                                $kid = 'krow_' . $r->KUNNR . '_' . $loop->index;

                                $totalSO = (int) ($r->SO_TOTAL_COUNT ?? 0);
                                $totalOverdueSO = (int) ($r->SO_LATE_COUNT ?? 0);
                                $overdueRatio = $totalSO > 0 ? ($totalOverdueSO / $totalSO) * 100 : 0;
                                $overdueColor = $totalOverdueSO > 0 ? 'bg-danger' : 'bg-success';

                                $outsValueUSD = (float) ($r->TOTAL_ALL_VALUE_USD ?? 0);
                                $outsValueIDR = (float) ($r->TOTAL_ALL_VALUE_IDR ?? 0);
                                $displayOutsValue = $formatTotals([
                                    'USD' => $outsValueUSD,
                                    'IDR' => $outsValueIDR,
                                ]);

                                $overdueValueUSD = (float) ($r->TOTAL_OVERDUE_VALUE_USD ?? 0);
                                $overdueValueIDR = (float) ($r->TOTAL_OVERDUE_VALUE_IDR ?? 0);
                                $displayOverdueValue = $formatTotals([
                                    'USD' => $overdueValueUSD,
                                    'IDR' => $overdueValueIDR,
                                ]);
                                $overdueValueStyle =
                                    $overdueValueUSD > 0 || $overdueValueIDR > 0 ? 'text-danger' : 'text-success';

                                $isOverdue = $totalOverdueSO > 0;
                                $highlightClass = $isOverdue ? 'yz-customer-card-overdue' : '';
                            @endphp

                            {{-- Custom Card Row --}}
                            <div class="yz-customer-card {{ $highlightClass }}" data-kunnr="{{ $r->KUNNR }}"
                                data-kid="{{ $kid }}" data-cname="{{ $r->NAME1 }}"
                                title="Klik untuk melihat detail SO">
                                <div class="d-flex align-items-center justify-content-between p-3">

                                    {{-- KIRI: Customer Name & Caret --}}
                                    <div class="d-flex align-items-center flex-grow-1 me-3">
                                        <span class="kunnr-caret me-3"><i class="fas fa-chevron-right"></i></span>
                                        <div class="customer-info">
                                            <div class="fw-bold fs-5 text-truncate">{{ $r->NAME1 }}</div>
                                            {{-- MODIFIKASI SEBELUMNYA: ID Pelanggan dihapus --}}
                                        </div>
                                    </div>

                                    {{-- KANAN: Metrik & Nilai --}}
                                    <div id="metric-columns"
                                        class="d-flex align-items-center text-center flex-wrap flex-md-nowrap">

                                        {{-- Total SO --}}
                                        <div class="metric-box mx-4" style="min-width: 100px;">
                                            <div class="metric-value fs-4 fw-bold text-primary text-end">
                                                {{ number_format($totalSO, 0, ',', '.') }}</div>
                                            <div class="text-end">Total SO</div>
                                        </div>

                                        {{-- Overdue SO with Visual Indicator --}}
                                        <div class="metric-box mx-4" style="min-width: 100px;">
                                            <div
                                                class="metric-value fs-4 fw-bold {{ $isOverdue ? 'text-danger' : 'text-success' }} text-end">
                                                {{ number_format($totalOverdueSO, 0, ',', '.') }}</div>
                                            <div class="metric-label text-end">Overdue SO</div>
                                        </div>

                                        {{-- Outstanding Value --}}
                                        <div class="metric-box mx-4 text-end" style="min-width: 180px;">
                                            <div class="metric-value fs-4 fw-bold text-dark">{{ $displayOutsValue }}</div>
                                            <div class="metric-label">Outstanding Value</div>
                                        </div>

                                        {{-- Overdue Value --}}
                                        <div class="metric-box mx-4 text-end" style="min-width: 180px;">
                                            <div class="metric-value fs-4 fw-bold {{ $overdueValueStyle }}">
                                                {{ $displayOverdueValue }}
                                            </div>
                                            <div class="metric-label">Overdue Value</div>
                                        </div>

                                    </div>
                                </div>
                            </div>

                            {{-- Detail Row (Nested Table Container) --}}
                            <div id="{{ $kid }}" class="yz-nest-card" style="display:none;">
                                <div class="yz-nest-wrap">
                                    <div
                                        class="p-3 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
                                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                                        Memuat data…
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="alert alert-warning text-center">
                                <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">Data tidak ditemukan</h5>
                                <p>Tidak ada data yang cocok untuk filter yang Anda pilih.</p>
                            </div>
                        @endforelse
                    </div>

                    {{-- Global Totals Card (Menggantikan TFOOT) --}}
                    <div class="card shadow-sm yz-global-total-card mb-4">
                        <div class="card-body p-3 d-flex justify-content-between align-items-center flex-wrap">
                            <h6 class="mb-0 text-dark-emphasis"><i class="fas fa-chart-pie me-2"></i>Total Keseluruhan</h6>

                            {{-- STRUKTUR METRIK FOOTER HARUS SAMA PERSIS DENGAN metric-columns di atas --}}
                            <div id="footer-metric-columns"
                                class="d-flex align-items-center text-center flex-wrap flex-md-nowrap">

                                {{-- Total SO Count --}}
                                <div class="metric-box mx-4"
                                    style="min-width: 100px; border-left: none !important; padding-left: 0 !important;">
                                    <div class="metric-value fs-4 fw-bold text-primary text-end">
                                        {{ number_format($totalSOTotal, 0, ',', '.') }}</div>
                                    <div class="text-end">Total SO Count</div>
                                </div>

                                {{-- Total Overdue SO --}}
                                <div class="metric-box mx-4" style="min-width: 100px;">
                                    <div class="fw-bold fs-4 text-danger text-end">
                                        {{ number_format($totalOverdueSOTotal, 0, ',', '.') }}
                                    </div>
                                    <div class="text-end">Total Overdue SO</div>
                                </div>

                                {{-- Total Outs. Value --}}
                                <div class="metric-box mx-4 text-end" style="min-width: 180px;">
                                    <div class="fw-bold fs-4 text-dark">{{ $formatTotals($pageTotalsAll ?? []) }}</div>
                                    <div class="metric-label"> Total Outs. Value</div>
                                </div>

                                {{-- Total Overdue Value --}}
                                <div class="metric-box mx-4 text-end" style="min-width: 180px;">
                                    <div class="fw-bold fs-4 text-danger">{{ $formatTotals($pageTotalsOverdue ?? []) }}
                                    </div>
                                    <div class="metric-label">Total Overdue Value</div>
                                </div>

                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    @else
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Silakan pilih <strong>Plant</strong> dan <strong>Type</strong> dari sidebar untuk menampilkan Laporan SO.
        </div>
    @endif

    {{-- Small Quantity Chart & Details --}}
    <div class="row g-4 mt-1" id="small-qty-section">
        <div class="col-12">
            <div class="card shadow-sm yz-chart-card">
                <div class="card-body">
                    <h5 class="card-title text-info-emphasis" id="small-qty-chart-title"
                        data-help-key="so.small_qty_by_customer">
                        <i class="fas fa-chart-line me-2"></i>Small Quantity (≤5)
                        Outstanding Items by Customer
                        <small class="text-muted ms-2" id="small-qty-total-item"></small>
                    </h5>
                    <hr class="mt-2">
                    <div class="chart-container" style="height: 600px;">
                        <canvas id="chartSmallQtyByCustomer"></canvas>
                        <div class="yz-nodata text-center p-5 text-muted" style="display:none;">
                            <i class="fas fa-info-circle fa-2x mb-2"></i><br>Tidak ada item outstanding dengan Qty Outs. SO
                            ≤ 5.
                        </div>
                    </div>
                </div>

                <div id="smallQtyDetailsContainer" class="card shadow-sm yz-chart-card mx-3 mb-3 mt-4"
                    style="display: none;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 text-primary-emphasis">
                                <i class="fas fa-list-ol me-2"></i>
                                <span id="smallQtyDetailsTitle">Detail Item Outstanding</span>
                                <small id="smallQtyMeta" class="text-muted ms-2"></small>
                            </h5>

                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-sm btn-outline-danger" id="exportSmallQtyPdf"
                                    disabled>
                                    <i class="fas fa-file-pdf me-1"></i> Export PDF
                                </button>
                                <button type="button" class="btn-close" id="closeDetailsTable"
                                    aria-label="Close"></button>
                            </div>
                        </div>
                        <hr class="mt-2">
                        <div id="smallQtyDetailsTable" class="mt-3">
                        </div>
                    </div>
                    <form id="smallQtyExportForm" action="{{ route('so.export.small_qty_pdf') }}" method="POST"
                        target="_blank" class="d-none">
                        @csrf
                        <input type="hidden" name="customerName" id="exp_customerName">
                        <input type="hidden" name="werks" value="{{ $selectedWerks ?? '' }}">
                        <input type="hidden" name="auart" value="{{ $selectedAuart ?? '' }}">
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Remark Modal --}}
    <div class="modal fade" id="remarkModal" tabindex="-1" aria-labelledby="remarkModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        Catatan Item
                        <small class="text-muted d-block" id="remarkModalSub">SO <span id="rm-so"></span> • Item
                            <span id="rm-pos"></span></small>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>

                <div class="modal-body">
                    <div id="remarkThreadList" class="remark-thread-list mb-3">
                        <!-- diisi via JS -->
                    </div>

                    <!-- Form tambah -->
                    <div class="mb-2">
                        <label for="remark-input" class="form-label mb-1">Tambah Catatan (maks. 60 karakter)</label>
                        <div class="d-flex align-items-start gap-2">
                            <textarea id="remark-input" class="form-control" rows="2" maxlength="60"
                                placeholder="Tulis catatan singkat..."></textarea>
                            <button type="button" id="add-remark-btn" class="btn btn-primary">
                                <i class="fas fa-paper-plane me-1"></i> Tambah
                            </button>
                        </div>
                        <div class="d-flex justify-content-between mt-1 small">
                            <span id="remark-feedback" class="text-muted"></span>
                            <span id="remark-counter" class="text-muted">0/60</span>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard-style.css') }}">
    <style>
        .remark-icon {
            cursor: pointer;
            color: #6c757d;
            transition: color .2s
        }

        .remark-icon:hover {
            color: #0d6efd
        }

        .remark-dot {
            height: 8px;
            width: 8px;
            background: #0d6efd;
            border-radius: 50%;
            display: inline-block;
            margin-left: 5px;
            vertical-align: middle
        }

        .so-selected-dot {
            height: 8px;
            width: 8px;
            background: #0d6efd;
            border-radius: 50%;
            display: none
        }

        .so-remark-flag {
            color: #6c757d;
            margin-right: 6px;
            display: none
        }

        .so-remark-flag.active {
            color: #0d6efd;
            display: inline-block
        }

        .row-highlighted {
            animation: flashRow 1.2s ease-in-out 3
        }

        @keyframes flashRow {
            0% {
                background: #fff8d6
            }

            50% {
                background: #ffe89a
            }

            100% {
                background: transparent
            }
        }

        .yz-caret {
            display: inline-block;
            transition: transform .18s ease;
            user-select: none;
            /* Tambahkan margin-left untuk mengatur posisi */
            margin-left: 5px;
            vertical-align: middle;
            line-height: 1;
        }

        /* Gaya baru untuk caret di Tabel 2 */
        .yz-t2-vbeln-wrap {
            display: flex;
            align-items: center;
        }

        .yz-caret.rot {
            transform: rotate(90deg)
        }

        /* Mengatur focus mode untuk Card-Row Level 1 */
        .customer-focus-mode .yz-customer-card:not(.is-focused) {
            display: none !important;
        }

        /* Menyembunyikan total global saat detail customer dibuka */
        .customer-focus-mode~.yz-global-total-card {
            display: none;
        }

        /* Tambahan CSS agar metrik sejajar atas-bawah */
        #metric-columns,
        #footer-metric-columns {
            /* Pastikan elemen ini memegang lebar yang sama dan kolomnya terstruktur */
            width: 100%;
            justify-content: flex-end;
            /* Dorong ke kanan */
        }

        #footer-metric-columns .metric-box {
            /* Hapus border vertikal di footer agar terlihat lebih bersih */
            border-left: none !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        /* Atur metrik boxes agar selalu sejajar vertikal */
        .yz-customer-card #metric-columns>.metric-box,
        #footer-metric-columns>div {
            /* Hilangkan gap yang diatur d-flex gap-4 */
            margin-left: 2rem !important;
            margin-right: 2rem !important;
        }

        /* Metrik box pertama harus lurus dengan container */
        .yz-customer-card #metric-columns>.metric-box:first-child {
            margin-left: 0 !important;
            padding-left: 0 !important;
        }

        /* Metrik box pertama di footer harus lurus dengan container */
        #footer-metric-columns>div:first-child {
            margin-left: 0 !important;
        }

        /* Atur alignment teks di dalam metric box untuk memastikan angka sejajar kanan */
        .metric-box .metric-value,
        .metric-box .metric-label {
            text-align: right !important;
        }

        /* Perbaikan: Atur progress bar agar tetap di tengah horizontal di dalam metric-box */
        .metric-box .progress {
            margin: 4px auto 0 !important;
            /* Mengatur ulang margin yang mungkin terpengaruh oleh alignment text-end */
        }

        .so-focus-mode .js-t2row {
            display: none;
        }

        .so-focus-mode .js-t2row.is-focused {
            display: table-row;
        }


        /* MODIFIKASI: Menghilangkan highlight baris merah penuh yang lama */
        .yz-row-highlight-negative>td,
        .yz-row-highlight-negative td {
            background-color: transparent !important;
        }

        .table-hover tbody tr.yz-row-highlight-negative:hover>td,
        .table-hover tbody tr.yz-row-highlight-negative:hover td {
            background-color: #f8f9fa !important;
            /* Kembali ke hover abu-abu normal */
        }

        /* Gaya kustom untuk bubble Overdue/On Track */
        .overdue-badge-bubble {
            padding: 0.35em 0.7em;
            font-size: 0.7rem;
            font-weight: 700;
            color: #fff;
            text-align: center;
            border-radius: 1rem;
            /* Bentuk bubble/pill */
            white-space: nowrap;
        }

        .bubble-late {
            background-color: #c53030;
            /* Merah gelap/Bordeaux */
        }

        .bubble-track {
            background-color: #38a3a5;
            /* Hijau Teal */
        }

        .bubble-today {
            background-color: #b7791f;
            /* Kuning Gelap/Coklat */
            color: #fff;
        }

        /* Tambahkan style untuk tombol collapse header Tabel 2 */
        .yz-header-so .js-collapse-toggle {
            line-height: 1;
            padding: 2px 8px;
        }

        .yz-header-so .yz-collapse-caret {
            display: inline-block;
            transition: transform .18s ease
        }

        .yz-header-so .yz-collapse-caret.rot {
            transform: rotate(90deg)
        }

        .remark-thread-list {
            max-height: 360px;
            overflow: auto;
            border: 1px solid #eee;
            border-radius: .5rem;
            padding: .75rem;
            background: #fafafa
        }

        .remark-item {
            display: flex;
            gap: .6rem;
            padding: .5rem .6rem;
            border-radius: .5rem;
            background: #fff;
            border: 1px solid #f0f0f0;
            margin-bottom: .5rem
        }

        .remark-item.own {
            border-color: #d1e7dd;
            background: #f8fff9
        }

        .remark-item .meta {
            font-size: .8rem;
            color: #6c757d
        }

        .remark-item .text {
            white-space: pre-wrap
        }

        .remark-item .act {
            margin-left: auto
        }

        .yz-machi-pct {
            cursor: help;
            text-decoration: underline dotted;
            white-space: nowrap;
        }

        .tooltip-cursor {
            cursor: pointer;
        }

        .yz-machi-pct.popover-cursor {
            cursor: pointer;
            text-decoration: none;
            font-weight: bold;
        }

        /* Container Popover - Mewah */
        .yz-lux-popover {
            /* Lebar yang proporsional */
            --bs-popover-max-width: 250px;
            /* Background gelap (mewah) */
            --bs-popover-bg: #212529;
            /* Warna teks cerah */
            --bs-popover-color: #f8f9fa;
            /* Padding besar */
            --bs-popover-padding-x: 1rem;
            --bs-popover-padding-y: 1rem;
            /* Border halus */
            --bs-popover-border-color: #343a40;
            --bs-popover-border-radius: .75rem;
            /* Bayangan yang menawan */
            filter: drop-shadow(0 0 10px rgba(0, 0, 0, 0.4));
        }

        /* Konten Popover */
        .yz-popover-content-lux {
            color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .yz-popover-content-lux h6 {
            font-size: 0.9rem;
            color: #ffd700;
            /* Emas */
            border-bottom: 1px solid #495057;
            padding-bottom: .25rem;
        }

        /* Gaya Progress Bar di Popover */
        .yz-popover-content-lux .progress {
            height: 6px !important;
            background-color: #495057;
            border-radius: 4px;
        }

        /* Gaya Separator */
        .yz-popover-content-lux hr {
            border-top: 1px solid #495057;
            opacity: 0.7;
        }

        /* Gaya Status */
        .yz-popover-content-lux .small {
            font-weight: 500;
        }

        /* Pastikan panah popover terlihat elegan dengan background gelap */
        .yz-lux-popover.popover .popover-arrow::before {
            border-color: #343a40;
        }

        .yz-lux-popover.bs-popover-auto[data-popper-placement^=top] .popover-arrow::after,
        .yz-lux-popover.bs-popover-top .popover-arrow::after {
            border-top-color: #212529;
        }

        .yz-lux-popover.bs-popover-auto[data-popper-placement^=bottom] .popover-arrow::after,
        .yz-lux-popover.bs-popover-bottom .popover-arrow::after {
            border-bottom-color: #212529;
        }
    </style>
@endpush

@push('scripts')
    {{-- Vendor charts --}}
    <script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
    <script src="{{ asset('vendor/chartjs/chartjs-adapter-date-fns.bundle.min.js') }}"></script>

    <script>
        (() => {
            'use strict';

            /* =========================================================
             * ROUTE CONSTANTS (dari Blade)
             * ======================================================= */
            const apiSoByCustomer = "{{ route('so.api.by_customer') }}";
            const apiItemsBySo = "{{ route('so.api.by_items') }}";
            const exportUrl = "{{ route('so.export') }}";
            const apiSmallQtyDetails = "{{ route('so.api.small_qty_details') }}";
            const exportSmallQtyPdfUrl = "{{ route('so.export.small_qty_pdf') }}";
            const apiListItemRemarks = "{{ route('so.api.item_remarks') }}";
            const apiAddItemRemark = "{{ route('so.api.item_remarks.store') }}";
            const apiDeleteItemRemarkTpl = @json(route('so.api.item_remarks.delete', ['id' => '___ID___']));
            const apiUpdateItemRemarkTpl = @json(route('so.api.item_remarks.update', ['id' => '___ID___']));
            const csrfToken = "{{ csrf_token() }}";

            /* =========================================================
             * UTILITIES
             * ======================================================= */
            if (!window.CSS) window.CSS = {};
            if (typeof window.CSS.escape !== 'function') {
                window.CSS.escape = s => String(s).replace(/([^\w-])/g, '\\$1');
            }

            // Data Small Qty awal dari controller → Blade
            const initialSmallQtyDataRaw = {!! json_encode($smallQtyByCustomer ?? collect()) !!};

            const formatCurrencyGlobal = (v, c, d = 0) => {
                const n = parseFloat(v);
                if (!Number.isFinite(n)) return '';
                const opt = {
                    minimumFractionDigits: d,
                    maximumFractionDigits: d
                };
                if (c === 'IDR') return `Rp ${n.toLocaleString('id-ID', opt)}`;
                if (c === 'USD') return `$${n.toLocaleString('en-US', opt)}`;
                return `${c} ${n.toLocaleString('id-ID', opt)}`;
            };
            const formatNumberGlobal = (v, d = 0) => {
                const n = parseFloat(v);
                if (!Number.isFinite(n)) return '';
                return n.toLocaleString('id-ID', {
                    minimumFractionDigits: d,
                    maximumFractionDigits: d
                });
            };
            const formatPercent = (v) => {
                const n = parseFloat(v);
                if (!Number.isFinite(n)) return '';
                return `${formatNumberGlobal(n, 0)}%`;
            };
            const uniqBy = (arr, keyer) => {
                const seen = new Set();
                return (arr || []).filter(x => {
                    const k = keyer(x);
                    if (seen.has(k)) return false;
                    seen.add(k);
                    return true;
                });
            };
            const escapeHtml = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g,
                '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            const waitFor = (fn, {
                timeout = 12000,
                interval = 120
            } = {}) => new Promise(resolve => {
                const start = Date.now();
                const t = setInterval(() => {
                    let ok = false;
                    try {
                        ok = !!fn();
                    } catch {}
                    if (ok) {
                        clearInterval(t);
                        return resolve(true);
                    }
                    if (Date.now() - start > timeout) {
                        clearInterval(t);
                        return resolve(false);
                    }
                }, interval);
            });
            const scrollAndFlash = (el) => {
                if (!el) return;
                el.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
                el.classList.add('row-highlighted');
            };

            // Fungsi kustom untuk konten Popover yang mewah ✨
            const makeStagePopoverContent = (stageName, grRaw, orderRaw) => {
                const toNum = v => {
                    const n = Number(v);
                    return Number.isFinite(n) ? n : null;
                };
                const fm = toNum(grRaw);
                const fq = toNum(orderRaw);

                const grValue = fm !== null ? formatNumberGlobal(fm, 0) : '—';
                const orderValue = fq !== null ? formatNumberGlobal(fq, 0) : '—';
                const totalOrder = fq !== null && fq > 0 ? fq : 1; // Hindari pembagian dengan nol
                const grProgress = (fm / totalOrder) * 100;

                const isCompleted = (fm !== null && fq !== null && fm >= fq && fq > 0);
                const status = isCompleted ? 'Selesai' : (fm > 0 ? 'Sedang Diproses' : 'Belum Mulai');
                const statusClass = isCompleted ? 'text-success' : (fm > 0 ? 'text-warning' : 'text-muted');

                const progressStyle = `width: ${Math.min(100, grProgress || 0)}%;`;
                const progressClass = isCompleted ? 'bg-success' : (fm > 0 ? 'bg-warning' : 'bg-secondary');

                return `
                    <div class="yz-popover-content-lux">
                        <h6 class="mb-2 text-uppercase fw-bold text-dark">${stageName} Progress</h6>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="fw-bolder">GR / Total Order</span>
                            <span class="fw-bolder">${grValue} / ${orderValue}</span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar ${progressClass}" role="progressbar" style="${progressStyle}" aria-valuenow="${Math.min(100, grProgress || 0)}" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <hr class="my-2">
                        <div class="small ${statusClass}">Status: **${status}**</div>
                    </div>
                `;
            };

            // Fungsi untuk inisialisasi Popover yang menggantikan Tooltip
            function attachBootstrapPopovers(container) {
                if (!container || !window.bootstrap || !bootstrap.Popover) return;

                // Dispose popover yang sudah ada
                container.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => {
                    const existing = bootstrap.Popover.getInstance(el);
                    if (existing) existing.dispose();

                    // Ambil data untuk konten
                    const stage = el.dataset.stage || 'Proses Tahap';
                    const gr = el.dataset.gr || '';
                    const order = el.dataset.order || '';

                    // Buat Popover
                    new bootstrap.Popover(el, {
                        container: 'body', // Penting agar tidak terpotong
                        html: true,
                        trigger: 'hover', // Tampil saat kursor menempel
                        placement: 'auto',
                        customClass: 'yz-lux-popover', // Kelas kustom untuk styling
                        content: makeStagePopoverContent(stage, gr, order),
                        sanitize: false // Izinkan HTML di Popover content
                    });
                    // Pastikan elemen tetap ada class yz-machi-pct
                    el.classList.remove('tooltip-cursor');
                    el.classList.add('popover-cursor');
                });
            }

            /* =========================================================
             * DATA LOADER
             * ======================================================= */
            const itemsCache = new Map(); // VBELN -> array items
            const itemIdToSO = new Map(); // itemId -> VBELN

            async function ensureItemsLoadedForSO(vbeln, WERKS, AUART) {
                if (itemsCache.has(vbeln)) return itemsCache.get(vbeln);

                const u = new URL(apiItemsBySo, window.location.origin);
                u.searchParams.set('vbeln', vbeln);
                u.searchParams.set('werks', WERKS);
                u.searchParams.set('auart', AUART);

                const r = await fetch(u, {
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                const jd = await r.json();
                if (!jd.ok) throw new Error(jd.error || 'Gagal memuat item');

                const dedupItems = uniqBy(jd.data || [], x =>
                    `${x.VBELN_KEY}|${x.POSNR_KEY}|${x.MATNR ?? ''}`);
                dedupItems.forEach(x => itemIdToSO.set(String(x.id), vbeln));
                itemsCache.set(vbeln, dedupItems);
                return dedupItems;
            }

            /* =========================================================
             * UI HELPERS
             * ======================================================= */
            const selectedItems = new Set(); // id item terpilih (untuk export)
            const exportDropdownContainer = document.getElementById('export-dropdown-container');
            const selectedCountSpan = document.getElementById('selected-count');

            function updateExportButton() {
                const n = selectedItems.size;
                if (selectedCountSpan) selectedCountSpan.textContent = n;
                if (exportDropdownContainer) exportDropdownContainer.style.display = n > 0 ? 'block' :
                    'none';
            }

            function updateSODot(vbeln) {
                document.querySelectorAll(`.js-t2row[data-vbeln='${CSS.escape(vbeln)}'] .so-selected-dot`)
                    .forEach(dot => {
                        const anySel = Array.from(selectedItems).some(id => itemIdToSO.get(String(
                            id)) === vbeln);
                        dot.style.display = anySel ? 'inline-block' : 'none';
                    });
            }

            function recalcSoRemarkFlagFromDom(vbeln) {
                const nest = document.querySelector(`.js-t2row[data-vbeln='${CSS.escape(vbeln)}']`)
                    ?.nextElementSibling;
                let hasAny = false;
                if (nest) nest.querySelectorAll('.remark-count-badge').forEach(b => {
                    if (Number(b.dataset.count || 0) > 0) hasAny = true;
                });
                document.querySelectorAll(`.js-t2row[data-vbeln='${CSS.escape(vbeln)}'] .so-remark-flag`)
                    .forEach(el => {
                        el.style.display = hasAny ? 'inline-block' : 'none';
                        el.classList.toggle('active', hasAny);
                    });
            }

            function applySelectionsToRenderedItems(container) {
                container.querySelectorAll('.check-item').forEach(chk => {
                    chk.checked = selectedItems.has(chk.dataset.id);
                });
            }

            function syncCheckAllHeader(itemBox) {
                const table = itemBox?.querySelector('table');
                if (!table) return;
                const hdr = table.querySelector('.check-all-items');
                if (!hdr) return;
                const all = Array.from(table.querySelectorAll('.check-item'));
                const allChecked = (all.length > 0 && all.every(ch => ch.checked));
                const anyChecked = all.some(ch => ch.checked);
                hdr.checked = allChecked;
                hdr.indeterminate = !allChecked && anyChecked;
            }

            function syncCheckAllSoHeader(tbody) {
                const allSOCheckboxes = Array.from(tbody.querySelectorAll('.check-so')).filter(ch => ch
                    .closest('tr').style.display !== 'none');
                const selectAllSo = tbody.closest('table')?.querySelector('.check-all-sos');
                if (!selectAllSo || allSOCheckboxes.length === 0) return;
                const allChecked = allSOCheckboxes.every(ch => ch.checked);
                selectAllSo.checked = allChecked;
                selectAllSo.indeterminate = false;
            }

            const getCollapse = (tbody) => !!(tbody && tbody.dataset && tbody.dataset.collapse === '1');
            const setCollapse = (tbody, on) => {
                if (tbody && tbody.dataset) tbody.dataset.collapse = on ? '1' : '0';
            };

            function updateT2FooterVisibility(t2Table) {
                if (!t2Table) return;
                const anyOpen = [...t2Table.querySelectorAll('tr.yz-nest')].some(tr => tr.style.display !==
                    'none' && tr.offsetParent !== null);
                const tfoot = t2Table.querySelector('tfoot.t2-footer');
                const tbody = t2Table.querySelector('tbody');
                if (tfoot) tfoot.style.display = (anyOpen || getCollapse(tbody)) ? 'none' : '';
            }

            /* =========================================================
             * RENDERERS (Level 3 Item, DENGAN POPOVER BARU)
             * ======================================================= */
            function renderLevel3_Items(rows) {
                if (!rows?.length)
                    return `<div class="p-2 text-muted">Tidak ada item detail (dengan Outs. SO > 0).</div>`;

                let html = `
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0 yz-mini">
        <thead class="yz-header-item">
          <tr>
            <th style="width:40px;">
              <input class="form-check-input check-all-items" type="checkbox" title="Pilih Semua Item">
            </th>
            <th>Item</th>
            <th>Material FG</th>
            <th>Desc FG</th>
            <th>Qty SO</th>
            <th>Outs. SO</th>
            <th>Stock Packing</th>
            <th>MACHI</th>
            <th>ASSY</th>
            <th>PAINT</th>
            <th>PACKING</th>
            <th>Net Price</th>
            <th>Outs. Packg Value</th>
            <th>Remark</th>
          </tr>
        </thead>
        <tbody>`;

                rows.forEach(r => {
                    const isChecked = selectedItems.has(String(r.id));
                    const countRemarks = Number(r.remark_count ?? ((r.remark && r.remark.trim() !==
                        '') ? 1 : 0));

                    html += `
      <tr id="item-${r.VBELN_KEY}-${r.POSNR_KEY}"
          data-item-id="${r.id}" data-werks="${r.WERKS_KEY}" data-auart="${r.AUART_KEY}"
          data-vbeln="${r.VBELN_KEY}" data-posnr="${r.POSNR}" data-posnr-key="${r.POSNR_KEY}">
        <td><input class="form-check-input check-item" type="checkbox" data-id="${r.id}" ${isChecked ? 'checked':''}></td>
        <td>${r.POSNR ?? ''}</td>
        <td>${r.MATNR ?? ''}</td>
        <td>${r.MAKTX ?? ''}</td>
        <td>${formatNumberGlobal(r.KWMENG, 0)}</td>
        <td>${formatNumberGlobal(r.PACKG, 0)}</td>
        <td>${formatNumberGlobal(r.KALAB2, 0)}</td>
    <td>
      <span
        class="yz-machi-pct"
        data-bs-toggle="popover"
        data-bs-placement="top"
        data-stage="Machining" 
        data-gr="${r.MACHI ?? ''}" 
        data-order="${r.QPROM ?? ''}"
        title="Progress Stage: Machining">
        ${formatPercent(r.PRSM)}
      </span>
    </td>
    <td>
      <span
        class="yz-machi-pct"
        data-bs-toggle="popover"
        data-bs-placement="top"
        data-stage="Assembly" 
        data-gr="${r.ASSYM ?? ''}" 
        data-order="${r.QPROA ?? ''}"
        title="Progress Stage: Assembly">
        ${formatPercent(r.PRSA)}
      </span>
    </td>
    <td>
      <span
        class="yz-machi-pct"
        data-bs-toggle="popover"
        data-bs-placement="top"
        data-stage="Paint" 
        data-gr="${r.PAINTM ?? ''}" 
        data-order="${r.QPROI ?? ''}"
        title="Progress Stage: Paint">
        ${formatPercent(r.PRSI)}
      </span>
    </td>
    <td>
      <span
        class="yz-machi-pct"
        data-bs-toggle="popover"
        data-bs-placement="top"
        data-stage="Packing" 
        data-gr="${r.PACKGM ?? ''}" 
        data-order="${r.QPROP ?? ''}"
        title="Progress Stage: Packing">
        ${formatPercent(r.PRSP)}
      </span>
    </td>
        <td>${formatCurrencyGlobal(r.NETPR, r.WAERK)}</td>
        <td>${formatCurrencyGlobal(r.TOTPR2, r.WAERK)}</td>
        <td class="text-center">
          <i class="fas fa-comments remark-icon"
            title="Lihat/tambah catatan"
            data-werks="${r.WERKS_KEY}"
            data-auart="${r.AUART_KEY}"
            data-vbeln="${r.VBELN_KEY}"
            data-posnr="${r.POSNR}"
            data-posnr-key="${r.POSNR_KEY}"></i>
          <span class="remark-count-badge badge rounded-pill bg-primary ms-1"
                data-count="${countRemarks}"
                style="display:${countRemarks>0 ? 'inline-block':'none'};">${countRemarks}</span>
        </td>
      </tr>`;
                });

                html += `</tbody></table></div>`;
                return html;
            }

            function renderLevel2_SO(rows, kunnr) {
                if (!rows?.length)
                    return `<div class="p-3 text-muted">Tidak ada data Outstanding SO untuk customer ini.</div>`;

                const totalOutsQtyT2 = rows.reduce((sum, r) => sum + parseFloat(r.outs_qty ?? r.OUTS_QTY ??
                    0), 0);

                let html = `
            <table class="table table-sm mb-0 yz-mini">
              <thead class="yz-header-so">
                <tr>
                  <th style="width:40px;" class="text-center">
                    <input type="checkbox" class="form-check-input check-all-sos" title="Pilih semua SO">
                  </th>
                  <th style="width:40px;" class="text-center">
                    <button type="button" class="btn btn-sm btn-light js-collapse-toggle" title="Mode Kolaps/Fokus">
                      <span class="yz-collapse-caret">▸</span>
                    </button>
                  </th>
                  <th class="text-start" style="width: 250px;">SO & Status</th>
                  <th class="text-center">SO Item Count</th>
                  <th class="text-start">Outs. Value</th>
                  <th class="text-center">Req. Deliv. Date</th>
                  <th class="text-center">Outs. Qty</th>
                  <th style="width:28px;"></th>
                </tr>
              </thead>
              <tbody>`;

                const rowsSorted = [...rows].sort((a, b) => {
                    const oa = Number(a.Overdue || 0),
                        ob = Number(b.Overdue || 0);
                    if (oa > 0 && ob <= 0) return -1;
                    if (oa <= 0 && ob > 0) return 1;
                    return ob - oa;
                });

                rowsSorted.forEach((r, i) => {
                    const rid = `t3_${kunnr}_${r.VBELN}_${i}`;
                    const overdueDays = Number(r.Overdue || 0);
                    const hasRemark = Number(r.remark_count || 0) > 0;
                    const outsQty = (typeof r.outs_qty !== 'undefined') ? r.outs_qty : (r
                        .OUTS_QTY ?? 0);
                    const displayValue = formatCurrencyGlobal(r.total_value, r.WAERK);

                    let overdueBadge = '';
                    if (overdueDays > 0) overdueBadge =
                        `<span class="overdue-badge-bubble bubble-late" title="${overdueDays} hari terlambat">${overdueDays} DAYS LATE</span>`;
                    else if (overdueDays < 0) overdueBadge =
                        `<span class="overdue-badge-bubble bubble-track" title="${Math.abs(overdueDays)} hari tersisa">-${Math.abs(overdueDays)} DAYS LEFT</span>`;
                    else overdueBadge =
                        `<span class="overdue-badge-bubble bubble-today" title="Jatuh tempo hari ini">TODAY</span>`;

                    html += `
              <tr class="yz-row js-t2row" data-vbeln="${r.VBELN}" data-tgt="${rid}">
                <td class="text-center">
                  <input type="checkbox" class="form-check-input check-so" data-vbeln="${r.VBELN}" onclick="event.stopPropagation()">
                </td>
                <td class="text-center"><span class="yz-caret">▸</span></td>
                <td class="text-start">
                  <div class="fw-bold text-primary mb-1">
                    <a href="#" class="js-open-so text-decoration-none" data-vbeln="${r.VBELN}" data-open-items="1">${r.VBELN}</a>
                  </div>
                  ${overdueBadge}
                </td>
                <td class="text-center fw-bold">${r.item_count ?? '-'}</td>
                <td class="text-start fw-bold fs-6">${displayValue}</td>
                <td class="text-center fw-bold">${r.FormattedEdatu || '-'}</td>
                <td class="text-center fw-bold">${formatNumberGlobal(outsQty, 0)}</td>
                <td class="text-center">
                  <i class="fas fa-pencil-alt so-remark-flag ${hasRemark ? 'active' : ''}" title="Ada item yang diberi catatan" style="display:${hasRemark?'inline-block':'none'};"></i>
                  <span class="so-selected-dot"></span>
                </td>
              </tr>
              <tr id="${rid}" class="yz-nest" style="display:none;">
                <td colspan="8" class="p-0">
                  <div class="yz-nest-wrap level-2" style="margin-left:0; padding:.5rem;">
                    <div class="yz-slot-items p-2"></div>
                  </div>
                </td>
              </tr>`;
                });

                html += `
              </tbody>
              <tfoot class="t2-footer">
                <tr class="table-light yz-t2-total-outs" style="background-color:#e9ecef;">
                  <th colspan="6" class="text-end">Total Outstanding Qty</th>
                  <th class="text-center fw-bold">${formatNumberGlobal(totalOutsQtyT2, 0)}</th>
                  <th></th>
                </tr>
              </tfoot>
            </table>`;
                return html;
            }

            /* =========================================================
             * MAIN LOGIC
             * ======================================================= */

            document.addEventListener('DOMContentLoaded', () => {
                const __root = document.getElementById('so-root');
                const WERKS = (__root?.dataset.werks || '').trim();
                const AUART = (__root?.dataset.auart || '').trim();
                const VBELN_HL = (__root?.dataset.hvbeln || '').trim();
                const KUNNR_HL = (__root?.dataset.hkunnr || '').trim();
                const POSNR_HL = (__root?.dataset.hposnr || '').trim();
                const AUTO = (__root?.dataset.auto || '0') === '1';

                // DOM Refs untuk Remark Modal
                const remarkModalEl = document.getElementById('remarkModal');
                if (remarkModalEl && remarkModalEl.parentElement !== document.body) {
                    document.body.appendChild(remarkModalEl);
                }
                let remarkModal = bootstrap.Modal.getInstance(remarkModalEl);
                if (!remarkModal) remarkModal = new bootstrap.Modal(remarkModalEl);

                const rmSO = document.getElementById('rm-so');
                const rmPOS = document.getElementById('rm-pos');
                const rmList = document.getElementById('remarkThreadList');
                const rmInput = document.getElementById('remark-input');
                const rmAddBtn = document.getElementById('add-remark-btn');
                const rmFeedback = document.getElementById('remark-feedback');
                const rmCounter = document.getElementById('remark-counter');

                const remarkModalState = {
                    werks: null,
                    auart: null,
                    vbeln: null,
                    posnr: null,
                    posnrKey: null
                };
                const MAX_REMARK = 60;

                function updateRmCounter() {
                    if (!rmInput || !rmCounter) return;
                    const n = (rmInput.value || '').length;
                    rmCounter.textContent = `${n}/${MAX_REMARK}`;
                }
                rmInput?.addEventListener('input', updateRmCounter);

                // Fungsi utama untuk memuat remark
                async function loadRemarkThread() {
                    if (!rmList) return;
                    rmList.innerHTML = `
                <div class="text-muted small d-flex align-items-center">
                  <div class="spinner-border spinner-border-sm me-2"></div>Memuat catatan...
                </div>`;

                    const u = new URL(apiListItemRemarks, window.location.origin);
                    u.searchParams.set('werks', remarkModalState.werks);
                    u.searchParams.set('auart', remarkModalState.auart);
                    u.searchParams.set('vbeln', remarkModalState.vbeln);
                    u.searchParams.set('posnr', remarkModalState.posnrKey);

                    try {
                        const r = await fetch(u, {
                            headers: {
                                'Accept': 'application/json'
                            }
                        });
                        const js = await r.json();
                        if (!js.ok) throw new Error(js.message || 'Gagal memuat catatan.');

                        rmList.innerHTML = js.data.length ? '' :
                            `<div class="text-muted">Belum ada catatan.</div>`;
                        js.data.forEach(it => {
                            const item = document.createElement('div');
                            item.className = 'remark-item' + (it.is_owner ? ' own' : '');
                            item.innerHTML = `
                    <div class="body flex-grow-1">
                      <div class="meta"><strong>${escapeHtml(it.user_name || 'User')}</strong> • <span>${escapeHtml(it.created_at || '')}</span></div>
                      <div class="text">${escapeHtml(it.remark || '')}</div>
                    </div>
                    <div class="act d-flex gap-1 align-items-center">
                      ${it.is_owner ? `<button type="button" class="btn btn-sm btn-outline-primary btn-edit-remark" data-id="${it.id}" data-remark="${escapeHtml(it.remark || '')}" title="Edit Catatan"><i class="fas fa-pencil-alt"></i></button>` : ''}
                      ${it.is_owner ? `<button type="button" class="btn btn-sm btn-outline-danger btn-delete-remark" data-id="${it.id}" title="Hapus Catatan"><i class="fas fa-trash"></i></button>` : ''}
                    </div>`;
                            rmList.appendChild(item);
                        });

                        // update badge count di T3
                        const rowSel =
                            `tr[data-werks='${remarkModalState.werks}'][data-auart='${remarkModalState.auart}'][data-vbeln='${remarkModalState.vbeln}'][data-posnr-key='${remarkModalState.posnrKey}']`;
                        const rowEl = document.querySelector(rowSel);
                        const badge = rowEl?.querySelector('.remark-count-badge');
                        if (badge) {
                            const c = js.data.length;
                            badge.dataset.count = String(c);
                            badge.textContent = String(c);
                            badge.style.display = c > 0 ? 'inline-block' : 'none';
                        }
                        recalcSoRemarkFlagFromDom(remarkModalState.vbeln);

                    } catch (err) {
                        rmList.innerHTML = `<div class="text-danger">${escapeHtml(err.message)}</div>`;
                    }
                }

                // Event Listener Customer Card Level-1
                document.querySelectorAll('.yz-customer-card').forEach(row => {
                    row.addEventListener('click', async () => {
                        const kunnr = row.dataset.kunnr;
                        const kid = row.dataset.kid;
                        const cname = row.dataset.cname;

                        const slot = document.getElementById(kid);
                        const wrap = slot.querySelector('.yz-nest-wrap');

                        const customerListContainer = row.closest('.d-grid');
                        const wasOpen = row.classList.contains('is-open');

                        // 1) Tutup card lain
                        document.querySelectorAll('.yz-customer-card.is-open').forEach(
                            r => {
                                if (r !== row) {
                                    const otherSlot = document.getElementById(r.dataset
                                        .kid);
                                    r.classList.remove('is-open');
                                    otherSlot.style.display = 'none';
                                    r.querySelector('.kunnr-caret')?.classList.remove(
                                        'rot');
                                    const otherWrap = otherSlot?.querySelector(
                                        '.yz-nest-wrap');
                                    otherWrap?.querySelectorAll('.js-t2row').forEach(
                                        so => {
                                            if (so.nextElementSibling.style
                                                .display !== 'none') {
                                                so.nextElementSibling.style
                                                    .display = 'none';
                                                so.querySelector('.yz-caret')
                                                    ?.classList.remove('rot');
                                            }
                                        });
                                    const otherTbody = otherWrap?.querySelector(
                                        'table tbody');
                                    if (otherTbody) setCollapse(otherTbody, false);
                                }
                            });

                        // 2) Toggle card aktif
                        row.classList.toggle('is-open', !wasOpen);
                        row.querySelector('.kunnr-caret')?.classList.toggle('rot', !
                            wasOpen);
                        slot.style.display = wasOpen ? 'none' : 'block';

                        // 3) Handle Small Qty
                        const smallQtySection = document.getElementById(
                            'small-qty-section');
                        const smallQtyDetailsContainer = document.getElementById(
                            'smallQtyDetailsContainer');
                        const chartCanvas = document.getElementById(
                            'chartSmallQtyByCustomer');
                        const smallQtyChartContainer = chartCanvas?.closest(
                            '.chart-container');

                        if (!wasOpen) {
                            customerListContainer.classList.add('customer-focus-mode');
                            document.querySelectorAll('.yz-customer-card').forEach(c => c
                                .classList.remove('is-focused'));
                            row.classList.add('is-focused');

                            const hasSmallQtyData = (Array.isArray(initialSmallQtyDataRaw) ?
                                    initialSmallQtyDataRaw : [])
                                .some(item => (item.NAME1 || '').trim() === (cname || '')
                                    .trim());

                            if (hasSmallQtyData && window.showSmallQtyDetails) {
                                await window.showSmallQtyDetails(cname, WERKS);
                            } else {
                                if (smallQtySection) smallQtySection.style.display = 'none';
                                if (smallQtyDetailsContainer) smallQtyDetailsContainer.style
                                    .display = 'none';
                            }
                        } else {
                            customerListContainer.classList.remove('customer-focus-mode');
                            document.querySelectorAll('.yz-customer-card').forEach(c => c
                                .classList.remove('is-focused'));
                            if (smallQtySection) smallQtySection.style.display = '';
                            if (smallQtyDetailsContainer) smallQtyDetailsContainer.style
                                .display = 'none';
                            if (smallQtyChartContainer) smallQtyChartContainer.style
                                .display = 'block';
                            if (chartCanvas) chartCanvas.style.display = 'block';
                            if (initialSmallQtyDataRaw.length > 0 && window
                                .renderSmallQtyChart) {
                                window.renderSmallQtyChart(initialSmallQtyDataRaw, WERKS);
                            }
                        }

                        // 4) Load Level-2 (SO list)
                        if (wasOpen) return;
                        if (wrap.dataset.loaded === '1') {
                            const soTbody = wrap.querySelector('table tbody');
                            if (soTbody) syncCheckAllSoHeader(soTbody);
                            return;
                        }

                        try {
                            wrap.innerHTML = `
                <div class="p-3 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
                  <div class="spinner-border spinner-border-sm me-2"></div>Memuat data…
                </div>`;

                            const url = new URL(apiSoByCustomer, window.location.origin);
                            url.searchParams.set('kunnr', kunnr);
                            url.searchParams.set('werks', WERKS);
                            url.searchParams.set('auart', AUART);

                            const res = await fetch(url, {
                                headers: {
                                    'Accept': 'application/json'
                                }
                            });
                            const js = await res.json();
                            if (!js.ok) throw new Error(js.error || 'Gagal memuat data SO');

                            const soRows = uniqBy(js.data, r => `${r.VBELN}`);
                            wrap.innerHTML = renderLevel2_SO(soRows, kunnr);
                            wrap.dataset.loaded = '1';

                            const soTable = wrap.querySelector('table');
                            const soTbody = soTable?.querySelector('tbody');
                            updateT2FooterVisibility(soTable);
                            if (soTbody) syncCheckAllSoHeader(soTbody);

                            // Tambahkan event listener untuk SO Row (Level-2)
                            wrap.querySelectorAll('.js-t2row').forEach(soRow => {
                                const soVbeln = soRow.dataset.vbeln;
                                updateSODot(soVbeln);

                                soRow.addEventListener('click', async (ev) => {
                                    if (ev.target.closest(
                                            '.check-so, .check-all-sos, .form-check-input, .remark-icon'
                                        )) return;
                                    ev.stopPropagation();

                                    const vbeln = soRow.dataset.vbeln;
                                    const tgtId = soRow.dataset.tgt;
                                    const itemTr = wrap.querySelector(
                                        '#' + tgtId);
                                    const box = itemTr.querySelector(
                                        '.yz-slot-items');
                                    const open = itemTr.style
                                        .display !== 'none';
                                    const t2tbl = soRow.closest(
                                        'table');
                                    const soTbody = soRow.closest(
                                        'tbody');

                                    soRow.querySelector('.yz-caret')
                                        ?.classList.toggle('rot');

                                    if (!open) {
                                        soTbody?.classList.add(
                                            'so-focus-mode');
                                        soRow.classList.add(
                                            'is-focused');
                                    } else {
                                        soTbody?.classList.remove(
                                            'so-focus-mode');
                                        soRow.classList.remove(
                                            'is-focused');
                                    }

                                    if (open) {
                                        itemTr.style.display = 'none';
                                        updateT2FooterVisibility(t2tbl);
                                        return;
                                    }

                                    itemTr.style.display = '';
                                    updateT2FooterVisibility(t2tbl);
                                    soRow.classList.remove(
                                        'row-highlighted');

                                    if (itemTr.dataset.loaded === '1') {
                                        applySelectionsToRenderedItems(
                                            box);
                                        syncCheckAllHeader(box);
                                        attachBootstrapPopovers(
                                            box
                                            ); // PENTING: Panggil Popover
                                        return;
                                    }

                                    box.innerHTML = `
                    <div class="p-2 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
                      <div class="spinner-border spinner-border-sm me-2"></div>Memuat item…
                    </div>`;
                                    try {
                                        const items =
                                            await ensureItemsLoadedForSO(
                                                vbeln, WERKS, AUART);
                                        box.innerHTML =
                                            renderLevel3_Items(items);
                                        applySelectionsToRenderedItems(
                                            box);
                                        syncCheckAllHeader(box);
                                        attachBootstrapPopovers(
                                            box
                                            ); // PENTING: Panggil Popover
                                        itemTr.dataset.loaded = '1';
                                    } catch (e) {
                                        console.error(
                                            'Items load error:', e);
                                        box.innerHTML =
                                            `<div class="alert alert-danger m-3">${escapeHtml(e.message)}</div>`;
                                    }
                                });
                            });

                        } catch (e) {
                            console.error('SO load error:', e);
                            wrap.innerHTML =
                                `<div class="alert alert-danger m-3">${escapeHtml(e.message)}</div>`;
                        }
                    });
                });

                // Event Listener Checkboxes (LENGKAP)
                document.body.addEventListener('change', async (e) => {
                    // single item (T3)
                    if (e.target.classList.contains('check-item')) {
                        const id = e.target.dataset.id;
                        if (e.target.checked) selectedItems.add(id);
                        else selectedItems.delete(id);

                        const vbeln = itemIdToSO.get(String(id));
                        if (vbeln) updateSODot(vbeln);
                        const box = e.target.closest('.yz-slot-items');
                        if (box) syncCheckAllHeader(box);
                        const soRow = vbeln ? document.querySelector(
                            `.js-t2row[data-vbeln='${CSS.escape(vbeln)}']`) : null;
                        const tbody = soRow?.closest('tbody');
                        if (tbody) syncCheckAllSoHeader(tbody);
                        updateExportButton();
                        return;
                    }

                    // check SO (T2)
                    if (e.target.classList.contains('check-so')) {
                        const chk = e.target;
                        const vbeln = chk.dataset.vbeln;
                        const isChecked = chk.checked;
                        const soRow = chk.closest('.js-t2row');
                        const soTbody = soRow?.closest('tbody');
                        const itemNest = soRow?.nextElementSibling;

                        const items = await ensureItemsLoadedForSO(vbeln, WERKS, AUART);
                        items.forEach(it => {
                            if (isChecked) selectedItems.add(String(it.id));
                            else selectedItems.delete(String(it.id));
                        });

                        if (itemNest && itemNest.dataset.loaded === '1') {
                            const box = itemNest.querySelector('.yz-slot-items');
                            box.querySelectorAll('.check-item').forEach(ch => ch.checked =
                                isChecked);
                            syncCheckAllHeader(box);
                        }

                        updateSODot(vbeln);
                        if (soTbody) syncCheckAllSoHeader(soTbody);
                        if (soTbody && getCollapse(soTbody)) await applyCollapseViewSo(soTbody,
                            true);
                        updateExportButton();
                        return;
                    }

                    // check-all items (T3)
                    if (e.target.classList.contains('check-all-items')) {
                        const table = e.target.closest('table');
                        if (!table) return;
                        const itemCheckboxes = table.querySelectorAll('.check-item');
                        itemCheckboxes.forEach(ch => {
                            ch.checked = e.target.checked;
                            const id = ch.dataset.id;
                            if (e.target.checked) selectedItems.add(id);
                            else selectedItems.delete(id);
                        });

                        const anyItem = table.querySelector('.check-item');
                        if (anyItem) {
                            const vbeln = itemIdToSO.get(String(anyItem.dataset.id));
                            if (vbeln) {
                                updateSODot(vbeln);
                                const soRow = document.querySelector(
                                    `.js-t2row[data-vbeln='${CSS.escape(vbeln)}']`);
                                const tbody = soRow?.closest('tbody');
                                if (tbody) syncCheckAllSoHeader(tbody);
                            }
                        }
                        updateExportButton();
                        return;
                    }

                    // check-all SO (T2)
                    if (e.target.classList.contains('check-all-sos')) {
                        const tbody = e.target.closest('table')?.querySelector('tbody');
                        if (!tbody) return;
                        const allSO = tbody.querySelectorAll('.check-so');

                        for (const chk of allSO) {
                            chk.checked = e.target.checked;
                            const vbeln = chk.dataset.vbeln;
                            const items = await ensureItemsLoadedForSO(vbeln, WERKS, AUART);
                            if (e.target.checked) items.forEach(it => selectedItems.add(String(it
                                .id)));
                            else {
                                Array.from(selectedItems).forEach(id => {
                                    if (itemIdToSO.get(String(id)) === vbeln) selectedItems
                                        .delete(id);
                                });
                            }
                            updateSODot(vbeln);

                            const soRow = chk.closest('.js-t2row');
                            const nest = soRow?.nextElementSibling;
                            if (nest && nest.dataset.loaded === '1') {
                                const box = nest.querySelector('.yz-slot-items');
                                box.querySelectorAll('.check-item').forEach(ch => ch.checked = e
                                    .target.checked);
                                const hdr = box.querySelector('table .check-all-items');
                                if (hdr) {
                                    hdr.checked = e.target.checked;
                                    hdr.indeterminate = false;
                                }
                            }
                        }
                        if (tbody) syncCheckAllSoHeader(tbody);
                        if (tbody && getCollapse(tbody)) await applyCollapseViewSo(tbody, true);
                        updateExportButton();
                        return;
                    }
                });

                // Toggle Collapse Mode (T2 header button) - (LENGKAP)
                async function applyCollapseViewSo(tbodyEl, on) {
                    if (!tbodyEl) return;
                    setCollapse(tbodyEl, on);

                    const headerCaret = tbodyEl.closest('table')?.querySelector(
                        '.js-collapse-toggle .yz-collapse-caret');
                    if (headerCaret) headerCaret.textContent = on ? '▾' : '▸';

                    tbodyEl.querySelector('.yz-empty-selected-row')?.remove();
                    tbodyEl.classList.remove('so-focus-mode');
                    tbodyEl.classList.toggle('collapse-mode', on);

                    const soRows = tbodyEl.querySelectorAll('.js-t2row');

                    if (on) {
                        let visibleCount = 0;
                        for (const r of soRows) {
                            const chk = r.querySelector('.check-so');
                            const isT3Open = r.nextElementSibling.style.display !== 'none';
                            if (chk?.checked) {
                                r.style.display = '';
                                visibleCount++;
                                if (!isT3Open) r.click();
                            } else {
                                r.style.display = 'none';
                                if (isT3Open) r.click();
                                else {
                                    r.nextElementSibling.style.display = 'none';
                                    r.querySelector('.yz-caret')?.classList.remove('rot');
                                }
                            }
                        }
                        if (visibleCount === 0 && getCollapse(tbodyEl)) {
                            await applyCollapseViewSo(tbodyEl, false);
                            return;
                        }
                    } else {
                        soRows.forEach(r => {
                            r.style.display = '';
                            r.classList.remove('is-focused');
                            if (r.nextElementSibling.style.display !== 'none') {
                                r.nextElementSibling.style.display = 'none';
                                r.querySelector('.yz-caret')?.classList.remove('rot');
                            }
                        });
                    }

                    syncCheckAllSoHeader(tbodyEl);
                    updateT2FooterVisibility(tbodyEl.closest('table'));
                }

                document.body.addEventListener('click', async (e) => {
                    const toggleBtn = e.target.closest('.js-collapse-toggle');
                    if (!toggleBtn) return;
                    e.stopPropagation();
                    const soTbody = toggleBtn.closest('table')?.querySelector('tbody');
                    if (soTbody) await applyCollapseViewSo(soTbody, !getCollapse(soTbody));
                });

                // Event Listener Export Button (LENGKAP)
                if (exportDropdownContainer) {
                    exportDropdownContainer.addEventListener('click', (e) => {
                        const opt = e.target.closest('.export-option');
                        if (!opt) return;
                        e.preventDefault();
                        const exportType = opt.dataset.type;
                        if (selectedItems.size === 0) {
                            alert('Pilih setidaknya satu item untuk diekspor.');
                            return;
                        }

                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = exportUrl;
                        form.target = '_blank';
                        const add = (n, v) => {
                            const i = document.createElement('input');
                            i.type = 'hidden';
                            i.name = n;
                            i.value = v;
                            form.appendChild(i);
                        };
                        add('_token', csrfToken);
                        add('export_type', exportType);
                        add('werks', WERKS);
                        add('auart', AUART);
                        selectedItems.forEach(id => add('item_ids[]', id));
                        document.body.appendChild(form);
                        form.submit();
                        document.body.removeChild(form);
                    });
                }

                // Event Listener Remark Modal (LENGKAP)
                document.body.addEventListener('click', async (e) => {
                    const icon = e.target.closest('.remark-icon');
                    if (!icon) return;

                    const row = icon.closest('tr');
                    remarkModalState.werks = row.dataset.werks;
                    remarkModalState.auart = row.dataset.auart;
                    remarkModalState.vbeln = row.dataset.vbeln;
                    remarkModalState.posnr = row.dataset.posnr;
                    remarkModalState.posnrKey = row.dataset.posnrKey;

                    if (rmSO) rmSO.textContent = remarkModalState.vbeln;
                    if (rmPOS) rmPOS.textContent = remarkModalState.posnr;

                    if (rmInput) rmInput.value = '';
                    updateRmCounter();
                    if (rmFeedback) rmFeedback.textContent = '';

                    const addForm = rmAddBtn?.closest('.mb-2');
                    if (addForm) addForm.style.display = '';

                    remarkModal.show();
                    await loadRemarkThread();
                });

                rmAddBtn?.addEventListener('click', async () => {
                    const text = (rmInput?.value || '').trim();
                    if (!text) {
                        if (rmFeedback) {
                            rmFeedback.textContent = 'Teks catatan tidak boleh kosong.';
                            rmFeedback.className = 'text-danger small';
                        }
                        return;
                    }
                    if (text.length > MAX_REMARK) {
                        if (rmFeedback) {
                            rmFeedback.textContent = `Maksimal ${MAX_REMARK} karakter.`;
                            rmFeedback.className = 'text-danger small';
                        }
                        return;
                    }

                    rmAddBtn.disabled = true;
                    rmAddBtn.innerHTML = `<span class="spinner-border spinner-border-sm"></span>`;
                    try {
                        const res = await fetch(apiAddItemRemark, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                werks: remarkModalState.werks,
                                auart: remarkModalState.auart,
                                vbeln: remarkModalState.vbeln,
                                posnr: remarkModalState.posnrKey,
                                remark: text
                            })
                        });
                        const js = await res.json();
                        if (!res.ok || !js.ok) throw new Error(js.message ||
                            'Gagal menambah catatan.');

                        if (rmInput) rmInput.value = '';
                        updateRmCounter();
                        if (rmFeedback) {
                            rmFeedback.textContent = 'Catatan ditambahkan.';
                            rmFeedback.className = 'text-success small';
                        }
                        itemsCache.delete(remarkModalState.vbeln); // invalidate cache
                        await loadRemarkThread();
                    } catch (err) {
                        if (rmFeedback) {
                            rmFeedback.textContent = err.message;
                            rmFeedback.className = 'text-danger small';
                        }
                    } finally {
                        rmAddBtn.disabled = false;
                        rmAddBtn.innerHTML = `<i class="fas fa-paper-plane me-1"></i> Tambah`;
                    }
                });

                rmList?.addEventListener('click', async (e) => {
                    // Delete
                    const delBtn = e.target.closest('.btn-delete-remark');
                    if (delBtn) {
                        e.preventDefault();
                        const id = delBtn.dataset.id;
                        if (!confirm('Hapus catatan ini?')) return;
                        delBtn.disabled = true;
                        try {
                            const delUrl = apiDeleteItemRemarkTpl.replace('___ID___', id);
                            const r = await fetch(delUrl, {
                                method: 'DELETE',
                                headers: {
                                    'X-CSRF-TOKEN': csrfToken,
                                    'Accept': 'application/json'
                                }
                            });
                            const js = await r.json();
                            if (!r.ok || !js.ok) throw new Error(js.message ||
                                'Gagal menghapus catatan.');
                            itemsCache.delete(remarkModalState.vbeln);
                            await loadRemarkThread();
                        } catch (err) {
                            alert(err.message);
                        } finally {
                            delBtn.disabled = false;
                        }
                        return;
                    }

                    // Edit
                    const editBtn = e.target.closest('.btn-edit-remark');
                    if (editBtn) {
                        e.preventDefault();
                        const id = editBtn.dataset.id;
                        const currentRemark = editBtn.dataset.remark;
                        const addForm = rmAddBtn?.closest('.mb-2');
                        if (addForm) addForm.style.display = 'none';

                        const remarkItemEl = editBtn.closest('.remark-item');
                        const remarkTextEl = remarkItemEl.querySelector('.text');
                        const actionEl = remarkItemEl.querySelector('.act');

                        remarkTextEl.innerHTML =
                            `<textarea class="form-control" rows="2" maxlength="60" id="edit-remark-${id}">${currentRemark}</textarea>`;
                        actionEl.innerHTML = `
                <button type="button" class="btn btn-success btn-sm btn-save-edit" data-id="${id}">Save</button>
                <button type="button" class="btn btn-outline-secondary btn-sm btn-cancel-edit">Cancel</button>`;
                        document.getElementById(`edit-remark-${id}`)?.focus();
                        return;
                    }

                    // Cancel Edit
                    const cancelBtn = e.target.closest('.btn-cancel-edit');
                    if (cancelBtn) {
                        e.preventDefault();
                        const addForm = rmAddBtn?.closest('.mb-2');
                        if (addForm) addForm.style.display = '';
                        await loadRemarkThread();
                        return;
                    }

                    // Save Edit
                    const saveBtn = e.target.closest('.btn-save-edit');
                    if (saveBtn) {
                        e.preventDefault();
                        const id = saveBtn.dataset.id;
                        const newRemarkInput = document.getElementById(`edit-remark-${id}`);
                        const newRemark = (newRemarkInput?.value || '').trim();
                        if (!newRemark || newRemark.length > 60) {
                            alert('Catatan tidak boleh kosong dan maksimal 60 karakter.');
                            return;
                        }

                        saveBtn.disabled = true;
                        saveBtn.innerHTML =
                            `<span class="spinner-border spinner-border-sm"></span>`;
                        const url = apiUpdateItemRemarkTpl.replace('___ID___', id);
                        try {
                            const res = await fetch(url, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': csrfToken,
                                    'Accept': 'application/json'
                                },
                                body: JSON.stringify({
                                    _method: 'PUT',
                                    remark: newRemark
                                })
                            });
                            const js = await res.json();
                            if (!res.ok || !js.ok) throw new Error(js.message ||
                                'Gagal menyimpan perubahan.');
                            itemsCache.delete(remarkModalState.vbeln);
                            const addForm = rmAddBtn?.closest('.mb-2');
                            if (addForm) addForm.style.display = '';
                            await loadRemarkThread();
                        } catch (err) {
                            alert(err.message);
                        } finally {
                            saveBtn.disabled = false;
                            saveBtn.innerHTML = `Save`;
                        }
                        return;
                    }
                });

                /* =========================================================
                 * NAVIGATE to SO (full logic)
                 * ======================================================= */
                window.navigateToSO = async function(vbeln, customerName = '', posnr = '') {
                    const forceOpen = (posnr === '__OPEN__');
                    const POSNR6 = (!forceOpen && posnr) ? String(posnr).replace(/\D/g, '').padStart(6,
                        '0') : '';

                    const findItemRow = (box, v, pos6) => {
                        const rows = box?.querySelectorAll(`tr[data-vbeln='${CSS.escape(v)}']`) ||
                        [];
                        for (const tr of rows)
                            if ((tr.dataset.posnrKey || '') === pos6) return tr;
                        return null;
                    };

                    async function openInsideCard(cardEl, {
                        openItems = false
                    } = {}) {
                        if (!cardEl) return {
                            soRow: null,
                            itemsBox: null
                        };
                        if (!cardEl.classList.contains('is-open')) cardEl.click();

                        const wrap = document.getElementById(cardEl.dataset.kid)?.querySelector(
                            '.yz-nest-wrap');
                        const okT2 = await waitFor(() => wrap && wrap.dataset.loaded === '1');
                        if (!okT2) return {
                            soRow: null,
                            itemsBox: null
                        };

                        const soRow = wrap.querySelector(
                            `.js-t2row[data-vbeln='${CSS.escape(vbeln)}']`);
                        if (!soRow) return {
                            soRow: null,
                            itemsBox: null
                        };

                        const nest = soRow.nextElementSibling;
                        const itemsBox = nest?.querySelector('.yz-slot-items');
                        const isOpen = nest && nest.style.display !== 'none';
                        if (openItems && !isOpen) soRow.click();

                        if (openItems) {
                            const okT3 = await waitFor(() => nest && nest.dataset.loaded === '1');
                            if (!okT3) return {
                                soRow,
                                itemsBox: null
                            };
                        }
                        return {
                            soRow,
                            itemsBox
                        };
                    }

                    // Prioritas: berdasarkan nama customer
                    if (customerName) {
                        const card = [...document.querySelectorAll('.yz-customer-card')]
                            .find(c => (c.dataset.cname || '').trim() === customerName.trim());
                        const {
                            soRow,
                            itemsBox
                        } = await openInsideCard(card, {
                            openItems: (forceOpen || !!POSNR6)
                        });
                        if (soRow) {
                            scrollAndFlash(soRow);
                            soRow.classList.remove('row-highlighted');
                            if (POSNR6 && itemsBox) {
                                const tr = findItemRow(itemsBox, vbeln, POSNR6);
                                if (tr) scrollAndFlash(tr);
                            }
                            return;
                        }
                    }

                    // Fallback: telusuri semua card
                    for (const card of document.querySelectorAll('.yz-customer-card')) {
                        const {
                            soRow,
                            itemsBox
                        } = await openInsideCard(card, {
                            openItems: (forceOpen || !!POSNR6)
                        });
                        if (soRow) {
                            scrollAndFlash(soRow);
                            if (POSNR6 && itemsBox) {
                                const tr = findItemRow(itemsBox, vbeln, POSNR6);
                                if (tr) scrollAndFlash(tr);
                            }
                            return;
                        }
                    }

                    alert(`SO ${vbeln} tidak ditemukan pada daftar ini.`);
                };

                // Delegasi klik untuk semua link SO (termasuk Small Qty table dan VBELN di Tabel-2)
                document.addEventListener('click', async (e) => {
                    const link = e.target.closest('.js-open-so');
                    if (!link) return;
                    e.preventDefault();

                    const t2row = link.closest('.js-t2row');
                    if (t2row) {
                        const nest = t2row.nextElementSibling;
                        if (nest && (nest.style.display === 'none' || nest.dataset.loaded !== '1'))
                            t2row.click();
                        return;
                    }

                    const forceOpen = link.dataset.openItems === '1';
                    await window.navigateToSO(
                        (link.dataset.vbeln || '').trim(),
                        (link.dataset.cname || '').trim(),
                        forceOpen ? '__OPEN__' : (link.dataset.posnr || '').trim()
                    );
                });

                /* =========================================================
                 * SMALL QTY (Chart + Details + Export) - LENGKAP
                 * ======================================================= */
                const smallQtyDetailsContainer = document.getElementById('smallQtyDetailsContainer');
                const smallQtyDetailsTable = document.getElementById('smallQtyDetailsTable');
                const smallQtyDetailsTitle = document.getElementById('smallQtyDetailsTitle');
                const smallQtyMeta = document.getElementById('smallQtyMeta');
                const exportSmallQtyPdfBtn = document.getElementById('exportSmallQtyPdf');
                const exportForm = document.getElementById('smallQtyExportForm');
                const smallQtySection = document.getElementById('small-qty-section');
                const chartCanvas = document.getElementById('chartSmallQtyByCustomer');
                const smallQtyChartContainer = chartCanvas?.closest('.chart-container');
                let smallQtyChartInstance = null;

                async function showSmallQtyDetails(customerName, werks) {
                    const root = document.getElementById('so-root');
                    const currentAuart = (root?.dataset.auart || '').trim();

                    if (smallQtyChartContainer) smallQtyChartContainer.style.display = 'none';
                    if (smallQtySection) smallQtySection.style.display = '';
                    smallQtyDetailsTitle.textContent = `Detail Item Outstanding (≤5) untuk ${customerName}`;
                    smallQtyMeta.textContent = '';
                    exportSmallQtyPdfBtn.disabled = true;

                    smallQtyDetailsTable.innerHTML = `
        <div class="d-flex justify-content-center align-items-center p-5">
          <div class="spinner-border text-primary" role="status"></div>
          <span class="ms-3 text-muted">Memuat data...</span>
        </div>`;
                    smallQtyDetailsContainer.style.display = 'block';

                    try {
                        const apiUrl = new URL(apiSmallQtyDetails, window.location.origin);
                        apiUrl.searchParams.append('customerName', customerName);
                        apiUrl.searchParams.append('werks', werks);
                        apiUrl.searchParams.append('auart', currentAuart);

                        const resp = await fetch(apiUrl, {
                            headers: {
                                'Accept': 'application/json'
                            }
                        });
                        const result = await resp.json();

                        const rows = Array.isArray(result.data) ? result.data : [];
                        if (!result.ok || rows.length === 0) {
                            smallQtyMeta.textContent = '';
                            exportSmallQtyPdfBtn.disabled = true;
                            smallQtyDetailsTable.innerHTML =
                                `<div class="text-center p-5 text-muted">Data item Small Quantity (Outs. SO &le; 5) tidak ditemukan untuk customer ini.</div>`;
                            return;
                        }

                        const uniqSO = new Set(rows.map(r => String(r.SO || '').trim()).filter(Boolean));
                        smallQtyMeta.textContent =
                            `(SO: ${uniqSO.size.toLocaleString('id-ID')} • Item: ${rows.length.toLocaleString('id-ID')})`;
                        exportSmallQtyPdfBtn.disabled = false;
                        if (exportForm) exportForm.querySelector('#exp_customerName').value = customerName;

                        rows.sort((a, b) => (parseFloat(a.PACKG ?? 0) - parseFloat(b.PACKG ?? 0)));

                        const headers = `
            <tr>
              <th style="width:5%;" class="text-center">No.</th>
              <th class="text-center">SO</th>
              <th class="text-center">Item</th>
              <th>Description</th>
              <th class="text-end">Shipped</th>
              <th class="text-end">Qty SO</th>
              <th class="text-end">Outs. SO (≤5)</th>
              <th class="text-end">WHFG</th>
              <th class="text-end">Stock Packing</th>
            </tr>`;

                        const body = rows.map((it, idx) => {
                            const so = String(it.SO || '').trim();
                            const pos = String(it.POSNR || '').replace(/\D/g, '').padStart(6, '0');

                            return `
              <tr class="js-sq-row"
                  style="cursor:pointer;"
                  data-vbeln="${so}"
                  data-posnr-key="${pos}"
                  data-cname="${customerName}">
                <td class="text-center">${idx + 1}</td>
                <td class="text-center fw-bold">
                  <a href="#"
                     class="js-open-so text-decoration-none"
                     data-vbeln="${so}"
                     data-cname="${customerName}"
                     data-posnr="${pos}"
                     data-open-items="1">${so}</a>
                </td>
                <td class="text-center">${it.POSNR ?? ''}</td>
                <td>${it.MAKTX ?? ''}</td>
                <td class="text-end">${formatNumberGlobal(it.QTY_GI)}</td>
                <td class="text-end">${formatNumberGlobal(it.KWMENG)}</td>
                <td class="text-end fw-bold text-danger">${formatNumberGlobal(it.PACKG)}</td>
                <td class="text-end">${formatNumberGlobal(it.KALAB)}</td>
                <td class="text-end">${formatNumberGlobal(it.KALAB2)}</td>
              </tr>`;
                        }).join('');

                        smallQtyDetailsTable.innerHTML = `
            <div class="table-responsive yz-scrollable-table-container" style="max-height: 400px;">
              <table class="table table-striped table-hover table-sm align-middle">
                <thead class="table-light">${headers}</thead>
                <tbody>${body}</tbody>
              </table>
            </div>`;

                    } catch (err) {
                        console.error('Gagal mengambil data detail Small Qty:', err);
                        smallQtyMeta.textContent = '';
                        exportSmallQtyPdfBtn.disabled = true;
                        smallQtyDetailsTable.innerHTML =
                            `<div class="text-center p-5 text-danger">Terjadi kesalahan saat memuat data.</div>`;
                    }
                }

                function renderSmallQtyChart(dataToRender, werks) {
                    const ctxSmallQty = document.getElementById('chartSmallQtyByCustomer');
                    const plantCode = (werks === '3000') ? 'Semarang' : 'Surabaya';
                    const barColor = (werks === '3000') ? '#198754' : '#ffc107';

                    const customerMap = new Map();
                    const totalItemCountMap = new Map();
                    dataToRender.forEach(item => {
                        const name = (item.NAME1 || '').trim();
                        if (!name) return;

                        const currentCount = customerMap.get(name) || 0;
                        customerMap.set(name, currentCount + parseInt(item.so_count, 10));

                        const currentItemCount = totalItemCountMap.get(name) || 0;
                        totalItemCountMap.set(name, currentItemCount + parseInt(item.item_count, 10));
                    });

                    const sortedCustomers = [...customerMap.entries()].sort((a, b) => b[1] - a[1]);
                    const labels = sortedCustomers.map(item => item[0]);
                    const soCounts = sortedCustomers.map(item => item[1]);
                    const totalSoCount = soCounts.reduce((sum, count) => sum + count, 0);
                    const totalItemCount = dataToRender.reduce((sum, item) => sum + parseInt(item.item_count,
                        10), 0);

                    const noDataEl = ctxSmallQty?.closest('.chart-container').querySelector('.yz-nodata');
                    if (!ctxSmallQty || dataToRender.length === 0 || totalSoCount === 0) {
                        if (smallQtyChartContainer) smallQtyChartContainer.style.display = 'block';
                        if (chartCanvas) chartCanvas.style.display = 'none';
                        if (noDataEl) noDataEl.style.display = 'block';
                        if (smallQtyDetailsContainer) smallQtyDetailsContainer.style.display = 'none';
                        if (smallQtyChartInstance) smallQtyChartInstance.destroy();
                        return;
                    } else {
                        if (chartCanvas) chartCanvas.style.display = 'block';
                        if (noDataEl) noDataEl.style.display = 'none';
                    }

                    const dynamicHeight = Math.max(200, Math.min(50 * labels.length, 600));
                    if (chartCanvas) {
                        chartCanvas.closest('.chart-container').style.height = dynamicHeight + 'px';
                    }

                    if (smallQtyChartInstance) smallQtyChartInstance.destroy();

                    smallQtyChartInstance = new Chart(ctxSmallQty, {
                        type: 'bar',
                        data: {
                            labels,
                            datasets: [{
                                label: plantCode,
                                data: soCounts,
                                backgroundColor: barColor
                            }]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: {
                                    stacked: false,
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Sales Order (With Outs. Item Qty ≤ 5)'
                                    },
                                    ticks: {
                                        callback: (value) => {
                                            if (Math.floor(value) === value) return value;
                                        }
                                    }
                                },
                                y: {
                                    stacked: false
                                }
                            },
                            plugins: {
                                legend: {
                                    display: true
                                },
                                tooltip: {
                                    callbacks: {
                                        label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.x} SO`
                                    }
                                }
                            },
                            onClick: async (event, elements) => {
                                if (elements.length === 0) return;
                                const barElement = elements[0];
                                const customerName = labels[barElement.index];
                                await showSmallQtyDetails(customerName, werks);
                            }
                        }
                    });
                    window.smallQtyChartInstance = smallQtyChartInstance;
                }

                // Expose fungsi Small Qty (harus ada di global scope)
                window.showSmallQtyDetails = showSmallQtyDetails;
                window.renderSmallQtyChart = renderSmallQtyChart;


                // Init chart (awal halaman)
                if (document.getElementById('chartSmallQtyByCustomer')) {
                    if (initialSmallQtyDataRaw && initialSmallQtyDataRaw.length > 0) {
                        renderSmallQtyChart(initialSmallQtyDataRaw, WERKS);
                    } else {
                        const sec = document.getElementById('small-qty-section');
                        if (sec) sec.style.display = 'none';
                        if (smallQtyDetailsContainer) smallQtyDetailsContainer.style.display = 'none';
                    }
                }

                // DELEGASI KLIK ROW Small Qty
                if (!window.__sqRowDelegationBound) {
                    document.addEventListener('click', (e) => {
                        const tr = e.target.closest('#smallQtyDetailsTable tr.js-sq-row');
                        if (!tr) return;

                        if (e.target.closest(
                                'a, button, .form-check-input, .form-select, .form-control')) return;

                        e.preventDefault();
                        const vbeln = (tr.dataset.vbeln || '').trim();
                        const cname = (tr.dataset.cname || '').trim();
                        const posnr = (tr.dataset.posnrKey || '')
                            .trim();

                        if (window.navigateToSO && vbeln) {
                            window.navigateToSO(vbeln, cname, posnr);
                        }
                    });
                    window.__sqRowDelegationBound = true;
                }

                // Export Small Qty PDF
                document.getElementById('exportSmallQtyPdf')?.addEventListener('click', (e) => {
                    e.preventDefault();
                    document.getElementById('smallQtyExportForm')?.submit();
                });

                // Close Small Qty Details
                document.getElementById('closeDetailsTable')?.addEventListener('click', () => {
                    document.getElementById('smallQtyDetailsContainer').style.display = 'none';
                    if (smallQtyChartContainer) smallQtyChartContainer.style.display = 'block';
                    if (initialSmallQtyDataRaw && initialSmallQtyDataRaw.length > 0) {
                        renderSmallQtyChart(initialSmallQtyDataRaw, WERKS);
                    }
                });

                /* =========================================================
                 * AUTO-EXPAND dari root highlight (full logic)
                 * ======================================================= */
                (async function autoExpandFromRoot() {
                    const VBELN = VBELN_HL,
                        KUNNR = KUNNR_HL,
                        POSNR = POSNR_HL,
                        shouldAuto = AUTO;
                    const POSNR6 = POSNR ? String(POSNR).replace(/\D/g, '').padStart(6, '0') : '';

                    const findItemRow = (box, v, pos6) => {
                        const rows = box?.querySelectorAll(`tr[data-vbeln='${CSS.escape(v)}']`) ||
                        [];
                        for (const tr of rows)
                            if ((tr.dataset.posnrKey || '') === pos6) return tr;
                        return null;
                    };

                    async function openToSO(customerRow, {
                        openItems = false
                    } = {}) {
                        if (!customerRow) return {
                            wrap: null,
                            soRow: null,
                            itemsBox: null
                        };
                        if (!customerRow.classList.contains('is-open')) customerRow.click();

                        const wrap = customerRow.nextElementSibling?.querySelector('.yz-nest-wrap');
                        const okT2 = await waitFor(() => wrap && wrap.dataset.loaded === '1', {
                            timeout: 7000
                        });
                        if (!okT2) return {
                            wrap: null,
                            soRow: null,
                            itemsBox: null
                        };

                        const soRow = wrap.querySelector(
                            `.js-t2row[data-vbeln='${CSS.escape(VBELN)}']`);
                        if (!soRow) return {
                            wrap,
                            soRow: null,
                            itemsBox: null
                        };

                        const itemNest = soRow.nextElementSibling;
                        const itemsBox = itemNest?.querySelector('.yz-slot-items');
                        const isOpen = itemNest && itemNest.style.display !== 'none';
                        if (openItems && !isOpen) soRow.click();

                        if (!openItems) return {
                            wrap,
                            soRow,
                            itemsBox: null
                        };

                        const okT3 = await waitFor(() => itemNest && itemNest.dataset.loaded ===
                            '1', {
                                timeout: 7000
                            });
                        if (!okT3) return {
                            wrap,
                            soRow,
                            itemsBox: null
                        };
                        return {
                            wrap,
                            soRow,
                            itemsBox
                        };
                    }

                    if (!(shouldAuto && (VBELN || KUNNR))) return;

                    if (VBELN && KUNNR) {
                        const crow = document.querySelector(
                            `.yz-customer-card[data-kunnr='${CSS.escape(KUNNR)}']`);
                        const {
                            soRow,
                            itemsBox
                        } = await openToSO(crow, {
                            openItems: !!POSNR6
                        });
                        if (!soRow) return;
                        scrollAndFlash(soRow);
                        if (POSNR6 && itemsBox) {
                            const itemTr = findItemRow(itemsBox, VBELN, POSNR6);
                            if (itemTr) scrollAndFlash(itemTr);
                            soRow.classList.remove('row-highlighted');
                        }
                        return;
                    }

                    if (VBELN && !KUNNR) {
                        let foundSoRow = null,
                            foundItemsBox = null;
                        const customerRows = Array.from(document.querySelectorAll('.yz-customer-card'));
                        for (const row of customerRows) {
                            const {
                                soRow,
                                itemsBox
                            } = await openToSO(row, {
                                openItems: !!POSNR6
                            });
                            if (soRow) {
                                foundSoRow = soRow;
                                foundItemsBox = itemsBox;
                                break;
                            }
                        }
                        if (!foundSoRow) return;
                        scrollAndFlash(foundSoRow);
                        if (POSNR6 && foundItemsBox) {
                            const itemTr = findItemRow(foundItemsBox, VBELN, POSNR6);
                            if (itemTr) scrollAndFlash(itemTr);
                        }
                    }
                })();

            }); // DOMContentLoaded
        })(); // IIFE
    </script>
@endpush
@push('scripts')
    <script>
        // Data harus dikirim dari Controller ke Blade (SO Report Controller sudah mengirim data ini)
        const initialSmallQtyDataRaw = {!! json_encode($smallQtyByCustomer ?? collect()) !!};

        /* ====================== SMALL QTY CHART LOGIC ====================== */
        let smallQtyChartInstance = null;
        const smallQtyDetailsContainer = document.getElementById('smallQtyDetailsContainer');
        const smallQtyDetailsTable = document.getElementById('smallQtyDetailsTable');
        const smallQtyDetailsTitle = document.getElementById('smallQtyDetailsTitle');
        const smallQtyMeta = document.getElementById('smallQtyMeta');
        const exportSmallQtyPdfBtn = document.getElementById('exportSmallQtyPdf');
        const exportForm = document.getElementById('smallQtyExportForm');
        const smallQtySection = document.getElementById('small-qty-section');
        const smallQtyChartTitle = document.getElementById('small-qty-chart-title');
        const smallQtyTotalItem = document.getElementById('small-qty-total-item');
        const chartCanvas = document.getElementById('chartSmallQtyByCustomer');
        const smallQtyChartContainer = chartCanvas?.closest('.chart-container');

        const formatNumberChart = (v) => {
            const n = parseFloat(v);
            if (!Number.isFinite(n)) return '';
            return n.toLocaleString('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });
        };
        // Fungsi ini dikembalikan ke global scope agar bisa dipanggil dari event listener Level-1
        function renderSmallQtyChart(dataToRender, werks) {
            const ctxSmallQty = document.getElementById('chartSmallQtyByCustomer');
            const plantCode = (werks === '3000') ? 'Semarang' : 'Surabaya';

            const barColor = (werks === '3000') ? '#198754' : '#ffc107';

            const customerMap = new Map();
            const totalItemCountMap = new Map(); // Map untuk total item (hanya untuk footer kecil)
            dataToRender.forEach(item => {
                const name = (item.NAME1 || '').trim();
                if (!name) return;

                // MODIFIKASI: Gunakan 'so_count' dari Controller
                const currentCount = customerMap.get(name) || 0;
                customerMap.set(name, currentCount + parseInt(item.so_count, 10)); // MENGHITUNG SO

                // Tetap hitung total ITEM untuk keterangan di judul
                const currentItemCount = totalItemCountMap.get(name) || 0;
                totalItemCountMap.set(name, currentItemCount + parseInt(item.item_count, 10)); // MENGHITUNG ITEM
            });

            const sortedCustomers = [...customerMap.entries()].sort((a, b) => b[1] - a[1]);
            const labels = sortedCustomers.map(item => item[0]);

            // MODIFIKASI: Ambil itemCounts dari SO Count
            const soCounts = sortedCustomers.map(item => item[1]);

            // Total SO Count Keseluruhan (untuk chart)
            const totalSoCount = soCounts.reduce((sum, count) => sum + count, 0);

            // Total ITEM Count Keseluruhan (untuk label judul)
            const totalItemCount = dataToRender.reduce((sum, item) => sum + parseInt(item.item_count, 10), 0);

            const noDataEl = ctxSmallQty?.closest('.chart-container').querySelector('.yz-nodata');
            if (!ctxSmallQty || dataToRender.length === 0 || totalSoCount === 0) { // Pengecekan menggunakan totalSoCount
                if (smallQtyChartContainer) smallQtyChartContainer.style.display = 'block';
                if (chartCanvas) chartCanvas.style.display = 'none';
                if (noDataEl) noDataEl.style.display = 'block';
                if (smallQtyDetailsContainer) smallQtyDetailsContainer.style.display = 'none';
                if (smallQtyChartInstance) smallQtyChartInstance.destroy();
                return;
            } else {
                if (chartCanvas) chartCanvas.style.display = 'block';
                if (noDataEl) noDataEl.style.display = 'none';
            }


            // Set height
            const dynamicHeight = Math.max(200, Math.min(50 * labels.length, 600));
            if (chartCanvas) {
                chartCanvas.closest('.chart-container').style.height = dynamicHeight + 'px';
            }

            if (smallQtyChartInstance) smallQtyChartInstance.destroy();

            smallQtyChartInstance = new Chart(ctxSmallQty, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: plantCode,
                        // MODIFIKASI: Menggunakan soCounts
                        data: soCounts,
                        backgroundColor: barColor
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            stacked: false,
                            beginAtZero: true,
                            title: {
                                display: true,
                                // MODIFIKASI LABEL: Menampilkan "SO"
                                text: 'Sales Order (With Outs. Item Qty ≤ 5)'
                            },
                            ticks: {
                                callback: (value) => {
                                    if (Math.floor(value) === value) return value;
                                }
                            }
                        },
                        y: {
                            stacked: false
                        }
                    },
                    plugins: {
                        legend: {
                            display: true
                        },
                        tooltip: {
                            callbacks: {
                                // MODIFIKASI TOOLTIP: Menampilkan "SO"
                                label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.x} SO`
                            }
                        }
                    },
                    onClick: async (event, elements) => {
                        if (elements.length === 0) return;
                        const barElement = elements[0];
                        const customerName = labels[barElement.index];
                        await showSmallQtyDetails(customerName, werks);
                    }
                }
            });
            window.smallQtyChartInstance = smallQtyChartInstance;
        }

        window.showSmallQtyDetails = showSmallQtyDetails;
        window.renderSmallQtyChart = renderSmallQtyChart;


        document.addEventListener('DOMContentLoaded', () => {
            const root = document.getElementById('so-root');
            const WERKS = (root?.dataset.werks || '').trim();

            if (chartCanvas) {
                if (initialSmallQtyDataRaw && initialSmallQtyDataRaw.length > 0) {
                    renderSmallQtyChart(initialSmallQtyDataRaw, WERKS);
                } else {
                    if (smallQtySection) smallQtySection.style.display = 'none';
                    if (smallQtyDetailsContainer) smallQtyDetailsContainer.style.display = 'none';
                }
            }

            const closeButton = document.getElementById('closeDetailsTable');
            closeButton?.addEventListener('click', () => {
                document.getElementById('smallQtyDetailsContainer').style.display = 'none';
                if (smallQtyChartContainer) smallQtyChartContainer.style.display = 'block';
                if (initialSmallQtyDataRaw && initialSmallQtyDataRaw.length > 0) {
                    renderSmallQtyChart(initialSmallQtyDataRaw, WERKS);
                }
            });

            const exportForm = document.getElementById('smallQtyExportForm');
            const exportSmallQtyPdfBtn = document.getElementById('exportSmallQtyPdf');
            if (exportSmallQtyPdfBtn && exportForm) {
                exportSmallQtyPdfBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    exportForm.submit();
                });
            }
        });
    </script>
@endpush
