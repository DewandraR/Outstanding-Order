@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

    @php
        // Ambil nilai dari controller / query
        $werks = $selected['werks'] ?? null;
        $auart = $selected['auart'] ?? null;
        $show = filled($werks) && filled($auart);
        $onlyWerksSelected = filled($werks) && empty($auart);

        $locationMap = ['2000' => 'Surabaya', '3000' => 'Semarang'];
        $locName = $locationMap[$werks] ?? $werks;

        // Nilai state global (dipakai tombol/pill)
        $curView = $view ?? 'po'; // 'po' | 'so'
        $curLoc = $selectedLocation ?? null; // '2000' | '3000' | null
        $curType = $selectedType ?? null; // 'lokal' | 'export' | null

        // Helper pembentuk URL terenkripsi ke /dashboard
        $encDash = function (array $params) use ($curView) {
            $payload = array_filter(array_merge(['view' => $curView], $params), fn($v) => !is_null($v) && $v !== '');
            return route('dashboard', ['q' => \Crypt::encrypt($payload)]);
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
            $selectedAuart = trim((string) ($auart ?? '')); // buang spasi tersembunyi
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
                                    $pillUrl = $encDash(['werks' => $werks, 'auart' => $auartCode, 'compact' => 1]);
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
        A. MODE TABEL (LAPORAN PO) – muncul kalau WERKS & AUART terpilih
    ========================================================= --}}
    @if ($show && $compact)
        <div class="card yz-card shadow-sm mb-3">
            <div class="card-body p-0 p-md-2">
                <div class="p-3 mx-md-3 mt-md-3 yz-main-title-wrapper">
                    <h5 class="yz-table-title mb-0">
                        <i class="fas fa-users me-2"></i>Overview Customer
                    </h5>
                </div>

                @php
                    // total per currency untuk tabel Overview Customer
                    $totalsByCurr = [];
                    foreach ($rows as $r) {
                        $cur = $r->WAERK ?? '';
                        $val = (float) $r->TOTPR;
                        $totalsByCurr[$cur] = ($totalsByCurr[$cur] ?? 0) + $val;
                    }

                    // helper format (sama dengan tampilan Value per baris)
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

                <div class="table-responsive yz-table px-md-3">
                    <table class="table table-hover mb-0 align-middle yz-grid">
                        <thead class="yz-header-customer">
                            <tr>
                                <th style="width:50px;"></th>
                                <th class="text-start" style="min-width:250px;">Customer</th>
                                <th style="min-width:120px; text-align:center;">Overdue PO</th>
                                <th style="min-width:150px; text-align:center;">Overdue Rate</th>
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
                                    <td class="text-center">
                                        {{ is_null($r->LATE_PCT) ? '—' : number_format((float) $r->LATE_PCT, 2, '.', '') . '%' }}
                                    </td>
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
                                    <td colspan="5" class="p-0">
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
                                    <th class="text-center" colspan="2">—</th>
                                    <th class="text-end">{{ $formatTotal($sum, $cur) }}</th>
                                </tr>
                            @endforeach
                        </tfoot>
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
        <div id="dashboard-data-holder" data-chart-data='@json($chartData)'
            data-mapping-data='@json($mapping)' data-selected-type='{{ $selectedType }}'
            data-current-view='{{ $view }}' data-current-location='{{ $selectedLocation ?? '' }}'
            data-current-auart='{{ $auart ?? '' }}' style="display:none;">
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

                {{-- Filter Plant (location/werks) – terenkripsi --}}
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

                {{-- Filter Work Center (AUART) – juga terenkripsi --}}
                @if (!empty($availableAuart) && $availableAuart->count() > 1)
                    <ul class="nav nav-pills shadow-sm p-1" style="border-radius:.75rem;">
                        <li class="nav-item">
                            <a class="nav-link {{ !$auart ? 'active' : '' }}"
                                href="{{ $encDash(['location' => $curLoc, 'type' => $curType, 'auart' => null]) }}">
                                All Work Center
                            </a>
                        </li>
                        @foreach ($availableAuart as $wc)
                            <li class="nav-item">
                                <a class="nav-link {{ $auart == $wc->IV_AUART ? 'active' : '' }}"
                                    href="{{ $encDash(['location' => $curLoc, 'type' => $curType, 'auart' => $wc->IV_AUART]) }}">
                                    {{ $wc->Deskription }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- Filter Tipe (Export/Lokal) – terenkripsi --}}
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
        @if ($view === 'so' && !empty($chartData))
            {{-- ==================== DASHBOARD SO ==================== --}}
            <div class="row g-4 mb-4">
                <div class="col-sm-6 col-xl-3">
                    <div class="card yz-kpi-card h-100 shadow-sm">
                        <div class="card-body d-flex align-items-center">
                            <div class="yz-kpi-icon bg-primary-subtle text-primary"><i class="fas fa-dollar-sign"></i>
                            </div>
                            <div class="ms-3">
                                <div class="mb-1 text-muted yz-kpi-title"
                                    data-help-key="so.kpi.total_outstanding_value_usd">
                                    <span>Outs Value Packing</span>
                                </div>
                                <h4 class="mb-0 fw-bolder" id="kpi-so-val-usd">$0.00</h4>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card yz-kpi-card h-100 shadow-sm">
                        <div class="card-body d-flex align-items-center">
                            <div class="yz-kpi-icon bg-success-subtle text-success"><i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="ms-3">
                                <div class="mb-1 text-muted yz-kpi-title"
                                    data-help-key="so.kpi.total_outstanding_value_idr">
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
                            <div class="yz-kpi-icon bg-warning-subtle text-warning"><i
                                    class="fas fa-exclamation-triangle"></i></div>
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
                            Outs Value Packing by Customer —
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
                            <tbody id="so-outs-tbody"><!-- diisi via JS --></tbody>
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
                        $auartDescriptions = collect($mapping)->flatten()->keyBy('IV_AUART');
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

            <div id="bottlenecks-tables" style="display:none;"></div>

            <div class="row g-4 mb-4">
                <div class="col-lg-7">
                    <div class="card shadow-sm h-100 yz-chart-card">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title" data-help-key="so.value_by_location_status">
                                <i class="fas fa-chart-column me-2"></i>Value to Packing vs Overdue by Location
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
                                Packing
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
            {{-- ==================== DASHBOARD PO ==================== --}}
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
            {{-- === /DETAIL === --}}

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
                            <div id="so-status-details" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>

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

                        <div id="po-overdue-details" style="display:none;"></div>
                    </div>
                </div>
            </div>

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
                                </h5>
                                <button type="button" class="btn-close" id="closeDetailsTable"
                                    aria-label="Close"></button>
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
            //    Prefer: cari elemen dengan class standar Anda (yz-currency-toggle).
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
            top: .75rem; /* Sedikit ke bawah agar sejajar dengan judul */
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
           SCRIPT UTAMA
           ======================================================== */
        (() => {
            injectToggleStyles();
            const rootElement = document.getElementById('yz-root');
            const showTable = rootElement ? !!parseInt(rootElement.dataset.show) : false;

            /* ---------- MODE TABEL (LAPORAN) ---------- */
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

                // ====== PO REPORT: Expand/collapse Customer (L1) -> PO (L2) -> Items (L3) ======
                document.addEventListener('click', function(e) {
                    // Blokir klik checkbox di T2 agar tidak memicu expand T3
                    if (e.target.closest('.check-po, .check-all-pos')) {
                        e.stopPropagation();
                        e.stopImmediatePropagation?.();
                    }
                }, true);

                document.querySelectorAll('.yz-kunnr-row').forEach(row => {
                    row.addEventListener('click', async () => {
                        const kunnr = (row.dataset.kunnr || '').trim();
                        const kid = row.dataset.kid; // id <tr> nested di bawah customer
                        const slot = document.getElementById(kid); // <tr class="yz-nest">
                        const wrap = slot?.querySelector(
                        '.yz-nest-wrap'); // container L2 di dalam slot

                        const tbody = row.closest('tbody');
                        const tableEl = row.closest('table');
                        const tfootEl = tableEl?.querySelector('tfoot.yz-footer-customer');

                        const wasOpen = row.classList.contains('is-open');

                        // toggle focus-mode pada tbody
                        if (!wasOpen) {
                            tbody.classList.add('customer-focus-mode');
                            row.classList.add('is-focused');
                        } else {
                            tbody.classList.remove('customer-focus-mode');
                            row.classList.remove('is-focused');
                        }

                        row.classList.toggle('is-open');

                        // tampil/sembunyikan nested T2
                        slot.style.display = wasOpen ? 'none' : '';

                        // === Jika barusan MENUTUP customer, paksa tutup semua child & bersihkan state
                        if (wasOpen) {
                            // tutup semua baris level-3 yang mungkin terbuka di dalam wrap
                            wrap?.querySelectorAll('tr.yz-nest').forEach(tr => tr.style.display =
                                'none');
                            // bersihkan kelas fokus/caret di T2
                            wrap?.querySelectorAll('tbody.so-focus-mode').forEach(tb => tb.classList
                                .remove('so-focus-mode'));
                            wrap?.querySelectorAll('.js-t2row.is-focused').forEach(r => r.classList
                                .remove('is-focused'));
                            wrap?.querySelectorAll('.js-t2row .yz-caret.rot').forEach(c => c
                                .classList.remove('rot'));
                        }

                        // ---- kontrol visibility total keseluruhan (tfoot) ----
                        if (tfootEl) {
                            const anyVisibleNest = [...tableEl.querySelectorAll('tr.yz-nest')]
                                .some(tr => tr.style.display !== 'none' && tr.offsetParent !==
                                null);
                            tfootEl.style.display = anyVisibleNest ? 'none' : '';
                        }

                        if (wasOpen) return; // kalau barusan menutup, selesai
                        if (wrap.dataset.loaded === '1') return; // sudah pernah dimuat

                        try {
                            wrap.innerHTML = `
        <div class="p-3 text-muted small d-flex align-items-center justify-content-center yz-loader-pulse">
          <div class="spinner-border spinner-border-sm me-2"></div>Memuat data…
        </div>`;

                            // apiT2 harus sudah didefinisikan sebelumnya (route untuk L2/PO by customer)
                            const url = new URL(apiT2, window.location.origin);
                            url.searchParams.set('kunnr', kunnr);
                            if (typeof WERKS !== 'undefined' && WERKS) url.searchParams.set('werks',
                                WERKS);
                            if (typeof AUART !== 'undefined' && AUART) url.searchParams.set('auart',
                                AUART);

                            const res = await fetch(url);
                            if (!res.ok) throw new Error('Network response was not ok');
                            const js = await res.json();
                            if (!js.ok) throw new Error(js.error || 'Gagal memuat data PO');

                            // renderT2: fungsi kamu untuk merender Tabel-2 (PO list)
                            wrap.innerHTML = renderT2(js.data, kunnr);
                            wrap.dataset.loaded = '1';

                            // Bind baris PO (L2) untuk buka/utup Items (L3)
                            wrap.querySelectorAll('.js-t2row').forEach(row2 => {
                                row2.addEventListener('click', async (ev) => {
                                    ev
                                .stopPropagation(); // jangan bubbling ke L1

                                    const vbeln = (row2.dataset.vbeln || '')
                                        .trim();
                                    const tgtId = row2.dataset
                                    .tgt; // id <tr> nested L3
                                    const caret = row2.querySelector(
                                        '.yz-caret');
                                    const tgt = wrap.querySelector('#' +
                                    tgtId); // <tr class="yz-nest">
                                    const body = tgt.querySelector(
                                        '.yz-slot-t3'); // container isi items
                                    const open = tgt.style.display !== 'none';
                                    const tbody2 = row2.closest('tbody');

                                    // focus-mode untuk L2
                                    if (!open) {
                                        tbody2.classList.add('so-focus-mode');
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

                                    body.innerHTML = `
            <div class="p-2 text-muted small yz-loader-pulse">
              <div class="spinner-border spinner-border-sm me-2"></div>Memuat detail…
            </div>`;

                                    // apiT3: route untuk items by PO
                                    const u3 = new URL(apiT3, window.location
                                        .origin);
                                    u3.searchParams.set('vbeln', vbeln);
                                    if (typeof WERKS !== 'undefined' && WERKS)
                                        u3.searchParams.set('werks', WERKS);
                                    if (typeof AUART !== 'undefined' && AUART)
                                        u3.searchParams.set('auart', AUART);

                                    const r3 = await fetch(u3);
                                    if (!r3.ok) throw new Error(
                                        'Network response was not ok for item details'
                                        );
                                    const j3 = await r3.json();
                                    if (!j3.ok) throw new Error(j3.error ||
                                        'Gagal memuat detail item');

                                    // renderT3: fungsi kamu untuk merender Tabel-3 (items)
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

                    fetch("{{ route('dashboard.api.decrypt_payload') }}", {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute(
                                    'content')
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
                return;
            }

            /* ---------- MODE DASHBOARD (grafik & kpi) ---------- */
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

            /* ======================== DASHBOARD SO ======================== */
            if (currentView === 'so') {

                /* ====== MON–SUN RANGE: ganti placeholder "(...range tanggal...)" di judul ====== */
                function getWeekRangeLabel(baseDate = new Date()) {
                    const d = new Date(baseDate.getFullYear(), baseDate.getMonth(), baseDate.getDate());
                    const offsetToMonday = (d.getDay() + 6) % 7; // 0=Min -> 6, 1=Sen -> 0, dst
                    const start = new Date(d);
                    start.setDate(d.getDate() - offsetToMonday); // Senin
                    const end = new Date(start);
                    end.setDate(start.getDate() + 6); // Minggu

                    const fmt = (date, opts) => new Intl.DateTimeFormat('id-ID', opts).format(date);
                    const sameMonthYear = start.getMonth() === end.getMonth() && start.getFullYear() === end
                        .getFullYear();

                    if (sameMonthYear) {
                        const d1 = fmt(start, {
                            day: '2-digit'
                        });
                        const d2 = fmt(end, {
                            day: '2-digit'
                        });
                        const my = fmt(end, {
                            month: 'short',
                            year: 'numeric'
                        });
                        return `${d1}–${d2} ${my}`; // contoh: 23–29 Sep 2025
                    } else {
                        const p1 = fmt(start, {
                            day: '2-digit',
                            month: 'short'
                        });
                        const p2 = fmt(end, {
                            day: '2-digit',
                            month: 'short',
                            year: 'numeric'
                        });
                        return `${p1} – ${p2}`; // contoh: 30 Sep – 06 Okt 2025
                    }
                }

                function applyWeekRangeToTitles() {
                    const label = getWeekRangeLabel();
                    // Ganti placeholder di judul (mis. "SO Due This Week (...range tanggal...)")
                    document.querySelectorAll('.card-title, h5, h4').forEach(el => {
                        const txt = el.textContent;
                        if (/\(\.\.\.range tanggal\.\.\.\)/i.test(txt)) {
                            el.textContent = txt.replace(/\(\.\.\.range tanggal\.\.\.\)/i, label);
                        }
                    });
                    // Opsi alternatif: <span data-week-range></span>
                    document.querySelectorAll('[data-week-range]').forEach(span => {
                        span.textContent = label;
                    });
                }
                applyWeekRangeToTitles();
                /* ====== /MON–SUN RANGE ====== */

                const toggleCard = document.getElementById('toggle-due-tables-card');
                const tablesContainer = document.getElementById('due-this-week-tables');
                if (toggleCard && tablesContainer) {
                    toggleCard.addEventListener('click', () => {
                        const isHidden = tablesContainer.style.display === 'none';
                        tablesContainer.style.display = isHidden ? '' : 'none';
                    });
                }

                // 🆕 Potential Bottlenecks toggle + fetch (tetap seperti semula)
                const bottleneckCard = document.getElementById('toggle-bottlenecks-card');
                const bottleneckBox = document.getElementById('bottlenecks-tables');
                const apiSoBottlenecks = "{{ route('dashboard.api.soBottlenecksDetails') }}";

                function renderBottlenecksTable(rows, windowInfo) { // <== Terima argumen windowInfo
                    const mappingData = JSON.parse(document.getElementById('dashboard-data-holder').dataset
                        .mappingData || '{}');
                    const auartMap2 = {};
                    for (const w in mappingData)(mappingData[w] || []).forEach(m => auartMap2[m.IV_AUART] = m
                        .Deskription);
                    const fmt = s => (!s ? '' : s.split('-').reverse().join('-'));

                    // Buat teks rentang tanggal
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
                    bottleneckCard.addEventListener('click', async () => {
                        const api = new URL(apiSoBottlenecks, window.location.origin);
                        const isHidden = bottleneckBox.style.display === 'none';
                        if (!isHidden) {
                            bottleneckBox.style.display = 'none';
                            return;
                        }
                        bottleneckBox.style.display = '';
                        bottleneckBox.innerHTML = `
          <div class="card yz-chart-card shadow-sm">
            <div class="card-body d-flex align-items-center justify-content-center">
              <div class="spinner-border spinner-border-sm me-2" role="status"></div> Loading data...
            </div>
          </div>`;

                        if (filterState.location) api.searchParams.set('location', filterState.location);
                        if (filterState.type) api.searchParams.set('type', filterState.type);
                        if (filterState.auart) api.searchParams.set('auart', filterState.auart);

                        try {
                            const res = await fetch(api);
                            const json = await res.json();
                            if (!json.ok) throw new Error(json.error || 'Gagal mengambil data.');
                            renderBottlenecksTable(json.data || [], json.window_info);
                        } catch (e) {
                            bottleneckBox.innerHTML = `
            <div class="alert alert-danger m-3">
              <i class="fas fa-exclamation-triangle me-2"></i>${e.message}
            </div>`;
                        }
                    });
                }

                const sumSoTotals = (rows) => {
                    let usd = 0,
                        idr = 0;
                    (rows || []).forEach(r => {
                        const ot = r.on_time_breakdown || {};
                        const od = r.overdue_breakdown || {};
                        usd += Number(ot.usd || 0) + Number(od.usd || 0);
                        idr += Number(ot.idr || 0) + Number(od.idr || 0);
                    });
                    return {
                        usd,
                        idr
                    };
                };

                const {
                    usd: soUsdTotal,
                    idr: soIdrTotal
                } = sumSoTotals(chartData.value_by_location_status || []);
                document.getElementById('kpi-so-val-usd').textContent = formatFullCurrency(soUsdTotal, 'USD');
                document.getElementById('kpi-so-val-idr').textContent = formatFullCurrency(soIdrTotal, 'IDR');
                document.getElementById('kpi-so-ship-week-usd').textContent = formatFullCurrency(chartData.kpi
                    .value_to_ship_this_week_usd, 'USD');
                document.getElementById('kpi-so-ship-week-idr').textContent = formatFullCurrency(chartData.kpi
                    .value_to_ship_this_week_idr, 'IDR');
                document.getElementById('kpi-so-bottleneck').textContent = chartData.kpi.potential_bottlenecks;

                let soValByLocChart = null;

                // Ambil data per currency dari breakdown
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

                    // hancurkan chart lama SEBELUM apa pun
                    if (soValByLocChart) {
                        try {
                            soValByLocChart.destroy();
                        } catch {}
                        soValByLocChart = null;
                    }

                    if (total === 0) {
                        showNoDataMessage(canvasId);
                        return;
                    }
                    hideNoDataMessage(canvasId);
                    setTitleCurrencySuffixByCanvas(canvasId, currency);

                    // destroy chart lama
                    if (soValByLocChart) {
                        try {
                            soValByLocChart.destroy();
                        } catch (e) {}
                    }

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
                                    beginAtZero: true
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

                function rerenderSoCurrencyDependentCharts() {
                    renderSoTopCustomers(currentSoCurrency);
                    renderSoValueByLocationStatus(currentSoCurrency);
                }

                // Toggle USD/IDR khusus card "Value to Pacing vs Overdue by Location"
                function mountSoLocationCurrencyToggle() {
                    if (!enableSoCurrencyToggle) return; // kalau user pilih Lokal/Export -> jangan munculkan toggle

                    const chartCanvas = document.getElementById('chartValueByLocationStatus');
                    if (!chartCanvas) return;

                    const card = chartCanvas.closest('.card');
                    const cardBody = card?.querySelector('.card-body');
                    if (!cardBody) return;

                    const toolbar = document.createElement('div');
                    toolbar.className = 'yz-card-toolbar';
                    toolbar.innerHTML = `
    <div class="btn-group btn-group-sm yz-currency-toggle" role="group">
      <button type="button" data-cur="USD" class="btn ${currentSoCurrency==='USD' ? 'btn-primary' : 'btn-outline-primary'}">USD</button>
      <button type="button" data-cur="IDR" class="btn ${currentSoCurrency==='IDR' ? 'btn-success' : 'btn-outline-success'}">IDR</button>
    </div>`;
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

                        // Sinkronkan semua tombol toggle USD/IDR di halaman SO
                        document.querySelectorAll('.yz-currency-toggle button[data-cur]').forEach(b => {
                            const isUSD = b.dataset.cur === 'USD';
                            const isIDR = b.dataset.cur === 'IDR';
                            b.classList.toggle('btn-primary', isUSD && currentSoCurrency === 'USD');
                            b.classList.toggle('btn-outline-primary', isUSD && currentSoCurrency !==
                                'USD');
                            b.classList.toggle('btn-success', isIDR && currentSoCurrency === 'IDR');
                            b.classList.toggle('btn-outline-success', isIDR && currentSoCurrency !==
                                'IDR');
                        });

                        rerenderSoCurrencyDependentCharts();
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

                const enableSoCurrencyToggle = !selectedType; // Toggle aktif jika 'All Type' dipilih
                let currentSoCurrency;

                // [LOGIKA DIPERBAIKI] Prioritaskan filter 'lokal'/'export' sebelum membaca localStorage
                if (selectedType === 'lokal') {
                    currentSoCurrency = 'IDR';
                } else if (selectedType === 'export') {
                    currentSoCurrency = 'USD';
                } else { // Hanya jika 'All Type' aktif, gunakan toggle dan localStorage
                    currentSoCurrency = 'USD'; // Default untuk 'All Type'
                    try {
                        const saved = localStorage.getItem('soTopCustomerCurrency');
                        if (saved === 'USD' || saved === 'IDR') {
                            currentSoCurrency = saved;
                        }
                    } catch (e) {}
                }

                // Variabel untuk menyimpan instance chart agar bisa di-destroy
                let soTopCustomersChart = null;

                // 2. Fungsi untuk me-render chart Top Customers SO berdasarkan currency
                function renderSoTopCustomers(currency) {
                    const canvasId = 'chartTopCustomersValueSO';

                    // Hancurkan chart yang lama jika sudah ada
                    if (soTopCustomersChart) {
                        soTopCustomersChart.destroy();
                    }

                    // Perbarui judul kartu dengan suffix (USD) atau (IDR)
                    setTitleCurrencySuffixByCanvas(canvasId, currency);

                    const data = (currency === 'IDR') ?
                        chartData.top_customers_value_idr :
                        chartData.top_customers_value_usd;

                    const colors = (currency === 'IDR') ? {
                            bg: 'rgba(25, 135, 84, 0.7)',
                            border: 'rgba(25, 135, 84, 1)'
                        } // Warna hijau untuk IDR
                        :
                        {
                            bg: 'rgba(59, 130, 246, 0.7)',
                            border: 'rgba(59, 130, 246, 1)'
                        }; // Warna biru untuk USD

                    createHorizontalBarChart(
                        canvasId,
                        data,
                        'total_value',
                        'Value of Overdue Orders',
                        colors,
                        currency
                    );

                    // Simpan instance chart yang baru dibuat
                    soTopCustomersChart = Chart.getChart(canvasId);
                }

                // 3. Fungsi untuk memasang tombol toggle di header card
                function mountSoCurrencyToggle() {
                    if (!enableSoCurrencyToggle) return; // Jangan tampilkan toggle jika filter aktif

                    const chartCanvas = document.getElementById('chartTopCustomersValueSO');
                    if (!chartCanvas) return;

                    const card = chartCanvas.closest('.card');
                    const cardBody = card?.querySelector('.card-body');
                    if (!cardBody) return;

                    // Buat tombol toggle
                    const toolbar = document.createElement('div');
                    toolbar.className = 'yz-card-toolbar'; // Class ini sudah punya style pojok kanan atas
                    toolbar.innerHTML = `
        <div class="btn-group btn-group-sm yz-currency-toggle" role="group">
            <button type="button" data-cur="USD" class="btn ${currentSoCurrency === 'USD' ? 'btn-primary' : 'btn-outline-primary'}">USD</button>
            <button type="button" data-cur="IDR" class="btn ${currentSoCurrency === 'IDR' ? 'btn-success' : 'btn-outline-success'}">IDR</button>
        </div>`;

                    // [POSISI DIPERBAIKI] Set parent ke 'relative' dan tambahkan toolbar ke card-body.
                    // CSS dari .yz-card-toolbar akan otomatis menempatkannya di kanan atas.
                    card.style.position = 'relative';
                    cardBody.appendChild(toolbar);

                    // Tambahkan event listener
                    toolbar.addEventListener('click', (e) => {
                        const btn = e.target.closest('button[data-cur]');
                        if (!btn) return;
                        const next = btn.dataset.cur;
                        if (next === currentSoCurrency) return;

                        currentSoCurrency = next;
                        try {
                            localStorage.setItem('soTopCustomerCurrency', currentSoCurrency);
                        } catch {}

                        // Update tampilan SEMUA toggle USD/IDR di halaman
                        document.querySelectorAll('.yz-currency-toggle button[data-cur]').forEach(b => {
                            const isUSD = b.dataset.cur === 'USD';
                            const isIDR = b.dataset.cur === 'IDR';
                            b.classList.toggle('btn-primary', isUSD && currentSoCurrency === 'USD');
                            b.classList.toggle('btn-outline-primary', isUSD && currentSoCurrency !==
                                'USD');
                            b.classList.toggle('btn-success', isIDR && currentSoCurrency === 'IDR');
                            b.classList.toggle('btn-outline-success', isIDR && currentSoCurrency !==
                                'IDR');
                        });

                        // Render ulang kedua chart yang tergantung currency
                        rerenderSoCurrencyDependentCharts();
                    });
                }

                // 4. Panggil fungsi inisialisasi untuk chart Top Customers SO
                mountSoCurrencyToggle();
                renderSoTopCustomers(currentSoCurrency);
                mountSoLocationCurrencyToggle(); // pasang toggle di chart lokasi
                renderSoValueByLocationStatus(currentSoCurrency);

                /* ========================  ITEM WITH REMARK (INLINE)  ======================== */
                (function itemWithRemarkTableOnly() {
                    const apiRemarkItems = "{{ route('so.api.remark_items') }}";
                    const listBox = document.getElementById('remark-list-box-inline');
                    if (!listBox) return;
                    const currentLocation = filterState.location;
                    const currentType = filterState.type;
                    const currentAuart = filterState.auart;
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
                    const __auartDesc = (() => {
                        const base = {
                            ZOR1: 'KMI Export SBY',
                            ZOR3: 'KMI Local SBY',
                            ZRP1: 'KMI Replace SBY',
                            ZOR2: 'KMI Export SMG',
                            ZOR4: 'KMI Local SMG',
                            ZRP2: 'KMI Replace SMG',
                        };
                        try {
                            const holder = document.getElementById('dashboard-data-holder');
                            const raw = holder?.dataset?.mappingData;
                            if (raw) {
                                const mapping = JSON.parse(raw);
                                Object.keys(mapping || {}).forEach(werks => {
                                    (mapping[werks] || []).forEach(m => {
                                        base[String(m.IV_AUART).trim()] = m.Deskription;
                                    });
                                });
                            }
                        } catch {}
                        return base;
                    })();

                    function buildTable(rows) {
                        if (!rows?.length) {
                            return `<div class="text-center text-muted py-4"><i class="fas fa-info-circle me-2"></i>Tidak ada item dengan remark.</div>`;
                        }

                        const body = rows.map((r, i) => {
                            const item = stripZeros(r.POSNR);
                            const werks = (r.IV_WERKS_PARAM || r.WERKS || '').trim();
                            const auart = String(r.IV_AUART_PARAM || r.AUART || '').trim();
                            const plant = __plantName(werks);
                            const otName = __auartDesc[auart] || auart || '-';
                            const so = (r.VBELN || '').trim();
                            const kunnr = (r.KUNNR || '').trim();

                            // [PERBAIKAN] Siapkan data untuk dikirim via POST, bukan membuat URL di sini
                            const postData = {
                                redirect_to: 'so.index',
                                werks: werks,
                                auart: auart,
                                compact: 1,
                                highlight_kunnr: kunnr,
                                highlight_vbeln: so,
                                highlight_posnr: item, // <<< TAMBAH INI
                                auto_expand: '1'
                            };

                            // [PERBAIKAN] Hapus `data-url` dan ganti dengan `data-payload` yang berisi data JSON yang aman
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
                            if (currentAuart) url.searchParams.set('auart', currentAuart);

                            const res = await fetch(url, {
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                }
                            });
                            const json = await res.json();
                            if (!json.ok) throw new Error(json.error || 'Gagal memuat daftar item.');
                            listBox.innerHTML = buildTable(json.data || []);
                        } catch (e) {
                            listBox.innerHTML = `
            <div class="alert alert-danger m-0">
                <i class="fas fa-exclamation-triangle me-2"></i>${e.message}
            </div>`;
                        }
                    }

                    // [PERBAIKAN] Logika klik sekarang mengirim data via form POST yang aman
                    listBox.addEventListener('click', (ev) => {
                        const tr = ev.target.closest('.js-remark-row');
                        if (!tr || !tr.dataset.payload) return;

                        const rowData = JSON.parse(tr.dataset.payload);

                        // Gunakan rowData apa adanya, lalu tambahkan field kontrol
                        const postData = {
                            ...rowData,
                            redirect_to: 'so.index', // laporan SO
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

                return; // selesai untuk view=SO
            }

            /* ======================== DASHBOARD PO ======================== */
            document.getElementById('kpi-out-usd').textContent = formatFullCurrency(chartData.kpi
                .total_outstanding_value_usd, 'USD');
            document.getElementById('kpi-out-idr').textContent = formatFullCurrency(chartData.kpi
                .total_outstanding_value_idr, 'IDR');
            document.getElementById('kpi-out-so').textContent = chartData.kpi.total_outstanding_so;
            document.getElementById('kpi-overdue-so').textContent = chartData.kpi.total_overdue_so;
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
                const ctx = document.getElementById('chartOutstandingLocation');
                if (!ctx) return;

                const locationData = chartData.outstanding_by_location || [];
                const ds = (locationData || []).filter(d => d.currency === currency);

                if (!ds.length) {
                    showNoDataMessage('chartOutstandingLocation');
                    return;
                }

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

                if (!document.getElementById('yzToggleCss')) {
                    const style = document.createElement('style');
                    style.id = 'yzToggleCss';
                    style.textContent = `
      .yz-card-toolbar{position:absolute; top:.35rem; right:.75rem; z-index:3;}
      .yz-card-toolbar .btn{padding:.15rem .5rem; font-size:.75rem; line-height:1.1;}
      .yz-card-header-pad{padding-right:96px;}
    `;
                    document.head.appendChild(style);
                }

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
                    const headerRow = titleEl?.parentElement;
                    if (!headerRow) return;

                    if (!headerRow.style.position) headerRow.style.position = 'relative';
                    headerRow.classList.add('yz-card-header-pad');
                    headerRow.querySelector('.yz-card-toolbar')?.remove();

                    const toolbar = makeToggle();
                    headerRow.appendChild(toolbar);

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
                                backgroundColor: ['rgba(255, 99, 132, 0.7)', 'rgba(255, 206, 86, 0.7)',
                                    'rgba(75, 192, 192, 0.7)'
                                ],
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
                                await loadSoStatusDetails(statusKey, label);
                            }
                        }
                    });
                }
            }

            createHorizontalBarChart(
                'chartTopOverdueCustomers',
                chartData.top_customers_overdue,
                'overdue_count',
                'Jumlah PO Terlambat', {
                    bg: 'rgba(220, 53, 69, 0.6)',
                    border: 'rgba(220, 53, 69, 1)'
                }
            );

            const performanceData = chartData.so_performance_analysis;
            const performanceTbody = document.getElementById('so-performance-tbody');
            const apiPoOverdueDetails = "{{ route('dashboard.api.poOverdueDetails') }}";

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
                    new bootstrap.Tooltip(document.body, {
                        selector: "[data-bs-toggle='tooltip']"
                    });
                }

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

                    let overlay = card.querySelector('#po-perf-details');
                    if (!overlay) {
                        overlay = document.createElement('div');
                        overlay.id = 'po-perf-details';
                        overlay.style.cssText =
                            'position:absolute;inset:0;background:var(--bs-card-bg,#fff);z-index:10;display:flex;padding:1rem;';
                        card.appendChild(overlay);
                    }

                    const showLoading = () => overlay.innerHTML = `
        <div class="card yz-chart-card shadow-sm h-100 w-100">
          <div class="card-body d-flex flex-column">
            <div class="d-flex justify-content-between align-items-center">
              <h6 class="card-title mb-0"><i class="fas fa-list me-2"></i>PO List — ${labelText}</h6>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="close-po-perf-details"><i class="fas fa-times"></i></button>
            </div>
            <hr class="mt-2">
            <div class="d-flex align-items-center justify-content-center flex-grow-1 text-muted">
              <div class="spinner-border spinner-border-sm me-2" role="status"></div> Loading data...
            </div>
          </div>
        </div>`;
                    const showError = (msg) => {
                        overlay.innerHTML = `
          <div class="card yz-chart-card shadow-sm h-100 w-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center">
                <h6 class="card-title mb-0 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Error</h6>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="close-po-perf-details"><i class="fas fa-times"></i></button>
              </div>
              <hr class="mt-2">
              <div class="alert alert-danger mb-0">${msg}</div>
            </div>
          </div>`;
                        document.getElementById('close-po-perf-details')?.addEventListener('click',
                            () => overlay.remove());
                    };

                    showLoading();
                    document.getElementById('close-po-perf-details')?.addEventListener('click', () =>
                        overlay.remove());

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

                        overlay.innerHTML = `
          <div class="card yz-chart-card shadow-sm h-100 w-100">
            <div class="card-body d-flex flex-column">
              <div class="d-flex justify-content-between align-items-center">
                <h6 class="card-title mb-0"><i class="fas fa-list me-2"></i>PO List — ${labelText}</h6>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="close-po-perf-details"><i class="fas fa-times"></i></button>
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
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <th class="text-center" style="min-width:120px;">EDATU</th>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <th class="text-center" style="min-width:140px;">OVERDUE (DAYS)</th>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        </tr>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    </thead>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <tbody>${body}</tbody>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                </table>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            </div>` :
              `<div class="text-muted p-4 text-center"><i class="fas fa-info-circle me-2"></i>Data tidak ditemukan.</div>`
            }
            </div>
          </div>`;
                        document.getElementById('close-po-perf-details')?.addEventListener('click', () =>
                            overlay.remove());
                    } catch (err) {
                        showError(err.message || 'Terjadi kesalahan.');
                    }
                });
            }

            function renderPoOverdueTable(rows, labelText) {
                const container = document.getElementById('po-overdue-details');
                if (!container) return;

                const tbody = (rows || []).map((r, i) => `
      <tr>
        <td class="text-center">${i + 1}</td>
        <td class="text-center">${r.PO ?? r.BSTNK ?? '-'}</td>
        <td class="text-center">${r.SO ?? r.VBELN ?? '-'}</td>
        <td class="text-center">${r.EDATU ?? '-'}</td>
        <td class="text-center fw-bold ${((r.OVERDUE_DAYS||0) > 0) ? 'text-danger' : ''}">${r.OVERDUE_DAYS ?? 0}</td>
    </tr>
    `).join('');

                container.innerHTML = `
      <div class="card yz-chart-card shadow-sm h-100 w-100">
        <div class="card-body d-flex flex-column">
          <div class="d-flex justify-content-between align-items-center">
            <h6 class="card-title mb-0"><i class="fas fa-list me-2"></i>PO List — ${labelText}</h6>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="closePoOverdueOverlay">
              <i class="fas fa-times"></i>
            </button>
          </div>
          <hr class="mt-2">
          ${rows && rows.length ? `
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          <div class="table-responsive yz-scrollable-table-container flex-grow-1" style="min-height:0;">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              <table class="table table-sm table-hover align-middle mb-0">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  <thead class="table-light" style="position:sticky;top:0;z-index:1;">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      <tr>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          <th class="text-center" style="width:60px;">NO.</th>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          <th class="text-center" style="min-width:120px;">PO</th>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          <th class="text-center" style="min-width:120px;">SO</th>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          <th class="text-center" style="min-width:120px;">EDATU</th>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          <th class="text-center" style="min-width:140px;">OVERDUE (DAYS)</th>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      </tr>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  </thead>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  <tbody>${tbody}</tbody>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              </table>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          </div>` : `
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          <div class="text-muted p-4 text-center">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              <i class="fas fa-info-circle me-2"></i>Data tidak ditemukan.
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          </div>`}
        </div>
      </div>`;
                document.getElementById('closePoOverdueOverlay')?.addEventListener('click', () => {
                    container.style.display = 'none';
                    container.innerHTML = '';
                    container.removeAttribute('style');
                });
            }

            /* ======================== Small Quantity chart (PO) ======================== */
            const ctxSmallQty = document.getElementById('chartSmallQtyByCustomer');
            const smallQtyDataRaw = chartData.small_qty_by_customer || [];
            if (ctxSmallQty) {
                if (smallQtyDataRaw.length === 0) {
                    showNoDataMessage('chartSmallQtyByCustomer');
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
                        (a[1]['3000'] + a[1]['2000']) - (b[1]['3000'] + b[1]['2000'])
                    ).reverse();
                    const labels = sortedCustomers.map(item => item[0]);
                    const semarangData = sortedCustomers.map(item => item[1]['3000']);
                    const surabayaData = sortedCustomers.map(item => item[1]['2000']);
                    const detailsContainer = document.getElementById('smallQtyDetailsContainer');
                    const detailsTitle = document.getElementById('smallQtyDetailsTitle');
                    const detailsTable = document.getElementById('smallQtyDetailsTable');
                    const closeButton = document.getElementById('closeDetailsTable');
                    closeButton?.addEventListener('click', () => detailsContainer.style.display = 'none');

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
                            scales: {
                                x: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Item (With Qty Outstanding ≤ 5)'
                                    }
                                }
                            },
                            maintainAspectRatio: false,
                            plugins: {
                                tooltip: {
                                    callbacks: {
                                        label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.x} PO`
                                    }
                                }
                            },
                            onClick: async (event, elements) => {
                                if (elements.length === 0) return;
                                const barElement = elements[0];
                                const customerName = labels[barElement.index];
                                const locationName = event.chart.data.datasets[barElement.datasetIndex]
                                    .label;
                                detailsTitle.textContent =
                                    `Detail Item untuk ${customerName} - (${locationName})`;
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
                                        result.data.sort((a, b) => parseFloat(a.QTY_BALANCE2) - parseFloat(b
                                            .QTY_BALANCE2));
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
                                        detailsTable.innerHTML =
                                            `<div class="text-center p-5 text-muted">Data item tidak ditemukan.</div>`;
                                    }
                                } catch (error) {
                                    console.error('Gagal mengambil data detail:', error);
                                    detailsTable.innerHTML =
                                        `<div class="text-center p-5 text-danger">Terjadi kesalahan saat memuat data.</div>`;
                                }
                            }
                        }
                    });
                }
            }

            /* ======================== Overlay helper: PO Status ======================== */
            async function loadSoStatusDetails(statusKey, labelText) {
                const container = document.getElementById('so-status-details');
                if (!container) return;
                container.style.display = 'block';
                container.innerHTML = `
      <div class="card yz-chart-card shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center">
            <h6 class="card-title mb-0"><i class="fas fa-list me-2"></i>PO List — ${labelText}</h6>
            <button type="button" class="btn btn-sm btn-outline-secondary" disabled><i class="fas fa-times"></i></button>
          </div>
          <hr class="mt-2">
          <div class="d-flex align-items-center justify-content-center p-4 text-muted">
            <div class="spinner-border spinner-border-sm me-2" role="status"></div> Loading data...
          </div>
        </div>
      </div>`;

                const api = new URL("{{ route('dashboard.api.soStatusDetails') }}", window.location.origin);
                api.searchParams.set('status', statusKey);
                if (filterState.location) api.searchParams.set('location', filterState.location);
                if (filterState.type) api.searchParams.set('type', filterState.type);

                try {
                    const res = await fetch(api);
                    const json = await res.json();
                    if (!json.ok) throw new Error('Gagal mengambil data dari server.');
                    renderSoStatusTable(json.data, labelText);
                } catch (e) {
                    const errorHtml = `
        <div class="card yz-chart-card shadow-sm">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
              <h6 class="card-title mb-0 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Error</h6>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="closeSoStatusDetailsError"><i class="fas fa-times"></i></button>
            </div>
            <hr class="mt-2">
            <div class="alert alert-danger mb-0">${e.message}</div>
          </div>
        </div>`;
                    container.innerHTML = errorHtml;
                    document.getElementById('closeSoStatusDetailsError')?.addEventListener('click', () => {
                        container.style.display = 'none';
                        container.innerHTML = '';
                    });
                }
            }

            function renderSoStatusTable(rows, labelText) {
                const container = document.getElementById('so-status-details');
                if (!container) return;
                const formatDate = (s) => !s ? '' : s.split('-').reverse().join('-');
                const table = (rows || []).map((r, i) => `
      <tr>
        <td class="text-center">${i + 1}</td>
        <td class="text-center">${r.BSTNK ?? '-'}</td>
        <td class="text-center">${r.VBELN}</td>
        <td>${r.NAME1 ?? ''}</td>
        <td class="text-center">${plantMap[r.IV_WERKS_PARAM] || r.IV_WERKS_PARAM}</td>
        <td class="text-center">${auartMap[r.IV_AUART_PARAM] || r.IV_AUART_PARAM}</td>
        <td class="text-center">${formatDate(r.due_date) || '-'}</td>
      </tr>`).join('');

                container.innerHTML = `
      <div class="card yz-chart-card shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center">
            <h6 class="card-title mb-0"><i class="fas fa-list me-2"></i>PO List — ${labelText}</h6>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="closeSoStatusDetails"><i class="fas fa-times"></i></button>
          </div>
          <hr class="mt-2">
          ${rows && rows.length ? `
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <div class="table-responsive yz-scrollable-table-container">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <table class="table table-sm table-hover align-middle mb-0">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <thead class="table-light">
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
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        </div>` : `
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <div class="text-muted p-4 text-center">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <i class="fas fa-info-circle me-2"></i>Data tidak ditemukan.
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        </div>`}
        </div>
      </div>`;
                document.getElementById('closeSoStatusDetails')?.addEventListener('click', () => {
                    container.style.display = 'none';
                    container.innerHTML = '';
                });
            }

            /* ======================== Overlay helper: SO Urgency ======================== */
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
                container.innerHTML = `
      <div class="card yz-chart-card shadow-sm h-100 w-100">
        <div class="card-body d-flex flex-column">
          <div class="d-flex justify-content-between align-items-center">
            <h6 class="card-title mb-0"><i class="fas fa-list me-2"></i>SO List — ${labelText}</h6>
            <button type="button" class="btn btn-sm btn-outline-secondary" disabled><i class="fas fa-times"></i></button>
          </div>
          <hr class="mt-2">
          <div class="d-flex align-items-center justify-content-center flex-grow-1 text-muted">
            <div class="spinner-border spinner-border-sm me-2" role="status"></div> Loading data...
          </div>
        </div>
      </div>`;

                const api = new URL("{{ route('dashboard.api.soUrgencyDetails') }}", window.location.origin);
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
                    container.innerHTML = `
        <div class="card yz-chart-card shadow-sm h-100 w-100">
          <div class="card-body d-flex flex-column">
            <div class="d-flex justify-content-between align-items-center">
              <h6 class="card-title mb-0 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Error</h6>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="closeSoUrgencyDetailsError"><i class="fas fa-times"></i></button>
            </div>
            <hr class="mt-2">
            <div class="alert alert-danger mb-0">${e.message}</div>
          </div>
        </div>`;
                    document.getElementById('closeSoUrgencyDetailsError')?.addEventListener('click', () => {
                        container.removeAttribute('style');
                        container.innerHTML = '';
                    });
                }
            }

            const formatOrderTypeLabel = (row) => {
                // 1) pakai label dari backend kalau ada
                const preset = (row.order_type_label || '').trim();
                if (preset) return preset;

                // 2) ambil deskripsi dari mapping (atau fallback)
                const desc = (auartMap[row.IV_AUART_PARAM] || row.order_type_name || '').trim();

                // 3) short plant
                const plantShort =
                    row.plant_short ||
                    (row.IV_WERKS_PARAM === '2000' ? 'SBY' :
                        row.IV_WERKS_PARAM === '3000' ? 'SMG' : '');

                // 4) fallback terakhir jika deskripsi kosong
                if (!desc) return [row.IV_AUART_PARAM || '', plantShort || ''].join(' ').trim();

                // 5) HINDARI duplikasi: kalau desc sudah diakhiri SBY/SMG, jangan append lagi
                const normalized = desc.replace(/\s+/g, ' ').trim().toUpperCase();
                if (normalized.endsWith(' SBY') || normalized.endsWith(' SMG') || !plantShort) {
                    return desc; // sudah ada suffix plant, biarkan apa adanya
                }

                // 6) kalau belum ada, tambahkan sekali
                return `${desc} ${plantShort}`.trim();
            };

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
        <td class="text-center">${({ '2000':'Surabaya','3000':'Semarang' })[r.IV_WERKS_PARAM] || r.IV_WERKS_PARAM}</td>
        <td class="text-center">${
      (() => {
        if (r.order_type_label && String(r.order_type_label).trim()) return r.order_type_label;
        const desc  = auartMap[r.IV_AUART_PARAM] || r.order_type_name || '';
        const short = r.plant_short || (r.IV_WERKS_PARAM === '2000' ? 'SBY'
                       : r.IV_WERKS_PARAM === '3000' ? 'SMG' : '');
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
        })();
    </script>
@endpush
@push('scripts')
    <script>
        (function() {
            const holder = document.getElementById('dashboard-data-holder');
            if (!holder) return;

            const apiUrl = "{{ route('api.po.outs_by_customer') }}";

            // Elemen detail card di bawah KPI
            const box = document.getElementById('po-outs-details');
            const tbodyEl = document.getElementById('po-outs-tbody');
            const totalEl = document.getElementById('po-outs-total');
            const filterEl = document.getElementById('po-outs-filter');
            const curBadgeEl = document.getElementById('po-outs-cur');
            const btnHide = document.getElementById('po-outs-hide');

            // Filter global dari holder (sama seperti sebelumnya)
            const curLoc = holder.dataset.currentLocation || '';
            const curType = holder.dataset.selectedType || '';
            const curAu = holder.dataset.currentAuart || '';

            // Formatter (copy dari kode kamu)
            function fmt(val, cur) {
                val = Number(val || 0);
                if (cur === 'USD') {
                    return '$' + val.toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                }
                if (cur === 'IDR') {
                    return 'Rp ' + val.toLocaleString('id-ID', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                }
                return val.toLocaleString();
            }

            function showBox() {
                box.style.display = '';
            }

            function hideBox() {
                box.style.display = 'none';
            }
            btnHide && btnHide.addEventListener('click', hideBox);

            function renderLoading(currency) {
                curBadgeEl.textContent = currency;
                filterEl.textContent =
                    `Filter: ${curLoc||'All Plant'} • ${curType||'All Type'} ${curAu?('• '+curAu):''}`;
                tbodyEl.innerHTML = `
      <tr><td colspan="3">
        <div class="text-center text-muted py-3">
          <div class="spinner-border spinner-border-sm me-2"></div> Loading...
        </div>
      </td></tr>`;
                totalEl.textContent = '–';
            }

            function renderRows(rows, currency) {
                let total = 0;
                const html = rows.map(r => {
                    total += Number(r.TOTAL_VALUE || 0);
                    return `<tr>
        <td>${r.NAME1||''}</td>
        <td class="text-center">${r.ORDER_TYPE||r.AUART||''}</td>
        <td class="text-end">${fmt(r.TOTAL_VALUE, currency)}</td>
      </tr>`;
                }).join('');
                tbodyEl.innerHTML = html;
                totalEl.textContent = fmt(total, currency);
            }

            async function openDetailBelowKPI(currency) {
                renderLoading(currency);
                showBox();

                const params = new URLSearchParams({
                    currency: currency,
                    location: curLoc || '',
                    type: curType || '',
                    auart: curAu || ''
                });

                try {
                    const res = await fetch(apiUrl + '?' + params.toString(), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const json = await res.json();
                    const rows = (json && json.ok) ? (json.data || []) : [];

                    if (!rows.length) {
                        tbodyEl.innerHTML =
                            `<tr><td colspan="3"><div class="alert alert-info m-0">Tidak ada data untuk filter saat ini.</div></td></tr>`;
                        totalEl.textContent = '–';
                        return;
                    }
                    renderRows(rows, currency);
                } catch (e) {
                    tbodyEl.innerHTML =
                        `<tr><td colspan="3"><div class="alert alert-danger m-0">Gagal memuat data.</div></td></tr>`;
                    totalEl.textContent = '–';
                }
            }

            // Trigger: klik KPI USD / IDR
            const usdCard = document.getElementById('kpi-po-outs-usd');
            const idrCard = document.getElementById('kpi-po-outs-idr');

            const poDetailBox = document.getElementById('po-outs-details');
            const poHideFunc = () => {
                poDetailBox.style.display = 'none';
            };

            // Klik KPI USD
            usdCard && usdCard.addEventListener('click', () => {
                if (poDetailBox.style.display === 'none' || poDetailBox.dataset.activeCurrency !== 'USD') {
                    poDetailBox.dataset.activeCurrency = 'USD';
                    openDetailBelowKPI('USD');
                } else {
                    poHideFunc();
                }
            });

            // Klik KPI IDR
            idrCard && idrCard.addEventListener('click', () => {
                if (poDetailBox.style.display === 'none' || poDetailBox.dataset.activeCurrency !== 'IDR') {
                    poDetailBox.dataset.activeCurrency = 'IDR';
                    openDetailBelowKPI('IDR');
                } else {
                    poHideFunc();
                }
            });

            // Pastikan fungsi hideBox() yang lama juga diperbarui
            function hideBox() {
                poDetailBox.style.display = 'none';
            }
        })();


        (function() {
            const holder = document.getElementById('dashboard-data-holder');
            if (!holder) return;

            const soApi = "{{ route('api.so.outs_by_customer') }}";

            const soBox = document.getElementById('so-outs-details');
            const soTbody = document.getElementById('so-outs-tbody');
            const soTotalEl = document.getElementById('so-outs-total');
            const soFilterEl = document.getElementById('so-outs-filter');
            const soCurBadge = document.getElementById('so-outs-cur');
            const soBtnHide = document.getElementById('so-outs-hide');

            const curLoc = holder.dataset.currentLocation || '';
            const curType = holder.dataset.selectedType || '';
            const curAu = holder.dataset.currentAuart || '';

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
                soFilterEl.textContent =
                    `Filter: ${curLoc||'All Plant'} • ${curType||'All Type'} ${curAu?('• '+curAu):''}`;
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
                    auart: curAu || ''
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
                    if (gtot !== null) soTotalEl.textContent = fmt(gtot, currency); // <- kunci
                } catch (e) {
                    soTbody.innerHTML =
                        `<tr><td colspan="3"><div class="alert alert-danger m-0">Gagal memuat data.</div></td></tr>`;
                    soTotalEl.textContent = '–';
                }
            }

            // Klik kartu KPI
            const soUsd = document.getElementById('kpi-so-val-usd')?.closest('.card');
            const soIdr = document.getElementById('kpi-so-val-idr')?.closest('.card');
            const soDetailBox = document.getElementById('so-outs-details');

            const soHideFunc = () => {
                soDetailBox.style.display = 'none';
            };

            // Klik KPI USD
            if (soUsd) {
                soUsd.addEventListener('click', () => {
                    if (soDetailBox.style.display === 'none' || soDetailBox.dataset.activeCurrency !== 'USD') {
                        soDetailBox.dataset.activeCurrency = 'USD';
                        openSoDetailBelowKPI('USD');
                    } else {
                        soHideFunc();
                    }
                });
            }

            // Klik KPI IDR
            if (soIdr) {
                soIdr.addEventListener('click', () => {
                    if (soDetailBox.style.display === 'none' || soDetailBox.dataset.activeCurrency !== 'IDR') {
                        soDetailBox.dataset.activeCurrency = 'IDR';
                        openSoDetailBelowKPI('IDR');
                    } else {
                        soHideFunc();
                    }
                });
            }

            // Pastikan fungsi soHide() yang lama juga diperbarui
            function soHide() {
                soDetailBox.style.display = 'none';
            }
        })();
    </script>
@endpush
