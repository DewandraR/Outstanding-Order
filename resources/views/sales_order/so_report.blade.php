@extends('layouts.app')

@section('title','Outstanding SO')

@section('content')

{{-- Variabel PHP untuk mengelola state halaman --}}
@php
$selectedWerks = $selected['werks'] ?? null;
$selectedAuart = $selected['auart'] ?? null;
$typesForPlant = collect($mapping[$selectedWerks] ?? []);
$locationMap = ['2000' => 'Surabaya', '3000' => 'Semarang'];
$locName = $locationMap[$selectedWerks] ?? $selectedWerks;
@endphp

{{-- =========================================================
    HEADER: Pills di kiri, Tombol Export (Dropdown) di kanan
   ========================================================= --}}
<div class="card yz-card shadow-sm mb-3 overflow-visible">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">

        {{-- BAGIAN KIRI: Daftar Pills (SO Type) --}}
        <div class="py-1">
            @if($selectedWerks && $typesForPlant->count())
            <ul class="nav nav-pills yz-auart-pills p-1 flex-wrap" style="border-radius:.75rem;">
                @foreach($typesForPlant as $t)
                <li class="nav-item mb-2 me-2">
                    <a class="nav-link pill-green {{ $selectedAuart == $t->IV_AUART ? 'active' : '' }}"
                        href="{{ route('so.index', ['werks' => $selectedWerks, 'auart' => $t->IV_AUART]) }}">
                        {{ $t->Deskription }}
                    </a>
                </li>
                @endforeach
            </ul>
            @else
            <i class="fas fa-info-circle me-2"></i> Pilih Plant dulu dari sidebar untuk menampilkan pilihan SO Type.
            @endif
        </div>

        {{-- BAGIAN KANAN: Tombol Export (Dropdown) --}}
        <div class="py-1">
            <div class="dropdown" id="export-dropdown-container" style="display: none;">
                <button class="btn btn-primary dropdown-toggle" type="button" id="export-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-file-export me-2"></i>
                    Export (<span id="selected-count">0</span>)
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="export-btn">
                    <li><a class="dropdown-item export-option" href="#" data-type="pdf"><i class="fas fa-file-pdf text-danger me-2"></i>Export to PDF</a></li>
                    <li><a class="dropdown-item export-option" href="#" data-type="excel"><i class="fas fa-file-excel text-success me-2"></i>Export to Excel</a></li>
                </ul>
            </div>
        </div>

    </div>
</div>

{{-- Pesan jika plant sudah dipilih namun auart belum --}}
@if($selectedWerks && empty($selectedAuart))
<div class="alert alert-info">
    <i class="fas fa-info-circle me-2"></i>
    Silakan pilih <strong>Type</strong> pada tombol hijau di atas.
</div>
@endif

{{-- =========================================================
     TABEL UTAMA (muncul hanya kalau plant + auart dipilih)
    ========================================================= --}}
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
                        <th style="min-width:120px; text-align:center;">Overdue SO</th>
                        <th style="min-width:150px; text-align:center;">Overdue Rate</th>
                        <th style="min-width:150px; text-align:center;">Value</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                    @php $kid = 'krow_'.$r->KUNNR.'_'.$loop->index; @endphp
                    <tr class="yz-kunnr-row" data-kunnr="{{ $r->KUNNR }}" data-kid="{{ $kid }}" title="Klik untuk melihat detail SO">
                        <td class="sticky-col-mobile-disabled"><span class="kunnr-caret"><i class="fas fa-chevron-right"></i></span></td>
                        <td class="sticky-col-mobile-disabled text-start"><span class="fw-bold">{{ $r->NAME1 }}</span></td>
                        <td class="text-center">{{ $r->SO_LATE_COUNT }}</td>
                        <td class="text-center">{{ is_null($r->LATE_PCT) ? '—' : number_format($r->LATE_PCT, 2, '.', '') . '%' }}</td>
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

