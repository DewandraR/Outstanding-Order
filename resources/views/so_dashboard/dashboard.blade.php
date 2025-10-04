@extends('layouts.app')

@section('title', 'Outstanding SO Dashboard')

@section('content')

    @php
        // Ambil nilai dari controller
        $werks = $selectedLocation ?? null; // werks dari location
        $auart = $selectedAuart ?? null; // auart (dipertahankan untuk filter server-side)
        $view = 'so';

        // Nilai state global
        $curLoc = $selectedLocation ?? null;
        $curType = $selectedType ?? null;

        // Ambil data mapping untuk JS
        $auartDescriptions = collect($mapping)->flatten()->keyBy('IV_AUART');

        // Helper pembentuk URL terenkripsi ke SO Dashboard
        $encDash = function (array $params) use ($view, $auart) {
            // Hapus 'auart' jika nilainya null/kosong agar URL lebih bersih, namun tetap ada di payload jika ada nilainya
            $payload = array_filter(
                array_merge(['view' => $view, 'auart' => $auart], $params),
                fn($v) => !is_null($v) && $v !== '',
            );
            if (empty($payload['auart'])) {
                unset($payload['auart']);
            }
            return route('so.dashboard', ['q' => \Crypt::encrypt($payload)]);
        };
    @endphp

    {{-- Anchor untuk JS --}}
    <div id="yz-root" data-show="0" data-werks="{{ $werks ?? '' }}" data-auart="{{ $auart ?? '' }}" style="display:none">
    </div>

    {{-- DATA HOLDER untuk Chart.js dan JS logic --}}
    <div id="dashboard-data-holder" data-chart-data='@json($chartData)'
        data-mapping-data='@json($mapping)' data-selected-type='{{ $selectedType }}'
        data-current-view='{{ $view }}' data-current-location='{{ $selectedLocation ?? '' }}'
        data-current-auart='{{ $selectedAuart ?? '' }}' style="display:none;">
    </div>

    <div class="d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-center mb-3 gap-3">
        <div>
            <h2 class="mb-0 fw-bolder">Outstanding SO Overview</h2>
            <p class="text-muted mb-0">Monitoring Sales Orders Ready for Packing</p>
        </div>

        <div class="d-flex flex-wrap gap-2 justify-content-start justify-content-lg-end">

            {{-- Filter Plant (location/werks) --}}
            <ul class="nav nav-pills shadow-sm p-1" style="border-radius:.75rem;">
                <li class="nav-item">
                    <a class="nav-link {{ !$selectedLocation ? 'active' : '' }}"
                        href="{{ $encDash(['location' => null, 'type' => $curType]) }}">
                        All Plant
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $selectedLocation == '3000' ? 'active' : '' }}"
                        href="{{ $encDash(['location' => '3000', 'type' => $curType]) }}">
                        Semarang
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $selectedLocation == '2000' ? 'active' : '' }}"
                        href="{{ $encDash(['location' => '2000', 'type' => $curType]) }}">
                        Surabaya
                    </a>
                </li>
            </ul>

            {{-- Filter Tipe (Export/Lokal) --}}
            <ul class="nav nav-pills shadow-sm p-1" style="border-radius:.75rem;">
                <li class="nav-item">
                    <a class="nav-link {{ !$selectedType ? 'active' : '' }}"
                        href="{{ $encDash(['location' => $curLoc, 'type' => null]) }}">
                        All Type
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $selectedType == 'export' ? 'active' : '' }}"
                        href="{{ $encDash(['location' => $curLoc, 'type' => 'export']) }}">
                        Export
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $selectedType == 'lokal' ? 'active' : '' }}"
                        href="{{ $encDash(['location' => $curLoc, 'type' => 'lokal']) }}">
                        Lokal
                    </a>
                </li>
            </ul>
        </div>
    </div>
    <hr class="mt-0 mb-4">

    @if (!empty($chartData))
        {{-- ==================== DASHBOARD SO CONTENT ==================== --}}
        <div class="row g-4 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="card yz-kpi-card h-100 shadow-sm clickable" id="kpi-so-val-usd-card" style="cursor: pointer;"
                    title="Klik untuk lihat breakdown Total Outstanding Value per Customer (USD)">
                    <div class="card-body d-flex align-items-center">
                        <div class="yz-kpi-icon bg-primary-subtle text-primary"><i class="fas fa-dollar-sign"></i>
                        </div>
                        <div class="ms-3">
                            {{-- Hapus label (Overdue) --}}
                            <div class="mb-1 text-muted yz-kpi-title" data-help-key="so.kpi.total_outstanding_value_usd">
                                <span>Outs Value Packing</span>
                            </div>
                            <h4 class="mb-0 fw-bolder" id="kpi-so-val-usd">$0.00</h4>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card yz-kpi-card h-100 shadow-sm clickable" id="kpi-so-val-idr-card" style="cursor: pointer;"
                    title="Klik untuk lihat breakdown Total Outstanding Value per Customer (IDR)">
                    <div class="card-body d-flex align-items-center">
                        <div class="yz-kpi-icon bg-success-subtle text-success"><i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="ms-3">
                            {{-- Hapus label (Overdue) --}}
                            <div class="mb-1 text-muted yz-kpi-title" data-help-key="so.kpi.total_outstanding_value_idr">
                                <span>Outs Value Packing</span>
                            </div>
                            <h4 class="mb-0 fw-bolder" id="kpi-so-val-idr">Rp 0</h4>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div id="toggle-due-tables-card" class="card yz-kpi-card card-highlight-info h-100 shadow-sm"
                    style="cursor: pointer;" title="Klik untuk menampilkan/menyembunyikan detail SO Due This Week">
                    <div class="card-body d-flex align-items-center">
                        <div class="yz-kpi-icon bg-info-subtle text-info"><i class="fas fa-shipping-fast"></i></div>
                        <div class="ms-3">
                            <div class="mb-1 text-muted yz-kpi-title" data-help-key="so.kpi.value_to_ship_this_week">
                                <span>Value to Packing This Week</span>
                            </div>
                            <h5 class="mb-0 fw-bolder" id="kpi-so-ship-week-usd">$0.00</h5>
                            <h5 class="mb-0 fw-bolder" id="kpi-so-ship-week-idr">Rp 0</h4>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div id="toggle-bottlenecks-card" class="card yz-kpi-card card-highlight-warning h-100 shadow-sm"
                    style="cursor: pointer;" title="Klik untuk melihat Potential Bottlenecks">
                    <div class="card-body d-flex align-items-center">
                        <div class="yz-kpi-icon bg-warning-subtle text-warning"><i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="ms-3">
                            <div class="mb-1 text-muted yz-kpi-title" data-help-key="so.kpi.potential_bottlenecks">
                                <span>Potential Bottlenecks</span>
                            </div>
                            <h4 class="mb-0 fw-bolder"><span id="kpi-so-bottleneck">0</span> <small
                                    id="kpi-so-bottleneck-unit">Items</small></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- === DETAIL (SO): Outs Value Packing by Customer — muncul di bawah KPI === --}}
        <div id="so-outs-details" class="card yz-chart-card mt-3" style="display:none;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-table me-2"></i>
                        Outstanding Value Packing by Customer —
                        <span id="so-outs-cur" class="badge bg-secondary">USD</span>
                    </h5>
                    <button type="button" class="btn btn-sm btn-light" id="so-outs-hide">Hide</button>
                </div>
                <div id="so-outs-filter" class="text-muted small mt-1">Filter: –</div>
                <hr class="mt-2">

                <div class="table-responsive yz-scrollable-table-container" style="max-height:45vh;">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light yz-sticky-thead">
                            <tr>
                                <th>Customer</th>
                                <th class="text-center" style="min-width:160px;">Order Type</th>
                                <th class="text-end" style="min-width:180px;">Value</th>
                            </tr>
                        </thead>
                        <tbody id="so-outs-tbody"></tbody>
                        <tfoot>
                            <tr class="table-light">
                                <th colspan="2" class="text-end">Total</th>
                                <th id="so-outs-total" class="text-end">–</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        {{-- === /DETAIL (SO) === --}}


        {{-- DUE THIS WEEK TABLES --}}
        <div id="due-this-week-tables" style="display: none;">
            @if (!empty($chartData['due_this_week']))
                @php
                    $rangeStart = \Carbon\Carbon::parse($chartData['due_this_week']['start']);
                    $rangeEndEx = \Carbon\Carbon::parse($chartData['due_this_week']['end_excl']);
                    $rangeEnd = $rangeEndEx->copy()->subDay(); // tampil s.d. Minggu
                    $dueSoRows = $chartData['due_this_week']['by_so'] ?? [];
                    $dueCustRows = $chartData['due_this_week']['by_customer'] ?? [];
                    $plantNames = ['2000' => 'SBY', '3000' => 'SMG'];
                @endphp

                <div class="row g-4 mb-4">
                    {{-- KIRI: SO jatuh tempo minggu ini --}}
                    <div class="col-lg-7">
                        <div class="card shadow-sm h-100 yz-chart-card">
                            <div class="card-body">
                                <h5 class="card-title" data-help-key="so.due_this_week_by_so">
                                    <i class="fas fa-truck-fast me-2"></i>SO Due This Week
                                    <span class="text-muted small">
                                        ({{ $rangeStart->translatedFormat('d M Y') }} –
                                        {{ $rangeEnd->translatedFormat('d M Y') }})
                                    </span>
                                </h5>
                                <hr class="mt-2">
                                @if (empty($dueSoRows))
                                    <div class="text-muted p-4 text-center">
                                        <i class="fas fa-info-circle me-2"></i>Tidak ada SO jatuh tempo minggu ini.
                                    </div>
                                @else
                                    <div class="table-responsive yz-scrollable-table-container">
                                        <table class="table table-sm table-hover align-middle mb-0">
                                            <thead class="table-light yz-sticky-thead">
                                                <tr>
                                                    <th class="text-center">SO</th>
                                                    <th class="text-center">PO</th>
                                                    <th>Customer</th>
                                                    <th class="text-center">Plant</th>
                                                    <th class="text-center">Order Type</th>
                                                    <th class="text-center">Due</th>
                                                    <th class="text-end">Value</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($dueSoRows as $r)
                                                    <tr>
                                                        <td class="text-center">{{ $r->VBELN }}</td>
                                                        <td class="text-center">{{ $r->BSTNK }}</td>
                                                        <td>{{ $r->NAME1 }}</td>
                                                        <td class="text-center">
                                                            {{ $plantNames[$r->IV_WERKS_PARAM] ?? $r->IV_WERKS_PARAM }}
                                                        </td>
                                                        <td class="text-center">
                                                            {{ $auartDescriptions[$r->IV_AUART_PARAM]->Deskription ?? $r->IV_AUART_PARAM }}
                                                        </td>
                                                        <td class="text-center">
                                                            {{ \Carbon\Carbon::parse($r->due_date)->format('d-m-Y') }}
                                                        </td>
                                                        <td class="text-end">
                                                            @if ($r->WAERK === 'USD')
                                                                ${{ number_format((float) $r->total_value, 2, '.', ',') }}
                                                            @else
                                                                Rp
                                                                {{ number_format((float) $r->total_value, 2, ',', '.') }}
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- KANAN: ringkasan per customer --}}
                    <div class="col-lg-5">
                        <div class="card shadow-sm h-100 yz-chart-card">
                            <div class="card-body">
                                <h5 class="card-title" data-help-key="so.due_this_week_by_customer">
                                    <i class="fas fa-user-clock me-2"></i>Customers Due This Week
                                </h5>
                                <hr class="mt-2">
                                @if (empty($dueCustRows))
                                    <div class="text-muted p-4 text-center">
                                        <i class="fas fa-info-circle me-2"></i>Tidak ada customer jatuh tempo minggu
                                        ini.
                                    </div>
                                @else
                                    <div class="table-responsive yz-scrollable-table-container">
                                        <table class="table table-sm table-hover align-middle mb-0">
                                            <thead class="table-light yz-sticky-thead">
                                                <tr>
                                                    <th>Customer</th>
                                                    <th class="text-end">Total Value</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($dueCustRows as $r)
                                                    <tr>
                                                        <td>{{ $r->NAME1 }}</td>
                                                        <td class="text-end">
                                                            @if ($r->WAERK === 'USD')
                                                                ${{ number_format((float) $r->total_value, 2, '.', ',') }}
                                                            @else
                                                                Rp
                                                                {{ number_format((float) $r->total_value, 2, ',', '.') }}
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Slot untuk Bottlenecks (diisi via JS/API) --}}
        <div id="bottlenecks-tables" style="display:none;"></div>

        <div class="row g-4 mb-4">
            <div class="col-lg-7">
                <div class="card shadow-sm h-100 yz-chart-card">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title" data-help-key="so.value_by_location_status">
                            <i class="fas fa-chart-column me-2"></i>Value to Packing vs Overdue by Location (USD)
                        </h5>
                        <hr class="mt-2">
                        <div class="chart-container flex-grow-1">
                            <canvas id="chartValueByLocationStatus"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card shadow-sm h-100 yz-chart-card position-relative">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title" data-help-key="so.status_overview">
                            <i class="fas fa-clock me-2"></i>SO Fulfillment Urgency
                        </h5>
                        <hr class="mt-2">
                        <div class="chart-container flex-grow-1">
                            <canvas id="chartSoUrgency"></canvas>
                        </div>
                    </div>
                    {{-- Slot untuk detail Urgency (diisi via JS/API) --}}
                    <div id="so-urgency-details" style="display:none;"></div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card shadow-sm h-100 yz-chart-card">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title text-primary-emphasis" data-help-key="so.top_overdue_customers_value">
                            <i class="fas fa-crown me-2"></i>Top 5 Customers by Value of Overdue Orders Awaiting
                            Packing (USD)
                        </h5>
                        <hr class="mt-2">
                        <div class="chart-container flex-grow-1" style="min-height: 400px;">
                            <canvas id="chartTopCustomersValueSO"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Items with Remark --}}
        <div class="row g-4 mb-4">
            <div class="col-lg-12">
                <div class="card yz-card shadow-sm h-100" id="remark-inline-container">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="card-title" data-help-key="so.items_with_remark">
                                <i class="fas fa-sticky-note me-2"></i>Item with Remark
                            </h5>
                        </div>
                        <hr class="mt-2">
                        <div id="remark-list-box-inline" class="flex-grow-1">
                            <div class="text-center text-muted py-4">
                                <div class="spinner-border spinner-border-sm me-2"></div> Loading data...
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="alert alert-info text-center">
            Data dashboard SO tidak tersedia untuk filter saat ini.
        </div>
    @endif

