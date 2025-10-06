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

        // total Outs. Qty halaman (Σ outstanding qty per customer)
        $totalQtyAll = (float) $rowsCol->sum(fn($r) => (float) ($r->TOTAL_OUTS_QTY ?? 0));

        // total "Outs. Value" (semua outstanding value) per currency
        $pageTotalsAll = $rowsCol
            ->groupBy(fn($r) => $r->WAERK ?? '')
            ->map(fn($g) => (float) $g->sum(fn($x) => (float) ($x->TOTAL_ALL_VALUE ?? 0)));

        // total "Overdue Value" (hanya yang telat) per currency
        $pageTotalsOverdue = $rowsCol
            ->groupBy(fn($r) => $r->WAERK ?? '')
            ->map(fn($g) => (float) $g->sum(fn($x) => (float) ($x->TOTAL_OVERDUE_VALUE ?? 0)));

        $formatTotals = function ($totals) {
            if (!$totals || count($totals) === 0) {
                return '—';
            }
            $parts = [];
            foreach ($totals as $cur => $sum) {
                if ($cur === 'IDR') {
                    $parts[] = 'Rp ' . number_format($sum, 2, ',', '.');
                } elseif ($cur === 'USD') {
                    $parts[] = '$' . number_format($sum, 2, '.', ',');
                } else {
                    $parts[] = ($cur ?? '') . ' ' . number_format($sum, 2, ',', '.');
                }
            }
            return implode(' | ', $parts);
        };
    @endphp

    {{-- Root state untuk JS --}}
    <div id="yz-root" data-show="{{ $show ? 1 : 0 }}" data-werks="{{ $werks ?? '' }}" data-auart="{{ $auart ?? '' }}"
        style="display:none"></div>

    {{-- =========================================================
   HEADER: PILIH TYPE + EXPORT
========================================================= --}}
    @if (filled($werks))
        @php
            $typesForPlant = collect($mapping[$werks] ?? []);
            $selectedAuart = trim((string) ($auart ?? ''));
        @endphp

        <div class="card yz-card shadow-sm mb-3 overflow-visible">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                {{-- Kiri: pills PO Type --}}
                <div class="py-1">
                    @if ($typesForPlant->count())
                        <ul class="nav nav-pills yz-auart-pills p-1 flex-wrap" style="border-radius:.75rem;">
                            @foreach ($typesForPlant as $t)
                                @php
                                    $auartCode = trim((string) $t->IV_AUART);
                                    $isActive = $selectedAuart === $auartCode;
                                    $pillUrl = $encReport(['werks' => $werks, 'auart' => $auartCode, 'compact' => 1]);
                                @endphp
                                <li class="nav-item mb-2 me-2">
                                    <a class="nav-link pill-green {{ $isActive ? 'active' : '' }}"
                                        href="{{ $pillUrl }}">
                                        {{ $t->Deskription }}
                                    </a>
                                </li>
                            @endforeach
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
                                <th class="text-center" style="min-width:120px;">Overdue PO</th>
                                <th class="text-center" style="min-width:120px;">Outs. Qty</th>
                                <th class="text-center" style="min-width:150px;">Outs. Value</th>
                                <th class="text-center" style="min-width:160px;">Overdue Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $r)
                                @php
                                    $kid = 'krow_' . $r->KUNNR . '_' . $loop->index;

                                    // Kolom baru dari controller:
                                    // - $r->TOTAL_OUTS_QTY (Σ QTY_BALANCE2 semua item outstanding customer)
                                    // - $r->TOTAL_ALL_VALUE (Σ TOTPR semua outstanding customer)
                                    // - $r->TOTAL_OVERDUE_VALUE (Σ TOTPR outstanding yang telat saja)
                                    $outsQtyAll = (float) ($r->TOTAL_OUTS_QTY ?? 0);
                                    $outsValueAll = (float) ($r->TOTAL_ALL_VALUE ?? 0);
                                    $overdueValue = (float) ($r->TOTAL_OVERDUE_VALUE ?? 0);
                                @endphp
                                <tr class="yz-kunnr-row" data-kunnr="{{ $r->KUNNR }}" data-kid="{{ $kid }}"
                                    title="Klik untuk melihat detail pesanan">
                                    <td class="sticky-col-mobile-disabled">
                                        <span class="kunnr-caret"><i class="fas fa-chevron-right"></i></span>
                                    </td>
                                    <td class="sticky-col-mobile-disabled text-start">
                                        <span class="fw-bold">{{ $r->NAME1 }}</span>
                                    </td>
                                    <td class="text-center">{{ $r->SO_LATE_COUNT }}</td>
                                    <td class="text-center">{{ number_format($outsQtyAll, 0, ',', '.') }}</td>
                                    <td class="text-center">
                                        @php
                                            if (($r->WAERK ?? '') === 'IDR') {
                                                echo 'Rp ' . number_format($outsValueAll, 2, ',', '.');
                                            } elseif (($r->WAERK ?? '') === 'USD') {
                                                echo '$' . number_format($outsValueAll, 2, '.', ',');
                                            } else {
                                                echo ($r->WAERK ?? '') .
                                                    ' ' .
                                                    number_format($outsValueAll, 2, ',', '.');
                                            }
                                        @endphp
                                    </td>
                                    <td class="text-center">
                                        @php
                                            if (($r->WAERK ?? '') === 'IDR') {
                                                echo 'Rp ' . number_format($overdueValue, 2, ',', '.');
                                            } elseif (($r->WAERK ?? '') === 'USD') {
                                                echo '$' . number_format($overdueValue, 2, '.', ',');
                                            } else {
                                                echo ($r->WAERK ?? '') .
                                                    ' ' .
                                                    number_format($overdueValue, 2, ',', '.');
                                            }
                                        @endphp
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
                                <th colspan="2" class="text-end">Total</th>
                                <th class="text-center"></th>
                                <th class="text-center">{{ number_format($totalQtyAll, 0, ',', '.') }}</th>
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
    @elseif ($onlyWerksSelected)
        {{-- Hanya plant dipilih --}}
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            Silakan pilih <strong>Type</strong> pada tombol hijau di atas.
        </div>
    @else
        {{-- Belum ada yang dipilih --}}
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Silakan pilih <strong>Plant</strong> dari sidebar untuk menampilkan Laporan PO.
        </div>
    @endif

