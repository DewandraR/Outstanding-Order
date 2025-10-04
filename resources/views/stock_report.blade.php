@extends('layouts.app')

@section('title', 'Laporan Stok')

@section('content')

    @php
        // Menginisialisasi variabel yang diperlukan dari controller
        $selectedWerks = $selected['werks'] ?? null;
        $selectedType = $selected['type'] ?? null;

        $locationMap = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locName = $locationMap[$selectedWerks] ?? $selectedWerks;

        // helpers
        $fmtNumber = function ($n, $d = 0) {
            return number_format((float) $n, $d, ',', '.');
        };
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

        use Illuminate\Support\Facades\Crypt;
    @endphp

    <div id="stock-root" data-werks="{{ $selectedWerks ?? '' }}" data-type="{{ $selectedType ?? '' }}" style="display:none">
    </div>

    {{-- =========================================================
    HEADER: Pills (Stock Type) • Collabs • Export Items
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
                    <i class="fas fa-info-circle me-2"></i> Pilih Plant (Surabaya/Semarang) dari sidebar untuk memulai.
                @endif
            </div>

            {{-- Kanan: Collabs + Export Items --}}
            <div class="py-1 d-flex align-items-center gap-2">

                {{-- [NEW] Collabs --}}
                <div id="collabs-container" class="d-flex gap-2" style="display:none;">
                    <button class="btn btn-collabs d-inline-flex align-items-center" type="button" id="btn-collabs">
                        <i class="fas fa-layer-group me-2"></i>
                        Buka Item SO Terpilih
                        <span class="badge rounded-pill bg-collabs ms-2" id="collabs-count-badge">0</span>
                    </button>
                    <button class="btn btn-collabs-exit d-inline-flex align-items-center" type="button"
                        id="btn-collabs-exit" style="display:none;">
                        <i class="fas fa-compress me-2"></i>
                        Keluar Mode
                    </button>
                </div>

                {{-- Export Items (dropdown) --}}
                <div class="dropdown" id="export-dropdown-container" style="display:none;">
                    <button class="btn btn-primary dropdown-toggle" type="button" id="export-btn" data-bs-toggle="dropdown"
                        aria-expanded="false">
                        <i class="fas fa-file-export me-2"></i>
                        Export Items (<span id="selected-count">0</span>)
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="export-btn">
                        <li><a class="dropdown-item export-option" href="#" data-type="pdf">
                                <i class="fas fa-file-pdf text-danger me-2"></i>Export to PDF</a>
                        </li>
                        <li><a class="dropdown-item export-option" href="#" data-type="excel">
                                <i class="fas fa-file-excel text-success me-2"></i>Export to Excel</a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {{-- =========================
      TABEL UTAMA (Overview Customer)
      ========================= --}}
    @if ($rows)
        <div class="card yz-card shadow-sm">
            <div class="card-body p-0 p-md-2">

                <div class="p-3 mx-md-3 mt-md-3 yz-main-title-wrapper">
                    <h5 class="yz-table-title mb-0">
                        <i class="fas fa-users me-2"></i>Overview Customer
                        @if ($selectedWerks)
                            <span class="text-muted small ms-2">— {{ $locName }}</span>
                        @endif
                    </h5>
                </div>

                <div class="table-responsive yz-table px-md-3">
                    <table class="table table-hover mb-0 align-middle yz-grid">
                        <thead class="yz-header-customer">
                            <tr>
                                <th style="width:50px;"></th>
                                <th class="text-start" style="min-width:250px;">Customer</th>
                                <th style="min-width:150px; text-align:center;">Total Stock Qty</th>
                                <th style="min-width:150px; text-align:center;">Total Value</th>
                            </tr>
                        </thead>

                        <tbody>
                            @forelse ($rows as $r)
                                @php $kid = 'krow_'.$r->KUNNR.'_'.$loop->index; @endphp
                                <tr class="yz-kunnr-row" data-kunnr="{{ $r->KUNNR }}" data-kid="{{ $kid }}"
                                    title="Klik untuk melihat detail">
                                    <td class="sticky-col-mobile-disabled">
                                        <span class="kunnr-caret"><i class="fas fa-chevron-right"></i></span>
                                    </td>
                                    <td class="sticky-col-mobile-disabled text-start">
                                        <span class="fw-bold">{{ $r->NAME1 }}</span>
                                    </td>
                                    <td class="text-center">{{ $fmtNumber($r->TOTAL_QTY) }}</td>
                                    <td class="text-center">@php echo $fmtMoney($r->TOTAL_VALUE, $r->WAERK); @endphp</td>
                                </tr>
                                <tr id="{{ $kid }}" class="yz-nest" style="display:none;">
                                    <td colspan="4" class="p-0">
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
                                    <td colspan="4" class="text-center p-5">
                                        <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">Data tidak ditemukan</h5>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>

                        @if ($rows && $rows->count())
                            <tfoot>
                                <tr class="table-light">
                                    <th></th>
                                    <th class="text-start"><span class="fw-bold">TOTAL</span></th>
                                    <th class="text-center">{{ $fmtNumber($grandTotalQty ?? 0) }}</th>
                                    <th class="text-center">
                                        @if (!empty($grandTotalsCurr))
                                            @foreach ($grandTotalsCurr as $curr => $val)
                                                <div class="small">{{ $fmtMoney($val, $curr) }}</div>
                                            @endforeach
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </th>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>

                {{-- pagination --}}
                @if ($rows)
                    <div class="px-3 py-2">
                        {{ $rows->links() }}
                    </div>
                @endif
            </div>
        </div>
    @endif
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard-style.css') }}">
    <style>
        .yz-caret {
            display: inline-block;
            transition: transform .18s ease;
            user-select: none
        }

        .yz-caret.rot {
            transform: rotate(90deg)
        }

        tbody.customer-focus-mode~tfoot {
            display: none !important
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

        /* [NEW] Collabs helpers */
        .collabs-hidden {
            display: none !important
        }

        /* [NEW] Tombol Collabs */
        .btn-collabs {
            --c1: #22c55e;
            --c2: #16a34a;
            background: linear-gradient(180deg, var(--c1), var(--c2));
            color: #fff;
            border: 0;
            box-shadow: 0 4px 12px rgba(34, 197, 94, .25);
        }

        .btn-collabs:hover {
            filter: brightness(.98);
            color: #fff
        }

        .btn-collabs:focus {
            outline: 0;
            box-shadow: 0 0 0 .2rem rgba(34, 197, 94, .35)
        }

        .btn-collabs-exit {
            background: #eef2ff;
            color: #3730a3;
            border: 1px solid #c7d2fe
        }

        .btn-collabs-exit:hover {
            background: #e0e7ff;
            color: #312e81
        }

        .btn-collabs-exit:focus {
            outline: 0;
            box-shadow: 0 0 0 .2rem rgba(99, 102, 241, .25)
        }

        .bg-collabs {
            background: #065f46 !important
        }

        /* emerald-800 */
    </style>
@endpush

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- Constants & State ---
            const apiSoByCustomer = "{{ route('stock.api.by_customer') }}";
            const apiItemsBySo = "{{ route('stock.api.by_items') }}";
            const exportUrl = "{{ route('stock.export') }}";
            const csrfToken = "{{ csrf_token() }}";

            const root = document.getElementById('stock-root');
            const WERKS = (root?.dataset.werks || '').trim();
            const TYPE = (root?.dataset.type || '').trim();

            const exportDropdownContainer = document.getElementById('export-dropdown-container');
            const selectedCountSpan = document.getElementById('selected-count');
            const selectedItems = new Set();
            const itemIdToSO = new Map(); // itemId -> vbeln
            const itemsCache = new Map(); // vbeln -> array items

            // [NEW] Collabs UI state
            const collabsContainer = document.getElementById('collabs-container');
            const collabsCountSpan = document.getElementById('collabs-count-badge');
            const btnCollabs = document.getElementById('btn-collabs');
            const btnCollabsExit = document.getElementById('btn-collabs-exit');
            let collabsMode = false;

            // --- Helpers ---
            function updateExportButton() {
                const count = selectedItems.size;
                selectedCountSpan.textContent = count;
                exportDropdownContainer.style.display = count > 0 ? 'block' : 'none';
            }

            function updateSODot(vbeln) {
                const anySelected = Array.from(selectedItems).some(id => itemIdToSO.get(String(id)) === vbeln);
                document.querySelectorAll(`.js-t2row[data-vbeln='${vbeln}'] .so-selected-dot`)
                    .forEach(dot => dot.style.display = anySelected ? 'inline-block' : 'none');
            }

            function applySelectionsToRenderedItems(container) {
                container.querySelectorAll('.check-item').forEach(chk => {
                    if (selectedItems.has(chk.dataset.id)) chk.checked = true;
                });
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
            const formatCurrency = (value, currency, decimals = 2) => {
                const n = parseFloat(value);
                if (!Number.isFinite(n)) return '';
                const opt = {
                    minimumFractionDigits: decimals,
                    maximumFractionDigits: decimals
                };
                if (currency === 'IDR') return `Rp ${n.toLocaleString('id-ID',opt)}`;
                if (currency === 'USD') return `$${n.toLocaleString('en-US',opt)}`;
                return `${currency} ${n.toLocaleString('id-ID',opt)}`;
            };
            const formatNumber = (num, decimals = 0) => {
                const n = parseFloat(num);
                if (!Number.isFinite(n)) return '';
                return n.toLocaleString('id-ID', {
                    minimumFractionDigits: decimals,
                    maximumFractionDigits: decimals
                });
            };

            // [NEW] Collabs helpers
            const countCheckedSO = () =>
                document.querySelectorAll('.yz-nest-wrap .check-so:checked').length;

            const setCollabsUI = () => {
                const showBar = collabsMode || countCheckedSO() > 0;
                if (collabsContainer) collabsContainer.style.display = showBar ? 'block' : 'none';
                if (btnCollabs) btnCollabs.style.display = collabsMode ? 'none' : 'inline-flex';
                if (btnCollabsExit) btnCollabsExit.style.display = collabsMode ? 'inline-flex' : 'none';
            };
            const updateCollabsButton = () => {
                const n = countCheckedSO();
                if (collabsCountSpan) collabsCountSpan.textContent = n;
                setCollabsUI();
            };

            // ===== RENDER LEVEL 2 =====
            function renderLevel2_SO(rows, kunnr) {
                if (!rows?.length)
                    return `<div class="p-3 text-muted">Tidak ada data Outstanding SO untuk customer ini.</div>`;
                let html = `
                <h5 class="yz-table-title-nested yz-title-so"><i class="fas fa-file-invoice me-2"></i>Outstanding SO</h5>
                <table class="table table-sm mb-0 yz-mini">
                  <thead class="yz-header-so">
                    <tr>
                      <th style="width:40px;" class="text-center">
                        <input type="checkbox" class="form-check-input check-all-sos"
                               title="Pilih semua SO"
                               onclick="event.stopPropagation()"
                               onmousedown="event.stopPropagation()">
                      </th>
                      <th style="width:40px;"></th>
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
                               data-vbeln="${r.VBELN}"
                               onclick="event.stopPropagation()"
                               onmousedown="event.stopPropagation()">
                      </td>
                      <td class="text-center"><span class="yz-caret">▸</span></td>
                      <td class="yz-t2-vbeln text-start">${r.VBELN}</td>
                      <td class="text-center">${formatNumber(r.total_qty)}</td>
                      <td class="text-center">${formatCurrency(r.total_value, r.WAERK)}</td>
                      <td class="text-center"><span class="so-selected-dot" style="display:${isSoSelected?'inline-block':'none'};"></span></td>
                    </tr>
                    <tr id="${rid}" class="yz-nest" style="display:none;">
                      <td colspan="6" class="p-0">
                        <div class="yz-nest-wrap level-2" style="margin-left:0; padding:.5rem;">
                          <div class="yz-slot-items p-2"></div>
                        </div>
                      </td>
                    </tr>`;
                });
                html += `</tbody></table>`;
                return html;
            }

            // ===== RENDER LEVEL 3 =====
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
                    const stockCellValue = r[stockField];
                    html += `
                      <tr data-item-id="${r.id}" data-vbeln="${r.VBELN}">
                        <td><input class="form-check-input check-item" type="checkbox" data-id="${r.id}" ${isChecked?'checked':''}></td>
                        <td>${r.POSNR ?? ''}</td>
                        <td>${(r.MATNR || '').replace(/^0+/, '')}</td>
                        <td>${r.MAKTX ?? ''}</td>
                        <td>${formatNumber(stockCellValue)}</td>
                        <td>${formatCurrency(r.NETPR, r.WAERK)}</td>
                        <td>${formatCurrency(r.VALUE, r.WAERK)}</td>
                      </tr>`;
                });
                html += `</tbody></table></div>`;
                return html;
            }

            // ===== Expand/collapse: Level-1 (Customer) -> Level-2 (SO) =====
            document.querySelectorAll('.yz-kunnr-row').forEach(row => {
                row.addEventListener('click', async () => {
                    const kunnr = row.dataset.kunnr;
                    const kid = row.dataset.kid;
                    const slot = document.getElementById(kid);
                    const wrap = slot.querySelector('.yz-nest-wrap');

                    const tbodyEl = row.closest('tbody');
                    const tableEl = row.closest('table');
                    const tfootEl = tableEl?.querySelector('tfoot');

                    const wasOpen = row.classList.contains('is-open');

                    if (!wasOpen) {
                        tbodyEl.classList.add('customer-focus-mode');
                        row.classList.add('is-focused');
                    } else {
                        tbodyEl.classList.remove('customer-focus-mode');
                        row.classList.remove('is-focused');
                    }

                    row.classList.toggle('is-open');

                    // tampil/sembunyi nested row utama
                    slot.style.display = wasOpen ? 'none' : '';

                    // saat menutup customer, paksa tutup semua nested (T2->T3) di dalamnya
                    if (wasOpen) {
                        wrap?.querySelectorAll('tr.yz-nest').forEach(tr => tr.style.display =
                            'none');
                        wrap?.querySelectorAll('tbody.so-focus-mode').forEach(tb => tb.classList
                            .remove('so-focus-mode'));
                        wrap?.querySelectorAll('.js-t2row.is-focused').forEach(r => r.classList
                            .remove('is-focused'));
                        wrap?.querySelectorAll('.js-t2row .yz-caret.rot').forEach(c => c
                            .classList.remove('rot'));
                    }

                    // Kontrol footer (hanya sembunyikan bila ada nest terlihat)
                    const anyVisibleNest = [...tableEl.querySelectorAll('tr.yz-nest')]
                        .some(tr => tr.style.display !== 'none' && tr.offsetParent !== null);
                    if (tfootEl) tfootEl.style.display = anyVisibleNest ? 'none' : '';

                    if (wasOpen) {
                        updateCollabsButton();
                        return;
                    }

                    // Sudah pernah dimuat -> selesai
                    if (wrap.dataset.loaded === '1') {
                        updateCollabsButton();
                        return;
                    }

                    // Fetch Level-2
                    try {
                        wrap.innerHTML = `
                          <div class="p-3 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
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

                        // Bind Level-3 (SO -> Items)
                        wrap.querySelectorAll('.js-t2row').forEach(soRow => {
                            updateSODot(soRow.dataset.vbeln);
                            soRow.addEventListener('click', async (ev) => {
                                // klik baris T2 untuk expand/collapse, cegah bubble
                                ev.stopPropagation();
                                const vbeln = soRow.dataset.vbeln;
                                const tgtId = soRow.dataset.tgt;
                                const itemTr = wrap.querySelector('#' +
                                    tgtId);
                                const itemBox = itemTr.querySelector(
                                    '.yz-slot-items');
                                const open = itemTr.style.display !==
                                    'none';
                                const soTbody = soRow.closest('tbody');

                                soRow.querySelector('.yz-caret')?.classList
                                    .toggle('rot');

                                if (soTbody) {
                                    if (!open) {
                                        soTbody.classList.add(
                                            'so-focus-mode');
                                        soRow.classList.add('is-focused');
                                    } else {
                                        soTbody.classList.remove(
                                            'so-focus-mode');
                                        soRow.classList.remove(
                                            'is-focused');
                                    }
                                }

                                if (open) {
                                    itemTr.style.display = 'none';
                                    return;
                                }

                                itemTr.style.display = '';
                                if (itemTr.dataset.loaded === '1') {
                                    applySelectionsToRenderedItems(itemBox);
                                    return;
                                }

                                itemBox.innerHTML = `<div class="p-2 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
                                    <div class="spinner-border spinner-border-sm me-2"></div>Memuat item…</div>`;
                                try {
                                    const items =
                                        await ensureItemsLoadedForSO(vbeln);
                                    itemBox.innerHTML = renderLevel3_Items(
                                        items);
                                    applySelectionsToRenderedItems(itemBox);
                                    itemTr.dataset.loaded = '1';
                                } catch (e) {
                                    itemBox.innerHTML =
                                        `<div class="alert alert-danger m-3">${e.message}</div>`;
                                }
                            });
                        });

                        updateCollabsButton();
                    } catch (e) {
                        wrap.innerHTML =
                            `<div class="alert alert-danger m-3">${e.message}</div>`;
                    }
                });
            });

            // ===== Cegah checkbox Tabel-2 memicu expand Tabel-3 =====
            document.body.addEventListener('click', function(e) {
                if (e.target.classList.contains('check-so') || e.target.classList.contains(
                        'check-all-sos')) {
                    e.stopPropagation();
                }
            }, true);

            // ===== Change events (pilih SO/Item) =====
            document.body.addEventListener('change', async function(e) {
                // Pilih semua item dalam T3
                if (e.target.classList.contains('check-all-items')) {
                    const table = e.target.closest('table');
                    if (!table) return;
                    const itemCheckboxes = table.querySelectorAll('.check-item');
                    itemCheckboxes.forEach(checkbox => {
                        checkbox.checked = e.target.checked;
                        const id = checkbox.dataset.id;
                        if (e.target.checked) selectedItems.add(id);
                        else selectedItems.delete(id);
                    });
                    const anyItem = table.querySelector('.check-item');
                    if (anyItem) {
                        const vbeln = itemIdToSO.get(String(anyItem.dataset.id));
                        if (vbeln) updateSODot(vbeln);
                    }
                    updateExportButton();
                    return;
                }

                // 1) Pilih semua SO (dalam T2 pada customer tsb)
                if (e.target.classList.contains('check-all-sos')) {
                    const tbody = e.target.closest('table')?.querySelector('tbody');
                    if (!tbody) return;
                    const allSO = tbody.querySelectorAll('.check-so');

                    for (const chk of allSO) {
                        chk.checked = e.target.checked;
                        const vbeln = chk.dataset.vbeln;
                        const items = await ensureItemsLoadedForSO(vbeln);

                        if (e.target.checked) items.forEach(it => selectedItems.add(String(it.id)));
                        else Array.from(selectedItems).forEach(id => {
                            if (itemIdToSO.get(String(id)) === vbeln) selectedItems.delete(id);
                        });

                        updateSODot(vbeln);

                        const nest = document.querySelector(`tr.js-t2row[data-vbeln='${vbeln}']`)
                            ?.nextElementSibling;
                        const box = nest?.querySelector('.yz-slot-items');
                        if (box) box.querySelectorAll('.check-item').forEach(ch => ch.checked = e.target
                            .checked);
                    }
                    updateExportButton();
                    updateCollabsButton();
                    return;
                }

                // 2) Pilih satu SO
                if (e.target.classList.contains('check-so')) {
                    const vbeln = e.target.dataset.vbeln;
                    const items = await ensureItemsLoadedForSO(vbeln);

                    if (e.target.checked) items.forEach(it => selectedItems.add(String(it.id)));
                    else Array.from(selectedItems).forEach(id => {
                        if (itemIdToSO.get(String(id)) === vbeln) selectedItems.delete(id);
                    });

                    updateSODot(vbeln);
                    updateExportButton();
                    updateCollabsButton();

                    const nest = document.querySelector(`tr.js-t2row[data-vbeln='${vbeln}']`)
                        ?.nextElementSibling;
                    const box = nest?.querySelector('.yz-slot-items');
                    if (box) box.querySelectorAll('.check-item').forEach(ch => ch.checked = e.target
                        .checked);
                    return;
                }

                // 3) Pilih item
                if (e.target.classList.contains('check-item')) {
                    const id = e.target.dataset.id;
                    if (e.target.checked) selectedItems.add(id);
                    else selectedItems.delete(id);
                    const vbeln = itemIdToSO.get(String(id));
                    if (vbeln) updateSODot(vbeln);
                    updateExportButton();
                    return;
                }
            });

            // ===== Export =====
            if (exportDropdownContainer) {
                exportDropdownContainer.addEventListener('click', function(e) {
                    const exportOption = e.target.closest('.export-option');
                    if (!exportOption) return;
                    e.preventDefault();
                    const exportType = exportOption.dataset.type;
                    if (selectedItems.size === 0) {
                        alert('Pilih setidaknya satu item untuk diekspor.');
                        return;
                    }
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = exportUrl;
                    form.target = '_blank';

                    const addHidden = (name, value) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        input.value = value;
                        form.appendChild(input);
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

            // ===== Aksesibilitas label kolom saat mobile =====
            document.querySelectorAll('.yz-kunnr-row').forEach(row => {
                row.querySelector('td:nth-child(2)')?.setAttribute('data-label', 'Customer');
                row.querySelector('td:nth-child(3)')?.setAttribute('data-label', 'Total Stock Qty');
                row.querySelector('td:nth-child(4)')?.setAttribute('data-label', 'Total Value');
            });

            // ===== [NEW] COLLABS: masuk/keluar mode =====
            function enterCollabsMode() {
                collabsMode = true;
                setCollabsUI();

                // Sembunyikan customer tanpa SO terpilih
                document.querySelectorAll('.yz-kunnr-row').forEach(krow => {
                    const nxt = document.getElementById(krow.dataset.kid);
                    const wrap = nxt?.querySelector('.yz-nest-wrap');
                    if (!wrap) return;

                    const anyChecked = wrap.querySelector('.check-so:checked');
                    if (!anyChecked) {
                        krow.classList.add('collabs-hidden');
                        nxt.classList.add('collabs-hidden');
                    } else {
                        krow.classList.remove('collabs-hidden');
                        nxt.classList.remove('collabs-hidden');
                    }
                });

                // Hanya tampilkan SO yang dicentang, buka T3, tandai collabsOpen
                document.querySelectorAll('.yz-nest-wrap').forEach(async (wrap) => {
                    // hide semua SO
                    wrap.querySelectorAll('.js-t2row').forEach(row => {
                        row.classList.add('collabs-hidden');
                        const nest = row.nextElementSibling;
                        if (nest?.classList.contains('yz-nest')) nest.classList.add(
                            'collabs-hidden');
                    });

                    const checked = wrap.querySelectorAll('.check-so:checked');
                    for (const chk of checked) {
                        const soRow = chk.closest('.js-t2row');
                        if (!soRow) continue;

                        // tampilkan baris + nest
                        soRow.classList.remove('collabs-hidden');
                        const tgt = soRow.nextElementSibling; // tr.yz-nest
                        const caret = soRow.querySelector('.yz-caret');

                        if (tgt && tgt.style.display === 'none') {
                            tgt.style.display = '';
                            tgt.dataset.collabsOpen = '1';
                            caret?.classList.add('rot');
                        }
                        tgt?.classList.remove('collabs-hidden');

                        // Load T3 bila belum
                        if (tgt && tgt.dataset.loaded !== '1') {
                            const vbeln = (chk.dataset.vbeln || '').trim();
                            const box = tgt.querySelector('.yz-slot-items');
                            if (box) {
                                box.innerHTML = `
                                  <div class="p-2 text-muted small yz-loader-pulse">
                                    <div class="spinner-border spinner-border-sm me-2"></div>Memuat item…
                                  </div>`;
                                const u3 = new URL(apiItemsBySo, window.location.origin);
                                u3.searchParams.set('vbeln', vbeln);
                                u3.searchParams.set('werks', WERKS);
                                u3.searchParams.set('type', TYPE);

                                try {
                                    const r3 = await fetch(u3);
                                    const j3 = await r3.json();
                                    if (j3?.ok) {
                                        j3.data.forEach(x => itemIdToSO.set(String(x.id), vbeln));
                                        itemsCache.set(vbeln, j3.data);
                                        box.innerHTML = renderLevel3_Items(j3.data);
                                        applySelectionsToRenderedItems(box);
                                        tgt.dataset.loaded = '1';
                                    } else {
                                        box.innerHTML =
                                            `<div class="alert alert-danger m-2">Gagal memuat detail item</div>`;
                                    }
                                } catch (_) {
                                    box.innerHTML =
                                        `<div class="alert alert-danger m-2">Gagal memuat detail item</div>`;
                                }
                            }
                        }
                    }
                });

                // Scroll ke SO terpilih pertama
                const first = document.querySelector('.yz-nest-wrap .check-so:checked')?.closest('.js-t2row')
                    ?.nextElementSibling;
                first?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }

            function exitCollabsMode() {
                collabsMode = false;
                setCollabsUI();

                // Tampilkan semua elemen yang tersembunyi saat collabs
                document.querySelectorAll('.collabs-hidden').forEach(el => el.classList.remove('collabs-hidden'));

                // Tutup hanya T3 yang dibuka oleh collabs
                document.querySelectorAll('tr.yz-nest[data-collabs-open="1"]').forEach(tgt => {
                    tgt.style.display = 'none';
                    tgt.dataset.collabsOpen = '';
                    const soRow = tgt.previousElementSibling;
                    soRow?.querySelector('.yz-caret')?.classList.remove('rot');
                });
            }

            // Hook tombol collabs
            if (btnCollabs) btnCollabs.addEventListener('click', () => {
                if (countCheckedSO() === 0) {
                    alert('Pilih minimal 1 SO di Tabel-2.');
                    return;
                }
                enterCollabsMode();
            });
            if (btnCollabsExit) btnCollabsExit.addEventListener('click', () => exitCollabsMode());

            // Init collabs bar visbility
            setCollabsUI();
        });
    </script>
@endpush
