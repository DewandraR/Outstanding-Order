@extends('layouts.app')

@section('title','Dashboard')

@section('content')

{{-- Logika utama Blade untuk memilih tampilan --}}
@if($show)
{{-- =================================================================== --}}
{{-- BAGIAN 1: TAMPILAN LAPORAN DETAIL (JIKA WERKS & AUART DIPILIH)  --}}
{{-- =================================================================== --}}
<div id="yz-root"
    data-werks="{{ $selected['werks'] }}"
    data-auart="{{ $selected['auart'] }}"
    data-show="{{ (int)$show }}">
</div>

<div class="card yz-card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <div class="py-1">
            @if($selected['werks'] && $selected['auart'])
            <i class="fas fa-chart-bar me-2"></i>
            <strong>Result for Plant : {{ $selected['werks'] }}</strong> - <strong>SO Type : {{ $selected['auart'] }}</strong>
            <span class="text-muted small ms-2 d-none d-md-inline">({{ $selectedDescription }})</span>
            @else
            <i class="fas fa-info-circle me-2"></i> Silakan pilih WERKS dan AUART dari menu sidebar.
            @endif
        </div>
    </div>

    <div class="card-body p-0 p-md-2">
        <div class="p-3 mx-md-3 mt-md-3 yz-main-title-wrapper">
            <h5 class="yz-table-title mb-0">
                <i class="fas fa-users me-2"></i>Overview Customer
            </h5>
        </div>

        <div class="table-responsive yz-table px-md-3">
            @if($compact)
            <table class="table table-hover mb-0 align-middle yz-grid">
                <thead class="yz-header-customer">
                    <tr>
                        <th style="width:50px;"></th>
                        <th class="text-start" style="min-width:250px;">Customer</th>
                        <th style="min-width:120px; text-align:center;">Overdue SO</th>
                        <th style="min-width:150px; text-align:center;">Overdue Rate</th>
                        <th style="min-width:150px;">Value</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                    @php $kid = 'krow_'.$r->KUNNR.'_'.$loop->index; @endphp
                    <tr class="yz-kunnr-row" data-kunnr="{{ $r->KUNNR }}" data-kid="{{ $kid }}" title="Klik untuk melihat detail pesanan">
                        <td class="sticky-col-mobile-disabled">
                            <span class="kunnr-caret"><i class="fas fa-chevron-right"></i></span>
                        </td>
                        <td class="sticky-col-mobile-disabled text-start">
                            <span class="fw-bold">{{ $r->NAME1 }}</span>
                        </td>
                        <td class="text-center">
                            {{ $r->SO_LATE_COUNT }}
                        </td>
                        <td class="text-center">
                            @php
                            $pct = is_null($r->LATE_PCT) ? null : (float)$r->LATE_PCT;
                            @endphp
                            {{ is_null($pct) ? '—' : number_format($pct, 2, '.', '') . '%' }}
                        </td>
                        <td class="data-raw-totpr">
                            <span class="customer-totpr">
                                @php
                                if ($r->WAERK === 'IDR') { echo 'Rp ' . number_format($r->TOTPR, 2, ',', '.'); }
                                elseif ($r->WAERK === 'USD') { echo '$' . number_format($r->TOTPR, 2, '.', ','); }
                                else { echo ($r->WAERK ?? '') . ' ' . number_format($r->TOTPR, 2, ',', '.'); }
                                @endphp
                            </span>
                        </td>
                    </tr>
                    <tr id="{{ $kid }}" class="yz-nest" style="display:none;">
                        <td colspan="5" class="p-0">
                            <div class="yz-nest-wrap">
                                <div class="p-3 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
                                    <div class="spinner-border spinner-border-sm me-2" role="status"><span class="visually-hidden">Loading...</span></div>
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
            </table>
            @endif
        </div>
    </div>
</div>
@else
{{-- =================================================================== --}}
{{-- BAGIAN 2: TAMPILAN DASHBOARD UTAMA (JIKA TIDAK ADA FILTER LAPORAN) --}}
{{-- =================================================================== --}}

<div id="dashboard-data-holder"
    data-chart-data='{{ json_encode($chartData ?? null) }}'
    data-selected-type="{{ $selectedType ?? '' }}"
    style="display: none;">
</div>

<div class="d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-center mb-3 gap-3">
    <div>
        <h2 class="mb-0 fw-bolder">Dashboard Overview</h2>
        <p class="text-muted mb-0">
            Displaying Outstanding Value Data
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2 justify-content-start justify-content-lg-end">
        {{-- Filter Lokasi --}}
        <ul class="nav nav-pills shadow-sm p-1" style="border-radius: 0.75rem;">
            <li class="nav-item"><a class="nav-link {{ !$selectedLocation ? 'active' : '' }}" href="{{ route('dashboard', array_merge(request()->query(), ['location' => null])) }}">All Location</a></li>
            <li class="nav-item"><a class="nav-link {{ $selectedLocation == '3000' ? 'active' : '' }}" href="{{ route('dashboard', array_merge(request()->query(), ['location' => '3000'])) }}">Semarang</a></li>
            <li class="nav-item"><a class="nav-link {{ $selectedLocation == '2000' ? 'active' : '' }}" href="{{ route('dashboard', array_merge(request()->query(), ['location' => '2000'])) }}">Surabaya</a></li>
        </ul>
        {{-- Filter Tipe (Export/Lokal) --}}
        <ul class="nav nav-pills shadow-sm p-1" style="border-radius: 0.75rem;">
            <li class="nav-item"><a class="nav-link {{ !$selectedType ? 'active' : '' }}" href="{{ route('dashboard', array_merge(request()->query(), ['type' => null])) }}">All Type</a></li>
            <li class="nav-item"><a class="nav-link {{ $selectedType == 'export' ? 'active' : '' }}" href="{{ route('dashboard', array_merge(request()->query(), ['type' => 'export'])) }}">Export</a></li>
            <li class="nav-item"><a class="nav-link {{ $selectedType == 'lokal' ? 'active' : '' }}" href="{{ route('dashboard', array_merge(request()->query(), ['type' => 'lokal'])) }}">Lokal</a></li>
        </ul>
    </div>
