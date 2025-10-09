@extends('layouts.app')

@section('title', 'Outstanding SO')

@section('content')

    @php
        // Ambil nilai dari controller / query
        $selectedWerks = $selected['werks'] ?? null;
        $selectedAuart = trim((string) ($selected['auart'] ?? ''));
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
                return 'Rp ' . number_format(0, 2, ',', '.');
            }

            $parts = [];

            if ($sumUsd > 0) {
                $parts[] = '$' . number_format($sumUsd, 2, '.', ',');
            }
            if ($sumIdr > 0) {
                $parts[] = 'Rp ' . number_format($sumIdr, 2, ',', '.');
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

    {{-- TABEL LEVEL-1 --}}
    @if (isset($rows) && $rows->count())
        <div class="card yz-card shadow-sm">
            <div class="card-body p-0 p-md-2">
                <div class="p-3 mx-md-3 mt-md-3 yz-main-title-wrapper">
                    <h5 class="yz-table-title mb-0"><i class="fas fa-users me-2"></i>Overview Customer</h5>
                </div>

                <div class="table-responsive yz-table px-md-3">
                    <table class="table table-hover mb-0 align-middle yz-grid">
                        <thead class="yz-header-customer">
                            <tr>
                                <th style="width:50px;"></th>
                                <th class="text-start" style="min-width:250px;">Customer</th>
                                <th class="text-center" style="min-width:100px;">Total SO</th>
                                <th class="text-center" style="min-width:120px;">Overdue SO</th>
                                <th class="text-center" style="min-width:150px;">Outs. Value</th>
                                <th class="text-center" style="min-width:160px;">Overdue Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $r)
                                @php
                                    $kid = 'krow_' . $r->KUNNR . '_' . $loop->index;

                                    // Nilai yang diharapkan dari controller (sudah dipecah IDR/USD):
                                    $outsValueUSD = (float) ($r->TOTAL_ALL_VALUE_USD ?? 0);
                                    $outsValueIDR = (float) ($r->TOTAL_ALL_VALUE_IDR ?? 0);
                                    $overdueValueUSD = (float) ($r->TOTAL_OVERDUE_VALUE_USD ?? 0);
                                    $overdueValueIDR = (float) ($r->TOTAL_OVERDUE_VALUE_IDR ?? 0);

                                    // Format tampilan untuk kolom Outs. Value (USD | IDR)
                                    $displayOutsValue = '';
                                    if ($outsValueUSD > 0) {
                                        $displayOutsValue .= '$' . number_format($outsValueUSD, 2, '.', ',');
                                    }
                                    if ($outsValueUSD > 0 && $outsValueIDR > 0) {
                                        $displayOutsValue .= ' | ';
                                    }
                                    if ($outsValueIDR > 0) {
                                        $displayOutsValue .= 'Rp ' . number_format($outsValueIDR, 2, ',', '.');
                                    }
                                    if (empty($displayOutsValue)) {
                                        $displayOutsValue = 'Rp ' . number_format(0, 2, ',', '.');
                                    }

                                    // Format tampilan untuk kolom Overdue Value (USD | IDR)
                                    $displayOverdueValue = '';
                                    if ($overdueValueUSD > 0) {
                                        $displayOverdueValue .= '$' . number_format($overdueValueUSD, 2, '.', ',');
                                    }
                                    if ($overdueValueUSD > 0 && $overdueValueIDR > 0) {
                                        $displayOverdueValue .= ' | ';
                                    }
                                    if ($overdueValueIDR > 0) {
                                        $displayOverdueValue .= 'Rp ' . number_format($overdueValueIDR, 2, ',', '.');
                                    }
                                    if (empty($displayOverdueValue)) {
                                        $displayOverdueValue = 'Rp ' . number_format(0, 2, ',', '.');
                                    }
                                @endphp
                                <tr class="yz-kunnr-row" data-kunnr="{{ $r->KUNNR }}" data-kid="{{ $kid }}"
                                    data-cname="{{ $r->NAME1 }}" title="Klik untuk melihat detail SO">
                                    <td class="sticky-col-mobile-disabled">
                                        <span class="kunnr-caret"><i class="fas fa-chevron-right"></i></span>
                                    </td>
                                    <td class="sticky-col-mobile-disabled text-start">
                                        <span class="fw-bold">{{ $r->NAME1 }}</span>
                                    </td>
                                    <td class="text-center">{{ $r->SO_TOTAL_COUNT ?? 0 }}</td>
                                    <td class="text-center">{{ $r->SO_LATE_COUNT }}</td>
                                    <td class="text-center">
                                        {{ $displayOutsValue }}
                                    </td>
                                    <td class="text-center">
                                        {{ $displayOverdueValue }}
                                    </td>
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
                                {{-- Total SO --}}
                                <th class="text-center">{{ number_format($totalSOTotal, 0, ',', '.') }}</th>
                                {{-- Total Overdue SO --}}
                                <th class="text-center">{{ number_format($totalOverdueSOTotal, 0, ',', '.') }}</th>
                                {{-- Total Outs. Value --}}
                                <th class="text-center">{{ $formatTotals($pageTotalsAll ?? []) }}</th>
                                {{-- Total Overdue Value --}}
                                <th class="text-center">{{ $formatTotals($pageTotalsOverdue ?? []) }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    @else
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Silakan pilih <strong>Plant</strong> dan <strong>Type</strong> dari sidebar untuk menampilkan Laporan SO.
        </div>
    @endif

    {{-- --}}
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

    {{-- --}}
    <div class="modal fade" id="remarkModal" tabindex="-1" aria-labelledby="remarkModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="remarkModalLabel">Tambah/Edit Catatan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form>
                        <div class="mb-3">
                            <label for="remark-text" class="col-form-label">Catatan untuk Item:</label>
                            <textarea class="form-control" id="remark-text" rows="4"></textarea>
                        </div>
                    </form>
                    <div id="remark-feedback" class="small mt-2"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    <button type="button" class="btn btn-primary" id="save-remark-btn">Simpan Catatan</button>
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

        .yz-footer-customer th {
            background: #f4faf7;
            border-top: 2px solid #cfe9dd
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

        tbody.customer-focus-mode~tfoot.yz-footer-customer {
            display: none !important
        }

        .yz-row-highlight-negative>td,
        .yz-row-highlight-negative td {
            background-color: #ffe5e5 !important
        }

        .table-hover tbody tr.yz-row-highlight-negative:hover>td,
        .table-hover tbody tr.yz-row-highlight-negative:hover td {
            background-color: #ffd6d6 !important
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
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
    <script src="{{ asset('vendor/chartjs/chartjs-adapter-date-fns.bundle.min.js') }}"></script>
    <script>
        /* ====== Filter helper ====== */
        function applySoFilter(params) {
            const f = document.createElement('form');
            f.method = 'POST';
            f.action = "{{ route('so.redirector') }}";
            const t = document.createElement('input');
            t.type = 'hidden';
            t.name = '_token';
            t.value = "{{ csrf_token() }}";
            const p = document.createElement('input');
            p.type = 'hidden';
            p.name = 'payload';
            p.value = JSON.stringify(params);
            f.appendChild(t);
            f.appendChild(p);
            document.body.appendChild(f);
            f.submit();
        }

        document.addEventListener('DOMContentLoaded', () => {
            /* ---------- Constants & state ---------- */
            const apiSoByCustomer = "{{ route('so.api.by_customer') }}";
            const apiItemsBySo = "{{ route('so.api.by_items') }}";
            const exportUrl = "{{ route('so.export') }}";
            const csrfToken = "{{ csrf_token() }}";
            const initialSmallQtyDataRaw = {!! json_encode($smallQtyByCustomer ?? collect()) !!};


            const __root = document.getElementById('so-root');
            const WERKS = (__root?.dataset.werks || '').trim();
            const AUART = (__root?.dataset.auart || '').trim();
            const VBELN_HL = (__root?.dataset.hvbeln || '').trim();
            const KUNNR_HL = (__root?.dataset.hkunnr || '').trim();
            const POSNR_HL = (__root?.dataset.hposnr || '').trim();
            const AUTO = (__root?.dataset.auto || '0') === '1';

            const exportDropdownContainer = document.getElementById('export-dropdown-container');
            const selectedCountSpan = document.getElementById('selected-count');

            const remarkModalEl = document.getElementById('remarkModal');
            let remarkModal = bootstrap.Modal.getInstance(remarkModalEl);
            if (!remarkModal) remarkModal = new bootstrap.Modal(remarkModalEl);
            const remarkTextarea = document.getElementById('remark-text');
            const saveRemarkBtn = document.getElementById('save-remark-btn');
            const remarkFeedback = document.getElementById('remark-feedback');

            const smallQtyChartContainer = document.getElementById('chartSmallQtyByCustomer')?.closest(
                '.chart-container');
            const smallQtyDetailsContainer = document.getElementById('smallQtyDetailsContainer');
            const chartCanvas = document.getElementById('chartSmallQtyByCustomer');
            const smallQtySection = document.getElementById(
                'small-qty-section');


            const selectedItems = new Set();
            const itemsCache = new Map(); // ITEM CACHE
            const itemIdToSO = new Map();

            // State untuk Collapse Mode di Tabel 2
            let COLLAPSE_MODE = false;


            /* ---------- Utils ---------- */
            if (!window.CSS) window.CSS = {};
            if (typeof window.CSS.escape !== 'function') window.CSS.escape = s => String(s).replace(/([^\w-])/g,
                '\\$1');

            function updateExportButton() {
                const n = selectedItems.size;
                selectedCountSpan.textContent = n;
                exportDropdownContainer.style.display = n > 0 ? 'block' : 'none';
            }

            // Helper untuk memformat angka (digunakan di renderers)
            const formatCurrencyGlobal = (v, c, d = 2) => {
                const n = parseFloat(v);
                if (!Number.isFinite(n)) return '';
                const opt = {
                    minimumFractionDigits: d,
                    maximumFractionDigits: d
                };
                if (c === 'IDR') return `Rp ${n.toLocaleString('id-ID',opt)}`;
                if (c === 'USD') return `$${n.toLocaleString('en-US',opt)}`;
                return `${c} ${n.toLocaleString('id-ID',opt)}`;
            };
            const formatNumberGlobal = (v, d = 0) => {
                const n = parseFloat(v);
                if (!Number.isFinite(n)) return '';
                return n.toLocaleString('id-ID', {
                    minimumFractionDigits: d,
                    maximumFractionDigits: d
                });
            };


            async function ensureItemsLoadedForSO(vbeln) {
                if (itemsCache.has(vbeln)) return itemsCache.get(vbeln);
                const u = new URL(apiItemsBySo, window.location.origin);
                u.searchParams.set('vbeln', vbeln);
                u.searchParams.set('werks', WERKS);
                u.searchParams.set('auart', AUART);
                const r = await fetch(u);
                const jd = await r.json();
                if (!jd.ok) throw new Error(jd.error || 'Gagal memuat item');
                jd.data.forEach(x => itemIdToSO.set(String(x.id), vbeln));
                itemsCache.set(vbeln, jd.data);
                return jd.data;
            }

            function updateSODot(vbeln) {
                const anySel = Array.from(selectedItems).some(id => itemIdToSO.get(String(id)) === vbeln);
                document.querySelectorAll(`.js-t2row[data-vbeln='${CSS.escape(vbeln)}'] .so-selected-dot`)
                    .forEach(dot => dot.style.display = anySel ? 'inline-block' : 'none');
            }

            function applySelectionsToRenderedItems(container) {
                container.querySelectorAll('.check-item').forEach(chk => {
                    chk.checked = selectedItems.has(chk.dataset.id);
                });
            }

            // Sinkronisasi checkbox header Item (T3)
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

            // 🟢 Sinkronisasi checkbox header SO (T2) - Mengimplementasikan kotak kosong
            function syncCheckAllSoHeader(tbody) {
                // Hanya lihat SO yang TAMPIL (tidak disembunyikan oleh collapse mode)
                const allSOCheckboxes = Array.from(tbody.querySelectorAll('.check-so')).filter(ch => ch.closest(
                    'tr').style.display !== 'none');
                const selectAllSo = tbody.closest('table')?.querySelector('.check-all-sos');

                if (!selectAllSo || allSOCheckboxes.length === 0) return;

                const allChecked = allSOCheckboxes.every(ch => ch.checked);
                const checkedCount = allSOCheckboxes.filter(ch => ch.checked).length;
                const totalCount = allSOCheckboxes.length;

                if (allChecked) {
                    selectAllSo.checked = true;
                    selectAllSo.indeterminate = false;
                } else {
                    // [PERBAIKAN]: Jika sebagian atau tidak ada yang dicentang, jadikan kotak kosong.
                    selectAllSo.checked = false;
                    selectAllSo.indeterminate = false;
                }
            }

            // 🟢 Fungsi untuk mengelola Collapse Mode di Tabel 2 (SO List) DENGAN BUKA OTOMATIS T3
            async function applyCollapseViewSo(tbodyEl, on) {
                COLLAPSE_MODE = on;

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
                        r.classList.remove('is-focused');

                        // Cek apakah item T3 sedang terbuka secara manual
                        const isT3Open = r.nextElementSibling.style.display !== 'none';

                        if (chk?.checked) {
                            r.style.display = ''; // Tampilkan SO yang dipilih
                            visibleCount++;

                            // PERBAIKAN: Klik baris SO untuk MEMBUKA/MEMUAT Tabel 3 jika belum terbuka
                            if (!isT3Open) {
                                r.click();
                            }

                        } else {
                            r.style.display = 'none'; // Sembunyikan SO yang tidak dipilih
                            // Tutup paksa T3 jika terbuka
                            if (isT3Open) {
                                r.click(); // Memanggil click untuk menutup
                            } else {
                                r.nextElementSibling.style.display = 'none';
                                r.querySelector('.yz-caret')?.classList.remove('rot');
                            }
                        }
                    }

                    // [PERBAIKAN UTAMA] Jika tidak ada PO yang tersisa, matikan mode kolaps secara otomatis
                    if (visibleCount === 0 && COLLAPSE_MODE) {
                        await applyCollapseViewSo(tbodyEl, false); // Rekursif ke mode normal
                        return; // Keluar dari fungsi ini setelah menonaktifkan
                    }
                } else {
                    // Mode normal: Tampilkan semua SO & tutup semua item (T3)
                    soRows.forEach(r => {
                        r.style.display = '';
                        r.classList.remove('is-focused');
                        // Tutup paksa T3 jika terbuka
                        if (r.nextElementSibling.style.display !== 'none') {
                            r.nextElementSibling.style.display = 'none';
                            r.querySelector('.yz-caret')?.classList.remove('rot');
                        }
                    });
                }

                if (tbodyEl) syncCheckAllSoHeader(tbodyEl);
                updateT2FooterVisibility(tbodyEl.closest('table'));
            }


            function updateSoRemarkFlagFromCache(vbeln) {
                const items = itemsCache.get(vbeln) || [];
                const hasAny = items.some(it => (it.remark || '').trim() !== '');
                document.querySelectorAll(`.js-t2row[data-vbeln='${CSS.escape(vbeln)}'] .so-remark-flag`)
                    .forEach(el => {
                        el.style.display = hasAny ? 'inline-block' : 'none';
                        el.classList.toggle('active', hasAny);
                    });
            }

            function recalcSoRemarkFlagFromDom(vbeln) {
                const nest = document.querySelector(`.js-t2row[data-vbeln='${CSS.escape(vbeln)}']`)
                    ?.nextElementSibling;
                let hasAny = false;
                if (nest) {
                    nest.querySelectorAll('.remark-icon').forEach(ic => {
                        const t = decodeURIComponent(ic.dataset.remark || '');
                        if (t.trim() !== '') hasAny = true;
                    });
                }
                document.querySelectorAll(`.js-t2row[data-vbeln='${CSS.escape(vbeln)}'] .so-remark-flag`)
                    .forEach(el => {
                        el.style.display = hasAny ? 'inline-block' : 'none';
                        el.classList.toggle('active', hasAny);
                    });
            }

            function updateT2FooterVisibility(t2Table) {
                if (!t2Table) return;
                const anyOpen = [...t2Table.querySelectorAll('tr.yz-nest')].some(tr => tr.style.display !==
                    'none' && tr.offsetParent !== null);
                const tfoot = t2Table.querySelector('tfoot.t2-footer');
                if (tfoot) tfoot.style.display = (anyOpen || COLLAPSE_MODE) ? 'none' : '';
            }

            document.addEventListener('click', (e) => {
                if (e.target.closest('.check-so') || e.target.closest('.check-all-sos')) e
                    .stopPropagation();
            }, true);

            /* ---------- RENDERERS ---------- */
            function renderLevel2_SO(rows, kunnr) {
                if (!rows?.length)
                    return `<div class="p-3 text-muted">Tidak ada data Outstanding SO untuk customer ini.</div>`;

                const totalOutsQtyT2 = rows.reduce((sum, r) => sum + parseFloat(r.outs_qty ?? r.OUTS_QTY ?? 0), 0);

                let html = `
    <h5 class="yz-table-title-nested yz-title-so"><i class="fas fa-file-invoice me-2"></i>Outstanding SO</h5>
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
          <th class="text-start">SO</th>
          <th class="text-center">SO Item Count</th>
          <th class="text-center">Req. Deliv. Date</th>
          <th class="text-center">Overdue (Days)</th>
          <th class="text-center">Outs. Qty</th>
          <th class="text-center">Outs. Value</th>
          <th style="width:28px;"></th>
        </tr>
      </thead>
      <tbody>`;
                const rowsSorted = [...rows].sort((a, b) => {
                    const oa = Number(a.Overdue || 0);
                    const ob = Number(b.Overdue || 0);
                    const aOver = oa > 0;
                    const bOver = ob > 0;
                    if (aOver !== bOver) return aOver ? -1 : 1;
                    return ob - oa;
                });

                rowsSorted.forEach((r, i) => {
                    const rid = `t3_${kunnr}_${r.VBELN}_${i}`;
                    const rowHi = r.Overdue > 0 ? 'yz-row-highlight-negative' : '';
                    const hasRemark = Number(r.remark_count || 0) > 0;
                    const outsQty = (typeof r.outs_qty !== 'undefined') ? r.outs_qty : (r.OUTS_QTY ?? 0);
                    const displayOutsValue = formatCurrencyGlobal(r.total_value, r.WAERK);

                    html += `
        <tr class="yz-row js-t2row ${rowHi}" data-vbeln="${r.VBELN}" data-tgt="${rid}">
          <td class="text-center"><input type="checkbox" class="form-check-input check-so" data-vbeln="${r.VBELN}" onclick="event.stopPropagation()"></td>
          <td class="text-center"><span class="yz-caret">▸</span></td>
          <td class="yz-t2-vbeln text-start">${r.VBELN}</td>
          <td class="text-center">${r.item_count ?? '-'}</td>
          <td class="text-center">${r.FormattedEdatu || '-'}</td>
          <td class="text-center">${r.Overdue}</td>
          <td class="text-center">${formatNumberGlobal(outsQty, 0)}</td> <td class="text-center">${displayOutsValue}</td>
          <td class="text-center">
            <i class="fas fa-pencil-alt so-remark-flag ${hasRemark?'active':''}" title="Ada item yang diberi catatan" style="display:${hasRemark?'inline-block':'none'};"></i>
            <span class="so-selected-dot"></span>
          </td>
        </tr>
        <tr id="${rid}" class="yz-nest" style="display:none;">
          <td colspan="9" class="p-0">
            <div class="yz-nest-wrap level-2" style="margin-left:0; padding:.5rem;">
              <div class="yz-slot-items p-2"></div>
            </div>
          </td>
        </tr>`;
                });

                html += `</tbody>
      <tfoot class="t2-footer">
          <tr class="table-light yz-t2-total-outs" style="background-color: #e9ecef;">
              <th colspan="6" class="text-end">Total Outstanding Qty</th>
              <th class="text-center fw-bold">${formatNumberGlobal(totalOutsQtyT2, 0)}</th> <th colspan="2"></th>
          </tr>
      </tfoot>
    </table>`;
                return html;
            }

            function renderLevel3_Items(rows) {
                if (!rows?.length)
                    return `<div class="p-2 text-muted">Tidak ada item detail (dengan Outs. SO > 0).</div>`;

                let html = `<div class="table-responsive">
        <table class="table table-sm table-hover mb-0 yz-mini">
        <thead class="yz-header-item">
          <tr>
            <th style="width:40px;"><input class="form-check-input check-all-items" type="checkbox" title="Pilih Semua Item"></th>
            <th>Item</th><th>Material FG</th><th>Desc FG</th>
            <th>Qty SO</th><th>Outs. SO</th><th>Stock Packing</th>
            <th>GR ASSY</th><th>GR PAINT</th><th>GR PKG</th>
            <th>Net Price</th><th>Outs. Packg Value</th><th>Remark</th>
          </tr>
        </thead>
        <tbody>`;
                rows.forEach(r => {
                    const isChecked = selectedItems.has(String(r.id));
                    const hasRemark = r.remark && r.remark.trim() !== '';
                    const escRemark = r.remark ? encodeURIComponent(r.remark) : '';
                    html += `
             <tr id="item-${r.VBELN_KEY}-${r.POSNR_KEY}"
                 data-item-id="${r.id}" data-werks="${r.WERKS_KEY}" data-auart="${r.AUART_KEY}"
                 data-vbeln="${r.VBELN_KEY}" 
                 data-posnr="${r.POSNR}"
                 data-posnr-key="${r.POSNR_KEY}">
                    <td><input class="form-check-input check-item" type="checkbox" data-id="${r.id}" ${isChecked?'checked':''}></td>
                    <td>${r.POSNR ?? ''}</td>
                    <td>${r.MATNR ?? ''}</td>
                    <td>${r.MAKTX ?? ''}</td>
                    <td>${formatNumberGlobal(r.KWMENG, 0)}</td> <td>${formatNumberGlobal(r.PACKG, 0)}</td> <td>${formatNumberGlobal(r.KALAB2, 0)}</td> <td>${formatNumberGlobal(r.ASSYM, 0)}</td> <td>${formatNumberGlobal(r.PAINT, 0)}</td> <td>${formatNumberGlobal(r.MENGE, 0)}</td> <td>${formatCurrencyGlobal(r.NETPR, r.WAERK)}</td>
                    <td>${formatCurrencyGlobal(r.TOTPR2, r.WAERK)}</td>
                    <td class="text-center">
                        <i class="fas fa-pencil-alt remark-icon" data-remark="${escRemark}" title="Tambah/Edit Catatan"></i>
                        <span class="remark-dot" style="display:${hasRemark?'inline-block':'none'};"></span>
                    </td>
                </tr>`;
                });
                html += `</tbody></table></div>`;
                return html;
            }

            /* ---------- Expand Level-1 (load T2) ---------- */
            document.querySelectorAll('.yz-kunnr-row').forEach(row => {
                row.addEventListener('click', async () => {
                    const kunnr = row.dataset.kunnr;
                    const kid = row.dataset.kid;
                    const cname = row.dataset.cname;
                    const slot = document.getElementById(kid);
                    const wrap = slot.querySelector('.yz-nest-wrap');

                    const tbody = row.closest('tbody');
                    const tableEl = row.closest('table');
                    const tfootEl = tableEl?.querySelector('tfoot.yz-footer-customer');

                    const wasOpen = row.classList.contains('is-open');

                    // Close all other open rows (exclusive toggle)
                    document.querySelectorAll('.yz-kunnr-row.is-open').forEach(r => {
                        if (r !== row) {
                            r.classList.remove('is-open');
                            document.getElementById(r.dataset.kid)?.style.setProperty(
                                'display', 'none', 'important');
                            r.querySelector('.kunnr-caret')?.classList.remove('rot');
                            const otherWrap = document.getElementById(r.dataset.kid)
                                ?.querySelector('.yz-nest-wrap');
                            otherWrap?.querySelectorAll('.js-t2row').forEach(so => {
                                // Tutup T3 jika terbuka
                                if (so.nextElementSibling.style.display !==
                                    'none') {
                                    so.nextElementSibling.style.display =
                                        'none';
                                    so.querySelector('.yz-caret')?.classList
                                        .remove('rot');
                                }
                            });
                            COLLAPSE_MODE = false;
                        }
                    });

                    // Toggle focus and caret
                    row.classList.toggle('is-open');
                    row.querySelector('.kunnr-caret')?.classList.toggle('rot', !wasOpen);
                    slot.style.display = wasOpen ? 'none' : '';

                    if (!wasOpen) {
                        tbody.classList.add('customer-focus-mode');
                        row.classList.add('is-focused');

                        const hasSmallQtyData = initialSmallQtyDataRaw.some(item => item
                            .NAME1 === cname);

                        if (hasSmallQtyData) {
                            if (window.showSmallQtyDetails) {
                                await window.showSmallQtyDetails(cname, WERKS);
                            }
                        } else {
                            if (smallQtySection) smallQtySection.style.display = 'none';
                            if (smallQtyDetailsContainer) smallQtyDetailsContainer.style
                                .display = 'none';
                        }
                    } else {
                        tbody.classList.remove('customer-focus-mode');
                        row.classList.remove('is-focused');

                        if (smallQtySection) smallQtySection.style.display =
                            '';
                        if (smallQtyDetailsContainer) smallQtyDetailsContainer.style.display =
                            'none';
                        if (smallQtyChartContainer) smallQtyChartContainer.style.display =
                            'block';
                        if (chartCanvas) chartCanvas.style.display = 'block';
                        if (initialSmallQtyDataRaw.length > 0 && window.renderSmallQtyChart) {
                            window.renderSmallQtyChart(initialSmallQtyDataRaw, WERKS);
                        }
                    }

                    if (tfootEl) {
                        const anyVisible = [...tableEl.querySelectorAll('tr.yz-nest')]
                            .some(tr => tr.style.display !== 'none' && tr.offsetParent !==
                                null);
                        tfootEl.style.display = anyVisible ? 'none' : '';
                    }
                    wrap?.querySelectorAll('table').forEach(tbl => updateT2FooterVisibility(
                        tbl));


                    if (wasOpen) return;
                    if (wrap.dataset.loaded === '1') {
                        const soTbody = wrap.querySelector('table tbody');
                        if (soTbody) syncCheckAllSoHeader(soTbody);

                        wrap.querySelector('.js-collapse-toggle')?.addEventListener('click',
                            async (ev) => {
                                ev.stopPropagation();
                                await applyCollapseViewSo(soTbody, !COLLAPSE_MODE);
                            });
                        return;
                    }

                    try {
                        wrap.innerHTML = `<div class="p-3 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
                <div class="spinner-border spinner-border-sm me-2"></div>Memuat data…
            </div>`;
                        const url = new URL(apiSoByCustomer, window.location.origin);
                        url.searchParams.set('kunnr', kunnr);
                        url.searchParams.set('werks', WERKS);
                        url.searchParams.set('auart', AUART);
                        const res = await fetch(url);
                        const js = await res.json();
                        if (!js.ok) throw new Error(js.error || 'Gagal memuat data SO');

                        wrap.innerHTML = renderLevel2_SO(js.data, kunnr);
                        wrap.dataset.loaded = '1';

                        const soTable = wrap.querySelector('table');
                        const soTbody = soTable?.querySelector('tbody');

                        updateT2FooterVisibility(soTable);

                        wrap.querySelector('.js-collapse-toggle')?.addEventListener('click',
                            async (ev) => {
                                ev.stopPropagation();
                                await applyCollapseViewSo(soTbody, !COLLAPSE_MODE);
                            });

                        if (soTbody) syncCheckAllSoHeader(soTbody);


                        // klik baris SO => focus-mode & load items
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
                                const itemTr = wrap.querySelector('#' +
                                    tgtId);
                                const box = itemTr.querySelector(
                                    '.yz-slot-items');
                                const open = itemTr.style.display !==
                                    'none';
                                const t2tbl = soRow.closest('table');
                                const soTbody = soRow.closest('tbody');

                                soRow.querySelector('.yz-caret')?.classList
                                    .toggle('rot');

                                if (!open) {
                                    soTbody?.classList.add('so-focus-mode');
                                    soRow.classList.add('is-focused');
                                } else {
                                    soTbody?.classList.remove(
                                        'so-focus-mode');
                                    soRow.classList.remove('is-focused');
                                }

                                if (open) {
                                    itemTr.style.display = 'none';
                                    updateT2FooterVisibility(t2tbl);
                                    return;
                                }
                                itemTr.style.display = '';
                                updateT2FooterVisibility(t2tbl);

                                if (itemTr.dataset.loaded === '1') {
                                    applySelectionsToRenderedItems(box);
                                    syncCheckAllHeader(box);
                                    return;
                                }

                                box.innerHTML = `<div class="p-2 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
                             <div class="spinner-border spinner-border-sm me-2"></div>Memuat item…
                           </div>`;
                                try {
                                    const items =
                                        await ensureItemsLoadedForSO(vbeln);

                                    box.innerHTML = renderLevel3_Items(
                                        items);
                                    applySelectionsToRenderedItems(box);
                                    syncCheckAllHeader(box);
                                    itemTr.dataset.loaded = '1';
                                    updateSoRemarkFlagFromCache(vbeln);
                                } catch (e) {
                                    box.innerHTML =
                                        `<div class="alert alert-danger m-3">${e.message}</div>`;
                                }
                            });
                        });
                    } catch (e) {
                        wrap.innerHTML =
                            `<div class="alert alert-danger m-3">${e.message}</div>`;
                    }
                });
            });

            /* ---------- CHANGE events (checkbox) ---------- */
            document.body.addEventListener('change', async (e) => {
                // --- single item (T3)
                if (e.target.classList.contains('check-item')) {
                    const id = e.target.dataset.id;
                    if (e.target.checked) selectedItems.add(id);
                    else selectedItems.delete(id);
                    const vbeln = itemIdToSO.get(String(id));
                    if (vbeln) updateSODot(vbeln);

                    const box = e.target.closest('.yz-slot-items');
                    if (box) syncCheckAllHeader(box);

                    const soRow = document.querySelector(
                        `.js-t2row[data-vbeln='${CSS.escape(vbeln)}']`);
                    const tbody = soRow?.closest('tbody');
                    if (tbody) syncCheckAllSoHeader(tbody);

                    updateExportButton();
                    return;
                }

                // --- check-all items (T3)
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

                // --- check-all SO (T2)
                if (e.target.classList.contains('check-all-sos')) {
                    const tbody = e.target.closest('table')?.querySelector('tbody');
                    if (!tbody) return;
                    const allSO = tbody.querySelectorAll('.check-so');

                    for (const chk of allSO) {
                        chk.checked = e.target.checked;
                        const vbeln = chk.dataset.vbeln;

                        const items = await ensureItemsLoadedForSO(vbeln);
                        if (e.target.checked) items.forEach(it => selectedItems.add(String(it.id)));
                        else {
                            Array.from(selectedItems).forEach(id => {
                                if (itemIdToSO.get(String(id)) === vbeln) selectedItems.delete(
                                    id);
                            });
                        }
                        updateSODot(vbeln);

                        const soRow = chk.closest('.js-t2row');
                        const nest = soRow?.nextElementSibling;

                        if (nest && nest.dataset.loaded === '1') {
                            const box = nest.querySelector('.yz-slot-items');
                            box.querySelectorAll('.check-item').forEach(ch => ch.checked = e.target
                                .checked);
                            const hdr = box.querySelector('table .check-all-items');
                            if (hdr) {
                                hdr.checked = e.target.checked;
                                hdr.indeterminate = false;
                            }
                        }
                    }

                    if (tbody) syncCheckAllSoHeader(tbody);

                    if (COLLAPSE_MODE && tbody) await applyCollapseViewSo(tbody, true);

                    updateExportButton();
                    return;
                }

                // --- single SO (T2)
                if (e.target.classList.contains('check-so')) {
                    const vbeln = e.target.dataset.vbeln;

                    if (e.target.checked) {
                        const items = await ensureItemsLoadedForSO(vbeln);
                        items.forEach(it => selectedItems.add(String(it.id)));
                    } else {
                        Array.from(selectedItems).forEach(id => {
                            if (itemIdToSO.get(String(id)) === vbeln) selectedItems.delete(id);
                        });
                    }
                    updateSODot(vbeln);

                    const tbody = e.target.closest('tbody');
                    if (tbody) syncCheckAllSoHeader(tbody);

                    const soRow = document.querySelector(
                        `.js-t2row[data-vbeln='${CSS.escape(vbeln)}']`);
                    const nest = soRow?.nextElementSibling;

                    if (nest && nest.dataset.loaded === '1') {
                        const box = nest.querySelector('.yz-slot-items');
                        box.querySelectorAll('.check-item').forEach(ch => ch.checked = e.target
                            .checked);
                        const hdr = box.querySelector('table .check-all-items');
                        if (hdr) hdr.checked = e.target.checked;
                    }

                    if (COLLAPSE_MODE && tbody) await applyCollapseViewSo(tbody, true);

                    updateExportButton();
                    return;
                }
            });

            // Mengikat event listener Collapse/Fokus untuk SO (Level 2)
            document.body.addEventListener('click', async (e) => {
                const toggleBtn = e.target.closest('.js-collapse-toggle');
                if (!toggleBtn) return;

                e.stopPropagation();
                const soTbody = toggleBtn.closest('table')?.querySelector('tbody');
                if (soTbody) {
                    await applyCollapseViewSo(soTbody, !COLLAPSE_MODE);
                }
            });


            /* ---------- Remark handlers ---------- */
            document.body.addEventListener('click', (e) => {
                if (!e.target.classList.contains('remark-icon')) return;
                const rowEl = e.target.closest('tr');
                const currentRemark = decodeURIComponent(e.target.dataset.remark || '');

                saveRemarkBtn.dataset.werks = rowEl.dataset.werks;
                saveRemarkBtn.dataset.auart = rowEl.dataset.auart;
                saveRemarkBtn.dataset.vbeln = rowEl.dataset.vbeln;
                saveRemarkBtn.dataset.posnr = rowEl.dataset.posnr; // POSNR dari UI
                saveRemarkBtn.dataset.posnrKey = rowEl.dataset.posnrKey; // POSNR_KEY (6 digit)

                remarkTextarea.value = currentRemark;
                remarkFeedback.textContent = '';
                if (remarkModalEl.parentElement !== document.body) document.body.appendChild(remarkModalEl);
                if (bootstrap.Modal.getInstance(remarkModalEl)) bootstrap.Modal.getInstance(remarkModalEl)
                    .hide();
                remarkModal.show();
            });

            saveRemarkBtn.addEventListener('click', async function() {
                const payload = {
                    werks: this.dataset.werks,
                    auart: this.dataset.auart,
                    vbeln: this.dataset.vbeln,
                    // Menggunakan data-posnr-key untuk payload ke API/DB
                    posnr: this.dataset.posnrKey,
                    remark: remarkTextarea.value
                };
                const vbeln = payload.vbeln;
                this.disabled = true;
                this.innerHTML =
                    `<span class="spinner-border spinner-border-sm" role="status"></span> Menyimpan...`;

                // Cari elemen yang relevan di DOM
                const soRow = document.querySelector(`.js-t2row[data-vbeln='${CSS.escape(vbeln)}']`);
                const itemNest = soRow?.nextElementSibling;
                const box = itemNest?.querySelector('.yz-slot-items');

                try {
                    const response = await fetch("{{ route('so.api.save_remark') }}", {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    });
                    const result = await response.json();
                    if (!response.ok || !result.ok) throw new Error(result.message ||
                        'Gagal menyimpan catatan.');

                    // --- PERBAIKAN UNTUK MEMBUKA/MEMUAT ULANG T3 & MENJAGA T3 TETAP TERBUKA ---

                    // 1. Hapus data SO dari itemsCache
                    itemsCache.delete(vbeln);

                    // 2. Tandai T3 agar dimuat ulang dari API
                    if (itemNest) {
                        itemNest.removeAttribute('data-loaded');
                    }

                    // 3. Jika T3 terbuka ATAU bisa dibuka (itemNest ada)
                    if (itemNest && itemNest.style.display === 'none') {
                        // Jika tertutup, panggil click pada baris SO untuk MEMBUKA dan memicu pemuatan ulang
                        soRow.click();
                        // Kita tidak perlu render manual di sini karena .click() akan menangani pemuatan dan rendering
                    } else if (itemNest && itemNest.style.display !== 'none' && box) {
                        // Jika sudah terbuka, kita harus memuat ulang konten secara paksa
                        box.innerHTML = `<div class="p-2 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
                            <div class="spinner-border spinner-border-sm me-2"></div>Memuat item terbaru…
                        </div>`;

                        const items = await ensureItemsLoadedForSO(vbeln);

                        // Render ulang konten item
                        box.innerHTML = renderLevel3_Items(items);
                        applySelectionsToRenderedItems(box);
                        syncCheckAllHeader(box);
                        itemNest.dataset.loaded = '1';
                        updateSoRemarkFlagFromCache(
                            vbeln); // Update flag Level 2 berdasarkan cache baru
                    }

                    // 4. Update status remark di item yang bersangkutan (Jika sudah di DOM sebelum refresh)
                    const rowSel =
                        `tr[data-werks='${payload.werks}'][data-auart='${payload.auart}'][data-vbeln='${payload.vbeln}'][data-posnr-key='${payload.posnr}']`;
                    const rowEl = document.querySelector(rowSel);
                    const ic = rowEl?.querySelector('.remark-icon');
                    const dot = rowEl?.querySelector('.remark-dot');
                    if (ic) ic.dataset.remark = encodeURIComponent(payload.remark || '');
                    if (dot) dot.style.display = (payload.remark.trim() !== '' ? 'inline-block' :
                        'none');

                    // 5. Recalculate remark flag di Level 2
                    // Jika langkah 3 berhasil, ini akan meng-update bendera pensil di Level 2.
                    recalcSoRemarkFlagFromDom(vbeln);

                    // --- Akhir Perbaikan ---

                    remarkFeedback.textContent = 'Catatan berhasil disimpan!';
                    remarkFeedback.className = 'small mt-2 text-success';
                    setTimeout(() => remarkModal.hide(), 800);
                } catch (err) {

                    // Jika T3 terbuka, pastikan ikon pensil di update minimal di DOM saat ini (jika tidak terjadi reload total)
                    const rowSel =
                        `tr[data-werks='${payload.werks}'][data-auart='${payload.auart}'][data-vbeln='${payload.vbeln}'][data-posnr-key='${payload.posnr}']`;
                    const rowEl = document.querySelector(rowSel);
                    const ic = rowEl?.querySelector('.remark-icon');
                    const dot = rowEl?.querySelector('.remark-dot');
                    if (ic) ic.dataset.remark = encodeURIComponent(payload.remark || '');
                    if (dot) dot.style.display = (payload.remark.trim() !== '' ? 'inline-block' :
                        'none');
                    recalcSoRemarkFlagFromDom(vbeln);

                    remarkFeedback.textContent = err.message;
                    remarkFeedback.className = 'small mt-2 text-danger';
                } finally {
                    this.disabled = false;
                    this.innerHTML = 'Simpan Catatan';
                }
            });

            /* ---------- Export ---------- */
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

            /* ---------- Auto expand/scroll (PERBAIKAN FINAL HIGHLIGHT) ---------- */
            (async function autoExpandFromRoot() {
                const VBELN = (__root?.dataset.hvbeln || '').trim();
                const KUNNR = (__root?.dataset.hkunnr || '').trim();
                const POSNR = (__root?.dataset.hposnr || '').trim();
                const shouldAuto = AUTO;

                const POSNR6 = POSNR ? String(POSNR).replace(/\D/g, '').padStart(6, '0') : '';

                const waitFor = (fn, {
                    timeout = 12000,
                    interval = 120
                } = {}) => new Promise(r => {
                    const s = Date.now(),
                        t = setInterval(() => {
                            let ok = false;
                            try {
                                ok = !!fn();
                            } catch {};
                            if (ok) {
                                clearInterval(t);
                                return r(true);
                            }
                            if (Date.now() - s > timeout) {
                                clearInterval(t);
                                return r(false);
                            }
                        }, interval);
                });
                const scrollAndFlash = (el) => {
                    if (!el) return;
                    try {
                        el.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                        el.classList.add('row-highlighted');
                        setTimeout(() => el.classList.remove('row-highlighted'), 3000);
                    } catch {};
                };

                const findItemRow = (box, vbeln, pos6) => {
                    const rows = box?.querySelectorAll(`tr[data-vbeln='${CSS.escape(vbeln)}']`) || [];
                    for (const tr of rows) {
                        const key = tr.dataset.posnrKey || '';
                        if (key === pos6) return tr;
                    }
                    return null;
                };

                const openToSO = async (customerRow) => {
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
                    const opened = itemNest && itemNest.style.display !== 'none';
                    if (!opened) soRow.click();
                    const okT3 = await waitFor(() => itemNest && itemNest.dataset.loaded === '1', {
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
                };

                if (!(shouldAuto && (VBELN || KUNNR))) return;

                let crow = null;
                if (KUNNR) {
                    crow = document.querySelector(`.yz-kunnr-row[data-kunnr='${CSS.escape(KUNNR)}']`);
                }

                if (VBELN && crow) {
                    const {
                        soRow,
                        itemsBox
                    } = await openToSO(crow);
                    if (!soRow) return;

                    const target = (POSNR6 && findItemRow(itemsBox, VBELN, POSNR6)) ||
                        Array.from(itemsBox?.querySelectorAll('tr[data-item-id]') || []).find(tr => {
                            const ic = tr.querySelector('.remark-icon');
                            return ic && decodeURIComponent(ic.dataset.remark || '').trim() !== '';
                        });

                    scrollAndFlash(target || soRow);
                    return;
                }

                if (VBELN && !KUNNR) {
                    let foundSoRow = null,
                        foundItemsBox = null;

                    const customerRows = Array.from(document.querySelectorAll('.yz-kunnr-row'));
                    for (const row of customerRows) {
                        const {
                            soRow,
                            itemsBox
                        } = await openToSO(row);
                        if (soRow) {
                            foundSoRow = soRow;
                            foundItemsBox = itemsBox;
                            break;
                        }
                    }

                    if (!foundSoRow) return;

                    const target = (POSNR6 && findItemRow(foundItemsBox, VBELN, POSNR6)) ||
                        Array.from(foundItemsBox?.querySelectorAll('tr[data-item-id]') || []).find(tr => {
                            const ic = tr.querySelector('.remark-icon');
                            return ic && decodeURIComponent(ic.dataset.remark || '').trim() !== '';
                        });

                    scrollAndFlash(target || foundSoRow);
                }
            })();

        });
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

        // Dibuat global agar bisa dipanggil dari event listener Level-1 di script block sebelumnya
        async function showSmallQtyDetails(customerName, werks) {
            const locationMap = {
                '2000': 'Surabaya',
                '3000': 'Semarang'
            };
            const locationName = locationMap[werks] ?? werks;
            const root = document.getElementById('so-root');
            const currentAuart = (root.dataset.auart || '').trim();

            if (smallQtyChartContainer) smallQtyChartContainer.style.display = 'none';

            smallQtyDetailsTitle.textContent =
                `Detail Item Outstanding untuk ${customerName} - (${locationName})`;
            smallQtyMeta.textContent = '';
            exportSmallQtyPdfBtn.disabled = true;
            smallQtyDetailsTable.innerHTML =
                `<div class="d-flex justify-content-center align-items-center p-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <span class="ms-3 text-muted">Memuat data...</span>
                </div>`;
            smallQtyDetailsContainer.style.display = 'block';

            if (smallQtySection) smallQtySection.style.display = '';

            smallQtyDetailsContainer.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });

            try {
                const apiUrl = new URL("{{ route('so.api.small_qty_details') }}", window.location.origin);
                apiUrl.searchParams.append('customerName', customerName);
                apiUrl.searchParams.append('werks', werks);
                apiUrl.searchParams.append('auart', currentAuart);

                const response = await fetch(apiUrl);
                const result = await response.json();

                if (result.ok && result.data.length > 0) {
                    const uniqSO = new Set(result.data.map(r => (r.SO || '').toString().trim()).filter(
                        Boolean));
                    const totalSO = uniqSO.size;
                    const totalItem = result.data.length;

                    smallQtyMeta.textContent = `• ${totalSO} SO • ${totalItem} Item`;
                    exportSmallQtyPdfBtn.disabled = false;

                    if (exportForm) {
                        exportForm.querySelector('#exp_customerName').value = customerName;
                    }

                    result.data.sort((a, b) => parseFloat(a.PACKG) - parseFloat(b.PACKG));

                    const tableHeaders = `<tr>
                        <th style="width:5%;" class="text-center">No.</th>
                        <th class="text-center">SO</th>
                        <th class="text-center">Item</th>
                        <th>Description</th>
                        <th class="text-end">Qty SO</th>
                        <th class="text-end">Outs. SO (≤5)</th>
                        </tr>`;

                    let tableBodyHtml = result.data.map((item, idx) => {
                        const qtySo = formatNumberChart(item.KWMENG);
                        const qtyOuts = formatNumberChart(item.PACKG);
                        return `<tr>
                        <td class="text-center">${idx+1}</td>
                        <td class="text-center">${item.SO}</td>
                        <td class="text-center">${item.POSNR}</td>
                        <td>${item.MAKTX}</td>
                        <td class="text-end">${qtySo}</td>
                        <td class="text-end fw-bold text-danger">${qtyOuts}</td>
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
                        `<div class="text-center p-5 text-muted">Data item Small Quantity (Outs. SO <=5) tidak ditemukan untuk customer ini.</div>`;
                }
            } catch (error) {
                console.error('Gagal mengambil data detail Small Qty:', error);
                smallQtyMeta.textContent = '';
                exportSmallQtyPdfBtn.disabled = true;
                smallQtyDetailsTable.innerHTML =
                    `<div class="text-center p-5 text-danger">Terjadi kesalahan saat memuat data.</div>`;
            }
        }

        // Fungsi ini dikembalikan ke global scope agar bisa dipanggil dari event listener Level-1
        function renderSmallQtyChart(dataToRender, werks) {
            const ctxSmallQty = document.getElementById('chartSmallQtyByCustomer');
            const plantCode = (werks === '3000') ? 'Semarang' : 'Surabaya';

            const barColor = (werks === '3000') ? '#198754' : '#ffc107';

            const customerMap = new Map();
            dataToRender.forEach(item => {
                const name = (item.NAME1 || '').trim();
                if (!name) return;
                const currentCount = customerMap.get(name) || 0;
                // Menggunakan parseInt untuk memastikan perhitungan item_count benar
                customerMap.set(name, currentCount + parseInt(item.item_count, 10));
            });

            const sortedCustomers = [...customerMap.entries()].sort((a, b) => b[1] - a[1]);
            const labels = sortedCustomers.map(item => item[0]);
            const itemCounts = sortedCustomers.map(item => item[1]);
            const totalItemCount = itemCounts.reduce((sum, count) => sum + count, 0);

            // Update title
            if (smallQtyChartTitle) {
                smallQtyTotalItem.textContent = `(Total Item: ${formatNumberChart(totalItemCount)})`;
            }

            // Handle No Data
            const noDataEl = ctxSmallQty?.closest('.chart-container').querySelector('.yz-nodata');
            if (!ctxSmallQty || dataToRender.length === 0 || totalItemCount === 0) {
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
