@extends('layouts.app')

@section('title', 'Outstanding SO')

@section('content')

    @php
        $selectedWerks = $selected['werks'] ?? null;
        $selectedAuart = trim((string) ($selected['auart'] ?? ''));
        $typesForPlant = collect($mapping[$selectedWerks] ?? []);

        $locationMap = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locName = $locationMap[$selectedWerks] ?? $selectedWerks;

        // Helper total untuk FOOTER Tabel-1
        $rowsCol = collect($rows ?? []);
        // kolom baru yang diharapkan dari controller
        $totalQtyAll = (float) $rowsCol->sum(fn($r) => (float) ($r->TOTAL_OUTS_QTY ?? 0));
        // total all value per currency
        $pageTotalsAll = $rowsCol
            ->groupBy(fn($r) => $r->WAERK ?? '')
            ->map(fn($g) => (float) $g->sum(fn($x) => (float) ($x->TOTAL_ALL_VALUE ?? ($x->TOTAL_VALUE ?? 0))));
        // total overdue value per currency
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

    {{-- ROOT STATE --}}
    <div id="so-root" data-werks="{{ $selectedWerks ?? '' }}" data-auart="{{ $selectedAuart }}"
        data-hkunnr="{{ request('highlight_kunnr', '') }}" data-hvbeln="{{ request('highlight_vbeln', '') }}"
        data-hposnr="{{ request('highlight_posnr', '') }}" data-auto="{{ request('auto', '1') ? '1' : '0' }}"
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
                                // Menggunakan URL terenkripsi yang sama dengan yang dikirim dari Dashboard
                                $filterUrl = route('so.index', [
                                    'q' => \Crypt::encrypt(['werks' => $selectedWerks, 'auart' => $auartCode]),
                                ]);
                            @endphp
                            <li class="nav-item mb-2 me-2">
                                <a href="{{ $filterUrl }}" class="nav-link pill-green {{ $isActive ? 'active' : '' }}">
                                    {{ $t->Deskription }}
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
    @if ($rows)
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
                                <th class="text-center" style="min-width:120px;">Overdue SO</th>
                                <th class="text-center" style="min-width:120px;">Outs. Qty</th>
                                <th class="text-center" style="min-width:150px;">Outs. Value</th>
                                <th class="text-center" style="min-width:160px;">Overdue Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $r)
                                @php
                                    $kid = 'krow_' . $r->KUNNR . '_' . $loop->index;

                                    // Nilai yang diharapkan dari controller:
                                    // - $r->TOTAL_OUTS_QTY (qty semua outstanding)
                                    // - $r->TOTAL_ALL_VALUE (value semua outstanding)
                                    // - $r->TOTAL_OVERDUE_VALUE (value overdue saja)
                                    // Fallback: jika TOTAL_ALL_VALUE belum ada, gunakan TOTAL_VALUE lama (yang mungkin berisi overdue)
                                    $outsQtyAll = (float) ($r->TOTAL_OUTS_QTY ?? 0);
                                    $outsValueAll = (float) ($r->TOTAL_ALL_VALUE ?? ($r->TOTAL_VALUE ?? 0));
                                    $overdueValue = (float) ($r->TOTAL_OVERDUE_VALUE ?? 0);
                                @endphp
                                <tr class="yz-kunnr-row" data-kunnr="{{ $r->KUNNR }}" data-kid="{{ $kid }}"
                                    title="Klik untuk melihat detail SO">
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
            </div>
        </div>
    @endif

    {{-- MODAL REMARK --}}
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
            user-select: none
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
    </style>
@endpush