</div>
<hr class="mt-0 mb-4">


{{-- BARIS 1: KPI CARDS --}}
<div class="row g-4 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="card yz-kpi-card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="yz-kpi-icon bg-primary-subtle text-primary">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="ms-3">
                    <p class="mb-1 text-muted">Total Outstanding (USD)</p>
                    <h4 class="mb-0 fw-bolder" id="kpi-out-usd">$0.00</h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card yz-kpi-card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="yz-kpi-icon bg-success-subtle text-success">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="ms-3">
                    <p class="mb-1 text-muted">Total Outstanding (IDR)</p>
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
                    <p class="mb-1 text-muted">Outstanding SO</p>
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
                    <p class="mb-1 text-muted">Overdue SO</p>
                    <h4 class="mb-0 fw-bolder"><span id="kpi-overdue-so">0</span> <small class="text-danger" id="kpi-overdue-rate">(0%)</small></h4>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- BARIS 2: CHART OVERVIEW --}}
<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card shadow-sm h-100 yz-chart-card">
            <div class="card-body d-flex flex-column">
                <h5 class="card-title"><i class="fas fa-chart-column me-2"></i>Outstanding Value by Location</h5>
                <hr class="mt-2">
                <div class="chart-container flex-grow-1">
                    <canvas id="chartOutstandingLocation"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm h-100 yz-chart-card">
            <div class="card-body d-flex flex-column">
                <h5 class="card-title"><i class="fas fa-chart-pie me-2"></i>Sales Order Status Overview</h5>
                <hr class="mt-2">
                <div class="chart-container flex-grow-1">
                    <canvas id="chartSOStatus"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- BARIS 3: CHART CUSTOMER INSIGHTS --}}
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100 yz-chart-card">
            <div class="card-body d-flex flex-column">
                <h5 class="card-title text-primary-emphasis"><i class="fas fa-crown me-2"></i>Top 4 Customer with the most Outstanding value</h5>
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
                <h5 class="card-title text-danger-emphasis"><i class="fas fa-triangle-exclamation me-2"></i>Top 4 Customers with Most Overdue SO</h5>
                <hr class="mt-2">
                <div class="chart-container flex-grow-1">
                    <canvas id="chartTopOverdueCustomers"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- BARIS 4: ANALISIS KINERJA TIPE SALES ORDER --}}
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card shadow-sm yz-chart-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h5 class="card-title mb-0">
                            <i class="fas fa-tasks me-2"></i>Outstanding SO & Performance Details by Type
                        </h5>
                    </div>
                    <div class="d-flex flex-wrap justify-content-end align-items-center" style="gap: 8px; flex-shrink: 0; margin-left: 1rem;">
                        <span class="legend-badge" style="background-color: #ffc107;">1-30</span>
                        <span class="legend-badge" style="background-color: #fd7e14;">31-60</span>
                        <span class="legend-badge" style="background-color: #dc3545;">61-90</span>
                        <span class="legend-badge" style="background-color: #8b0000;">>90</span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">SO Type</th>
                                <th scope="col" class="text-center">Total SO</th>
                                <th scope="col" class="text-end">Outs. Value (IDR)</th>
                                <th scope="col" class="text-end">Outs. Value (USD)</th>
                                <th scope="col" class="text-center">SO Overdue</th>
                                <th scope="col" style="min-width: 300px;" class="text-center">Overdue Distribution (Days)</th>
                            </tr>
                        </thead>
                        <tbody id="so-performance-tbody">
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- BARIS 5: DISTRIBUSI ITEM OUTSTANDING KUANTITAS KECIL --}}
<div class="row g-4">
    <div class="col-12">
        <div class="card shadow-sm yz-chart-card">
            <div class="card-body">
                <h5 class="card-title text-info-emphasis">
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
                    </h5>
                    <button type="button" class="btn-close" id="closeDetailsTable" aria-label="Close"></button>
                </div>
                <hr class="mt-2">
                <div id="smallQtyDetailsTable" class="mt-3">
                </div>
            </div>
        </div>
    </div>
</div>
@endif
@endsection

