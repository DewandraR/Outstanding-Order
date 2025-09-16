@extends('layouts.app')

@section('title','Dashboard')

@section('content')

@php
// Ambil nilai dari controller / query
$werks = $selected['werks'] ?? request('werks');
$auart = $selected['auart'] ?? request('auart');
$show = filled($werks) && filled($auart); // Tampilkan TABLE kalau WERKS & AUART terpilih
$onlyWerksSelected = filled($werks) && empty($auart); // Hanya plant dipilih (belum pilih AUART)
$locationMap = ['2000' => 'Surabaya', '3000' => 'Semarang'];
$locName = $locationMap[$werks] ?? $werks;
@endphp

{{-- Anchor untuk JS agar tahu sedang mode TABLE atau bukan --}}
<div id="yz-root"
     data-show="{{ $show ? 1 : 0 }}"
     data-werks="{{ $werks ?? '' }}"
     data-auart="{{ $auart ?? '' }}"
     style="display:none"></div>

{{-- =========================================================
     HEADER: PILIH TYPE (SELALU tampil jika plant dipilih)
     ========================================================= --}}
@if(filled($werks))
@php
$typesForPlant = collect($mapping[$werks] ?? []);
@endphp
<div class="card yz-card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <div class="py-1 w-100">
            @if($typesForPlant->count())
            <ul class="nav nav-pills yz-auart-pills p-1 flex-wrap" style="border-radius:.75rem;">
                @foreach($typesForPlant as $t)
                <li class="nav-item mb-2 me-2">
                    <a class="nav-link pill-green {{ ($auart ?? null) == $t->IV_AUART ? 'active' : '' }}"
                       href="{{ route('dashboard', ['werks' => $werks, 'auart' => $t->IV_AUART, 'compact' => 1]) }}">
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
     A. MODE TABEL (LAPORAN PO) – muncul kalau WERKS & AUART terpilih
     ========================================================= --}}
@if($show && $compact)
<div class="card yz-card shadow-sm mb-3">
    <div class="card-body p-0 p-md-2">
        <div class="p-3 mx-md-3 mt-md-3 yz-main-title-wrapper">
            <h5 class="yz-table-title mb-0">
                <i class="fas fa-users me-2"></i>Overview Customer
            </h5>
        </div>

        <div class="table-responsive yz-table px-md-3">
            <table class="table table-hover mb-0 align-middle yz-grid">
                <thead class="yz-header-customer">
                    <tr>
                        <th style="width:50px;"></th>
                        <th class="text-start" style="min-width:250px;">Customer</th>
                        <th style="min-width:120px; text-align:center;">Overdue PO</th>
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
                        <td class="text-center">{{ $r->SO_LATE_COUNT }}</td>
                        <td class="text-center">
                            {{ is_null($r->LATE_PCT) ? '—' : number_format((float)$r->LATE_PCT, 2, '.', '') . '%' }}
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
        </div>
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
     C. MODE DASHBOARD (grafik PO / SO)
     ========================================================= --}}
@else
<div id="dashboard-data-holder"
     data-chart-data='{{ json_encode($chartData ?? null) }}'
     data-selected-type="{{ $selectedType ?? '' }}"
     style="display: none;">
</div>

