@extends('layouts.app')

@section('title', 'PO Report by Customer')

@section('content')

    @php
        // Ambil nilai dari controller / query
        $werks = $selected['werks'] ?? null;
        $auart = $selected['auart'] ?? null;
        $show = filled($werks) && filled($auart);
        $onlyWerksSelected = filled($werks) && empty($auart);
        $compact = $compact ?? true; // Default true untuk halaman report

        $locationMap = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locName = $locationMap[$werks] ?? $werks;

        // Helper pembentuk URL terenkripsi ke /po-report
        $encReport = function (array $params) {
            $payload = array_filter(array_merge(['compact' => 1], $params), fn($v) => !is_null($v) && $v !== '');
            return route('po.report', ['q' => \Crypt::encrypt($payload)]);
        };
    @endphp

    {{-- Anchor untuk JS agar tahu sedang mode TABLE atau bukan --}}
    <div id="yz-root" data-show="{{ $show ? 1 : 0 }}" data-werks="{{ $werks ?? '' }}" data-auart="{{ $auart ?? '' }}"
        style="display:none"></div>

    {{-- =========================================================
    HEADER: PILIH TYPE & ACTION BUTTONS
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

                {{-- Kanan: Collabs + Export --}}
                <div class="py-1 d-flex align-items-center gap-2">
                    {{-- [NEW] Collabs buttons --}}
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

                    {{-- Export Items (muncul saat ada item terpilih) --}}
                    <div class="dropdown" id="export-dropdown-container" style="display:none;">
                        <button class="btn btn-primary dropdown-toggle" type="button" id="export-btn"
                            data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-file-export me-2"></i>
                            Export Items (<span id="selected-count">0</span>)
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="export-btn">
                            <li><a class="dropdown-item export-option" href="#" data-type="pdf">
                                    <i class="fas fa-file-pdf text-danger me-2"></i>Export to PDF
                                </a></li>
                            <li><a class="dropdown-item export-option" href="#" data-type="excel">
                                    <i class="fas fa-file-excel text-success me-2"></i>Export to Excel
                                </a></li>
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

                @php
                    $totalsByCurr = [];
                    foreach ($rows as $r) {
                        $cur = $r->WAERK ?? '';
                        $val = (float) $r->TOTPR;
                        $totalsByCurr[$cur] = ($totalsByCurr[$cur] ?? 0) + $val;
                    }
                    $formatTotal = function ($val, $cur) {
                        if ($cur === 'IDR') {
                            return 'Rp ' . number_format($val, 2, ',', '.');
                        }
                        if ($cur === 'USD') {
                            return '$' . number_format($val, 2, '.', ',');
                        }
                        return ($cur ? $cur . ' ' : '') . number_format($val, 2, ',', '.');
                    };
                @endphp

                {{-- TABEL UTAMA PO REPORT --}}
                <div class="table-responsive yz-table px-md-3">
                    <table class="table table-hover mb-0 align-middle yz-grid">
                        <thead class="yz-header-customer">
                            <tr>
                                <th style="width:50px;"></th>
                                <th class="text-start" style="min-width:250px;">Customer</th>
                                <th style="min-width:120px; text-align:center;">Overdue PO</th>
                                <th style="min-width:150px;">Outs. Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $r)
                                @php $kid = 'krow_'.$r->KUNNR.'_'.$loop->index; @endphp
                                <tr class="yz-kunnr-row" data-kunnr="{{ $r->KUNNR }}" data-kid="{{ $kid }}"
                                    title="Klik untuk melihat detail pesanan">
                                    <td class="sticky-col-mobile-disabled">
                                        <span class="kunnr-caret"><i class="fas fa-chevron-right"></i></span>
                                    </td>
                                    <td class="sticky-col-mobile-disabled text-start">
                                        <span class="fw-bold">{{ $r->NAME1 }}</span>
                                    </td>
                                    <td class="text-center">{{ $r->SO_LATE_COUNT }}</td>
                                    <td class="data-raw-totpr">
                                        <span class="customer-totpr">
                                            @php
                                                if ($r->WAERK === 'IDR') {
                                                    echo 'Rp ' . number_format($r->TOTPR, 2, ',', '.');
                                                } elseif ($r->WAERK === 'USD') {
                                                    echo '$' . number_format($r->TOTPR, 2, '.', ',');
                                                } else {
                                                    echo ($r->WAERK ?? '') .
                                                        ' ' .
                                                        number_format($r->TOTPR, 2, ',', '.');
                                                }
                                            @endphp
                                        </span>
                                    </td>
                                </tr>
                                <tr id="{{ $kid }}" class="yz-nest" style="display:none;">
                                    <td colspan="4" class="p-0">
                                        <div class="yz-nest-wrap">
                                            <div
                                                class="p-3 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
                                                <div class="spinner-border spinner-border-sm me-2" role="status">
                                                    <span class="visually-hidden">Loading...</span>
                                                </div>
                                                Memuat data…
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center p-5">
                                        <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">Data tidak ditemukan</h5>
                                        <p>Tidak ada data yang cocok untuk filter yang Anda pilih.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot class="yz-footer-customer">
                            @foreach ($totalsByCurr as $cur => $sum)
                                <tr class="table-light">
                                    <th></th>
                                    <th class="text-start">Total ({{ $cur ?: 'N/A' }})</th>
                                    <th class="text-center"></th>
                                    <th class="text-center">{{ $formatTotal($sum, $cur) }}</th>
                                </tr>
                            @endforeach
                        </tfoot>
                    </table>
                </div>

                {{-- Pagination --}}
                @if ($rows->hasPages())
                    <div class="px-3 pt-3">
                        {{ $rows->onEachSide(1)->links('pagination::bootstrap-5') }}
                    </div>
                @endif
            </div>
        </div>

        {{-- =========================================================
    B. HANYA Plant dipilih → minta user pilih AUART
    ========================================================= --}}
    @elseif($onlyWerksSelected)
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            Silakan pilih <strong>Type</strong> pada tombol hijau di atas.
        </div>

        {{-- =========================================================
    C. BELUM ADA YANG DIPILIH
    ========================================================= --}}
    @else
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
        }

        .yz-caret.rot {
            transform: rotate(90deg);
        }

        /* [NEW] kelas untuk hide saat collabs-focus */
        .collabs-hidden {
            display: none !important;
        }

        /* [NEW][opsional] rapikan tombol */
        #collabs-container .btn {
            white-space: nowrap;
        }

        .btn-collabs {
            --c1: #22c55e;
            /* green-500 */
            --c2: #16a34a;
            /* green-600 */
            background: linear-gradient(180deg, var(--c1), var(--c2));
            color: #fff;
            border: 0;
            box-shadow: 0 4px 12px rgba(34, 197, 94, .25);
        }

        .btn-collabs:hover {
            filter: brightness(0.98);
            color: #fff;
        }

        .btn-collabs:focus {
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(34, 197, 94, .35);
        }

        .btn-collabs-exit {
            background: #eef2ff;
            /* indigo-50 */
            color: #3730a3;
            /* indigo-800 */
            border: 1px solid #c7d2fe;
            /* indigo-200 */
        }

        .btn-collabs-exit:hover {
            background: #e0e7ff;
            color: #312e81;
        }

        .btn-collabs-exit:focus {
            outline: 0;
            box-shadow: 0 0 0 .2rem rgba(99, 102, 241, .25);
        }

        .bg-collabs {
            background: #065f46 !important;
        }

        /* emerald-800 */
    </style>