@push('styles')
<style>
    .legend-badge {
        padding: 0.2em 0.6em;
        font-size: 0.75rem;
        font-weight: 600;
        color: #fff;
        text-align: center;
        border-radius: 0.3rem;
    }

    .yz-grid {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 10px
    }

    .yz-card {
        border: none;
        border-radius: .75rem;
        overflow: hidden
    }

    .yz-card .card-header {
        background: #ecfdf5;
        color: #14532d;
        font-weight: 600;
        border-bottom: 1px solid #d1fae5
    }

    .yz-main-title-wrapper {
        background: #f0fdfa;
        border: 1px solid #ccfbf1;
        border-radius: 12px
    }

    .yz-table-title {
        color: #0f766e;
        font-weight: 600;
        font-size: 1.1rem
    }

    .yz-table-title-nested {
        font-size: 1rem;
        font-weight: 600;
        border-radius: 10px;
        padding: .5rem 1rem;
        margin-bottom: 1rem
    }

    .yz-title-so {
        color: #1e40af;
        background: #eff6ff;
        border: 1px solid #dbeafe
    }

    .yz-title-item {
        color: #fff;
        background: #38a3a5;
        border: 1px solid #2e8486
    }

    thead th {
        font-weight: 600;
        font-size: .8rem;
        text-transform: uppercase;
        letter-spacing: .05em;
        border-top: 0
    }

    .yz-header-customer th {
        background: #f0fdfa;
        color: #0f766e;
        border-bottom: 2px solid #a7f3d0
    }

    .yz-header-so th {
        background: #eff6ff;
        color: #1e40af;
        border-bottom: 2px solid #bfdbfe
    }

    .yz-header-item th {
        background: #38a3a5;
        color: #fff;
        border-bottom: 2px solid #2e8486
    }

    .yz-header-customer th,
    .yz-kunnr-row td {
        text-align: center;
        vertical-align: middle
    }

    .yz-kunnr-row td:nth-child(2) {
        text-align: left
    }

    .sticky-col {
        position: sticky;
        left: 0;
        background: inherit
    }

    .yz-kunnr-row td {
        background: #fff;
        border-top: 1px solid #e2e8f0;
        border-bottom: 1px solid #e2e8f0;
        transition: .2s background-color
    }

    .yz-kunnr-row td:first-child {
        border-left: 1px solid #e2e8f0;
        border-top-left-radius: 25px;
        border-bottom-left-radius: 25px
    }

    .yz-kunnr-row td:last-child {
        border-right: 1px solid #e2e8f0;
        border-top-right-radius: 25px;
        border-bottom-right-radius: 25px
    }

    .yz-kunnr-row:hover td {
        background: #f8f9fa
    }

    .yz-kunnr-row {
        cursor: pointer
    }

    .yz-kunnr-row.is-open td {
        background: #f8f9fa;
        border-color: #d1d5db
    }

    .yz-row-highlight-negative td {
        background: #fee2e2 !important
    }

    .yz-row-highlight-negative:hover td {
        background: #fecaca !important
    }

    .kunnr-caret {
        display: inline-block;
        width: 1rem;
        text-align: center;
        transition: .2s transform ease-in-out;
        color: #16a3a5;
        font-size: .8rem
    }

    .yz-kunnr-row.is-open .kunnr-caret {
        transform: rotate(90deg)
    }

    .yz-caret {
        display: inline-block;
        transition: transform .2s ease-in-out
    }

    .yz-caret.rot {
        transform: rotate(90deg)
    }

    .yz-nest-wrap {
        padding: .5rem;
        background: #f8f9fa;
        margin-left: 0
    }

    @media (min-width: 768px) {
        .yz-nest-wrap {
            padding: 1rem;
            margin-left: 60px
        }
    }

    .yz-nest[style=""] .yz-nest-wrap {
        animation: slideDown .4s ease-out
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-15px)
        }

        to {
            opacity: 1;
            transform: translateY(0)
        }
    }

    .yz-mini {
        border: none;
        border-collapse: separate;
        border-spacing: 0 8px
    }

    .yz-mini tbody tr td {
        background: #fff;
        border-top: 1px solid #eef2f6;
        border-bottom: 1px solid #eef2f6;
        transition: .2s background-color
    }

    .yz-mini tbody tr td:first-child {
        border-left: 1px solid #eef2f6;
        border-top-left-radius: 15px;
        border-bottom-left-radius: 15px
    }

    .yz-mini tbody tr td:last-child {
        border-right: 1px solid #eef2f6;
        border-top-right-radius: 15px;
        border-bottom-right-radius: 15px
    }

    .yz-mini .js-t2row {
        cursor: pointer
    }

    .yz-mini .js-t2row:hover td {
        background: #f8f9fa
    }

    .yz-t2-vbeln {
        font-weight: 600;
        color: #15803d
    }

    .yz-bstnk-cell {
        text-align: center
    }

    .yz-header-so+tbody td,
    .yz-header-so th {
        text-align: right
    }

    .yz-header-so+tbody td:nth-child(-n+3),
    .yz-header-so th:nth-child(-n+3) {
        text-align: left
    }

    .yz-header-item+tbody td,
    .yz-header-item th {
        text-align: center
    }

    .yz-loader-pulse {
        animation: pulse-opacity 1.5s ease-in-out infinite
    }

    @keyframes pulse-opacity {

        0%,
        to {
            opacity: 1
        }

        50% {
            opacity: .6
        }
    }

    .customer-focus-mode .yz-kunnr-row {
        display: none
    }

    .customer-focus-mode .yz-kunnr-row.is-focused {
        display: table-row
    }

    .so-focus-mode .js-t2row {
        display: none
    }

    .so-focus-mode .js-t2row.is-focused {
        display: table-row
    }

    .yz-chart-card {
        border: 1px solid #e9ecef;
        border-radius: .75rem;
        transition: all .3s ease-in-out
    }

    .yz-chart-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .1) !important
    }

    .yz-chart-card .card-title {
        font-weight: 600
    }

    .chart-container {
        position: relative;
        min-height: 350px;
        width: 100%
    }

    .yz-kpi-card {
        border: none;
        border-radius: .75rem;
        transition: all .2s ease-in-out
    }

    .yz-kpi-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .075) !important
    }

    .yz-kpi-icon {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem
    }

    .bar-chart-container {
        display: flex;
        height: 20px;
        width: 100%;
        min-width: 250px;
        background-color: #e9ecef;
        border-radius: 4px;
        overflow: hidden;
        font-size: 0.75rem;
        color: white;
    }

    .bar-segment {
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all .2s ease-in-out;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis
    }

    .bar-segment:hover {
        transform: scale(1.05);
        z-index: 10;
        box-shadow: 0 0 5px rgba(0, 0, 0, 0.3);
    }

    .row-highlighted td {
        background-color: #dbeafe !important;
        animation: pulse-highlight 1.5s ease-in-out 2;
        border-top: 1px solid #93c5fd !important;
        border-bottom: 1px solid #93c5fd !important;
    }

    @keyframes pulse-highlight {
        0% {
            background-color: #dbeafe;
        }

        50% {
            background-color: #93c5fd;
        }

        100% {
            background-color: #dbeafe;
        }
    }
