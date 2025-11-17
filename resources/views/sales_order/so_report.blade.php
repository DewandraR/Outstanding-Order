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
                <div class="py-1 d-flex align-items-center gap-2">
                    <div class="yz-material-toggle-container me-2" role="group" aria-label="Material mode">
                        <button type="button" id="btn-mode-wood" class="yz-toggle-btn yz-wood active" data-mode="wood">
                            <i class="fas fa-tree me-1"></i>WOOD
                        </button>
                        <button type="button" id="btn-mode-metal" class="yz-toggle-btn yz-metal" data-mode="metal">
                            <i class="fas fa-industry me-1"></i>METAL
                        </button>
                        <div class="yz-toggle-slider" id="yz-toggle-slider"></div>
                    </div>
                    <div class="dropdown" id="export-dropdown-container" style="display:none;">
                        <button class="btn btn-primary dropdown-toggle" type="button" id="export-btn"
                            data-bs-toggle="dropdown" aria-expanded="false">
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
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">

                            {{-- Judul --}}
                            <h5 class="yz-table-title mb-0">
                                <i class="fas fa-file-invoice me-2"></i>Outstanding SO
                            </h5>

                            {{-- Kanan: Search + checkbox + COLABS (layout sama seperti PO) --}}
                            <div class="d-flex align-items-center gap-3 flex-wrap ms-auto">

                                {{-- GLOBAL SEARCH: CUST / PO / SO / Item (SAMA DENGAN REPORT PO) --}}
                                <form id="so-global-search-form" class="d-flex align-items-center gap-2" method="GET"
                                    action="{{ route('so.index') }}">

                                    {{-- pertahankan filter plant & type --}}
                                    <input type="hidden" name="werks" value="{{ $selectedWerks }}">
                                    <input type="hidden" name="auart" value="{{ $selectedAuart }}">

                                    <div class="input-group input-group-sm" style="min-width: 320px;">
                                        <input type="text" name="keyword" id="so-item-search-input" class="form-control"
                                            placeholder="Cari: Material/Desc (FG)" value="{{ request('keyword') }}">
                                        <button class="btn btn-outline-primary" type="submit" id="so-item-search-btn">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                </form>

                                {{-- Pilih semua customer (posisinya sama seperti di PO) --}}
                                <div class="form-check m-0">
                                    <input class="form-check-input" type="checkbox" id="check-all-customers">
                                    <label class="form-check-label" for="check-all-customers">Pilih semua customer</label>
                                </div>

                                {{-- Tombol COLABS tetap --}}
                                <button class="btn btn-colabs btn-outline-secondary btn-lg" id="btn-open-selected"
                                    type="button" style="display:none">
                                    <i class="fas fa-layer-group me-1"></i>
                                    COLABS: Open To SO Item
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="yz-customer-list px-md-3 pt-3">

                        {{-- Customer Cards Container --}}
                        <div class="d-grid gap-0 mb-4" id="customer-list-container">
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
                                            {{-- Checkbox customer (tidak memicu toggle kartu) --}}
                                            <input type="checkbox" class="form-check-input me-2 check-customer"
                                                data-kunnr="{{ $r->KUNNR }}" onclick="event.stopPropagation()"
                                                onmousedown="event.stopPropagation()">

                                            <span class="kunnr-caret me-3"><i class="fas fa-chevron-right"></i></span>
                                            <div class="customer-info">
                                                <div class="fw-bold fs-5 text-truncate">{{ $r->NAME1 }}</div>
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
                                                <div class="metric-value fs-4 fw-bold text-dark">{{ $displayOutsValue }}
                                                </div>
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
                                <h6 class="mb-0 text-dark-emphasis"><i class="fas fa-chart-pie me-2"></i>Total Keseluruhan
                                </h6>

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
                                        <div class="fw-bold fs-4 text-dark">{{ $formatTotals($pageTotalsAll ?? []) }}
                                        </div>
                                        <div class="metric-label"> Total Outs. Value</div>
                                    </div>

                                    {{-- Total Overdue Value --}}
                                    <div class="metric-box mx-4 text-end" style="min-width: 180px;">
                                        <div class="fw-bold fs-4 text-danger">
                                            {{ $formatTotals($pageTotalsOverdue ?? []) }}
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
                                <i class="fas fa-info-circle fa-2x mb-2"></i><br>Tidak ada item outstanding dengan Qty
                                Outs. SO
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
                            <label for="remark-input" class="form-label mb-1">Tambah Catatan (maks. 100 karakter)</label>
                            <div class="d-flex align-items-start gap-2">
                                <textarea id="remark-input" class="form-control" rows="2" maxlength="100"
                                    placeholder="Tulis catatan singkat..."></textarea>
                                <button type="button" id="add-remark-btn" class="btn btn-primary">
                                    <i class="fas fa-paper-plane me-1"></i> Tambah
                                </button>
                            </div>
                            <div class="d-flex justify-content-between mt-1 small">
                                <span id="remark-feedback" class="text-muted"></span>
                                <span id="remark-counter" class="text-muted">0/100</span>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="machiningModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            Machining Lines
                            <small class="text-muted d-block">
                                SO <span id="machi-so"></span> • Item <span id="machi-pos"></span>
                                • <br><span id="machi-desc" style="max-width:60vw;"></span>
                            </small>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div id="machiningModalBody" class="p-3 text-muted d-flex align-items-center">
                            <div class="spinner-border spinner-border-sm me-2"></div> Memuat data...
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal fade" id="pembahananModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            Pembahanan Lines
                            <small class="text-muted d-block">
                                SO <span id="pemb-so"></span> • Item <span id="pemb-pos"></span><br>
                                <span id="pemb-desc" style="max-width:60vw;"></span>
                            </small>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div id="pembahananModalBody" class="p-3 text-muted d-flex align-items-center">
                            <div class="spinner-border spinner-border-sm me-2"></div> Memuat data...
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
        <link rel="stylesheet" href="{{ asset('css/so.css') }}">
    @endpush

    @push('scripts')
        <script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
        <script src="{{ asset('vendor/chartjs/chartjs-adapter-date-fns.bundle.min.js') }}"></script>

        <script>
            (() => {
                'use strict';
                let isColabsActive = false;

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
                const apiMachiningLines = "{{ route('so.api.machining_lines') }}"; // <-- PENTING
                const apiPembahananLines = "{{ route('so.api.pembahanan_lines') }}";
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
                // Persen MACHI: dibagi 100 (3263 -> 32.63%). (Tetap 0 desimal sesuai skrip Anda)
                const formatMachiPercent = (v) => {
                    const n = parseFloat(v);
                    if (!Number.isFinite(n) || n === 0) return '0%';
                    const corrected = n / 100;
                    return `${formatNumberGlobal(corrected, 0)}%`;
                };
                // Persen stage lain (tanpa pembagian)
                const formatPercent = (v) => {
                    const n = parseFloat(v);
                    if (!Number.isFinite(n)) return '';
                    return `${formatNumberGlobal(n, 0)}%`;
                };
                const formatPercentScale1 = (v) => {
                    const n = parseFloat(v);
                    if (!Number.isFinite(n)) return '';
                    return `${formatNumberGlobal(n * 100, 0)}%`;
                };
                const formatPercentAuto = (v) => {
                    const n = parseFloat(v);
                    if (!Number.isFinite(n)) return '0%';
                    // bila <=1 anggap rasio → kalikan 100
                    return `${formatNumberGlobal(n <= 1 ? (n * 100) : n, 0)}%`;
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
                const escapeHtml = (s) => String(s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
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
                const scrollAndFlashTemp = (el, ms = 4500) => {
                    scrollAndFlash(el);
                    setTimeout(() => el.classList.remove('row-highlighted'), ms);
                };

                /* =========================================================
                 * POPOVER (hover) — kembali seperti awal
                 * ======================================================= */
                const makeStagePopoverContent = (stageName, grRaw, orderRaw) => {
                    const toNum = v => {
                        const n = Number(v);
                        return Number.isFinite(n) ? n : null;
                    };

                    // nilai numerik apa adanya (sudah dipasok lewat data-* di masing2 stage)
                    const fm = toNum(grRaw); // GR / TP (tergantung stage)
                    const fq = toNum(orderRaw); // Total Order / Request (tergantung stage)

                    // === label dinamis khusus Pembahanan ===
                    const isPemb = (String(stageName).trim().toLowerCase() === 'pembahanan');
                    const pairLabel = isPemb ? 'TP / Request' : 'GR / Total Order';

                    const grValue = fm !== null ? formatNumberGlobal(fm, 0) : '—';
                    const orderValue = fq !== null ? formatNumberGlobal(fq, 0) : '—';

                    const totalOrder = fq !== null && fq > 0 ? fq : 1;
                    const grProgress = (fm / totalOrder) * 100;

                    const isCompleted = (fm !== null && fq !== null && fm >= fq && fq > 0);
                    const status = isCompleted ? 'Selesai' : (fm > 0 ? 'Sedang Diproses' : 'Belum Mulai');
                    const statusClass = isCompleted ? 'text-success' : (fm > 0 ? 'text-warning' : 'text-muted');

                    const progressStyle = `width: ${Math.min(100, grProgress || 0)}%;`;
                    const progressClass = isCompleted ? 'bg-success' : (fm > 0 ? 'bg-warning' : 'bg-secondary');

                    return `
    <div class="yz-popover-content-lux">
      <h6 class="mb-2 fw-bold">Progress Stage: ${escapeHtml(stageName)}</h6>

      <div class="d-flex justify-content-between mb-1">
        <span class="fw-bolder">${pairLabel}</span>
        <span class="fw-bolder">${grValue} / ${orderValue}</span>
      </div>

      <div class="progress" style="height:8px;">
        <div class="progress-bar ${progressClass}" role="progressbar" style="${progressStyle}"
             aria-valuenow="${Math.min(100, grProgress || 0)}" aria-valuemin="0" aria-valuemax="100"></div>
      </div>

      <hr class="my-2">
      <div class="small ${statusClass}">Status: <strong>${status}</strong></div>
    </div>`;
                };

                function attachBootstrapPopovers(container) {
                    if (!container || !window.bootstrap || !bootstrap.Popover) return;
                    container.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => {
                        const existing = bootstrap.Popover.getInstance(el);
                        if (existing) existing.dispose();

                        const stage = el.dataset.stage || 'Proses Tahap';
                        const gr = el.dataset.gr || '';
                        const order = el.dataset.order || '';

                        const isTouch = matchMedia('(hover: none)').matches;
                        new bootstrap.Popover(el, {
                            container: 'body',
                            html: true,
                            placement: 'auto',
                            trigger: isTouch ? 'click' : 'hover',
                            customClass: 'yz-lux-popover',
                            content: makeStagePopoverContent(stage, gr, order),
                            sanitize: false
                        });


                        el.classList.add('popover-cursor');
                        // Tambah affordance "klik" khusus Machining
                        const st = (stage || '').toLowerCase();
                        if (st === 'machining' || st === 'pembahanan') {
                            el.style.cursor = 'pointer';
                            el.style.textDecoration = 'underline';
                        }
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
                    const dedupItems = uniqBy(jd.data || [], x => `${x.VBELN_KEY}|${x.POSNR_KEY}|${x.MATNR ?? ''}`);
                    dedupItems.forEach(x => itemIdToSO.set(String(x.id), vbeln));
                    itemsCache.set(vbeln, dedupItems);
                    return dedupItems;
                }

                /* =========================================================
                 * UI HELPERS
                 * ======================================================= */
                const globalTotalsCard = document.querySelector('.yz-global-total-card');
                const isActuallyVisible = el => !!(el && el.getClientRects().length > 0);

                function updateGlobalTotalCardVisibility() {
                    if (!globalTotalsCard) return;

                    // JIka COLABS aktif, jangan sembunyikan footer global
                    if (typeof isColabsActive !== 'undefined' && isColabsActive) {
                        globalTotalsCard.style.display = '';
                        return;
                    }

                    // Tabel-2 dianggap terbuka jika ada kontainer nest customer yang terlihat
                    const anyLevel2Open = Array.from(document.querySelectorAll('.yz-nest-card'))
                        .some(isActuallyVisible);

                    // Tabel-3 dianggap terbuka jika ada baris nest (items) yang terlihat
                    const anyLevel3Open = Array.from(document.querySelectorAll('tr.yz-nest'))
                        .some(isActuallyVisible);

                    const shouldHide = anyLevel2Open || anyLevel3Open;
                    globalTotalsCard.style.display = shouldHide ? 'none' : '';
                }

                // panggil sekali saat load
                updateGlobalTotalCardVisibility();
                const selectedItems = new Set();
                const exportDropdownContainer = document.getElementById('export-dropdown-container');
                const selectedCountSpan = document.getElementById('selected-count');
                const updateExportButton = () => {
                    const n = selectedItems.size;
                    if (selectedCountSpan) selectedCountSpan.textContent = n;
                    if (exportDropdownContainer) exportDropdownContainer.style.display = n > 0 ? 'block' : 'none';
                };
                const updateSODot = (vbeln) => {
                    document.querySelectorAll(`.js-t2row[data-vbeln='${CSS.escape(vbeln)}'] .so-selected-dot`)
                        .forEach(dot => {
                            const anySel = Array.from(selectedItems).some(id => itemIdToSO.get(String(id)) ===
                                vbeln);
                            dot.style.display = anySel ? 'inline-block' : 'none';
                        });
                };
                const recalcSoRemarkFlagFromDom = (vbeln) => {
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
                };
                const applySelectionsToRenderedItems = (container) => {
                    container.querySelectorAll('.check-item').forEach(chk => {
                        chk.checked = selectedItems.has(chk.dataset.id);
                    });
                };
                const syncCheckAllHeader = (itemBox) => {
                    const table = itemBox?.querySelector('table');
                    if (!table) return;
                    const hdr = table.querySelector('.check-all-items');
                    if (!hdr) return;
                    const all = Array.from(table.querySelectorAll('.check-item'));
                    const allChecked = (all.length > 0 && all.every(ch => ch.checked));
                    const anyChecked = all.some(ch => ch.checked);
                    hdr.checked = allChecked;
                    hdr.indeterminate = !allChecked && anyChecked;
                };
                const syncCheckAllSoHeader = (tbody) => {
                    const allSOCheckboxes = Array.from(tbody.querySelectorAll('.check-so')).filter(ch => ch.closest(
                        'tr').style.display !== 'none');
                    const selectAllSo = tbody.closest('table')?.querySelector('.check-all-sos');
                    if (!selectAllSo || allSOCheckboxes.length === 0) return;
                    const allChecked = allSOCheckboxes.every(ch => ch.checked);
                    selectAllSo.checked = allChecked;
                    selectAllSo.indeterminate = false;
                };
                const getCollapse = (tbody) => !!(tbody && tbody.dataset && tbody.dataset.collapse === '1');
                const setCollapse = (tbody, on) => {
                    if (tbody && tbody.dataset) tbody.dataset.collapse = on ? '1' : '0';
                };
                const closeAllOpenSOTables = () => {
                    // Reset semua Tabel-2 (SO) ke kondisi normal:
                    // - tidak collapse
                    // - semua baris SO kelihatan
                    // - semua Tabel-3 tertutup
                    document.querySelectorAll('table tbody').forEach(tb => {
                        if (!tb.querySelector('.js-t2row')) return; // hanya Tabel-2

                        // matikan mode collapse & fokus
                        setCollapse(tb, false);
                        tb.classList.remove('collapse-mode', 'so-focus-mode');

                        tb.querySelectorAll('.js-t2row').forEach(r => {
                            r.style.display = ''; // tampilkan semua SO
                            r.classList.remove('is-focused', 'so-visited');

                            const nest = r.nextElementSibling;
                            if (nest) {
                                nest.style.display = 'none'; // tutup Tabel-3
                            }
                            r.querySelector('.yz-caret')?.classList.remove('rot');
                        });

                        updateT2FooterVisibility(tb.closest('table'));
                    });
                };
                const updateT2FooterVisibility = (t2Table) => {
                    if (!t2Table) return;
                    const anyOpen = [...t2Table.querySelectorAll('tr.yz-nest')]
                        .some(tr => tr.style.display !== 'none' && tr.offsetParent !== null);
                    const tfoot = t2Table.querySelector('tfoot.t2-footer');
                    const tbody = t2Table.querySelector('tbody');
                    if (tfoot) tfoot.style.display = (anyOpen || getCollapse(tbody)) ? 'none' : '';
                };

                /* =========================================================
                 * RENDERERS (Level 3 Items)
                 * ======================================================= */
                function renderLevel3_Items(rows, mode = 'wood') {
                    // fallback bila tidak ada data
                    if (!rows || !rows.length) {
                        return `<div class="p-2 text-muted">Tidak ada item detail (dengan Outs. SO > 0).</div>`;
                    }

                    const isMetal = (mode === 'metal');

                    // helper lokal: persen auto-scale (untuk PRSIMT: bisa 0..1 atau 0..100)
                    const formatPercentAuto = (v) => {
                        const n = parseFloat(v);
                        if (!Number.isFinite(n) || n === 0) return '0%';
                        const val = (n <= 1 ? (n * 100) : n);
                        return `${formatNumberGlobal(val, 0)}%`;
                    };

                    // ===== Header =====
                    const headerHtml = `
<tr>
  <th style="width:40px;"><input class="form-check-input check-all-items" type="checkbox" title="Pilih Semua Item"></th>
  <th>Item</th>
  <th>Material FG</th>
  <th>Desc FG</th>
  <th>Qty SO</th>
  <th>Outs. SO</th>
  <th>Stock Packing</th>
  ${isMetal ? '' : '<th>Pembahanan</th>'}          <!-- ⬅ tampil hanya WOOD -->
  <th>${isMetal ? 'CUTING' : 'MACHI'}</th>
  <th>ASSY</th>
  ${isMetal ? '<th>PRIMER</th>' : ''}              <!-- PRIMER hanya METAL -->
  <th>PAINT</th>
  <th>PACKING</th>
  <th>Remark</th>
</tr>`;

                    let html = `
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0 yz-mini">
        <thead class="yz-header-item">
          ${headerHtml}
        </thead>
        <tbody>`;

                    rows.forEach(r => {
                        // checkbox state & remark count
                        const isChecked = selectedItems && selectedItems.has(String(r.id));
                        const countRemarks = Number(r.remark_count ?? ((r.remark && String(r.remark).trim() !==
                            '') ? 1 : 0));

                        // ===== Persentase per proses =====
                        const pembPercent = formatPercent(r.PRSM2); // (0..100) – pembahanan
                        const cutingPercent = isMetal ? formatPercentAuto(r.PRSC) : formatMachiPercent(r.PRSM);
                        const assyPercent = isMetal ? formatPercentAuto(r.PRSAM) : formatPercent(r.PRSA);
                        const primerPercent = isMetal ? formatPercentAuto(r.PRSIR) : null; // (0..1) → %
                        const paintPercent = isMetal ? formatPercentAuto(r
                                .PRSIMT) // METAL pakai PRSIMT (auto-scale)
                            :
                            formatPercent(r.PRSI); // WOOD pakai PRSI
                        const packingPct = formatPercent(r.PRSP);

                        html += `
      <tr id="item-${r.VBELN_KEY}-${r.POSNR_KEY}"
          data-item-id="${r.id}"
          data-werks="${r.WERKS_KEY}"
          data-auart="${r.AUART_KEY}"
          data-vbeln="${r.VBELN_KEY}"
          data-posnr="${r.POSNR}"
          data-posnr-key="${r.POSNR_KEY}"
          data-maktx="${escapeHtml(r.MAKTX ?? '')}">
        <td><input class="form-check-input check-item" type="checkbox" data-id="${r.id}" ${isChecked ? 'checked' : ''}></td>
  <td>${r.POSNR ?? ''}</td>
  <td>${r.MATNR ?? ''}</td>
  <td>${escapeHtml(r.MAKTX ?? '')}</td>
  <td>${formatNumberGlobal(r.KWMENG, 0)}</td>
  <td>${formatNumberGlobal(r.PACKG, 0)}</td>
  <td>${formatNumberGlobal(r.KALAB2, 0)}</td>

  ${isMetal ? '' : `
                                                                                                                                                                                                                                                          <!-- Pembahanan (popover T4) – hanya WOOD -->
                                                                                                                                                                                                                                                          <td>
                                                                                                                                                                                                                                                            <span class="yz-machi-pct"
                                                                                                                                                                                                                                                                  data-bs-toggle="popover"
                                                                                                                                                                                                                                                                  data-bs-placement="top"
                                                                                                                                                                                                                                                                  data-stage="Pembahanan"
                                                                                                                                                                                                                                                                  data-gr="${r.TOTTP ?? ''}"
                                                                                                                                                                                                                                                                  data-order="${r.TOTREQ ?? ''}"
                                                                                                                                                                                                                                                                  title="Progress Stage: Pembahanan">
                                                                                                                                                                                                                                                              ${pembPercent}
                                                                                                                                                                                                                                                            </span>
                                                                                                                                                                                                                                                          </td>`}

        <!-- CUTING (METAL) atau MACHI (WOOD) -->
        <td>
          ${isMetal
            ? `<span class="yz-machi-pct text-decoration-none"
                                                                                                                                                                                                                                                                                                                                             data-bs-toggle="popover"
                                                                                                                                                                                                                                                                                                                                             data-bs-placement="top"
                                                                                                                                                                                                                                                                                                                                             data-stage="Cuting"
                                                                                                                                                                                                                                                                                                                                             data-gr="${r.CUTT ?? ''}"
                                                                                                                                                                                                                                                                                                                                             data-order="${r.QPROC ?? ''}"
                                                                                                                                                                                                                                                                                                                                             title="Progress Stage: Cuting">${cutingPercent}</span>`
            : `<span class="yz-machi-pct"
                                                                                                                                                                                                                                                                                                                                             data-bs-toggle="popover"
                                                                                                                                                                                                                                                                                                                                             data-bs-placement="top"
                                                                                                                                                                                                                                                                                                                                             data-stage="Machining"
                                                                                                                                                                                                                                                                                                                                             data-gr="${r.MACHI ?? ''}"
                                                                                                                                                                                                                                                                                                                                             data-order="${r.QPROM ?? ''}"
                                                                                                                                                                                                                                                                                                                                             title="Progress Stage: Machining">${cutingPercent}</span>`}
        </td>

        <!-- ASSY -->
        <td>
          <span class="yz-machi-pct text-decoration-none"
                data-bs-toggle="popover"
                data-bs-placement="top"
                data-stage="Assembly"
                data-gr="${isMetal ? (r.ASSYMT ?? '') : (r.ASSYM ?? '')}"
                data-order="${isMetal ? (r.QPROAM ?? '') : (r.QPROA ?? '')}"
                title="Progress Stage: Assembly">
            ${assyPercent}
          </span>
        </td>

        <!-- PRIMER (hanya METAL) -->
        ${isMetal ? `
                                                                                                                                                                                                                                                                                                                                <td>
                                                                                                                                                                                                                                                                                                                                  <span class="yz-machi-pct text-decoration-none"
                                                                                                                                                                                                                                                                                                                                        data-bs-toggle="popover"
                                                                                                                                                                                                                                                                                                                                        data-bs-placement="top"
                                                                                                                                                                                                                                                                                                                                        data-stage="Primer"
                                                                                                                                                                                                                                                                                                                                        data-gr="${r.PRIMER ?? ''}"
                                                                                                                                                                                                                                                                                                                                        data-order="${r.QPROIR ?? ''}"
                                                                                                                                                                                                                                                                                                                                        title="Progress Stage: Primer">
                                                                                                                                                                                                                                                                                                                                    ${primerPercent}
                                                                                                                                                                                                                                                                                                                                  </span>
                                                                                                                                                                                                                                                                                                                                </td>` : ''}

        <!-- PAINT -->
        <td>
          <span class="yz-machi-pct text-decoration-none"
                data-bs-toggle="popover"
                data-bs-placement="top"
                data-stage="Paint"
                data-gr="${isMetal ? (r.PAINTMT ?? '') : (r.PAINTM ?? '')}"
                data-order="${isMetal ? (r.QPROIMT ?? '') : (r.QPROI ?? '')}"
                title="Progress Stage: Paint">
            ${paintPercent}
          </span>
        </td>

        <!-- PACKING -->
        <td>
          <span class="yz-machi-pct text-decoration-none"
                data-bs-toggle="popover"
                data-bs-placement="top"
                data-stage="Packing"
                data-gr="${r.PACKGM ?? ''}"
                data-order="${r.QPROP ?? ''}"
                title="Progress Stage: Packing">
            ${packingPct}
          </span>
        </td>

        <!-- Remark -->
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
                style="display:${countRemarks > 0 ? 'inline-block' : 'none'};">${countRemarks}</span>
        </td>
      </tr>`;
                    });

                    html += `</tbody></table></div>`;
                    return html;
                }



                function renderLevel2_SO(rows, kunnr) {
                    if (!rows?.length)
                        return `<div class="p-3 text-muted">Tidak ada data Outstanding SO untuk customer ini.</div>`;
                    const totalOutsQtyT2 = rows.reduce((sum, r) => sum + parseFloat(r.outs_qty ?? r.OUTS_QTY ?? 0), 0);
                    let html = `
        <table class="table table-sm mb-0 yz-mini">
          <thead class="yz-header-so">
            <tr>
              <th style="width:40px;" class="text-center"><input type="checkbox" class="form-check-input check-all-sos" title="Pilih semua SO"></th>
              <th style="width:40px;" class="text-center"><button type="button" class="btn btn-sm btn-light js-collapse-toggle" title="Mode Kolaps/Fokus"><span class="yz-collapse-caret">▸</span></button></th>
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
                        const outsQty = (typeof r.outs_qty !== 'undefined') ? r.outs_qty : (r.OUTS_QTY ?? 0);
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
            <td class="text-center"><input type="checkbox" class="form-check-input check-so" data-vbeln="${r.VBELN}" onclick="event.stopPropagation()"></td>
            <td class="text-center"><span class="yz-caret">▸</span></td>
            <td class="text-start">
              <div class="fw-bold text-primary mb-1">
                <a href="#" class="js-open-so text-decoration-none" data-vbeln="${r.VBELN}" data-open-items="1">${r.VBELN}</a>
              </div>
              ${overdueBadge}
            </td>
            <td class="text-center fw-bold">${r.item_count ?? '-'}</td>
            <td class="text-start fw-bold fs-6">${displayValue}</td>
            <td class="text-center fw-bold">${escapeHtml(r.FormattedEdatu || '-')}</td>
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

                let materialMode = 'wood'; // default
                const yzToggleSlider = document.getElementById('yz-toggle-slider');
                const btnWood = document.getElementById('btn-mode-wood');
                const btnMetal = document.getElementById('btn-mode-metal');

                function updateToggleSlider() {
                    if (!yzToggleSlider || !btnWood || !btnMetal) return;

                    // Dapatkan lebar dan posisi
                    const activeBtn = materialMode === 'wood' ? btnWood : btnMetal;
                    const container = activeBtn.closest('.yz-material-toggle-container');
                    if (!container) return;

                    const containerRect = container.getBoundingClientRect();
                    const activeRect = activeBtn.getBoundingClientRect();

                    // Hitung posisi relatif ke container
                    const relativeLeft = activeRect.left - containerRect.left;

                    yzToggleSlider.style.width = activeRect.width + 'px';
                    yzToggleSlider.style.transform = `translateX(${relativeLeft}px)`;
                }

                function setMaterialMode(mode) {
                    if (materialMode === mode) return;
                    materialMode = mode;

                    // toggle gaya tombol
                    btnWood?.classList.toggle('active', mode === 'wood');
                    btnMetal?.classList.toggle('active', mode === 'metal');

                    updateToggleSlider(); // Panggil fungsi slider

                    // RERENDER SEMUA TABEL-3 yang sudah dimuat (tetap)
                    document.querySelectorAll('tr.yz-nest[data-loaded="1"]').forEach(nestTr => {
                        const box = nestTr.querySelector('.yz-slot-items');
                        const soRow = nestTr.previousElementSibling;
                        const vbeln = soRow?.dataset.vbeln;
                        if (!vbeln || !box) return;
                        const items = itemsCache.get(vbeln);
                        if (!items) return;

                        box.innerHTML = renderLevel3_Items(items, materialMode);
                        applySelectionsToRenderedItems(box);
                        syncCheckAllHeader(box);
                        attachBootstrapPopovers(box);
                    });
                }

                document.getElementById('btn-mode-wood')?.addEventListener('click', () => setMaterialMode('wood'));
                document.getElementById('btn-mode-metal')?.addEventListener('click', () => setMaterialMode('metal'));

                window.addEventListener('load', updateToggleSlider);
                window.addEventListener('resize', updateToggleSlider);

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

                    // Pasang popover untuk elemen yang sudah ada (jaga2)
                    attachBootstrapPopovers(document);
                    const selectedCustomers = new Set();
                    const btnOpenSelected = document.getElementById('btn-open-selected');
                    const checkAllCustomers = document.getElementById('check-all-customers');
                    const customerListContainer = document.getElementById('customer-list-container');

                    // VISIBILITAS tombol COLABS — tampil hanya jika ada customer tercentang & Tabel-2 tidak sedang terbuka
                    function updateColabsButtonVisibility() {
                        if (!btnOpenSelected) return;
                        const anyChecked = document.querySelector('.check-customer:checked') !== null;
                        const anyTabel2Open = document.querySelector('.yz-customer-card.is-open') !== null;
                        const shouldShow = anyChecked && (!anyTabel2Open || isColabsActive);
                        btnOpenSelected.style.display = shouldShow ? '' : 'none';
                    }

                    function setColabsButton(active) {
                        isColabsActive = active;
                        if (!btnOpenSelected) return;
                        if (active) {
                            btnOpenSelected.classList.remove('btn-outline-secondary');
                            btnOpenSelected.classList.add('btn-danger');
                            btnOpenSelected.innerHTML = `<i class="fas fa-compress-alt me-1"></i>Tutup COLABS`;
                        } else {
                            btnOpenSelected.classList.remove('btn-danger');
                            btnOpenSelected.classList.add('btn-outline-secondary');
                            btnOpenSelected.innerHTML =
                                `<i class="fas fa-expand-alt me-1"></i>COLABS: Open To SO Item`;
                        }
                        updateColabsButtonVisibility();
                    }
                    setColabsButton(false);
                    updateColabsButtonVisibility();

                    // sinkron checkbox master
                    function syncSelectAllCustomersState() {
                        if (!checkAllCustomers) return;
                        const checks = document.querySelectorAll('.check-customer');
                        if (checks.length === 0) {
                            checkAllCustomers.checked = false;
                            checkAllCustomers.indeterminate = false;
                            return;
                        }
                        const checkedCount = Array.from(checks).filter(ch => ch.checked).length;
                        checkAllCustomers.checked = checkedCount === checks.length;
                        checkAllCustomers.indeterminate = checkedCount > 0 && checkedCount < checks.length;
                    }

                    // tangkap perubahan checkbox per-customer
                    document.body.addEventListener('change', (e) => {
                        if (e.target.classList.contains('check-customer')) {
                            const kunnr = e.target.dataset.kunnr;
                            if (!kunnr) return;
                            if (e.target.checked) selectedCustomers.add(kunnr);
                            else selectedCustomers.delete(kunnr);
                            syncSelectAllCustomersState();
                            updateColabsButtonVisibility();
                        }
                    });

                    // checkbox master
                    if (checkAllCustomers) {
                        checkAllCustomers.addEventListener('change', () => {
                            const all = document.querySelectorAll('.check-customer');
                            all.forEach(ch => {
                                ch.checked = checkAllCustomers.checked;
                                const k = ch.dataset.kunnr;
                                if (checkAllCustomers.checked) selectedCustomers.add(k);
                                else selectedCustomers.delete(k);
                            });
                            syncSelectAllCustomersState();
                            updateColabsButtonVisibility();
                        });
                    }

                    // cegah klik checkbox memicu toggle kartu
                    document.body.addEventListener('click', (e) => {
                        if (e.target.closest('.check-customer')) {
                            e.stopPropagation();
                        }
                    }, true);

                    // helper: buka satu SO-row sampai item (programatik, sama seperti klik baris)
                    async function openItemsIfNeededForSORow(soRow) {
                        if (!soRow) return;
                        const wrap = soRow.closest('.yz-nest-wrap') || document;
                        const vbeln = soRow.dataset.vbeln;
                        const tgtId = soRow.dataset.tgt;
                        const itemTr = wrap.querySelector('#' + tgtId);
                        const box = itemTr?.querySelector('.yz-slot-items');
                        const t2tbl = soRow.closest('table');
                        const soTbody = soRow.closest('tbody');

                        if (!itemTr) return;
                        if (itemTr.style.display === 'none') {
                            itemTr.style.display = '';
                            soRow.querySelector('.yz-caret')?.classList.add('rot');
                            // JANGAN masuk focus-mode saat COLABS
                            if (!isColabsActive) {
                                soTbody?.classList.add('so-focus-mode');
                                soRow.classList.add('is-focused');
                            }
                            updateT2FooterVisibility(t2tbl);
                            updateGlobalTotalCardVisibility();
                        }

                        if (itemTr.dataset.loaded === '1') {
                            applySelectionsToRenderedItems(box);
                            syncCheckAllHeader(box);
                            attachBootstrapPopovers(box);
                            return;
                        }

                        box.innerHTML = `
      <div class="p-2 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
        <div class="spinner-border spinner-border-sm me-2"></div>Memuat item…
      </div>`;
                        try {
                            const items = await ensureItemsLoadedForSO(vbeln, WERKS, AUART);
                            box.innerHTML = renderLevel3_Items(items, materialMode);
                            applySelectionsToRenderedItems(box);
                            syncCheckAllHeader(box);
                            attachBootstrapPopovers(box);
                            itemTr.dataset.loaded = '1';
                        } catch (e) {
                            box.innerHTML =
                                `<div class="alert alert-danger m-3">${(e?.message||'Gagal memuat item')}</div>`;
                        }
                    }

                    function bindSoRowClicks(wrap) {
                        wrap.querySelectorAll('.js-t2row').forEach(soRow => {
                            if (soRow.dataset.bound === '1') return; // cegah double-binding
                            soRow.dataset.bound = '1';

                            const soVbeln = soRow.dataset.vbeln;
                            updateSODot(soVbeln);

                            soRow.addEventListener('click', async (ev) => {
                                // Abaikan klik pada checkbox/ikon
                                if (ev.target.closest(
                                        '.check-so, .check-all-sos, .form-check-input, .remark-icon'
                                    )) return;
                                ev.stopPropagation();

                                const vbeln = soRow.dataset.vbeln;
                                const tgtId = soRow.dataset.tgt;
                                const itemTr = wrap.querySelector('#' + tgtId);
                                const box = itemTr.querySelector('.yz-slot-items');
                                const t2tbl = soRow.closest('table');
                                const soTbody = soRow.closest('tbody');
                                const open = itemTr.style.display !== 'none';

                                soRow.querySelector('.yz-caret')?.classList.toggle('rot');

                                if (open) {
                                    itemTr.style.display = 'none';
                                    updateT2FooterVisibility(t2tbl);
                                    updateGlobalTotalCardVisibility();
                                    return;
                                }

                                if (!isColabsActive) {
                                    soTbody?.classList.add('so-focus-mode');
                                    soRow.classList.add('is-focused');
                                }
                                itemTr.style.display = '';
                                updateGlobalTotalCardVisibility();
                                updateT2FooterVisibility(t2tbl);

                                if (itemTr.dataset.loaded === '1') {
                                    applySelectionsToRenderedItems(box);
                                    syncCheckAllHeader(box);
                                    attachBootstrapPopovers(box);
                                    return;
                                }

                                box.innerHTML = `
        <div class="p-2 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
          <div class="spinner-border spinner-border-sm me-2"></div>Memuat item…
        </div>`;
                                try {
                                    const items = await ensureItemsLoadedForSO(vbeln, WERKS,
                                        AUART);
                                    box.innerHTML = renderLevel3_Items(items, materialMode);
                                    applySelectionsToRenderedItems(box);
                                    syncCheckAllHeader(box);
                                    attachBootstrapPopovers(box);
                                    itemTr.dataset.loaded = '1';
                                } catch (e) {
                                    box.innerHTML =
                                        `<div class="alert alert-danger m-3">${escapeHtml(e.message)}</div>`;
                                }
                            });
                        });
                    }

                    // helper: buka 1 customer sampai level-3 (tanpa focus-mode & tanpa menutup yang lain)
                    async function openCustomerFully(cardEl) {
                        if (!cardEl) return;
                        const kunnr = cardEl.dataset.kunnr;
                        const kid = cardEl.dataset.kid;
                        const slot = document.getElementById(kid);
                        const wrap = slot?.querySelector('.yz-nest-wrap');

                        // Pastikan kartu terbuka, tapi jangan aktifkan focus-mode saat COLABS
                        cardEl.classList.add('is-open');
                        cardEl.querySelector('.kunnr-caret')?.classList.add('rot');
                        if (slot) slot.style.display = 'block';

                        // jika SO (tabel-2) belum dimuat → muat dulu
                        if (wrap && wrap.dataset.loaded !== '1') {
                            wrap.innerHTML = `
        <div class="p-3 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
          <div class="spinner-border spinner-border-sm me-2"></div>Memuat data…
        </div>`;
                            const url = new URL("{{ route('so.api.by_customer') }}", window.location.origin);
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

                            const soRows = (js.data || []).filter(Boolean);
                            wrap.innerHTML = renderLevel2_SO(soRows, kunnr);
                            wrap.dataset.loaded = '1';

                            // PASANG HANDLER KLIK UNTUK BARIS SO (penting untuk COLABS -> tutup -> pilih sebagian)
                            bindSoRowClicks(wrap);

                            const soTable = wrap.querySelector('table');
                            const soTbody = soTable?.querySelector('tbody');
                            updateT2FooterVisibility(soTable);
                            if (soTbody) syncCheckAllSoHeader(soTbody);
                        }

                        // setelah tabel-2 ada, buka SEMUA SO & muat item (tabel-3)
                        const soRows = wrap?.querySelectorAll('.js-t2row') || [];
                        for (const soRow of soRows) await openItemsIfNeededForSORow(soRow);
                    }

                    function closeCustomerFully(cardEl) {
                        if (!cardEl) return;
                        const kid = cardEl.dataset.kid;
                        const slot = document.getElementById(kid);
                        const wrap = slot?.querySelector('.yz-nest-wrap');

                        // Tutup semua level-2 & 3
                        if (wrap) {
                            wrap.querySelectorAll('.js-t2row').forEach(soRow => {
                                const itemTr = soRow.nextElementSibling;
                                if (itemTr) itemTr.style.display = 'none';
                                soRow.querySelector('.yz-caret')?.classList.remove('rot');
                            });
                            const t2tb = wrap.querySelector('table tbody');
                            if (t2tb) t2tb.classList.remove('so-focus-mode', 'collapse-mode');
                        }

                        if (slot) slot.style.display = 'none';
                        cardEl.classList.remove('is-open', 'is-focused');
                        cardEl.querySelector('.kunnr-caret')?.classList.remove('rot');

                        // perbarui UI global
                        updateT2FooterVisibility(wrap?.querySelector('table'));
                        updateGlobalTotalCardVisibility();
                    }

                    // Klik tombol COLABS
                    if (btnOpenSelected) {
                        btnOpenSelected.addEventListener('click', async () => {
                            // Saat aksi massal: jangan focus-mode & biarkan footer global tampil
                            customerListContainer?.classList.remove('customer-focus-mode');
                            document.querySelectorAll('.yz-customer-card').forEach(r => r.classList
                                .remove('is-focused'));
                            if (globalTotalsCard) globalTotalsCard.style.display = '';

                            const chosenRows = Array
                                .from(document.querySelectorAll('.yz-customer-card'))
                                .filter(r => r.querySelector('.check-customer')?.checked);

                            if (!isColabsActive) {
                                // --- MODE BUKA ---
                                if (chosenRows.length === 0) {
                                    alert('Centang dulu customer di Tabel 1 yang ingin dibuka.');
                                    return;
                                }
                                for (const row of chosenRows) await openCustomerFully(row);
                                setColabsButton(true);
                            } else {
                                // --- MODE TUTUP ---
                                const targets = chosenRows.length ?
                                    chosenRows :
                                    Array.from(document.querySelectorAll('.yz-customer-card.is-open'));
                                for (const row of targets) closeCustomerFully(row);
                                setColabsButton(false);
                            }
                        });
                    }
                    // ====== Remark Modal setup (tetap dari skrip Anda) ======
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
                    const MAX_REMARK = 100;

                    function updateRmCounter() {
                        if (!rmInput || !rmCounter) return;
                        const n = (rmInput.value || '').length;
                        rmCounter.textContent = `${n}/${MAX_REMARK}`;
                    }
                    rmInput?.addEventListener('input', updateRmCounter);

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

                    // ====== Customer card click, load SO, load Items, dsb. (tanpa perubahan selain attachPopover) ======
                    document.querySelectorAll('.yz-customer-card').forEach(row => {
                        row.addEventListener('click', async () => {
                            const kunnr = row.dataset.kunnr;
                            const kid = row.dataset.kid;
                            const cname = row.dataset.cname;
                            const slot = document.getElementById(kid);
                            const wrap = slot.querySelector('.yz-nest-wrap');

                            const customerListContainer = row.closest('.d-grid');
                            const wasOpen = row.classList.contains('is-open');

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

                            row.classList.toggle('is-open', !wasOpen);
                            row.querySelector('.kunnr-caret')?.classList.toggle('rot', !
                                wasOpen);
                            slot.style.display = wasOpen ? 'none' : 'block';
                            updateGlobalTotalCardVisibility();
                            updateColabsButtonVisibility
                                (); // <<< supaya tombol beradaptasi saat kartu dibuka/tutup


                            // Small Qty show/hide (tetap)
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

                            // Load Level-2
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

                                // Bind SO row click
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
                                            soTbody?.querySelectorAll(
                                                '.js-t2row').forEach(
                                                r => r.classList.remove(
                                                    'so-visited'));
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
                                            updateGlobalTotalCardVisibility
                                                ();
                                            return;
                                        }

                                        soRow.classList.add('so-visited');
                                        itemTr.style.display = '';
                                        updateGlobalTotalCardVisibility();
                                        updateT2FooterVisibility(t2tbl);
                                        updateGlobalTotalCardVisibility();
                                        soRow.classList.remove(
                                            'row-highlighted');

                                        if (itemTr.dataset.loaded === '1') {
                                            applySelectionsToRenderedItems(
                                                box);
                                            syncCheckAllHeader(box);
                                            attachBootstrapPopovers(
                                                box
                                            ); // <-- pasang popover untuk item
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
                                                renderLevel3_Items(items,
                                                    materialMode);

                                            applySelectionsToRenderedItems(
                                                box);
                                            syncCheckAllHeader(box);
                                            attachBootstrapPopovers(
                                                box
                                            ); // <-- pasang popover untuk item
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

                    // ====== Checkbox handlers (tetap) ======
                    document.body.addEventListener('change', async (e) => {
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
                                else Array.from(selectedItems).forEach(id => {
                                    if (itemIdToSO.get(String(id)) === vbeln) selectedItems
                                        .delete(id);
                                });
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

                    // ====== Collapse Mode ======
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
                        updateGlobalTotalCardVisibility();
                    }
                    document.body.addEventListener('click', async (e) => {
                        const toggleBtn = e.target.closest('.js-collapse-toggle');
                        if (!toggleBtn) return;
                        e.stopPropagation();
                        const soTbody = toggleBtn.closest('table')?.querySelector('tbody');
                        if (soTbody) await applyCollapseViewSo(soTbody, !getCollapse(soTbody));
                    });

                    // ====== Export Button ======
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

                    // ====== Remark Modal events (tetap) ======
                    document.body.addEventListener('click', async (e) => {
                        const icon = e.target.closest('.remark-icon');
                        if (!icon) return;
                        const row = icon.closest('tr');
                        scrollAndFlashTemp(row, 4500);
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
                            itemsCache.delete(remarkModalState.vbeln);
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
                                `<textarea class="form-control" rows="2" maxlength="100" id="edit-remark-${id}">${currentRemark}</textarea>`;
                            actionEl.innerHTML = `
            <button type="button" class="btn btn-success btn-sm btn-save-edit" data-id="${id}">Save</button>
            <button type="button" class="btn btn-outline-secondary btn-sm btn-cancel-edit">Cancel</button>`;
                            document.getElementById(`edit-remark-${id}`)?.focus();
                            return;
                        }
                        const cancelBtn = e.target.closest('.btn-cancel-edit');
                        if (cancelBtn) {
                            e.preventDefault();
                            const addForm = rmAddBtn?.closest('.mb-2');
                            if (addForm) addForm.style.display = '';
                            await loadRemarkThread();
                            return;
                        }
                        const saveBtn = e.target.closest('.btn-save-edit');
                        if (saveBtn) {
                            e.preventDefault();
                            const id = saveBtn.dataset.id;
                            const newRemarkInput = document.getElementById(`edit-remark-${id}`);
                            const newRemark = (newRemarkInput?.value || '').trim();
                            if (!newRemark || newRemark.length > 100) {
                                alert('Catatan tidak boleh kosong dan maksimal 100 karakter.');
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
                     * NAVIGATE to SO
                     * ======================================================= */
                    window.navigateToSO = async function(vbeln, customerName = '', posnr = '', exclusive = false) {
                        const forceOpen = (posnr === '__OPEN__');
                        const POSNR6 = (!forceOpen && posnr) ? String(posnr).replace(/\D/g, '').padStart(6,
                            '0') : '';
                        if (exclusive) {
                            document.querySelectorAll('table tbody').forEach(tb => {
                                if (getCollapse && getCollapse(tb)) return;
                                tb.classList.remove('so-focus-mode');
                                tb.querySelectorAll('.js-t2row').forEach(r => {
                                    r.classList.remove('is-focused', 'so-visited');
                                    const nest = r.nextElementSibling;
                                    if (nest && nest.style.display !== 'none') {
                                        nest.style.display = 'none';
                                        r.querySelector('.yz-caret')?.classList.remove(
                                            'rot');
                                    }
                                });
                                if (typeof updateT2FooterVisibility === 'function') {
                                    const tbl = tb.closest('table');
                                    if (tbl) updateT2FooterVisibility(tbl);
                                }
                            });
                        }
                        const findItemRow = (box, v, pos6) => {
                            const rows = box?.querySelectorAll(`tr[data-vbeln='${CSS.escape(v)}']`) ||
                            [];
                            for (const tr of rows) {
                                if ((tr.dataset.posnrKey || '') === pos6) return tr;
                            }
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
                            const itemNest = soRow.nextElementSibling;
                            const itemsBox = itemNest?.querySelector('.yz-slot-items');
                            const isOpen = itemNest && itemNest.style.display !== 'none';
                            if (openItems && !isOpen) soRow.click();
                            if (openItems) {
                                const okT3 = await waitFor(() => itemNest && itemNest.dataset.loaded ===
                                    '1');
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
                        if (customerName) {
                            const card = [...document.querySelectorAll('.yz-customer-card')].find(c => (c
                                .dataset.cname || '').trim() === customerName.trim());
                            const {
                                soRow,
                                itemsBox
                            } = await openInsideCard(card, {
                                openItems: (forceOpen || !!POSNR6)
                            });
                            if (soRow) {
                                scrollAndFlash(soRow);
                                soRow.classList.remove('row-highlighted');
                                if (exclusive) {
                                    const tbody = soRow.closest('tbody');
                                    if (tbody && !(getCollapse && getCollapse(tbody))) {
                                        tbody.classList.add('so-focus-mode');
                                        tbody.querySelectorAll('.js-t2row').forEach(r => r.classList.toggle(
                                            'is-focused', r === soRow));
                                        if (typeof updateT2FooterVisibility === 'function') {
                                            const tbl = tbody.closest('table');
                                            if (tbl) updateT2FooterVisibility(tbl);
                                        }
                                    }
                                }
                                if (POSNR6 && itemsBox) {
                                    const tr = findItemRow(itemsBox, vbeln, POSNR6);
                                    if (tr) scrollAndFlashTemp(tr, 4500);
                                }
                                return;
                            }
                        }
                        const cards = document.querySelectorAll('.yz-customer-card');
                        for (const card of cards) {
                            const {
                                soRow,
                                itemsBox
                            } = await openInsideCard(card, {
                                openItems: (forceOpen || !!POSNR6)
                            });
                            if (!soRow) continue;
                            scrollAndFlash(soRow);
                            if (exclusive) {
                                const tbody = soRow.closest('tbody');
                                if (tbody && !(getCollapse && getCollapse(tbody))) {
                                    tbody.classList.add('so-focus-mode');
                                    tbody.querySelectorAll('.js-t2row').forEach(r => r.classList.toggle(
                                        'is-focused', r === soRow));
                                    if (typeof updateT2FooterVisibility === 'function') {
                                        const tbl = tbody.closest('table');
                                        if (tbl) updateT2FooterVisibility(tbl);
                                    }
                                }
                            }
                            if (POSNR6 && itemsBox) {
                                const tr = findItemRow(itemsBox, vbeln, POSNR6);
                                if (tr) scrollAndFlashTemp(tr, 4500);
                            }
                            return;
                        }
                        alert(`SO ${vbeln} tidak ditemukan pada daftar ini.`);
                    };

                    document.addEventListener('click', async (e) => {
                        const link = e.target.closest('.js-open-so');
                        if (!link) return;
                        e.preventDefault();
                        const forceOpen = link.dataset.openItems === '1';
                        const exclusive = link.dataset.exclusive === '1';
                        await window.navigateToSO(
                            (link.dataset.vbeln || '').trim(),
                            (link.dataset.cname || '').trim(),
                            forceOpen ? '__OPEN__' : (link.dataset.posnr || '').trim(),
                            exclusive
                        );
                    });

                    /* =========================================================
                     * PENCARIAN ITEM (Material FG / Desc FG)
                     * ======================================================= */

                    const itemSearchInput = document.getElementById('so-item-search-input');
                    const itemSearchBtn = document.getElementById('so-item-search-btn');

                    let isItemSearching = false;

                    // RESET hasil pencarian sebelumnya:
                    // - hilangkan highlight
                    // - tampilkan lagi semua item yang sempat disembunyikan
                    function resetItemSearchFilter() {
                        // hapus highlight
                        document
                            .querySelectorAll('tr.yz-item-hit')
                            .forEach(tr => tr.classList.remove('yz-item-hit'));

                        // tampilkan semua item Tabel-3 yang pernah disembunyikan oleh pencarian
                        document
                            .querySelectorAll('tr.yz-item-hidden-by-search')
                            .forEach(tr => {
                                tr.classList.remove('yz-item-hidden-by-search');
                                tr.style.display = '';
                            });
                    }

                    async function runItemSearch() {
                        if (!itemSearchInput || isItemSearching) return;

                        const raw = (itemSearchInput.value || '').trim();

                        // setiap pencarian baru → bersihkan dulu hasil filter sebelumnya
                        resetItemSearchFilter();

                        // Kalau input kosong → cukup reset, jangan lakukan pencarian
                        if (!raw) return;

                        const keyword = raw.toUpperCase();

                        isItemSearching = true;
                        if (itemSearchBtn) {
                            itemSearchBtn.disabled = true;
                            itemSearchBtn.innerHTML =
                                '<span class="spinner-border spinner-border-sm me-1"></span>Mencari...';
                        }
                        itemSearchInput.disabled = true;

                        try {
                            const customerCards = Array.from(
                                document.querySelectorAll('.yz-customer-card')
                            );

                            const results = []; // {kunnr, cname, vbeln, posnrKey}
                            let firstMatchedKunnr = null;

                            // LOOP CUSTOMER (Tabel 1)
                            for (const card of customerCards) {
                                const kunnr = card.dataset.kunnr;
                                const cname = (card.dataset.cname || '').trim();

                                // Kalau sudah ketemu customer pertama yg match,
                                // customer lain TIDAK di-cek lagi
                                if (firstMatchedKunnr && kunnr !== firstMatchedKunnr) {
                                    continue;
                                }

                                // Ambil daftar SO untuk customer ini
                                let soList = [];
                                const kid = card.dataset.kid;
                                const slot = document.getElementById(kid);
                                const wrap = slot?.querySelector('.yz-nest-wrap');

                                if (wrap && wrap.dataset.loaded === '1') {
                                    // SO sudah pernah dimuat → ambil dari DOM
                                    wrap.querySelectorAll('.js-t2row').forEach(tr => {
                                        soList.push({
                                            VBELN: tr.dataset.vbeln
                                        });
                                    });
                                } else {
                                    // Belum dimuat → panggil API by_customer (background, tidak klik UI)
                                    const url = new URL(apiSoByCustomer, window.location.origin);
                                    url.searchParams.set('kunnr', kunnr);
                                    url.searchParams.set('werks', WERKS);
                                    url.searchParams.set('auart', AUART);

                                    const resp = await fetch(url, {
                                        headers: {
                                            'Accept': 'application/json'
                                        }
                                    });
                                    const js = await resp.json();
                                    if (!js.ok) continue;

                                    soList = uniqBy(js.data || [], r => `${r.VBELN}`);
                                }

                                let anyMatchForThisCustomer = false;

                                // LOOP SO (Tabel 2) UNTUK CUSTOMER INI SAJA
                                for (const so of soList) {
                                    const vbeln = so.VBELN;
                                    const items = await ensureItemsLoadedForSO(vbeln, WERKS, AUART);

                                    // LOOP ITEM (Tabel 3)
                                    for (const it of items) {
                                        const matnr = String(it.MATNR || '')
                                            .trim()
                                            .toUpperCase();
                                        const maktx = String(it.MAKTX || '')
                                            .trim()
                                            .toUpperCase();

                                        // Desc FG: CONTAINS
                                        const matchDesc = maktx.includes(keyword);
                                        // Material FG: EXACT
                                        const matchMatnr = matnr === keyword;

                                        if (matchDesc || matchMatnr) {
                                            anyMatchForThisCustomer = true;
                                            results.push({
                                                kunnr,
                                                cname,
                                                vbeln,
                                                posnrKey: it.POSNR_KEY
                                            });
                                        }
                                    }
                                }

                                if (anyMatchForThisCustomer && !firstMatchedKunnr) {
                                    // Simpan KUNNR customer pertama yang match,
                                    // lalu berhenti cek customer lain
                                    firstMatchedKunnr = kunnr;
                                    break;
                                }
                            }

                            if (!results.length) {
                                alert('Tidak ditemukan item dengan kata kunci tersebut.');
                                return;
                            }

                            // Map: VBELN -> Set POSNR_KEY yang match
                            const matchesBySO = new Map();
                            const openedItemKeys = new Set();

                            // Tampilkan hasil:
                            // - HANYA customer pertama yang match (firstMatchedKunnr)
                            // - Semua SO yg berisi item match akan dibuka sampai Tabel 3
                            for (const r of results) {
                                if (r.kunnr !== firstMatchedKunnr) continue;

                                if (!matchesBySO.has(r.vbeln)) {
                                    matchesBySO.set(r.vbeln, new Set());
                                }
                                matchesBySO.get(r.vbeln).add(String(r.posnrKey));

                                const key = `${r.vbeln}|${r.posnrKey}`;
                                if (openedItemKeys.has(key)) continue;
                                openedItemKeys.add(key);

                                // Buka customer + SO + item menggunakan helper yg sudah ada
                                // (tidak exclusive supaya customer lain tetap tampil normal)
                                await window.navigateToSO(
                                    r.vbeln,
                                    r.cname,
                                    r.posnrKey,
                                    false
                                );
                            }

                            // Setelah semua SO yang mengandung item match sudah dibuka,
                            // SEMBUNYIKAN item yang tidak match (di semua Tabel-3 yang sudah ada di DOM)
                            const allItemRows = document.querySelectorAll(
                                "tr[data-item-id][data-vbeln][data-posnr-key]"
                            );

                            allItemRows.forEach(tr => {
                                const vbeln = tr.dataset.vbeln || '';
                                const posKey = tr.dataset.posnrKey || '';
                                const set = matchesBySO.get(vbeln);
                                const isMatch = !!(set && set.has(posKey));

                                if (isMatch) {
                                    tr.classList.add('yz-item-hit'); // highlight hasil
                                    tr.classList.remove('yz-item-hidden-by-search');
                                    tr.style.display = '';
                                } else {
                                    tr.classList.remove('yz-item-hit');
                                    tr.classList.add('yz-item-hidden-by-search');
                                    tr.style.display = 'none'; // inilah yang menyembunyikan non-match
                                }
                            });
                        } catch (err) {
                            console.error('Item search error:', err);
                            alert('Terjadi kesalahan saat melakukan pencarian item.');
                        } finally {
                            isItemSearching = false;
                            if (itemSearchBtn) {
                                itemSearchBtn.disabled = false;
                                itemSearchBtn.innerHTML =
                                    '<i class="fas fa-search me-1"></i> Cari';
                            }
                            itemSearchInput.disabled = false;
                        }
                    }
                    itemSearchInput?.addEventListener('input', () => {
                        if (isItemSearching) return;
                        const raw = (itemSearchInput.value || '').trim();
                        if (!raw) {
                            resetItemSearchFilter();
                        }
                    });

                    // Klik tombol "Cari"
                    itemSearchBtn?.addEventListener('click', (e) => {
                        e.preventDefault();
                        runItemSearch();
                    });

                    // Tekan Enter di input
                    itemSearchInput?.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            runItemSearch();
                        }
                    });


                    /* =========================================================
                     * SMALL QTY (Chart + Details + Export) — tetap
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
              <th class="text-end">Qty SO</th>
              <th class="text-end">Shipped</th>
              <th class="text-end">Outs. SO (≤5)</th>
              <th class="text-end">WHFG</th>
              <th class="text-end">Stock Packing</th>
            </tr>`;
                            const body = rows.map((it, idx) => {
                                const so = String(it.SO || '').trim();
                                const pos = String(it.POSNR || '').replace(/\D/g, '').padStart(6, '0');
                                return `
              <tr class="js-sq-row" style="cursor:pointer;" data-vbeln="${so}" data-posnr-key="${pos}" data-cname="${customerName}">
                <td class="text-center">${idx + 1}</td>
                <td class="text-center fw-bold">
                  <a href="#" class="js-open-so text-decoration-none"
                     data-vbeln="${so}" data-cname="${customerName}"
                     data-posnr="${pos}" data-open-items="1" data-exclusive="1">${so}</a>
                </td>
                <td class="text-center">${it.POSNR ?? ''}</td>
                <td>${escapeHtml(it.MAKTX ?? '')}</td>
                <td class="text-end">${formatNumberGlobal(it.KWMENG)}</td>
                <td class="text-end">${formatNumberGlobal(it.QTY_GI)}</td>
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
                        dataToRender.forEach(item => {
                            const name = (item.NAME1 || '').trim();
                            if (!name) return;
                            customerMap.set(name, (customerMap.get(name) || 0) + parseInt(item.so_count,
                                10));
                        });
                        const sorted = [...customerMap.entries()].sort((a, b) => b[1] - a[1]);
                        const labels = sorted.map(x => x[0]);
                        const soCounts = sorted.map(x => x[1]);
                        const totalSoCount = soCounts.reduce((s, c) => s + c, 0);
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
                        if (chartCanvas) chartCanvas.closest('.chart-container').style.height = dynamicHeight +
                            'px';
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
                                            callback: (v) => {
                                                if (Math.floor(v) === v) return v;
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
                    window.showSmallQtyDetails = showSmallQtyDetails;
                    window.renderSmallQtyChart = renderSmallQtyChart;

                    if (document.getElementById('chartSmallQtyByCustomer')) {
                        if (initialSmallQtyDataRaw && initialSmallQtyDataRaw.length > 0) {
                            renderSmallQtyChart(initialSmallQtyDataRaw, WERKS);
                        } else {
                            const sec = document.getElementById('small-qty-section');
                            if (sec) sec.style.display = 'none';
                            if (smallQtyDetailsContainer) smallQtyDetailsContainer.style.display = 'none';
                        }
                    }
                    if (!window.__sqRowDelegationBound) {
                        document.addEventListener('click', (e) => {
                            const tr = e.target.closest('#smallQtyDetailsTable tr.js-sq-row');
                            if (!tr) return;
                            if (e.target.closest(
                                    'a, button, .form-check-input, .form-select, .form-control')) return;
                            e.preventDefault();
                            const vbeln = (tr.dataset.vbeln || '').trim();
                            const cname = (tr.dataset.cname || '').trim();
                            const posnr = (tr.dataset.posnrKey || '').trim();
                            if (window.navigateToSO && vbeln) window.navigateToSO(vbeln, cname, posnr,
                                true);
                        });
                        window.__sqRowDelegationBound = true;
                    }
                    document.getElementById('exportSmallQtyPdf')?.addEventListener('click', (e) => {
                        e.preventDefault();
                        document.getElementById('smallQtyExportForm')?.submit();
                    });
                    document.getElementById('closeDetailsTable')?.addEventListener('click', () => {
                        document.getElementById('smallQtyDetailsContainer').style.display = 'none';
                        if (smallQtyChartContainer) smallQtyChartContainer.style.display = 'block';
                        if (initialSmallQtyDataRaw && initialSmallQtyDataRaw.length > 0) {
                            renderSmallQtyChart(initialSmallQtyDataRaw, WERKS);
                        }
                    });

                    /* =========================================================
                     * AUTO-EXPAND dari root highlight (tanpa perubahan)
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
                                if (itemTr) {
                                    scrollAndFlash(itemTr);
                                    scrollAndFlashTemp(itemTr, 4500);
                                }
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

                    /* =========================================================
                     * KLIK MACHI → MODAL "Machining Lines"
                     * ======================================================= */
                    // cache fetch machining per kombinasi
                    const machiningCache = new Map();
                    const keyMachining = (werks, auart, vbeln, posnrKey) =>
                        `${werks}|${auart}|${vbeln}|${posnrKey}`;

                    // referensi modal
                    const machiningModalEl = document.getElementById('machiningModal');
                    let machiningModal = null;
                    let machiSOEl = null,
                        machiPOSEl = null,
                        machiBodyEl = null,
                        machiDescEl = null;

                    function ensureMachiningModal() {
                        if (!machiningModalEl) return false;
                        if (machiningModalEl && machiningModalEl.parentElement !== document.body) {
                            document.body.appendChild(machiningModalEl);
                        }
                        machiningModal = bootstrap.Modal.getInstance(machiningModalEl) || new bootstrap.Modal(
                            machiningModalEl);
                        machiSOEl = document.getElementById('machi-so');
                        machiPOSEl = document.getElementById('machi-pos');
                        machiBodyEl = document.getElementById('machiningModalBody');
                        machiDescEl = document.getElementById('machi-desc');
                        return true;
                    }

                    function renderMachiningModal(rows) {
                        if (!rows || rows.length === 0) {
                            return `<div class="text-center text-muted p-5 bg-light rounded-3">
      <i class="fas fa-tools fa-3x mb-3 text-secondary"></i>
      <h5 class="fw-bold">Tidak Ada Langkah Machining</h5>
      <p class="mb-0">Data langkah-langkah Machining Lines tidak ditemukan untuk item ini.</p>
    </div>`;
                        }

                        let tPS = 0,
                            tWE = 0;
                        rows.forEach(r => {
                            tPS += Number(r.PSMNG || 0);
                            tWE += Number(r.WEMNG || 0);
                        });

                        const bodyHtml = rows.map(r => {
                            const prsn = Number(r.PRSN || 0);
                            const progressPct = Math.min(100, Math.max(0, prsn));
                            const progressClass = progressPct === 100 ? 'bg-success' : (progressPct > 0 ?
                                'bg-info' : 'bg-secondary');
                            const desc = escapeHtml(r.MAKTX ?? '—');
                            const matnr = escapeHtml(r.MATNR ?? '—');

                            return `
      <div class="machi-line-card shadow-sm mb-3">
        <div class="machi-header d-flex justify-content-between align-items-center mb-2">
          <div class="machi-title-group"><div class="machi-matnr-display fw-bold text-primary">${matnr}</div></div>
        </div>
        <div class="machi-description mb-3">${desc}</div>

        <div class="machi-metrics row g-2 mb-3">
          <div class="col-3"><div class="metric-label small text-muted">Order</div>
            <div class="metric-value fw-bold text-end">${formatNumberGlobal(r.PSMNG, 0)}</div></div>
          <div class="col-3"><div class="metric-label small text-muted">GR</div>
            <div class="metric-value fw-bold text-end">${formatNumberGlobal(r.WEMNG, 0)}</div></div>
          <div class="col-3 border-start"><div class="metric-label small text-muted">Progress</div>
            <div class="metric-value fw-bold text-end ${progressPct === 100 ? 'text-success' : 'text-primary'}">${progressPct}%</div></div>
        </div>

        <div class="machi-progress-wrapper">
          <div class="small text-muted mb-1 d-flex justify-content-between">
            <span>Progress</span><span>${progressPct}%</span>
          </div>
          <div class="progress machi-progress" style="height: 6px;">
            <div class="progress-bar ${progressClass}" role="progressbar" style="width: ${progressPct}%;" aria-valuenow="${progressPct}" aria-valuemin="0" aria-valuemax="100"></div>
          </div>
        </div>
      </div>`;
                        }).join('');

                        return `
    <div class="machi-container">
      ${bodyHtml}
      <div class="machi-total-card p-3 mt-4 border-top">
        <div class="d-flex justify-content-between align-items-center fw-bold">
          <div class="fs-5 text-dark">TOTAL KUANTITAS</div>
          <div class="d-flex gap-4">
            <div class="text-end"><div class="small text-muted">Total Order</div><div class="text-primary">${formatNumberGlobal(tPS, 0)}</div></div>
            <div class="text-end"><div class="small text-muted">Total GR</div><div class="text-success">${formatNumberGlobal(tWE, 0)}</div></div>
          </div>
        </div>
      </div>
    </div>`;
                    }

                    async function openMachiningModal(fromRowEl) {
                        if (!ensureMachiningModal() || !fromRowEl) return;

                        const werks = fromRowEl.dataset.werks || '';
                        const auart = fromRowEl.dataset.auart || '';
                        const vbeln = fromRowEl.dataset.vbeln || '';
                        const posnr = fromRowEl.dataset.posnr || '';
                        const posnrKey = fromRowEl.dataset.posnrKey || '';
                        const descFG = fromRowEl.dataset.maktx || ''; // ⬅️ ambil MAKTX

                        const cacheKey = `${werks}|${auart}|${vbeln}|${posnrKey}`;

                        if (machiSOEl) machiSOEl.textContent = vbeln;
                        if (machiPOSEl) machiPOSEl.textContent = posnr;
                        if (machiDescEl) machiDescEl.textContent = descFG; // ⬅️ set ke header

                        if (machiBodyEl) {
                            machiBodyEl.innerHTML = `
      <div class="p-3 text-muted d-flex align-items-center">
        <div class="spinner-border spinner-border-sm me-2"></div> Memuat data...
      </div>`;
                        }

                        machiningModal.show();
                        try {
                            let rows;
                            if (machiningCache.has(cacheKey)) {
                                rows = machiningCache.get(cacheKey);
                            } else {
                                const u = new URL(apiMachiningLines, window.location.origin);
                                u.searchParams.set('werks', werks);
                                u.searchParams.set('auart', auart);
                                u.searchParams.set('vbeln', vbeln);
                                u.searchParams.set('posnr', posnrKey);
                                const r = await fetch(u, {
                                    headers: {
                                        'Accept': 'application/json'
                                    }
                                });
                                const js = await r.json();
                                rows = (js && js.ok) ? (js.data || []) : [];
                                machiningCache.set(cacheKey, rows);
                            }
                            if (machiBodyEl) machiBodyEl.innerHTML = renderMachiningModal(rows);
                        } catch (err) {
                            if (machiBodyEl) machiBodyEl.innerHTML =
                                `<div class="text-danger">Gagal memuat data.</div>`;
                            console.error(err);
                        }
                    }

                    const pembahananModalEl = document.getElementById('pembahananModal');
                    let pembahananModal = null;
                    let pembSOEl = null,
                        pembPOSEl = null,
                        pembBodyEl = null,
                        pembDescEl = null;

                    function ensurePembahananModal() {
                        if (!pembahananModalEl) return false;
                        if (pembahananModalEl && pembahananModalEl.parentElement !== document.body) {
                            document.body.appendChild(pembahananModalEl);
                        }
                        pembahananModal = bootstrap.Modal.getInstance(pembahananModalEl) || new bootstrap.Modal(
                            pembahananModalEl);
                        pembSOEl = document.getElementById('pemb-so');
                        pembPOSEl = document.getElementById('pemb-pos');
                        pembBodyEl = document.getElementById('pembahananModalBody');
                        pembDescEl = document.getElementById('pemb-desc');
                        return true;
                    }

                    function renderPembahananModal(rows) {
                        if (!rows || rows.length === 0) {
                            return `
      <div class="text-center text-muted p-5 bg-light rounded-3">
        <i class="fas fa-tools fa-3x mb-3 text-secondary"></i>
        <h5 class="fw-bold">Tidak Ada Langkah Pembahanan</h5>
        <p class="mb-0">Data langkah-langkah Pembahanan tidak ditemukan untuk item ini.</p>
      </div>
    `;
                        }

                        // total untuk ringkasan
                        let tRequest = 0,
                            tTP = 0;
                        rows.forEach(r => {
                            tRequest += Number(r.TOTREQ || 0);
                            tTP += Number(r.TOTTP || 0);
                        });

                        const bodyHtml = rows.map(r => {
                            const rawPct = Number(r.PRSN2 ?? 0); // progress murni (bisa >100)
                            const displayPct = `${formatNumberGlobal(rawPct, 0)}%`;
                            const barPct = Math.max(0, Math.min(100, rawPct)); // lebar bar dibatasi 0..100
                            const progressClass =
                                rawPct <= 0 ? 'bg-secondary' : rawPct >= 100 ? 'bg-success' : 'bg-warning';

                            const desc = escapeHtml(r.MAKTX ?? '—');
                            const matnr = escapeHtml(r.MATNR ?? '—');

                            return `
      <div class="machi-line-card shadow-sm mb-3">
        <div class="machi-header d-flex justify-content-between align-items-center mb-2">
          <div class="machi-title-group">
            <div class="machi-matnr-display fw-bold text-primary">${matnr}</div>
          </div>
        </div>

        <div class="machi-description mb-3">${desc}</div>

        <div class="machi-metrics row g-2 mb-3">
          <div class="col-3">
            <div class="metric-label small text-muted">Request</div>
            <div class="metric-value fw-bold text-end">${formatNumberGlobal(r.TOTREQ, 0)}</div>
          </div>
          <div class="col-3">
            <div class="metric-label small text-muted">TP</div>
            <div class="metric-value fw-bold text-end">${formatNumberGlobal(r.TOTTP, 0)}</div>
          </div>
          <div class="col-3 border-start">
            <div class="metric-label small text-muted">Progress</div>
            <div class="metric-value fw-bold text-end ${rawPct >= 100 ? 'text-success' : 'text-primary'}">${displayPct}</div>
          </div>
        </div>

        <div class="machi-progress-wrapper">
          <div class="small text-muted mb-1 d-flex justify-content-between">
            <span>Progress</span><span>${displayPct}</span>
          </div>
          <div class="progress machi-progress" style="height: 6px;">
            <div class="progress-bar ${progressClass}" role="progressbar"
                 style="width: ${barPct}%;" aria-valuenow="${barPct}"
                 aria-valuemin="0" aria-valuemax="100"></div>
          </div>
        </div>
      </div>
    `;
                        }).join('');

                        return `
    <div class="machi-container">
      ${bodyHtml}
      <div class="machi-total-card p-3 mt-4 border-top">
        <div class="d-flex justify-content-between align-items-center fw-bold">
          <div class="fs-5 text-dark">TOTAL KUANTITAS</div>
          <div class="d-flex gap-4">
            <div class="text-end">
              <div class="small text-muted">Total Request</div>
              <div class="text-primary">${formatNumberGlobal(tRequest, 0)}</div>
            </div>
            <div class="text-end">
              <div class="small text-muted">Total TP</div>
              <div class="text-success">${formatNumberGlobal(tTP, 0)}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `;
                    }


                    async function openPembahananModal(fromRowEl) {
                        if (!ensurePembahananModal() || !fromRowEl) return;

                        const werks = fromRowEl.dataset.werks || '';
                        const auart = fromRowEl.dataset.auart || '';
                        const vbeln = fromRowEl.dataset.vbeln || '';
                        const posnr = fromRowEl.dataset.posnr || '';
                        const posnrKey = fromRowEl.dataset.posnrKey || '';
                        const descFG = fromRowEl.dataset.maktx || '';

                        if (pembSOEl) pembSOEl.textContent = vbeln;
                        if (pembPOSEl) pembPOSEl.textContent = posnr;
                        if (pembDescEl) pembDescEl.textContent = descFG;
                        if (pembBodyEl) {
                            pembBodyEl.innerHTML = `<div class="p-3 text-muted d-flex align-items-center">
      <div class="spinner-border spinner-border-sm me-2"></div> Memuat data...</div>`;
                        }

                        pembahananModal.show();

                        try {
                            const u = new URL(apiPembahananLines, window.location.origin);
                            u.searchParams.set('werks', werks);
                            u.searchParams.set('auart', auart);
                            u.searchParams.set('vbeln', vbeln);
                            u.searchParams.set('posnr', posnrKey);
                            const r = await fetch(u, {
                                headers: {
                                    'Accept': 'application/json'
                                }
                            });
                            const js = await r.json();
                            const rows = (js && js.ok) ? (js.data || []) : [];
                            if (pembBodyEl) pembBodyEl.innerHTML = renderPembahananModal(rows);
                        } catch (err) {
                            if (pembBodyEl) pembBodyEl.innerHTML =
                                `<div class="text-danger">Gagal memuat data.</div>`;
                            console.error(err);
                        }
                    }
                    document.addEventListener('click', (e) => {
                        const span = e.target.closest('.yz-machi-pct');
                        if (!span) return;

                        const st = (span.dataset.stage || '').toLowerCase();
                        if (st !== 'machining' && st !== 'pembahanan') return;

                        e.preventDefault();
                        e.stopPropagation();

                        const tr = span.closest('tr'); // baris item Tabel-3 (punya dataset lengkap)
                        if (st === 'machining') openMachiningModal(tr);
                        else if (st === 'pembahanan') openPembahananModal(tr);
                    });

                }); // DOMContentLoaded
            })(); // IIFE
        </script>
    @endpush
