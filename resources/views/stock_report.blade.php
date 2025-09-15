@extends('layouts.app')

@section('title','Laporan Stok')

@section('content')

@php
$selectedWerks = $selected['werks'] ?? null;
$selectedType = $selected['type'] ?? null;
$locationMap = ['2000' => 'Surabaya', '3000' => 'Semarang'];
$locName = $locationMap[$selectedWerks] ?? $selectedWerks;
@endphp

{{-- Header dengan filter WHFG dan FG --}}
<div class="card yz-card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <div class="py-1 w-100">
            @if($selectedWerks)
            <ul class="nav nav-pills yz-auart-pills p-1 flex-wrap" style="border-radius:.75rem;">
                <li class="nav-item mb-2 me-2">
                    <a class="nav-link pill-green {{ $selectedType == 'whfg' ? 'active' : '' }}"
                        href="{{ route('stock.index', ['werks' => $selectedWerks, 'type' => 'whfg']) }}">
                        WHFG (Stock > 0)
                    </a>
                </li>
                <li class="nav-item mb-2 me-2">
                    <a class="nav-link pill-green {{ $selectedType == 'fg' ? 'active' : '' }}"
                        href="{{ route('stock.index', ['werks' => $selectedWerks, 'type' => 'fg']) }}">
                        FG (Stock Packing > 0)
                    </a>
                </li>
            </ul>
            @else
            <i class="fas fa-info-circle me-2"></i> Pilih Plant (Surabaya/Semarang) dari sidebar untuk memulai.
            @endif
        </div>
    </div>
</div>

{{-- Tabel Utama --}}
@if($rows)
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
                        <th style="min-width:120px; text-align:center;">SO Count</th>
                        <th style="min-width:150px; text-align:center;">Value</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($rows as $r)
                    @php $kid = 'krow_'.$r->KUNNR.'_'.$loop->index; @endphp
                    <tr class="yz-kunnr-row" data-kunnr="{{ $r->KUNNR }}" data-kid="{{ $kid }}" title="Klik untuk melihat detail SO">
                        <td class="sticky-col-mobile-disabled"><span class="kunnr-caret"><i class="fas fa-chevron-right"></i></span></td>
                        <td class="sticky-col-mobile-disabled text-start"><span class="fw-bold">{{ $r->NAME1 }}</span></td>
                        <td class="text-center">{{ $r->SO_COUNT }}</td>
                        <td class="text-center">
                            @php
                            if ($r->WAERK === 'IDR') { echo 'Rp ' . number_format($r->TOTAL_VALUE, 2, ',', '.'); }
                            elseif ($r->WAERK === 'USD') { echo '$' . number_format($r->TOTAL_VALUE, 2, '.', ','); }
                            else { echo ($r->WAERK ?? '') . ' ' . number_format($r->TOTAL_VALUE, 2, ',', '.'); }
                            @endphp
                        </td>
                    </tr>
                    <tr id="{{ $kid }}" class="yz-nest" style="display:none;">
                        <td colspan="5" class="p-0">
                            <div class="yz-nest-wrap">
                                <div class="p-3 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
                                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>Memuat data…
                                </div>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="text-center p-5">
                            <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">Data tidak ditemukan</h5>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif
@endsection

