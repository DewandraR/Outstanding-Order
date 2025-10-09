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

        // Ambil data mapping untuk dropdown
        $allMapping = $mapping;

        // Ambil data KPI baru
        $kpiNew = $chartData['kpi_new'] ?? [];

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

        // Helper pembentuk URL terenkripsi ke SO Report (so.index)
        $encReport = function (array $params) {
            $payload = array_filter(array_merge(['compact' => 1], $params), fn($v) => !is_null($v) && $v !== '');
            // NOTE: Route 'so.index' harus sudah terdaftar
            return route('so.index', ['q' => \Crypt::encrypt($payload)]);
        };

        // Helper untuk format mata uang (DIPERLUKAN UNTUK RENDER BLOCK Awal)
        $formatCurrency = function ($value, $currency, $decimals = 2) {
            $n = (float) $value;
            if ($n == 0) {
                return '–';
            }
            if ($currency === 'IDR') {
                return 'Rp ' . number_format($n, $decimals, ',', '.');
            }
            return '$' . number_format($n, $decimals, '.', ',');
        };

        // Helper untuk format quantity (Count SO) (DIPERLUKAN UNTUK RENDER BLOCK Awal)
        $formatQty = function ($value, $decimals = 0) {
            $n = (float) $value;
            if ($n == 0) {
                return '–';
            }
            return number_format($n, $decimals, '.', ',');
        };

        // =========================================================================================
        // PERBAIKAN: Filter Mapping untuk Drill Down Report (Menghapus Replace)
        // =========================================================================================
        $filteredMapping = $allMapping->map(function ($plantGroup) {
            // Hapus semua Order Type yang Deskription-nya mengandung 'Replace'
            $filteredGroup = $plantGroup->reject(function ($item) {
                return \Illuminate\Support\Str::contains(strtolower((string) $item->Deskription), 'replace');
            });

            return $filteredGroup->values();
        });

        // =========================================================================================
        // HELPER PHP UNTUK RENDER SINGLE KPI BLOCK (DIADAPTASI DARI PO)
        // =========================================================================================
        $renderSingleKpiBlock = function ($locationName, $kpiData, $locPrefix, $allMapping) use (
            $formatCurrency,
            $formatQty,
            $encReport,
        ) {
            $werksCode = $locPrefix === 'smg' ? '3000' : '2000';

            // Logika untuk menemukan AUART Export dan Lokal
            $exportAuart = collect($allMapping[$werksCode] ?? [])->first(
                fn($t) => \Illuminate\Support\Str::contains(strtolower((string) $t->Deskription), 'export') &&
                    !\Illuminate\Support\Str::contains(strtolower((string) $t->Deskription), 'local') &&
                    !\Illuminate\Support\Str::contains(strtolower((string) $t->Deskription), 'replace'),
            );

            $localAuart = collect($allMapping[$werksCode] ?? [])->first(
                fn($t) => \Illuminate\Support\Str::contains(strtolower((string) $t->Deskription), 'local'),
            );

            // NOTE: Karena SO Report menggabungkan Export + Replace secara internal,
            // kita hanya perlu mengarahkan ke AUART Export untuk link USD/Export.

            $exportAuartCode = $exportAuart ? trim((string) $exportAuart->IV_AUART) : null;
            $localAuartCode = $localAuart ? trim((string) $localAuart->IV_AUART) : null;

            // Buat URL terenkripsi ke SO Index/Report
            $urlExport = $exportAuartCode ? $encReport(['werks' => $werksCode, 'auart' => $exportAuartCode]) : '#';
            $urlLocal = $localAuartCode ? $encReport(['werks' => $werksCode, 'auart' => $localAuartCode]) : '#';

            // Ambil data dari kpiNew
            $usdVal = $kpiData[$locPrefix . '_usd_val'] ?? 0;
            $usdQty = $kpiData[$locPrefix . '_usd_qty'] ?? 0;
            $usdOverdueVal = $kpiData[$locPrefix . '_usd_overdue_val'] ?? 0;
            $usdOverdueQty = $kpiData[$locPrefix . '_usd_overdue_qty'] ?? 0;

            $idrVal = $kpiData[$locPrefix . '_idr_val'] ?? 0;
            $idrQty = $kpiData[$locPrefix . '_idr_qty'] ?? 0;
            $idrOverdueVal = $kpiData[$locPrefix . '_idr_overdue_val'] ?? 0;
            $idrOverdueQty = $kpiData[$locPrefix . '_idr_overdue_qty'] ?? 0;

            // Data-attribute untuk JavaScript (menyimpan semua nilai)
            $dataAttrs = [
                'data-werks' => $werksCode,
                // PENTING: data-export-url dan data-local-url harus ada
                'data-export-url' => $urlExport,
                'data-local-url' => $urlLocal,
                'data-usd-val' => $usdVal,
                'data-usd-qty' => $usdQty,
                'data-usd-overdue-val' => $usdOverdueVal,
                'data-usd-overdue-qty' => $usdOverdueQty,
                'data-idr-val' => $idrVal,
                'data-idr-qty' => $idrQty,
                'data-idr-overdue-val' => $idrOverdueVal,
                'data-idr-overdue-qty' => $idrOverdueQty,
            ];

            // Pilihan warna
            $mainColor = $locPrefix === 'smg' ? 'bg-teal-gradient' : 'bg-indigo-gradient';

            // Tampilan Awal (Default: USD)
            $initialVal = $formatCurrency($usdVal, 'USD');
            $initialQty = $formatQty($usdQty);
            $initialOverdueVal = $formatCurrency($usdOverdueVal, 'USD');
            $initialOverdueQty = $formatQty($usdOverdueQty);

            // Class awal untuk nilai USD
            $valClass = 'text-usd';

            // URL default (USD/Export)
            $initialUrl = $urlExport;

            return '
                <div class="col-lg-6" ' .
                implode(' ', array_map(fn($k, $v) => "{$k}=\"$v\"", array_keys($dataAttrs), $dataAttrs)) .
                ' id="kpi-block-' .
                $locPrefix .
                '">
                    <div class="card shadow-lg h-100 kpi-main-card yz-kpi-card-enhanced">
                        
                        <div class="card-header position-relative p-3 ' .
                $mainColor .
                '">
                            <h4 class="mb-0 fw-bolder text-center text-white" style="letter-spacing: 1px;">' .
                $locationName .
                '</h4>
                            <div class="yz-card-toolbar" id="kpi-toggle-holder-' .
                $locPrefix .
                '">
                                <div class="btn-group btn-group-sm yz-currency-toggle" role="group">
                                    <button type="button" data-cur="USD" class="btn btn-primary btn-sm-square">USD</button>
                                    <button type="button" data-cur="IDR" class="btn btn-outline-success btn-sm-square">IDR</button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card-body p-4 pt-3">
                            
                            <div class="row mb-4 border-bottom pb-3">
                                <h6 class="text-uppercase fw-bold ps-3 pt-0 mb-3" style="color: #6c757d;">Outstanding</h6>
                                <div class="col-lg-6 mb-3">
                                    <a href="' .
                $initialUrl .
                '" class="text-decoration-none kpi-link" id="' .
                $locPrefix .
                '-outs-link">
                                        <div class="d-flex align-items-center yz-kpi-item-inner">
                                            <div class="yz-kpi-icon bg-primary-subtle text-primary p-2 me-3" 
                                                style="border-radius: 50%; box-shadow: 0 0 10px rgba(13, 110, 253, 0.2);">
                                                <i class="fas fa-sack-dollar"></i>
                                            </div>
                                            <div style="line-height:1.2;">
                                                <div class="mb-0 text-muted small text-uppercase fw-semibold"><span>Outstanding Value</span></div>
                                                <h3 class="mb-0 fw-bolder ' .
                $valClass .
                '" id="' .
                $locPrefix .
                '-outstanding-value">' .
                ($initialVal === '–' ? '<span class="text-muted">–</span>' : $initialVal) .
                '</h3>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-lg-6 mb-3">
                                    <a href="' .
                $initialUrl .
                '" class="text-decoration-none kpi-link" id="' .
                $locPrefix .
                '-qty-link">
                                        <div class="d-flex align-items-center yz-kpi-item-inner">
                                            <div class="yz-kpi-icon bg-info-subtle text-info p-2 me-3" 
                                                style="border-radius: 50%; box-shadow: 0 0 10px rgba(13, 202, 240, 0.2);">
                                                <i class="fas fa-box-open"></i>
                                            </div>
                                            <div style="line-height:1.2;">
                                                <div class="mb-0 text-muted small text-uppercase fw-semibold"><span>Total SO</span></div>
                                                <h3 class="mb-0 fw-bolder text-info' . // Hapus $qtyColorClass karena sudah di class info di sini
                '" id="' .
                $locPrefix .
                '-outstanding-qty">' .
                ($initialQty === '–' ? '<span class="text-muted">–</span>' : $initialQty) .
                '</h3>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                            </div>
                            
                            <div class="row pt-3">
                                <h6 class="text-uppercase text-danger fw-bold ps-3 pt-0 mb-3">Overdue</h6>
                                <div class="col-lg-6 mb-3">
                                    <a href="' .
                $initialUrl .
                '" class="text-decoration-none kpi-link" id="' .
                $locPrefix .
                '-overdue-val-link">
                                        <div class="d-flex align-items-center yz-kpi-item-inner">
                                            <div class="yz-kpi-icon bg-danger-subtle text-danger p-2 me-3" 
                                                style="border-radius: 50%; box-shadow: 0 0 10px rgba(220, 53, 69, 0.2);">
                                                <i class="fas fa-circle-exclamation"></i>
                                            </div>
                                            <div style="line-height:1.2;">
                                                <div class="mb-0 text-muted small text-uppercase fw-semibold"><span>Overdue Value</span></div>
                                                <h3 class="mb-0 fw-bolder text-danger" id="' .
                $locPrefix .
                '-overdue-value">' .
                ($initialOverdueVal === '–' ? '<span class="text-muted">–</span>' : $initialOverdueVal) .
                '</h3>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-lg-6 mb-3">
                                    <a href="' .
                $initialUrl .
                '" class="text-decoration-none kpi-link" id="' .
                $locPrefix .
                '-overdue-qty-link">
                                        <div class="d-flex align-items-center yz-kpi-item-inner">
                                            <div class="yz-kpi-icon bg-danger-subtle text-danger p-2 me-3" 
                                                style="border-radius: 50%; box-shadow: 0 0 10px rgba(220, 53, 69, 0.2);">
                                                <i class="fas fa-hourglass-half"></i>
                                            </div>
                                            <div style="line-height:1.2;">
                                                <div class="mb-0 text-muted small text-uppercase fw-semibold"><span>Total SO</span></div>
                                                <h3 class="mb-0 fw-bolder text-danger" id="' .
                $locPrefix .
                '-overdue-qty">' .
                ($initialOverdueQty === '–' ? '<span class="text-muted">–</span>' : $initialOverdueQty) .
                '</h3>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                            </div>
                            
                        </div>
                    </div>
                </div>';
        };
    @endphp

    {{-- Anchor untuk JS --}}
    <div id="yz-root" data-show="0" data-werks="{{ $werks ?? '' }}" data-auart="{{ $auart ?? '' }}" style="display:none">
    </div>

    {{-- DATA HOLDER untuk Chart.js dan JS logic --}}
    <div id="dashboard-data-holder" data-chart-data='@json($chartData)'
        data-mapping-data='@json($filteredMapping)' data-selected-type='{{ $selectedType }}'
        data-current-view='{{ $view }}' data-current-location='{{ $selectedLocation ?? '' }}'
        data-current-auart='{{ $selectedAuart ?? '' }}' style="display:none;">
    </div>

    {{-- HEADER DENGAN DROPDOWN --}}
    <div class="d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-center mb-3 gap-3">
        <div>
            <h2 class="mb-0 fw-bolder text-primary">Outstanding SO Overview</h2>
            <p class="text-muted mb-0"><i class="fas fa-chart-line me-1"></i> Monitoring Sales Orders Outstanding & Overdue
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2 justify-content-start justify-content-lg-end">
            <span class="d-flex align-items-center text-muted small fw-semibold me-2">Drill Down Report:</span>
            @php
                $locations = ['3000' => 'Semarang', '2000' => 'Surabaya'];
            @endphp

            @foreach ($locations as $werks_code => $name)
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm text-truncate" type="button"
                        data-bs-toggle="dropdown" aria-expanded="false" style="min-width: 120px;">
                        {{ $name }}
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-lg">
                        <h6 class="dropdown-header text-uppercase small text-primary-emphasis">Order Type
                            {{ $name }}</h6>
                        @php
                            // Menggunakan $filteredMapping yang sudah menghapus 'Replace'
                            $werksMapping = $filteredMapping[$werks_code] ?? collect([]);
                        @endphp

                        @forelse ($werksMapping as $t)
                            @php
                                $auartCode = trim((string) $t->IV_AUART);
                                // Ciptakan payload terenkripsi ke SO Report (so.index)
                                $reportUrl = $encReport(['werks' => $werks_code, 'auart' => $auartCode]);
                            @endphp
                            <li>
                                <a class="dropdown-item" href="{{ $reportUrl }}">
                                    <i class="fas fa-file-alt me-2 text-info"></i> {{ $t->Deskription }}
                                </a>
                            </li>
                        @empty
                            <li><span class="dropdown-item text-muted disabled">No Order Types Found</span></li>
                        @endforelse
                    </ul>
                </div>
            @endforeach
        </div>
    </div>
    <hr class="mt-0 mb-4 border-primary opacity-25">

    @if (!empty($chartData))
        {{-- ==================== DASHBOARD SO CONTENT: KPI BLOCKS BARU ==================== --}}
        <div class="row g-4 mb-4">
            {{-- SEMARANG BLOCK --}}
            {!! $renderSingleKpiBlock('Semarang', $kpiNew, 'smg', $allMapping) !!}

            {{-- SURABAYA BLOCK --}}
            {!! $renderSingleKpiBlock('Surabaya', $kpiNew, 'sby', $allMapping) !!}
        </div>

        <hr class="my-4 border-dashed border-secondary">

        {{-- CHART TOP CUSTOMERS (Overdue Value) --}}
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card shadow-lg h-100 yz-chart-card position-relative">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title text-danger-emphasis yz-card-header-pad-top"
                            data-help-key="so.top_overdue_customers_value">
                            <i class="fas fa-crown me-2 text-warning"></i>Top 5 Customers by Value of Overdue Orders
                            Awaiting Packing (USD)
                        </h5>
                        <hr class="mt-2 mb-3">
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
    <style>
        /* ========================================================= */
        /* Custom Styles untuk Tampilan KPI Block (Dari PO Dashboard) */
        /* ========================================================= */

        .border-dashed {
            border-style: dashed !important;
            opacity: 0.35;
        }

        /* Peningkatan Kontras Warna untuk Header KPI Gradient */
        .bg-teal-gradient {
            background: linear-gradient(90deg, #0d9488 0%, #065f46 100%);
        }

        .bg-indigo-gradient {
            background: linear-gradient(90deg, #4f46e5 0%, #312e81 100%);
        }

        .text-teal {
            color: #0d9488;
        }

        .text-indigo {
            color: #4f46e5;
        }

        /* Penyesuaian KPI Block */
        .yz-kpi-card-enhanced {
            border-radius: 1.25rem;
            overflow: hidden;
            border: none;
            transition: transform 0.3s ease-in-out, box-shadow 0.3s ease-in-out;
        }

        .yz-kpi-card-enhanced:hover {
            transform: translateY(-5px);
            box-shadow: 0 1rem 2rem rgba(0, 0, 0, 0.15) !important;
        }

        /* Icon pada KPI Block */
        .yz-kpi-icon {
            font-size: 1.5rem;
            width: 54px;
            height: 54px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .yz-kpi-item-inner {
            transition: transform 0.2s ease;
        }

        .yz-kpi-item-inner:hover {
            transform: translateX(5px);
        }

        /* Perbaikan: Atur posisi toolbar KPI block */
        .yz-kpi-card-enhanced .yz-card-toolbar {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            right: 1rem;
        }

        .yz-kpi-card-enhanced .yz-card-toolbar .btn-sm-square {
            padding: .2rem .6rem;
            font-size: .8rem;
            line-height: 1;
        }

        .text-usd {
            color: var(--bs-primary, #0d6efd) !important;
        }

        .text-idr {
            color: var(--bs-success, #198754) !important;
        }

        /* Judul Chart */
        .yz-chart-card .card-title {
            font-size: 1.15rem;
        }

        .yz-card-header-pad-top {
            padding-right: 150px !important;
        }

        .yz-chart-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 0.8rem 1.5rem rgba(0, 0, 0, 0.1) !important;
        }

        /* Perbaikan Kontras Warna Toggle di dalam Header Gradient (KPI) */
        .yz-currency-toggle .btn-sm-square {
            background-color: rgba(255, 255, 255, 0.15);
            color: white !important;
            border: 1px solid rgba(255, 255, 255, 0.5);
            font-weight: 600;
        }

        .yz-currency-toggle .btn.btn-primary {
            background-color: #ffffff !important;
            color: #0d9488 !important;
            border-color: #ffffff !important;
        }

        .yz-currency-toggle .btn.btn-success {
            background-color: #ffffff !important;
            color: #15803d !important;
            border-color: #ffffff !important;
        }

        .yz-currency-toggle .btn.btn-outline-primary,
        .yz-currency-toggle .btn.btn-outline-success {
            background-color: transparent !important;
        }

        .yz-kpi-card-enhanced .card-header h4 {
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.3);
        }

        /* ========================================================= */
        /* PERBAIKAN UTAMA: Warna Toggle di dalam Chart (Latar Belakang Putih) */
        /* ========================================================= */
        .yz-currency-toggle-chart {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            border-radius: 0.5rem;
            overflow: hidden;
        }

        /* Tombol non-aktif */
        .yz-currency-toggle-chart .btn-sm-square {
            background-color: #f8f9fa !important;
            color: #6c757d !important;
            border: 1px solid #e9ecef;
            font-weight: 600;
        }

        /* Tombol USD (Primary) saat AKTIF */
        .yz-currency-toggle-chart .btn.btn-primary {
            background-color: var(--bs-primary, #0d6efd) !important;
            color: white !important;
            border-color: var(--bs-primary, #0d6efd) !important;
        }

        /* Tombol IDR (Success) saat AKTIF */
        .yz-currency-toggle-chart .btn.btn-success {
            background-color: var(--bs-success, #198754) !important;
            color: white !important;
            border-color: var(--bs-success, #198754) !important;
        }

        /* Tombol non-aktif saat outline */
        .yz-currency-toggle-chart .btn-outline-primary,
        .yz-currency-toggle-chart .btn-outline-success {
            background-color: #f8f9fa !important;
            color: #6c757d !important;
            border-color: #e9ecef !important;
        }

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
    </style>
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

        // Set Default Chart Font/Style
        Chart.defaults.font.family = 'Inter, sans-serif';
        Chart.defaults.plugins.legend.position = 'bottom';
        Chart.defaults.responsive = true;
        Chart.defaults.maintainAspectRatio = false;

        // =========================================================
        // Helper Functions
        // =========================================================

        const formatFullCurrency = (value, currency) => {
            const n = parseFloat(value);
            if (isNaN(n) || n === 0) return '–';
            if (currency === 'IDR') {
                return new Intl.NumberFormat('id-ID', {
                    style: 'currency',
                    currency: 'IDR',
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }).format(n).replace('IDR', 'Rp');
            }
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD',
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(n);
        };

        const formatQty = (value, decimals = 0) => {
            const n = parseFloat(value);
            if (isNaN(n) || n === 0) return '–';

            return new Intl.NumberFormat('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: decimals
            }).format(n);
        };

        function setTitleCurrencySuffixByCanvas(canvasId, currency) {
            const titleEl = document.getElementById(canvasId)?.closest('.card')?.querySelector('.card-title');
            if (!titleEl) return;
            const textNodes = Array.from(titleEl.childNodes)
                .filter(n => n.nodeType === Node.TEXT_NODE && n.textContent.trim().length);

            if (!textNodes.length) return;
            const tn = textNodes[textNodes.length - 1];
            let raw = tn.textContent;

            raw = raw.replace(/\s*\((USD|IDR)\)\s*$/, '');

            if (/\(USD|IDR\)/.test(raw)) {
                tn.textContent = raw.replace(/\((USD|IDR)\)/, `(${currency})`);
            } else {
                tn.textContent = `${raw.trim()} (${currency})`;
            }
        }

        function preventInfoButtonPropagation() {
            const infoButtons = document.querySelectorAll('.yz-info-icon');
            infoButtons.forEach(btn => {
                if (btn.dataset.clickBound === '1') return;
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    e.stopImmediatePropagation?.();
                });
                btn.dataset.clickBound = '1';
            });
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


        /**
         * Fungsi untuk membuat Horizontal Bar Chart.
         */
        const createHorizontalBarChart = (canvasId, chartData, dataKey, label, color, currency = '') => {
            if (!chartData || chartData.length === 0) {
                showNoDataMessage(canvasId);
                return null;
            }
            hideNoDataMessage(canvasId);
            const ctx = document.getElementById(canvasId);
            if (!ctx) return null;
            const prev = Chart.getChart(canvasId);
            if (prev) prev.destroy();

            const labels = chartData.map(d => {
                const customerName = d.NAME1.length > 25 ? d.NAME1.substring(0, 25) + '...' : d.NAME1;

                // Jika data memiliki so_count, tambahkan sebagai label agar terlihat di chart
                const soCountText = d.so_count ? ` (${d.so_count} SO)` : '';
                return `${customerName}${soCountText}`;
            });
            const values = chartData.map(d => d[dataKey]);

            const newChart = new Chart(ctx, {
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
                                // Menampilkan nama Customer tanpa SO Count di title tooltip
                                title: (items) => items[0].label.split('(')[0].trim(),
                                label: (context) => {
                                    const dataPoint = chartData[context.dataIndex];

                                    if (currency && dataPoint) {
                                        const totalTxt = formatFullCurrency(context.raw, currency);

                                        let breakdownTxt = '';
                                        // LOGIKA BREAKDOWN SBY/SMG
                                        if (canvasId === 'chartTopCustomersValueSO') {
                                            const sby = Number(dataPoint.sby_value || 0);
                                            const smg = Number(dataPoint.smg_value || 0);

                                            if (sby > 0 && smg > 0) {
                                                breakdownTxt =
                                                    ` (SMG: ${formatFullCurrency(smg, currency)}, SBY: ${formatFullCurrency(sby, currency)})`;
                                            } else if (smg > 0 && sby === 0) {
                                                breakdownTxt = ' (SMG)';
                                            } else if (sby > 0 && smg === 0) {
                                                breakdownTxt = ' (SBY)';
                                            }
                                        }

                                        return `${totalTxt}${breakdownTxt}`;
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
                                minRotation: 20,
                                maxRotation: 20,
                                autoSkip: true,
                                padding: 6,
                                callback: (value) => {
                                    if (Math.floor(value) === value) {
                                        if (currency) {
                                            let formatted = new Intl.NumberFormat('id-ID', {
                                                minimumFractionDigits: 0,
                                                maximumFractionDigits: 2
                                            }).format(value);
                                            return formatted;
                                        }
                                        return value;
                                    }
                                }
                            }
                        }
                    }
                }
            });
            return newChart;
        };


        /* =========================================================
           HELPER KPI BARU (UNTUK FUNGSI TOGGLE)
           ======================================================== */
        /**
         * Memperbarui nilai dan link pada blok KPI tunggal berdasarkan mata uang yang dipilih.
         */
        const updateKpiBlock = (locPrefix, currency) => {
            const block = document.getElementById(`kpi-block-${locPrefix}`);
            if (!block) return;

            const curLower = currency.toLowerCase();
            const isUSD = currency === 'USD';

            // Ambil nilai dari data attribute
            const val = parseFloat(block.dataset[`${curLower}Val`]) || 0;
            const qty = parseInt(block.dataset[`${curLower}Qty`]) || 0;
            const overdueVal = parseFloat(block.dataset[`${curLower}OverdueVal`]) || 0;
            const overdueQty = parseInt(block.dataset[`${curLower}OverdueQty`]) || 0;

            // Ambil URL yang sudah terenkripsi dari data attribute
            const reportUrl = isUSD ? (block.dataset.exportUrl || '#') : (block.dataset.localUrl || '#');

            // Format nilai
            const valText = formatFullCurrency(val, currency);
            const qtyText = formatQty(qty, 0);
            const overdueValText = formatFullCurrency(overdueVal, currency);
            const overdueQtyText = formatQty(overdueQty, 0);

            // Tentukan class warna untuk Outstanding Value
            const valColorClass = isUSD ? 'text-usd' : 'text-idr';

            // Target elemen
            const valEl = document.getElementById(`${locPrefix}-outstanding-value`);
            const qtyEl = document.getElementById(`${locPrefix}-outstanding-qty`);
            const overdueValEl = document.getElementById(`${locPrefix}-overdue-value`);
            const overdueQtyEl = document.getElementById(`${locPrefix}-overdue-qty`);

            // PENTING: Perbaikan Logika Link
            const links = block.querySelectorAll('.kpi-link');

            // Update Nilai & Warna Outstanding Value
            if (valEl) {
                valEl.innerHTML = (val === 0 || valText === '–') ? '<span class="text-muted">–</span>' : valText;
                valEl.classList.remove('text-primary', 'text-success', 'text-usd', 'text-idr');
                valEl.classList.add(valColorClass);
            }
            if (qtyEl) {
                qtyEl.innerHTML = (qty === 0 || qtyText === '–') ? '<span class="text-muted">–</span>' : qtyText;
            }
            if (overdueValEl) {
                overdueValEl.innerHTML = (overdueVal === 0 || overdueValText === '–') ?
                    '<span class="text-muted">–</span>' : overdueValText;
            }
            if (overdueQtyEl) {
                overdueQtyEl.innerHTML = (overdueQty === 0 || overdueQtyText === '–') ?
                    '<span class="text-muted">–</span>' : overdueQtyText;
            }

            // Update Link Tujuan (USD -> Export, IDR -> Local)
            links.forEach(link => {
                link.href = reportUrl;
            });


            // Update Tampilan Toggle
            document.querySelectorAll(`#kpi-toggle-holder-${locPrefix} button[data-cur]`).forEach(b => {
                const isCurrent = b.dataset.cur === currency;

                if (b.dataset.cur === 'USD') {
                    b.classList.toggle('btn-primary', isCurrent);
                    b.classList.toggle('btn-outline-primary', !isCurrent);
                    b.classList.remove('btn-success', 'btn-outline-success');
                } else if (b.dataset.cur === 'IDR') {
                    b.classList.toggle('btn-success', isCurrent);
                    b.classList.toggle('btn-outline-success', !isCurrent);
                    b.classList.remove('btn-primary', 'btn-outline-primary');
                }
            });
        };

        /**
         * FUNGSI BARU UNTUK MEMPERBARUI TOMBOL TOGGLE DI CHART
         */
        function updateChartToggleButtons(currency) {
            document.querySelectorAll('.yz-currency-toggle-chart button[data-cur]').forEach(b => {
                const v = b.dataset.cur;
                b.classList.toggle('btn-primary', v === 'USD' && currency === 'USD');
                b.classList.toggle('btn-outline-primary', v === 'USD' && currency !== 'USD');
                b.classList.toggle('btn-success', v === 'IDR' && currency === 'IDR');
                b.classList.toggle('btn-outline-success', v === 'IDR' && currency !== 'IDR');
            });
        }

        /**
         * Menginisialisasi tombol toggle KPI dan event listener-nya.
         */
        function initKpiToggles(chartCurrencyFunction, initialChartCurrency) {
            const locations = ['smg', 'sby'];

            let savedKpiCur = initialChartCurrency;
            try {
                const saved = localStorage.getItem('soKpiCurrency');
                if (saved === 'USD' || saved === 'IDR') savedKpiCur = saved;
            } catch {}

            let currentKpiCurrency = savedKpiCur;

            // 1. Terapkan nilai awal KPI
            locations.forEach(loc => {
                updateKpiBlock(loc, currentKpiCurrency);
            });

            // Panggil render chart pertama kali (menggunakan currency KPI)
            if (typeof chartCurrencyFunction === 'function') {
                chartCurrencyFunction(currentKpiCurrency);
            }

            // PENTING: Perbarui tampilan tombol chart toggle saat inisialisasi
            updateChartToggleButtons(currentKpiCurrency);

            // 2. Tambahkan event listeners untuk toggle KPI
            locations.forEach(loc => {
                const toggleHolder = document.getElementById(`kpi-toggle-holder-${loc}`);
                toggleHolder?.addEventListener('click', (e) => {
                    const btn = e.target.closest('button[data-cur]');
                    if (!btn) return;
                    e.preventDefault();

                    const nextCurrency = btn.dataset.cur;

                    if (nextCurrency === currentKpiCurrency) return;

                    currentKpiCurrency = nextCurrency;

                    // Simpan ke Local Storage untuk preferensi
                    try {
                        localStorage.setItem('soKpiCurrency', currentKpiCurrency);
                        // Simpan ke chart currency juga agar sinkron saat halaman dimuat ulang
                        localStorage.setItem('soTopCustomerCurrency', currentKpiCurrency);
                    } catch {}

                    // Perbarui semua blok KPI dan link
                    locations.forEach(l => updateKpiBlock(l, currentKpiCurrency));

                    // Perbarui chart Top Customers
                    if (typeof chartCurrencyFunction === 'function') {
                        chartCurrencyFunction(currentKpiCurrency);
                        // PENTING: Panggil fungsi update tombol chart di sini juga
                        updateChartToggleButtons(currentKpiCurrency);
                    }
                });
            });

            return currentKpiCurrency;
        }

        /* =========================================================
           MAIN SO DASHBOARD SCRIPT
           ======================================================== */
        (() => {

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
            if (!chartData) return;

            // --- LOGIC PENENTU MATA UANG CHART AWAL ---
            const hasTypeFilter = !!filterState.type;
            const enableCurrencyToggle = (!hasTypeFilter);

            // Tentukan mata uang chart default/initial
            let initialChartCurrency = (dataHolder.dataset.selectedType === 'lokal') ? 'IDR' : 'USD';
            if (enableCurrencyToggle) {
                try {
                    const saved = localStorage.getItem('soTopCustomerCurrency');
                    if (saved === 'USD' || saved === 'IDR') initialChartCurrency = saved;
                } catch {}
            }

            let currentChartCurrency = initialChartCurrency;

            // Chart definitions
            let soTopCustomersChart = null;


            // --- FUNGSI RENDER CHART TOP CUSTOMERS ---
            function renderSoTopCustomers(currency) {
                const canvasId = 'chartTopCustomersValueSO';
                if (soTopCustomersChart) Chart.getChart(canvasId)?.destroy?.();
                soTopCustomersChart = null;

                // PENTING: Update currentChartCurrency agar mountCurrencyToggleIfNeeded menggunakan nilai terbaru
                currentChartCurrency = currency;

                // Judul
                const titleEl = document.getElementById(canvasId)?.closest('.card')?.querySelector('.card-title');
                if (titleEl) {
                    titleEl.innerHTML =
                        `<i class="fas fa-crown me-2 text-warning"></i>Top 5 Customers by Value of Overdue Orders Awaiting Packing (${currency})`;
                }

                // Data
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

                const newChart = createHorizontalBarChart(
                    canvasId,
                    data,
                    'total_value',
                    'Value of Overdue Orders',
                    colors,
                    currency
                );
                soTopCustomersChart = newChart;
            }

            // FUNGSI INI AKAN DIPANGGIL DARI initKpiToggles.
            function rerenderSoCurrencyDependentCharts(currency) {
                renderSoTopCustomers(currency);
            }

            // --- INISIALISASI KPI TOGGLE (Memicu render chart pertama) ---
            if (chartData.kpi_new) {
                // Gunakan chartData.kpi_new untuk inisialisasi KPI
                initKpiToggles(rerenderSoCurrencyDependentCharts, initialChartCurrency);
            } else {
                // Fallback untuk render chart saja (jika filter tidak menampilkan KPI detail)
                rerenderSoCurrencyDependentCharts(currentChartCurrency);
            }
            // --- END INISIALISASI KPI TOGGLE ---


            /* Mounting Currency Toggle Chart (memperindah bagian chart) */
            function mountCurrencyToggleIfNeeded() {
                if (!enableCurrencyToggle) return;

                const canvasIds = ['chartTopCustomersValueSO'];

                const makeToggle = () => {
                    const holder = document.createElement('div');
                    holder.className = 'yz-card-toolbar';
                    // PENTING: Toggle Chart diinisialisasi dengan currentChartCurrency
                    holder.innerHTML = `
                             <div class="btn-group btn-group-sm yz-currency-toggle-chart" role="group">
                                 <button type="button" data-cur="USD"
                                 class="btn btn-sm-square ${currentChartCurrency==='USD'?'btn-primary':'btn-outline-primary'}">USD</button>
                                 <button type="button" data-cur="IDR"
                                 class="btn btn-sm-square ${currentChartCurrency==='IDR'?'btn-success':'btn-outline-success'}">IDR</button>
                             </div>
                             `;
                    return holder;
                };

                canvasIds.forEach(canvasId => {
                    const targetCanvas = document.getElementById(canvasId);
                    if (!targetCanvas) return;

                    const card = targetCanvas.closest('.card');
                    const titleEl = card?.querySelector('.card-title');
                    const headerRow = titleEl?.parentElement;

                    if (!card || !headerRow) return;

                    card.style.position = 'relative';
                    // Cek dan hapus toolbar lama jika ada
                    headerRow.querySelector('.yz-card-toolbar')?.remove();

                    const toolbar = makeToggle();
                    headerRow.appendChild(toolbar);
                });


                // LOGIC KLIK UNTUK CHART (untuk klik langsung pada chart toggle)
                document.querySelectorAll('.yz-currency-toggle-chart').forEach(toggleEl => {
                    toggleEl.addEventListener('click', (e) => {
                        const btn = e.target.closest('button[data-cur]');
                        if (!btn) return;
                        const next = btn.dataset.cur;
                        if (next !== 'USD' && next !== 'IDR') return;
                        if (next === currentChartCurrency) return;

                        currentChartCurrency = next;
                        try {
                            localStorage.setItem('soTopCustomerCurrency', currentChartCurrency);
                            // Simpan ke KPI currency juga agar sinkron saat halaman dimuat ulang
                            localStorage.setItem('soKpiCurrency', currentChartCurrency);
                        } catch {}

                        // PENTING: Perbarui tampilan semua elemen
                        rerenderSoCurrencyDependentCharts(currentChartCurrency);
                        updateChartToggleButtons(currentChartCurrency);

                        // Perbarui semua blok KPI
                        const locations = ['smg', 'sby'];
                        locations.forEach(l => updateKpiBlock(l, currentChartCurrency));
                    });
                });
            }

            // Panggil mounting toggle chart
            mountCurrencyToggleIfNeeded();


            /* ======================== ITEM WITH REMARK (INLINE) ======================== */
            function escapeHtml(str = '') {
                return String(str).replace(/[&<>"']/g, s => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                } [s]));
            }

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
                        const item = stripZeros(r.POSNR); // untuk ditampilkan
                        const posnr6 = String(r.POSNR ?? '').trim().padStart(6, '0'); // untuk API/highlight
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
                            highlight_posnr: posnr6, // ***PERBAIKAN: Kirim POSNR 6 digit***
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
                            <td class="text-center">
                            <button type="button"
                                    class="btn btn-sm btn-outline-danger js-del-remark"
                                    title="Hapus remark"
                                    data-vbeln="${so}"
                                    data-posnr="${posnr6}"
                                    data-werks="${werks}"
                                    data-auart="${auart}">
                                <i class="fas fa-trash"></i>
                            </button>
                          </td>
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
                                        <th class="text-center" style="width:70px;">Aksi</th>
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
                    if (ev.target.closest('.js-del-remark')) return;
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

                listBox.addEventListener('click', async (ev) => {
                    const btn = ev.target.closest('.js-del-remark');
                    if (!btn) return;
                    ev.stopPropagation();

                    const vbeln = btn.dataset.vbeln || '';
                    const posnr = btn.dataset.posnr || ''; // Sudah 6 digit padded
                    const werks = btn.dataset.werks || '';
                    const auart = btn.dataset.auart || '';

                    // Konfirmasi
                    const ok = confirm(`Hapus remark untuk SO ${vbeln} / Item ${stripZeros(posnr)}?`);
                    if (!ok) return;

                    // UX: disable sementara
                    btn.disabled = true;

                    try {
                        const res = await fetch(`{{ route('so.api.remark_delete') }}`, {
                            method: 'DELETE',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector(
                                    'meta[name="csrf-token"]').getAttribute('content'),
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({
                                vbeln: vbeln,
                                posnr: posnr,
                                werks: werks,
                                auart: auart,
                            })
                        });

                        const json = await res.json();
                        if (!json.ok) throw new Error(json.error || 'Gagal menghapus remark.');

                        // Reload daftar supaya konsisten
                        await loadList();
                    } catch (e) {
                        alert(e.message || 'Gagal menghapus remark.');
                        btn.disabled = false;
                    }
                });

                loadList();
            })();

        })();
    </script>
@endpush
