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
        HEADER: PILIH TYPE (SELALU tampil jika plant dipilih)
    ========================================================= --}}
    @if (filled($werks))
        @php
            $typesForPlant = collect($mapping[$werks] ?? []);
            $selectedAuart = trim((string) ($auart ?? ''));
        @endphp

        <div class="card yz-card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                <div class="py-1 w-100">
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
            </div>
        </div>
    @endif

    {{-- =========================================================
        A. MODE TABEL (LAPORAN PO) – KODE UTAMA PO REPORT
    ========================================================= --}}
    @if ($show && $compact)
        <div class="card yz-card shadow-sm mb-3">
            <div class="card-body p-0 p-md-2">
                <div class="p-3 mx-md-3 mt-md-3 yz-main-title-wrapper">
                    <h5 class="yz-table-title mb-0">
                        <i class="fas fa-users me-2"></i>Overview Customer
                    </h5>
                </div>

                {{-- SISA KODE PHP UNTUK PERHITUNGAN TOTAL DAN FORMAT MATA UANG --}}
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
@endpush

@push('scripts')
    <script>
        // ... (SALIN SEMUA FUNGSI JAVASCRIPT: formatCurrencyForTable, renderT2, renderT3, handleSearchHighlight, dan event listener click expand/collapse)
        // ... (gunakan `route('dashboard.api.t2')` dan `route('dashboard.api.t3')` karena kita biarkan API di DashboardController)

        const formatCurrencyForTable = (value, currency) => {
            const n = parseFloat(value);
            if (!Number.isFinite(n)) return '';
            const options = {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            };
            if (currency === 'IDR') return `Rp ${n.toLocaleString('id-ID', options)}`;
            if (currency === 'USD') return `$${n.toLocaleString('en-US', options)}`;
            return `${currency} ${n.toLocaleString('id-ID', options)}`;
        };

        function renderT2(rows, kunnr) {
            if (!rows?.length)
                return `<div class="p-3 text-muted">Tidak ada data PO untuk KUNNR <b>${kunnr}</b>.</div>`;
            const totalsByCurr = {};
            rows.forEach(r => {
                const cur = (r.WAERK || '').trim();
                const val = parseFloat(r.TOTPR) || 0;
                totalsByCurr[cur] = (totalsByCurr[cur] || 0) + val;
            });

            let html = `<div style="width:100%"><h5 class="yz-table-title-nested yz-title-so"><i class="fas fa-file-invoice me-2"></i>Overview PO</h5>
            <table class="table table-sm mb-0 yz-mini">
              <thead class="yz-header-so">
                <tr>
                  <th style="width:40px;text-align:center;"></th>
                  <th style="min-width:150px;text-align:left;">PO</th>
                  <th style="min-width:100px;text-align:left;">SO</th>
                  <th style="min-width:100px;text-align:right;">Outs. Value</th>
                  <th style="min-width:100px;text-align:center;">Req. Delv Date</th>
                  <th style="min-width:100px;text-align:center;">Overdue (Days)</th>
                  <th style="min-width:120px;text-align:center;">Shortage %</th>
                </tr>
              </thead><tbody>`;

            rows.forEach((r, i) => {
                const rid = `t3_${kunnr}_${r.VBELN}_${i}`;
                const overdueDays = r.Overdue;
                const rowHighlightClass = overdueDays < 0 ? 'yz-row-highlight-negative' : '';
                const edatuDisplay = r.FormattedEdatu || '';
                const shortageDisplay = `${(r.ShortagePercentage || 0).toFixed(2)}%`;
                html += `<tr class="yz-row js-t2row ${rowHighlightClass}" data-vbeln="${r.VBELN}" data-tgt="${rid}">
                <td style="text-align:center;"><span class="yz-caret">▸</span></td>
                <td style="text-align:left;">${r.BSTNK ?? ''}</td>
                <td class="yz-t2-vbeln" style="text-align:left;">${r.VBELN}</td>
                <td style="text-align:right;">${formatCurrencyForTable(r.TOTPR, r.WAERK)}</td>
                <td style="text-align:center;">${edatuDisplay}</td>
                <td style="text-align:center;">${overdueDays ?? 0}</td>
                <td style="text-align:center;">${shortageDisplay}</td>
              </tr>
              <tr id="${rid}" class="yz-nest" style="display:none;">
                <td colspan="7" class="p-0">
                  <div class="yz-nest-wrap level-2" style="margin-left:0;padding:.5rem;">
                    <div class="yz-slot-t3 p-2"></div>
                  </div>
                </td>
              </tr>`;
            });

            html += `</tbody><tfoot>`;
            Object.entries(totalsByCurr).forEach(([cur, sum]) => {
                html += `<tr class="table-light">
                <th></th>
                <th colspan="2" style="text-align:left;">Total (${cur || 'N/A'})</th>
                <th style="text-align:right;">${formatCurrencyForTable(sum, cur)}</th>
                <th style="text-align:center;">—</th>
                <th style="text-align:center;">—</th>
                <th style="text-align:center;">—</th>
              </tr>`;
            });
            html += `</tfoot></table></div>`;
            return html;
        }

        function renderT3(rows) {
            if (!rows?.length) return `<div class="p-2 text-muted">Tidak ada item detail.</div>`;
            let out = `<div class="table-responsive"><table class="table table-sm mb-0 yz-mini">
              <thead class="yz-header-item">
                <tr>
                  <th style="min-width:80px; text-align:center;">Item</th>
                  <th style="min-width:150px; text-align:center;">Material FG</th>
                  <th style="min-width:300px">Desc FG</th>
                  <th style="min-width:80px">Qty PO</th>
                  <th style="min-width:60px">Shipped</th>
                  <th style="min-width:60px">Outs. Ship</th>
                  <th style="min-width:80px">WHFG</th>
                  <th style="min-width:100px">Net Price</th>
                  <th style="min-width:80px">Outs. Ship Value</th>
                </tr>
              </thead><tbody>`;
            rows.forEach(r => {
                out += `<tr>
                <td style="text-align:center;">${r.POSNR ?? ''}</td>
                <td style="text-align:center;">${r.MATNR ?? ''}</td>
                <td>${r.MAKTX ?? ''}</td>
                <td>${parseFloat(r.KWMENG).toLocaleString('id-ID')}</td>
                <td>${parseFloat(r.QTY_GI).toLocaleString('id-ID')}</td>
                <td>${parseFloat(r.QTY_BALANCE2).toLocaleString('id-ID')}</td>
                <td>${parseFloat(r.KALAB).toLocaleString('id-ID')}</td>
                <td>${formatCurrencyForTable(r.NETPR, r.WAERK)}</td>
                <td>${formatCurrencyForTable(r.TOTPR, r.WAERK)}</td>
              </tr>`;
            });
            out += `</tbody></table></div>`;
            return out;
        }


        document.addEventListener('DOMContentLoaded', function() {
            // Hilangkan semua toggle currency (USD/IDR) di header jika ada
            document.querySelectorAll('.yz-currency-toggle').forEach(el => el.remove());

            // Tambahkan label untuk responsif tabel
            const customerRows = document.querySelectorAll('.yz-kunnr-row');
            customerRows.forEach(row => {
                row.querySelector('td:nth-child(2)')?.setAttribute('data-label', 'Customer');
                row.querySelector('td:nth-child(3)')?.setAttribute('data-label', 'Overdue PO');
                row.querySelector('td:nth-child(4)')?.setAttribute('data-label', 'Outs. Value');
            });

            const rootElement = document.getElementById('yz-root');
            const showTable = rootElement ? !!parseInt(rootElement.dataset.show) : false;

            /* ---------- MODE TABEL (LAPORAN) ---------- */
            if (showTable) {
                // Gunakan route API yang baru/sudah ada
                const apiT2 = "{{ route('dashboard.api.t2') }}";
                const apiT3 = "{{ route('dashboard.api.t3') }}";
                const apiDecryptPayload = "{{ route('dashboard.api.decrypt_payload') }}";
                const WERKS = (rootElement.dataset.werks || '').trim() || null;
                const AUART = (rootElement.dataset.auart || '').trim() || null;

                // ... (Event listener click expand/collapse)
                document.querySelectorAll('.yz-kunnr-row').forEach(row => {
                    row.addEventListener('click', async () => {
                        const kunnr = (row.dataset.kunnr || '').trim();
                        const kid = row.dataset.kid;
                        const slot = document.getElementById(kid);
                        const wrap = slot?.querySelector('.yz-nest-wrap');

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
                            wrap?.querySelectorAll('tr.yz-nest').forEach(tr => tr.style
                                .display = 'none');
                            wrap?.querySelectorAll('tbody.so-focus-mode').forEach(tb => tb
                                .classList.remove('so-focus-mode'));
                            wrap?.querySelectorAll('.js-t2row.is-focused').forEach(r => r
                                .classList.remove('is-focused'));
                            wrap?.querySelectorAll('.js-t2row .yz-caret.rot').forEach(c => c
                                .classList.remove('rot'));
                        }

                        if (tfootEl) {
                            const anyVisibleNest = [...tableEl.querySelectorAll('tr.yz-nest')]
                                .some(tr => tr.style.display !== 'none' && tr.offsetParent !==
                                    null);
                            tfootEl.style.display = anyVisibleNest ? 'none' : '';
                        }

                        if (wasOpen) return;
                        if (wrap.dataset.loaded === '1') return;

                        try {
                            wrap.innerHTML = `<div class="p-3 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
                              <div class="spinner-border spinner-border-sm me-2"></div>Memuat data…
                            </div>`;

                            const url = new URL(apiT2, window.location.origin);
                            url.searchParams.set('kunnr', kunnr);
                            if (typeof WERKS !== 'undefined' && WERKS) url.searchParams.set(
                                'werks', WERKS);
                            if (typeof AUART !== 'undefined' && AUART) url.searchParams.set(
                                'auart', AUART);

                            const res = await fetch(url);
                            if (!res.ok) throw new Error('Network response was not ok');
                            const js = await res.json();
                            if (!js.ok) throw new Error(js.error || 'Gagal memuat data PO');

                            wrap.innerHTML = renderT2(js.data, kunnr);
                            wrap.dataset.loaded = '1';

                            wrap.querySelectorAll('.js-t2row').forEach(row2 => {
                                row2.addEventListener('click', async (ev) => {
                                    ev.stopPropagation();

                                    const vbeln = (row2.dataset.vbeln || '')
                                        .trim();
                                    const tgtId = row2.dataset.tgt;
                                    const caret = row2.querySelector(
                                        '.yz-caret');
                                    const tgt = wrap.querySelector('#' +
                                        tgtId);
                                    const body = tgt.querySelector(
                                        '.yz-slot-t3');
                                    const open = tgt.style.display !==
                                        'none';
                                    const tbody2 = row2.closest('tbody');

                                    if (!open) {
                                        tbody2.classList.add(
                                            'so-focus-mode');
                                        row2.classList.add('is-focused');
                                    } else {
                                        tbody2.classList.remove(
                                            'so-focus-mode');
                                        row2.classList.remove('is-focused');
                                    }

                                    if (open) {
                                        tgt.style.display = 'none';
                                        caret?.classList.remove('rot');
                                        return;
                                    }

                                    tgt.style.display = '';
                                    caret?.classList.add('rot');

                                    if (tgt.dataset.loaded === '1') return;

                                    body.innerHTML = `<div class="p-2 text-muted small yz-loader-pulse">
                                      <div class="spinner-border spinner-border-sm me-2"></div>Memuat detail…
                                    </div>`;

                                    const u3 = new URL(apiT3, window
                                        .location.origin);
                                    u3.searchParams.set('vbeln', vbeln);
                                    if (typeof WERKS !== 'undefined' &&
                                        WERKS) u3.searchParams.set('werks',
                                        WERKS);
                                    if (typeof AUART !== 'undefined' &&
                                        AUART) u3.searchParams.set('auart',
                                        AUART);

                                    const r3 = await fetch(u3);
                                    if (!r3.ok) throw new Error(
                                        'Network response was not ok for item details'
                                    );
                                    const j3 = await r3.json();
                                    if (!j3.ok) throw new Error(j3.error ||
                                        'Gagal memuat detail item');

                                    body.innerHTML = renderT3(j3.data);
                                    tgt.dataset.loaded = '1';
                                });
                            });
                        } catch (e) {
                            console.error(e);
                            wrap.innerHTML =
                                `<div class="alert alert-danger m-3">${e.message}</div>`;
                        }
                    });
                });

                // highlight hasil pencarian dari Search PO
                const handleSearchHighlight = () => {
                    const urlParams = new URLSearchParams(window.location.search);
                    const encryptedPayload = urlParams.get('q');
                    if (!encryptedPayload) return;

                    fetch(apiDecryptPayload, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                                    .getAttribute('content')
                            },
                            body: JSON.stringify({
                                q: encryptedPayload
                            })
                        })
                        .then(res => res.json())
                        .then(result => {
                            if (!result.ok || !result.data) return;

                            const params = result.data;
                            const highlightKunnr = params.highlight_kunnr;
                            const highlightVbeln = params.highlight_vbeln;

                            if (highlightKunnr && highlightVbeln) {
                                const customerRow = document.querySelector(
                                    `.yz-kunnr-row[data-kunnr="${highlightKunnr}"]`);
                                if (customerRow) {
                                    customerRow.click();
                                    let attempts = 0,
                                        maxAttempts = 50;
                                    const interval = setInterval(() => {
                                        const soRow = document.querySelector(
                                            `.js-t2row[data-vbeln="${highlightVbeln}"]`);
                                        if (soRow) {
                                            clearInterval(interval);
                                            soRow.classList.add('row-highlighted');
                                            soRow.addEventListener('click', () => {
                                                soRow.classList.remove('row-highlighted');
                                            }, {
                                                once: true
                                            });
                                            setTimeout(() => soRow.scrollIntoView({
                                                behavior: 'smooth',
                                                block: 'center'
                                            }), 500);
                                        }
                                        attempts++;
                                        if (attempts > maxAttempts) clearInterval(interval);
                                    }, 100);
                                }
                            }
                        }).catch(console.error);
                };
                handleSearchHighlight();
            }

        });
    </script>
@endpush