<div class="d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-center mb-3 gap-3">
    <div>
        @if ($view === 'so')
        <h2 class="mb-0 fw-bolder">Outstanding SO Overview</h2>
        <p class="text-muted mb-0">Monitoring Sales Orders Ready for Packing</p>
        @else
        <h2 class="mb-0 fw-bolder">Dashboard Overview PO</h2>
        <p class="text-muted mb-0">Displaying Outstanding Value Data</p>
        @endif
    </div>
    <div class="d-flex flex-wrap gap-2 justify-content-start justify-content-lg-end">
        {{-- [DIUBAH] Filter Plant (WERKS) --}}
        <ul class="nav nav-pills shadow-sm p-1" style="border-radius: 0.75rem;">
            <li class="nav-item"><a class="nav-link {{ !$selectedLocation ? 'active' : '' }}" href="{{ route('dashboard', array_merge(request()->query(), ['location' => null, 'view' => 'so'])) }}">All Plant</a></li>
            <li class="nav-item"><a class="nav-link {{ $selectedLocation == '3000' ? 'active' : '' }}" href="{{ route('dashboard', array_merge(request()->query(), ['location' => '3000', 'view' => 'so'])) }}">Semarang</a></li>
            <li class="nav-item"><a class="nav-link {{ $selectedLocation == '2000' ? 'active' : '' }}" href="{{ route('dashboard', array_merge(request()->query(), ['location' => '2000', 'view' => 'so'])) }}">Surabaya</a></li>
        </ul>

        {{-- [BARU] Filter Work Center (AUART) --}}
        @if (!empty($availableAuart) && $availableAuart->count() > 1)
        <ul class="nav nav-pills shadow-sm p-1" style="border-radius: 0.75rem;">
            <li class="nav-item"><a class="nav-link {{ !request('auart') ? 'active' : '' }}" href="{{ route('dashboard', array_merge(request()->query(), ['auart' => null, 'view' => 'so'])) }}">All Work Center</a></li>
            @foreach($availableAuart as $wc)
            <li class="nav-item"><a class="nav-link {{ request('auart') == $wc->IV_AUART ? 'active' : '' }}" href="{{ route('dashboard', array_merge(request()->query(), ['auart' => $wc->IV_AUART, 'view' => 'so'])) }}">{{ $wc->Deskription }}</a></li>
            @endforeach
        </ul>
        @endif

        {{-- Filter Tipe (Export/Lokal) --}}
        <ul class="nav nav-pills shadow-sm p-1" style="border-radius: 0.75rem;">
            <li class="nav-item"><a class="nav-link {{ !$selectedType ? 'active' : '' }}" href="{{ route('dashboard', array_merge(request()->query(), ['type' => null, 'view' => 'so'])) }}">All Type</a></li>
            <li class="nav-item"><a class="nav-link {{ $selectedType == 'export' ? 'active' : '' }}" href="{{ route('dashboard', array_merge(request()->query(), ['type' => 'export', 'view' => 'so'])) }}">Export</a></li>
            <li class="nav-item"><a class="nav-link {{ $selectedType == 'lokal' ? 'active' : '' }}" href="{{ route('dashboard', array_merge(request()->query(), ['type' => 'lokal', 'view' => 'so'])) }}">Lokal</a></li>
        </ul>
    </div>
</div>
<hr class="mt-0 mb-4">

@if ($view === 'so' && !empty($chartData))
{{-- ==================== DASHBOARD SO ==================== --}}
<div class="row g-4 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card yz-kpi-card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="yz-kpi-icon bg-primary-subtle text-primary"><i class="fas fa-dollar-sign"></i></div>
                <div class="ms-3">
                    <p class="mb-1 text-muted">Outs Value Packing (USD)</p>
                    <h4 class="mb-0 fw-bolder" id="kpi-so-val-usd">$0.00</h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card yz-kpi-card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="yz-kpi-icon bg-success-subtle text-success"><i class="fas fa-money-bill-wave"></i></div>
                <div class="ms-3">
                    <p class="mb-1 text-muted">Outs Value Packing (IDR)</p>
                    <h4 class="mb-0 fw-bolder" id="kpi-so-val-idr">Rp 0</h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        {{-- [DIUBAH] Menambahkan ID dan style cursor --}}
        <div id="toggle-due-tables-card" class="card yz-kpi-card card-highlight-info h-100 shadow-sm" style="cursor: pointer;" title="Klik untuk menampilkan/menyembunyikan detail SO Due This Week">
            <div class="card-body d-flex align-items-center">
                <div class="yz-kpi-icon bg-info-subtle text-info"><i class="fas fa-shipping-fast"></i></div>
                <div class="ms-3">
                    <p class="mb-1 text-muted">Value to Packing This Week</p>
                    <h5 class="mb-0 fw-bolder" id="kpi-so-ship-week-usd">$0.00</h5>
                    <h5 class="mb-0 fw-bolder" id="kpi-so-ship-week-idr">Rp 0</h5>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card yz-kpi-card card-highlight-warning h-100 shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="yz-kpi-icon bg-warning-subtle text-warning"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="ms-3">
                    <p class="mb-1 text-muted">Potential Bottlenecks</p>
                    <h4 class="mb-0 fw-bolder"><span id="kpi-so-bottleneck">0</span> <small>Items</small></h4>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- [DIUBAH] Membungkus tabel agar bisa disembunyikan/ditampilkan --}}
