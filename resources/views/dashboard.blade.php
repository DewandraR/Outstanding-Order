@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

    @php
        // Ambil nilai dari controller / query
        $werks = $selected['werks'] ?? null;
        $auart = $selected['auart'] ?? null;
        $show = false; // Karena semua mode report sudah di-redirect

        // Nilai state global (dipakai tombol/pill)
        $curView = 'po'; // HARDCODED untuk PO
        $curLoc = $selectedLocation ?? null; // '2000' | '3000' | null
        $curType = $selectedType ?? null; // 'lokal' | 'export' | null

        // Ambil data mapping untuk dropdown
        $allMapping = $mapping;

        // Helper pembentuk URL terenkripsi ke PO Report (po.report)
        $encReport = function (array $params) {
            $payload = array_filter(array_merge(['compact' => 1], $params), fn($v) => !is_null($v) && $v !== '');
            return route('po.report', ['q' => \Crypt::encrypt($payload)]);
        };
    @endphp

    {{-- Anchor untuk JS (dipertahankan nilainya 0) --}}
    <div id="yz-root" data-show="0" data-werks="{{ $werks ?? '' }}" data-auart="{{ $auart ?? '' }}" style="display:none">
    </div>

    {{-- =========================================================
        C. MODE DASHBOARD (grafik PO) - DIMULAI DI SINI
    ========================================================= --}}

    <div id="dashboard-data-holder" data-chart-data='@json($chartData)'
        data-mapping-data='@json($mapping)' data-selected-type='{{ $selectedType }}'
        data-current-view='{{ $curView }}' data-current-location='{{ $selectedLocation ?? '' }}'
        data-current-auart='{{ $auart ?? '' }}' style="display:none;">
    </div>

    <div class="d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-center mb-3 gap-3">
        <div>
            <h2 class="mb-0 fw-bolder">Dashboard Overview PO</h2>
            <p class="text-muted mb-0">Displaying Outstanding Value Data</p>
        </div>

        {{-- DROPDOWN FILTER BARU --}}
        <div class="d-flex flex-wrap gap-2 justify-content-start justify-content-lg-end">
            @php
                $locations = ['3000' => 'Semarang', '2000' => 'Surabaya'];
            @endphp

            @foreach ($locations as $werks_code => $name)
                <div class="dropdown">
                    <button class="btn btn-sm btn-primary dropdown-toggle shadow-sm" type="button"
                        data-bs-toggle="dropdown" aria-expanded="false">
                        {{ $name }}
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        @php
                            // Ambil Order Type unik untuk Plant ini
                            $werksMapping = $allMapping[$werks_code] ?? collect([]);
                        @endphp

                        @forelse ($werksMapping as $t)
                            @php
                                $auartCode = trim((string) $t->IV_AUART);
                                // Ciptakan payload terenkripsi ke PO Report (po.report)
                                $reportUrl = $encReport(['werks' => $werks_code, 'auart' => $auartCode]);
                            @endphp
                            <li>
                                <a class="dropdown-item" href="{{ $reportUrl }}">
                                    <i class="fas fa-file-alt me-2"></i> {{ $t->Deskription }}
                                </a>
                            </li>
                        @empty
                            <li><span class="dropdown-item text-muted disabled">No Order Types Found</span></li>
                        @endforelse
                    </ul>
                </div>
            @endforeach
        </div>
        {{-- END DROPDOWN FILTER BARU --}}
    </div>
    <hr class="mt-0 mb-4">

    {{-- ==================== DASHBOARD PO CONTENT (TETAP) ==================== --}}
    <div class="row g-4 mb-4"> {{-- <== PEMBUNGKUS ROW SUPAYA GRID RAPI --}}
        <div class="col-md-6 col-xl-3">
            <div id="kpi-po-outs-usd" data-currency="USD" class="card yz-kpi-card h-100 shadow-sm clickable"
                style="cursor:pointer" title="Klik untuk lihat breakdown per customer">
                <div class="card-body d-flex align-items-center">
                    <div class="yz-kpi-icon bg-primary-subtle text-primary">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                    <div class="ms-3">
                        <div class="mb-1 text-muted yz-kpi-title" data-help-key="po.kpi.value_usd">
                            <span>Outs Value Ship&nbsp;</span>
                        </div>
                        <h4 class="mb-0 fw-bolder" id="kpi-out-usd">$0.00</h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div id="kpi-po-outs-idr" data-currency="IDR" class="card yz-kpi-card h-100 shadow-sm clickable"
                style="cursor:pointer" title="Klik untuk lihat breakdown per customer">
                <div class="card-body d-flex align-items-center">
                    <div class="yz-kpi-icon bg-success-subtle text-success">
                        <i class="fas a-money-bill-wave fa-money-bill-wave"></i>
                    </div>
                    <div class="ms-3">
                        <div class="mb-1 text-muted yz-kpi-title" data-help-key="po.kpi.value_idr">
                            <span>Outs Value Ship&nbsp;</span>
                        </div>
                        <h4 class="mb-0 fw-bolder" id="kpi-out-idr">Rp 0</h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card yz-kpi-card h-100 shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="yz-kpi-icon bg-info-subtle text-info">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <div class="ms-3">
                        <div class="mb-1 text-muted yz-kpi-title" data-help-key="po.kpi.outstanding_po">
                            <span>Outstanding&nbsp;PO</span>
                        </div>
                        <h4 class="mb-0 fw-bolder" id="kpi-out-so">0</h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card yz-kpi-card h-100 shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="yz-kpi-icon bg-danger-subtle text-danger">
                        <i class="fas fa-business-time"></i>
                    </div>
                    <div class="ms-3">
                        <div class="mb-1 text-muted yz-kpi-title" data-help-key="po.kpi.overdue_po">
                            <span>Overdue&nbsp;PO</span>
                        </div>
                        <h4 class="mb-0 fw-bolder">
                            <span id="kpi-overdue-so">0</span>
                            <small class="text-danger" id="kpi-overdue-rate">(0%)</small>
                        </h4>
                    </div>
                </div>
            </div>
        </div>
    </div> {{-- </row> --}}
    ---
    {{-- === DETAIL: Outstanding Value by Customer (muncul di bawah KPI) === --}}
    <div id="po-outs-details" class="card yz-chart-card mt-3" style="display:none;">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="card-title mb-0">
                    <i class="fas fa-table me-2"></i>
                    Outstanding Value by Customer —
                    <span id="po-outs-cur" class="badge bg-secondary">USD</span>
                </h5>
                <button type="button" class="btn btn-sm btn-light" id="po-outs-hide">
                    Hide
                </button>
            </div>
            <div id="po-outs-filter" class="text-muted small mt-1">Filter: –</div>
            <hr class="mt-2">

            <div class="table-responsive yz-scrollable-table-container" style="max-height:45vh;">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light yz-sticky-thead">
                        <tr>
                            <th>Customer</th>
                            <th class="text-center" style="min-width:160px;">Order Type</th>
                            <th class="text-end" style="min-width:180px;">Outs. Value</th>
                        </tr>
                    </thead>
                    <tbody id="po-outs-tbody">
                        {{-- diisi via JS --}}
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <th colspan="2" class="text-end">Total</th>
                            <th id="po-outs-total" class="text-end">–</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    ---
    {{-- Outstanding by Location + PO Status --}}
    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <div class="card shadow-sm h-100 yz-chart-card">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title" data-help-key="po.outstanding_by_location">
                        <i class="fas fa-chart-column me-2"></i>Outstanding Value by Location
                    </h5>
                    <hr class="mt-2">
                    <div class="chart-container flex-grow-1">
                        <canvas id="chartOutstandingLocation"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm h-100 yz-chart-card position-relative">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title" data-help-key="po.status_overview">PO Status Overview</h5>
                    <hr class="mt-2">
                    <div class="chart-container flex-grow-1">
                        <canvas id="chartSOStatus"></canvas>
                    </div>
                    {{-- Detail overlay untuk chart status PO (dipertahankan) --}}
                    <div id="so-status-details" style="display:none;"></div>
                </div>
            </div>
        </div>
    </div>
    ---
    {{-- Top customers (USD & IDR) + Top overdue customers --}}
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100 yz-chart-card">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title text-primary-emphasis" data-help-key="po.top_customers_value_usd">
                        <i class="fas fa-crown me-2"></i>Top 4 Customers by Outstanding Value
                    </h5>
                    <hr class="mt-2">
                    <div class="chart-container flex-grow-1">
                        <canvas id="chartTopCustomersValue"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100 yz-chart-card">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title text-danger-emphasis" data-help-key="po.top_customers_overdue">
                        <i class="fas fa-triangle-exclamation me-2"></i>Top 4 Customers with Most Overdue PO
                    </h5>
                    <hr class="mt-2">
                    <div class="chart-container flex-grow-1">
                        <canvas id="chartTopOverdueCustomers"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    ---
    {{-- Performance details by Type --}}
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card shadow-sm yz-chart-card position-relative">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h5 class="card-title mb-0" data-help-key="po.performance_details">
                                <i class="fas fa-tasks me-2"></i>Outstanding PO & Performance Details by Type
                            </h5>
                        </div>
                        <div class="d-flex flex-wrap justify-content-end align-items-center"
                            style="gap: 8px; flex-shrink: 0; margin-left: 1rem;">
                            <span class="legend-badge" style="background-color: #ffc107;">1-30</span>
                            <span class="legend-badge" style="background-color: #fd7e14;">31-60</span>
                            <span class="legend-badge" style="background-color: #dc3545;">61-90</span>
                            <span class="legend-badge" style="background-color: #8b0000;">&gt;90</span>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">PO Type</th>
                                    <th scope="col" class="text-center">Total PO</th>
                                    <th scope="col" class="text-end">Outs. Value (IDR)</th>
                                    <th scope="col" class="text-end">Outs. Value (USD)</th>
                                    <th scope="col" class="text-center">PO Overdue</th>
                                    <th scope="col" style="min-width: 300px;" class="text-center">Overdue
                                        Distribution (Days)</th>
                                </tr>
                            </thead>
                            <tbody id="so-performance-tbody"></tbody>
                        </table>
                    </div>
                </div>

                {{-- Detail overlay untuk tabel performance (dipertahankan) --}}
                <div id="po-overdue-details" style="display:none;"></div>
            </div>
        </div>
    </div>
    ---
    {{-- Small quantity (≤5) --}}
    <div class="row g-4">
        <div class="col-12">
            <div class="card shadow-sm yz-chart-card">
                <div class="card-body">
                    <h5 class="card-title text-info-emphasis" data-help-key="po.small_qty_by_customer">
                        <i class="fas fa-chart-line me-2"></i>Small Quantity (≤5) Outstanding Items by Customer
                    </h5>
                    <hr class="mt-2">
                    <div class="chart-container" style="height: 600px;">
                        <canvas id="chartSmallQtyByCustomer"></canvas>
                    </div>
                </div>
            </div>

            <div id="smallQtyDetailsContainer" class="card shadow-sm yz-chart-card mt-4" style="display: none;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0 text-primary-emphasis">
                            <i class="fas fa-list-ol me-2"></i>
                            <span id="smallQtyDetailsTitle">Detail Item Outstanding</span>
                            <small id="smallQtyMeta" class="text-muted ms-2"></small>
                        </h5>

                        <div class="d-flex align-items-center gap-2">
                            {{-- tombol export PDF --}}
                            <button type="button" class="btn btn-sm btn-outline-danger" id="exportSmallQtyPdf" disabled>
                                <i class="fas fa-file-pdf me-1"></i> Export PDF
                            </button>
                            {{-- tombol close --}}
                            <button type="button" class="btn-close" id="closeDetailsTable" aria-label="Close"></button>
                        </div>
                    </div>
                    <hr class="mt-2">
                    <div id="smallQtyDetailsTable" class="mt-3"></div>
                </div>
                <form id="smallQtyExportForm" action="{{ route('dashboard.export.smallQtyPdf') }}" method="POST"
                    target="_blank" class="d-none">
                    @csrf
                    <input type="hidden" name="customerName" id="exp_customerName">
                    <input type="hidden" name="locationName" id="exp_locationName">
                    <input type="hidden" name="type" id="exp_type">
                </form>
            </div>
        </div>
    </div>
    {{-- ==================== /DASHBOARD PO CONTENT ==================== --}}