@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard-style.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
    <script src="{{ asset('vendor/chartjs/chartjs-adapter-date-fns.bundle.min.js') }}"></script>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Bind awal
            preventInfoButtonPropagation();

            // Bind ulang untuk ikon yang disisipkan dinamis oleh chart-help.js
            const iv = setInterval(() => {
                if (!document.querySelector('.yz-info-icon:not([data-click-bound="1"])')) {
                    clearInterval(iv);
                    return;
                }
                preventInfoButtonPropagation();
            }, 500);

            // Stop pengecekan setelah 5 detik
            setTimeout(() => clearInterval(iv), 5000);
        });
        // =========================================================
        // Helper Functions (Copy dari kode lama)
        // =========================================================

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

        function preventInfoButtonPropagation() {
            document.querySelectorAll('.yz-info-icon').forEach(btn => {
                if (btn.dataset.clickBound === '1') return;
                btn.addEventListener('click', (e) => {
                    e.stopPropagation(); // cukup hentikan bubbling
                    // JANGAN panggil e.preventDefault() atau e.stopImmediatePropagation()
                });
                btn.dataset.clickBound = '1';
            });
        }
        document.addEventListener('DOMContentLoaded', function() {
            // Logika hide currency toggle jika type filter aktif (tetap dipertahankan)
            const typeSelected = {!! json_encode((bool) $curType) !!};
            if (typeSelected) {
                document.querySelectorAll('.yz-currency-toggle').forEach(el => el.remove());
                const maybeGroups = document.querySelectorAll('.btn-group, .nav, .nav-pills');
                maybeGroups.forEach(g => {
                    const labels = Array.from(g.querySelectorAll('a,button'))
                        .map(b => (b.textContent || '').trim().toUpperCase());
                    if (labels.includes('USD') && labels.includes('IDR')) g.remove();
                });
            }
        });

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

        function injectToggleStyles() {
            if (document.getElementById('yzToggleCss')) return;
            const style = document.createElement('style');
            style.id = 'yzToggleCss';
            style.textContent = `
        .yz-card-toolbar {
            position: absolute;
            top: .75rem;
            right: .75rem;
            z-index: 3;
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

            const labels = chartData.map(d => {
                const customerName = d.NAME1.length > 25 ? d.NAME1.substring(0, 25) + '...' : d.NAME1;
                if (d.locations) return [customerName, formatLocations(d.locations)];
                return customerName;
            });
            const values = chartData.map(d => d[dataKey]);

            let existingChart = Chart.getChart(canvasId);
            if (existingChart) {
                existingChart.destroy();
            }
            hideNoDataMessage(canvasId);

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

                                    if (currency && dataPoint) {
                                        const totalTxt = formatFullCurrency(context.raw, currency);

                                        let breakdownTxt = '';
                                        if (canvasId === 'chartTopCustomersValueSO') {
                                            const sby = Number(dataPoint.sby_value || 0);
                                            const smg = Number(dataPoint.smg_value || 0);

                                            if (sby > 0 && smg > 0) {
                                                breakdownTxt =
                                                    ` (SMG: ${formatFullCurrency(smg, currency)}, ` +
                                                    `SBY: ${formatFullCurrency(sby, currency)})`;
                                            } else if (smg > 0 && sby === 0) {
                                                breakdownTxt = ' (SMG)';
                                            } else if (sby > 0 && smg === 0) {
                                                breakdownTxt = ' (SBY)';
                                            }
                                        }

                                        const soCountTxt = dataPoint.so_count ?
                                            ` (${dataPoint.so_count} PO)` : '';
                                        return `${totalTxt}${breakdownTxt}${soCountTxt}`;
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

        function escapeHtml(str = '') {
            return String(str).replace(/[&<>"']/g, s => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [s]));
        }

        // =========================================================
        // MAIN SO DASHBOARD SCRIPT
        // =========================================================
        (() => {
            injectToggleStyles();
            const dataHolder = document.getElementById('dashboard-data-holder');
            if (!dataHolder) return;

            const mappingData = JSON.parse(dataHolder.dataset.mappingData || '{}');
            const filterState = {
                location: dataHolder.dataset.currentLocation || null,
                type: dataHolder.dataset.selectedType || null,
                auart: dataHolder.dataset.currentAuart || null,
            };

            const auartMap = {};
            if (mappingData) {
                for (const werks in mappingData) {
                    mappingData[werks].forEach(item => {
                        auartMap[item.IV_AUART] = item.Deskription;
                    });
                }
            }
            const plantMap = {
                '2000': 'Surabaya',
                '3000': 'Semarang'
            };

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

            /* ======================== KPI & Data Initialization ======================== */

            // Mengisi KPI Card - Menggunakan nilai TOTAL outstanding yang sudah dihitung di Controller
            document.getElementById('kpi-so-val-usd').textContent = formatFullCurrency(chartData.kpi
                .total_outstanding_value_usd, 'USD');
            document.getElementById('kpi-so-val-idr').textContent = formatFullCurrency(chartData.kpi
                .total_outstanding_value_idr, 'IDR');
            document.getElementById('kpi-so-ship-week-usd').textContent = formatFullCurrency(chartData.kpi
                .value_to_ship_this_week_usd, 'USD');
            document.getElementById('kpi-so-ship-week-idr').textContent = formatFullCurrency(chartData.kpi
                .value_to_ship_this_week_idr, 'IDR');
            document.getElementById('kpi-so-bottleneck').textContent = chartData.kpi.potential_bottlenecks;


            /* ======================== Currency Toggle Logic (Value by Location & Top Customer) ======================== */

            const enableSoCurrencyToggle = !selectedType;
            let currentSoCurrency;

            if (selectedType === 'lokal') {
                currentSoCurrency = 'IDR';
            } else if (selectedType === 'export') {
                currentSoCurrency = 'USD';
            } else {
                currentSoCurrency = 'USD';
                try {
                    const saved = localStorage.getItem('soTopCustomerCurrency');
                    if (saved === 'USD' || saved === 'IDR') {
                        currentSoCurrency = saved;
                    }
                } catch (e) {}
            }

            let soValByLocChart = null;
            let soTopCustomersChart = null;

            function buildSoLocationSeries(rows, currency) {
                const labels = ['Semarang', 'Surabaya'];
                const curKey = currency === 'IDR' ? 'idr' : 'usd';
                const findRow = (loc) => (rows || []).find(d => d.location === loc) || {};
                const num = (v) => Number(v || 0);

                const onTime = labels.map(loc => num((findRow(loc).on_time_breakdown || {})[curKey]));
                const overdue = labels.map(loc => num((findRow(loc).overdue_breakdown || {})[curKey]));

                return {
                    labels,
                    onTime,
                    overdue
                };
            }

            function renderSoValueByLocationStatus(currency) {
                const canvasId = 'chartValueByLocationStatus';
                const ctx = document.getElementById(canvasId);
                if (!ctx) return;

                const rows = chartData.value_by_location_status || [];
                const {
                    labels,
                    onTime,
                    overdue
                } = buildSoLocationSeries(rows, currency);
                const total = [...onTime, ...overdue].reduce((a, b) => a + b, 0);

                if (soValByLocChart) soValByLocChart.destroy();
                soValByLocChart = null;

                if (total === 0) {
                    showNoDataMessage(canvasId);
                    return;
                }
                hideNoDataMessage(canvasId);
                setTitleCurrencySuffixByCanvas(canvasId, currency);

                soValByLocChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [{
                                label: 'On Time',
                                data: onTime,
                                backgroundColor: 'rgba(75, 192, 192, 0.7)'
                            },
                            {
                                label: 'Overdue',
                                data: overdue,
                                backgroundColor: 'rgba(255, 99, 132, 0.7)'
                            }
                        ]
                    },
                    options: {
                        scales: {
                            x: {
                                stacked: true
                            },
                            y: {
                                stacked: true,
                                beginAtZero: true,
                                ticks: {
                                    callback: (v) => new Intl.NumberFormat('id-ID').format(v)
                                }
                            }
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: (ctx) =>
                                        `${ctx.dataset.label}: ${formatFullCurrency(ctx.raw || 0, currency)}`
                                }
                            }
                        }
                    }
                });
            }

            function renderSoTopCustomers(currency) {
                const canvasId = 'chartTopCustomersValueSO';
                if (soTopCustomersChart) soTopCustomersChart.destroy();
                soTopCustomersChart = null;
                setTitleCurrencySuffixByCanvas(canvasId, currency);

                // Chart Top Customers ini menggunakan data OVERDUE (sesuai judulnya)
                const data = (currency === 'IDR') ?
                    chartData.top_customers_value_idr :
                    chartData.top_customers_value_usd;

                const colors = (currency === 'IDR') ? {
                    bg: 'rgba(25, 135, 84, 0.7)',
                    border: 'rgba(25, 135, 84, 1)'
                } : {
                    bg: 'rgba(59, 130, 246, 0.7)',
                    border: 'rgba(59, 130, 246, 1)'
                };

                createHorizontalBarChart(
                    canvasId,
                    data,
                    'total_value',
                    'Value of Overdue Orders',
                    colors,
                    currency
                );
                soTopCustomersChart = Chart.getChart(canvasId);
            }

            function rerenderSoCurrencyDependentCharts() {
                renderSoTopCustomers(currentSoCurrency);
                renderSoValueByLocationStatus(currentSoCurrency);
            }

            function mountSoCurrencyToggle() {
                if (!enableSoCurrencyToggle) return;

                const targets = [
                    document.getElementById('chartValueByLocationStatus'),
                    document.getElementById('chartTopCustomersValueSO')
                ].filter(Boolean);

                const makeToggle = () => {
                    const toolbar = document.createElement('div');
                    toolbar.className = 'yz-card-toolbar';
                    toolbar.innerHTML = `
        <div class="btn-group btn-group-sm yz-currency-toggle" role="group">
          <button type="button" data-cur="USD" class="btn ${currentSoCurrency==='USD' ? 'btn-primary' : 'btn-outline-primary'}">USD</button>
          <button type="button" data-cur="IDR" class="btn ${currentSoCurrency==='IDR' ? 'btn-success' : 'btn-outline-success'}">IDR</button>
        </div>`;
                    return toolbar;
                };

                targets.forEach(cv => {
                    const card = cv.closest('.card');
                    const cardBody = card?.querySelector('.card-body');
                    if (!cardBody || card.querySelector('.yz-card-toolbar')) return;

                    const toolbar = makeToggle();
                    card.style.position = 'relative';
                    cardBody.appendChild(toolbar);

                    toolbar.addEventListener('click', (e) => {
                        const btn = e.target.closest('button[data-cur]');
                        if (!btn) return;
                        const next = btn.dataset.cur;
                        if (next === currentSoCurrency) return;

                        currentSoCurrency = next;
                        try {
                            localStorage.setItem('soTopCustomerCurrency', currentSoCurrency);
                        } catch {}

                        document.querySelectorAll('.yz-currency-toggle button[data-cur]').forEach(b => {
                            const isUSD = b.dataset.cur === 'USD';
                            const isIDR = b.dataset.cur === 'IDR';
                            b.classList.toggle('btn-primary', isUSD && currentSoCurrency ===
                                'USD');
                            b.classList.toggle('btn-outline-primary', isUSD &&
                                currentSoCurrency !==
                                'USD');
                            b.classList.toggle('btn-success', isIDR && currentSoCurrency ===
                                'IDR');
                            b.classList.toggle('btn-outline-success', isIDR &&
                                currentSoCurrency !==
                                'IDR');
                        });

                        rerenderSoCurrencyDependentCharts();
                    });
                });
            }

            mountSoCurrencyToggle();
            rerenderSoCurrencyDependentCharts();


            /* ======================== Donut Chart (SO Urgency) ======================== */

            async function loadSoUrgencyDetails(statusKey, labelText) {
                const container = document.getElementById('so-urgency-details');
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
                container.innerHTML =
                    `<div class="card yz-chart-card shadow-sm h-100 w-100"><div class="card-body d-flex flex-column"><div class="d-flex justify-content-between align-items-center"><h6 class="card-title mb-0"><i class="fas fa-list me-2"></i>SO List — ${labelText}</h6><button type="button" class="btn btn-sm btn-outline-secondary" disabled><i class="fas fa-times"></i></button></div><hr class="mt-2"><div class="d-flex align-items-center justify-content-center flex-grow-1 text-muted"><div class="spinner-border spinner-border-sm me-2" role="status"></div> Loading data...</div></div></div>`;

                const api = new URL("{{ route('so.api.urgency_details') }}", window.location.origin);
                api.searchParams.set('status', statusKey);
                if (filterState.location) api.searchParams.set('location', filterState.location);
                if (filterState.type) api.searchParams.set('type', filterState.type);
                if (filterState.auart) api.searchParams.set('auart', filterState.auart);

                try {
                    const res = await fetch(api);
                    const json = await res.json();
                    if (!json.ok) throw new Error(json.error || 'Gagal mengambil data dari server.');
                    renderSoUrgencyTable(json.data, labelText);
                } catch (e) {
                    container.innerHTML =
                        `<div class="card yz-chart-card shadow-sm h-100 w-100"><div class="card-body d-flex flex-column"><div class="d-flex justify-content-between align-items-center"><h6 class="card-title mb-0 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Error</h6><button type="button" class="btn btn-sm btn-outline-secondary" id="closeSoUrgencyDetailsError"><i class="fas fa-times"></i></button></div><hr class="mt-2"><div class="alert alert-danger mb-0">${e.message}</div></div></div>`;
                    document.getElementById('closeSoUrgencyDetailsError')?.addEventListener('click', () => {
                        container.removeAttribute('style');
                        container.innerHTML = '';
                    });
                }
            }

            function renderSoUrgencyTable(rows, labelText) {
                const container = document.getElementById('so-urgency-details');
                if (!container) return;
                const formatDate = (s) => !s ? '' : s.split('-').reverse().join('-');
                const table = (rows || []).map((r, i) => `
      <tr>
        <td class="text-center">${i + 1}</td>
        <td class="text-center">${r.BSTNK ?? '-'}</td>
        <td class="text-center">${r.VBELN}</td>
        <td>${r.NAME1 ?? ''}</td>
        <td class="text-center">${plantMap[r.IV_WERKS_PARAM] || r.IV_WERKS_PARAM}</td>
        <td class="text-center">${
      (() => {
        const desc  = auartMap[r.IV_AUART_PARAM] || r.order_type_name || '';
        const short = r.IV_WERKS_PARAM === '2000' ? 'SBY' : r.IV_WERKS_PARAM === '3000' ? 'SMG' : '';
        return desc ? `${desc}${short ? ' ' + short : ''}`.trim() : (r.IV_AUART_PARAM || '');
      })()
    }</td>
        <td class="text-center">${formatDate(r.due_date) || '-'}</td>
      </tr>`).join('');
                container.innerHTML = `
      <div class="card yz-chart-card shadow-sm h-100 w-100">
        <div class="card-body d-flex flex-column">
          <div class="d-flex justify-content-between align-items-center">
            <h6 class="card-title mb-0"><i class="fas fa-list me-2"></i>SO List — ${labelText}</h6>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="closeSoUrgencyDetails"><i class="fas fa-times"></i></button>
          </div>
          <hr class="mt-2">
          ${(rows && rows.length) ? `
                                                      <div class="table-responsive yz-scrollable-table-container flex-grow-1" style="min-height: 0;">
                                                          <table class="table table-sm table-hover align-middle mb-0">
                                                              <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                                                                  <tr>
                                                                      <th class="text-center" style="width:60px;">NO.</th>
                                                                      <th class="text-center" style="min-width:120px;">PO</th>
                                                                      <th class="text-center" style="min-width:120px;">SO</th>
                                                                      <th>CUSTOMER</th>
                                                                      <th class="text-center" style="min-width:100px;">PLANT</th>
                                                                      <th class="text-center" style="min-width:120px;">ORDER TYPE</th>
                                                                      <th class="text-center" style="min-width:120px;">DUE DATE</th>
                                                                  </tr>
                                                              </thead>
                                                              <tbody>${table}</tbody>
                                                          </table>
                                                      </div>` :
              `<div class="text-muted p-4 text-center"><i class="fas fa-info-circle me-2"></i>Data tidak ditemukan.</div>`
            }
        </div>
      </div>`;
                document.getElementById('closeSoUrgencyDetails')?.addEventListener('click', () => {
                    container.removeAttribute('style');
                    container.innerHTML = '';
                });
            }

            const ctxSoUrgency = document.getElementById('chartSoUrgency');
            if (ctxSoUrgency && chartData.aging_analysis) {
                const agingData = chartData.aging_analysis;
                const total = Object.values(agingData).reduce((a, b) => a + b, 0);
                if (total === 0) {
                    showNoDataMessage('chartSoUrgency');
                } else {
                    const soUrgencyChart = new Chart(ctxSoUrgency, {
                        type: 'doughnut',
                        data: {
                            labels: ['Overdue > 30 Days', 'Overdue 1-30 Days', 'Due This Week', 'On Time'],
                            datasets: [{
                                data: [agingData.overdue_over_30, agingData.overdue_1_30, agingData
                                    .due_this_week, agingData.on_time
                                ],
                                backgroundColor: ['#b91c1c', '#ef4444', '#f59e0b', '#10b981']
                            }]
                        },
                        options: {
                            cutout: '60%',
                            onClick: async (evt, elements) => {
                                if (!elements.length) return;
                                const idx = elements[0].index;
                                const label = soUrgencyChart.data.labels[idx];
                                const map = {
                                    'Overdue > 30 Days': 'overdue_over_30',
                                    'Overdue 1-30 Days': 'overdue_1_30',
                                    'Due This Week': 'due_this_week',
                                    'On Time': 'on_time'
                                };
                                const statusKey = map[label];
                                if (!statusKey) return;
                                await loadSoUrgencyDetails(statusKey, label);
                            }
                        }
                    });
                }
            }

            /* ======================== KPI Detail Table (Outs by Customer) ======================== */
            (function() {
                const soApi = "{{ route('so.api.outs_by_customer') }}";
                const soBox = document.getElementById('so-outs-details');
                const soTbody = document.getElementById('so-outs-tbody');
                const soTotalEl = document.getElementById('so-outs-total');
                const soFilterEl = document.getElementById('so-outs-filter');
                const soCurBadge = document.getElementById('so-outs-cur');
                const soBtnHide = document.getElementById('so-outs-hide');

                const curLoc = filterState.location;
                const curType = filterState.type;
                const curAu = filterState.auart;

                function fmt(val, cur) {
                    val = Number(val || 0);
                    if (cur === 'USD') return '$' + val.toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                    if (cur === 'IDR') return 'Rp ' + val.toLocaleString('id-ID', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                    return val.toLocaleString();
                }

                function soShow() {
                    soBox.style.display = '';
                }

                function soHide() {
                    soBox.style.display = 'none';
                }
                soBtnHide && soBtnHide.addEventListener('click', soHide);

                function renderSoLoading(currency) {
                    soCurBadge.textContent = currency;
                    // Hilangkan curAu dari label filter UI
                    soFilterEl.textContent = `Filter: ${curLoc||'All Plant'} • ${curType||'All Type'}`;
                    soTbody.innerHTML = `
      <tr><td colspan="3">
        <div class="text-center text-muted py-3">
          <div class="spinner-border spinner-border-sm me-2"></div> Loading...
        </div>
      </td></tr>`;
                    soTotalEl.textContent = '–';
                }

                function renderSoRows(rows, currency) {
                    soTbody.innerHTML = rows.map(r => `
      <tr>
        <td>${r.NAME1||''}</td>
        <td class="text-center">${r.ORDER_TYPE||''}</td>
        <td class="text-end">${fmt(r.TOTAL_VALUE, currency)}</td>
      </tr>`).join('');
                }

                async function openSoDetailBelowKPI(currency) {
                    renderSoLoading(currency);
                    soShow();

                    const params = new URLSearchParams({
                        currency: currency,
                        location: curLoc || '',
                        type: curType || '',
                        auart: curAu || '' // Tetap kirim ke API agar filter server-side tetap berjalan
                    });

                    try {
                        const res = await fetch(soApi + '?' + params.toString(), {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        const json = await res.json();
                        const rows = (json && json.ok) ? (json.data || []) : [];
                        const gtot = (json && json.ok) ? json.grand_total : null;

                        if (!rows.length) {
                            soTbody.innerHTML =
                                `<tr><td colspan="3"><div class="alert alert-info m-0">Tidak ada data untuk filter saat ini.</div></td></tr>`;
                            soTotalEl.textContent = '–';
                            return;
                        }

                        renderSoRows(rows, currency);
                        if (gtot !== null) soTotalEl.textContent = fmt(gtot, currency);
                    } catch (e) {
                        soTbody.innerHTML =
                            `<tr><td colspan="3"><div class="alert alert-danger m-0">Gagal memuat data.</div></td></tr>`;
                        soTotalEl.textContent = '–';
                    }
                }

                const soUsd = document.getElementById('kpi-so-val-usd-card');
                const soIdr = document.getElementById('kpi-so-val-idr-card');
                const soDetailBox = document.getElementById('so-outs-details');

                const soHideFunc = () => {
                    soDetailBox.style.display = 'none';
                };

                if (soUsd) {
                    soUsd.addEventListener('click', (ev) => {
                        if (ev.target.closest('.yz-info-icon')) return; // GUARD
                        if (soDetailBox.style.display === 'none' || soDetailBox.dataset.activeCurrency !==
                            'USD') {
                            soDetailBox.dataset.activeCurrency = 'USD';
                            openSoDetailBelowKPI('USD');
                        } else {
                            soHideFunc();
                        }
                    });
                }

                if (soIdr) {
                    soIdr.addEventListener('click', (ev) => {
                        if (ev.target.closest('.yz-info-icon')) return; // GUARD
                        if (soDetailBox.style.display === 'none' || soDetailBox.dataset.activeCurrency !==
                            'IDR') {
                            soDetailBox.dataset.activeCurrency = 'IDR';
                            openSoDetailBelowKPI('IDR');
                        } else {
                            soHideFunc();
                        }
                    });
                }
            })();

            /* ======================== Bottlenecks Toggle/Fetch ======================== */

            const bottleneckCard = document.getElementById('toggle-bottlenecks-card');
            const bottleneckBox = document.getElementById('bottlenecks-tables');
            const apiSoBottlenecks = "{{ route('so.api.bottlenecks_details') }}";

            function renderBottlenecksTable(rows, windowInfo) {
                const auartMap2 = auartMap;
                const fmt = s => (!s ? '' : s.split('-').reverse().join('-'));

                let dateRangeText = '';
                if (windowInfo && windowInfo.start && windowInfo.end) {
                    const startDate = new Date(windowInfo.start + 'T00:00:00').toLocaleDateString('id-ID', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric'
                    });
                    const endDate = new Date(windowInfo.end + 'T00:00:00').toLocaleDateString('id-ID', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric'
                    });
                    dateRangeText = `(${startDate} – ${endDate})`;
                }

                const body = (rows || []).map((r, i) => `
        <tr>
            <td class="text-center">${i+1}</td>
            <td class="text-center">${r.VBELN}</td>
            <td class="text-center">${r.BSTNK ?? '-'}</td>
            <td>${r.NAME1 ?? ''}</td>
            <td class="text-center">${({ '2000':'Surabaya','3000':'Semarang' })[r.IV_WERKS_PARAM] || r.IV_WERKS_PARAM}</td>
            <td class="text-center">${auartMap2[r.IV_AUART_PARAM] || r.IV_AUART_PARAM}</td>
            <td class="text-center">${fmt(r.due_date) || '-'}</td>
        </tr>
    `).join('');

                bottleneckBox.innerHTML = `
        <div class="row g-4 mb-4">
            <div class="col-lg-12">
                <div class="card shadow-sm h-100 yz-chart-card">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <span><i class="fas fa-exclamation-triangle me-2"></i>Potential Bottlenecks (SO Level)</span>
                                <span class="text-muted small ms-2">${dateRangeText}</span>
                            </h5>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="close-bottlenecks"><i class="fas fa-times"></i></button>
                        </div>
                <hr class="mt-2">
                ${(rows && rows.length) ? `
                                                                    <div class="table-responsive yz-scrollable-table-container flex-grow-1" style="min-height:0;">
                                                                        <table class="table table-sm table-hover align-middle mb-0">
                                                                            <thead class="table-light" style="position:sticky;top:0;z-index:1;">
                                                                                <tr>
                                                                                    <th class="text-center" style="width:60px;">NO.</th>
                                                                                    <th class="text-center" style="min-width:120px;">SO</th>
                                                                                    <th class="text-center" style="min-width:120px;">PO</th>
                                                                                    <th>Customer</th>
                                                                                    <th class="text-center" style="min-width:100px;">Plant</th>
                                                                                    <th class="text-center" style="min-width:140px;">Order Type</th>
                                                                                    <th class="text-center" style="min-width:120px;">Due Date</th>
                                                                                </tr>
                                                                            </thead>
                                                                            <tbody>${body}</tbody>
                                                                        </table>
                                                                    </div>` :
              `<div class="text-muted p-4 text-center"><i class="fas fa-info-circle me-2"></i>Tidak ada Potensial bottleneck (dalam 7 hari ke depan).</div>`}
              </div>
            </div>
          </div>
        </div>`;
                document.getElementById('close-bottlenecks')?.addEventListener('click', () => bottleneckBox.style
                    .display = 'none');
            }

            if (bottleneckCard && bottleneckBox) {
                bottleneckCard.addEventListener('click', async (ev) => {
                    if (ev.target.closest('.yz-info-icon')) return; // GUARD
                    const api = new URL(apiSoBottlenecks, window.location.origin);
                    const isHidden = bottleneckBox.style.display === 'none';
                    if (!isHidden) {
                        bottleneckBox.style.display = 'none';
                        return;
                    }
                    bottleneckBox.style.display = '';
                    bottleneckBox.innerHTML =
                        `<div class="card yz-chart-card shadow-sm"><div class="card-body d-flex align-items-center justify-content-center"><div class="spinner-border spinner-border-sm me-2" role="status"></div> Loading data...</div></div>`;

                    if (filterState.location) api.searchParams.set('location', filterState.location);
                    if (filterState.type) api.searchParams.set('type', filterState.type);

                    try {
                        const res = await fetch(api);
                        const json = await res.json();
                        if (!json.ok) throw new Error(json.error || 'Gagal mengambil data.');
                        renderBottlenecksTable(json.data || [], json.window_info);
                    } catch (e) {
                        bottleneckBox.innerHTML =
                            `<div class="alert alert-danger m-3"><i class="fas fa-exclamation-triangle me-2"></i>${e.message}</div>`;
                    }
                });
            }

            // Toggle Due This Week Table
            const toggleCard = document.getElementById('toggle-due-tables-card');
            const tablesContainer = document.getElementById('due-this-week-tables');
            if (toggleCard && tablesContainer) {
                toggleCard.addEventListener('click', (ev) => {
                    if (ev.target.closest('.yz-info-icon')) return; // GUARD
                    const isHidden = tablesContainer.style.display === 'none';
                    tablesContainer.style.display = isHidden ? '' : 'none';
                });
            }

            /* ======================== ITEM WITH REMARK (INLINE) ======================== */
            (function itemWithRemarkTableOnly() {
                const apiRemarkItems = "{{ route('so.api.remark_items') }}";
                const listBox = document.getElementById('remark-list-box-inline');
                if (!listBox) return;
                const currentLocation = filterState.location;
                const currentType = filterState.type;
                const stripZeros = v => {
                    const s = String(v ?? '').trim();
                    if (!s) return '';
                    const z = s.replace(/^0+/, '');
                    return z.length ? z : '0';
                };
                const __plantName = w => ({
                    '2000': 'Surabaya',
                    '3000': 'Semarang'
                } [String(w || '').trim()] || (w ?? ''));
                const __auartDesc = auartMap;

                function buildTable(rows) {
                    if (!rows?.length) {
                        return `<div class="text-center text-muted py-4"><i class="fas fa-info-circle me-2"></i>Tidak ada item dengan remark.</div>`;
                    }

                    const body = rows.map((r, i) => {
                        const item = stripZeros(r.POSNR);
                        const werks = (r.IV_WERKS_PARAM || '').trim();
                        const auart = String(r.IV_AUART_PARAM || '').trim();
                        const plant = __plantName(werks);
                        const otName = __auartDesc[auart] || auart || '-';
                        const so = (r.VBELN || '').trim();
                        const kunnr = (r.KUNNR || '').trim();

                        const postData = {
                            redirect_to: 'so.index',
                            werks: werks,
                            auart: auart,
                            compact: 1,
                            highlight_kunnr: kunnr,
                            highlight_vbeln: so,
                            highlight_posnr: item,
                            auto_expand: '1'
                        };

                        return `
<tr class="js-remark-row" data-payload='${JSON.stringify(postData)}' style="cursor:pointer;" title="Klik untuk melihat detail SO">
    <td class="text-center">${i + 1}</td>
    <td class="text-center">${so || '-'}</td>
    <td class="text-center">${item || '-'}</td>
    <td class="text-center">${plant || '-'}</td>
    <td class="text-center">${otName}</td>
    <td>${escapeHtml(r.remark || '').replace(/\n/g,'<br>')}</td>
</tr>`;
                    }).join('');

                    return `
        <div class="yz-scrollable-table-container" style="max-height:420px;">
            <table class="table table-striped table-hover table-sm align-middle mb-0">
                <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                    <tr>
                        <th class="text-center" style="width:60px;">No.</th>
                        <th class="text-center" style="min-width:110px;">SO</th>
                        <th class="text-center" style="min-width:90px;">Item</th>
                        <th class="text-center" style="min-width:110px;">Plant</th>
                        <th class="text-center" style="min-width:160px;">Order Type</th>
                        <th style="min-width:220px;">Remark</th>
                    </tr>
                </thead>
                <tbody>${body}</tbody>
            </table>
        </div>
        <div class="small text-muted mt-2">Klik baris untuk membuka laporan SO terkait.</div>`;
                }

                async function loadList() {
                    const inlineCard = document.getElementById('remark-inline-container');
                    inlineCard.style.display = '';
                    listBox.innerHTML = `
        <div class="d-flex justify-content-center align-items-center py-4 text-muted">
            <div class="spinner-border spinner-border-sm me-2"></div> Loading data...
        </div>`;

                    try {
                        const url = new URL(apiRemarkItems, window.location.origin);
                        if (currentLocation) url.searchParams.set('location', currentLocation);
                        if (currentType) url.searchParams.set('type', currentType);

                        const res = await fetch(url, {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        const json = await res.json();
                        if (!json.ok) throw new Error(json.error || 'Gagal memuat daftar item.');
                        listBox.innerHTML = buildTable(json.data || []);
                    } catch (e) {
                        listBox.innerHTML =
                            `<div class="alert alert-danger m-0"><i class="fas fa-exclamation-triangle me-2"></i>${e.message}</div>`;
                    }
                }

                listBox.addEventListener('click', (ev) => {
                    const tr = ev.target.closest('.js-remark-row');
                    if (!tr || !tr.dataset.payload) return;

                    const rowData = JSON.parse(tr.dataset.payload);

                    const postData = {
                        ...rowData,
                        redirect_to: 'so.index',
                        compact: 1,
                        auto_expand: '1'
                    };

                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = "{{ route('dashboard.redirector') }}";

                    const csrf = document.createElement('input');
                    csrf.type = 'hidden';
                    csrf.name = '_token';
                    csrf.value = document.querySelector('meta[name="csrf-token"]').getAttribute(
                        'content');
                    form.appendChild(csrf);

                    const payload = document.createElement('input');
                    payload.type = 'hidden';
                    payload.name = 'payload';
                    payload.value = JSON.stringify(postData);
                    form.appendChild(payload);

                    document.body.appendChild(form);
                    form.submit();
                });

                loadList();
            })();

        })();
    </script>
@endpush