@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard-style.css') }}">
@endpush

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // DIUBAH: Menggunakan route dan parameter yang benar untuk menu Stok
        const apiSoByCustomer = "{{ route('stock.api.by_customer') }}";
        const apiItemsBySo = "{{ route('stock.api.by_items') }}";
        const qs = new URLSearchParams(window.location.search);
        const WERKS = (qs.get('werks') || '').trim();
        const TYPE = (qs.get('type') || '').trim(); // Menggunakan 'type' bukan 'auart'

        // (Fungsi-fungsi helper JavaScript lainnya tetap sama)
        const formatCurrency = (value, currency, decimals = 2) => {
            const n = parseFloat(value);
            if (!Number.isFinite(n)) return '';
            const opt = {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            };
            if (currency === 'IDR') return `Rp ${n.toLocaleString('id-ID', opt)}`;
            if (currency === 'USD') return `$${n.toLocaleString('en-US', opt)}`;
            return `${currency} ${n.toLocaleString('id-ID', opt)}`;
        };
        const formatNumber = (num, decimals = 0) => {
            const n = parseFloat(num);
            if (!Number.isFinite(n)) return '';
            return n.toLocaleString('id-ID', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            });
        };

        function renderLevel2_SO(rows, kunnr) {
            if (!rows?.length) return `<div class="p-3 text-muted">Tidak ada data Outstanding SO untuk customer ini.</div>`;

            // DIUBAH: Header tabel disederhanakan
            let html = `<div><h5 class="yz-table-title-nested yz-title-so"><i class="fas fa-file-invoice me-2"></i>Outstanding SO</h5>
        <table class="table table-sm mb-0 yz-mini">
            <thead class="yz-header-so">
                <tr>
                    <th style="width:40px;"></th>
                    <th class="text-start">SO</th>
                    <th class="text-center">SO Item Count</th>
                    <th class="text-center">Value</th>
                </tr>
            </thead>
            <tbody>`;

            rows.forEach((r, i) => {
                const rid = `t3_${kunnr}_${r.VBELN}_${i}`;

                // DIHAPUS: Logika highlight baris merah
                // const rowHighlightClass = r.Overdue < 0 ? 'yz-row-highlight-negative' : '';

                // DIUBAH: Baris tabel disederhanakan, tanpa highlight dan kolom ekstra
                html += `<tr class="yz-row js-t2row" data-vbeln="${r.VBELN}" data-tgt="${rid}">
                <td class="text-center"><span class="yz-caret">▸</span></td>
                <td class="yz-t2-vbeln text-start">${r.VBELN}</td>
                <td class="text-center">${r.item_count ?? '-'}</td>
                <td class="text-center">${formatCurrency(r.total_value, r.WAERK)}</td>
            </tr>
            <tr id="${rid}" class="yz-nest" style="display:none;">
                <td colspan="4" class="p-0">
                    <div class="yz-nest-wrap level-2" style="margin-left:0; padding:.5rem;">
                        <div class="yz-slot-items p-2"></div>
                    </div>
                </td>
            </tr>`;
            });
            html += `</tbody></table></div>`;
            return html;
        }

        function renderLevel3_Items(rows) {
            if (!rows?.length) return `<div class="p-2 text-muted">Tidak ada item detail untuk filter stok ini.</div>`;

            // Logika untuk menentukan header dan sel kolom secara dinamis
            const whfgHeader = TYPE === 'whfg' ? '<th>WHFG</th>' : '';
            // DITAMBAHKAN: Logika untuk header Stock Packing
            const stockPackingHeader = TYPE === 'fg' ? '<th>Stock Packing</th>' : '';

            // DIUBAH: Header tabel sekarang dinamis
            let html = `<div class="table-responsive">
            <table class="table table-sm mb-0 yz-mini">
                <thead class="yz-header-item">
                    <tr>
                        <th>Item</th>
                        <th>Material FG</th>
                        <th>Desc FG</th>
                        <th>Qty SO</th>
                        ${stockPackingHeader}
                        ${whfgHeader}
                        <th>Net Price</th>
                        <th>VALUE</th>
                    </tr>
                </thead>
                <tbody>`;

            rows.forEach(r => {
                // Sel WHFG hanya akan ditambahkan jika tipe-nya 'whfg'
                const whfgCell = TYPE === 'whfg' ? `<td>${formatNumber(r.KALAB)}</td>` : '';
                // DITAMBAHKAN: Logika untuk sel Stock Packing
                const stockPackingCell = TYPE === 'fg' ? `<td>${formatNumber(r.KALAB2)}</td>` : '';

                // DIUBAH: Baris tabel sekarang dinamis
                html += `<tr>
                <td>${r.POSNR ?? ''}</td>
                <td>${r.MATNR ?? ''}</td>
                <td>${r.MAKTX ?? ''}</td>
                <td>${formatNumber(r.KWMENG)}</td>
                ${stockPackingCell}
                ${whfgCell} 
                <td>${formatCurrency(r.NETPR, r.WAERK)}</td>
                <td>${formatCurrency(r.VALUE, r.WAERK)}</td> 
            </tr>`;
            });

            html += `</tbody></table></div>`;
            return html;
        }

        document.querySelectorAll('.yz-kunnr-row').forEach(row => {
            row.addEventListener('click', async () => {
                const kunnr = row.dataset.kunnr;
                const kid = row.dataset.kid;
                const slot = document.getElementById(kid);
                const wrap = slot.querySelector('.yz-nest-wrap');
                const isOpen = row.classList.contains('is-open');
                const tbody_utama = row.closest('tbody');

                if (!isOpen) {
                    tbody_utama.classList.add('customer-focus-mode');
                    row.classList.add('is-focused');
                } else {
                    tbody_utama.classList.remove('customer-focus-mode');
                    row.classList.remove('is-focused');
                }
                row.classList.toggle('is-open');
                if (isOpen) {
                    slot.style.display = 'none';
                    return;
                }
                slot.style.display = '';
                if (wrap.dataset.loaded === '1') return;
                try {
                    wrap.innerHTML = `<div class="p-3 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse"><div class="spinner-border spinner-border-sm me-2"></div>Memuat data…</div>`;
                    const url = new URL(apiSoByCustomer);
                    url.searchParams.set('kunnr', kunnr);
                    url.searchParams.set('werks', WERKS);
                    url.searchParams.set('type', TYPE); // DIUBAH: Mengirim 'type'
                    const res = await fetch(url);
                    const js = await res.json();
                    if (!js.ok) throw new Error(js.error || 'Gagal memuat data SO');
                    wrap.innerHTML = renderLevel2_SO(js.data, kunnr);
                    wrap.dataset.loaded = '1';

                    wrap.querySelectorAll('.js-t2row').forEach(soRow => { // <--- INI PERBAIKANNYA
                        soRow.addEventListener('click', async (ev) => {
                            ev.stopPropagation();
                            const vbeln = soRow.dataset.vbeln;
                            const tgtId = soRow.dataset.tgt;
                            const itemRow = wrap.querySelector('#' + tgtId);
                            const itemBox = itemRow.querySelector('.yz-slot-items');
                            const open = itemRow.style.display !== 'none';

                            // PERBAIKAN: Cari tbody terdekat dari baris SO yang diklik
                            const soTbody = soRow.closest('tbody');

                            // Terapkan class pada tbody yang benar (tbody dari tabel level-2)
                            if (soTbody) {
                                if (!open) {
                                    soTbody.classList.add('so-focus-mode');
                                    soRow.classList.add('is-focused');
                                } else {
                                    soTbody.classList.remove('so-focus-mode');
                                    soRow.classList.remove('is-focused');
                                }
                            }

                            soRow.querySelector('.yz-caret')?.classList.toggle('rot');
                            if (open) {
                                itemRow.style.display = 'none';
                                return;
                            }
                            itemRow.style.display = '';

                            if (itemRow.dataset.loaded === '1') return;

                            itemBox.innerHTML = `<div class="p-2 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse"><div class="spinner-border spinner-border-sm me-2"></div>Memuat item…</div>`;
                            try {
                                const u = new URL(apiItemsBySo);
                                u.searchParams.set('vbeln', vbeln);
                                u.searchParams.set('werks', WERKS);
                                u.searchParams.set('type', TYPE);
                                const r = await fetch(u);
                                const jd = await r.json();
                                if (!jd.ok) throw new Error(jd.error || 'Gagal memuat item');
                                itemBox.innerHTML = renderLevel3_Items(jd.data);
                                itemRow.dataset.loaded = '1';
                            } catch (e) {
                                itemBox.innerHTML = `<div class="alert alert-danger m-3">${e.message}</div>`;
                            }
                        });
                    });
                } catch (e) {
                    wrap.innerHTML = `<div class="alert alert-danger m-3">${e.message}</div>`;
                }
            });
        });
    });
</script>
@endpush