@endsection

@push('styles')
    {{-- Pastikan asset ini ada di project Anda --}}
    <link rel="stylesheet" href="{{ asset('css/dashboard-style.css') }}">
@endpush

@push('scripts')
    {{-- Pastikan semua vendor ada di project Anda --}}
    <script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
    <script src="{{ asset('vendor/chartjs/chartjs-adapter-date-fns.bundle.min.js') }}"></script>

    <script>
        function setTitleCurrencySuffixByCanvas(canvasId, currency) {
            const titleEl = document.getElementById(canvasId)?.closest('.card')?.querySelector('.card-title');
            if (!titleEl) return;
            const textNodes = Array.from(titleEl.childNodes)
                .filter(n => n.nodeType === Node.TEXT_NODE && n.textContent.trim().length);

            if (!textNodes.length) return;
            const tn = textNodes[textNodes.length - 1];
            const raw = tn.textContent;

            if (/\((USD|IDR)\)/.test(raw)) {
                tn.textContent = raw.replace(/\((USD|IDR)\)/, `(${currency})`);
            } else {
                tn.textContent = `${raw.trim()} (${currency})`;
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // TRUE kalau user sedang filter Lokal / Export
            const typeSelected = {!! json_encode((bool) $selectedType) !!};

            if (!typeSelected) return;

            // 1) Sembunyikan semua currency toggle
            document.querySelectorAll('.yz-currency-toggle').forEach(el => el.remove());

            // 2) Fallback: kalau tidak pakai class, deteksi otomatis tombol USD/IDR
            const maybeGroups = document.querySelectorAll('.btn-group, .nav, .nav-pills');
            maybeGroups.forEach(g => {
                const labels = Array.from(g.querySelectorAll('a,button')).map(b => (b.textContent || '')
                    .trim().toUpperCase());
                if (labels.includes('USD') && labels.includes('IDR')) g.remove();
            });
        });
        document.addEventListener('DOMContentLoaded', function() {
            const customerRows = document.querySelectorAll('.yz-kunnr-row');
            customerRows.forEach(row => {
                row.querySelector('td:nth-child(2)')?.setAttribute('data-label', 'Customer');
                row.querySelector('td:nth-child(3)')?.setAttribute('data-label', 'Overdue PO');
                row.querySelector('td:nth-child(4)')?.setAttribute('data-label', 'Overdue Rate');
                row.querySelector('td:nth-child(5)')?.setAttribute('data-label', 'Outs. Value');
            });
        });

        function preventInfoButtonPropagation() {
            // Tombol info dibuat dinamis oleh chart-help.js dengan class .yz-info-icon
            const infoButtons = document.querySelectorAll('.yz-info-icon');

            infoButtons.forEach(btn => {
                // Pastikan event handler hanya dipasang sekali
                if (btn.dataset.clickBound === '1') return;

                btn.addEventListener('click', (e) => {
                    // KUNCI UTAMA: Hentikan event agar tidak 'menggelembung'
                    // ke elemen card induk yang memiliki click listener lain.
                    e.stopPropagation();
                    e.stopImmediatePropagation?.();
                });

                btn.dataset.clickBound = '1';
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Panggil setelah DOM dimuat (mungkin sebelum chart-help)
            preventInfoButtonPropagation();

            // Panggil ulang secara berkala. Tombol 'i' dibuat oleh 'chart-help.js',
            // yang mungkin berjalan agak lambat atau setelah DOMContentLoaded.
            const intervalId = setInterval(() => {
                // Hanya jalankan jika ada tombol 'i' yang belum di-bind
                if (!document.querySelector('.yz-info-icon:not([data-click-bound="1"])')) {
                    clearInterval(intervalId);
                    return;
                }
                preventInfoButtonPropagation();
            }, 500); // Coba setiap 500ms

            // Hentikan pengecekan setelah 5 detik agar tidak membebani browser
            setTimeout(() => clearInterval(intervalId), 5000);
        });

        /* =========================================================
        	HELPER UMUM
        	======================================================== */
        const formatFullCurrency = (value, currency) => {
            const n = parseFloat(value);
            if (isNaN(n)) return '';
            if (currency === 'IDR') {
                return new Intl.NumberFormat('id-ID', {
                    style: 'currency',
                    currency: 'IDR',
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }).format(n);
            }
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD'
            }).format(n);
        };

        // ======================================================================
        // [BARU] TAMBAHKAN FUNGSI DI BAWAH INI
        // Fungsi ini untuk memastikan CSS untuk toggle SELALU ada
        function injectToggleStyles() {
            if (document.getElementById('yzToggleCss')) return; // Jangan tambahkan jika sudah ada
            const style = document.createElement('style');
            style.id = 'yzToggleCss';
            style.textContent = `
            .yz-card-toolbar {
            position: absolute;
            top: .75rem;
            right: .75rem;
            z-index: 3;
            }
            .yz-card-header-pad {
                padding-right: 96px !important;  
            }
            .yz-card-toolbar .btn {
                padding: .15rem .5rem;
                font-size: .75rem;
                line-height: 1.1;
            }
        `;
            document.head.appendChild(style);
        }

        const showNoDataMessage = (canvasId, msg = 'Data tidak tersedia untuk filter ini.') => {
            const canvas = document.getElementById(canvasId);
            if (!canvas || !canvas.parentElement) return;
            let msgEl = canvas.parentElement.querySelector('.yz-nodata');
            if (!msgEl) {
                msgEl = document.createElement('div');
                msgEl.className = 'yz-nodata d-flex align-items-center justify-content-center h-100 p-3 text-muted';
                msgEl.style.minHeight = '300px';
                canvas.parentElement.appendChild(msgEl);
            }
            msgEl.innerHTML = `<i class="fas fa-info-circle me-2"></i> ${msg}`;
            canvas.style.display = 'none';
            msgEl.style.display = '';
        };

        const hideNoDataMessage = (canvasId) => {
            const canvas = document.getElementById(canvasId);
            const msgEl = canvas?.parentElement?.querySelector('.yz-nodata');
            if (msgEl) msgEl.style.display = 'none';
            if (canvas) canvas.style.display = '';
        };

        const formatLocations = (locsString) => {
            if (!locsString) return '';
            const hasSemarang = locsString.includes('3000');
            const hasSurabaya = locsString.includes('2000');
            if (hasSemarang && hasSurabaya) return 'Semarang & Surabaya';
            if (hasSemarang) return 'Semarang';
            if (hasSurabaya) return 'Surabaya';
            return '';
        };

        const createHorizontalBarChart = (canvasId, chartData, dataKey, label, color, currency = '') => {
            if (!chartData || chartData.length === 0) {
                showNoDataMessage(canvasId);
                return;
            }
            const ctx = document.getElementById(canvasId);
            if (!ctx) return;
            const prev = Chart.getChart(canvasId);
            if (prev) prev.destroy();

            const labels = chartData.map(d => {
                const customerName = d.NAME1.length > 25 ? d.NAME1.substring(0, 25) + '...' : d.NAME1;
                if (d.locations) return [customerName, formatLocations(d.locations)];
                return customerName;
            });
            const values = chartData.map(d => d[dataKey]);

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label,
                        data: values,
                        backgroundColor: color.bg,
                        borderColor: color.border,
                        borderWidth: 1,
                        borderRadius: 5
                    }]
                },
                options: {
                    indexAxis: 'y',
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                title: (items) => items[0].label.split(',')[0],
                                label: (context) => {
                                    const dataPoint = chartData[context.dataIndex];

                                    // Untuk chart nilai (punya argumen 'currency')
                                    if (currency && dataPoint) {
                                        const totalTxt = formatFullCurrency(context.raw, currency);

                                        let breakdownTxt = '';
                                        if (canvasId === 'chartTopCustomersValueSO') {
                                            const sby = Number(dataPoint.sby_value || 0);
                                            const smg = Number(dataPoint.smg_value || 0);

                                            if (sby > 0 && smg > 0) {
                                                // gabungan → tampilkan keduanya dengan nilai masing-masing
                                                breakdownTxt =
                                                    ` (SMG: ${formatFullCurrency(smg, currency)}, ` +
                                                    `SBY: ${formatFullCurrency(sby, currency)})`;
                                            } else if (smg > 0 && sby === 0) {
                                                // hanya SMG → tampilkan label saja
                                                breakdownTxt = ' (SMG)';
                                            } else if (sby > 0 && smg === 0) {
                                                // hanya SBY → tampilkan label saja
                                                breakdownTxt = ' (SBY)';
                                            }
                                        }

                                        const soCountTxt = dataPoint.so_count ?
                                            ` (${dataPoint.so_count} PO)` : '';
                                        return `${totalTxt}${breakdownTxt}${soCountTxt}`;
                                    }

                                    // Chart jumlah PO (tetap seperti sebelumnya)
                                    if (canvasId === 'chartTopOverdueCustomers' && dataPoint) {
                                        const total = dataPoint.overdue_count,
                                            smg = dataPoint.smg_count,
                                            sby = dataPoint.sby_count;
                                        const segs = [];
                                        if (smg > 0) segs.push(`SMG: ${smg}`);
                                        if (sby > 0) segs.push(`SBY: ${sby}`);
                                        return `${total} PO${segs.length ? ' (' + segs.join(', ') + ')' : ''}`;
                                    }

                                    return `${context.raw} PO`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: {
                                // ⬇️ ROTASI AGAR DARI AWAL SUDAH MIRING
                                minRotation: 20,
                                maxRotation: 20,
                                autoSkip: true,
                                padding: 6,
                                callback: (value) => {
                                    if (Math.floor(value) === value) {
                                        return currency ? formatFullCurrency(value, currency).replace(
                                            /\,00$/, '') : value;
                                    }
                                }
                            }
                        }
                    }
                }
            });
        };

        /* =========================================================
        	SCRIPT UTAMA - DISESUAIKAN UNTUK HANYA PO
        	======================================================== */
        (() => {
            injectToggleStyles();
            const rootElement = document.getElementById('yz-root');
            const showTable = rootElement ? !!parseInt(rootElement.dataset.show) : false;

            /* ---------- MODE TABEL (LAPORAN) - Logika Asli Dipertahankan ---------- */
            if (showTable) {
                const apiT2 = "{{ route('dashboard.api.t2') }}";
                const apiT3 = "{{ route('dashboard.api.t3') }}";
                const WERKS = (rootElement.dataset.werks || '').trim() || null;
                const AUART = (rootElement.dataset.auart || '').trim() || null;

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

                    // 🔧 SORT: telat (positif) dulu, lalu terbesar → terkecil; sisanya (negatif) otomatis di bawah
                    const sortedRows = [...rows].sort((a, b) => {
                        const oa = Number(a.Overdue ?? 0);
                        const ob = Number(b.Overdue ?? 0);
                        // Positif (telat) selalu di atas negatif (belum jatuh tempo)
                        if ((oa > 0) !== (ob > 0)) return ob > 0 ? 1 : -
                            1; // atau cukup return ob - oa; tapi ini eksplisit
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

                        custRow.classList.toggle('is-open');
                        slot.style.display = wasOpen ? 'none' : '';

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

                                    const u3 = new URL(
                                        "{{ route('dashboard.api.t3') }}",
                                        window.location
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

                            /* ========= CHANGE HANDLERS (checkbox) - Dibuat di document.body karena adanya auto-expand ========= */

                            // Perlu event listener global untuk check-all dan check-single item
                            document.body.addEventListener('change', async (e) => {
                                const target = e.target;

                                // --- CHECK-ALL ITEMS (T3) ---
                                if (target.classList.contains('check-all-items')) {
                                    const t3 = target.closest('table');
                                    t3.querySelectorAll('.check-item').forEach(ch => {
                                        const sid = sanitizeId(ch.dataset.id);
                                        if (!sid) return;
                                        ch.checked = target.checked;
                                        if (target.checked) selectedItems.add(
                                            sid);
                                        else selectedItems.delete(sid);
                                    });
                                    const anyItem = t3.querySelector('.check-item');
                                    if (anyItem) {
                                        const v = itemIdToSO.get(String(anyItem.dataset
                                            .id));
                                        if (v) soHasSelectionDot(v);
                                    }
                                    updateExportButton();
                                    return;
                                }

                                // --- CHECK SINGLE ITEM (T3) ---
                                if (target.classList.contains('check-item')) {
                                    const sid = sanitizeId(target.dataset.id);
                                    if (!sid) return;
                                    if (target.checked) selectedItems.add(sid);
                                    else selectedItems.delete(sid);

                                    const v = itemIdToSO.get(String(sid));
                                    if (v) soHasSelectionDot(v);
                                    updateExportButton();
                                    return;
                                }

                                // --- CHECK-ALL PO (T2) ---
                                if (target.classList.contains('check-all-sos')) {
                                    const t2 = target.closest('table');
                                    const allSO = t2.querySelectorAll('.js-t2row');
                                    for (const soRow of allSO) {
                                        const chk = soRow.querySelector('.check-so');
                                        chk.checked = target.checked;

                                        const vbeln = chk.dataset.vbeln;
                                        const nest = soRow.nextElementSibling;
                                        const box = nest.querySelector('.yz-slot-t3');
                                        const caret = soRow.querySelector('.yz-caret');

                                        // Load items untuk mengambil ID
                                        const u3 = new URL(
                                            "{{ route('dashboard.api.t3') }}",
                                            window.location.origin);
                                        if (WERKS) u3.searchParams.set('werks', WERKS);
                                        if (AUART) u3.searchParams.set('auart', AUART);
                                        u3.searchParams.set('vbeln', vbeln);
                                        const r3 = await fetch(u3);
                                        const j3 = await r3.json();
                                        if (j3?.ok) {
                                            j3.data.forEach(it => {
                                                const sid = sanitizeId(it.id);
                                                if (!sid) return;
                                                itemIdToSO.set(sid, vbeln);
                                                if (target.checked)
                                                    selectedItems.add(sid);
                                                else selectedItems.delete(sid);
                                            });
                                        }

                                        // UI update: expand/collapse + check T3
                                        if (target.checked) {
                                            if (nest.style.display === 'none') {
                                                nest.style.display = '';
                                                caret?.classList.add('rot');
                                            }
                                            if (nest.dataset.loaded !== '1') {
                                                if (j3?.ok) {
                                                    box.innerHTML = renderT3(j3.data);
                                                    nest.dataset.loaded = '1';
                                                }
                                            }
                                            // Setelah load/ada, centang semua di T3
                                            box.querySelectorAll('.check-item').forEach(
                                                ci => ci.checked = true);
                                            box.querySelector('.check-all-items')
                                                .checked = true;

                                        } else {
                                            // Uncheck: collapse + uncheck T3
                                            nest.style.display = 'none';
                                            caret?.classList.remove('rot');
                                            box.querySelectorAll('.check-item').forEach(
                                                ci => ci.checked = false);
                                            box.querySelector('.check-all-items')
                                                .checked = false;
                                        }

                                        soHasSelectionDot(vbeln);
                                    }
                                    updateExportButton();
                                    return;
                                }

                                // --- CHECK SINGLE SO (T2) ---
                                if (target.classList.contains('check-so')) {
                                    const soRow = target.closest('.js-t2row');
                                    const vbeln = target.dataset.vbeln;
                                    const nest = soRow.nextElementSibling;
                                    const box = nest.querySelector('.yz-slot-t3');
                                    const caret = soRow.querySelector('.yz-caret');
                                    const t2tbl = soRow.closest('table');

                                    // 1. Load items untuk ID
                                    let items;
                                    try {
                                        const u3 = new URL(
                                            "{{ route('dashboard.api.t3') }}",
                                            window.location.origin);
                                        if (WERKS) u3.searchParams.set('werks', WERKS);
                                        if (AUART) u3.searchParams.set('auart', AUART);
                                        u3.searchParams.set('vbeln', vbeln);
                                        const r3 = await fetch(u3);
                                        const j3 = await r3.json();
                                        if (j3?.ok) {
                                            items = j3.data;
                                            items.forEach(it => itemIdToSO.set(
                                                sanitizeId(it.id), vbeln));
                                        }
                                    } catch (e) {
                                        console.error(
                                            "Failed to fetch items for SO selection:",
                                            e);
                                        return;
                                    }

                                    // 2. Update Set Global
                                    if (target.checked) {
                                        items.forEach(it => selectedItems.add(
                                            sanitizeId(it.id)));
                                    } else {
                                        Array.from(selectedItems).forEach(id => {
                                            if (itemIdToSO.get(String(id)) ===
                                                vbeln) selectedItems.delete(id);
                                        });
                                    }

                                    // 3. Update UI (expand/collapse)
                                    if (target.checked) {
                                        if (nest.style.display === 'none') {
                                            nest.style.display = '';
                                            caret?.classList.add('rot');
                                        }
                                        if (nest.dataset.loaded !== '1') {
                                            box.innerHTML = renderT3(items);
                                            nest.dataset.loaded = '1';
                                        }
                                        // Pastikan checkbox di T3 dicentang
                                        box.querySelectorAll('.check-item').forEach(
                                            ci => ci.checked = true);
                                        box.querySelector('.check-all-items').checked =
                                            true;
                                    } else {
                                        // Uncheck: collapse + uncheck T3
                                        nest.style.display = 'none';
                                        caret?.classList.remove('rot');
                                        box.querySelectorAll('.check-item').forEach(
                                            ci => ci.checked = false);
                                        box.querySelector('.check-all-items').checked =
                                            false;
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
            }

            /* ---------- MODE DASHBOARD (grafik & kpi) - HANYA PO ---------- */
            const dataHolder = document.getElementById('dashboard-data-holder');
            if (!dataHolder) return;

            const mappingData = JSON.parse(dataHolder.dataset.mappingData || '{}');
            const currentView = (dataHolder.dataset.currentView || 'po').toLowerCase();
            const filterState = {
                location: dataHolder.dataset.currentLocation || null,
                type: dataHolder.dataset.selectedType || null,
                auart: dataHolder.dataset.currentAuart || null,
            };
            const plantMap = {
                '2000': 'Surabaya',
                '3000': 'Semarang'
            };
            const auartMap = {};
            if (mappingData) {
                for (const werks in mappingData) {
                    mappingData[werks].forEach(item => {
                        auartMap[item.IV_AUART] = item.Deskription;
                    });
                }
            }
            const chartData = JSON.parse(dataHolder.dataset.chartData);
            const selectedType = dataHolder.dataset.selectedType;
            if (!chartData || !chartData.kpi) {
                document.querySelectorAll('.row.g-4.mb-4').forEach(el => el.style.display = 'none');
                return;
            }

            Chart.defaults.font.family = 'Inter, sans-serif';
            Chart.defaults.plugins.legend.position = 'bottom';
            Chart.defaults.responsive = true;
            Chart.defaults.maintainAspectRatio = false;

            /* ======================== KPI PO ======================== */
            document.getElementById('kpi-out-usd').textContent = formatFullCurrency(chartData.kpi
                .total_outstanding_value_usd, 'USD');
            document.getElementById('kpi-out-idr').textContent = formatFullCurrency(chartData.kpi
                .total_outstanding_value_idr, 'IDR');
            document.getElementById('kpi-out-so').textContent = chartData.kpi
                .total_outstanding_so; // Masih pakai so karena di controller fieldnya 'total_outstanding_so'
            document.getElementById('kpi-overdue-so').textContent = chartData.kpi
                .total_overdue_so; // Masih pakai so karena di controller fieldnya 'total_overdue_so'
            document.getElementById('kpi-overdue-rate').textContent =
                `(${(chartData.kpi.overdue_rate || 0).toFixed(1)}%)`;

            const __charts = {
                poLocation: null,
                topCustomers: null
            };
            const __destroy = (k) => {
                try {
                    __charts[k]?.destroy?.();
                } catch {}
                __charts[k] = null;
            };

            const hasTypeFilter = !!filterState.type;
            const enableCurrencyToggle = (!
                hasTypeFilter);

            let currentCurrency = (dataHolder.dataset.selectedType === 'lokal') ? 'IDR' : 'USD';
            if (enableCurrencyToggle) {
                try {
                    const saved = localStorage.getItem('poCurrency');
                    if (saved === 'USD' || saved === 'IDR') currentCurrency = saved;
                } catch {}
            }

            /* ---------- RENDER: Outstanding Value by Location ---------- */
            function renderOutstandingLocation(currency) {
                const canvasId = 'chartOutstandingLocation';
                const ctx = document.getElementById(canvasId);
                if (!ctx) return;

                const locationData = chartData.outstanding_by_location || [];
                const ds = (locationData || []).filter(d => d.currency === currency);

                if (!ds.length) {
                    showNoDataMessage(canvasId);
                    return;
                }
                hideNoDataMessage(canvasId);

                const semarang_val = ds.find(d => d.location === 'Semarang')?.total_value || 0;
                const surabaya_val = ds.find(d => d.location === 'Surabaya')?.total_value || 0;

                __destroy('poLocation');
                __charts.poLocation = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: ['Semarang', 'Surabaya'],
                        datasets: [{
                            label: `Outstanding (${currency})`,
                            data: [semarang_val, surabaya_val],
                            backgroundColor: currency === 'IDR' ? 'rgba(25, 135, 84, 0.6)' :
                                'rgba(54, 162, 235, 0.6)',
                            borderColor: currency === 'IDR' ? 'rgba(25, 135, 84, 1)' :
                                'rgba(54, 162, 235, 1)',
                            borderWidth: 1,
                            borderRadius: 5
                        }]
                    },
                    options: {
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: (v) => new Intl.NumberFormat('id-ID').format(v)
                                }
                            }
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => {
                                        const dataPoint = ds[ctx.dataIndex];
                                        const value = formatFullCurrency(ctx.raw, currency);
                                        const count = dataPoint ? dataPoint.so_count : '';
                                        return `${value} (${count} PO)`;
                                    }
                                }
                            }
                        }
                    }
                });
            }



            function escapeHtml(str = '') {
                return String(str).replace(/[&<>"']/g, s => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                } [s]));
            }


            /* ---------- RENDER: Top 4 Customers by Outstanding Value ---------- */
            function renderTopCustomersByCurrency(currency) {
                setTitleCurrencySuffixByCanvas('chartTopCustomersValue', currency);

                const ds = (currency === 'IDR') ? chartData.top_customers_value_idr :
                    chartData.top_customers_value_usd;

                __destroy('topCustomers');
                const canvas = document.getElementById('chartTopCustomersValue');
                if (canvas) {
                    createHorizontalBarChart(
                        'chartTopCustomersValue',
                        ds,
                        'total_value',
                        'Total Outstanding',
                        (currency === 'IDR') ? {
                            bg: 'rgba(25, 135, 84, 0.6)',
                            border: 'rgba(25, 135, 84, 1)'
                        } : {
                            bg: 'rgba(13, 110, 253, 0.6)',
                            border: 'rgba(13, 110, 253, 1)'
                        },
                        currency
                    );
                    __charts.topCustomers = Chart.getChart(canvas);
                }
            }

            function mountCurrencyToggleIfNeeded() {
                if (!enableCurrencyToggle) return;

                injectToggleStyles(); // Pastikan style dasar absolute ada

                const targets = [
                    document.getElementById('chartOutstandingLocation'),
                    document.getElementById('chartTopCustomersValue'),
                ].filter(Boolean);

                const makeToggle = () => {
                    const holder = document.createElement('div');
                    holder.className = 'yz-card-toolbar';
                    holder.innerHTML = `
            <div class="btn-group btn-group-sm yz-currency-toggle" role="group">
                <button type="button" data-cur="USD"
                class="btn ${currentCurrency==='USD'?'btn-primary':'btn-outline-primary'}">USD</button>
                <button type="button" data-cur="IDR"
                class="btn ${currentCurrency==='IDR'?'btn-success':'btn-outline-success'}">IDR</button>
            </div>
            `;
                    return holder;
                };

                targets.forEach(cv => {
                    const card = cv.closest('.card');
                    const titleEl = card?.querySelector('.card-title');
                    const headerRow = titleEl?.parentElement; // Ini biasanya div yang membungkus Judul + HR

                    if (!card || !headerRow) return;

                    // --- Perbaikan Utama ---
                    // 1. Hapus kelas padding yang menyebabkan ruang kosong
                    headerRow.classList.remove('yz-card-header-pad');

                    // 2. Terapkan position: relative ke Card/Card Body agar toolbar absolute posisinya benar.
                    //    (Pilih card jika card-body tidak berfungsi)
                    card.style.position = 'relative';

                    // 3. Hapus toggle lama dan pasang yang baru
                    headerRow.querySelector('.yz-card-toolbar')?.remove();

                    const toolbar = makeToggle();
                    // Pasang di headerRow (div pembungkus judul)  
                    // dan biarkan CSS absolute yang bekerja.
                    headerRow.appendChild(toolbar);
                    // --- End Perbaikan Utama ---

                    // (Logika klik toggle tetap sama)
                    toolbar.querySelector('.yz-currency-toggle')?.addEventListener('click', (e) => {
                        const btn = e.target.closest('button[data-cur]');
                        if (!btn) return;
                        const next = btn.dataset.cur;
                        if (next !== 'USD' && next !== 'IDR') return;
                        if (next === currentCurrency) return;

                        currentCurrency = next;
                        try {
                            localStorage.setItem('poCurrency', currentCurrency);
                        } catch {}

                        renderOutstandingLocation(currentCurrency);
                        renderTopCustomersByCurrency(currentCurrency);

                        document.querySelectorAll('.yz-currency-toggle button[data-cur]').forEach(b => {
                            const v = b.dataset.cur;
                            b.classList.toggle('btn-primary', v === 'USD' && currentCurrency ===
                                'USD');
                            b.classList.toggle('btn-outline-primary', v === 'USD' &&
                                currentCurrency !== 'USD');
                            b.classList.toggle('btn-success', v === 'IDR' && currentCurrency ===
                                'IDR');
                            b.classList.toggle('btn-outline-success', v === 'IDR' &&
                                currentCurrency !== 'IDR');
                        });
                    });
                });
            }

            mountCurrencyToggleIfNeeded();
            if (enableCurrencyToggle) {
                renderOutstandingLocation(currentCurrency);
                renderTopCustomersByCurrency(currentCurrency);
            } else {
                const fallbackCurrency = (dataHolder.dataset.selectedType === 'lokal') ? 'IDR' : 'USD';
                renderOutstandingLocation(fallbackCurrency);
                renderTopCustomersByCurrency(fallbackCurrency);
            }

            /* ---------- RENDER: PO Status Overview (Doughnut) ---------- */
            const ctxStatus = document.getElementById('chartSOStatus');
            let soStatusChart = null;
            if (ctxStatus) {
                const statusData = chartData.so_status;
                if (statusData && (statusData.overdue + statusData.due_this_week + statusData.on_time === 0)) {
                    showNoDataMessage('chartSOStatus');
                } else if (statusData) {
                    soStatusChart = new Chart(ctxStatus, {
                        type: 'doughnut',
                        data: {
                            labels: ['Overdue', 'Due This Week', 'On Time'],
                            datasets: [{
                                data: [statusData.overdue, statusData.due_this_week, statusData
                                    .on_time
                                ],
                                backgroundColor: ['#FF6384', '#FFCE56',
                                    '#4BC0C0'
                                ], // Warna disesuaikan dengan gambar
                                borderColor: ['#fff'],
                                borderWidth: 2
                            }]
                        },
                        options: {
                            cutout: '60%',
                            onClick: async (evt, elements) => {
                                if (!elements.length) return;
                                const idx = elements[0].index;
                                const label = soStatusChart.data.labels[idx];
                                const map = {
                                    'Overdue': 'overdue',
                                    'Due This Week': 'due_this_week',
                                    'On Time': 'on_time'
                                };
                                const statusKey = map[label];
                                if (!statusKey) return;
                                await loadPoStatusDetails(statusKey, label);
                            },
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        boxWidth: 40,
                                        padding: 20
                                    }
                                }
                            }
                        }
                    });
                }
            }

            /* ---------- RENDER: Top 4 Customers with Most Overdue PO ---------- */
            createHorizontalBarChart(
                'chartTopOverdueCustomers',
                chartData.top_customers_overdue,
                'overdue_count',
                'Jumlah PO Terlambat', {
                    bg: 'rgba(220, 53, 69, 0.6)',
                    border: 'rgba(220, 53, 69, 1)'
                }
            );

            /* ---------- RENDER: Performance Details by Type (Table + Bar) ---------- */
            const performanceData = chartData.so_performance_analysis;
            const performanceTbody = document.getElementById('so-performance-tbody');
            const apiPoOverdueDetails =
                "{{ route('dashboard.api.poOverdueDetails') }}"; // Route di controller Anda sudah benar

            const poTypeToCodes = {
                'KMI Export SBY': {
                    werks: '2000',
                    auart: 'ZOR1'
                },
                'KMI Local SBY': {
                    werks: '2000',
                    auart: 'ZOR3'
                },
                'KMI Replace SBY': {
                    werks: '2000',
                    auart: 'ZRP1'
                },
                'KMI Export SMG': {
                    werks: '3000',
                    auart: 'ZOR2'
                },
                'KMI Local SMG': {
                    werks: '3000',
                    auart: 'ZOR4'
                },
                'KMI Replace SMG': {
                    werks: '3000',
                    auart: 'ZRP2'
                },
            };

            const bucketLabel = (b) => (
                b === '1_30' ? 'Overdue 1–30 Days' :
                b === '31_60' ? 'Overdue 31–60 Days' :
                b === '61_90' ? 'Overdue 61–90 Days' :
                'Overdue > 90 Days'
            );

            const getCodesFromItem = (item) => {
                // ... (logic sama seperti sebelumnya) ...
                if (item.IV_WERKS_PARAM && item.IV_AUART_PARAM) return {
                    werks: String(item.IV_WERKS_PARAM),
                    auart: String(item.IV_AUART_PARAM)
                };
                if (item.IV_WERKS && item.IV_AUART) return {
                    werks: String(item.IV_WERKS),
                    auart: String(item.IV_AUART)
                };
                if (item.WERKS && item.AUART) return {
                    werks: String(item.WERKS),
                    auart: String(item.AUART)
                };
                const key = (item.Deskription || '').trim();
                return poTypeToCodes[key] || {
                    werks: '',
                    auart: ''
                };
            };

            if (performanceTbody) {
                if (!performanceData || performanceData.length === 0) {
                    performanceTbody.innerHTML =
                        `<tr><td colspan="6" class="text-center p-5 text-muted">
                        <i class="fas fa-info-circle fa-2x mb-2"></i><br>Performance data is not available for this filter.
                    </td></tr>`;
                } else {
                    let tableHtml = '';
                    performanceData.forEach(item => {
                        const totalSo = parseInt(item.total_so);
                        const overdueSo = parseInt(item.overdue_so_count);
                        const overdueRate = totalSo > 0 ? ((overdueSo / totalSo) * 100).toFixed(1) : 0;

                        const hasIdr = parseFloat(item.total_value_idr) > 0;
                        const hasUsd = parseFloat(item.total_value_usd) > 0;
                        const valueIdr = hasIdr ? formatFullCurrency(item.total_value_idr, 'IDR') : '-';
                        const valueUsd = hasUsd ? formatFullCurrency(item.total_value_usd, 'USD') : '-';
                        const classIdr = hasIdr ? 'text-end' : 'text-center text-muted';
                        const classUsd = hasUsd ? 'text-end' : 'text-center text-muted';

                        const {
                            werks,
                            auart
                        } = getCodesFromItem(item);
                        const totalOverdueForBar = overdueSo;
                        const pct = (n) => totalOverdueForBar > 0 ? (n / totalOverdueForBar * 100).toFixed(2) :
                            0;

                        const seg = (count, percent, color, bucket, textTitle) => {
                            if (!count) return '';
                            return `<div class="bar-segment js-overdue-seg"
                                data-werks="${werks}"
                                data-auart="${auart}"
                                data-bucket="${bucket}"
                                style="width:${percent}%;background-color:${color};cursor:pointer"
                                data-bs-toggle="tooltip"
                                title="${textTitle}: ${count} PO">${count}</div>`;
                        };

                        let barChartHtml = '<div class="bar-chart-container">';
                        barChartHtml += seg(item.overdue_1_30, pct(item.overdue_1_30), '#ffc107', '1_30',
                            '1–30 Days');
                        barChartHtml += seg(item.overdue_31_60, pct(item.overdue_31_60), '#fd7e14', '31_60',
                            '31–60 Days');
                        barChartHtml += seg(item.overdue_61_90, pct(item.overdue_61_90), '#dc3545', '61_90',
                            '61–90 Days');
                        barChartHtml += seg(item.overdue_over_90, pct(item.overdue_over_90), '#8b0000', 'gt_90',
                            '>90 Days');
                        barChartHtml += '</div>';

                        tableHtml += `<tr>
                            <td><div class="fw-bold">${item.Deskription}</div></td>
                            <td class="text-center">${totalSo}</td>
                            <td class="${classIdr}">${valueIdr}</td>
                            <td class="${classUsd}">${valueUsd}</td>
                            <td class="text-center">
                                <span class="fw-bold ${overdueSo > 0 ? 'text-danger' : ''}">${overdueSo}</span>
                                <small class="text-muted d-block">(${overdueRate}%)</small>
                            </td>
                            <td>${ totalOverdueForBar > 0 ? barChartHtml : '<span class="text-muted small">Tidak ada PO terlambat</span>' }</td>
                        </tr>`;
                    });
                    performanceTbody.innerHTML = tableHtml;
                    // Re-initialize tooltips
                    new bootstrap.Tooltip(document.body, {
                        selector: "[data-bs-toggle='tooltip']"
                    });
                }

                // Logic untuk mengklik bar di tabel performance
                performanceTbody.addEventListener('click', async (e) => {
                    const seg = e.target.closest('.js-overdue-seg');
                    if (!seg) return;

                    const bucket = seg.dataset.bucket || '';
                    const werks = seg.dataset.werks || '';
                    const auart = seg.dataset.auart || '';

                    const rowTitle = seg.closest('tr')?.querySelector('td:first-child .fw-bold')
                        ?.textContent?.trim() || 'Selected';
                    const labelText = `${rowTitle} — ${bucketLabel(bucket)}`;

                    const card = performanceTbody.closest('.card');
                    if (!card) return;
                    card.classList.add('position-relative');

                    const container = card.querySelector('#po-overdue-details');
                    container.style.display = 'flex'; // tampilkan container overlay
                    container.style.cssText =
                        'position:absolute;inset:0;background:var(--bs-card-bg,#fff);z-index:10;display:flex;padding:1rem;';

                    const showLoading = () => container.innerHTML = `
                    <div class="card yz-chart-card shadow-sm h-100 w-100">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="card-title mb-0"><i class="fas fa-list me-2"></i>PO List — ${labelText}</h6>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="closePoOverdueOverlay"><i class="fas fa-times"></i></button>
                            </div>
                            <hr class="mt-2">
                            <div class="d-flex align-items-center justify-content-center flex-grow-1 text-muted">
                                <div class="spinner-border spinner-border-sm me-2" role="status"></div> Loading data...
                            </div>
                        </div>
                    </div>`;
                    const showError = (msg) => {
                        container.innerHTML = `
                        <div class="card yz-chart-card shadow-sm h-100 w-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="card-title mb-0 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Error</h6>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="closePoOverdueOverlayError"><i class="fas fa-times"></i></button>
                                </div>
                                <hr class="mt-2">
                                <div class="alert alert-danger mb-0">${msg}</div>
                            </div>
                        </div>`;
                        document.getElementById('closePoOverdueOverlayError')?.addEventListener('click',
                            () => container.style.display = 'none');
                    };

                    showLoading();
                    document.getElementById('closePoOverdueOverlay')?.addEventListener('click', () =>
                        container.style.display = 'none');

                    try {
                        if (!werks || !auart) throw new Error(
                            'Parameter plant (werks) atau order type (auart) kosong.');

                        const api = new URL(apiPoOverdueDetails, window.location.origin);
                        api.searchParams.set('werks', werks);
                        api.searchParams.set('auart', auart);
                        api.searchParams.set('bucket', bucket);

                        const res = await fetch(api, {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        const text = await res.text();
                        let json;
                        try {
                            json = JSON.parse(text);
                        } catch (_) {
                            throw new Error('Server mengembalikan HTML/error page.');
                        }
                        if (!res.ok || !json.ok) throw new Error(json?.message || json?.error ||
                            'Gagal mengambil data.');

                        const rows = json.data || [];
                        const body = rows.map((r, i) => `
                        <tr>
                            <td class="text-center">${i + 1}</td>
                            <td class="text-center">${r.PO ?? '-'}</td>
                            <td class="text-center">${r.SO ?? '-'}</td>
                            <td class="text-center">${r.EDATU ?? '-'}</td>
                            <td class="text-center fw-bold ${(r.OVERDUE_DAYS || 0) > 0 ? 'text-danger' : ''}">${r.OVERDUE_DAYS ?? 0}</td>
                        </tr>`).join('');

                        container.innerHTML = `
                        <div class="card yz-chart-card shadow-sm h-100 w-100">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="card-title mb-0"><i class="fas fa-list me-2"></i>PO List — ${labelText}</h6>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="closePoOverdueOverlay"><i class="fas fa-times"></i></button>
                                </div>
                                <hr class="mt-2">
                                ${rows.length ? `
                                                <div class="table-responsive yz-scrollable-table-container flex-grow-1" style="min-height:0;">
                                                    <table class="table table-sm table-hover align-middle mb-0">
                                                        <thead class="table-light" style="position:sticky;top:0;z-index:1;">
                                                            <tr>
                                                                <th class="text-center" style="width:60px;">NO.</th>
                                                                <th class="text-center" style="min-width:120px;">PO</th>
                                                                <th class="text-center" style="min-width:120px;">SO</th>
                                                                <th class="text-center" style="min-width:120px;">Req. Delv Date</th>
                                                                <th class="text-center" style="min-width:140px;">OVERDUE (DAYS)</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>${body}</tbody>
                                                    </table>
                                                </div>` : `
                                                <div class="text-muted p-4 text-center">
                                                    <i class="fas fa-info-circle me-2"></i>Data tidak ditemukan.
                                                </div>`}
                            </div>
                        </div>`;
                        document.getElementById('closePoOverdueOverlay')?.addEventListener('click', () => {
                            container.style.display = 'none';
                        });
                    } catch (err) {
                        showError(err.message || 'Terjadi kesalahan.');
                    }
                });
            }

            /* ---------- RENDER: Small Quantity chart (PO) ---------- */
            const ctxSmallQty = document.getElementById('chartSmallQtyByCustomer');
            const smallQtyDataRaw = chartData.small_qty_by_customer || [];
            if (ctxSmallQty) {
                if (smallQtyDataRaw.length === 0) {
                    showNoDataMessage('chartSmallQtyByCustomer', 'Tidak ada item outstanding dengan Qty ≤ 5.');
                } else {
                    const customerMap = new Map();
                    smallQtyDataRaw.forEach(item => {
                        if (!customerMap.has(item.NAME1)) customerMap.set(item.NAME1, {
                            '3000': 0,
                            '2000': 0
                        });
                        customerMap.get(item.NAME1)[item.IV_WERKS_PARAM] = parseInt(item.item_count, 10);
                    });
                    const sortedCustomers = [...customerMap.entries()].sort((a, b) =>
                        (b[1]['3000'] + b[1]['2000']) - (a[1]['3000'] + a[1][
                            '2000'
                        ]) // Diubah urutan agar besar di atas
                    );
                    const labels = sortedCustomers.map(item => item[0]);
                    const semarangData = sortedCustomers.map(item => item[1]['3000']);
                    const surabayaData = sortedCustomers.map(item => item[1]['2000']);
                    const detailsContainer = document.getElementById('smallQtyDetailsContainer');
                    const detailsTitle = document.getElementById('smallQtyDetailsTitle');
                    const detailsTable = document.getElementById('smallQtyDetailsTable');
                    const closeButton = document.getElementById('closeDetailsTable');
                    closeButton?.addEventListener('click', () => detailsContainer.style.display = 'none');
                    document.getElementById('exportSmallQtyPdf')?.addEventListener('click', function() {
                        if (this.disabled) return;

                        // isi form tersembunyi
                        document.getElementById('exp_customerName').value = this.dataset.customerName || '';
                        document.getElementById('exp_locationName').value = this.dataset.locationName || '';
                        document.getElementById('exp_type').value = this.dataset.type || '';

                        // submit ke route PDF
                        document.getElementById('smallQtyExportForm').submit();
                    });

                    new Chart(ctxSmallQty, {
                        type: 'bar',
                        data: {
                            labels,
                            datasets: [{
                                    label: 'Semarang',
                                    data: semarangData,
                                    backgroundColor: 'rgba(25, 135, 84, 0.8)'
                                },
                                {
                                    label: 'Surabaya',
                                    data: surabayaData,
                                    backgroundColor: 'rgba(255, 193, 7, 0.8)'
                                }
                            ]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: {
                                    stacked: false, // <-- UBAH KE FALSE AGAR BAR TERPISAH
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Item (With Qty Outstanding ≤ 5)'
                                    },
                                    ticks: {
                                        // Pastikan ticks adalah bilangan bulat
                                        callback: (value) => {
                                            if (Math.floor(value) === value) return value;
                                        }
                                    }
                                },
                                y: {
                                    stacked: false // <-- UBAH KE FALSE
                                }
                            },
                            plugins: {
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
                                const locationName = event.chart.data.datasets[barElement.datasetIndex]
                                    .label;
                                const locationCode = locationName === 'Semarang' ? '3000' : '2000';

                                detailsTitle.textContent =
                                    `Detail Item Outstanding untuk ${customerName} - (${locationName})`;
                                detailsTable.innerHTML =
                                    `<div class="d-flex justify-content-center align-items-center p-5"><div class="spinner-border text-primary" role="status"></div><span class="ms-3 text-muted">Memuat data...</span></div>`;
                                detailsContainer.style.display = 'block';
                                detailsContainer.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start'
                                });

                                const apiUrl = new URL("{{ route('dashboard.api.smallQtyDetails') }}",
                                    window.location.origin);
                                apiUrl.searchParams.append('customerName', customerName);
                                apiUrl.searchParams.append('locationName', locationName);
                                if (filterState.type) apiUrl.searchParams.append('type', filterState.type);

                                try {
                                    const response = await fetch(apiUrl);
                                    const result = await response.json();

                                    if (result.ok && result.data.length > 0) {
                                        // ====== hitung jumlah PO & jumlah item ======
                                        const uniqPO = new Set(result.data.map(r => (r.BSTNK || r.PO || '')
                                            .toString().trim()).filter(Boolean));
                                        const totalPO = uniqPO.size;
                                        const totalItem = result.data.length;

                                        // tampilkan meta ringkas di judul
                                        document.getElementById('smallQtyMeta').textContent =
                                            `• ${totalPO} PO • ${totalItem} Item`;

                                        // aktifkan tombol Export + isi dataset untuk form export
                                        const btnExport = document.getElementById('exportSmallQtyPdf');
                                        btnExport.disabled = false;
                                        btnExport.dataset.customerName = customerName;
                                        btnExport.dataset.locationName =
                                            locationName; // "Semarang" | "Surabaya"
                                        btnExport.dataset.type = (filterState.type ||
                                            ''); // '' | 'lokal' | 'export'

                                        // urutkan item dari outstanding terkecil
                                        result.data.sort((a, b) => parseFloat(a.QTY_BALANCE2) - parseFloat(b
                                            .QTY_BALANCE2));

                                        const tableHeaders = `<tr>
                                            <th style="width:5%;" class="text-center">No.</th>
                                            <th class="text-center">PO</th>
                                            <th class="text-center">SO</th>
                                            <th class="text-center">Item</th>
                                            <th>Desc FG</th>
                                            <th class="text-center">Qty PO</th>
                                            <th class="text-center">Shipped</th>
                                            <th class="text-center">Outstanding</th>
                                        </tr>`;

                                        let tableBody = result.data.map((item, idx) => {
                                            const po = item.BSTNK || item.PO || '-';
                                            return `<tr>
                                                <td class="text-center">${idx + 1}</td>
                                                <td class="text-center">${po}</td>
                                                <td class="text-center">${item.VBELN}</td>
                                                <td class="text-center">${parseInt(item.POSNR, 10)}</td>
                                                <td>${item.MAKTX}</td>
                                                <td class="text-center">${parseFloat(item.KWMENG) || '0'}</td>
                                                <td class="text-center">${parseFloat(item.QTY_GI) || '0'}</td>
                                                <td class="text-center fw-bold text-danger">${parseFloat(item.QTY_BALANCE2)}</td>
                                            </tr>`;
                                        }).join('');

                                        const tableHtml = `
                                            <div class="table-responsive yz-scrollable-table-container" style="max-height: 400px;">
                                                <table class="table table-striped table-hover table-sm align-middle">
                                                    <thead class="table-light">${tableHeaders}</thead>
                                                    <tbody>${tableBody}</tbody>
                                                </table>
                                            </div>`;
                                        detailsTable.innerHTML = tableHtml;
                                    } else {
                                        document.getElementById('smallQtyMeta').textContent = '';
                                        document.getElementById('exportSmallQtyPdf').disabled = true;
                                        detailsTable.innerHTML =
                                            `<div class="text-center p-5 text-muted">Data item tidak ditemukan.</div>`;
                                    }
                                } catch (error) {
                                    console.error('Gagal mengambil data detail:', error);
                                    document.getElementById('smallQtyMeta').textContent = '';
                                    document.getElementById('exportSmallQtyPdf').disabled = true;
                                    detailsTable.innerHTML =
                                        `<div class="text-center p-5 text-danger">Terjadi kesalahan saat memuat data.</div>`;
                                }
                            }
                        }
                    });
                }
            }

            /* ======================== Overlay helper: PO Status (Doughnut Click) ======================== */
            async function loadPoStatusDetails(statusKey, labelText) {
                const container = document.getElementById('so-status-details');
                if (!container) return;
                Object.assign(container.style, {
                    position: 'absolute',
                    top: '0',
                    left: '0',
                    width: '100%',
                    height: '100%',
                    background: 'var(--bs-card-bg, white)',
                    zIndex: '10',
                    display: 'flex',
                    padding: '1rem'
                });

                const api = new URL("{{ route('dashboard.api.poStatusDetails') }}", window.location
                    .origin); // Ganti ke route PO jika ada, atau buat dummy
                api.searchParams.set('status', statusKey);
                if (filterState.location) api.searchParams.set('location', filterState.location);
                if (filterState.type) api.searchParams.set('type', filterState.type);

                // --- START LOADING ---
                container.innerHTML = `
                <div class="card yz-chart-card shadow-sm h-100 w-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="card-title mb-0"><i class="fas fa-list me-2"></i>PO List — ${labelText}</h6>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="closePoStatusDetails"><i class="fas fa-times"></i></button>
                        </div>
                        <hr class="mt-2">
                        <div class="d-flex align-items-center justify-content-center flex-grow-1 text-muted">
                            <div class="spinner-border spinner-border-sm me-2" role="status"></div> Loading data...
                        </div>
                    </div>
                </div>`;

                try {
                    // Cek di controller Anda, jika tidak ada route `dashboard.api.poStatusDetails`, ini akan error 404/500
                    // Asumsi: route ini ada dan mengembalikan data yang sama dengan apiPoOverdueDetails tapi tanpa filter bucket

                    const res = await fetch(api);
                    const json = await res.json();

                    if (!json.ok) {
                        // Jika server merespon dengan error atau json.ok false
                        throw new Error(json.error || 'Gagal mengambil data dari server.');
                    }

                    const rows = json.data || [];
                    const formatDate = (s) => !s ? '' : s.split('-').reverse().join('-');
                    const table = rows.map((r, i) => `
                        <tr>
                            <td class="text-center">${i + 1}</td>
                            <td class="text-center">${r.PO ?? r.BSTNK ?? '-'}</td>
                            <td class="text-center">${r.SO ?? r.VBELN ?? '-'}</td>
                            <td class="text-center">${r.EDATU ?? '-'}</td> 
                            <td class="text-center fw-bold ${((r.OVERDUE_DAYS||0) > 0) ? 'text-danger' : ''}">${r.OVERDUE_DAYS ?? 0}</td>
                        </tr>`).join('');

                    // --- RENDER TABLE ---
                    container.innerHTML = `
                        <div class="card yz-chart-card shadow-sm h-100 w-100">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="card-title mb-0"><i class="fas fa-list me-2"></i>PO List — ${labelText}</h6>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="closePoStatusDetails"><i class="fas fa-times"></i></button>
                                </div>
                                <hr class="mt-2">
                                ${rows.length ? `
                                                <div class="table-responsive yz-scrollable-table-container flex-grow-1" style="min-height:0;">
                                                    <table class="table table-sm table-hover align-middle mb-0">
                                                        <thead class="table-light" style="position:sticky;top:0;z-index:1;">
                                                            <tr>
                                                                <th class="text-center" style="width:60px;">NO.</th>
                                                                <th class="text-center" style="min-width:120px;">PO</th>
                                                                <th class="text-center" style="min-width:120px;">SO</th>
                                                                <th class="text-center" style="min-width:120px;">Req. Delv Date</th>
                                                                <th class="text-center" style="min-width:140px;">OVERDUE (DAYS)</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>${table}</tbody>
                                                    </table>
                                                </div>` : `
                                                <div class="text-muted p-4 text-center">
                                                    <i class="fas fa-info-circle me-2"></i>Data tidak ditemukan.
                                                </div>`}
                            </div>
                        </div>`;
                    document.getElementById('closePoStatusDetails')?.addEventListener('click', () => {
                        container.style.display = 'none';
                        container.innerHTML = '';
                    });

                } catch (e) {
                    // --- RENDER ERROR ---
                    container.innerHTML = `
                    <div class="card yz-chart-card shadow-sm h-100 w-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="card-title mb-0 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Error</h6>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="closePoStatusDetailsError"><i class="fas fa-times"></i></button>
                            </div>
                            <hr class="mt-2">
                            <div class="alert alert-danger mb-0">${e.message}</div>
                        </div>
                    </div>`;
                    document.getElementById('closePoStatusDetailsError')?.addEventListener('click', () => {
                        container.style.display = 'none';
                        container.innerHTML = '';
                    });
                }
            }


            /* ======================== LOGIC KLIK KPI PO ======================== */
            const poApi = "{{ route('api.po.outs_by_customer') }}";

            // Elemen detail card di bawah KPI
            const poBox = document.getElementById('po-outs-details');
            const poTbody = document.getElementById('po-outs-tbody');
            const poTotalEl = document.getElementById('po-outs-total');
            const poFilterEl = document.getElementById('po-outs-filter');
            const poCurBadge = document.getElementById('po-outs-cur');
            const poBtnHide = document.getElementById('po-outs-hide');

            // Re-define helper untuk detail KPI karena ada di dalam closure berbeda
            function fmtKPI(val, cur) {
                val = Number(val || 0);
                const options = {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                };
                if (cur === 'USD') return '$' + val.toLocaleString('en-US', options);
                if (cur === 'IDR') return 'Rp ' + val.toLocaleString('id-ID', options);
                return val.toLocaleString();
            }

            function renderPoLoading(currency) {
                poCurBadge.textContent = currency;
                poFilterEl.textContent =
                    `Filter: ${filterState.location ? plantMap[filterState.location] : 'All Plant'} • ${filterState.type || 'All Type'} ${filterState.auart?('• '+filterState.auart):''}`;
                poTbody.innerHTML = `
                <tr><td colspan="3">
                    <div class="text-center text-muted py-3">
                        <div class="spinner-border spinner-border-sm me-2"></div> Loading...
                    </div>
                </td></tr>`;
                poTotalEl.textContent = '–';
            }

            function renderPoRows(rows, currency) {
                let total = 0;
                const html = rows.map(r => {
                    total += Number(r.TOTAL_VALUE || 0);
                    return `<tr>
                <td>${r.NAME1||''}</td>
                <td class="text-center">${r.ORDER_TYPE||r.AUART||'-'}</td>
                <td class="text-end">${fmtKPI(r.TOTAL_VALUE, currency)}</td>
            </tr>`;
                }).join('');
                poTbody.innerHTML = html;
                poTotalEl.textContent = fmtKPI(total, currency);
            }

            async function openPoDetailBelowKPI(currency) {
                renderPoLoading(currency);
                poBox.style.display = '';

                const params = new URLSearchParams({
                    currency: currency,
                    location: filterState.location || '',
                    type: filterState.type || '',
                    auart: filterState.auart || ''
                });

                try {
                    const res = await fetch(poApi + '?' + params.toString(), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const json = await res.json();
                    const rows = (json && json.ok) ? (json.data || []) : [];

                    if (!rows.length) {
                        poTbody.innerHTML =
                            `<tr><td colspan="3"><div class="alert alert-info m-0">Tidak ada data untuk filter saat ini.</div></td></tr>`;
                        poTotalEl.textContent = '–';
                        return;
                    }
                    renderPoRows(rows, currency);
                } catch (e) {
                    poTbody.innerHTML =
                        `<tr><td colspan="3"><div class="alert alert-danger m-0">Gagal memuat data.</div></td></tr>`;
                    poTotalEl.textContent = '–';
                }
            }

            const usdCard = document.getElementById('kpi-po-outs-usd');
            const idrCard = document.getElementById('kpi-po-outs-idr');

            const poHideFunc = () => {
                poBox.style.display = 'none';
            };

            poBtnHide && poBtnHide.addEventListener('click', poHideFunc);

            // Klik KPI USD
            usdCard && usdCard.addEventListener('click', () => {
                if (poBox.style.display === 'none' || poBox.dataset.activeCurrency !== 'USD') {
                    poBox.dataset.activeCurrency = 'USD';
                    openPoDetailBelowKPI('USD');
                } else {
                    poHideFunc();
                }
            });

            // Klik KPI IDR
            idrCard && idrCard.addEventListener('click', () => {
                if (poBox.style.display === 'none' || poBox.dataset.activeCurrency !== 'IDR') {
                    poBox.dataset.activeCurrency = 'IDR';
                    openPoDetailBelowKPI('IDR');
                } else {
                    poHideFunc();
                }
            });

        })();
    </script>
@endpush
