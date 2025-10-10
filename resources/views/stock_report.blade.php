{{-- resources/views/stock_report.blade.php --}}
@extends('layouts.app')

@section('title', 'Laporan Stok')

@section('content')

    @php
        // Ambil nilai dari controller / query
        $selectedWerks = $selected['werks'] ?? null;
        $selectedType = $selected['type'] ?? null;

        $locationMap = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locName = $locationMap[$selectedWerks] ?? $selectedWerks;

        // TOTAL GLOBAL DITANGANI OLEH CONTROLLER. INISIALISASI DI SINI HANYA UNTUK MENGHINDARI ERROR JIKA VIEW DIRENDER TANPA DATA.
        $grandTotalQty = $grandTotalQty ?? 0;
        $grandTotalsCurr = $grandTotalsCurr ?? [];

        // helpers
        $fmtNumber = fn($n, $d = 0) => number_format((float) $n, $d, ',', '.');
        $fmtMoney = function ($value, $currency) {
            $n = (float) $value;
            if ($currency === 'IDR') {
                return 'Rp ' . number_format($n, 2, ',', '.');
            }
            if ($currency === 'USD') {
                return '$' . number_format($n, 2, '.', ',');
            }
            return trim(($currency ?: '') . ' ' . number_format($n, 2, ',', '.'));
        };

        $formatTotalsStock = function (array $totals) use ($fmtMoney) {
            $parts = [];
            $allZero = true;

            // Urutan: USD, IDR, mata uang lain
            if (isset($totals['USD']) && (float) $totals['USD'] != 0) {
                $parts[] = $fmtMoney((float) $totals['USD'], 'USD');
                $allZero = false;
            }
            if (isset($totals['IDR']) && (float) $totals['IDR'] != 0) {
                $parts[] = $fmtMoney((float) $totals['IDR'], 'IDR');
                $allZero = false;
            }

            // Jika ada mata uang lain (hasil fallback)
            foreach ($totals as $curr => $val) {
                if ($curr !== 'USD' && $curr !== 'IDR' && (float) $val != 0) {
                    $parts[] = $fmtMoney((float) $val, $curr);
                    $allZero = false;
                }
            }

            if ($allZero) {
                return 'Rp 0,00';
            }

            return implode(' | ', $parts);
        };

        use Illuminate\Support\Facades\Crypt;
    @endphp

    <div id="stock-root" data-werks="{{ $selectedWerks ?? '' }}" data-type="{{ $selectedType ?? '' }}" style="display:none">
    </div>

    {{-- =========================================================
    HEADER: Pills (Stock Type) • Export Items
    ========================================================= --}}
    <div class="card yz-card shadow-sm mb-3 overflow-visible">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            {{-- Kiri: Pills --}}
            <div class="py-1">
                @if ($selectedWerks)
                    <ul class="nav nav-pills yz-auart-pills p-1 flex-wrap" style="border-radius:.75rem;">
                        <li class="nav-item mb-2 me-2">
                            <a class="nav-link pill-green {{ $selectedType == 'whfg' ? 'active' : '' }}"
                                href="{{ route('stock.index', ['q' => Crypt::encrypt(['werks' => $selectedWerks, 'type' => 'whfg'])]) }}">
                                WHFG
                            </a>
                        </li>
                        <li class="nav-item mb-2 me-2">
                            <a class="nav-link pill-green {{ $selectedType == 'fg' ? 'active' : '' }}"
                                href="{{ route('stock.index', ['q' => Crypt::encrypt(['werks' => $selectedWerks, 'type' => 'fg'])]) }}">
                                Packing
                            </a>
                        </li>
                    </ul>
                @else
                    <i class="fas fa-info-circle me-2"></i>
                    Pilih Plant (Surabaya/Semarang) dari sidebar untuk memulai.
                @endif
            </div>

            {{-- Kanan: Export Items (opsional) --}}
            <div class="py-1 d-flex align-items-center gap-2">
                <div class="dropdown" id="export-dropdown-container" style="display:none;">
                    <button class="btn btn-primary dropdown-toggle" type="button" id="export-btn" data-bs-toggle="dropdown"
                        aria-expanded="false">
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

    {{-- =========================
    TABEL UTAMA (Stock By Customer)
    ========================= --}}
    @if ($rows)
        <div class="card yz-card shadow-sm">
            <div class="card-body p-0 p-md-2">

                <div class="p-3 mx-md-3 mt-md-3 yz-main-title-wrapper">
                    <h5 class="yz-table-title mb-0">
                        <i class="fas fa-users me-2"></i>
                        Stock By Customer
                        @if ($selectedWerks)
                            <span class="text-muted small ms-2">— {{ $locName }}</span>
                        @endif
                    </h5>
                </div>

                <div class="yz-customer-list px-md-3 pt-3">
                    <div class="d-grid gap-0 mb-4" id="customer-list-container">
                        @forelse ($rows as $r)
                            @php
                                $kid = 'krow_' . $r->KUNNR . '_' . $loop->index;

                                $totalQty = (float) ($r->TOTAL_QTY ?? 0);

                                $customerTotals = [
                                    'USD' => (float) ($r->TOTAL_VALUE_USD ?? 0),
                                    'IDR' => (float) ($r->TOTAL_VALUE_IDR ?? 0),
                                ];

                                // Fallback untuk customer totals (hanya jika USD dan IDR keduanya nol)
                                if ($customerTotals['USD'] == 0 && $customerTotals['IDR'] == 0) {
                                    if (($r->TOTAL_VALUE ?? 0) != 0) {
                                        $customerTotals[$r->WAERK ?? 'IDR'] = (float) ($r->TOTAL_VALUE ?? 0);
                                    }
                                }

                                $displayTotalValue = $formatTotalsStock($customerTotals);
                            @endphp

                            {{-- Customer Card (Level 1) --}}
                            <div class="yz-customer-card" data-kunnr="{{ $r->KUNNR }}" data-kid="{{ $kid }}"
                                data-cname="{{ $r->NAME1 }}" title="Klik untuk melihat detail SO">
                                <div class="d-flex align-items-center justify-content-between p-3">

                                    {{-- KIRI: Customer Name & Caret --}}
                                    <div class="d-flex align-items-center flex-grow-1 me-3">
                                        <span class="kunnr-caret me-3"><i class="fas fa-chevron-right"></i></span>
                                        <div class="customer-info">
                                            <div class="fw-bold fs-5 text-truncate">{{ $r->NAME1 }}</div>
                                        </div>
                                    </div>

                                    {{-- KANAN: Metrik & Nilai --}}
                                    <div id="metric-columns"
                                        class="d-flex align-items-center text-center flex-wrap flex-md-nowrap">

                                        {{-- Total Stock Qty --}}
                                        <div class="metric-box mx-4" style="min-width: 100px;">
                                            <div class="metric-value fs-4 fw-bold text-primary text-end">
                                                {{ $fmtNumber($totalQty) }}
                                            </div>
                                            <div class="metric-label text-muted small text-end">Total Qty</div>
                                        </div>

                                        {{-- Total Stock Value --}}
                                        <div class="metric-box mx-4 text-end" style="min-width: 180px;">
                                            <div class="metric-value fw-bold text-dark">{{ $displayTotalValue }}</div>
                                            <div class="metric-label text-muted small">Total Value</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Detail Row (Nested Table Container - Level 2) --}}
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

                    {{-- Global Totals Card (Pengganti TFOOT) --}}
                    {{-- Tampilkan jika ada baris data --}}
                    @if ($rows->count() > 0)
                        <div class="card shadow-sm yz-global-total-card mb-4">
                            <div class="card-body p-3 d-flex justify-content-between align-items-center flex-wrap">
                                <h6 class="mb-0 text-dark-emphasis"><i class="fas fa-chart-pie me-2"></i>Total Keseluruhan
                                </h6>

                                <div id="footer-metric-columns"
                                    class="d-flex align-items-center text-center flex-wrap flex-md-nowrap">

                                    {{-- Total Stock Qty --}}
                                    <div class="metric-box mx-4"
                                        style="min-width: 100px; border-left: none !important; padding-left: 0 !important;">
                                        <div class="fw-bold text-primary text-end">
                                            {{ $fmtNumber($grandTotalQty) }}
                                        </div>
                                        <div class="small text-muted text-end">Total Qty</div>
                                    </div>

                                    {{-- Total Stock Value --}}
                                    <div class="metric-box mx-4 text-end" style="min-width: 180px;">
                                        <div class="fw-bold text-dark">{{ $formatTotalsStock($grandTotalsCurr) }}</div>
                                        <div class="small text-muted">Total Value</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- pagination --}}
            @if ($rows)
                <div class="px-3 py-2">
                    {{ $rows->links() }}
                </div>
            @endif
        </div>
    @endif
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard-style.css') }}">
    <style>
        .yz-caret {
            display: inline-block;
            transition: transform .18s ease;
            user-select: none;
            margin-right: 5px;
            vertical-align: middle;
            line-height: 1;
        }

        .yz-caret.rot {
            transform: rotate(90deg)
        }

        .so-selected-dot {
            height: 8px;
            width: 8px;
            background: #0d6efd;
            border-radius: 50%;
            display: none;
            margin-left: 5px;
            vertical-align: middle
        }

        #metric-columns .metric-box {
            text-align: right;
        }

        .yz-row-highlight-negative td {
            background-color: transparent !important;
        }

        .yz-row-highlight-negative:hover td {
            background-color: #f8f9fa !important;
        }

        .yz-header-so .js-collapse-toggle {
            line-height: 1;
            padding: 2px 8px;
        }

        .yz-header-so .yz-collapse-caret {
            display: inline-block;
            transition: transform .18s ease
        }

        /* BARU: Sembunyikan card customer lain saat mode fokus aktif */
        .d-grid.customer-focus-mode .yz-customer-card:not(.is-focused) {
            display: none;
        }

        /* PERBAIKAN: Sembunyikan nest card yang tidak relevan. Menggunakan koma untuk menggabungkan selektor */
        .d-grid.customer-focus-mode .yz-nest-card {
            display: none !important;
        }

        .d-grid.customer-focus-mode .yz-customer-card.is-focused+.yz-nest-card {
            display: block !important;
        }
    </style>