<div id="due-this-week-tables" style="display: none;">
    @if(!empty($chartData['due_this_week']))
@php
    $rangeStart = \Carbon\Carbon::parse($chartData['due_this_week']['start']);
    $rangeEndEx = \Carbon\Carbon::parse($chartData['due_this_week']['end_excl']);
    $rangeEnd   = $rangeEndEx->copy()->subDay(); // tampil s.d. Minggu
    $dueSoRows  = $chartData['due_this_week']['by_so'] ?? [];
    $dueCustRows= $chartData['due_this_week']['by_customer'] ?? [];

    // -- PERSIAPAN DATA HELPER (DIPINDAHKAN KE SINI) --
    $plantNames = ['2000' => 'SBY', '3000' => 'SMG'];
    $auartDescriptions = collect($mapping)->flatten()->keyBy('IV_AUART');
@endphp
<div class="row g-4 mb-4">
    {{-- KIRI: daftar SO jatuh tempo minggu ini (DIMODIFIKASI) --}}
    <div class="col-lg-7">
        <div class="card shadow-sm h-100 yz-chart-card">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="fas fa-truck-fast me-2"></i>SO Due This Week
                    <span class="text-muted small">
                        ({{ $rangeStart->format('d M') }} – {{ $rangeEnd->format('d M Y') }})
                    </span>
                </h5>
                <hr class="mt-2">
                @if(empty($dueSoRows))
                    <div class="text-muted p-4 text-center">
                        <i class="fas fa-info-circle me-2"></i>Tidak ada SO jatuh tempo minggu ini.
                    </div>
                @else
                <div class="table-responsive">
                    {{-- Tambahkan class yz-table-centered-cols untuk styling terpusat jika perlu --}}
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                {{-- Tambahkan class text-center pada header --}}
                                <th class="text-center">SO</th>
                                <th class="text-center">PO</th>
                                <th>Customer</th>
                                <th class="text-center">Plant</th>
                                <th class="text-center">Work Center</th>
                                <th class="text-center">Due</th>
                                <th class="text-end">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($dueSoRows as $r)
                            <tr>
                                {{-- Tambahkan class text-center pada sel data --}}
                                <td class="text-center">{{ $r->VBELN }}</td>
                                <td class="text-center">{{ $r->BSTNK }}</td>
                                <td>{{ $r->NAME1 }}</td>
                                <td class="text-center">{{ $plantNames[$r->IV_WERKS_PARAM] ?? $r->IV_WERKS_PARAM }}</td>
                                <td class="text-center">{{ $auartDescriptions[$r->IV_AUART_PARAM]->Deskription ?? $r->IV_AUART_PARAM }}</td>
                                <td class="text-center">{{ \Carbon\Carbon::parse($r->due_date)->format('d-m-Y') }}</td>
                                <td class="text-end">
                                    @if($r->WAERK === 'USD')
                                        ${{ number_format((float)$r->total_value, 2, '.', ',') }}
                                    @else
                                        Rp {{ number_format((float)$r->total_value, 2, ',', '.') }}
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
        <div class="col-lg-4">
            <div class="card shadow-sm h-100 yz-chart-card">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-user-clock me-2"></i>Customers Due This Week</h5>
                    <hr class="mt-2">
                    @if(empty($dueCustRows))
                        <div class="text-muted p-4 text-center">
                            <i class="fas fa-info-circle me-2"></i>Tidak ada customer jatuh tempo minggu ini.
                        </div>
                    @else
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Customer</th>
                                    <th class="text-end">Total Value</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($dueCustRows as $r)
                                <tr>
                                    <td>{{ $r->NAME1 }}</td>
                                    <td class="text-end">
                                        @if($r->WAERK === 'USD')
                                            ${{ number_format((float)$r->total_value, 2, '.', ',') }}
                                        @else
                                            Rp {{ number_format((float)$r->total_value, 2, ',', '.') }}
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
{{-- ============ AKHIR BLOK BARU: SO Due This Week ============ --}}