{{-- ======================================================================
     MODAL UNTUK REMARK (DIPINDAHKAN KE SINI, DI LUAR SEMUA KONDISI @if)
    ====================================================================== --}}
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
{{-- Style untuk Ikon Remark dan Dot Penanda --}}
<style>
    .remark-icon {
        cursor: pointer;
        color: #6c757d;
        transition: color 0.2s;
    }

    .remark-icon:hover {
        color: #0d6efd;
    }

    .remark-dot {
        height: 8px;
        width: 8px;
        background-color: #0d6efd;
        border-radius: 50%;
        display: inline-block;
        margin-left: 5px;
        vertical-align: middle;
    }
</style>
@endpush

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        //-----------------------------------------------------
        // 1. PENGATURAN AWAL DAN VARIABEL GLOBAL
        //-----------------------------------------------------
        const apiSoByCustomer = "{{ route('so.api.by_customer') }}";
        const apiItemsBySo = "{{ route('so.api.by_items') }}";
        const exportUrl = "{{ route('so.export') }}";
        const saveRemarkUrl = "{{ route('so.api.save_remark') }}";
        const csrfToken = "{{ csrf_token() }}";
        const qs = new URLSearchParams(window.location.search);
        const WERKS = (qs.get('werks') || '').trim();
        const AUART = (qs.get('auart') || '').trim();
        const exportDropdownContainer = document.getElementById('export-dropdown-container');
        const selectedCountSpan = document.getElementById('selected-count');
        const selectedItems = new Set();

        const remarkModalEl = document.getElementById('remarkModal');
        const remarkModal = new bootstrap.Modal(remarkModalEl);
        const remarkTextarea = document.getElementById('remark-text');
        const saveRemarkBtn = document.getElementById('save-remark-btn');
        const remarkFeedback = document.getElementById('remark-feedback');

        //-----------------------------------------------------
        // 2. FUNGSI-FUNGSI BANTU (HELPERS)
        //-----------------------------------------------------
        function updateExportButton() {
            if (!exportDropdownContainer || !selectedCountSpan) return;
            const count = selectedItems.size;
            selectedCountSpan.textContent = count;
            exportDropdownContainer.style.display = count > 0 ? 'block' : 'none';
        }

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

        //-----------------------------------------------------
        // 3. FUNGSI UNTUK MERENDER TABEL DETAIL (AJAX)
        //-----------------------------------------------------
        function renderLevel2_SO(rows, kunnr) {
            if (!rows?.length) return `<div class="p-3 text-muted">Tidak ada data Outstanding SO untuk customer ini.</div>`;
            let html = `<h5 class="yz-table-title-nested yz-title-so"><i class="fas fa-file-invoice me-2"></i>Outstanding SO</h5>
                <table class="table table-sm mb-0 yz-mini">
                    <thead class="yz-header-so">
                        <tr>
                            <th style="width:40px;"></th>
                            <th class="text-start">SO</th>
                            <th class="text-center">SO Item Count</th>
                            <th class="text-center">Req. Deliv. Date</th>
                            <th class="text-center">Overdue (Days)</th>
                            <th class="text-center">Value</th>
                        </tr>
                    </thead>
                    <tbody>`;
            rows.forEach((r, i) => {
                const rid = `t3_${kunnr}_${r.VBELN}_${i}`;
                const rowHighlightClass = r.Overdue < 0 ? 'yz-row-highlight-negative' : '';
                html += `<tr class="yz-row js-t2row ${rowHighlightClass}" data-vbeln="${r.VBELN}" data-tgt="${rid}">
                            <td class="text-center"><span class="yz-caret">▸</span></td>
                            <td class="yz-t2-vbeln text-start">${r.VBELN}</td>
                            <td class="text-center">${r.item_count ?? '-'}</td>
                            <td class="text-center">${r.FormattedEdatu || '-'}</td>
                            <td class="text-center">${r.Overdue}</td>
                            <td class="text-center">${formatCurrency(r.total_value, r.WAERK)}</td>
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

        function renderLevel3_Items(rows) {
            if (!rows?.length) return `<div class="p-2 text-muted">Tidak ada item detail (dengan Outs. SO > 0).</div>`;
            let html = `<div class="table-responsive"><table class="table table-sm table-hover mb-0 yz-mini">
                <thead class="yz-header-item">
                    <tr>
                        <th style="width: 40px;"><input class="form-check-input check-all-items" type="checkbox" title="Pilih Semua Item"></th>
                        <th>Item</th><th>Material FG</th>
                        <th>Desc FG</th><th>Qty SO</th>
                        <th>Outs. SO</th><th>Stock Packing</th>
                        <th>GR PKG</th>
                        <th>Net Price</th>
                        <th>Outs. Packg Value</th>
                        <th>Outs. SO Value</th>
                        <th>Remark</th>
                    </tr>
                </thead>
                <tbody>`;
            rows.forEach(r => {
                const isChecked = selectedItems.has(r.id.toString());
                const hasRemark = r.remark && r.remark.trim() !== '';
                const escapedRemark = r.remark ? encodeURIComponent(r.remark) : '';
                html += `<tr data-item-id="${r.id}">
                    <td><input class="form-check-input check-item" type="checkbox" data-id="${r.id}" ${isChecked ? 'checked' : ''}></td>
                    <td>${r.POSNR ?? ''}</td>
                    <td>${r.MATNR ?? ''}</td>
                    <td>${r.MAKTX ?? ''}</td>
                    <td>${formatNumber(r.KWMENG)}</td>
                    <td>${formatNumber(r.PACKG)}</td>
                    <td>${formatNumber(r.KALAB2)}</td>
                    <td>${formatNumber(r.MENGE)}</td>
                    <td>${formatCurrency(r.NETPR, r.WAERK)}</td>
                    <td>${formatCurrency(r.TOTPR2, r.WAERK)}</td>
                    <td>${formatCurrency(r.TOTPR, r.WAERK)}</td>
                    <td class="text-center">
                        <i class="fas fa-pencil-alt remark-icon" data-id="${r.id}" data-remark="${escapedRemark}" title="Tambah/Edit Catatan"></i>
                        <span class="remark-dot" style="display: ${hasRemark ? 'inline-block' : 'none'};"></span>
                    </td>
                </tr>`;
            });
            html += `</tbody></table></div>`;
            return html;
        }

        //-----------------------------------------------------
        // 4. EVENT LISTENERS UTAMA
        //-----------------------------------------------------
        document.querySelectorAll('.yz-kunnr-row').forEach(row => {
            row.addEventListener('click', async () => {
                const kunnr = row.dataset.kunnr;
                const kid = row.dataset.kid;
                const slot = document.getElementById(kid);
                const wrap = slot.querySelector('.yz-nest-wrap');
                const isOpen = row.classList.contains('is-open');
                const tbody = row.closest('tbody');
                if (!isOpen) {
                    tbody.classList.add('customer-focus-mode');
                    row.classList.add('is-focused');
                } else {
                    tbody.classList.remove('customer-focus-mode');
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
                    url.searchParams.set('auart', AUART);
                    const res = await fetch(url);
                    const js = await res.json();
                    if (!js.ok) throw new Error(js.error || 'Gagal memuat data SO');
                    wrap.innerHTML = renderLevel2_SO(js.data, kunnr);
                    wrap.dataset.loaded = '1';
                    wrap.querySelectorAll('.js-t2row').forEach(soRow => {
                        soRow.addEventListener('click', async (ev) => {
                            ev.stopPropagation();
                            const vbeln = soRow.dataset.vbeln;
                            const tgtId = soRow.dataset.tgt;
                            const itemRow = wrap.querySelector('#' + tgtId);
                            const itemBox = itemRow.querySelector('.yz-slot-items');
                            const open = itemRow.style.display !== 'none';
                            const soTbody = soRow.closest('tbody');
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
                                u.searchParams.set('auart', AUART);
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

        document.body.addEventListener('change', function(e) {
            if (e.target.classList.contains('check-all-items')) {
                const table = e.target.closest('table');
                if (!table) return;
                const itemCheckboxes = table.querySelectorAll('.check-item');
                itemCheckboxes.forEach(checkbox => {
                    checkbox.checked = e.target.checked;
                    const itemId = checkbox.dataset.id;
                    if (e.target.checked) selectedItems.add(itemId);
                    else selectedItems.delete(itemId);
                });
                updateExportButton();
            }
            if (e.target.classList.contains('check-item')) {
                const itemId = e.target.dataset.id;
                if (e.target.checked) selectedItems.add(itemId);
                else selectedItems.delete(itemId);
                updateExportButton();
            }
        });

        document.body.addEventListener('click', function(e) {
            if (e.target.classList.contains('remark-icon')) {
                const itemId = e.target.dataset.id;
                const currentRemark = decodeURIComponent(e.target.dataset.remark || '');

                remarkTextarea.value = currentRemark;
                saveRemarkBtn.dataset.itemId = itemId;
                remarkFeedback.textContent = '';

                // ⬇️ PENTING: pastikan modal menjadi anak langsung <body>
                if (remarkModalEl.parentElement !== document.body) {
                    document.body.appendChild(remarkModalEl);
                }

                // (opsional) pastikan tidak ada instance modal lama nyangkut
                if (bootstrap.Modal.getInstance(remarkModalEl)) {
                    bootstrap.Modal.getInstance(remarkModalEl).hide();
                }

                remarkModal.show();
            }
        });

        saveRemarkBtn.addEventListener('click', async function() {
            const itemId = this.dataset.itemId;
            const remarkText = remarkTextarea.value;
            this.disabled = true;
            this.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Menyimpan...`;
            try {
                const response = await fetch(saveRemarkUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        id: itemId,
                        remark: remarkText
                    })
                });
                const result = await response.json();
                if (!response.ok || !result.ok) {
                    throw new Error(result.message || 'Gagal menyimpan catatan.');
                }
                const remarkIcon = document.querySelector(`.remark-icon[data-id='${itemId}']`);
                const remarkDot = remarkIcon.nextElementSibling;
                remarkIcon.dataset.remark = encodeURIComponent(remarkText);
                remarkDot.style.display = remarkText.trim() !== '' ? 'inline-block' : 'none';
                remarkFeedback.textContent = 'Catatan berhasil disimpan!';
                remarkFeedback.className = 'small mt-2 text-success';
                setTimeout(() => remarkModal.hide(), 1000);
            } catch (error) {
                console.error('Error:', error);
                remarkFeedback.textContent = error.message;
                remarkFeedback.className = 'small mt-2 text-danger';
            } finally {
                this.disabled = false;
                this.innerHTML = 'Simpan Catatan';
            }
        });

        if (exportDropdownContainer) {
            exportDropdownContainer.addEventListener('click', function(e) {
                if (e.target.classList.contains('export-option')) {
                    e.preventDefault();
                    const exportType = e.target.dataset.type;
                    if (selectedItems.size === 0) {
                        alert('Pilih setidaknya satu item untuk diekspor.');
                        return;
                    }
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = exportUrl;
                    form.target = "_blank";
                    const csrfInput = document.createElement('input');
                    csrfInput.type = 'hidden';
                    csrfInput.name = '_token';
                    csrfInput.value = csrfToken;
                    form.appendChild(csrfInput);
                    const typeInput = document.createElement('input');
                    typeInput.type = 'hidden';
                    typeInput.name = 'export_type';
                    typeInput.value = exportType;
                    form.appendChild(typeInput);
                    const werksInput = document.createElement('input');
                    werksInput.type = 'hidden';
                    werksInput.name = 'werks';
                    werksInput.value = WERKS;
                    form.appendChild(werksInput);
                    const auartInput = document.createElement('input');
                    auartInput.type = 'hidden';
                    auartInput.name = 'auart';
                    auartInput.value = AUART;
                    form.appendChild(auartInput);
                    selectedItems.forEach(id => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'item_ids[]';
                        input.value = id;
                        form.appendChild(input);
                    });
                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);
                }
            });
        }
    });
</script>
@endpush