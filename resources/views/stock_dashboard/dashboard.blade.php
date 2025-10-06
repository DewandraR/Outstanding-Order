@extends('layouts.app')

@section('title', 'Stock Dashboard & Inventory Analysis')

@push('styles')
    <style>
        .kpi-card {
            border: none;
            border-radius: 1rem;
            transition: .3s;
            background: #fff
        }

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, .1)
        }

        .kpi-icon {
            font-size: 1.75rem;
            padding: 1.25rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 70px;
            height: 70px
        }

        .yz-chart-card {
            border-radius: 1rem;
            padding: 1.5rem;
            height: 100%
        }

        .chart-box {
            position: relative;
            height: 480px;
        }
    </style>
@endpush

@section('content')

    <script>
        window.__STOCK_DASH__ = @json($dashboardData, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    </script>
    @php
        use Illuminate\Support\Facades\Crypt;

        // Define mapping for dropdown
        $locations = [
            '3000' => 'Semarang',
            '2000' => 'Surabaya',
        ];
    @endphp

    {{-- HEADER & FILTER --}}
    <div class="d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-center mb-4 gap-3">
        <div>
            <h1 class="mb-0 fw-bolder">Stock Dashboard</h1>
            <p class="text-muted mb-0">Live Inventory Analysis as of {{ date('d F Y') }}</p>
        </div>

        {{-- MODIFIED LOCATION DROPDOWNS --}}
        <div class="d-flex flex-wrap gap-2 justify-content-start justify-content-lg-end">
            @foreach ($locations as $werks => $name)
                <div class="dropdown">
                    <button class="btn btn-sm btn-primary dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown"
                        aria-expanded="false">
                        {{ $name }}
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            {{-- Link ke Stock Report WHFG (type=whfg) --}}
                            @php $whfgUrl = route('stock.index', ['q' => Crypt::encrypt(['werks' => $werks, 'type' => 'whfg'])]); @endphp
                            <a class="dropdown-item" href="{{ $whfgUrl }}">
                                <i class="fas fa-warehouse me-2 text-info"></i> WHFG
                            </a>
                        </li>
                        <li>
                            {{-- Link ke Stock Report Packing (FG) (type=fg) --}}
                            @php $packingUrl = route('stock.index', ['q' => Crypt::encrypt(['werks' => $werks, 'type' => 'fg'])]); @endphp
                            <a class="dropdown-item" href="{{ $packingUrl }}">
                                <i class="fas fa-cubes me-2 text-success"></i> Packing
                            </a>
                        </li>
                    </ul>
                </div>
            @endforeach
        </div>
        {{-- END MODIFIED LOCATION DROPDOWNS --}}

    </div>

    {{-- KPI CARDS --}}
    @php
        $kpi = $dashboardData['kpi'] ?? [];
        $fmtUsd = fn($v) => '$' . number_format((float) ($v ?? 0), 2, '.', ',');
        $fmtNum = fn($v) => number_format((float) ($v ?? 0), 0, ',', '.'); // untuk qty
    @endphp

    <div class="row g-4 mb-4">
        <div class="col-md-6 col-xl-3">
            <div class="card kpi-card h-100 shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="kpi-icon bg-primary-subtle text-primary"><i class="fas fa-coins"></i></div>
                    <div class="ms-3">
                        <div class="mb-1 text-muted yz-kpi-title" data-help-key="stock.kpi.inventory_whfg_value">
                            <span>Inventory&nbsp;WHFG</span>
                        </div>
                        <h5 class="mb-0 fw-bolder">{{ $fmtUsd($kpi['whfg_total_value_usd'] ?? null) }}</h5>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card kpi-card h-100 shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="kpi-icon bg-info-subtle text-info"><i class="fas fa-boxes-stacked"></i></div>
                    <div class="ms-3">
                        <div class="mb-1 text-muted yz-kpi-title" data-help-key="stock.kpi.whfg_stock_qty">
                            <span>WHFG&nbsp;Stock</span>
                        </div>
                        <h3 class="mb-0 fw-bolder">{{ $fmtNum($kpi['whfg_qty'] ?? 0) }}</h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card kpi-card h-100 shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="kpi-icon bg-warning-subtle text-warning"><i class="fas fa-sack-dollar"></i></div>
                    <div class="ms-3">
                        <div class="mb-1 text-muted yz-kpi-title" data-help-key="stock.kpi.inventory_fg_value">
                            <span>Inventory&nbsp;FG</span>
                        </div>
                        <h5 class="mb-0 fw-bolder">{{ $fmtUsd($kpi['fg_total_value_usd'] ?? null) }}</h5>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card kpi-card h-100 shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="kpi-icon bg-success-subtle text-success"><i class="fas fa-cubes"></i></div>
                    <div class="ms-3">
                        <div class="mb-1 text-muted yz-kpi-title" data-help-key="stock.kpi.packing_stock_qty">
                            <span>Packing&nbsp;Stock</span>
                        </div>
                        <h3 class="mb-0 fw-bolder">{{ $fmtNum($kpi['fg_qty'] ?? 0) }}</h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Most Stock Customers (ALL) --}}
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card shadow-sm yz-chart-card">
                <div class="card shadow-sm yz-chart-card">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title" data-help-key="stock.charts.most_stock_customers">
                            <i class="fas fa-crown me-2"></i>Most Stock Customers (ALL)
                        </h5>
                        <hr class="mt-2">
                        <div class="mb-4">
                            <h6 class="text-muted mb-2">WHFG Qty</h6>
                            <div class="chart-box">
                                <canvas id="chartTopWhfg"></canvas>
                            </div>
                        </div>
                        <div>
                            <h6 class="text-muted mb-2">Stock Packing Qty</h6>
                            <div class="chart-box">
                                <canvas id="chartTopFg"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endsection

    @push('scripts')
        <script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
        <script>
            (function() {
                const data = window.__STOCK_DASH__ || {};
                const fmt = v => new Intl.NumberFormat('id-ID').format(Number(v || 0));

                function setContainerHeight(canvas, rows) {
                    const perRow = 26;
                    const MIN = 360;
                    const MAX = 720;
                    const h = Math.min(MAX, Math.max(MIN, rows * perRow + 80));
                    canvas.parentElement.style.height = h + 'px';
                }

                // DIUBAH: Fungsi renderBar ditambahkan parameter 'breakdownKey'
                function renderBar(canvasId, label, items, valueKey, breakdownKey) {
                    const canvas = document.getElementById(canvasId);
                    if (!canvas) return;

                    const src = Array.isArray(items) ? items.filter(r => Number(r[valueKey] || 0) > 0) : [];
                    if (!src.length) {
                        canvas.parentElement.innerHTML =
                            '<div class="text-muted p-3"><i class="fas fa-info-circle me-2"></i>Data tidak tersedia.</div>';
                        return;
                    }

                    const labels = src.map(r => r.NAME1 || '');
                    const vals = src.map(r => Number(r[valueKey] || 0));

                    setContainerHeight(canvas, labels.length);

                    const fill = 'rgba(25, 135, 84, 0.35)';
                    const stroke = '#198754';

                    new Chart(canvas.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels,
                            datasets: [{
                                label,
                                data: vals,
                                borderColor: stroke,
                                backgroundColor: fill,
                                borderWidth: 1,
                                borderRadius: 4
                            }]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            animation: false,
                            plugins: {
                                legend: {
                                    display: false
                                },
                                // [DITAMBAHKAN] Logika Tooltip kustom
                                tooltip: {
                                    callbacks: {
                                        // Label utama: "WHFG Qty: 1.804"
                                        label: (context) => `${label}: ${fmt(context.parsed.x)}`,
                                        // Footer untuk rincian lokasi
                                        footer: (tooltipItems) => {
                                            const context = tooltipItems[0];
                                            const dataPoint = src[context.dataIndex];
                                            const breakdown = dataPoint.breakdown ? dataPoint.breakdown[
                                                breakdownKey] : null;

                                            if (!breakdown) return '';

                                            const parts = [];
                                            const smgQty = breakdown['3000'] || 0;
                                            const sbyQty = breakdown['2000'] || 0;

                                            if (smgQty > 0) parts.push(`SMG: ${fmt(smgQty)}`);
                                            if (sbyQty > 0) parts.push(`SBY: ${fmt(sbyQty)}`);

                                            return parts.join(' | ');
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: v => fmt(v)
                                    }
                                },
                                y: {
                                    ticks: {
                                        autoSkip: false,
                                        font: {
                                            size: 12
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

                const tc = (data.topCustomers || {});
                // DIUBAH: Panggilan fungsi renderBar ditambahkan parameter breakdownKey
                renderBar('chartTopWhfg', 'WHFG Qty', tc.whfg || [], 'whfg_qty', 'whfg');
                renderBar('chartTopFg', 'FG Qty', tc.fg || [], 'fg_qty', 'fg');
            })();
        </script>
    @endpush