<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card shadow-sm h-100 yz-chart-card">
            <div class="card-body d-flex flex-column">
                <h5 class="card-title"><i class="fas fa-chart-column me-2"></i>Value to Pacing vs Overdue by Location</h5>
                <hr class="mt-2">
                <div class="chart-container flex-grow-1">
                    <canvas id="chartValueByLocationStatus"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm h-100 yz-chart-card">
            <div class="card-body d-flex flex-column">
                <h5 class="card-title"><i class="fas fa-clock me-2"></i>SO Fulfillment Urgency</h5>
                <hr class="mt-2">
                <div class="chart-container flex-grow-1">
                    <canvas id="chartSoUrgency"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card shadow-sm h-100 yz-chart-card">
            <div class="card-body d-flex flex-column">
                <h5 class="card-title text-primary-emphasis"><i class="fas fa-crown me-2"></i>Top 5 Customers by Value Awaiting Packing</h5>
                <hr class="mt-2">
                <div class="chart-container flex-grow-1" style="min-height: 400px;">
                    <canvas id="chartTopCustomersValueSO"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>
@else
{{-- ==================== DASHBOARD PO ==================== --}}
<div class="row g-4 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="card yz-kpi-card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="yz-kpi-icon bg-primary-subtle text-primary">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="ms-3">
                    <p class="mb-1 text-muted">Outs Value Ship (USD)</p>
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
                    <p class="mb-1 text-muted">Outs Value Ship (IDR)</p>
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
                    <p class="mb-1 text-muted">Outstanding PO</p>
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
                    <p class="mb-1 text-muted">Overdue PO</p>
                    <h4 class="mb-0 fw-bolder"><span id="kpi-overdue-so">0</span> <small class="text-danger" id="kpi-overdue-rate">(0%)</small></h4>
                </div>
            </div>
        </div>
    </div>
</div>

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
                <h5 class="card-title"><i class="fas fa-chart-pie me-2"></i>PO Status Overview</h5>
                <hr class="mt-2">
                <div class="chart-container flex-grow-1">
                    <canvas id="chartSOStatus"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

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
                <h5 class="card-title text-danger-emphasis"><i class="fas fa-triangle-exclamation me-2"></i>Top 4 Customers with Most Overdue PO</h5>
                <hr class="mt-2">
                <div class="chart-container flex-grow-1">
                    <canvas id="chartTopOverdueCustomers"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card shadow-sm yz-chart-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h5 class="card-title mb-0">
                            <i class="fas fa-tasks me-2"></i>Outstanding PO & Performance Details by Type
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
                                <th scope="col">PO Type</th>
                                <th scope="col" class="text-center">Total PO</th>
                                <th scope="col" class="text-end">Outs. Value (IDR)</th>
                                <th scope="col" class="text-end">Outs. Value (USD)</th>
                                <th scope="col" class="text-center">PO Overdue</th>
                                <th scope="col" style="min-width: 300px;" class="text-center">Overdue Distribution (Days)</th>
                            </tr>
                        </thead>
                        <tbody id="so-performance-tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

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
                <div id="smallQtyDetailsTable" class="mt-3"></div>
            </div>
        </div>
    </div>
</div>
@endif
@endif

@endsection

@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard-style.css') }}">
@endpush

@push('scripts')
<script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
<script src="{{ asset('vendor/chartjs/chartjs-adapter-date-fns.bundle.min.js') }}"></script>