@push('scripts')
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
            const saveRemarkUrl = "{{ route('so.api.save_remark') }}";
            const csrfToken = "{{ csrf_token() }}";

            const __root = document.getElementById('so-root');
            const WERKS = (__root?.dataset.werks || '').trim();
            const AUART = (__root?.dataset.auart || '').trim();
            const KUNNR_HL = (__root?.dataset.hkunnr || '').trim();
            const VBELN_HL = (__root?.dataset.hvbeln || '').trim();
            const AUTO = (__root?.dataset.auto || '0') === '1';

            const exportDropdownContainer = document.getElementById('export-dropdown-container');
            const selectedCountSpan = document.getElementById('selected-count');

            const remarkModalEl = document.getElementById('remarkModal');
            const remarkModal = new bootstrap.Modal(remarkModalEl);
            const remarkTextarea = document.getElementById('remark-text');
            const saveRemarkBtn = document.getElementById('save-remark-btn');
            const remarkFeedback = document.getElementById('remark-feedback');

            const selectedItems = new Set(); // item ids
            const itemsCache = new Map(); // vbeln -> items
            const itemIdToSO = new Map(); // itemId -> vbeln

            /* ---------- Utils ---------- */
            if (!window.CSS) window.CSS = {};
            if (typeof window.CSS.escape !== 'function') window.CSS.escape = s => String(s).replace(/([^\w-])/g,
                '\\$1');

            function updateExportButton() {
                const n = selectedItems.size;
                selectedCountSpan.textContent = n;
                exportDropdownContainer.style.display = n > 0 ? 'block' : 'none';
            }
            const formatCurrency = (v, c, d = 2) => {
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
            const formatNumber = (v, d = 0) => {
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

            function syncCheckAllHeader(itemBox) {
                const table = itemBox?.querySelector('table');
                if (!table) return;
                const hdr = table.querySelector('.check-all-items');
                if (!hdr) return;
                const all = Array.from(table.querySelectorAll('.check-item'));
                hdr.checked = (all.length > 0 && all.every(ch => ch.checked));
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
                if (tfoot) tfoot.style.display = anyOpen ? 'none' : '';
            }

            document.addEventListener('click', (e) => {
                if (e.target.closest('.check-so') || e.target.closest('.check-all-sos')) e
                    .stopPropagation();
            }, true);

            /* ---------- RENDERERS ---------- */
            function renderLevel2_SO(rows, kunnr) {
                if (!rows?.length)
                    return `<div class="p-3 text-muted">Tidak ada data Outstanding SO untuk customer ini.</div>`;

                let html = `
    <h5 class="yz-table-title-nested yz-title-so"><i class="fas fa-file-invoice me-2"></i>Outstanding SO</h5>
    <table class="table table-sm mb-0 yz-mini">
      <thead class="yz-header-so">
        <tr>
          <th style="width:40px;" class="text-center">
            <input type="checkbox" class="form-check-input check-all-sos" title="Pilih semua SO">
          </th>
          <th style="width:40px;"></th>
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
                    if (aOver !== bOver) return aOver ? -1 : 1; // overdue (+) dulu
                    return ob - oa; // desc dalam grup
                });

                rowsSorted.forEach((r, i) => {
                    const rid = `t3_${kunnr}_${r.VBELN}_${i}`;
                    const rowHi = r.Overdue > 0 ? 'yz-row-highlight-negative' : '';
                    const hasRemark = Number(r.remark_count || 0) > 0;
                    const outsQty = (typeof r.outs_qty !== 'undefined') ? r.outs_qty : (r.OUTS_QTY ?? 0);

                    html += `
        <tr class="yz-row js-t2row ${rowHi}" data-vbeln="${r.VBELN}" data-tgt="${rid}">
          <td class="text-center"><input type="checkbox" class="form-check-input check-so" data-vbeln="${r.VBELN}"></td>
          <td class="text-center"><span class="yz-caret">▸</span></td>
          <td class="yz-t2-vbeln text-start">${r.VBELN}</td>
          <td class="text-center">${r.item_count ?? '-'}</td>
          <td class="text-center">${r.FormattedEdatu || '-'}</td>
          <td class="text-center">${r.Overdue}</td>
          <td class="text-center">${formatNumber(outsQty)}</td>
          <td class="text-center">${formatCurrency(r.total_value, r.WAERK)}</td>
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

                html += `</tbody></table>`;
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
                data-vbeln="${r.VBELN_KEY}" data-posnr="${r.POSNR_KEY}">
              <td><input class="form-check-input check-item" type="checkbox" data-id="${r.id}" ${isChecked?'checked':''}></td>
              <td>${r.POSNR ?? ''}</td>
              <td>${r.MATNR ?? ''}</td>
              <td>${r.MAKTX ?? ''}</td>
              <td>${formatNumber(r.KWMENG)}</td>
              <td>${formatNumber(r.PACKG)}</td>
              <td>${formatNumber(r.KALAB2)}</td>
              <td>${formatNumber(r.ASSYM)}</td>
              <td>${formatNumber(r.PAINT)}</td>
              <td>${formatNumber(r.MENGE)}</td>
              <td>${formatCurrency(r.NETPR, r.WAERK)}</td>
              <td>${formatCurrency(r.TOTPR2, r.WAERK)}</td>
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
                    const slot = document.getElementById(kid);
                    const wrap = slot.querySelector('.yz-nest-wrap');

                    const tbody = row.closest('tbody');
                    const tableEl = row.closest('table');
                    const tfootEl = tableEl?.querySelector('tfoot.yz-footer-customer');

                    const wasOpen = row.classList.contains('is-open');
                    if (!wasOpen) {
                        tbody.classList.add('customer-focus-mode');
                        row.classList.add('is-focused');
                    } else {
                        tbody.classList.remove('customer-focus-mode');
                        row.classList.remove('is-focused');
                    }

                    row.classList.toggle('is-open');
                    slot.style.display = wasOpen ? 'none' : '';

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

                    const anyVisibleNest = [...tableEl.querySelectorAll('tr.yz-nest')]
                        .some(tr => tr.style.display !== 'none' && tr.offsetParent !== null);
                    if (tfootEl) tfootEl.style.display = anyVisibleNest ? 'none' : '';

                    if (wasOpen) return;
                    if (wrap.dataset.loaded === '1') return;

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
                        updateT2FooterVisibility(wrap.querySelector('table'));

                        // klik baris SO => focus-mode & load items
                        wrap.querySelectorAll('.js-t2row').forEach(soRow => {
                            soRow.addEventListener('click', async (ev) => {
                                if (ev.target.closest(
                                        '.check-so, .check-all-sos, .form-check-input'
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
                                    const u = new URL(apiItemsBySo, window
                                        .location.origin);
                                    u.searchParams.set('vbeln', vbeln);
                                    u.searchParams.set('werks', WERKS);
                                    u.searchParams.set('auart', AUART);
                                    const r = await fetch(u);
                                    const jd = await r.json();
                                    if (!jd.ok) throw new Error(jd.error ||
                                        'Gagal memuat item');
                                    jd.data.forEach(x => itemIdToSO.set(
                                        String(x.id), vbeln));
                                    itemsCache.set(vbeln, jd.data);

                                    box.innerHTML = renderLevel3_Items(jd
                                        .data);
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
                // --- check-all items (T3)
                if (e.target.classList.contains('check-all-items')) {
                    const table = e.target.closest('table');
                    if (!table) return;
                    table.querySelectorAll('.check-item').forEach(ch => {
                        ch.checked = e.target.checked;
                        const id = ch.dataset.id;
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

                // --- single item (T3)
                if (e.target.classList.contains('check-item')) {
                    const id = e.target.dataset.id;
                    if (e.target.checked) selectedItems.add(id);
                    else selectedItems.delete(id);
                    const vbeln = itemIdToSO.get(String(id));
                    if (vbeln) updateSODot(vbeln);
                    const box = e.target.closest('.yz-slot-items');
                    if (box) syncCheckAllHeader(box);
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
                        const caret = soRow?.querySelector('.yz-caret');
                        const t2tbl = soRow?.closest('table');

                        if (nest) {
                            const box = nest.querySelector('.yz-slot-items');
                            if (e.target.checked) {
                                if (nest.style.display === 'none') {
                                    nest.style.display = '';
                                    caret?.classList.add('rot');
                                }
                                if (nest.dataset.loaded !== '1') {
                                    box.innerHTML = renderLevel3_Items(items);
                                    applySelectionsToRenderedItems(box);
                                    nest.dataset.loaded = '1';
                                    updateSoRemarkFlagFromCache(vbeln);
                                } else {
                                    box.querySelectorAll('.check-item').forEach(ch => ch.checked =
                                        true);
                                }
                                const hdr = box.querySelector('table .check-all-items');
                                if (hdr) hdr.checked = true;
                            } else {
                                if (box) {
                                    box.querySelectorAll('.check-item').forEach(ch => ch.checked =
                                        false);
                                    const hdr = box.querySelector('table .check-all-items');
                                    if (hdr) hdr.checked = false;
                                }
                                nest.style.display = 'none';
                                caret?.classList.remove('rot');
                            }
                            updateT2FooterVisibility(t2tbl);
                        }
                    }
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
                    updateExportButton();

                    const soRow = document.querySelector(
                        `.js-t2row[data-vbeln='${CSS.escape(vbeln)}']`);
                    const nest = soRow?.nextElementSibling;
                    const caret = soRow?.querySelector('.yz-caret');
                    const t2tbl = soRow?.closest('table');

                    if (nest) {
                        const box = nest.querySelector('.yz-slot-items');
                        if (e.target.checked) {
                            if (nest.style.display === 'none') {
                                nest.style.display = '';
                                caret?.classList.add('rot');
                            }
                            if (nest.dataset.loaded !== '1') {
                                const items = await ensureItemsLoadedForSO(vbeln);
                                box.innerHTML = renderLevel3_Items(items);
                                applySelectionsToRenderedItems(box);
                                nest.dataset.loaded = '1';
                                updateSoRemarkFlagFromCache(vbeln);
                            } else {
                                box.querySelectorAll('.check-item').forEach(ch => ch.checked = true);
                            }
                            const hdr = box.querySelector('table .check-all-items');
                            if (hdr) hdr.checked = true;
                        } else {
                            if (box) {
                                box.querySelectorAll('.check-item').forEach(ch => ch.checked = false);
                                const hdr = box.querySelector('table .check-all-items');
                                if (hdr) hdr.checked = false;
                            }
                            nest.style.display = 'none';
                            caret?.classList.remove('rot');
                        }
                        updateT2FooterVisibility(t2tbl);
                    }
                    return;
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
                saveRemarkBtn.dataset.posnr = rowEl.dataset.posnr;

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
                    posnr: this.dataset.posnr,
                    remark: remarkTextarea.value
                };
                this.disabled = true;
                this.innerHTML =
                    `<span class="spinner-border spinner-border-sm" role="status"></span> Menyimpan...`;
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

                    const rowSel =
                        `tr[data-werks='${payload.werks}'][data-auart='${payload.auart}'][data-vbeln='${payload.vbeln}'][data-posnr='${payload.posnr}']`;
                    const rowEl = document.querySelector(rowSel);
                    const ic = rowEl?.querySelector('.remark-icon');
                    const dot = rowEl?.querySelector('.remark-dot');
                    if (ic) ic.dataset.remark = encodeURIComponent(payload.remark || '');
                    if (dot) dot.style.display = (payload.remark.trim() !== '' ? 'inline-block' :
                        'none');

                    recalcSoRemarkFlagFromDom(payload.vbeln);

                    remarkFeedback.textContent = 'Catatan berhasil disimpan!';
                    remarkFeedback.className = 'small mt-2 text-success';
                    setTimeout(() => remarkModal.hide(), 800);
                } catch (err) {
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

            /* ---------- Auto expand/scroll (opsional) ---------- */
            (async function autoExpandFromRoot() {
                const VBELN = VBELN_HL,
                    KUNNR = KUNNR_HL;
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
                        const norm = (tr.dataset.posnr || '').replace(/\D/g, '').padStart(6, '0');
                        if (norm === pos6) return tr;
                    }
                    return null;
                };

                if (!(shouldAuto && (VBELN || KUNNR))) return;

                const openToSO = async (customerRow) => {
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

                if (VBELN && KUNNR) {
                    const crow = document.querySelector(`.yz-kunnr-row[data-kunnr='${CSS.escape(KUNNR)}']`);
                    if (!crow) return;
                    const {
                        soRow,
                        itemsBox
                    } = await openToSO(crow);
                    if (!soRow) return;
                    const target = (POSNR6 && findItemRow(itemsBox, VBELN, POSNR6)) ||
                        Array.from(itemsBox?.querySelectorAll('tr') || []).find(tr => {
                            const ic = tr.querySelector('.remark-icon');
                            return ic && decodeURIComponent(ic.dataset.remark || '').trim() !== '';
                        });
                    scrollAndFlash(target || soRow);
                    return;
                }

                if (VBELN && !KUNNR) {
                    let foundSoRow = null,
                        foundItemsBox = null;
                    for (const crow of Array.from(document.querySelectorAll('.yz-kunnr-row'))) {
                        const {
                            soRow,
                            itemsBox
                        } = await openToSO(crow);
                        if (soRow) {
                            foundSoRow = soRow;
                            foundItemsBox = itemsBox;
                            break;
                        }
                    }
                    if (!foundSoRow) return;
                    const target = (POSNR6 && findItemRow(foundItemsBox, VBELN, POSNR6)) ||
                        Array.from(foundItemsBox?.querySelectorAll('tr') || []).find(tr => {
                            const ic = tr.querySelector('.remark-icon');
                            return ic && decodeURIComponent(ic.dataset.remark || '').trim() !== '';
                        });
                    scrollAndFlash(target || foundSoRow);
                }
            })();

        });
    </script>
@endpush