@endpush

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // --- Endpoints & State ---
            const apiSoByCustomer = "{{ route('stock.api.by_customer') }}";
            const apiItemsBySo = "{{ route('stock.api.by_items') }}";
            const exportUrl = "{{ route('stock.export') }}";
            const csrfToken = "{{ csrf_token() }}";

            const root = document.getElementById('stock-root');
            const WERKS = (root?.dataset.werks || '').trim();
            const TYPE = (root?.dataset.type || '').trim();

            const exportDropdownContainer = document.getElementById('export-dropdown-container');
            const selectedCountSpan = document.getElementById('selected-count');
            const globalFooter = document.querySelector('.yz-global-total-card');

            const selectedItems = new Set();
            const itemIdToSO = new Map();
            const itemsCache = new Map();

            let COLLAPSE_MODE = false;

            // --- Helpers ---
            const formatCurrency = (value, currency, d = 2) => {
                const n = parseFloat(value);
                if (!Number.isFinite(n)) return '';
                const opt = {
                    minimumFractionDigits: d,
                    maximumFractionDigits: d
                };
                if (currency === 'IDR') return `Rp ${n.toLocaleString('id-ID',opt)}`;
                if (currency === 'USD') return `$${n.toLocaleString('en-US',opt)}`;
                return `${currency} ${n.toLocaleString('id-ID',opt)}`;
            };
            const formatNumber = (num, d = 0) => {
                const n = parseFloat(num);
                if (!Number.isFinite(n)) return '';
                return n.toLocaleString('id-ID', {
                    minimumFractionDigits: d,
                    maximumFractionDigits: d
                });
            };

            function updateExportButton() {
                const n = selectedItems.size;
                if (selectedCountSpan) selectedCountSpan.textContent = n;
                if (exportDropdownContainer) exportDropdownContainer.style.display = n > 0 ? 'block' : 'none';
            }

            function updateSODot(vbeln) {
                const anySel = Array.from(selectedItems).some(id => itemIdToSO.get(String(id)) === vbeln);
                document.querySelectorAll(`.yz-nest-card .js-t2row[data-vbeln='${vbeln}'] .so-selected-dot`)
                    .forEach(dot => dot.style.display = anySel ? 'inline-block' : 'none');
            }

            function syncSelectAllItemsState(container) {
                const itemCheckboxes = container.querySelectorAll('.check-item');
                const selectAll = container.querySelector('.check-all-items');
                if (!selectAll || !itemCheckboxes.length) return;
                const allChecked = Array.from(itemCheckboxes).every(ch => ch.checked);
                selectAll.checked = allChecked;
                selectAll.indeterminate = !allChecked && Array.from(itemCheckboxes).some(ch => ch.checked);
            }

            function syncSelectAllSoState(tbody) {
                const allSoCheckboxes = Array.from(tbody.querySelectorAll('.check-so'));
                const visibleSoCheckboxes = allSoCheckboxes.filter(ch => ch.closest('tr').style.display !== 'none');
                const soCheckboxesToConsider = COLLAPSE_MODE ? visibleSoCheckboxes : allSoCheckboxes;

                const selectAllSo = tbody.closest('table')?.querySelector('.check-all-sos');

                if (!selectAllSo || soCheckboxesToConsider.length === 0) {
                    if (selectAllSo) selectAllSo.checked = false, selectAllSo.indeterminate = false;
                    return;
                }

                const checkedCount = soCheckboxesToConsider.filter(ch => ch.checked).length;
                const totalCount = soCheckboxesToConsider.length;

                if (checkedCount === 0) {
                    selectAllSo.checked = false;
                    selectAllSo.indeterminate = false;
                } else if (checkedCount === totalCount) {
                    selectAllSo.checked = true;
                    selectAllSo.indeterminate = false;
                } else {
                    selectAllSo.checked = false;
                    selectAllSo.indeterminate = false;
                }
            }

            function applySelectionsToRenderedItems(container) {
                container.querySelectorAll('.check-item').forEach(chk => {
                    if (selectedItems.has(chk.dataset.id)) chk.checked = true;
                    else chk.checked = false;
                });
                syncSelectAllItemsState(container);
            }

            async function ensureItemsLoadedForSO(vbeln) {
                if (itemsCache.has(vbeln)) return itemsCache.get(vbeln);
                const u = new URL(apiItemsBySo, window.location.origin);
                u.searchParams.set('vbeln', vbeln);
                u.searchParams.set('werks', WERKS);
                u.searchParams.set('type', TYPE);
                const r = await fetch(u);
                const jd = await r.json();
                if (!jd.ok) throw new Error(jd.error || 'Gagal memuat item');
                jd.data.forEach(x => itemIdToSO.set(String(x.id), vbeln));
                itemsCache.set(vbeln, jd.data);
                return jd.data;
            }

            // ===== RENDER LEVEL 2 (SO) =====
            function renderLevel2_SO(rows, kunnr) {
                if (!rows?.length)
                    return `<div class="p-3 text-muted">Tidak ada data Outstanding SO untuk customer ini.</div>`;
                let html = `
        <div class="table-responsive">
        <table class="table table-sm mb-0 yz-mini">
            <thead class="yz-header-so">
                <tr>
                    <th style="width:40px;" class="text-center">
                        <input type="checkbox" class="form-check-input check-all-sos"
                                title="Pilih semua SO" onclick="event.stopPropagation()" onmousedown="event.stopPropagation()">
                    </th>
                    <th style="width:40px;" class="text-center">
                        <button type="button" class="btn btn-sm btn-light js-collapse-toggle" title="Mode Kolaps/Fokus">
                            <span class="yz-collapse-caret">▸</span>
                        </button>
                    </th>
                    <th class="text-start">SO</th>
                    <th class="text-center">Total Stock Qty</th>
                    <th class="text-center">Total Value</th>
                    <th style="width:28px;"></th>
                </tr>
            </thead>
            <tbody>`;
                rows.forEach((r, i) => {
                    const rid = `t3_${kunnr}_${r.VBELN}_${i}`;
                    const isSoSelected = Array.from(selectedItems).some(id => itemIdToSO.get(String(id)) ===
                        r.VBELN);
                    html += `
              <tr class="yz-row js-t2row" data-vbeln="${r.VBELN}" data-tgt="${rid}">
                <td class="text-center">
                  <input type="checkbox" class="form-check-input check-so"
                                data-vbeln="${r.VBELN}" onclick="event.stopPropagation()" onmousedown="event.stopPropagation()">
                </td>
                <td class="text-center">
                  <span class="yz-caret">▸</span> </td>
                <td class="yz-t2-vbeln text-start fw-bold text-primary">${r.VBELN}</td>
                <td class="text-center">${formatNumber(r.total_qty)}</td>
                <td class="text-center">${formatCurrency(r.total_value, r.WAERK)}</td>
                <td class="text-center"><span class="so-selected-dot" style="display:${isSoSelected?'inline-block':'none'};"></span></td>
              </tr>
              <tr id="${rid}" class="yz-nest" style="display:none;">
                <td colspan="6" class="p-0"> <div class="yz-nest-wrap level-2" style="margin-left:0; padding:.5rem;">
                    <div class="yz-slot-items p-2"></div>
                  </div>
                </td>
              </tr>`;
                });
                html += `</tbody></table></div>`;
                return html;
            }

            // ===== RENDER LEVEL 3 (Items) =====
            function renderLevel3_Items(rows) {
                if (!rows?.length)
                    return `<div class="p-2 text-muted">Tidak ada item detail untuk filter stok ini.</div>`;
                const stockHeader = TYPE === 'whfg' ? '<th>WHFG</th>' : '<th>Stock Packing</th>';
                const stockField = TYPE === 'whfg' ? 'KALAB' : 'KALAB2';
                let html = `<div class="table-responsive">
            <table class="table table-sm mb-0 yz-mini">
              <thead class="yz-header-item">
                <tr>
                  <th style="width:40px;"><input class="form-check-input check-all-items" type="checkbox" title="Pilih Semua Item"></th>
                  <th>Item</th><th>Material FG</th><th>Desc FG</th>
                  ${stockHeader}<th>Net Price</th><th>Total Value</th>
                </tr>
              </thead>
              <tbody>`;
                rows.forEach(r => {
                    const isChecked = selectedItems.has(String(r.id));
                    const stockVal = r[stockField];
                    html += `
              <tr data-item-id="${r.id}" data-vbeln="${r.VBELN}">
                <td><input class="form-check-input check-item" type="checkbox" data-id="${r.id}" ${isChecked?'checked':''}></td>
                <td>${r.POSNR ?? ''}</td>
                <td>${(r.MATNR || '').replace(/^0+/,'')}</td>
                <td>${r.MAKTX ?? ''}</td>
                <td>${formatNumber(stockVal)}</td>
                <td>${formatCurrency(r.NETPR, r.WAERK)}</td>
                <td>${formatCurrency(r.VALUE, r.WAERK)}</td>
              </tr>`;
                });
                html += `</tbody></table></div>`;
                return html;
            }

            function openItemsIfNeededForSORow(soRow) {
                const vbeln = soRow.dataset.vbeln;
                const nest = soRow?.nextElementSibling;
                const caret = soRow?.querySelector('td:nth-child(2) .yz-caret');
                if (!nest) return;
                if (nest.style.display === 'none') {
                    nest.style.display = '';
                    caret?.classList.add('rot');
                }
                const itemBox = nest.querySelector('.yz-slot-items');

                if (nest.dataset.loaded !== '1') {
                    itemBox.innerHTML = `<div class="p-2 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
                        <div class="spinner-border spinner-border-sm me-2"></div>Memuat item…
                    </div>`;

                    ensureItemsLoadedForSO(vbeln).then(items => {
                        itemBox.innerHTML = renderLevel3_Items(items);
                        applySelectionsToRenderedItems(itemBox);
                        nest.dataset.loaded = '1';
                    }).catch(e => {
                        itemBox.innerHTML = `<div class="alert alert-danger m-3">${e.message}</div>`;
                    });
                } else {
                    applySelectionsToRenderedItems(itemBox);
                }
            }

            function closeItemsForSORow(soRow) {
                const nest = soRow?.nextElementSibling;
                const caret = soRow?.querySelector('td:nth-child(2) .yz-caret');
                if (nest) {
                    nest.style.display = 'none';
                    caret?.classList.remove('rot');
                }
            }

            async function applyCollapseView(tbodyEl, on) {
                COLLAPSE_MODE = on;

                const headerCaret = tbodyEl.closest('table')?.querySelector(
                    '.js-collapse-toggle .yz-collapse-caret');
                if (headerCaret) headerCaret.textContent = on ? '▾' : '▸';

                tbodyEl.classList.remove('so-focus-mode');
                tbodyEl.classList.toggle('collapse-mode', on);

                if (on) {
                    let visibleCount = 0;
                    const rows = tbodyEl.querySelectorAll('.js-t2row');

                    for (const r of rows) {
                        const chk = r.querySelector('.check-so');
                        r.classList.remove('is-focused');

                        if (chk?.checked) {
                            r.style.display = '';
                            await openItemsIfNeededForSORow(r);
                            visibleCount++;
                        } else {
                            r.style.display = 'none';
                            closeItemsForSORow(r);
                        }
                    }

                    if (visibleCount === 0) {
                        await applyCollapseView(tbodyEl, false);
                        return;
                    }
                } else {
                    const rows = tbodyEl.querySelectorAll('.js-t2row');
                    rows.forEach(r => {
                        r.style.display = '';
                        r.classList.remove('is-focused');
                        closeItemsForSORow(r);
                    });
                }

                if (tbodyEl) syncSelectAllSoState(tbodyEl);
            }

            // ===== Expand/collapse Level-1 (Customer) -> Level-2 (SO) =====
            document.querySelectorAll('.yz-customer-card').forEach(row => {
                row.addEventListener('click', async () => {
                    const kunnr = row.dataset.kunnr;
                    const kid = row.dataset.kid;
                    const slot = document.getElementById(kid);
                    const wrap = slot.querySelector('.yz-nest-wrap');
                    const customerListContainer = document.getElementById(
                        'customer-list-container');

                    const wasOpen = row.classList.contains('is-open');

                    // 1. Logic Exclusive Toggle dan Focus Mode
                    document.querySelectorAll('.yz-customer-card.is-open').forEach(r => {
                        if (r !== row) {
                            const otherSlot = document.getElementById(r.dataset.kid);
                            r.classList.remove('is-open', 'is-focused');
                            r.querySelector('.kunnr-caret')?.classList.remove('rot');

                            const otherTable = otherSlot?.querySelector('table');
                            otherTable?.querySelector('tbody')?.classList.remove(
                                'so-focus-mode', 'collapse-mode');
                            otherTable?.querySelectorAll('.js-t2row').forEach(r =>
                                closeItemsForSORow(r));
                        }
                    });

                    // 2. Toggle status kartu saat ini
                    row.classList.toggle('is-open');
                    row.querySelector('.kunnr-caret')?.classList.toggle('rot', !wasOpen);

                    // 3. Keluar/masuk Customer Focus Mode dan Global Footer
                    if (!wasOpen) {
                        customerListContainer.classList.add('customer-focus-mode');
                        row.classList.add('is-focused');
                        if (globalFooter) globalFooter.style.display =
                            'none'; // Sembunyikan Total Global
                        COLLAPSE_MODE = false; // Reset mode kolaps
                    } else {
                        customerListContainer.classList.remove('customer-focus-mode');
                        row.classList.remove('is-focused');
                        if (globalFooter) globalFooter.style.display =
                            ''; // Tampilkan Total Global
                        COLLAPSE_MODE = false; // Reset mode kolaps
                    }

                    if (wasOpen) return;

                    // 4. Muat data SO (Level 2)
                    if (wrap.dataset.loaded === '1') {
                        // Re-bind listeners jika sudah dimuat
                        const soTable = wrap.querySelector('table.yz-mini');
                        const soTbody = soTable?.querySelector('tbody');
                        const collapseBtn = soTable?.querySelector('.js-collapse-toggle');
                        collapseBtn?.addEventListener('click', async (ev) => {
                            ev.stopPropagation();
                            await applyCollapseView(soTbody, !COLLAPSE_MODE);
                        });
                        wrap.querySelectorAll('.js-t2row').forEach(soRow => {
                            updateSODot(soRow.dataset.vbeln);
                        });
                        if (soTbody) syncSelectAllSoState(soTbody);
                        return;
                    }

                    try {
                        wrap.innerHTML = `<div class="p-3 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>Memuat data…
                        </div>`;
                        const url = new URL(apiSoByCustomer, window.location.origin);
                        url.searchParams.set('kunnr', kunnr);
                        url.searchParams.set('werks', WERKS);
                        url.searchParams.set('type', TYPE);

                        const res = await fetch(url);
                        const js = await res.json();
                        if (!js.ok) throw new Error(js.error || 'Gagal memuat data SO');

                        wrap.innerHTML = renderLevel2_SO(js.data, kunnr);
                        wrap.dataset.loaded = '1';

                        // 5. Bind Listeners Level 2
                        const soTable = wrap.querySelector('table.yz-mini');
                        const soTbody = soTable?.querySelector('tbody');
                        const collapseBtn = soTable?.querySelector('.js-collapse-toggle');

                        collapseBtn?.addEventListener('click', async (ev) => {
                            ev.stopPropagation();
                            await applyCollapseView(soTbody, !COLLAPSE_MODE);
                        });

                        wrap.querySelectorAll('.js-t2row').forEach(soRow => {
                            updateSODot(soRow.dataset.vbeln);

                            soRow.addEventListener('click', async (ev) => {
                                if (ev.target.closest('.form-check-input'))
                                    return;
                                ev.stopPropagation();
                                const open = soRow.nextElementSibling.style
                                    .display !== 'none';
                                const soTbody = soRow.closest('tbody');

                                if (open) {
                                    closeItemsForSORow(soRow);
                                    soTbody?.classList.remove(
                                        'so-focus-mode');
                                    soRow.classList.remove('is-focused');
                                    return;
                                }

                                await openItemsIfNeededForSORow(soRow);
                                soTbody?.classList.add('so-focus-mode');
                                soRow.classList.add('is-focused');
                            });
                        });
                        if (soTbody) syncSelectAllSoState(soTbody);

                    } catch (e) {
                        wrap.innerHTML =
                            `<div class="alert alert-danger m-3">${e.message}</div>`;
                    }
                });
            });

            // ===== Cegah checkbox memicu toggle baris (Level 2 & 3) =====
            document.body.addEventListener('click', (e) => {
                if (e.target.closest('.check-so') || e.target.closest('.check-all-sos') || e.target.closest(
                        '.check-item') || e.target.closest('.check-all-items')) {
                    e.stopPropagation();
                }
            }, true);


            // ===== Change events (pilih SO / Item) =====
            document.body.addEventListener('change', async (e) => {
                // 1. Select all items (Level 3)
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
                            const soRow = document.querySelector(`.js-t2row[data-vbeln='${vbeln}']`);
                            const tbody = soRow?.closest('tbody');
                            if (tbody) syncSelectAllSoState(tbody);
                        }
                    }
                    updateExportButton();
                    return;
                }

                // 2. Pilih item tunggal (Level 3)
                if (e.target.classList.contains('check-item')) {
                    const id = e.target.dataset.id;
                    if (e.target.checked) selectedItems.add(id);
                    else selectedItems.delete(id);

                    const box = e.target.closest('.yz-slot-items') || document;
                    syncSelectAllItemsState(box);

                    const vbeln = itemIdToSO.get(String(id));
                    if (vbeln) {
                        updateSODot(vbeln);
                        const soRow = document.querySelector(`.js-t2row[data-vbeln='${vbeln}']`);
                        const tbody = soRow?.closest('tbody');
                        if (tbody) syncSelectAllSoState(tbody);
                    }
                    updateExportButton();
                    return;
                }

                // 3. Select All SO (Level 2)
                if (e.target.classList.contains('check-all-sos')) {
                    const tbody = e.target.closest('table')?.querySelector('tbody');
                    if (!tbody) return;
                    const allSO = tbody.querySelectorAll('.check-so');

                    for (const chk of allSO) {
                        chk.checked = e.target.checked;
                        const vbeln = chk.dataset.vbeln;

                        const items = await ensureItemsLoadedForSO(vbeln);
                        if (e.target.checked) {
                            items.forEach(it => selectedItems.add(String(it.id)));
                        } else {
                            Array.from(selectedItems).forEach(id => {
                                if (itemIdToSO.get(String(id)) === vbeln) selectedItems.delete(
                                    id);
                            });
                        }
                        updateSODot(vbeln);
                    }
                    if (tbody) syncSelectAllSoState(tbody);
                    if (COLLAPSE_MODE) await applyCollapseView(tbody, true);
                    updateExportButton();
                    return;
                }

                // 4. Pilih SO tunggal (Level 2)
                if (e.target.classList.contains('check-so')) {
                    const vbeln = e.target.dataset.vbeln;
                    const items = await ensureItemsLoadedForSO(vbeln);

                    if (e.target.checked) {
                        items.forEach(it => selectedItems.add(String(it.id)));
                    } else {
                        Array.from(selectedItems).forEach(id => {
                            if (itemIdToSO.get(String(id)) === vbeln) selectedItems.delete(id);
                        });
                    }

                    updateSODot(vbeln);
                    const tbody = e.target.closest('tbody');
                    if (tbody) syncSelectAllSoState(tbody);

                    if (COLLAPSE_MODE) await applyCollapseView(tbody, true);

                    updateExportButton();
                    return;
                }
            });

            // ===== Export (opsional) =====
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

                    const addHidden = (n, v) => {
                        const i = document.createElement('input');
                        i.type = 'hidden';
                        i.name = n;
                        i.value = v;
                        form.appendChild(i);
                    };
                    addHidden('_token', csrfToken);
                    addHidden('export_type', exportType);
                    addHidden('werks', WERKS);
                    addHidden('type', TYPE);
                    selectedItems.forEach(id => addHidden('item_ids[]', id));

                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);
                });
            }
        });
    </script>
@endpush