<script>
    // ==========================
    // Tabel Detail PO helpers
    // ==========================
    document.addEventListener('DOMContentLoaded', function() {
        const customerRows = document.querySelectorAll('.yz-kunnr-row');
        customerRows.forEach(row => {
            row.querySelector('td:nth-child(2)')?.setAttribute('data-label', 'Customer');
            row.querySelector('td:nth-child(3)')?.setAttribute('data-label', 'Overdue PO');
            row.querySelector('td:nth-child(4)')?.setAttribute('data-label', 'Overdue Rate');
            row.querySelector('td:nth-child(5)')?.setAttribute('data-label', 'Value');
        });
    });

    // ==========================
    // Helper umum (formatting)
    // ==========================
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

    const showNoDataMessage = (canvasId) => {
        const canvas = document.getElementById(canvasId);
        if (canvas) {
            const container = canvas.parentElement;
            if (container) {
                container.innerHTML =
                    `<div class="d-flex align-items-center justify-content-center h-100 p-3 text-muted" style="min-height:300px;">
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
                                    const value = formatFullCurrency(context.raw, currency);
                                    if (dataPoint.so_count) return `${value} (${dataPoint.so_count} PO)`;
                                    return `${value}`;
                                }
                                if (canvasId === 'chartTopOverdueCustomers' && dataPoint) {
                                    const total = dataPoint.overdue_count,
                                        smg = dataPoint.smg_count,
                                        sby = dataPoint.sby_count;
                                    let details = [];
                                    if (smg > 0) details.push(`SMG: ${smg}`);
                                    if (sby > 0) details.push(`SBY: ${sby}`);
                                    return `${total} PO${details.length ? ' ('+details.join(', ')+')' : ''}`;
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
                            callback: function(value) {
                                if (Math.floor(value) === value) {
                                    return currency ? formatFullCurrency(value, currency).replace(/\,00$/, '') : value;
                                }
                            }
                        }
                    }
                }
            }
        });
    };

    // ==========================
    // SCRIPT UTAMA
    // ==========================
    (() => {
        const rootElement = document.getElementById('yz-root');
        const showTable = rootElement ? !!parseInt(rootElement.dataset.show) : false;

        // ---------- MODE TABEL (LAPORAN) ----------
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
                if (!rows?.length) return `<div class="p-3 text-muted">Tidak ada data PO untuk KUNNR <b>${kunnr}</b>.</div>`;
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
                html += `</tbody></table></div>`;
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
                        const url = new URL(apiT2, window.location.origin);
                        url.searchParams.set('kunnr', kunnr);
                        if (WERKS) url.searchParams.set('werks', WERKS);
                        if (AUART) url.searchParams.set('auart', AUART);
                        const res = await fetch(url);
                        if (!res.ok) throw new Error('Network response was not ok');
                        const js = await res.json();
                        if (!js.ok) throw new Error(js.error || 'Gagal memuat data PO');
                        wrap.innerHTML = renderT2(js.data, kunnr);
                        wrap.dataset.loaded = '1';

                        wrap.querySelectorAll('.js-t2row').forEach(row2 => {
                            row2.addEventListener('click', async (ev) => {
                                if (row2.classList.contains('row-highlighted')) {
                                    row2.classList.remove('row-highlighted');
                                }
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
                                const u3 = new URL(apiT3, window.location.origin);
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

            // LOGIKA UNTUK SEARCH HIGHLIGHT
            const handleSearchHighlight = () => {
                const urlParams = new URLSearchParams(window.location.search);
                const highlightKunnr = urlParams.get('highlight_kunnr');
                const highlightVbeln = urlParams.get('highlight_vbeln');

                if (highlightKunnr && highlightVbeln) {
                    const customerRow = document.querySelector(`.yz-kunnr-row[data-kunnr="${highlightKunnr}"]`);

                    if (customerRow) {
                        customerRow.click();
                        let attempts = 0;
                        const maxAttempts = 50;
                        const interval = setInterval(() => {
                            const soRow = document.querySelector(`.js-t2row[data-vbeln="${highlightVbeln}"]`);
                            if (soRow) {
                                clearInterval(interval);

                                soRow.classList.add('row-highlighted');
                                setTimeout(() => {
                                    soRow.scrollIntoView({
                                        behavior: 'smooth',
                                        block: 'center'
                                    });
                                }, 500);
                            }
                            attempts++;
                            if (attempts > maxAttempts) {
                                clearInterval(interval);
                            }
                        }, 100);
                    }
                }
            };
            handleSearchHighlight();

            return;
        }

        // ---------- KODE DI BAWAH INI SUDAH DIPERBAIKI ----------
        const dataHolder = document.getElementById('dashboard-data-holder');
        if (!dataHolder) return;
        
        const currentView = new URLSearchParams(window.location.search).get('view');
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

        if (currentView === 'so') {
            // Logika untuk toggle tabel
            const toggleCard = document.getElementById('toggle-due-tables-card');
            const tablesContainer = document.getElementById('due-this-week-tables');
            if (toggleCard && tablesContainer) {
                toggleCard.addEventListener('click', () => {
                    const isHidden = tablesContainer.style.display === 'none';
                    tablesContainer.style.display = isHidden ? '' : 'none';
                });
            }
            
            // KPI SO
            document.getElementById('kpi-so-val-usd').textContent = formatFullCurrency(chartData.kpi.total_outstanding_value_usd, 'USD');
            document.getElementById('kpi-so-val-idr').textContent = formatFullCurrency(chartData.kpi.total_outstanding_value_idr, 'IDR');
            document.getElementById('kpi-so-ship-week-usd').textContent = formatFullCurrency(chartData.kpi.value_to_ship_this_week_usd, 'USD');
            document.getElementById('kpi-so-ship-week-idr').textContent = formatFullCurrency(chartData.kpi.value_to_ship_this_week_idr, 'IDR');
            document.getElementById('kpi-so-bottleneck').textContent = chartData.kpi.potential_bottlenecks;

            // Bar by location & status
            const ctxLocationStatus = document.getElementById('chartValueByLocationStatus');
            if (ctxLocationStatus) {
                const locationData = chartData.value_by_location_status || [];
                if (locationData.length === 0) {
                    showNoDataMessage('chartValueByLocationStatus');
                } else {
                    const labels = ['Semarang', 'Surabaya'];
                    const onTime = labels.map(loc => {
                        const row = locationData.find(d => d.location === loc);
                        return row ? Number(row.on_time_value) : 0;
                    });
                    const overdue = labels.map(loc => {
                        const row = locationData.find(d => d.location === loc);
                        return row ? Number(row.overdue_value) : 0;
                    });

                    new Chart(ctxLocationStatus, {
                        type: 'bar',
                        data: {
                            labels,
                            datasets: [{
                                label: 'On Time',
                                data: onTime,
                                backgroundColor: 'rgba(75, 192, 192, 0.7)'
                            }, {
                                label: 'Overdue',
                                data: overdue,
                                backgroundColor: 'rgba(255, 99, 132, 0.7)'
                            }]
                        },
                        options: {
                            scales: {
                                x: { stacked: true },
                                y: { stacked: true, beginAtZero: true }
                            },
                            plugins: {
                                tooltip: {
                                    callbacks: {
                                        label: (ctx) => {
                                            const val = Number(ctx.raw) || 0;
                                            return `${ctx.dataset.label}: ${formatFullCurrency(val, 'USD')}`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            }

            // Doughnut urgency
            const ctxSoUrgency = document.getElementById('chartSoUrgency');
            if (ctxSoUrgency && chartData.aging_analysis) {
                const agingData = chartData.aging_analysis;
                const total = Object.values(agingData).reduce((a, b) => a + b, 0);
                if (total === 0) {
                    showNoDataMessage('chartSoUrgency');
                } else {
                    new Chart(ctxSoUrgency, {
                        type: 'doughnut',
                        data: {
                            labels: ['Overdue > 30 Days', 'Overdue 1-30 Days', 'Due This Week', 'On Time'],
                            datasets: [{
                                data: [agingData.overdue_over_30, agingData.overdue_1_30, agingData.due_this_week, agingData.on_time],
                                backgroundColor: ['#b91c1c', '#ef4444', '#f59e0b', '#10b981']
                            }]
                        },
                        options: { cutout: '60%' }
                    });
                }
            }

            // Top customers (SO)
            const topCustomerData = selectedType === 'lokal' ? chartData.top_customers_value_idr : chartData.top_customers_value_usd;
            const topCustomerCurrency = selectedType === 'lokal' ? 'IDR' : 'USD';
            createHorizontalBarChart('chartTopCustomersValueSO', topCustomerData, 'total_value', 'Value Awaiting Shipment', {
                bg: 'rgba(59, 130, 246, 0.7)',
                border: 'rgba(59, 130, 246, 1)'
            }, topCustomerCurrency);

        } else {
            // KPI PO
            document.getElementById('kpi-out-usd').textContent = formatFullCurrency(chartData.kpi.total_outstanding_value_usd, 'USD');
            document.getElementById('kpi-out-idr').textContent = formatFullCurrency(chartData.kpi.total_outstanding_value_idr, 'IDR');
            document.getElementById('kpi-out-so').textContent = chartData.kpi.total_outstanding_so;
            document.getElementById('kpi-overdue-so').textContent = chartData.kpi.total_overdue_so;
            document.getElementById('kpi-overdue-rate').textContent = `(${(chartData.kpi.overdue_rate || 0).toFixed(1)}%)`;

            const currencyToDisplay = selectedType === 'lokal' ? 'IDR' : 'USD';

            // Outstanding by location
            const ctxLocation = document.getElementById('chartOutstandingLocation');
            if (ctxLocation) {
                const locationData = chartData.outstanding_by_location;
                const locationChartData = (locationData || []).filter(d => d.currency === currencyToDisplay);
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
                                    ticks: { callback: (v) => new Intl.NumberFormat('id-ID').format(v) }
                                }
                            },
                            plugins: {
                                tooltip: {
                                    callbacks: {
                                        label: (ctx) => {
                                            const dataPoint = locationChartData[ctx.dataIndex];
                                            const value = formatFullCurrency(ctx.raw, currencyToDisplay);
                                            const count = dataPoint ? dataPoint.so_count : '';
                                            return `${value} (${count} PO)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            }

            // Status doughnut
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
                                borderWidth: 2
                            }]
                        },
                        options: { cutout: '60%' }
                    });
                }
            }

            // Top value (PO)
            const topValueTitle = document.querySelector('#chartTopCustomersValue')?.closest('.card')?.querySelector('.card-title');
            if (selectedType === 'lokal') {
                if (topValueTitle) topValueTitle.innerHTML = `<i class="fas fa-crown me-2"></i>Top 4 Customers by Outstanding Value (IDR)`;
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

            // Top overdue customers
            createHorizontalBarChart('chartTopOverdueCustomers', chartData.top_customers_overdue, 'overdue_count', 'Jumlah PO Terlambat', {
                bg: 'rgba(220, 53, 69, 0.6)',
                border: 'rgba(220, 53, 69, 1)'
            });

            // Performance table
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
                        if (item.overdue_1_30 > 0) barChartHtml += `<div class="bar-segment" style="width:${pct1_30}%;background-color:#ffc107;" data-bs-toggle="tooltip" title="1-30 Days: ${item.overdue_1_30} PO">${item.overdue_1_30}</div>`;
                        if (item.overdue_31_60 > 0) barChartHtml += `<div class="bar-segment" style="width:${pct31_60}%;background-color:#fd7e14;" data-bs-toggle="tooltip" title="31-60 Days: ${item.overdue_31_60} PO">${item.overdue_31_60}</div>`;
                        if (item.overdue_61_90 > 0) barChartHtml += `<div class="bar-segment" style="width:${pct61_90}%;background-color:#dc3545;" data-bs-toggle="tooltip" title="61-90 Days: ${item.overdue_61_90} PO">${item.overdue_61_90}</div>`;
                        if (item.overdue_over_90 > 0) barChartHtml += `<div class="bar-segment" style="width:${pctOver90}%;background-color:#8b0000;" data-bs-toggle="tooltip" title=">90 Days: ${item.overdue_over_90} PO">${item.overdue_over_90}</div>`;
                        barChartHtml += '</div>';

                        tableHtml += `<tr>
                                <td><div class="fw-bold">${item.Deskription}</div></td>
                                <td class="text-center">${totalSo}</td>
                                <td class="${classIdr}">${valueIdr}</td>
                                <td class="${classUsd}">${valueUsd}</td>
                                <td class="text-center"><span class="fw-bold ${overdueSo > 0 ? 'text-danger' : ''}">${overdueSo}</span><small class="text-muted d-block">(${overdueRate}%)</small></td>
                                <td>${totalOverdueForBar > 0 ? barChartHtml : '<span class="text-muted small">Tidak ada PO terlambat</span>'}</td>
                            </tr>`;
                    });
                    performanceTbody.innerHTML = tableHtml;
                    new bootstrap.Tooltip(document.body, { selector: "[data-bs-toggle='tooltip']" });
                }
            }

            // Small quantity stacked bar + onClick detail
            const ctxSmallQty = document.getElementById('chartSmallQtyByCustomer');
            const smallQtyDataRaw = chartData.small_qty_by_customer || [];
            if (ctxSmallQty) {
                if (smallQtyDataRaw.length === 0) {
                    showNoDataMessage('chartSmallQtyByCustomer');
                } else {
                    const customerMap = new Map();
                    smallQtyDataRaw.forEach(item => {
                        if (!customerMap.has(item.NAME1)) {
                            customerMap.set(item.NAME1, { '3000': 0, '2000': 0 });
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
                            labels,
                            datasets: [
                                { label: 'Semarang', data: semarangData, backgroundColor: 'rgba(25, 135, 84, 0.8)' },
                                { label: 'Surabaya', data: surabayaData, backgroundColor: 'rgba(255, 193, 7, 0.8)' }
                            ]
                        },
                        options: {
                            indexAxis: 'y',
                            scales: {
                                // [MODIFIED] Menghapus 'stacked: true' agar bar tidak ditumpuk
                                x: { beginAtZero: true, title: { display: true, text: 'Item (With Qty Outstanding ≤ 5)' } },
                                y: {}
                            },
                            maintainAspectRatio: false,
                            plugins: {
                                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.x} PO` } }
                            },
                            onClick: async (event, elements) => {
                                if (elements.length === 0) return;
                                const barElement = elements[0];
                                const customerName = labels[barElement.index];
                                const locationName = event.chart.data.datasets[barElement.datasetIndex].label;
                                detailsTitle.textContent = `Detail Item untuk ${customerName} - (${locationName})`;
                                detailsTable.innerHTML = `<div class="d-flex justify-content-center align-items-center p-5"><div class="spinner-border text-primary" role="status"></div><span class="ms-3 text-muted">Memuat data...</span></div>`;
                                detailsContainer.style.display = 'block';
                                detailsContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });

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
                                        // [SESUDAH DIUBAH] Tambahkan class yz-scrollable-table-container
                                        let tableHtml = `<div class="table-responsive yz-scrollable-table-container"><table class="table table-striped table-hover table-sm align-middle">
                                            <thead class="table-light"><tr>
                                                <th style="width:5%;" class="text-center">No.</th>
                                                <th class="text-center">PO</th>
                                                <th class="text-center">SO</th>
                                                <th class="text-center">Item</th>
                                                <th>Desc FG</th>
                                                <th class="text-center">Qty PO</th>
                                                <th class="text-center">Outstanding</th>
                                            </tr></thead><tbody>`;
                                        result.data.forEach((item, idx) => {
                                            tableHtml += `<tr>
                                                <td class="text-center">${idx+1}</td>
                                                <td class="text-center">${item.BSTNK || '-'}</td>
                                                <td class="text-center">${item.VBELN}</td>
                                                <td class="text-center">${parseInt(item.POSNR, 10)}</td>
                                                <td>${item.MAKTX}</td>
                                                <td class="text-center">${parseFloat(item.KWMENG)}</td>
                                                <td class="text-center fw-bold text-danger">${parseFloat(item.QTY_BALANCE2)}</td>
                                            </tr>`;
                                        });
                                        tableHtml += `</tbody></table></div></div>`;
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
        }
    })();
</script>
@endpush