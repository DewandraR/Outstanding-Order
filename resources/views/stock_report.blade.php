{{-- resources/views/stock_report.blade.php --}}
@extends('layouts.app')

@section('title', 'Laporan Stok')

@section('content')

    @php
        $selectedWerks = $selected['werks'] ?? null;
        $selectedType = $selected['type'] ?? null;

        $locationMap = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locName = $locationMap[$selectedWerks] ?? $selectedWerks;

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
      TABEL UTAMA (Overview Customer)
      ========================= --}}
    @if ($rows)
        <div class="card yz-card shadow-sm">
            <div class="card-body p-0 p-md-2">

                <div class="p-3 mx-md-3 mt-md-3 yz-main-title-wrapper">
                    <h5 class="yz-table-title mb-0">
                        <i class="fas fa-users me-2"></i>
                        Overview Customer
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
                                        <p>Tidak ada data yang cocok untuk filter yang Anda pilih.</p>
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
            user-select: none;
            /* Tambahkan margin di sini untuk mengatur posisi awal */
            margin-right: 5px;
            vertical-align: middle;
            line-height: 1;
            /* Pastikan tinggi baris 1 */
        }

        .yz-caret.rot {
            transform: rotate(90deg)
        }

        /* sembunyikan tfoot saat customer focus mode aktif */
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

        /* tombol kolaps di header Tabel-2 */
        .yz-header-so .js-collapse-toggle {
            line-height: 1;
            padding: 2px 8px;
            /* Pastikan lebar tombol header tidak terlalu besar/kecil */
        }

        /* Sesuaikan style caret di header agar seragam dengan caret di baris data */
        .yz-header-so .yz-collapse-caret {
            display: inline-block;
            transition: transform .18s ease
        }

        /* Mengatur posisi caret di baris SO agar sejajar dengan tombol collapse di header */
        .yz-t2-vbeln .yz-caret {
            /* Hapus margin-right di sini jika sudah diatur di .yz-caret global,
                               atau gunakan posisi relatif/absolut jika pengaturan kolom tabel tidak ideal */
            margin-right: 5px;
        }

        /* Tambahkan padding/margin kiri pada sel VBELN jika perlu */
        .yz-t2-vbeln {
            padding-left: 0.75rem !important;
            /* Contoh: Menyesuaikan padding sel */
        }


        tbody.so-focus-mode~tfoot {
            display: none !important;
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

            const selectedItems = new Set(); // id item terpilih (untuk export)
            const itemIdToSO = new Map(); // itemId -> VBELN
            const itemsCache = new Map(); // VBELN  -> array items

            // NEW: mode kolaps/fokus pada Tabel-2
            let COLLAPSE_MODE = false;

            // --- Helpers ---
            // Helper standar
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
            const sanitizeId = (v) => {
                const s = String(v ?? '').replace(/\D+/g, '');
                return s.length ? s : null;
            };


            function updateExportButton() {
                const n = selectedItems.size;
                if (selectedCountSpan) selectedCountSpan.textContent = n;
                if (exportDropdownContainer) exportDropdownContainer.style.display = n > 0 ? 'block' : 'none';
            }

            function updateSODot(vbeln) {
                const anySel = Array.from(selectedItems).some(id => itemIdToSO.get(String(id)) === vbeln);
                document.querySelectorAll(`.js-t2row[data-vbeln='${vbeln}'] .so-selected-dot`)
                    .forEach(dot => dot.style.display = anySel ? 'inline-block' : 'none');
            }

            function syncSelectAllItemsState(container) {
                const itemCheckboxes = container.querySelectorAll('.check-item');
                const selectAll = container.querySelector('.check-all-items');
                if (!selectAll || !itemCheckboxes.length) return;
                const allChecked = Array.from(itemCheckboxes).every(ch => ch.checked);
                selectAll.checked = allChecked;
                // [STOCK REPORT ASLI] menggunakan indeterminate untuk partial selection
                // Namun, untuk konsistensi dengan permintaan di PO Report (menjadi kotak kosong),
                // kita akan ubah logika indeterminate menjadi false.
                selectAll.indeterminate = !allChecked && Array.from(itemCheckboxes).some(ch => ch.checked);
            }

            // 🟢 MODIFIED: Helper untuk sinkronisasi status checkbox header SO (Non-Indeterminate)
            function syncSelectAllSoState(tbody) {
                const allSoCheckboxes = Array.from(tbody.querySelectorAll('.check-so'));
                const visibleSoCheckboxes = allSoCheckboxes.filter(ch => ch.closest('tr').style.display !== 'none');
                const soCheckboxesToConsider = COLLAPSE_MODE ? visibleSoCheckboxes : allSoCheckboxes;

                const selectAllSo = tbody.closest('table')?.querySelector('.check-all-sos');

                if (!selectAllSo || soCheckboxesToConsider.length === 0) {
                    selectAllSo.checked = false;
                    selectAllSo.indeterminate = false;
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
                    // [PERBAIKAN] Jika hanya sebagian yang tercentang, jadikan kotak kosong, BUKAN strip.
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

            function clearRenderedItemsUnderSO(nest) {
                if (!nest) return;
                const box = nest.querySelector('.yz-slot-items');
                if (!box) return;
                box.querySelectorAll('.check-item').forEach(ch => ch.checked = false);
                const selAll = box.querySelector('.check-all-items');
                if (selAll) selAll.checked = false, selAll.indeterminate = false;
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

            // ===== RENDER LEVEL 2 (SO) –— SESUAIKAN KOLOM KE-2/KE-3 UNTUK CARET =====
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
                <td class="yz-t2-vbeln text-start">${r.VBELN}</td>
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
                html += `</tbody></table>`;
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

            // ===== Helper untuk buka/tutup SO row (digunakan di mode kolaps) =====
            async function openItemsIfNeededForSORow(soRow) {
                const vbeln = soRow.dataset.vbeln;
                const nest = soRow?.nextElementSibling;
                // Ambil caret dari kolom ke-2
                const caret = soRow?.querySelector('td:nth-child(2) .yz-caret');
                if (!nest) return;
                if (nest.style.display === 'none') {
                    nest.style.display = '';
                    caret?.classList.add('rot');
                }
                const box = nest.querySelector('.yz-slot-items');
                if (nest.dataset.loaded !== '1') {
                    const items = await ensureItemsLoadedForSO(vbeln);
                    box.innerHTML = renderLevel3_Items(items);
                    applySelectionsToRenderedItems(box);
                    nest.dataset.loaded = '1';
                } else {
                    applySelectionsToRenderedItems(box);
                }
            }

            function closeItemsForSORow(soRow) {
                const nest = soRow?.nextElementSibling;
                // Ambil caret dari kolom ke-2
                const caret = soRow?.querySelector('td:nth-child(2) .yz-caret');
                if (nest) {
                    nest.style.display = 'none';
                    caret?.classList.remove('rot');
                }
            }

            // 🟢 MODIFIED: applyCollapseView dengan Auto-Keluar saat nol
            async function applyCollapseView(tbodyEl, on) {
                COLLAPSE_MODE = on;

                // Update caret tombol header
                const headerCaret = tbodyEl.closest('table')?.querySelector(
                    '.js-collapse-toggle .yz-collapse-caret');
                if (headerCaret) headerCaret.textContent = on ? '▾' : '▸';

                // Bersihkan placeholder lama
                const oldEmpty = tbodyEl.querySelector('.yz-empty-selected-row');
                if (oldEmpty) oldEmpty.remove();

                // HAPUS efek "so-focus-mode" agar baris SO tidak disembunyikan global
                tbodyEl.classList.remove('so-focus-mode'); // <-- kunci
                tbodyEl.classList.toggle('collapse-mode', on); // (opsional) kelas baru hanya untuk penanda

                if (on) {
                    let visibleCount = 0;
                    const rows = tbodyEl.querySelectorAll('.js-t2row');

                    for (const r of rows) {
                        const chk = r.querySelector('.check-so');
                        // pastikan semua baris tidak membawa status fokus lama
                        r.classList.remove('is-focused');

                        if (chk?.checked) {
                            r.style.display = ''; // tampilkan baris SO
                            await openItemsIfNeededForSORow(r); // buka Tabel-3
                            visibleCount++;
                        } else {
                            r.style.display = 'none'; // sembunyikan SO yang tak dipilih
                            closeItemsForSORow(r); // tutup Tabel-3-nya bila terbuka
                        }
                    }

                    // [PERBAIKAN UTAMA] Jika tidak ada yang terlihat, matikan mode kolaps
                    if (visibleCount === 0) {
                        await applyCollapseView(tbodyEl, false); // Rekursif ke mode normal
                        return; // Keluar
                    }

                } else {
                    // Mode normal: tampilkan semua SO & tutup semua Tabel-3
                    const rows = tbodyEl.querySelectorAll('.js-t2row');
                    rows.forEach(r => {
                        r.style.display = '';
                        r.classList.remove('is-focused');
                        closeItemsForSORow(r);
                    });
                }

                // 🟢 Sinkronisasi status checkbox header SO setelah applyCollapseView
                if (tbodyEl) syncSelectAllSoState(tbodyEl);
            }

            // ===== Expand/collapse Level-1 (Customer) -> Level-2 (SO) =====
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

                    // FOCUS MODE utk klik Customer (tetap seperti semula)
                    if (!wasOpen) {
                        tbodyEl.classList.add('customer-focus-mode');
                        row.classList.add('is-focused');
                    } else {
                        tbodyEl.classList.remove('customer-focus-mode');
                        row.classList.remove('is-focused');
                        wrap?.querySelectorAll('tbody.so-focus-mode').forEach(tb => tb.classList
                            .remove('so-focus-mode'));
                        wrap?.querySelectorAll('.js-t2row.is-focused').forEach(r => r.classList
                            .remove('is-focused'));
                        wrap?.querySelectorAll('.yz-caret.rot').forEach(c => c.classList.remove(
                            'rot'));
                        wrap?.querySelectorAll('tr.yz-nest').forEach(tr => tr.style.display =
                            'none');
                        COLLAPSE_MODE = false; // reset mode kolaps saat tutup customer
                    }

                    row.classList.toggle('is-open');
                    slot.style.display = wasOpen ? 'none' : '';

                    // sembunyikan tfoot saat ada nest terlihat
                    const anyVisibleNest = [...tableEl.querySelectorAll('tr.yz-nest')]
                        .some(tr => tr.style.display !== 'none' && tr.offsetParent !== null);
                    if (tfootEl) tfootEl.style.display = anyVisibleNest ? 'none' : '';

                    if (wasOpen) return;

                    if (wrap.dataset.loaded === '1') return;

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

                        // Bind tombol kolaps header
                        const soTable = wrap.querySelector('table.yz-mini');
                        const soTbody = soTable?.querySelector('tbody');
                        const collapseBtn = soTable?.querySelector('.js-collapse-toggle');
                        collapseBtn?.addEventListener('click', async (ev) => {
                            ev.stopPropagation();
                            await applyCollapseView(soTbody, !COLLAPSE_MODE);
                        });

                        // Bind Level-3 (SO -> Items) untuk klik baris (tetap ada untuk manual buka/tutup)
                        wrap.querySelectorAll('.js-t2row').forEach(soRow => {
                            updateSODot(soRow.dataset.vbeln);

                            soRow.addEventListener('click', async (ev) => {
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

                                // Ambil caret dari kolom ke-2
                                soRow.querySelector(
                                        'td:nth-child(2) .yz-caret')
                                    ?.classList
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
                                    return;
                                }

                                itemTr.style.display = '';
                                if (itemTr.dataset.loaded === '1') {
                                    applySelectionsToRenderedItems(itemBox);
                                    return;
                                }

                                itemBox.innerHTML = `<div class="p-2 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
                                <div class="spinner-border spinner-border-sm me-2"></div>Memuat item…
                            </div>`;
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
                        // 🟢 BARU: Sinkronisasi status checkbox header SO saat pertama kali dimuat
                        if (soTbody) syncSelectAllSoState(soTbody);
                    } catch (e) {
                        wrap.innerHTML =
                            `<div class="alert alert-danger m-3">${e.message}</div>`;
                    }
                });
            });

            // ===== Cegah checkbox di Tabel-2 memicu toggle baris =====
            document.body.addEventListener('click', (e) => {
                if (e.target.classList.contains('check-so') || e.target.classList.contains(
                        'check-all-sos')) {
                    e.stopPropagation();
                }
            }, true);

            // ===== Change events (pilih SO / Item) =====
            document.body.addEventListener('change', async (e) => {
                // Select all items pada Tabel-3
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
                            // 🟢 BARU: Panggil sinkronisasi untuk Tabel 2 (SO) setelah mengubah item
                            const soRow = document.querySelector(`.js-t2row[data-vbeln='${vbeln}']`);
                            const tbody = soRow?.closest('tbody');
                            if (tbody) syncSelectAllSoState(tbody);
                        }
                    }
                    updateExportButton();
                    return;
                }

                // Select All SO dalam satu customer
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

                    // 🟢 Panggil sinkronisasi status checkbox header SO
                    if (tbody) syncSelectAllSoState(tbody);

                    if (COLLAPSE_MODE) await applyCollapseView(tbody, true);

                    updateExportButton();
                    return;
                }

                // Pilih satu SO
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
                    // 🟢 BARU: Panggil sinkronisasi status checkbox header SO
                    if (tbody) syncSelectAllSoState(tbody);

                    // 🟢 PENTING: Cek dan panggil applyCollapseView jika mode aktif (untuk auto-exit)
                    if (COLLAPSE_MODE && tbody) await applyCollapseView(tbody, true);

                    updateExportButton();
                    return;
                }

                // Pilih item
                if (e.target.classList.contains('check-item')) {
                    const id = e.target.dataset.id;
                    if (e.target.checked) selectedItems.add(id);
                    else selectedItems.delete(id);

                    const box = e.target.closest('.yz-slot-items') || document;
                    syncSelectAllItemsState(box);

                    const vbeln = itemIdToSO.get(String(id));
                    if (vbeln) {
                        updateSODot(vbeln);
                        // 🟢 BARU: Panggil sinkronisasi status checkbox header SO setelah mengubah item
                        const soRow = document.querySelector(`.js-t2row[data-vbeln='${vbeln}']`);
                        const tbody = soRow?.closest('tbody');
                        if (tbody) syncSelectAllSoState(tbody);
                    }
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

            // label kolom aksesibel di mobile
            document.querySelectorAll('.yz-kunnr-row').forEach(row => {
                row.querySelector('td:nth-child(2)')?.setAttribute('data-label', 'Customer');
                row.querySelector('td:nth-child(3)')?.setAttribute('data-label', 'Total Stock Qty');
                row.querySelector('td:nth-child(4)')?.setAttribute('data-label', 'Total Value');
            });
        });
    </script>
@endpush
