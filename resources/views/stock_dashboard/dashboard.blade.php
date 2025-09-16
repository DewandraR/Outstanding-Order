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

{{-- HEADER & FILTER --}}
<div class="d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-center mb-4 gap-3">
    <div>
        <h1 class="mb-0 fw-bolder">Stock Dashboard</h1>
        <p class="text-muted mb-0">Live Inventory Analysis as of {{ date('d F Y') }}</p>
    </div>
    <div class="d-flex flex-wrap gap-2 justify-content-start justify-content-lg-end">
        <ul class="nav nav-pills shadow-sm p-1" style="border-radius:.75rem;">
            <li class="nav-item"><a class="nav-link {{ !$selectedLocation ? 'active' : '' }}" href="{{ route('stock.dashboard') }}">All Location</a></li>
            <li class="nav-item"><a class="nav-link {{ $selectedLocation == '3000' ? 'active' : '' }}" href="{{ route('stock.dashboard', ['location' => '3000']) }}">Semarang</a></li>
            <li class="nav-item"><a class="nav-link {{ $selectedLocation == '2000' ? 'active' : '' }}" href="{{ route('stock.dashboard', ['location' => '2000']) }}">Surabaya</a></li>
        </ul>
    </div>
</div>

{{-- KPI CARDS --}}
@php
$kpi = $dashboardData['kpi'] ?? [];
$fmtUsd = fn($v) => '$'.number_format((float)($v ?? 0), 2, '.', ',');
$fmtInt = fn($v) => number_format((int)($v ?? 0), 0, ',', '.');
@endphp

<div class="row g-4 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="card kpi-card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="kpi-icon bg-primary-subtle text-primary"><i class="fas fa-coins"></i></div>
                <div class="ms-3">
                    <p class="mb-1 text-muted">Inventory Value (WHFG)</p>
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
                    <p class="mb-1 text-muted">WHFG Items (Stock &gt; 0)</p>
                    <h3 class="mb-0 fw-bolder">{{ $fmtInt($kpi['whfg_count'] ?? null) }}</h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card kpi-card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="kpi-icon bg-warning-subtle text-warning"><i class="fas fa-sack-dollar"></i></div>
                <div class="ms-3">
                    <p class="mb-1 text-muted">Inventory Value (FG)</p>
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
                    <p class="mb-1 text-muted">FG Items (Packing &gt; 0)</p>
                    <h3 class="mb-0 fw-bolder">{{ $fmtInt($kpi['fg_count'] ?? null) }}</h3>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Most Stock Customers (ALL) --}}
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card shadow-sm yz-chart-card">
            <div class="card-body d-flex flex-column">
                <h5 class="card-title"><i class="fas fa-crown me-2"></i>Most Stock Customers (ALL)</h5>
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
                                    const breakdown = dataPoint.breakdown ? dataPoint.breakdown[breakdownKey] : null;

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