@endpush

@push('scripts')
    <script>
        /* ========= UTIL ========= */
        const formatCurrencyForTable = (value, currency) => {
            const n = parseFloat(value);
            if (!Number.isFinite(n)) return '';
            const opt = {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            };
            if (currency === 'IDR') return `Rp ${n.toLocaleString('id-ID', opt)}`;
            if (currency === 'USD') return `$${n.toLocaleString('en-US', opt)}`;
            return `${currency} ${n.toLocaleString('id-ID', opt)}`;
        };
        const formatNumber = (num, d = 0) => {
            const n = parseFloat(num);
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

        /* ========= STATE EXPORT (berbasis item) ========= */
        const selectedItems = new Set(); // id item
        const itemIdToSO = new Map(); // id -> vbeln
        const exportDropdownContainer = document.getElementById('export-dropdown-container');
        const selectedCountSpan = document.getElementById('selected-count');
        const updateExportButton = () => {
            selectedCountSpan.textContent = selectedItems.size;
            if (exportDropdownContainer) exportDropdownContainer.style.display = selectedItems.size > 0 ? 'block' :
                'none';
        };

        /* ========= [NEW] STATE COLLABS (berbasis SO) ========= */
        const collabsContainer = document.getElementById('collabs-container');
        const collabsCountSpan = document.getElementById('collabs-count-badge');
        const btnCollabs = document.getElementById('btn-collabs');
        const btnCollabsExit = document.getElementById('btn-collabs-exit');
        let collabsMode = false;

        const countCheckedSO = () =>
            document.querySelectorAll('.yz-nest-wrap .check-so:checked').length;

        const setCollabsUI = () => {
            if (collabsContainer) {
                collabsContainer.style.display = (collabsMode || countCheckedSO() > 0) ? 'block' : 'none';
            }
            if (btnCollabs) btnCollabs.style.display = collabsMode ? 'none' : 'inline-flex';
            if (btnCollabsExit) btnCollabsExit.style.display = collabsMode ? 'inline-flex' : 'none';
        };

        const updateCollabsButton = () => {
            const n = countCheckedSO();
            if (collabsCountSpan) collabsCountSpan.textContent = n;
            setCollabsUI();
        };


        /* Tangkal klik checkbox agar tidak ikut expand */
        document.addEventListener('click', (e) => {
            if (e.target.closest('.form-check-input')) e.stopPropagation();
        }, true);

        /* ========= RENDER T2 ========= */
        function renderT2(rows, kunnr) {
            if (!rows?.length) return `<div class="p-3 text-muted">Tidak ada data PO untuk KUNNR <b>${kunnr}</b>.</div>`;

            const totalsByCurr = {};
            rows.forEach(r => {
                const cur = (r.WAERK || '').trim();
                const val = parseFloat(r.TOTPR) || 0;
                totalsByCurr[cur] = (totalsByCurr[cur] || 0) + val;
            });

            let html = `
  <div style="width:100%">
    <h5 class="yz-table-title-nested yz-title-so"><i class="fas fa-file-invoice me-2"></i>Overview PO</h5>
    <table class="table table-sm mb-0 yz-mini">
      <thead class="yz-header-so">
        <tr>
          <th style="width:40px" class="text-center">
            <input type="checkbox" class="form-check-input check-all-sos" title="Pilih semua SO">
          </th>
          <th style="width:40px;text-align:center;"></th>
          <th style="min-width:150px;text-align:left;">PO</th>
          <th style="min-width:100px;text-align:left;">SO</th>
          <th style="min-width:110px;text-align:right;">Outs. Value</th>
          <th style="min-width:110px;text-align:center;">Req. Delv Date</th>
          <th style="min-width:110px;text-align:center;">Overdue (Days)</th>
          <th style="min-width:120px;text-align:center;">Shortage %</th>
        </tr>
      </thead>
      <tbody>`;

            rows.forEach((r, i) => {
                const rid = `t3_${kunnr}_${r.VBELN}_${i}`;
                const over = r.Overdue;
                const rowCls = over < 0 ? 'yz-row-highlight-negative' : '';
                const edatu = r.FormattedEdatu || '';
                const shrt = `${(r.ShortagePercentage||0).toFixed(2)}%`;

                html += `
      <tr class="yz-row js-t2row ${rowCls}" data-vbeln="${r.VBELN}" data-tgt="${rid}">
        <td class="text-center"><input type="checkbox" class="form-check-input check-so" data-vbeln="${r.VBELN}"></td>
        <td style="text-align:center;"><span class="yz-caret">▸</span></td>
        <td style="text-align:left;">${r.BSTNK ?? ''}</td>
        <td class="yz-t2-vbeln" style="text-align:left;">${r.VBELN}</td>
        <td style="text-align:right;">${formatCurrencyForTable(r.TOTPR, r.WAERK)}</td>
        <td style="text-align:center;">${edatu}</td>
        <td style="text-align:center;">${over ?? 0}</td>
        <td style="text-align:center;">${shrt}</td>
      </tr>
      <tr id="${rid}" class="yz-nest" style="display:none;">
        <td colspan="8" class="p-0">
          <div class="yz-nest-wrap level-2" style="margin-left:0;padding:.5rem;">
            <div class="yz-slot-t3 p-2"></div>
          </div>
        </td>
      </tr>`;
            });

            html += `</tbody><tfoot class="t2-footer">`;
            Object.entries(totalsByCurr).forEach(([cur, sum]) => {
                html += `
      <tr class="table-light">
        <th></th><th></th>
        <th colspan="2" style="text-align:left;">Total (${cur || 'N/A'})</th>
        <th style="text-align:right;">${formatCurrencyForTable(sum, cur)}</th>
        <th></th><th></th><th></th>
      </tr>`;
            });
            html += `</tfoot></table></div>`;
            return html;
        }

        /* ========= RENDER T3 ========= */
        function renderT3(rows) {
            if (!rows?.length) return `<div class="p-2 text-muted">Tidak ada item detail.</div>`;
            let out = `
  <div class="table-responsive">
    <table class="table table-sm mb-0 yz-mini">
      <thead class="yz-header-item">
        <tr>
          <th style="width:40px;"><input class="form-check-input check-all-items" type="checkbox" title="Pilih Semua Item"></th>
          <th style="min-width:80px;text-align:center;">Item</th>
          <th style="min-width:150px;text-align:center;">Material FG</th>
          <th style="min-width:300px;">Desc FG</th>
          <th style="min-width:80px;">Qty PO</th>
          <th style="min-width:60px;">Shipped</th>
          <th style="min-width:60px;">Outs. Ship</th>
          <th style="min-width:80px;">WHFG</th>
          <th style="min-width:80px;">FG</th>
          <th style="min-width:100px;">Net Price</th>
        </tr>
      </thead>
      <tbody>`;

            rows.forEach(r => {
                const sid = sanitizeId(r.id);
                const checked = sid && selectedItems.has(sid) ? 'checked' : '';
                out += `
      <tr data-item-id="${sid ?? ''}" data-vbeln="${r.VBELN}">
        <td><input class="form-check-input check-item" type="checkbox" data-id="${sid ?? ''}" ${checked}></td>
        <td style="text-align:center;">${r.POSNR ?? ''}</td>
        <td style="text-align:center;">${r.MATNR ?? ''}</td>
        <td>${r.MAKTX ?? ''}</td>
        <td>${formatNumber(r.KWMENG)}</td>
        <td>${formatNumber(r.QTY_GI)}</td>
        <td>${formatNumber(r.QTY_BALANCE2)}</td>
        <td>${formatNumber(r.KALAB)}</td>
        <td>${formatNumber(r.KALAB2)}</td>
        <td>${formatCurrencyForTable(r.NETPR, r.WAERK)}</td>
      </tr>`;
                if (sid) itemIdToSO.set(sid, String(r.VBELN));
            });

            out += `</tbody></table></div>`;
            return out;
        }

        /* Footer T2 hide/show saat T3 dibuka */
        function updateT2FooterVisibility(t2Table) {
            if (!t2Table) return;
            const anyOpen = [...t2Table.querySelectorAll('tr.yz-nest')]
                .some(tr => tr.style.display !== 'none' && tr.offsetParent !== null);
            const tfoot = t2Table.querySelector('tfoot.t2-footer');
            if (tfoot) tfoot.style.display = anyOpen ? 'none' : '';
        }

        /* ========= COLLABS MODE HELPERS ========= */
        function enterCollabsMode() {
            collabsMode = true;
            setCollabsUI();

            const wraps = document.querySelectorAll('.yz-nest-wrap');
            const affectedT2Tables = new Set();

            // Sembunyikan seluruh customer yg tidak punya SO terpilih
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

            // Di tiap wrap yang punya SO terpilih: tampilkan hanya SO terpilih, expand & load T3
            wraps.forEach(async (wrap) => {
                const t2tbl = wrap.querySelector('table');
                if (t2tbl) affectedT2Tables.add(t2tbl);

                // hide semua baris dulu
                wrap.querySelectorAll('.js-t2row').forEach(row => {
                    row.classList.add('collabs-hidden');
                    const nest = row.nextElementSibling;
                    if (nest?.classList.contains('yz-nest')) nest.classList.add('collabs-hidden');
                });

                const checked = wrap.querySelectorAll('.check-so:checked');
                for (const chk of checked) {
                    const soRow = chk.closest('.js-t2row');
                    if (!soRow) continue;

                    // show row + nest
                    soRow.classList.remove('collabs-hidden');
                    const tgt = soRow.nextElementSibling; // tr.yz-nest (T3)
                    const caret = soRow.querySelector('.yz-caret');

                    // Expand
                    if (tgt && tgt.style.display === 'none') {
                        tgt.style.display = '';
                        tgt.dataset.collabsOpen = '1'; // [ADD] tandai bahwa T3 ini dibuka oleh collabs
                        caret?.classList.add('rot');
                        caret?.classList.add('rot');
                    }
                    tgt?.classList.remove('collabs-hidden');

                    // Load jika belum
                    if (tgt && tgt.dataset.loaded !== '1') {
                        const vbeln = (chk.dataset.vbeln || '').trim();
                        const box = tgt.querySelector('.yz-slot-t3');
                        if (box) {
                            box.innerHTML = `
                                <div class="p-2 text-muted small yz-loader-pulse">
                                    <div class="spinner-border spinner-border-sm me-2"></div>Memuat detail…
                                </div>`;
                            const u3 = new URL("{{ route('dashboard.api.t3') }}", window.location.origin);
                            const root = document.getElementById('yz-root');
                            const WERKS = (root?.dataset.werks || '').trim() || null;
                            const AUART = (root?.dataset.auart || '').trim() || null;
                            u3.searchParams.set('vbeln', vbeln);
                            if (WERKS) u3.searchParams.set('werks', WERKS);
                            if (AUART) u3.searchParams.set('auart', AUART);

                            const r3 = await fetch(u3);
                            const j3 = await r3.json();
                            if (j3?.ok) {
                                box.innerHTML = renderT3(j3.data);
                                tgt.dataset.loaded = '1';
                                box.querySelectorAll('.check-item').forEach(ci => {
                                    const sid = sanitizeId(ci.dataset.id);
                                    ci.checked = !!(sid && selectedItems.has(sid));
                                });
                            } else {
                                box.innerHTML =
                                    `<div class="alert alert-danger m-2">Gagal memuat detail item</div>`;
                            }
                        }
                    }
                }
            });

            affectedT2Tables.forEach(tbl => updateT2FooterVisibility(tbl));

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

            // 1) Tampilkan semua baris/customer yang tersembunyi saat fokus
            document.querySelectorAll('.collabs-hidden').forEach(el => el.classList.remove('collabs-hidden'));

            // 2) TUTUP HANYA T3 yang dibuka oleh collabs sebelumnya
            document.querySelectorAll('tr.yz-nest[data-collabs-open="1"]').forEach(tgt => {
                tgt.style.display = 'none'; // close
                tgt.dataset.collabsOpen = ''; // clear flag
                const soRow = tgt.previousElementSibling;
                soRow?.querySelector('.yz-caret')?.classList.remove('rot'); // kembalikan caret
            });

            // 3) Recompute footer visibility per T2
            document.querySelectorAll('.yz-nest-wrap table').forEach(tbl => updateT2FooterVisibility(tbl));

            // Catatan: status ceklis SO/Item TETAP (tidak diubah).
        }


        /* ========= MAIN ========= */
        document.addEventListener('DOMContentLoaded', () => {
            // Label responsif Tabel-1
            document.querySelectorAll('.yz-kunnr-row').forEach(row => {
                row.querySelector('td:nth-child(2)')?.setAttribute('data-label', 'Customer');
                row.querySelector('td:nth-child(3)')?.setAttribute('data-label', 'Overdue PO');
                row.querySelector('td:nth-child(4)')?.setAttribute('data-label', 'Outs. Value');
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
                    }

                    // Footer Tabel-1
                    if (tfootEl) {
                        const anyVisible = [...tableEl.querySelectorAll('tr.yz-nest')]
                            .some(tr => tr.style.display !== 'none' && tr.offsetParent !==
                                null);
                        tfootEl.style.display = anyVisible ? 'none' : '';
                    }

                    if (wasOpen) return;
                    if (wrap.dataset.loaded === '1') {
                        updateCollabsButton();
                        return;
                    }

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
                        updateCollabsButton();

                        // Klik baris SO → toggle & load T3
                        wrap.querySelectorAll('.js-t2row').forEach(soRow => {
                            soRow.addEventListener('click', async (ev) => {
                                if (ev.target.closest('.form-check-input'))
                                    return; // abaikan klik checkbox
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
                                const tbody2 = soRow.closest('tbody');
                                const t2tbl = soRow.closest('table');

                                if (!open) {
                                    tbody2.classList.add('so-focus-mode');
                                    soRow.classList.add('is-focused');
                                } else {
                                    tbody2.classList.remove(
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

                                // Sinkronkan checkbox item yang sudah terpilih
                                box.querySelectorAll('.check-item').forEach(
                                    chk => {
                                        const sid = sanitizeId(chk
                                            .dataset.id);
                                        chk.checked = !!(sid &&
                                            selectedItems.has(sid));
                                    });
                            });
                        });

                        /* Delegasi perubahan checkbox di embed T2/T3 (di dalam wrap) */
                        wrap.addEventListener('change', async (e) => {
                            // Check-all SO
                            if (e.target.classList.contains('check-all-sos')) {
                                const allSO = wrap.querySelectorAll('.check-so');
                                for (const chk of allSO) {
                                    chk.checked = e.target.checked;
                                    const v = chk.dataset.vbeln;

                                    // kalau T3 sudah di DOM
                                    const nest = wrap.querySelector(
                                            `.js-t2row[data-vbeln='${v}']`)
                                        ?.nextElementSibling;
                                    const box = nest?.querySelector('.yz-slot-t3');
                                    if (box && nest.dataset.loaded === '1') {
                                        box.querySelectorAll('.check-item').forEach(
                                            ci => {
                                                const sid = sanitizeId(ci
                                                    .dataset.id);
                                                if (!sid) return;
                                                ci.checked = e.target.checked;
                                                if (e.target.checked)
                                                    selectedItems.add(sid);
                                                else selectedItems.delete(sid);
                                            });
                                        continue;
                                    }

                                    // belum di-load → fetch cepat untuk daftar id
                                    const u3 = new URL(apiT3, window.location
                                        .origin);
                                    u3.searchParams.set('vbeln', v);
                                    if (WERKS) u3.searchParams.set('werks', WERKS);
                                    if (AUART) u3.searchParams.set('auart', AUART);
                                    const r3 = await fetch(u3);
                                    const j3 = await r3.json();
                                    if (j3.ok) {
                                        j3.data.forEach(it => {
                                            const sid = sanitizeId(it.id);
                                            if (!sid) return;
                                            if (e.target.checked)
                                                selectedItems.add(sid);
                                            else selectedItems.delete(sid);
                                            itemIdToSO.set(sid, String(it
                                                .VBELN));
                                        });
                                    }
                                }
                                updateExportButton();
                                updateCollabsButton();
                                return;
                            }

                            // Check SO baris tunggal
                            if (e.target.classList.contains('check-so')) {
                                const v = e.target.dataset.vbeln;
                                const nest = wrap.querySelector(
                                        `.js-t2row[data-vbeln='${v}']`)
                                    ?.nextElementSibling;
                                const box = nest?.querySelector('.yz-slot-t3');

                                if (box && nest.dataset.loaded === '1') {
                                    box.querySelectorAll('.check-item').forEach(
                                        ci => {
                                            const sid = sanitizeId(ci.dataset
                                                .id);
                                            if (!sid) return;
                                            ci.checked = e.target.checked;
                                            if (e.target.checked) selectedItems
                                                .add(sid);
                                            else selectedItems.delete(sid);
                                        });
                                    updateExportButton();
                                    updateCollabsButton();
                                    return;
                                }

                                // fetch cepat
                                const u3 = new URL(apiT3, window.location.origin);
                                u3.searchParams.set('vbeln', v);
                                if (WERKS) u3.searchParams.set('werks', WERKS);
                                if (AUART) u3.searchParams.set('auart', AUART);
                                const r3 = await fetch(u3);
                                const j3 = await r3.json();
                                if (j3.ok) {
                                    j3.data.forEach(it => {
                                        const sid = sanitizeId(it.id);
                                        if (!sid) return;
                                        if (e.target.checked) selectedItems
                                            .add(sid);
                                        else selectedItems.delete(sid);
                                        itemIdToSO.set(sid, String(it
                                            .VBELN));
                                    });
                                }
                                updateExportButton();
                                updateCollabsButton();
                                return;
                            }

                            // Check-all items (di T3)
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
                                updateExportButton();
                                updateCollabsButton();
                                return;
                            }

                            // Check single item
                            if (e.target.classList.contains('check-item')) {
                                const sid = sanitizeId(e.target.dataset.id);
                                if (!sid) return;
                                if (e.target.checked) selectedItems.add(sid);
                                else selectedItems.delete(sid);
                                updateExportButton();
                                updateCollabsButton();
                                return;
                            }
                        });

                    } catch (err) {
                        wrap.innerHTML =
                            `<div class="alert alert-danger m-3">${err.message}</div>`;
                    }
                });
            });

            // [NEW] Handler tombol Collabs → fokus hanya SO terpilih (buka T3)
            if (btnCollabs) {
                btnCollabs.addEventListener('click', async () => {
                    if (countCheckedSO() === 0) {
                        alert('Pilih minimal 1 SO di Tabel-2.');
                        return;
                    }
                    enterCollabsMode();
                });
            }
            // [NEW] Keluar Mode Collabs
            if (btnCollabsExit) {
                btnCollabsExit.addEventListener('click', () => {
                    exitCollabsMode();
                });
            }

            // Export handler (tetap sama)
            if (exportDropdownContainer) {
                exportDropdownContainer.addEventListener('click', (e) => {
                    if (!e.target.classList.contains('export-option')) return;
                    e.preventDefault();
                    if (selectedItems.size === 0) {
                        alert('Pilih minimal 1 item.');
                        return;
                    }

                    const exportType = e.target.dataset.type;

                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = "{{ route('po.export') }}";
                    form.target = '_blank';

                    const csrf = document.createElement('input');
                    csrf.type = 'hidden';
                    csrf.name = '_token';
                    csrf.value = "{{ csrf_token() }}";
                    form.appendChild(csrf);

                    const t = document.createElement('input');
                    t.type = 'hidden';
                    t.name = 'export_type';
                    t.value = exportType;
                    form.appendChild(t);

                    const w = document.createElement('input');
                    w.type = 'hidden';
                    w.name = 'werks';
                    w.value = "{{ $selected['werks'] ?? '' }}";
                    form.appendChild(w);

                    const a = document.createElement('input');
                    a.type = 'hidden';
                    a.name = 'auart';
                    a.value = "{{ $selected['auart'] ?? '' }}";
                    form.appendChild(a);

                    Array.from(selectedItems).forEach(id => {
                        const i = document.createElement('input');
                        i.type = 'hidden';
                        i.name = 'item_ids[]';
                        i.value = id;
                        form.appendChild(i);
                    });

                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);
                });
            }
        });
    </script>
@endpush
