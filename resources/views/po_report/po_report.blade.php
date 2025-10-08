@extends('layouts.app')

@section('title', 'PO Report by Customer')

@section('content')

    @php
        // Ambil nilai dari controller / query
        $werks = $selected['werks'] ?? null;
        $auart = $selected['auart'] ?? null;
        $show = filled($werks) && filled($auart);
        $onlyWerksSelected = filled($werks) && empty($auart);
        $compact = $compact ?? true; // default true

        // DATA BARU DARI CONTROLLER
        $performanceData = $performanceData ?? collect();
        $smallQtyByCustomer = $smallQtyByCustomer ?? collect();

        // Asumsikan $selectedDescription dikirim dari controller
        $selectedDescription = $selectedDescription ?? '';
        $isExportContext = Str::contains(strtolower($selectedDescription), 'export');

        $locationMap = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locName = $locationMap[$werks] ?? $werks;

        // Helper URL terenkripsi ke /po-report
        $encReport = function (array $params) {
            $payload = array_filter(array_merge(['compact' => 1], $params), fn($v) => !is_null($v) && $v !== '');
            return route('po.report', ['q' => \Crypt::encrypt($payload)]);
        };

        // ====== Helper total untuk FOOTER Tabel-1 ======
        // Ambil koleksi item dari paginator kalau $rows adalah paginator
        $rowsCol = method_exists($rows ?? null, 'getCollection') ? $rows->getCollection() : collect($rows ?? []);

        // Total SO & Overdue SO Halaman
        $totalSO = (int) $rowsCol->sum(fn($r) => (int) ($r->SO_TOTAL_COUNT ?? 0));
        $totalOverdueSO = (int) $rowsCol->sum(fn($r) => (int) ($r->SO_LATE_COUNT ?? 0));

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

        $formatTotals = function (array $totals) {
            if (empty($totals)) {
                return '—';
            }
            $parts = [];
            $sumUsd = $totals['USD'] ?? 0;
            $sumIdr = $totals['IDR'] ?? 0;
            if ($sumUsd > 0) {
                $parts[] = '$' . number_format($sumUsd, 2, '.', ',');
            }
            if ($sumIdr > 0) {
                $parts[] = 'Rp ' . number_format($sumIdr, 2, ',', '.');
            }
            return implode(' | ', $parts) ?: '—';
        };
    @endphp

    {{-- Root state untuk JS --}}
    <div id="yz-root" data-show="{{ $show ? 1 : 0 }}" data-werks="{{ $werks ?? '' }}" data-auart="{{ $auart ?? '' }}"
        data-is-export="{{ $isExportContext ? 1 : 0 }}"
        data-auto-expand="{{ (int) request()->boolean('auto_expand', $selected['auto_expand'] ?? false) }}"
        data-h-kunnr="{{ request('highlight_kunnr', '') }}" data-h-vbeln="{{ request('highlight_vbeln', '') }}"
        data-h-bstnk="{{ request('highlight_bstnk', '') }}" style="display:none"></div>

    {{-- =========================================================
HEADER: PILIH TYPE + EXPORT
========================================================= --}}
    @if (filled($werks))
        @php
            $typesForPlant = collect($mapping[$werks] ?? []);
            $selectedAuart = trim((string) ($auart ?? ''));

            // Gabungkan 'Replace' ke 'Export' secara visual
            $pillsToShow = [];
            $exportAuart = null;
            $replaceAuart = null;

            foreach ($typesForPlant as $t) {
                $desc = strtolower((string) $t->Deskription);
                if (str_contains($desc, 'export') && !str_contains($desc, 'local') && !str_contains($desc, 'replace')) {
                    $exportAuart = $t;
                } elseif (str_contains($desc, 'replace')) {
                    $replaceAuart = $t;
                }
            }

            if ($exportAuart) {
                $pillsToShow[trim((string) $exportAuart->IV_AUART)] = $exportAuart;
            }
            foreach ($typesForPlant as $t) {
                $desc = strtolower((string) $t->Deskription);
                if (str_contains($desc, 'local')) {
                    $pillsToShow[trim((string) $t->IV_AUART)] = $t;
                }
            }

            $highlightAuartCode = $selectedAuart;
            if ($replaceAuart && $exportAuart && $selectedAuart === trim((string) $replaceAuart->IV_AUART)) {
                $highlightAuartCode = trim((string) $exportAuart->IV_AUART);
            }
        @endphp

        <div class="card yz-card shadow-sm mb-3 overflow-visible">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                {{-- Kiri: pills PO Type --}}
                <div class="py-1">
                    @if (count($pillsToShow))
                        <ul class="nav nav-pills yz-auart-pills p-1 flex-wrap" style="border-radius:.75rem;">
                            @foreach ($pillsToShow as $auartCode => $t)
                                @php
                                    $isActive = $highlightAuartCode === $auartCode;
                                    $pillUrl = $encReport(['werks' => $werks, 'auart' => $auartCode, 'compact' => 1]);

                                    $description = $t->Deskription;
                                    if (
                                        $exportAuart &&
                                        $auartCode === trim((string) $exportAuart->IV_AUART) &&
                                        $replaceAuart
                                    ) {
                                        $description = 'KMI Export';
                                    }
                                @endphp
                                <li class="nav-item mb-2 me-2">
                                    <a class="nav-link pill-green {{ $isActive ? 'active' : '' }}"
                                        href="{{ $pillUrl }}">
                                        {{ $description }}
                                    </a>
                                </li>
                            @endforeach

                            @if ($replaceAuart && $selectedAuart === trim((string) $replaceAuart->IV_AUART))
                                <li class="nav-item mb-2 me-2" style="display:none;">
                                    <a class="nav-link pill-green active"
                                        href="{{ $encReport(['werks' => $werks, 'auart' => trim((string) $replaceAuart->IV_AUART), 'compact' => 1]) }}">
                                        {{ $replaceAuart->Deskription }}
                                    </a>
                                </li>
                            @endif
                        </ul>
                    @else
                        <i class="fas fa-info-circle me-2"></i> Silakan pilih Plant terlebih dahulu dari sidebar.
                    @endif
                </div>

                {{-- Kanan: Export Items (muncul saat ada item terpilih) --}}
                <div class="py-1 d-flex align-items-center gap-2">
                    <div class="dropdown" id="export-dropdown-container" style="display:none;">
                        <button class="btn btn-primary dropdown-toggle" type="button" id="export-btn"
                            data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-file-export me-2"></i>
                            Export Items (<span id="selected-count">0</span>)
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="export-btn">
                            <li>
                                <a class="dropdown-item export-option" href="#" data-type="pdf">
                                    <i class="fas fa-file-pdf text-danger me-2"></i>Export to PDF
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item export-option" href="#" data-type="excel">
                                    <i class="fas fa-file-excel text-success me-2"></i>Export to Excel
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>

            </div>
        </div>
    @endif

    {{-- =========================================================
A. MODE TABEL (LAPORAN PO)
========================================================= --}}
    @if ($show && $compact)
        <div class="card yz-card shadow-sm mb-3">
            <div class="card-body p-0 p-md-2">
                <div class="p-3 mx-md-3 mt-md-3 yz-main-title-wrapper">
                    <h5 class="yz-table-title mb-0">
                        <i class="fas fa-users me-2"></i>Overview Customer
                    </h5>
                </div>

                <div class="table-responsive yz-table px-md-3">
                    <table class="table table-hover mb-0 align-middle yz-grid">
                        <thead class="yz-header-customer">
                            <tr>
                                <th style="width:50px;"></th>
                                <th class="text-start" style="min-width:250px;">Customer</th>
                                <th class="text-center" style="min-width:100px;">Total PO</th>
                                <th class="text-center" style="min-width:120px;">Overdue PO</th>
                                {{-- <th class="text-center" style="min-width:120px;">Outs. Qty</th> --}}
                                <th class="text-center" style="min-width:150px;">Outs. Value</th>
                                <th class="text-center" style="min-width:160px;">Overdue Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $r)
                                @php
                                    $kid = 'krow_' . $r->KUNNR . '_' . $loop->index;

                                    $outsValueUSD = (float) ($r->TOTAL_ALL_VALUE_USD ?? 0);
                                    $outsValueIDR = (float) ($r->TOTAL_ALL_VALUE_IDR ?? 0);
                                    $overdueValueUSD = (float) ($r->TOTAL_OVERDUE_VALUE_USD ?? 0);
                                    $overdueValueIDR = (float) ($r->TOTAL_OVERDUE_VALUE_IDR ?? 0);

                                    $displayOutsValue =
                                        ($outsValueUSD > 0 ? '$' . number_format($outsValueUSD, 2, '.', ',') : '') .
                                        ($outsValueUSD > 0 && $outsValueIDR > 0 ? ' | ' : '') .
                                        ($outsValueIDR > 0 ? 'Rp ' . number_format($outsValueIDR, 2, ',', '.') : '');

                                    $displayOverdueValue =
                                        ($overdueValueUSD > 0
                                            ? '$' . number_format($overdueValueUSD, 2, '.', ',')
                                            : '') .
                                        ($overdueValueUSD > 0 && $overdueValueIDR > 0 ? ' | ' : '') .
                                        ($overdueValueIDR > 0
                                            ? 'Rp ' . number_format($overdueValueIDR, 2, ',', '.')
                                            : '');

                                    if (empty($displayOutsValue)) {
                                        $displayOutsValue = 'Rp 0,00';
                                    }
                                    if (empty($displayOverdueValue)) {
                                        $displayOverdueValue = 'Rp 0,00';
                                    }
                                @endphp

                                <tr class="yz-kunnr-row" data-kunnr="{{ $r->KUNNR }}" data-kid="{{ $kid }}"
                                    data-cname="{{ $r->NAME1 }}" title="Klik untuk melihat detail pesanan">
                                    <td class="sticky-col-mobile-disabled">
                                        <span class="kunnr-caret"><i class="fas fa-chevron-right"></i></span>
                                    </td>
                                    <td class="sticky-col-mobile-disabled text-start">
                                        <span class="fw-bold">{{ $r->NAME1 }}</span>
                                    </td>
                                    <td class="text-center">{{ $r->SO_TOTAL_COUNT }}</td>
                                    <td class="text-center">{{ $r->SO_LATE_COUNT }}</td>
                                    <td class="text-center">{{ $displayOutsValue }}</td>
                                    <td class="text-center">{{ $displayOverdueValue }}</td>
                                </tr>
                                <tr id="{{ $kid }}" class="yz-nest" style="display:none;">
                                    <td colspan="6" class="p-0">
                                        <div class="yz-nest-wrap">
                                            <div
                                                class="p-3 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
                                                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                                                Memuat data…
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center p-5">
                                        <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">Data tidak ditemukan</h5>
                                        <p>Tidak ada data yang cocok untuk filter yang Anda pilih.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>

                        <tfoot class="yz-footer-customer">
                            <tr>
                                <th colspan="2" class="text-center">Total</th>
                                <th class="text-center">{{ number_format($totalSO, 0, ',', '.') }}</th>
                                <th class="text-center">{{ number_format($totalOverdueSO, 0, ',', '.') }}</th>
                                {{-- Kolom Outs. Qty dihapus dari sini --}}
                                <th class="text-center">{{ $formatTotals($pageTotalsAll ?? []) }}</th>
                                <th class="text-center">{{ $formatTotals($pageTotalsOverdue ?? []) }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @if (method_exists($rows, 'hasPages') && $rows->hasPages())
                    <div class="px-3 pt-3">
                        {{ $rows->onEachSide(1)->links('pagination::bootstrap-5') }}
                    </div>
                @endif
            </div>
        </div>

        {{-- =========================================================
    B. KPI: Outstanding PO Distribution
    ========================================================= --}}
        {{-- Section ini harus tetap visible agar JS bisa mengambil elemen-elemen di dalamnya --}}
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card shadow-sm yz-chart-card position-relative">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h5 class="card-title mb-0" data-help-key="po.performance_details">
                                    <i class="fas fa-tasks me-1"></i>Outstanding PO Distribution
                                </h5>
                            </div>
                            <div class="d-flex flex-wrap justify-content-end align-items-center"
                                style="gap: 8px; flex-shrink: 0; margin-left: 1rem;">
                                <span class="legend-badge" style="background-color: #198754;">On-Track</span>
                                <span class="legend-badge" style="background-color: #ffc107;">1-30</span>
                                <span class="legend-badge" style="background-color: #fd7e14;">31-60</span>
                                <span class="legend-badge" style="background-color: #dc3545;">61-90</span>
                                <span class="legend-badge" style="background-color: #8b0000;">&gt;90</span>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light" id="performance-table-header">
                                    {{-- HANYA 1 KOLOM HEADER --}}
                                    <tr>
                                        <th scope="col" class="text-center">Distribution
                                            (Days)
                                            <small class="text-muted d-block">(On-Track & Overdue)</small>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody id="performance-table-body">
                                    @forelse ($performanceData as $item)
                                        @php
                                            $totalSo = (int) $item->total_so;
                                            $overdueSo = (int) $item->overdue_so_count;
                                            $onTrackSo = $totalSo - $overdueSo;
                                            $totalSoForBar = $totalSo;

                                            $werks_code = $item->IV_WERKS;
                                            $auart_code = $item->IV_AUART;
                                            $pct = fn($n) => $totalSoForBar > 0 ? ($n / $totalSoForBar) * 100 : 0;

                                            $seg = function ($count, $percent, $color, $bucket, $textTitle) use (
                                                $werks_code,
                                                $auart_code,
                                            ) {
                                                if (!$count) {
                                                    return '';
                                                }
                                                return '<div class="bar-segment js-distribution-seg"
                                                    data-werks="' .
                                                    $werks_code .
                                                    '"
                                                    data-auart="' .
                                                    $auart_code .
                                                    '"
                                                    data-bucket="' .
                                                    $bucket .
                                                    '"
                                                    style="width:' .
                                                    $percent .
                                                    '%;background-color:' .
                                                    $color .
                                                    ';' .
                                                    ($bucket !== 'on_track' ? 'cursor:pointer' : '') .
                                                    '"
                                                    data-bs-toggle="tooltip"
                                                    title="' .
                                                    $textTitle .
                                                    ': ' .
                                                    $count .
                                                    ' PO">' .
                                                    $count .
                                                    '</div>';
                                            };

                                            $barChartHtml = '<div class="bar-chart-container">';
                                            $barChartHtml .= $seg(
                                                $onTrackSo,
                                                $pct($onTrackSo),
                                                '#198754',
                                                'on_track',
                                                'On-Track (Tidak Overdue)',
                                            );
                                            $barChartHtml .= $seg(
                                                $item->overdue_1_30,
                                                $pct($item->overdue_1_30),
                                                '#ffc107',
                                                '1_30',
                                                '1–30 Days',
                                            );
                                            $barChartHtml .= $seg(
                                                $item->overdue_31_60,
                                                $pct($item->overdue_31_60),
                                                '#fd7e14',
                                                '31_60',
                                                '31–60 Days',
                                            );
                                            $barChartHtml .= $seg(
                                                $item->overdue_61_90,
                                                $pct($item->overdue_61_90),
                                                '#dc3545',
                                                '61_90',
                                                '61–90 Days',
                                            );
                                            $barChartHtml .= $seg(
                                                $item->overdue_over_90,
                                                $pct($item->overdue_over_90),
                                                '#8b0000',
                                                'gt_90',
                                                '>90 Days',
                                            );
                                            $barChartHtml .= '</div>';
                                        @endphp
                                        <tr>
                                            {{-- HANYA SATU KOLOM DATA (Distribution) --}}
                                            <td>
                                                {!! $totalSoForBar > 0 ? $barChartHtml : '<span class="text-muted small">Tidak ada PO Outstanding</span>' !!}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            {{-- Colspan DIUBAH MENJADI 1 --}}
                                            <td colspan="1" class="text-center p-3 text-muted">
                                                Tidak ada data performa untuk filter ini.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        {{-- =========================================================
    C. KPI: Small Quantity (≤5) Outstanding Items by Customer
    ========================================================= --}}
        {{-- Memberi ID pada row luar agar mudah disembunyikan/ditampilkan --}}
        <div class="row g-4" id="small-qty-section">
            <div class="col-12">
                <div class="card shadow-sm yz-chart-card">
                    <div class="card-body">
                        <h5 class="card-title text-info-emphasis" id="small-qty-chart-title"
                            data-help-key="po.small_qty_by_customer">
                            <i class="fas fa-chart-line me-2"></i>Small Quantity (≤5)
                            Outstanding Items by Customer
                            @if (($totalSmallQtyOutstanding ?? 0) > 0)
                                <small class="text-muted ms-2" id="small-qty-total-item">(Total Item:
                                    {{ number_format($totalSmallQtyOutstanding, 0, ',', '.') }})</small>
                            @endif

                        </h5>
                        <hr class="mt-2">
                        <div class="chart-container" style="height: 600px;">
                            <canvas id="chartSmallQtyByCustomer"></canvas>
                        </div>
                    </div>
                </div>

                {{-- Overlay detail + Export PDF --}}
                <div id="smallQtyDetailsContainer" class="card shadow-sm yz-chart-card mt-4" style="display: none;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 text-primary-emphasis">
                                <i class="fas fa-list-ol me-2"></i>
                                <span id="smallQtyDetailsTitle">Detail Item Outstanding</span>
                                <small id="smallQtyMeta" class="text-muted ms-2"></small>
                            </h5>

                            <div class="d-flex align-items-center gap-2">
                                {{-- tombol export PDF --}}
                                <button type="button" class="btn btn-sm btn-outline-danger" id="exportSmallQtyPdf"
                                    disabled>
                                    <i class="fas fa-file-pdf me-1"></i> Export PDF
                                </button>
                                {{-- tombol close --}}
                                <button type="button" class="btn-close" id="closeDetailsTable"
                                    aria-label="Close"></button>
                            </div>
                        </div>
                        <hr class="mt-2">
                        <div id="smallQtyDetailsTable" class="mt-3"></div>
                    </div>
                    <form id="smallQtyExportForm" action="{{ route('dashboard.export.smallQtyPdf') }}" method="POST"
                        target="_blank" class="d-none">
                        @csrf
                        <input type="hidden" name="customerName" id="exp_customerName">
                        <input type="hidden" name="locationName" id="exp_locationName">
                        <input type="hidden" name="type" id="exp_type">
                        <input type="hidden" name="auart" id="exp_auart"> {{-- Tambahkan field AUART --}}
                    </form>
                </div>
            </div>
        </div>
    @elseif ($onlyWerksSelected)
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            Silakan pilih <strong>Type</strong> pada tombol hijau di atas.
        </div>
    @else
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Silakan pilih <strong>Plant</strong> dari sidebar untuk menampilkan Laporan PO.
        </div>
    @endif


    {{-- =========================================================
MODAL POP-UP UNTUK DETAIL OVERDUE
========================================================= --}}
    <div class="modal fade" id="overdueDetailsModal" tabindex="-1" aria-labelledby="overdueDetailsModalLabel"
        aria-hidden="true" data-bs-backdrop="false">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content shadow-lg" style="border-radius: 1rem;">
                {{-- PERBAIKAN: Tambahkan bg-success untuk on-track --}}
                <div class="modal-header bg-danger text-white p-3"
                    style="border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                    <h5 class="modal-title fw-bold" id="overdueDetailsModalLabel">
                        <i class="fas fa-triangle-exclamation me-2"></i>Detail PO Overdue
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <h6 class="text-muted mb-3" id="modal-sub-title">Memuat data...</h6>
                    <div id="modal-content-area">
                        <div class="text-center p-5">
                            <div class="spinner-border text-danger" role="status"></div>
                            <p class="mt-3 text-muted">Memuat detail PO...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
    {{-- =========================================================
END MODAL
========================================================= --}}

@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard-style.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
    <script src="{{ asset('vendor/chartjs/chartjs-adapter-date-fns.bundle.min.js') }}"></script>
    <script>
        /* ====================== UTIL ====================== */
        const fmtMoney = (v, c) => {
            const n = parseFloat(v);
            if (!Number.isFinite(n)) return '';
            const o = {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            };
            if (c === 'IDR') return `Rp ${n.toLocaleString('id-ID', o)}`;
            if (c === 'USD') return `$${n.toLocaleString('en-US', o)}`;
            return `${(c||'')} ${n.toLocaleString('id-ID', o)}`;
        };
        const fmtNum = (v, d = 0) => {
            const n = parseFloat(v);
            if (!Number.isFinite(n)) return '';
            return n.toLocaleString('id-ID', {
                minimumFractionDigits: d,
                maximumFractionDigits: d
            });
        };
        const sanitizeId = (v) => {
            const s = String(v ?? '').replace(/\D+/g, '');
            return s.length ? s : null;
        };
        if (!window.CSS) window.CSS = {};
        if (typeof window.CSS.escape !== 'function') window.CSS.escape = s => String(s).replace(/([^\w-])/g, '\\$1');

        // Helper kecil
        const wait = (ms) => new Promise(res => setTimeout(res, ms));
        const softHighlightRow = (row) => {
            if (!row) return;
            const old = row.style.boxShadow;
            const oldBg = row.style.backgroundColor;
            row.style.transition = 'box-shadow .6s ease, background-color .6s ease';
            row.style.boxShadow = 'inset 0 0 0 9999px rgba(255,235,59,.35)';
            row.style.backgroundColor = 'rgba(255,235,59,.15)';
            setTimeout(() => {
                row.style.boxShadow = old ?? '';
                row.style.backgroundColor = oldBg ?? '';
            }, 1600);
        };

        /* ====================== JS STATE GLOBAL ====================== */
        const selectedItems = new Set(); // item ids (from T3)
        const itemIdToSO = new Map(); // item id -> VBELN
        let activeCustomerKunnr = null; // KUNNR customer yang sedang dibuka
        let activeCustomerName = null; // Nama customer yang sedang dibuka

        const soHasSelectionDot = (vbeln) => {
            const anySel = Array.from(selectedItems).some(id => itemIdToSO.get(String(id)) === vbeln);
            document.querySelectorAll(`.js-t2row[data-vbeln='${CSS.escape(vbeln)}'] .so-selected-dot`)
                .forEach(dot => dot.style.display = anySel ? 'inline-block' : 'none');
        };
        const exportDropdownContainer = document.getElementById('export-dropdown-container');
        const selectedCountSpan = document.getElementById('selected-count');
        const updateExportButton = () => {
            if (selectedCountSpan) selectedCountSpan.textContent = selectedItems.size;
            if (exportDropdownContainer) exportDropdownContainer.style.display = selectedItems.size > 0 ? 'block' :
                'none';
        };

        /* Cegah checkbox memicu click baris */
        document.addEventListener('click', (e) => {
            if (e.target.closest('.form-check-input')) e.stopPropagation();
        }, true);

        /* ====================== RENDER T2/T3/ETC ====================== */

        function updateT2FooterVisibility(t2Table) {
            if (!t2Table) return;
            const anyOpen = [...t2Table.querySelectorAll('tr.yz-nest')]
                .some(tr => tr.style.display !== 'none' && tr.offsetParent !== null);
            const tfoot = t2Table.querySelector('tfoot.t2-footer') || t2Table.querySelector('.yz-t2-total-outs')?.closest(
                'tfoot');
            if (tfoot) tfoot.style.display = anyOpen ? 'none' : '';
        }

        function renderT2(rows, kunnr) {
            if (!rows?.length) return `<div class="p-3 text-muted">Tidak ada data PO untuk KUNNR <b>${kunnr}</b>.</div>`;
            const totalOutsQtyT2 = rows.reduce((sum, r) => sum + parseFloat(r.outs_qty ?? r.OUTS_QTY ?? 0), 0);
            const sortedRows = [...rows].sort((a, b) => {
                const oa = Number(a.Overdue ?? 0),
                    ob = Number(b.Overdue ?? 0);
                if ((oa > 0) !== (ob > 0)) return ob > 0 ? 1 : -1;
                return ob - oa;
            });
            let html = `
<div class="table-responsive" style="width:100%">
  <h5 class="yz-table-title-nested yz-title-so"><i class="fas fa-file-invoice me-2"></i>Outstanding PO</h5>
  <table class="table table-sm mb-0 yz-mini" style="width:100%">
    <thead class="yz-header-so">
      <tr>
        <th style="width:40px" class="text-center">
          <input type="checkbox" class="form-check-input check-all-sos" title="Pilih semua SO">
        </th>
        <th style="width:40px;text-align:center;"></th>
        <th class="text-start">PO</th>
        <th class="text-start">SO</th>
        <th class="text-center">Outs. Qty</th>
        <th class="text-center">Outs. Value</th>
        <th class="text-center">Req. Deliv. Date</th>
        <th class="text-center">Overdue (Days)</th>
        <th style="width:28px;"></th>
      </tr>
    </thead>
    <tbody>`;
            sortedRows.forEach((r, i) => {
                const rid = `t3_${kunnr}_${r.VBELN}_${i}`;
                const over = r.Overdue ?? 0;
                const rowCls = over > 0 ? 'yz-row-highlight-negative' : '';
                const edatu = r.FormattedEdatu || '';
                const outsQty = r.outs_qty ?? r.OUTS_QTY ?? 0;
                const totalVal = r.total_value ?? r.TOTPR ?? 0;
                html += `
      <tr class="yz-row js-t2row ${rowCls}" data-vbeln="${r.VBELN}" data-tgt="${rid}">
        <td class="text-center"><input type="checkbox" class="form-check-input check-so" data-vbeln="${r.VBELN}"></td>
        <td class="text-center"><span class="yz-caret">▸</span></td>
        <td class="text-start">${r.BSTNK ?? ''}</td>
        <td class="yz-t2-vbeln text-start">${r.VBELN}</td>
        <td class="text-center">${fmtNum(outsQty)}</td>
        <td class="text-center">${fmtMoney(totalVal, r.WAERK)}</td>
        <td class="text-center">${edatu}</td>
        <td class="text-center">${over}</td>
        <td class="text-center"><span class="so-selected-dot"></span></td>
      </tr>
      <tr id="${rid}" class="yz-nest" style="display:none;">
        <td colspan="9" class="p-0">
          <div class="yz-nest-wrap level-2" style="margin-left:0;padding:.5rem;">
            <div class="yz-slot-t3 p-2"></div>
          </div>
        </td>
      </tr>`;
            });

            html += `
    </tbody>
    <tfoot>
        <tr class="table-light yz-t2-total-outs" style="background-color: #e9ecef;">
            <th colspan="4" class="text-end">Total Outstanding Qty</th>
            <th class="text-center fw-bold">${fmtNum(totalOutsQtyT2)}</th>
            <th colspan="4"></th>
        </tr>
    </tfoot>
  </table>
</div>`;
            return html;
        }

        function renderT3(rows) {
            if (!rows?.length) return `<div class="p-2 text-muted">Tidak ada item detail.</div>`;
            let out = `
<div class="table-responsive">
  <table class="table table-sm table-hover mb-0 yz-mini">
    <thead class="yz-header-item">
      <tr>
        <th style="width:40px;"><input class="form-check-input check-all-items" type="checkbox" title="Pilih Semua Item"></th>
        <th>Item</th>
        <th>Material FG</th>
        <th>Desc FG</th>
        <th>Qty PO</th>
        <th>Shipped</th>
        <th>Outs. Ship</th>
        <th>WHFG</th>
        <th>FG</th>
        <th>Net Price</th>
      </tr>
    </thead>
    <tbody>`;
            rows.forEach(r => {
                const sid = sanitizeId(r.id);
                const checked = sid && selectedItems.has(sid) ? 'checked' : '';
                out += `
      <tr data-item-id="${sid ?? ''}" data-vbeln="${r.VBELN}">
        <td><input class="form-check-input check-item" type="checkbox" data-id="${sid ?? ''}" ${checked}></td>
        <td>${r.POSNR ?? ''}</td>
        <td>${r.MATNR ?? ''}</td>
        <td>${r.MAKTX ?? ''}</td>
        <td>${fmtNum(r.KWMENG)}</td>
        <td>${fmtNum(r.QTY_GI)}</td>
        <td>${fmtNum(r.QTY_BALANCE2)}</td>
        <td>${fmtNum(r.KALAB)}</td>
        <td>${fmtNum(r.KALAB2)}</td>
        <td>${fmtMoney(r.NETPR, r.WAERK)}</td>
      </tr>`;
                if (sid) itemIdToSO.set(sid, String(r.VBELN));
            });
            out += `</tbody></table></div>`;
            return out;
        }

        const renderModalTable = (rows, labelText) => {
            if (!rows || rows.length === 0) {
                return `<div class="text-muted p-4 text-center"><i class="fas fa-info-circle me-2"></i>Data PO tidak ditemukan untuk kategori ini.</div>`;
            }
            const showCustomerColumn = !activeCustomerName;
            const customerHeader = showCustomerColumn ?
                '<th class="text-start" style="min-width:200px;">Customer</th>' : '';
            const customerCell = (r) => showCustomerColumn ?
                `<td class="text-start">${r.CUSTOMER_NAME_MODAL ?? '—'}</td>` : '';

            const body = rows.map((r, i) => `
    <tr>
      <td class="text-center">${i+1}</td>
      ${customerCell(r)} 
      <td class="text-center">${r.PO ?? '-'}</td>
      <td class="text-center">${r.SO ?? '-'}</td>
      <td class="text-center">${r.EDATU ?? '-'}</td>
      <td class="text-center fw-bold ${(r.OVERDUE_DAYS||0) > 0 ? 'text-danger' : 'text-success'}">${r.OVERDUE_DAYS ?? 0}</td>
    </tr>`).join('');

            return `
    <div class="table-responsive" style="max-height: 60vh;">
      <table class="table table-striped table-hover table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="text-center" style="width:60px;">NO.</th>
            ${customerHeader}
            <th class="text-center" style="min-width:120px;">PO</th>
            <th class="text-center" style="min-width:120px;">SO</th>
            <th class="text-center" style="min-width:120px;">Req. Deliv Date</th>
            <th class="text-center" style="min-width:140px;">OVERDUE (DAYS)</th>
          </tr>
        </thead>
        <tbody>${body}</tbody>
      </table>
    </div>`;
        };

        const renderBarSegments = (item, isExport) => {
            const totalSo = parseInt(item.total_so);
            const overdueSo = parseInt(item.overdue_so_count);
            const onTrackSo = totalSo - overdueSo;
            const totalSoForBar = totalSo;
            const pct = (n) => totalSoForBar > 0 ? (n / totalSoForBar) * 100 : 0;

            const seg = (count, percent, color, bucket, textTitle) => {
                if (!count) return '';
                return `<div class="bar-segment js-distribution-seg"
                    data-werks="${item.IV_WERKS}"
                    data-auart="${item.IV_AUART}"
                    data-bucket="${bucket}"
                    style="width:${percent}%;background-color:${color};${bucket !== 'on_track' ? 'cursor:pointer' : ''}"
                    data-bs-toggle="tooltip"
                    title="${textTitle}: ${count} PO">
                    ${count}
                </div>`;
            };

            let barChartHtml = '<div class="bar-chart-container">';
            barChartHtml += seg(onTrackSo, pct(onTrackSo), '#198754', 'on_track', 'On-Track (Tidak Overdue)');
            barChartHtml += seg(parseInt(item.overdue_1_30), pct(parseInt(item.overdue_1_30)), '#ffc107', '1_30',
                '1–30 Days');
            barChartHtml += seg(parseInt(item.overdue_31_60), pct(parseInt(item.overdue_31_60)), '#fd7e14', '31_60',
                '31–60 Days');
            barChartHtml += seg(parseInt(item.overdue_61_90), pct(parseInt(item.overdue_61_90)), '#dc3545', '61_90',
                '61–90 Days');
            barChartHtml += seg(parseInt(item.overdue_over_90), pct(parseInt(item.overdue_over_90)), '#8b0000', 'gt_90',
                '>90 Days');
            barChartHtml += '</div>';

            return totalSoForBar > 0 ? barChartHtml : '<span class="text-muted small">Tidak ada PO Outstanding</span>';
        };

        function renderPerformanceTable(performanceData, customerName, isExportContext) {
            const tableBody = document.getElementById('performance-table-body');
            const tableHeader = document.getElementById('performance-table-header');

            const newTitleText = 'Outstanding PO Distribution';
            const titleContainer = document.querySelector('.yz-chart-card .card-title');
            if (titleContainer) {
                let innerHtml = `<i class="fas fa-tasks me-2"></i>${newTitleText}`;
                if (customerName) innerHtml += ` <small class="text-primary fw-bold">(${customerName})</small>`;
                titleContainer.innerHTML = innerHtml;
            }

            if (!performanceData || performanceData.length === 0) {
                tableBody.innerHTML = `
            <tr>
                <td colspan="1" class="text-center p-3 text-muted">
                    Tidak ada data performa untuk customer ini.
                </td>
            </tr>`;
                return;
            }

            const item = performanceData[0];
            const barChartHtml = renderBarSegments(item, isExportContext);

            tableBody.innerHTML = `
        <tr>
            <td class="distribution-cell">
                ${barChartHtml}
            </td>
        </tr>`;

            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                bootstrap.Tooltip.getInstance(el)?.dispose();
                new bootstrap.Tooltip(el);
            });
        }

        const defaultPerformanceData = @json($performanceData);
        const defaultIsExportContext = @json($isExportContext);
        const defaultSmallQtyDataRaw = @json($smallQtyByCustomer);

        let smallQtyChartInstance = null;
        const smallQtyChartTitle = document.getElementById('small-qty-chart-title');
        const smallQtyTotalItem = document.getElementById('small-qty-total-item');
        const smallQtyChartContainer = document.getElementById('chartSmallQtyByCustomer')?.closest('.card-body')
            ?.querySelector('.chart-container');
        const smallQtyCard = document.getElementById('smallQtyDetailsContainer')?.closest('.yz-chart-card.shadow-sm');

        const smallQtyDetailsContainer = document.getElementById('smallQtyDetailsContainer');
        const smallQtyDetailsTable = document.getElementById('smallQtyDetailsTable');
        const smallQtyDetailsTitle = document.getElementById('smallQtyDetailsTitle');
        const smallQtyMeta = document.getElementById('smallQtyMeta');
        const exportSmallQtyPdfBtn = document.getElementById('exportSmallQtyPdf');

        async function showSmallQtyDetails(customerName, locationName) {
            const root = document.getElementById('yz-root');
            const currentAuart = (root.dataset.auart || '').trim();
            const exportType = currentAuart.toLowerCase().includes('export') ? 'export' : 'lokal';

            if (smallQtyChartContainer) smallQtyChartContainer.style.display = 'none';
            if (smallQtyChartTitle) {
                smallQtyChartTitle.style.display = 'none';
                if (smallQtyChartTitle.nextElementSibling.tagName === 'HR') smallQtyChartTitle.nextElementSibling.style
                    .display = 'none';
            }

            smallQtyDetailsTitle.textContent = `Detail Item Outstanding untuk ${customerName} - (${locationName})`;
            smallQtyMeta.textContent = '';
            exportSmallQtyPdfBtn.disabled = true;
            smallQtyDetailsTable.innerHTML = `<div class="d-flex justify-content-center align-items-center p-5">
                <div class="spinner-border text-primary" role="status"></div>
                <span class="ms-3 text-muted">Memuat data...</span></div>`;
            smallQtyDetailsContainer.style.display = 'block';
            smallQtyDetailsContainer.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });

            const apiUrl = new URL("{{ route('dashboard.api.smallQtyDetails') }}", window.location.origin);
            apiUrl.searchParams.append('customerName', customerName);
            apiUrl.searchParams.append('locationName', locationName);
            apiUrl.searchParams.append('auart', currentAuart);

            try {
                const response = await fetch(apiUrl);
                const result = await response.json();

                if (result.ok && result.data.length > 0) {
                    const uniqPO = new Set(result.data.map(r => (r.BSTNK || r.PO || '').toString().trim()).filter(
                        Boolean));
                    const totalPO = uniqPO.size;
                    const totalItem = result.data.length;

                    smallQtyMeta.textContent = `• ${totalPO} PO • ${totalItem} Item`;
                    exportSmallQtyPdfBtn.disabled = false;

                    document.getElementById('exp_customerName').value = customerName;
                    document.getElementById('exp_locationName').value = locationName;
                    document.getElementById('exp_type').value = exportType;
                    document.getElementById('exp_auart').value = currentAuart;

                    result.data.sort((a, b) => parseFloat(a.QTY_BALANCE2) - parseFloat(b.QTY_BALANCE2));

                    const tableHeaders = `<tr>
                        <th style="width:5%;" class="text-center">No.</th>
                        <th class="text-center">PO</th>
                        <th class="text-center">SO</th>
                        <th class="text-center">Item</th>
                        <th>Desc FG</th>
                        <th class="text-center">Qty PO</th>
                        <th class="text-center">Shipped</th>
                        <th class="text-center">Outstanding</th>
                        </tr>`;

                    let tableBodyHtml = result.data.map((item, idx) => {
                        const po = item.BSTNK || item.PO || '-';
                        const qtyPo = fmtNum(item.KWMENG, 0);
                        const qtyShp = fmtNum(item.QTY_GI, 0);
                        const qtyOuts = fmtNum(item.QTY_BALANCE2, 0);
                        return `<tr>
                        <td class="text-center">${idx+1}</td>
                        <td class="text-center">${po}</td>
                        <td class="text-center">${item.VBELN}</td>
                        <td class="text-center">${parseInt(item.POSNR,10)}</td>
                        <td>${item.MAKTX}</td>
                        <td class="text-center">${qtyPo}</td>
                        <td class="text-center">${qtyShp}</td>
                        <td class="text-center fw-bold text-danger">${qtyOuts}</td>
                        </tr>`;
                    }).join('');

                    const tableHtml = `
                        <div class="table-responsive yz-scrollable-table-container" style="max-height: 400px;">
                            <table class="table table-striped table-hover table-sm align-middle">
                                <thead class="table-light">${tableHeaders}</thead>
                                <tbody>${tableBodyHtml}</tbody>
                            </table>
                        </div>`;
                    smallQtyDetailsTable.innerHTML = tableHtml;
                } else {
                    smallQtyMeta.textContent = '';
                    exportSmallQtyPdfBtn.disabled = true;
                    smallQtyDetailsTable.innerHTML =
                        `<div class="text-center p-5 text-muted">Data item Small Quantity (<=5) tidak ditemukan untuk customer ini.</div>`;
                }
            } catch (error) {
                console.error('Gagal mengambil data detail Small Qty:', error);
                smallQtyMeta.textContent = '';
                exportSmallQtyPdfBtn.disabled = true;
                smallQtyDetailsTable.innerHTML =
                    `<div class="text-center p-5 text-danger">Terjadi kesalahan saat memuat data.</div>`;
            }
        }

        function renderSmallQtyChart(dataToRender, customerNameFilter = null) {
            const ctxSmallQty = document.getElementById('chartSmallQtyByCustomer');
            const root = document.getElementById('yz-root');
            const currentWerks = (root.dataset.werks || '').trim();
            const plantCode = (currentWerks === '3000') ? 'Semarang' : 'Surabaya';
            const barColor = (currentWerks === '3000') ? 'rgba(25, 135, 84, 0.8)' : 'rgba(255, 193, 7, 0.8)';

            let filteredData = dataToRender;
            if (customerNameFilter) filteredData = dataToRender.filter(item => item.NAME1 === customerNameFilter);

            const customerMap = new Map();
            filteredData.forEach(item => {
                const name = (item.NAME1 || '').trim();
                if (!name) return;
                const currentCount = customerMap.get(name) || 0;
                customerMap.set(name, currentCount + parseInt(item.item_count, 10));
            });

            const sortedCustomers = [...customerMap.entries()].sort((a, b) => b[1] - a[1]);
            const labels = sortedCustomers.map(item => item[0]);
            const itemCounts = sortedCustomers.map(item => item[1]);
            const totalItemCount = itemCounts.reduce((sum, count) => sum + count, 0);

            if (smallQtyChartTitle) {
                let baseTitle = 'Small Quantity (≤5) Outstanding Items by Customer';
                if (customerNameFilter) {
                    smallQtyChartTitle.innerHTML =
                        `<i class="fas fa-chart-line me-2"></i>${baseTitle} <small class="text-primary fw-bold">(${customerNameFilter})</small>`;
                    if (smallQtyChartContainer) smallQtyChartContainer.style.display = 'none';
                } else {
                    smallQtyChartTitle.innerHTML = `<i class="fas fa-chart-line me-2"></i>${baseTitle}`;
                    if (smallQtyChartContainer) smallQtyChartContainer.style.display = 'block';
                }
                smallQtyChartTitle.style.display = 'block';
                if (smallQtyChartTitle.nextElementSibling.tagName === 'HR') {
                    smallQtyChartTitle.nextElementSibling.style.display = 'block';
                }
                if (smallQtyTotalItem) smallQtyTotalItem.textContent =
                    `(Total Item: ${fmtNum(customerNameFilter ? totalItemCount : @json($totalSmallQtyOutstanding))})`;
            }

            if (!customerNameFilter && smallQtyDetailsContainer) smallQtyDetailsContainer.style.display = 'none';

            if (smallQtyChartInstance) smallQtyChartInstance.destroy();

            if (!ctxSmallQty || filteredData.length === 0) {
                const cardBody = ctxSmallQty?.closest('.card-body');
                let noDataEl = cardBody?.querySelector('.yz-nodata');
                if (!noDataEl) {
                    noDataEl = document.createElement('div');
                    noDataEl.className = 'yz-nodata text-center p-5 text-muted';
                    cardBody?.appendChild(noDataEl);
                }
                noDataEl.innerHTML =
                    `<i class="fas fa-info-circle fa-2x mb-2"></i><br>Tidak ada item outstanding dengan Qty ≤ 5.`;
                ctxSmallQty.style.display = 'none';
                return;
            } else {
                ctxSmallQty.style.display = 'block';
                ctxSmallQty.closest('.card-body').querySelector('.yz-nodata')?.remove();
            }

            ctxSmallQty.closest('.chart-container').style.height = (labels.length > 1) ? '600px' : '150px';

            if (!customerNameFilter) {
                smallQtyChartInstance = new Chart(ctxSmallQty, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [{
                            label: plantCode,
                            data: itemCounts,
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
                                    text: 'Item (With Qty Outstanding ≤ 5)'
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
                                    label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.x} Item`
                                }
                            }
                        },
                        onClick: async (event, elements) => {
                            if (elements.length === 0) return;
                            const barElement = elements[0];
                            const customerName = labels[barElement.index];
                            const locationName = plantCode;
                            await showSmallQtyDetails(customerName, locationName);
                        }
                    }
                });
            }
        }

        /* ====================== MAIN EVENT LISTENERS ====================== */
        document.addEventListener('DOMContentLoaded', () => {
            // Label responsif Tabel-1
            document.querySelectorAll('.yz-kunnr-row').forEach(row => {
                row.querySelector('td:nth-child(2)')?.setAttribute('data-label', 'Customer');
                row.querySelector('td:nth-child(3)')?.setAttribute('data-label', 'Total PO');
                row.querySelector('td:nth-child(4)')?.setAttribute('data-label', 'Overdue PO');
                row.querySelector('td:nth-child(5)')?.setAttribute('data-label', 'Outs. Qty');
                row.querySelector('td:nth-child(6)')?.setAttribute('data-label', 'Outs. Value');
                row.querySelector('td:nth-child(7)')?.setAttribute('data-label', 'Overdue Value');
            });

            const root = document.getElementById('yz-root');
            const showTable = root ? !!parseInt(root.dataset.show) : false;
            if (!showTable) return;

            const apiT2 = "{{ route('dashboard.api.t2') }}";
            const apiT3 = "{{ route('dashboard.api.t3') }}";
            const apiPoOverdueDetails = "{{ route('dashboard.api.poOverdueDetails') }}";
            const apiPerformanceByCustomer = "{{ route('po.api.performanceByCustomer') }}";

            const WERKS = (root.dataset.werks || '').trim() || null;
            const AUART = (root.dataset.auart || '').trim() || null;
            const isExportContext = !!parseInt(root.dataset.isExport || 0);

            // >>> nilai dari search (auto expand + highlight)
            const needAutoExpand = !!parseInt(root.dataset.autoExpand || 0);
            const HL_KUNNR = (root.dataset.hKunnr || '').trim();
            const HL_VBELN = (root.dataset.hVbeln || '').trim();
            const HL_BSTNK = (root.dataset.hBstnk || '').trim();

            // Lokasi untuk Small Qty
            const locationCode = (root.dataset.werks || '').trim();
            const locationMap = {
                '2000': 'Surabaya',
                '3000': 'Semarang'
            };
            const locationName = locationMap[locationCode] || locationCode;

            // Modal
            const modalElement = document.getElementById('overdueDetailsModal');
            let overdueDetailsModal = bootstrap.Modal.getInstance(modalElement);
            if (!overdueDetailsModal) overdueDetailsModal = new bootstrap.Modal(modalElement);
            const modalSubTitle = document.getElementById('modal-sub-title');
            const modalContentArea = document.getElementById('modal-content-area');

            const scrollToCenter = (el) => {
                if (!el) return Promise.resolve();
                const rect = el.getBoundingClientRect();
                const targetY = rect.top + window.pageYOffset - (window.innerHeight / 2 - rect.height / 2);
                return new Promise((resolve) => {
                    let done = false;
                    const finish = () => {
                        if (!done) {
                            done = true;
                            resolve();
                        }
                    };
                    const to = setTimeout(finish, 350);
                    window.addEventListener('scrollend', () => {
                        clearTimeout(to);
                        finish();
                    }, {
                        once: true
                    });
                    window.scrollTo({
                        top: Math.max(0, targetY),
                        behavior: 'smooth'
                    });
                });
            };

            // Expand Level-1 → load T2
            document.querySelectorAll('.yz-kunnr-row').forEach(custRow => {
                custRow.addEventListener('click', async () => {
                    const kunnr = (custRow.dataset.kunnr || '').trim();
                    const kid = custRow.dataset.kid;
                    const customerName = custRow.dataset.cname || '';
                    const slot = document.getElementById(kid);
                    const wrap = slot?.querySelector('.yz-nest-wrap');

                    const tbody = custRow.closest('tbody');
                    const tableEl = custRow.closest('table');
                    const tfootEl = tableEl?.querySelector('tfoot.yz-footer-customer');

                    const wasOpen = custRow.classList.contains('is-open');

                    // Toggle exclusif
                    document.querySelectorAll('.yz-kunnr-row.is-open').forEach(r => {
                        if (r !== custRow) {
                            r.classList.remove('is-open');
                            document.getElementById(r.dataset.kid)?.style.setProperty(
                                'display', 'none', 'important');
                            r.querySelector('.kunnr-caret')?.classList.remove('rot');
                        }
                    });

                    custRow.classList.toggle('is-open');
                    custRow.querySelector('.kunnr-caret')?.classList.toggle('rot', !wasOpen);
                    slot.style.display = wasOpen ? 'none' : '';

                    if (!wasOpen) {
                        tbody.classList.add('customer-focus-mode');
                        custRow.classList.add('is-focused');
                        activeCustomerKunnr = kunnr;
                        activeCustomerName = customerName;
                    } else {
                        tbody.classList.remove('customer-focus-mode');
                        custRow.classList.remove('is-focused');
                        activeCustomerKunnr = null;
                        activeCustomerName = null;
                    }

                    if (tfootEl) {
                        const anyVisible = [...tableEl.querySelectorAll('tr.yz-nest')]
                            .some(tr => tr.style.display !== 'none' && tr.offsetParent !==
                                null);
                        tfootEl.style.display = anyVisible ? 'none' : '';
                    }
                    wrap?.querySelectorAll('table').forEach(tbl => updateT2FooterVisibility(
                        tbl));

                    // Update KPI & Small Qty saat buka/tutup
                    const performanceTableBody = document.getElementById(
                        'performance-table-body');

                    if (wasOpen) {
                        renderPerformanceTable(defaultPerformanceData, null,
                            defaultIsExportContext);
                        const smallQtySection = document.getElementById('small-qty-section');
                        if (smallQtySection) smallQtySection.style.display = '';
                        renderSmallQtyChart(defaultSmallQtyDataRaw, null);
                        if (smallQtyDetailsContainer) smallQtyDetailsContainer.style.display =
                            'none';
                        if (smallQtyChartTitle) {
                            smallQtyChartTitle.style.display = 'block';
                            if (smallQtyChartTitle.nextElementSibling?.tagName === 'HR') {
                                smallQtyChartTitle.nextElementSibling.style.display = 'block';
                            }
                        }
                    } else {
                        if (performanceTableBody && WERKS && AUART) {
                            performanceTableBody.innerHTML = `
                                <tr>
                                    <td colspan="1" class="text-center p-3 text-muted">
                                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>Memuat data performa untuk ${customerName}...
                                    </td>
                                </tr>`;
                            try {
                                const perfUrl = new URL(apiPerformanceByCustomer, window
                                    .location.origin);
                                perfUrl.searchParams.set('werks', WERKS);
                                perfUrl.searchParams.set('auart', AUART);
                                perfUrl.searchParams.set('kunnr', kunnr);
                                const res = await fetch(perfUrl);
                                const js = await res.json();
                                if (!res.ok || !js.ok) throw new Error(js.error ||
                                    'Gagal memuat data performa customer.');
                                renderPerformanceTable(js.data, js.customer_name, js
                                    .is_export_context);
                            } catch (err) {
                                performanceTableBody.innerHTML = `
                                <tr>
                                    <td colspan="1" class="text-center p-3 text-danger">
                                        <i class="fas fa-exclamation-triangle me-2"></i> ${err.message}
                                    </td>
                                </tr>`;
                            }
                        }

                        const hasSmallQtyData = defaultSmallQtyDataRaw.some(item => item
                            .NAME1 === customerName);
                        const smallQtySection = document.getElementById('small-qty-section');
                        if (hasSmallQtyData) {
                            if (smallQtySection) smallQtySection.style.display = '';
                            showSmallQtyDetails(customerName, locationName);
                        } else {
                            if (smallQtySection) smallQtySection.style.display = 'none';
                        }
                    }

                    // Load T2/T3
                    if (wasOpen) return;
                    if (wrap.dataset.loaded === '1') return;

                    try {
                        wrap.innerHTML = `
            <div class="p-3 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
              <div class="spinner-border spinner-border-sm me-2"></div>Memuat data…
            </div>`;

                        const url = new URL(apiT2, window.location.origin);
                        url.searchParams.set('kunnr', kunnr);
                        if (WERKS) url.searchParams.set('werks', WERKS);
                        if (AUART) url.searchParams.set('auart', AUART);

                        const res = await fetch(url);
                        const js = await res.json();
                        if (!res.ok || !js.ok) throw new Error(js.error ||
                            'Gagal memuat data PO');

                        wrap.innerHTML = renderT2(js.data, kunnr);
                        wrap.dataset.loaded = '1';
                        updateExportButton();
                        updateT2FooterVisibility(wrap.querySelector('table'));

                        // Klik SO → toggle & load T3
                        wrap.querySelectorAll('.js-t2row').forEach(soRow => {
                            soRow.addEventListener('click', async (ev) => {
                                if (ev.target.closest('.form-check-input'))
                                    return;
                                ev.stopPropagation();

                                const vbeln = (soRow.dataset.vbeln || '')
                                    .trim();
                                const tgtId = soRow.dataset.tgt;
                                const caret = soRow.querySelector(
                                    '.yz-caret');
                                const tgt = wrap.querySelector('#' + tgtId);
                                const box = tgt.querySelector(
                                    '.yz-slot-t3');
                                const open = tgt.style.display !== 'none';
                                const t2tbl = soRow.closest('table');
                                const soTbody = soRow.closest('tbody');
                                const totalRow = t2tbl.querySelector(
                                    '.yz-t2-total-outs');

                                if (!open) {
                                    soTbody.classList.add('so-focus-mode');
                                    soRow.classList.add('is-focused');
                                    if (totalRow) totalRow.style.display =
                                        'none';
                                } else {
                                    soTbody.classList.remove(
                                        'so-focus-mode');
                                    soRow.classList.remove('is-focused');
                                }

                                if (open) {
                                    tgt.style.display = 'none';
                                    caret?.classList.remove('rot');
                                    const allT3Rows = t2tbl
                                        .querySelectorAll('tr.yz-nest');
                                    let anyOtherT3Open = false;
                                    for (const row of allT3Rows) {
                                        if (row.style.display !== 'none' &&
                                            row !== tgt) {
                                            anyOtherT3Open = true;
                                            break;
                                        }
                                    }
                                    if (!anyOtherT3Open && totalRow)
                                        totalRow.style.display = '';
                                    updateT2FooterVisibility(t2tbl);
                                    return;
                                }

                                tgt.style.display = '';
                                caret?.classList.add('rot');
                                updateT2FooterVisibility(t2tbl);
                                if (totalRow) totalRow.style.display =
                                    'none';

                                if (tgt.dataset.loaded === '1') return;

                                box.innerHTML = `
                <div class="p-2 text-muted small yz-loader-pulse">
                  <div class="spinner-border spinner-border-sm me-2"></div>Memuat detail…
                </div>`;

                                const u3 = new URL(apiT3, window.location
                                    .origin);
                                u3.searchParams.set('vbeln', vbeln);
                                if (WERKS) u3.searchParams.set('werks',
                                    WERKS);
                                if (AUART) u3.searchParams.set('auart',
                                    AUART);

                                const r3 = await fetch(u3);
                                const j3 = await r3.json();
                                if (!r3.ok || !j3.ok) throw new Error(j3
                                    .error ||
                                    'Gagal memuat detail item');

                                box.innerHTML = renderT3(j3.data);
                                tgt.dataset.loaded = '1';

                                box.querySelectorAll('.check-item').forEach(
                                    chk => {
                                        const sid = sanitizeId(chk
                                            .dataset.id);
                                        chk.checked = !!(sid &&
                                            selectedItems.has(sid));
                                    });
                            });
                        });

                        // [Event listeners checkbox T2/T3]
                        wrap.addEventListener('change', async (e) => {
                            if (e.target.classList.contains('check-all-items')) {
                                const t3 = e.target.closest('table');
                                t3.querySelectorAll('.check-item').forEach(ch => {
                                    const sid = sanitizeId(ch.dataset.id);
                                    if (!sid) return;
                                    ch.checked = e.target.checked;
                                    if (e.target.checked) selectedItems.add(
                                        sid);
                                    else selectedItems.delete(sid);
                                });
                                const anyItem = t3.querySelector('.check-item');
                                if (anyItem) {
                                    const v = itemIdToSO.get(String(anyItem.dataset
                                        .id));
                                    if (v) soHasSelectionDot(v);
                                }
                                updateExportButton();
                                return;
                            }

                            if (e.target.classList.contains('check-item')) {
                                const sid = sanitizeId(e.target.dataset.id);
                                if (!sid) return;
                                if (e.target.checked) selectedItems.add(sid);
                                else selectedItems.delete(sid);
                                const v = itemIdToSO.get(String(sid));
                                if (v) soHasSelectionDot(v);
                                updateExportButton();
                                return;
                            }

                            if (e.target.classList.contains('check-all-sos')) {
                                const allSO = wrap.querySelectorAll('.js-t2row');
                                for (const soRow of allSO) {
                                    const chk = soRow.querySelector('.check-so');
                                    chk.checked = e.target.checked;

                                    const vbeln = chk.dataset.vbeln;
                                    const nest = soRow.nextElementSibling;
                                    const box = nest.querySelector('.yz-slot-t3');
                                    const caret = soRow.querySelector('.yz-caret');
                                    const t2tbl = soRow.closest('table');

                                    if (e.target.checked) {
                                        if (nest.style.display === 'none') {
                                            nest.style.display = '';
                                            caret?.classList.add('rot');
                                        }
                                        if (nest.dataset.loaded !== '1') {
                                            const u3 = new URL(
                                                "{{ route('dashboard.api.t3') }}",
                                                window.location.origin);
                                            if (WERKS) u3.searchParams.set('werks',
                                                WERKS);
                                            if (AUART) u3.searchParams.set('auart',
                                                AUART);
                                            u3.searchParams.set('vbeln', vbeln);
                                            const r3 = await fetch(u3);
                                            const j3 = await r3.json();
                                            if (j3?.ok) {
                                                box.innerHTML = renderT3(j3.data);
                                                nest.dataset.loaded = '1';
                                            } else {
                                                box.innerHTML =
                                                    `<div class="alert alert-danger m-2">Gagal memuat detail item</div>`;
                                            }
                                        }
                                        box.querySelectorAll('.check-item').forEach(
                                            ci => {
                                                const sid = sanitizeId(ci
                                                    .dataset.id);
                                                if (!sid) return;
                                                ci.checked = true;
                                                selectedItems.add(sid);
                                            });
                                    } else {
                                        if (nest.dataset.loaded === '1') {
                                            box.querySelectorAll('.check-item')
                                                .forEach(ci => {
                                                    const sid = sanitizeId(ci
                                                        .dataset.id);
                                                    if (!sid) return;
                                                    ci.checked = false;
                                                    selectedItems.delete(sid);
                                                });
                                        } else {
                                            const u3 = new URL(
                                                "{{ route('dashboard.api.t3') }}",
                                                window.location.origin);
                                            if (WERKS) u3.searchParams.set('werks',
                                                WERKS);
                                            if (AUART) u3.searchParams.set('auart',
                                                AUART);
                                            u3.searchParams.set('vbeln', vbeln);
                                            const r3 = await fetch(u3);
                                            const j3 = await r3.json();
                                            if (j3?.ok) {
                                                j3.data.forEach(it => {
                                                    const sid = sanitizeId(
                                                        it.id);
                                                    if (sid) selectedItems
                                                        .delete(sid);
                                                });
                                            }
                                        }
                                        nest.style.display = 'none';
                                        caret?.classList.remove('rot');
                                    }
                                    updateT2FooterVisibility(t2tbl);
                                    soHasSelectionDot(vbeln);
                                }
                                updateExportButton();
                                return;
                            }

                            if (e.target.classList.contains('check-so')) {
                                const soRow = e.target.closest('.js-t2row');
                                const vbeln = e.target.dataset.vbeln;
                                const nest = soRow.nextElementSibling;
                                const box = nest.querySelector('.yz-slot-t3');
                                const caret = soRow.querySelector('.yz-caret');
                                const t2tbl = soRow.closest('table');

                                if (e.target.checked) {
                                    if (nest.style.display === 'none') {
                                        nest.style.display = '';
                                        caret?.classList.add('rot');
                                    }
                                    if (nest.dataset.loaded !== '1') {
                                        const u3 = new URL(
                                            "{{ route('dashboard.api.t3') }}",
                                            window.location.origin);
                                        if (WERKS) u3.searchParams.set('werks',
                                            WERKS);
                                        if (AUART) u3.searchParams.set('auart',
                                            AUART);
                                        u3.searchParams.set('vbeln', vbeln);
                                        const r3 = await fetch(u3);
                                        const j3 = await r3.json();
                                        if (j3?.ok) {
                                            box.innerHTML = renderT3(j3.data);
                                            nest.dataset.loaded = '1';
                                        } else {
                                            box.innerHTML =
                                                `<div class="alert alert-danger m-2">Gagal memuat detail item</div>`;
                                        }
                                    }
                                    box.querySelectorAll('.check-item').forEach(
                                        ci => {
                                            const sid = sanitizeId(ci.dataset
                                                .id);
                                            if (!sid) return;
                                            ci.checked = true;
                                            selectedItems.add(sid);
                                        });
                                } else {
                                    if (nest.dataset.loaded === '1') {
                                        box.querySelectorAll('.check-item').forEach(
                                            ci => {
                                                const sid = sanitizeId(ci
                                                    .dataset.id);
                                                if (!sid) return;
                                                ci.checked = false;
                                                selectedItems.delete(sid);
                                            });
                                    } else {
                                        const u3 = new URL(
                                            "{{ route('dashboard.api.t3') }}",
                                            window.location.origin);
                                        if (WERKS) u3.searchParams.set('werks',
                                            WERKS);
                                        if (AUART) u3.searchParams.set('auart',
                                            AUART);
                                        u3.searchParams.set('vbeln', vbeln);
                                        const r3 = await fetch(u3);
                                        const j3 = await r3.json();
                                        if (j3?.ok) {
                                            j3.data.forEach(it => {
                                                const sid = sanitizeId(it
                                                    .id);
                                                if (sid) selectedItems
                                                    .delete(sid);
                                            });
                                        }
                                    }
                                    nest.style.display = 'none';
                                    caret?.classList.remove('rot');
                                }

                                updateT2FooterVisibility(t2tbl);
                                soHasSelectionDot(vbeln);
                                updateExportButton();
                                return;
                            }
                        });

                    } catch (err) {
                        wrap.innerHTML =
                            `<div class="alert alert-danger m-3">${err.message}</div>`;
                    }
                });
            });

            // Klik bar segment (distribution table)
            document.querySelector('.yz-chart-card')?.addEventListener('click', async (e) => {
                const seg = e.target.closest('.bar-segment');
                if (!seg) return;

                const bucket = seg.dataset.bucket || '';
                const werks = seg.dataset.werks || '';
                const auart = seg.dataset.auart || '';

                const rowTitle = activeCustomerName || 'Selected PO Type';
                const finalAuart = auart || AUART;

                const labelText =
                    bucket === 'on_track' ? 'On-Track (Tidak Overdue)' :
                    bucket === '1_30' ? '1–30 Days Overdue' :
                    bucket === '31_60' ? '31–60 Days Overdue' :
                    bucket === '61_90' ? '61–90 Days Overdue' : '>90 Days Overdue';

                const isOverdue = bucket !== 'on_track';
                const modalHeader = modalElement.querySelector('.modal-header');
                const modalIcon = modalElement.querySelector('#overdueDetailsModalLabel i');
                if (isOverdue) {
                    modalHeader.classList.add('bg-danger');
                    modalHeader.classList.remove('bg-success');
                    if (modalIcon) modalIcon.className = 'fas fa-triangle-exclamation me-2';
                } else {
                    modalHeader.classList.add('bg-success');
                    modalHeader.classList.remove('bg-danger');
                    if (modalIcon) modalIcon.className = 'fas fa-circle-check me-2';
                }

                const modalTitle = modalElement.querySelector('#overdueDetailsModalLabel');
                if (modalTitle) modalTitle.textContent = isOverdue ? 'Detail PO Overdue' :
                    'Detail PO On-Track';

                const kpiCard = document.querySelector('.yz-chart-card');
                await scrollToCenter(kpiCard);

                let modalFilterText = `${rowTitle} / ${labelText}`;
                modalSubTitle.textContent = `Filter: ${modalFilterText}...`;
                modalContentArea.innerHTML = `
      <div class="text-center p-5">
        <div class="spinner-border ${isOverdue ? 'text-danger' : 'text-success'}" role="status"></div>
        <p class="mt-3 text-muted">Memuat detail PO...</p>
      </div>`;

                modalElement.addEventListener('shown.bs.modal', () => {
                    document.body.style.overflow = 'auto';
                    document.body.style.paddingRight = '';
                }, {
                    once: true
                });
                overdueDetailsModal.show();

                try {
                    if (!werks || !finalAuart) throw new Error(
                        'Parameter Plant atau Order Type kosong.');

                    const api = new URL(apiPoOverdueDetails, window.location.origin);
                    api.searchParams.set('werks', werks);
                    api.searchParams.set('auart', finalAuart);
                    api.searchParams.set('bucket', bucket);
                    if (activeCustomerKunnr) api.searchParams.set('kunnr', activeCustomerKunnr);

                    const res = await fetch(api, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const json = await res.json();
                    if (!json.ok) throw new Error(json?.message || json?.error ||
                        'Gagal mengambil data.');

                    modalSubTitle.textContent = `${modalFilterText} (${json.data.length} PO)`;
                    modalContentArea.innerHTML = renderModalTable(json.data, labelText);
                } catch (err) {
                    modalSubTitle.textContent = `Gagal Memuat Detail`;
                    modalContentArea.innerHTML =
                        `<div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i> ${err.message || 'Terjadi kesalahan saat mengambil data.'}</div>`;
                }
            });

            modalElement.addEventListener('click', (ev) => {
                if (ev.target === modalElement) overdueDetailsModal.hide();
            });

            if (exportDropdownContainer) {
                exportDropdownContainer.addEventListener('click', (e) => {
                    const opt = e.target.closest('.export-option');
                    if (!opt) return;
                    e.preventDefault();
                    if (selectedItems.size === 0) {
                        alert('Pilih minimal 1 item.');
                        return;
                    }
                    const exportType = opt.dataset.type;

                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = "{{ route('po.export') }}";
                    form.target = '_blank';

                    const add = (n, v) => {
                        const i = document.createElement('input');
                        i.type = 'hidden';
                        i.name = n;
                        i.value = v;
                        form.appendChild(i);
                    };
                    add('_token', "{{ csrf_token() }}");
                    add('export_type', exportType);
                    add('werks', "{{ $selected['werks'] ?? '' }}");
                    add('auart', "{{ $selected['auart'] ?? '' }}");
                    Array.from(selectedItems).forEach(id => add('item_ids[]', id));

                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);
                });
            }

            /* ====================== SMALL QTY CHART INITIAL ====================== */
            if (document.getElementById('chartSmallQtyByCustomer')) {
                const smallQtySection = document.getElementById('small-qty-section');
                if (!defaultSmallQtyDataRaw || defaultSmallQtyDataRaw.length === 0) {
                    if (smallQtySection) smallQtySection.style.display = 'none';
                } else {
                    if (smallQtySection) smallQtySection.style.display = '';
                    renderSmallQtyChart(defaultSmallQtyDataRaw, null);
                }
            }

            const closeButton = document.getElementById('closeDetailsTable');
            closeButton?.addEventListener('click', () => {
                document.getElementById('smallQtyDetailsContainer').style.display = 'none';
                renderSmallQtyChart(defaultSmallQtyDataRaw, null);
            });

            const smallQtyExportForm = document.getElementById('smallQtyExportForm');
            if (exportSmallQtyPdfBtn && smallQtyExportForm) {
                exportSmallQtyPdfBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    smallQtyExportForm.submit();
                });
            }

            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                new bootstrap.Tooltip(el);
            });

            /* ====================== AUTO EXPAND & HIGHLIGHT (HASIL SEARCH) ====================== */
            (function autoOpenFromSearch() {
                const root = document.getElementById('yz-root');
                const needAutoExpand = !!parseInt(root?.dataset.autoExpand || 0);
                if (!needAutoExpand) return;

                const HL_KUNNR = (root.dataset.hKunnr || '').trim();
                const HL_VBELN = (root.dataset.hVbeln || '').trim();
                const HL_BSTNK = (root.dataset.hBstnk || '').trim();
                if (!HL_KUNNR) return;

                const onlyDigits = s => String(s || '').replace(/\D/g, '');
                const pad10 = s => onlyDigits(s).padStart(10, '0');
                const wait = ms => new Promise(r => setTimeout(r, ms));

                // 1) buka baris customer (Tabel-2 muncul)
                const findCustomerRow = () => {
                    const d = onlyDigits(HL_KUNNR);
                    if (!d) return null;
                    return document.querySelector(
                            `tr.yz-kunnr-row[data-kunnr='${CSS.escape(pad10(d))}']`) ||
                        document.querySelector(`tr.yz-kunnr-row[data-kunnr$='${CSS.escape(d)}']`);
                };

                (async () => {
                    const custRow = findCustomerRow();
                    if (!custRow) return;
                    custRow.click(); // ini hanya membuka Tabel-2

                    const slot = document.getElementById(custRow.dataset.kid);
                    if (!slot) return;

                    // 2) tunggu Tabel-2 dirender
                    let tries = 0;
                    while (tries++ < 100) {
                        if (slot.querySelector('.js-t2row')) break;
                        await wait(100);
                    }

                    const t2Rows = Array.from(slot.querySelectorAll('.js-t2row'));
                    if (!t2Rows.length) return;

                    // 3) cari baris SO target
                    const byVbeln = v => t2Rows.find(r => (r.querySelector('.yz-t2-vbeln')
                        ?.textContent || '').trim() === v);
                    const byPO = p => t2Rows.find(r => (r.children[2]?.textContent || '').trim() === p);
                    const targetRow = (HL_VBELN && byVbeln(HL_VBELN)) || (HL_BSTNK && byPO(HL_BSTNK)) ||
                        t2Rows[0];

                    // 4) JANGAN klik -> agar Tabel-3 TIDAK terbuka
                    //    cukup scroll & highlight baris Tabel-2
                    //    (bersihkan highlight lama dulu jika ada)
                    document.querySelectorAll('.row-highlighted')
                        .forEach(el => el.classList.remove('row-highlighted'));

                    targetRow.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });

                    // pastikan nested T3 tetap tertutup & caret kembali
                    const nest = targetRow.nextElementSibling;
                    if (nest?.classList.contains('yz-nest')) {
                        nest.style.display = 'none';
                        targetRow.querySelector('.yz-caret')?.classList.remove('rot');
                    }

                    // pakai kelas highlight bawaan CSS, dan BIARKAN sampai user klik barisnya
                    targetRow.classList.add('row-highlighted');

                    // Begitu baris SO diklik (untuk buka Tabel-3 manual), matikan highlight sekali saja.
                    const removeHL = () => targetRow.classList.remove('row-highlighted');
                    targetRow.addEventListener('click', removeHL, {
                        capture: true,
                        once: true
                    });
                })();
            })();
        });
    </script>
@endpush