@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard-style.css') }}">
    <style>
        tbody.customer-focus-mode~tfoot.yz-footer-customer {
            display: none !important;
        }

        .yz-row-highlight-negative>td {
            background: #ffe5e5 !important;
        }

        .table-hover tbody tr.yz-row-highlight-negative:hover>td {
            background: #ffd6d6 !important;
        }

        .yz-caret {
            display: inline-block;
            transition: transform .18s ease;
            user-select: none;
        }

        .yz-caret.rot {
            transform: rotate(90deg);
        }

        .so-selected-dot {
            height: 8px;
            width: 8px;
            background: #0d6efd;
            border-radius: 50%;
            display: none;
        }
    </style>
@endpush

@push('scripts')
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
            return `${(c || '')} ${n.toLocaleString('id-ID', o)}`;
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

        /* ====================== STATE EXPORT (berbasis item) ====================== */
        const selectedItems = new Set(); // item ids (from T3)
        const itemIdToSO = new Map(); // item id -> VBELN
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

        /* ====================== RENDER T2 ====================== */
        function renderT2(rows, kunnr) {
            if (!rows?.length) return `<div class="p-3 text-muted">Tidak ada data PO untuk KUNNR <b>${kunnr}</b>.</div>`;

            // 🔧 SORT: telat (positif) dulu, lalu terbesar → terkecil; sisanya (negatif) otomatis di bawah
            const sortedRows = [...rows].sort((a, b) => {
                const oa = Number(a.Overdue ?? 0);
                const ob = Number(b.Overdue ?? 0);
                // Positif (telat) selalu di atas negatif (belum jatuh tempo)
                if ((oa > 0) !== (ob > 0)) return ob > 0 ? 1 : -1; // atau cukup return ob - oa; tapi ini eksplisit
                // Jika sama-sama positif → yang lebih besar (lebih lama telat) paling atas
                // Jika sama-sama negatif → nilai lebih tinggi (contoh -1 di atas -14)
                return ob - oa; // DESC
            });

            let html = `
<div style="width:100%">
  <h5 class="yz-table-title-nested yz-title-so"><i class="fas fa-file-invoice me-2"></i>Outstanding PO</h5>
  <table class="table table-sm mb-0 yz-mini">
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

            html += `</tbody></table></div>`;
            return html;
        }

        /* ====================== RENDER T3 ====================== */
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

        /* Footer T2 hide/show saat T3 dibuka/ditutup */
        function updateT2FooterVisibility(t2Table) {
            if (!t2Table) return;
            const anyOpen = [...t2Table.querySelectorAll('tr.yz-nest')]
                .some(tr => tr.style.display !== 'none' && tr.offsetParent !== null);
            const tfoot = t2Table.querySelector('tfoot.t2-footer');
            if (tfoot) tfoot.style.display = anyOpen ? 'none' : '';
        }

        /* ====================== MAIN ====================== */
        document.addEventListener('DOMContentLoaded', () => {
            // Label responsif Tabel-1
            document.querySelectorAll('.yz-kunnr-row').forEach(row => {
                row.querySelector('td:nth-child(2)')?.setAttribute('data-label', 'Customer');
                row.querySelector('td:nth-child(3)')?.setAttribute('data-label', 'Overdue PO');
                row.querySelector('td:nth-child(4)')?.setAttribute('data-label', 'Outs. Qty');
                row.querySelector('td:nth-child(5)')?.setAttribute('data-label', 'Outs. Value');
                row.querySelector('td:nth-child(6)')?.setAttribute('data-label', 'Overdue Value');
            });

            const root = document.getElementById('yz-root');
            const showTable = root ? !!parseInt(root.dataset.show) : false;
            if (!showTable) return;

            const apiT2 = "{{ route('dashboard.api.t2') }}";
            const apiT3 = "{{ route('dashboard.api.t3') }}";
            const WERKS = (root.dataset.werks || '').trim() || null;
            const AUART = (root.dataset.auart || '').trim() || null;

            // Expand Level-1 → load T2
            document.querySelectorAll('.yz-kunnr-row').forEach(custRow => {
                custRow.addEventListener('click', async () => {
                    const kunnr = (custRow.dataset.kunnr || '').trim();
                    const kid = custRow.dataset.kid;
                    const slot = document.getElementById(kid);
                    const wrap = slot?.querySelector('.yz-nest-wrap');

                    const tbody = custRow.closest('tbody');
                    const tableEl = custRow.closest('table');
                    const tfootEl = tableEl?.querySelector('tfoot.yz-footer-customer');

                    const wasOpen = custRow.classList.contains('is-open');
                    if (!wasOpen) {
                        tbody.classList.add('customer-focus-mode');
                        custRow.classList.add('is-focused');
                    } else {
                        tbody.classList.remove('customer-focus-mode');
                        custRow.classList.remove('is-focused');
                    }

                    custRow.classList.toggle('is-open');
                    slot.style.display = wasOpen ? 'none' : '';

                    if (wasOpen) {
                        wrap?.querySelectorAll('tr.yz-nest').forEach(tr => tr.style.display =
                            'none');
                        wrap?.querySelectorAll('.yz-caret.rot').forEach(c => c.classList.remove(
                            'rot'));
                        // 🔧 perbaikan utama: bersihkan state fokus Tabel-2
                        wrap?.querySelectorAll('tbody.so-focus-mode').forEach(tb => tb.classList
                            .remove('so-focus-mode'));
                        wrap?.querySelectorAll('.js-t2row.is-focused').forEach(r => r.classList
                            .remove('is-focused'));
                        wrap?.querySelectorAll('.check-so').forEach(chk => (chk.checked =
                            false));
                    }

                    if (tfootEl) {
                        const anyVisible = [...tableEl.querySelectorAll('tr.yz-nest')].some(
                            tr => tr.style.display !== 'none' && tr.offsetParent !== null
                        );
                        tfootEl.style.display = anyVisible ? 'none' : '';
                    }
                    wrap?.querySelectorAll('table').forEach(tbl => updateT2FooterVisibility(
                        tbl));

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

                        // Klik baris SO → toggle & load T3
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

                                if (!open) {
                                    soTbody.classList.add('so-focus-mode');
                                    soRow.classList.add('is-focused');
                                } else {
                                    soTbody.classList.remove(
                                        'so-focus-mode');
                                    soRow.classList.remove('is-focused');
                                }

                                if (open) {
                                    tgt.style.display = 'none';
                                    caret?.classList.remove('rot');
                                    updateT2FooterVisibility(t2tbl);
                                    return;
                                }

                                tgt.style.display = '';
                                caret?.classList.add('rot');
                                updateT2FooterVisibility(t2tbl);

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

                                // Sinkronkan item yang sudah dipilih
                                box.querySelectorAll('.check-item').forEach(
                                    chk => {
                                        const sid = sanitizeId(chk
                                            .dataset.id);
                                        chk.checked = !!(sid &&
                                            selectedItems.has(sid));
                                    });
                            });
                        });

                        /* ========= CHANGE HANDLERS (checkbox) ========= */
                        wrap.addEventListener('change', async (e) => {
                            // ======= CHECK-ALL ITEMS (T3) =======
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
                                // update dot SO
                                const anyItem = t3.querySelector('.check-item');
                                if (anyItem) {
                                    const v = itemIdToSO.get(String(anyItem.dataset
                                        .id));
                                    if (v) soHasSelectionDot(v);
                                }
                                updateExportButton();
                                return;
                            }

                            // ======= CHECK SINGLE ITEM (T3) =======
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

                            // ======= CHECK-ALL SO (T2) =======
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

                            // ======= CHECK SINGLE SO (T2) =======
                            if (e.target.classList.contains('check-so')) {
                                const soRow = e.target.closest('.js-t2row');
                                const vbeln = e.target.dataset.vbeln;
                                const nest = soRow.nextElementSibling; // tr.yz-nest
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

            // Export handler
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
        });
    </script>
@endpush