</style>
@endpush

@push('scripts')
{{-- Memuat pustaka Chart.js & adapter tanggal dari CDN --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const customerRows = document.querySelectorAll('.yz-kunnr-row');
        customerRows.forEach(row => {
            row.querySelector('td:nth-child(2)').setAttribute('data-label', 'Customer');
            row.querySelector('td:nth-child(3)').setAttribute('data-label', 'Overdue SO');
            row.querySelector('td:nth-child(4)').setAttribute('data-label', 'Overdue Rate');
            row.querySelector('td:nth-child(5)').setAttribute('data-label', 'Value');
        });
    });

    const formatFullCurrency = (value, currency) => {
        const n = parseFloat(value);
        if (isNaN(n)) return '';
        if (currency === 'IDR') {
            return new Intl.NumberFormat('id-ID', {
                style: 'currency',
                currency: 'IDR',
                minimumFractionDigits: 0
            }).format(n);
        }
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD'
        }).format(n);
    };

    const showNoDataMessage = (canvasId) => {
        const canvas = document.getElementById(canvasId);
        if (canvas) {
            const container = canvas.parentElement;
            if (container) {
                container.innerHTML = `<div class="d-flex align-items-center justify-content-center h-100 p-3 text-muted" style="min-height: 300px;">
                                            <i class="fas fa-info-circle me-2"></i> Data tidak tersedia untuk filter ini.
                                        </div>`;
            }
        }
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

    // ===================================================================
    // SCRIPT UTAMA
    // ===================================================================
    (() => {
        const rootElement = document.getElementById('yz-root');
        const showTable = rootElement ? !!parseInt(rootElement.dataset.show) : false;

        // ===================================================================
        // BAGIAN 1: LOGIKA JIKA MODE TABEL INTERAKTIF AKTIF
        // ===================================================================
        if (showTable) {
            const apiT2 = "{{ route('dashboard.api.t2') }}";
            const apiT3 = "{{ route('dashboard.api.t3') }}";
            const qs = new URLSearchParams(window.location.search);
            let WERKS = (qs.get('werks') || '').trim() || null;
            let AUART = (qs.get('auart') || '').trim() || null;

            if (!WERKS || !AUART) {
                const root = document.getElementById('yz-root');
                if (root?.dataset) {
                    WERKS = WERKS || (root.dataset.werks || '').trim() || null;
                    AUART = AUART || (root.dataset.auart || '').trim() || null;
                }
            }

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
                if (!rows?.length) return `<div class="p-3 text-muted">Tidak ada data SO untuk KUNNR <b>${kunnr}</b>.</div>`;
                let html = `<div style="width:100%"><h5 class="yz-table-title-nested yz-title-so"><i class="fas fa-file-invoice me-2"></i>Overview SO</h5><table class="table table-sm mb-0 yz-mini"><thead class="yz-header-so"><tr><th style="width:40px;text-align:center;"></th><th style="min-width:100px;text-align:left;">SO</th><th style="min-width:150px;text-align:center;">PO</th><th style="min-width:100px;text-align:right;">Outs. Value</th><th style="min-width:100px;text-align:center;">Req. Delv Date</th><th style="min-width:100px;text-align:center;">Overdue (Days)</th><th style="min-width:120px;text-align:center;">Shortage %</th></tr></thead><tbody>`;
                rows.forEach((r, i) => {
                    const rid = `t3_${kunnr}_${r.VBELN}_${i}`;
                    const overdueDays = r.Overdue;
                    const rowHighlightClass = overdueDays < 0 ? 'yz-row-highlight-negative' : '';
                    const edatuDisplay = r.FormattedEdatu || '';
                    const shortageDisplay = `${(r.ShortagePercentage || 0).toFixed(2)}%`;
                    html += `<tr class="yz-row js-t2row ${rowHighlightClass}" data-vbeln="${r.VBELN}" data-tgt="${rid}"><td style="text-align:center;"><span class="yz-caret">▸</span></td><td class="yz-t2-vbeln">${r.VBELN}</td><td style="text-align:center;">${r.BSTNK ?? ''}</td><td style="text-align:right;">${formatCurrencyForTable(r.TOTPR, r.WAERK)}</td><td style="text-align:center;">${edatuDisplay}</td><td style="text-align:center;">${overdueDays ?? 0}</td><td style="text-align:center;">${shortageDisplay}</td></tr><tr id="${rid}" class="yz-nest" style="display:none;"><td colspan="7" class="p-0"><div class="yz-nest-wrap level-2" style="margin-left:0;padding:.5rem;"><div class="yz-slot-t3 p-2"></div></div></td></tr>`;
                });
                html += `</tbody></table></div>`;
                return html;
            }

            function renderT3(rows) {
                if (!rows?.length) return `<div class="p-2 text-muted">Tidak ada item detail.</div>`;
                let out = `<div class="table-responsive"><table class="table table-sm mb-0 yz-mini"><thead class="yz-header-item"><tr><th style="min-width:80px">Item</th><th style="min-width:150px">Material FG</th><th style="min-width:300px">Desc FG</th><th style="min-width:80px">Qty PO</th><th style="min-width:60px">Shipped</th><th style="min-width:60px">Outstanding</th><th style="min-width:80px">Outs. Value</th></tr></thead><tbody>`;
                rows.forEach(r => {
                    out += `<tr><td>${r.POSNR ?? ''}</td><td>${r.MATNR ?? ''}</td><td>${r.MAKTX ?? ''}</td><td>${parseFloat(r.KWMENG).toLocaleString('id-ID')}</td><td>${parseFloat(r.QTY_GI).toLocaleString('id-ID')}</td><td>${parseFloat(r.QTY_BALANCE2).toLocaleString('id-ID')}</td><td>${formatCurrencyForTable(r.TOTPR, r.WAERK)}</td></tr>`;
                });
                out += `</tbody></table></div>`;
                return out;
            }

            document.querySelectorAll('.yz-kunnr-row').forEach(row => {
                row.addEventListener('click', async () => {
                    const kunnr = (row.dataset.kunnr || '').trim();
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
                        const url = new URL(apiT2);
                        url.searchParams.set('kunnr', kunnr);
                        if (WERKS) url.searchParams.set('werks', WERKS);
                        if (AUART) url.searchParams.set('auart', AUART);
                        const res = await fetch(url);
                        if (!res.ok) throw new Error('Network response was not ok');
                        const js = await res.json();
                        if (!js.ok) throw new Error(js.error || 'Gagal memuat data SO');
                        wrap.innerHTML = renderT2(js.data, kunnr);
                        wrap.dataset.loaded = '1';
                        wrap.querySelectorAll('.js-t2row').forEach(row2 => {
                            row2.addEventListener('click', async (ev) => {
                                ev.stopPropagation();
                                const vbeln = (row2.dataset.vbeln || '').trim();
                                const tgtId = row2.dataset.tgt;
                                const caret = row2.querySelector('.yz-caret');
                                const tgt = wrap.querySelector('#' + tgtId);
                                const body = tgt.querySelector('.yz-slot-t3');
                                const open = tgt.style.display !== 'none';
                                const tbody2 = row2.closest('tbody');
                                if (!open) {
                                    tbody2.classList.add('so-focus-mode');
                                    row2.classList.add('is-focused');
                                } else {
                                    tbody2.classList.remove('so-focus-mode');
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
                                body.innerHTML = `<div class="p-2 text-muted small yz-loader-pulse">Memuat detail…</div>`;
                                const u3 = new URL(apiT3);
                                u3.searchParams.set('vbeln', vbeln);
                                if (WERKS) u3.searchParams.set('werks', WERKS);
                                if (AUART) u3.searchParams.set('auart', AUART);
                                const r3 = await fetch(u3);
                                if (!r3.ok) throw new Error('Network response was not ok for item details');
                                const j3 = await r3.json();
                                if (!j3.ok) throw new Error(j3.error || 'Gagal memuat detail item');
                                body.innerHTML = renderT3(j3.data);
                                tgt.dataset.loaded = '1';
                            });
                        });
                    } catch (e) {
                        console.error(e);
                        wrap.innerHTML = `<div class="alert alert-danger m-3">${e.message}</div>`;
                    }
                });
            });
        }

        // ===================================================================
        // BAGIAN 2: LOGIKA JIKA MODE DASHBOARD CHART AKTIF
        // ===================================================================
        document.addEventListener('DOMContentLoaded', () => {
            const dataHolder = document.getElementById('dashboard-data-holder');
            if (!dataHolder) return;

            const chartData = JSON.parse(dataHolder.dataset.chartData);
            const selectedType = dataHolder.dataset.selectedType;

            if (!chartData || !chartData.kpi) return;

            document.getElementById('kpi-out-usd').textContent = formatFullCurrency(chartData.kpi.total_outstanding_value_usd, 'USD');
            document.getElementById('kpi-out-idr').textContent = formatFullCurrency(chartData.kpi.total_outstanding_value_idr, 'IDR');
            document.getElementById('kpi-out-so').textContent = chartData.kpi.total_outstanding_so;
            document.getElementById('kpi-overdue-so').textContent = chartData.kpi.total_overdue_so;
            document.getElementById('kpi-overdue-rate').textContent = `(${(chartData.kpi.overdue_rate || 0).toFixed(1)}%)`;

            Chart.defaults.font.family = 'Inter, sans-serif';
            Chart.defaults.plugins.legend.position = 'bottom';
            Chart.defaults.responsive = true;
            Chart.defaults.maintainAspectRatio = false;

            const currencyToDisplay = selectedType === 'lokal' ? 'IDR' : 'USD';

            const ctxLocation = document.getElementById('chartOutstandingLocation');
            if (ctxLocation) {
                const locationData = chartData.outstanding_by_location;
                const locationChartData = locationData.filter(d => d.currency === currencyToDisplay);
                if (locationChartData.length === 0) {
                    showNoDataMessage('chartOutstandingLocation');
                } else {
                    const semarang_val = locationChartData.find(d => d.location === 'Semarang')?.total_value || 0;
                    const surabaya_val = locationChartData.find(d => d.location === 'Surabaya')?.total_value || 0;
                    new Chart(ctxLocation, {
                        type: 'bar',
                        data: {
                            labels: ['Semarang', 'Surabaya'],
                            datasets: [{
                                label: `Outstanding (${currencyToDisplay})`,
                                data: [semarang_val, surabaya_val],
                                backgroundColor: currencyToDisplay === 'IDR' ? 'rgba(25, 135, 84, 0.6)' : 'rgba(54, 162, 235, 0.6)',
                                borderColor: currencyToDisplay === 'IDR' ? 'rgba(25, 135, 84, 1)' : 'rgba(54, 162, 235, 1)',
                                borderWidth: 1,
                                borderRadius: 5
                            }]
                        },
                        options: {
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return new Intl.NumberFormat('id-ID').format(value);
                                        }
                                    }
                                }
                            },
                            plugins: {
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const dataPoint = locationChartData[context.dataIndex];
                                            const value = formatFullCurrency(context.raw, currencyToDisplay);
                                            const count = dataPoint ? dataPoint.so_count : '';
                                            return `${value} (${count} SO)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            }

            const ctxStatus = document.getElementById('chartSOStatus');
            if (ctxStatus) {
                const statusData = chartData.so_status;
                if (statusData && (statusData.overdue + statusData.due_this_week + statusData.on_time === 0)) {
                    showNoDataMessage('chartSOStatus');
                } else if (statusData) {
                    new Chart(ctxStatus, {
                        type: 'doughnut',
                        data: {
                            labels: ['Overdue', 'Due This Week', 'On Time'],
                            datasets: [{
                                data: [statusData.overdue, statusData.due_this_week, statusData.on_time],
                                backgroundColor: ['rgba(255, 99, 132, 0.7)', 'rgba(255, 206, 86, 0.7)', 'rgba(75, 192, 192, 0.7)'],
                                borderColor: ['#fff'],
                                borderWidth: 2,
                            }]
                        },
                        options: {
                            cutout: '60%'
                        }
                    });
                }
            }

            const createHorizontalBarChart = (canvasId, chartData, dataKey, label, color, currency = '') => {
                if (!chartData || chartData.length === 0) {
                    showNoDataMessage(canvasId);
                    return;
                }
                const ctx = document.getElementById(canvasId);
                if (!ctx) return;
                const labels = chartData.map(d => {
                    const customerName = d.NAME1.length > 25 ? d.NAME1.substring(0, 25) + '...' : d.NAME1;
                    if (d.locations) {
                        const locationSubtitle = formatLocations(d.locations);
                        return [customerName, locationSubtitle];
                    }
                    return customerName;
                });
                const values = chartData.map(d => d[dataKey]);
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: label,
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
                                    title: function(tooltipItems) {
                                        return tooltipItems[0].label.split(',')[0];
                                    },
                                    label: function(context) {
                                        const dataPoint = chartData[context.dataIndex];
                                        if (currency && dataPoint) {
                                            const value = formatFullCurrency(context.raw, currency);
                                            const count = dataPoint.so_count;
                                            return `${value} (${count} SO)`;
                                        }
                                        if (canvasId === 'chartTopOverdueCustomers' && dataPoint) {
                                            const total = dataPoint.overdue_count;
                                            const smg = dataPoint.smg_count;
                                            const sby = dataPoint.sby_count;

                                            let details = [];
                                            if (smg > 0) {
                                                details.push(`SMG: ${smg}`);
                                            }
                                            if (sby > 0) {
                                                details.push(`SBY: ${sby}`);
                                            }
                                            if (details.length > 0) {
                                                return `${total} SO (${details.join(', ')})`;
                                            }
                                            return `${total} SO`;
                                        }
                                        return `${context.raw} SO`;
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        if (Math.floor(value) === value) {
                                            if (currency) {
                                                return formatFullCurrency(value, currency).replace(/\,00$/, '');
                                            }
                                            return value;
                                        }
                                    }
                                }
                            }
                        }
                    }
                });
            };

            const topValueTitle = document.querySelector('#chartTopCustomersValue').closest('.card').querySelector('.card-title');
            if (selectedType === 'lokal') {
                if (topValueTitle) topValueTitle.innerHTML = `<i class="fas fa-crown me-2"></i>Top 5 Customers by Outstanding Value (IDR)`;
                createHorizontalBarChart('chartTopCustomersValue', chartData.top_customers_value_idr, 'total_value', 'Total Outstanding', {
                    bg: 'rgba(25, 135, 84, 0.6)',
                    border: 'rgba(25, 135, 84, 1)'
                }, 'IDR');
            } else {
                if (topValueTitle) topValueTitle.innerHTML = `<i class="fas fa-crown me-2"></i>Top 4 Customers by Outstanding Value (USD)`;
                createHorizontalBarChart('chartTopCustomersValue', chartData.top_customers_value_usd, 'total_value', 'Total Outstanding', {
                    bg: 'rgba(13, 110, 253, 0.6)',
                    border: 'rgba(13, 110, 253, 1)'
                }, 'USD');
            }

            createHorizontalBarChart('chartTopOverdueCustomers', chartData.top_customers_overdue, 'overdue_count', 'Jumlah SO Terlambat', {
                bg: 'rgba(220, 53, 69, 0.6)',
                border: 'rgba(220, 53, 69, 1)'
            });

            const performanceData = chartData.so_performance_analysis;
            const performanceTbody = document.getElementById('so-performance-tbody');
            if (performanceTbody) {
                if (!performanceData || performanceData.length === 0) {
                    performanceTbody.innerHTML = `<tr><td colspan="6" class="text-center p-5 text-muted"><i class="fas fa-info-circle fa-2x mb-2"></i><br>Performance data is not available for this filter.</td></tr>`;
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
                        const totalOverdueForBar = overdueSo;
                        const pct1_30 = totalOverdueForBar > 0 ? (item.overdue_1_30 / totalOverdueForBar * 100).toFixed(2) : 0;
                        const pct31_60 = totalOverdueForBar > 0 ? (item.overdue_31_60 / totalOverdueForBar * 100).toFixed(2) : 0;
                        const pct61_90 = totalOverdueForBar > 0 ? (item.overdue_61_90 / totalOverdueForBar * 100).toFixed(2) : 0;
                        const pctOver90 = totalOverdueForBar > 0 ? (item.overdue_over_90 / totalOverdueForBar * 100).toFixed(2) : 0;
                        let barChartHtml = '<div class="bar-chart-container">';
                        if (item.overdue_1_30 > 0) {
                            barChartHtml += `<div class="bar-segment" style="width: ${pct1_30}%; background-color: #ffc107;" data-bs-toggle="tooltip" title="1-30 Days: ${item.overdue_1_30} SO">${item.overdue_1_30}</div>`;
                        }
                        if (item.overdue_31_60 > 0) {
                            barChartHtml += `<div class="bar-segment" style="width: ${pct31_60}%; background-color: #fd7e14;" data-bs-toggle="tooltip" title="31-60 Days: ${item.overdue_31_60} SO">${item.overdue_31_60}</div>`;
                        }
                        if (item.overdue_61_90 > 0) {
                            barChartHtml += `<div class="bar-segment" style="width: ${pct61_90}%; background-color: #dc3545;" data-bs-toggle="tooltip" title="61-90 Days: ${item.overdue_61_90} SO">${item.overdue_61_90}</div>`;
                        }
                        if (item.overdue_over_90 > 0) {
                            barChartHtml += `<div class="bar-segment" style="width: ${pctOver90}%; background-color: #8b0000;" data-bs-toggle="tooltip" title=">90 Days: ${item.overdue_over_90} SO">${item.overdue_over_90}</div>`;
                        }
                        barChartHtml += '</div>';
                        tableHtml += `<tr><td><div class="fw-bold">${item.Deskription}</div></td><td class="text-center">${totalSo}</td><td class="${classIdr}">${valueIdr}</td><td class="${classUsd}">${valueUsd}</td><td class="text-center"><span class="fw-bold ${overdueSo > 0 ? 'text-danger' : ''}">${overdueSo}</span><small class="text-muted d-block">(${overdueRate}%)</small></td><td>${totalOverdueForBar > 0 ? barChartHtml : '<span class="text-muted small">Tidak ada SO terlambat</span>'}</td></tr>`;
                    });
                    performanceTbody.innerHTML = tableHtml;
                    new bootstrap.Tooltip(document.body, {
                        selector: "[data-bs-toggle='tooltip']"
                    });
                }
            }

            // =================================================================================================
            // BLOK CHART INTERAKTIF UNTUK KUANTITAS KECIL
            // =================================================================================================
            const ctxSmallQty = document.getElementById('chartSmallQtyByCustomer');
            const smallQtyDataRaw = chartData.small_qty_by_customer || [];
            if (ctxSmallQty) {
                if (smallQtyDataRaw.length === 0) {
                    showNoDataMessage('chartSmallQtyByCustomer');
                } else {
                    const customerMap = new Map();
                    smallQtyDataRaw.forEach(item => {
                        if (!customerMap.has(item.NAME1)) {
                            customerMap.set(item.NAME1, {
                                '3000': 0,
                                '2000': 0
                            });
                        }
                        customerMap.get(item.NAME1)[item.IV_WERKS_PARAM] = parseInt(item.item_count, 10);
                    });
                    const sortedCustomers = [...customerMap.entries()].sort((a, b) => (a[1]['3000'] + a[1]['2000']) - (b[1]['3000'] + b[1]['2000'])).reverse();
                    const labels = sortedCustomers.map(item => item[0]);
                    const semarangData = sortedCustomers.map(item => item[1]['3000']);
                    const surabayaData = sortedCustomers.map(item => item[1]['2000']);
                    const detailsContainer = document.getElementById('smallQtyDetailsContainer');
                    const detailsTitle = document.getElementById('smallQtyDetailsTitle');
                    const detailsTable = document.getElementById('smallQtyDetailsTable');
                    const closeButton = document.getElementById('closeDetailsTable');
                    closeButton.addEventListener('click', () => detailsContainer.style.display = 'none');

                    new Chart(ctxSmallQty, {
                        type: 'bar',
                        data: {
                            labels: labels,
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
                            scales: {
                                x: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Item (With Qty Outstanding ≤ 5)'
                                    }
                                },
                                y: {}
                            },
                            maintainAspectRatio: false,
                            plugins: {
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.dataset.label || '';
                                            if (label) {
                                                label += ': ';
                                            }
                                            if (context.parsed.x !== null) {
                                                label += context.parsed.x + ' SO';
                                            }
                                            return label;
                                        }
                                    }
                                }
                            },
                            onClick: async (event, elements) => {
                                if (elements.length === 0) return;
                                const barElement = elements[0];
                                const customerName = labels[barElement.index];
                                const locationName = event.chart.data.datasets[barElement.datasetIndex].label;
                                detailsTitle.textContent = `Detail Item untuk ${customerName} - (${locationName})`;
                                detailsTable.innerHTML = `<div class="d-flex justify-content-center align-items-center p-5"><div class="spinner-border text-primary" role="status"></div><span class="ms-3 text-muted">Memuat data...</span></div>`;
                                detailsContainer.style.display = 'block';
                                detailsContainer.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start'
                                });
                                const currentParams = new URLSearchParams(window.location.search);
                                const type = currentParams.get('type');
                                const apiUrl = new URL("{{ route('dashboard.api.smallQtyDetails') }}", window.location.origin);
                                apiUrl.searchParams.append('customerName', customerName);
                                apiUrl.searchParams.append('locationName', locationName);
                                if (type) apiUrl.searchParams.append('type', type);
                                try {
                                    const response = await fetch(apiUrl);
                                    const result = await response.json();
                                    if (result.ok && result.data.length > 0) {
                                        result.data.sort((a, b) => parseFloat(a.QTY_BALANCE2) - parseFloat(b.QTY_BALANCE2));
                                        let tableHtml = `<div class="table-responsive"><table class="table table-striped table-hover table-sm align-middle">
                                                            <thead class="table-light">
                                                                <tr>
                                                                    <th style="width: 5%;" class="text-center">No.</th>
                                                                    <th class="text-center">PO</th>
                                                                    <th class="text-center">SO</th>
                                                                    <th class="text-center">Item</th>
                                                                    <th>Desc FG</th>
                                                                    <th class="text-center">Qty PO</th>
                                                                    <th class="text-center">Outstanding</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>`;
                                        result.data.forEach((item, index) => {
                                            tableHtml += `<tr>
                                                            <td class="text-center">${index + 1}</td>
                                                            <td class="text-center">${item.BSTNK || '-'}</td>
                                                            <td class="text-center">${item.VBELN}</td>
                                                            <td class="text-center">${item.POSNR}</td>
                                                            <td>${item.MAKTX}</td>
                                                            <td class="text-center">${parseFloat(item.KWMENG)}</td>
                                                            <td class="text-center fw-bold text-danger">${parseFloat(item.QTY_BALANCE2)}</td>
                                                        </tr>`;
                                        });
                                        tableHtml += `</tbody></table></div>`;
                                        detailsTable.innerHTML = tableHtml;
                                    } else {
                                        detailsTable.innerHTML = `<div class="text-center p-5 text-muted">Data item tidak ditemukan.</div>`;
                                    }
                                } catch (error) {
                                    console.error('Gagal mengambil data detail:', error);
                                    detailsTable.innerHTML = `<div class="text-center p-5 text-danger">Terjadi kesalahan saat memuat data.</div>`;
                                }
                            }
                        }
                    });
                }
            }
        });

        document.addEventListener('DOMContentLoaded', () => {
            const params = new URLSearchParams(window.location.search);
            const highlightKunnr = params.get('highlight_kunnr');
            const highlightVbeln = params.get('highlight_vbeln');
            const searchTerm = params.get('search_term');

            // 1. Pertahankan nilai di search bar
            const searchInput = document.querySelector('form.sidebar-search-form input[name="term"]');
            if (searchTerm && searchInput) {
                searchInput.value = searchTerm;
            }

            if (highlightKunnr && highlightVbeln) {
                const customerRow = document.querySelector(`.yz-kunnr-row[data-kunnr="${highlightKunnr}"]`);

                if (customerRow) {
                    customerRow.click();

                    const detailSlot = document.getElementById(customerRow.dataset.kid);
                    const observer = new MutationObserver((mutationsList, observer) => {
                        for (const mutation of mutationsList) {
                            if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                                const soRow = detailSlot.querySelector(`.js-t2row[data-vbeln="${highlightVbeln}"]`);

                                if (soRow) {
                                    soRow.classList.add('row-highlighted');
                                    soRow.scrollIntoView({
                                        behavior: 'smooth',
                                        block: 'center'
                                    });

                                    // 3. Tambahkan event listener untuk menghapus highlight saat diklik
                                    soRow.addEventListener('click', function() {
                                        this.classList.remove('row-highlighted');
                                    }, {
                                        once: true
                                    });
                                    observer.disconnect();
                                }
                            }
                        }
                    });

                    observer.observe(detailSlot, {
                        childList: true,
                        subtree: true
                    });
                }
            }
        });
    })();
</script>
@endpush