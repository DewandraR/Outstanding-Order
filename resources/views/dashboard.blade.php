@extends('layouts.app')

@section('title', 'Dashboard Monitoring PO')

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
            // NOTE: Route 'po.report' harus sudah terdaftar
            return route('po.report', ['q' => \Crypt::encrypt($payload)]);
        };

        // Ambil data KPI baru
        $kpiNew = $chartData['kpi_new'] ?? [];

        // Helper untuk format mata uang
        $formatCurrency = function ($value, $currency, $decimals = 0) {
            $n = (float) $value;
            if ($n == 0) {
                return '–';
            }
            if ($currency === 'IDR') {
                return 'Rp ' . number_format($n, $decimals, ',', '.');
            }
            return '$' . number_format($n, $decimals, '.', ',');
        };

        // Helper untuk format quantity (Count PO)
        $formatQty = function ($value, $decimals = 0) {
            $n = (float) $value;
            if ($n == 0) {
                return '–';
            }
            return number_format($n, $decimals, '.', ',');
        };

        // =========================================================================================
        // HELPER PHP UNTUK RENDER SINGLE KPI BLOCK (PERBAIKAN ICON & KEY)
        // =========================================================================================
        $renderSingleKpiBlock = function ($locationName, $kpiData, $locPrefix, $allMapping) use (
            $formatCurrency,
            $formatQty,
            $encReport,
        ) {
            $werksCode = $locPrefix === 'smg' ? '3000' : '2000';

            // Cari AUART Export dan Lokal untuk plant ini
            $exportAuart = collect($allMapping[$werksCode] ?? [])->first(
                fn($t) => Str::contains(strtolower((string) $t->Deskription), 'export') &&
                    !Str::contains(strtolower((string) $t->Deskription), 'local') &&
                    !Str::contains(strtolower((string) $t->Deskription), 'replace'),
            );

            $localAuart = collect($allMapping[$werksCode] ?? [])->first(
                fn($t) => Str::contains(strtolower((string) $t->Deskription), 'local'),
            );

            $exportAuartCode = $exportAuart ? trim((string) $exportAuart->IV_AUART) : null;
            $localAuartCode = $localAuart ? trim((string) $localAuart->IV_AUART) : null;

            // Buat URL terenkripsi untuk ditaruh di data-attribute link
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
                // Tambahkan URL terenkripsi ke data-attribute link
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
            $overdueColorClass = 'text-danger';
            $qtyColorClass = 'text-info';
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
                                ' .
                // --- OUTSTANDING SECTION TITLE (Icon di sini) ---
                // MENGGUNAKAN KEY YANG VALID DI JSON UNTUK SECTION OUTSTANDING
                '<h6 class="text-uppercase fw-bold ps-3 pt-0 mb-3" style="color: #6c757d;" data-help-key="po.kpi.outstanding_po">' .
                'Outstanding' .
                '</h6>' .
                // -----------------------------------------------------------
                '
                                <div class="col-lg-6 mb-3">
                                    <a href="' .
                $initialUrl .
                '" data-idr-href="' .
                $urlLocal .
                '" data-usd-href="' .
                $urlExport .
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
                '" data-idr-href="' .
                $urlLocal .
                '" data-usd-href="' .
                $urlExport .
                '" class="text-decoration-none kpi-link" id="' .
                $locPrefix .
                '-qty-link">
                                        <div class="d-flex align-items-center yz-kpi-item-inner">
                                            <div class="yz-kpi-icon bg-info-subtle text-info p-2 me-3" 
                                                style="border-radius: 50%; box-shadow: 0 0 10px rgba(13, 202, 240, 0.2);">
                                                <i class="fas fa-box-open"></i>
                                            </div>
                                            <div style="line-height:1.2;">
                                                <div class="mb-0 text-muted small text-uppercase fw-semibold"><span>Total PO</span></div>
                                                <h3 class="mb-0 fw-bolder ' .
                $qtyColorClass .
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
                                ' .
                // --- OVERDUE SECTION TITLE (Icon di sini) ---
                // MENGGUNAKAN KEY YANG VALID DI JSON UNTUK SECTION OVERDUE
                '<h6 class="text-uppercase text-danger fw-bold ps-3 pt-0 mb-3" data-help-key="po.kpi.overdue_po">' .
                'Overdue' .
                '</h6>' .
                // -------------------------------------------------------
                '
                                <div class="col-lg-6 mb-3">
                                    <a href="' .
                $initialUrl .
                '" data-idr-href="' .
                $urlLocal .
                '" data-usd-href="' .
                $urlExport .
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
                                                <h3 class="mb-0 fw-bolder ' .
                $overdueColorClass .
                '" id="' .
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
                '" data-idr-href="' .
                $urlLocal .
                '" data-usd-href="' .
                $urlExport .
                '" class="text-decoration-none kpi-link" id="' .
                $locPrefix .
                '-overdue-qty-link">
                                        <div class="d-flex align-items-center yz-kpi-item-inner">
                                            <div class="yz-kpi-icon bg-danger-subtle text-danger p-2 me-3" 
                                                style="border-radius: 50%; box-shadow: 0 0 10px rgba(220, 53, 69, 0.2);">
                                                <i class="fas fa-hourglass-half"></i>
                                            </div>
                                            <div style="line-height:1.2;">
                                                <div class="mb-0 text-muted small text-uppercase fw-semibold"><span>Total PO</span></div>
                                                <h3 class="mb-0 fw-bolder ' .
                $overdueColorClass .
                '" id="' .
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

    <div id="yz-root" data-show="0" data-werks="{{ $werks ?? '' }}" data-auart="{{ $auart ?? '' }}" style="display:none">
    </div>

    <div id="dashboard-data-holder" data-chart-data='@json($chartData)'
        data-mapping-data='@json($mapping)' data-selected-type='{{ $selectedType }}'
        data-current-view='{{ $curView }}' data-current-location='{{ $selectedLocation ?? '' }}'
        data-current-auart='{{ $auart ?? '' }}' style="display:none;">
    </div>

    {{-- HEADER WITH DROPDOWNS --}}
    <div class="d-flex flex-column flex-lg-row justify-content-lg-between align-items-lg-center mb-3 gap-3">
        <div>
            <h2 class="mb-0 fw-bolder text-primary">Dashboard Overview PO</h2>
            <p class="text-muted mb-0"><i class="fas fa-chart-line me-1"></i> Monitor data Outstanding Value dan Overdue</p>
        </div>
    </div>
    <hr class="mt-0 mb-4 border-primary opacity-25">

    {{-- ==================== DASHBOARD PO CONTENT: KPI BLOCKS ==================== --}}
    <div class="row g-4 mb-4">
        {{-- SEMARANG BLOCK --}}
        {!! $renderSingleKpiBlock('Semarang', $kpiNew, 'smg', $allMapping) !!}

        {{-- SURABAYA BLOCK --}}
        {!! $renderSingleKpiBlock('Surabaya', $kpiNew, 'sby', $allMapping) !!}
    </div>

    <hr class="my-4 border-dashed border-secondary">

    {{-- ==================== CHART TOP CUSTOMERS (Outstanding) ==================== --}}
    <div class="row g-4 mb-4">
        {{-- SEMARANG: Top 4 Customers by Outstanding Value (USD/IDR) --}}
        <div class="col-lg-6">
            <div class="card shadow-lg h-100 yz-chart-card">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title text-primary-emphasis yz-card-header-pad-top"
                        data-help-key="po.top_customers_value_usd_smg">
                        <i class="fas fa-crown me-2 text-warning"></i>Top 4 Customers Outstanding Value -
                        <strong>Semarang</strong>
                    </h5>
                    <hr class="mt-2 mb-3">
                    <div class="chart-container flex-grow-1">
                        <canvas id="chartTopCustomersValue_smg"></canvas>
                    </div>
                </div>
            </div>
        </div>
        {{-- SURABAYA: Top 4 Customers by Outstanding Value (USD/IDR) --}}
        <div class="col-lg-6">
            <div class="card shadow-lg h-100 yz-chart-card">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title text-primary-emphasis yz-card-header-pad-top"
                        data-help-key="po.top_customers_value_usd_sby">
                        <i class="fas fa-crown me-2 text-warning"></i>Top 4 Customers Outstanding Value -
                        <strong>Surabaya</strong>
                    </h5>
                    <hr class="mt-2 mb-3">
                    <div class="chart-container flex-grow-1">
                        <canvas id="chartTopCustomersValue_sby"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ==================== CHART TOP CUSTOMERS (Overdue) ==================== --}}
    <div class="row g-4 mb-4">
        {{-- SEMARANG: Top 4 Customers with Most Overdue PO --}}
        <div class="col-lg-6">
            <div class="card shadow-lg h-100 yz-chart-card">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title text-danger-emphasis yz-card-header-pad-top"
                        data-help-key="po.top_customers_overdue_smg">
                        <i class="fas fa-triangle-exclamation me-2 text-danger"></i>Top 4 Overdue Customers -
                        <strong>Semarang</strong>
                    </h5>
                    <hr class="mt-2 mb-3">
                    <div class="chart-container flex-grow-1">
                        <canvas id="chartTopOverdueCustomers_smg"></canvas>
                    </div>
                </div>
            </div>
        </div>
        {{-- SURABAYA: Top 4 Customers with Most Overdue PO --}}
        <div class="col-lg-6">
            <div class="card shadow-lg h-100 yz-chart-card">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title text-danger-emphasis yz-card-header-pad-top"
                        data-help-key="po.top_customers_overdue_sby">
                        <i class="fas fa-triangle-exclamation me-2 text-danger"></i>Top 4 Overdue Customers -
                        <strong>Surabaya</strong>
                    </h5>
                    <hr class="mt-2 mb-3">
                    <div class="chart-container flex-grow-1">
                        <canvas id="chartTopOverdueCustomers_sby"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ==================== PO: ITEMS WITH REMARK (INLINE) ==================== --}}
    <div class="row g-4 mb-4">
        <div class="col-lg-12">
            <div class="card yz-card shadow-sm h-100" id="po-remark-inline-container">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="card-title" data-help-key="po.items_with_remark">
                            <i class="fas fa-sticky-note me-2"></i>PO Item with Remark
                        </h5>
                    </div>
                    <hr class="mt-2">
                    <div id="po-remark-list-box-inline" class="flex-grow-1">
                        <div class="text-center text-muted py-4">
                            <div class="spinner-border spinner-border-sm me-2"></div> Loading data...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard-style.css') }}">
    <style>
        /* ========================================================= */
        /* Custom Styles untuk Tampilan Baru */
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

        .yz-card-toolbar {
            top: 50%;
            transform: translateY(-50%);
            right: 1rem;
        }

        .yz-card-toolbar .btn-sm-square {
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
    </style>
@endpush

@push('scripts')
    <script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
    <script src="{{ asset('vendor/chartjs/chartjs-adapter-date-fns.bundle.min.js') }}"></script>

    <script>
        // Set Default Chart Font/Style
        Chart.defaults.font.family = 'Inter, sans-serif';
        Chart.defaults.plugins.legend.position = 'bottom';
        Chart.defaults.responsive = true;
        Chart.defaults.maintainAspectRatio = false;

        /* =========================================================
           HELPER UMUM (DARI KODE ASLI)
           ======================================================== */
        const formatFullCurrency = (value, currency) => {
            const n = parseFloat(value);
            if (isNaN(n) || n === 0) return '–';
            if (currency === 'IDR') {
                return new Intl.NumberFormat('id-ID', {
                    style: 'currency',
                    currency: 'IDR',
                    minimumFractionDigits: 0,
                    maximumFractionDigits: 0
                }).format(n).replace('IDR', 'Rp');
            }
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD',
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
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

            if (/\((USD|IDR)\)/.test(raw)) {
                tn.textContent = raw.replace(/\((USD|IDR)\)/, `(${currency})`);
            } else {
                tn.textContent = `${raw.trim()} (${currency})`;
            }
        }

        function preventInfoButtonPropagation() {
            // Fungsi ini mencegah klik pada ikon (i) memicu link <a> di luarnya.
            const infoButtons = document.querySelectorAll('.yz-info-icon');
            infoButtons.forEach(btn => {
                if (btn.dataset.clickBound === '1') return;

                // MENGHENTIKAN PENYEBARAN EVENT KLIK
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

        const formatLocations = (locsString) => {
            if (!locsString) return '';
            const hasSemarang = locsString.includes('3000');
            const hasSurabaya = locsString.includes('2000');
            if (hasSemarang && hasSurabaya) return 'Semarang & Surabaya';
            if (hasSemarang) return 'Semarang';
            if (hasSurabaya) return 'Surabaya';
            return '';
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
                // Logika PO (Outstanding Value menggunakan currency, Overdue menggunakan count)
                const isOverdueChart = canvasId.startsWith('chartTopOverdueCustomers');
                const countText = isOverdueChart ? ` (${d.overdue_count} PO)` : ` (${d.so_count} PO)`;

                return `${customerName}${countText}`;
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
                                title: (items) => items[0].label.split('(')[0].trim(),
                                label: (context) => {
                                    const isOverdueChart = canvasId.startsWith('chartTopOverdueCustomers');

                                    if (!isOverdueChart && currency) {
                                        return formatFullCurrency(context.raw, currency);
                                    }

                                    if (isOverdueChart) {
                                        return `${context.raw} PO Overdue`;
                                    }

                                    return `${context.raw}`;
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
                                        if (currency && !canvasId.startsWith('chartTopOverdueCustomers')) {
                                            let formatted = new Intl.NumberFormat('id-ID', {
                                                minimumFractionDigits: 0,
                                                maximumFractionDigits: 0
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
            const overdueColorClass = 'text-danger'; // Tetap merah
            const qtyColorClass = 'text-info'; // Tetap biru

            // Target elemen
            const valEl = document.getElementById(`${locPrefix}-outstanding-value`);
            const qtyEl = document.getElementById(`${locPrefix}-outstanding-qty`);
            const overdueValEl = document.getElementById(`${locPrefix}-overdue-value`);
            const overdueQtyEl = document.getElementById(`${locPrefix}-overdue-qty`);

            // Target Link
            const links = [
                document.getElementById(`${locPrefix}-outs-link`),
                document.getElementById(`${locPrefix}-qty-link`),
                document.getElementById(`${locPrefix}-overdue-val-link`),
                document.getElementById(`${locPrefix}-overdue-qty-link`),
            ].filter(el => el);


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
         * Menginisialisasi tombol toggle KPI dan event listener-nya.
         */
        function initKpiToggles(chartCurrencyFunction, initialChartCurrency) {
            const locations = ['smg', 'sby'];

            let savedKpiCur = initialChartCurrency;
            try {
                const saved = localStorage.getItem('poKpiCurrency');
                if (saved === 'USD' || saved === 'IDR') savedKpiCur = saved;
            } catch {}

            let currentKpiCurrency = savedKpiCur;

            // 1. Terapkan nilai awal KPI dan panggil chart render
            locations.forEach(loc => {
                updateKpiBlock(loc, currentKpiCurrency);
            });

            // Panggil render chart pertama kali (menggunakan currency KPI)
            if (typeof chartCurrencyFunction === 'function') {
                chartCurrencyFunction(currentKpiCurrency);
            }

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
                        localStorage.setItem('poKpiCurrency', currentKpiCurrency);
                        // Simpan ke chart currency juga agar sinkron saat halaman dimuat ulang
                        localStorage.setItem('poCurrency', currentKpiCurrency);
                    } catch {}

                    // Perbarui semua blok KPI dan link
                    locations.forEach(l => updateKpiBlock(l, currentKpiCurrency));

                    // Perbarui chart Top Customers 
                    if (typeof chartCurrencyFunction === 'function') {
                        chartCurrencyFunction(currentKpiCurrency);
                    }
                });
            });

            return currentKpiCurrency;
        }

        /* =========================================================
           LOGIC DASHBOARD UTAMA (DARI KODE ASLI)
           ======================================================== */
        (() => {

            const dataHolder = document.getElementById('dashboard-data-holder');
            if (!dataHolder) return;
            const chartData = JSON.parse(dataHolder.dataset.chartData);

            // --- DEKLARASI CHART & HELPER PENGELOLAAN CHART ---
            const __charts = {
                topCustomers_smg: null,
                topCustomers_sby: null,
                chartTopOverdueCustomers_smg: null,
                chartTopOverdueCustomers_sby: null,
            };
            const __destroy = (k) => {
                try {
                    Chart.getChart(k)?.destroy?.();
                } catch {}
                __charts[k] = null;
            };

            // --- LOGIC PENENTU MATA UANG CHART AWAL ---
            const filterState = {
                location: dataHolder.dataset.currentLocation || null,
                type: dataHolder.dataset.selectedType || null,
                auart: dataHolder.dataset.currentAuart || null,
            };
            const hasTypeFilter = !!filterState.type;
            const enableCurrencyToggle = (!hasTypeFilter);

            // Tentukan mata uang chart default/initial
            let initialChartCurrency = (dataHolder.dataset.selectedType === 'lokal') ? 'IDR' : 'USD';
            if (enableCurrencyToggle) {
                try {
                    const saved = localStorage.getItem('poCurrency');
                    if (saved === 'USD' || saved === 'IDR') initialChartCurrency = saved;
                } catch {}
            }

            let currentChartCurrency = initialChartCurrency;

            // --- FUNGSI RENDER CHART TOP CUSTOMERS ---
            function renderTopCustomersByCurrency(currency) {
                const locations = ['smg', 'sby'];
                currentChartCurrency = currency; // Simpan currency yang sedang aktif untuk chart

                locations.forEach(loc => {
                    const canvasId = `chartTopCustomersValue_${loc}`;
                    setTitleCurrencySuffixByCanvas(canvasId, currency);
                    __destroy(canvasId);

                    // Key data di Controller adalah: top_customers_value_[usd/idr]_[smg/sby]
                    const dsKey = (currency === 'IDR') ? `top_customers_value_idr_${loc}` :
                        `top_customers_value_usd_${loc}`;
                    const ds = chartData[dsKey];

                    const colors = (currency === 'IDR') ? {
                        bg: 'rgba(25, 135, 84, 0.8)',
                        border: 'rgba(25, 135, 84, 1)'
                    } : {
                        bg: 'rgba(13, 110, 253, 0.8)',
                        border: 'rgba(13, 110, 253, 1)'
                    };

                    const canvas = document.getElementById(canvasId);
                    if (canvas) {
                        const newChart = createHorizontalBarChart(
                            canvasId,
                            ds,
                            'total_value',
                            'Total Outstanding',
                            colors,
                            currency
                        );
                        __charts[`topCustomers_${loc}`] = newChart;
                    }
                });

                // PENGATURAN TOMBOL TOGGLE CHART: Pastikan tombol di kedua chart terupdate
                document.querySelectorAll('.yz-currency-toggle-chart button[data-cur]').forEach(b => {
                    const v = b.dataset.cur;
                    // Menggunakan warna yang sama untuk konsistensi
                    b.classList.toggle('btn-primary', v === 'USD' && currency === 'USD');
                    b.classList.toggle('btn-outline-primary', v === 'USD' && currency !== 'USD');
                    b.classList.toggle('btn-success', v === 'IDR' && currency === 'IDR');
                    b.classList.toggle('btn-outline-success', v === 'IDR' && currency !== 'IDR');
                });
            }
            // --- END FUNGSI RENDER CHART TOP CUSTOMERS ---

            // --- RENDER CHART OVERDUE (Ini tetap berjalan karena tidak tergantung currency toggle) ---
            __charts.chartTopOverdueCustomers_smg = createHorizontalBarChart(
                'chartTopOverdueCustomers_smg',
                chartData.top_customers_overdue_smg,
                'overdue_count',
                'Jumlah PO Terlambat', {
                    bg: 'rgba(220, 53, 69, 0.8)',
                    border: 'rgba(220, 53, 69, 1)'
                }
            );

            __charts.chartTopOverdueCustomers_sby = createHorizontalBarChart(
                'chartTopOverdueCustomers_sby',
                chartData.top_customers_overdue_sby,
                'overdue_count',
                'Jumlah PO Terlambat', {
                    bg: 'rgba(220, 53, 69, 0.8)',
                    border: 'rgba(220, 53, 69, 1)'
                }
            );
            // --- END RENDER CHART OVERDUE ---

            // --- INISIALISASI KPI TOGGLE (Memicu render chart pertama) ---
            if (chartData.kpi_new) {
                // Inisialisasi KPI. initialChartCurrency digunakan sebagai currency awal KPI.
                initKpiToggles(renderTopCustomersByCurrency, initialChartCurrency);
            }

            /* Mounting Currency Toggle Chart (memperindah bagian chart) */
            function mountCurrencyToggleIfNeeded() {
                if (!enableCurrencyToggle) return;

                const canvasIds = ['chartTopCustomersValue_smg', 'chartTopCustomersValue_sby'];

                const makeToggle = () => {
                    const holder = document.createElement('div');
                    holder.className = 'yz-card-toolbar';
                    // Menggunakan tombol yang lebih kecil
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
                    headerRow.querySelector('.yz-card-toolbar')?.remove();

                    const toolbar = makeToggle();
                    headerRow.appendChild(toolbar);
                });


                // LOGIC KLIK UNTUK CHART
                document.querySelectorAll('.yz-currency-toggle-chart').forEach(toggleEl => {
                    toggleEl.addEventListener('click', (e) => {
                        const btn = e.target.closest('button[data-cur]');
                        if (!btn) return;
                        const next = btn.dataset.cur;
                        if (next !== 'USD' && next !== 'IDR') return;
                        if (next === currentChartCurrency) return;

                        // Simpan preferensi chart terpisah dari KPI
                        currentChartCurrency = next;
                        try {
                            localStorage.setItem('poCurrency', currentChartCurrency);
                        } catch {}

                        // RENDER KEDUA CHART dengan mata uang baru
                        renderTopCustomersByCurrency(currentChartCurrency);
                    });
                });
            }

            // Panggil mounting toggle chart
            mountCurrencyToggleIfNeeded();


            // Panggil fungsi click prevention setelah DOM dimuat
            document.addEventListener('DOMContentLoaded', function() {
                preventInfoButtonPropagation();
                const intervalId = setInterval(() => {
                    if (!document.querySelector('.yz-info-icon:not([data-click-bound="1"])')) {
                        clearInterval(intervalId);
                        return;
                    }
                    preventInfoButtonPropagation();
                }, 500);
                setTimeout(() => clearInterval(intervalId), 5000);
            });

        })();
    </script>
    <script>
        /* ======================== PO: ITEM WITH REMARK (INLINE) ======================== */
        (function poItemWithRemarkTableOnly() {
            const apiRemarkItems = "{{ route('po.api.remark_items') }}";
            const apiRemarkDelete = "{{ route('po.api.remark_delete') }}";
            const listBox = document.getElementById('po-remark-list-box-inline');
            if (!listBox) return;

            const dataHolder = document.getElementById('dashboard-data-holder');
            const currentLocation = dataHolder?.dataset.currentLocation || null; // '2000' | '3000' | ''
            const currentType = dataHolder?.dataset.selectedType || null; // 'lokal'|'export'|''

            const mappingData = JSON.parse(dataHolder.dataset.mappingData || '{}');
            const auartMap = {};
            if (mappingData) {
                for (const werks in mappingData) {
                    (mappingData[werks] || []).forEach(item => {
                        auartMap[item.IV_AUART] = item.Deskription;
                    });
                }
            }

            const stripZeros = v => {
                const s = String(v ?? '').trim();
                if (!s) return '';
                const z = s.replace(/^0+/, '');
                return z.length ? z : '0';
            };
            const escapeHtml = (str = '') => String(str).replace(/[&<>"']/g, s => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [s]));

            const plantName = w => ({
                '2000': 'Surabaya',
                '3000': 'Semarang'
            } [String(w || '').trim()] || (w ?? ''));
            const auartDesc = auartMap;

            function buildTable(rows) {
                if (!rows?.length) {
                    return `<div class="text-center text-muted py-4"><i class="fas fa-info-circle me-2"></i>Tidak ada item dengan remark.</div>`;
                }

                const body = rows.map((r, i) => {
                    const posnrDisp = stripZeros(r.POSNR);
                    const posnr6 = String(r.POSNR ?? '').trim().padStart(6, '0');
                    const so = (r.VBELN || '').trim();
                    const po = (r.BSTNK || '').trim();
                    const werks = (r.IV_WERKS_PARAM || '').trim();
                    const auart = String(r.IV_AUART_PARAM || '').trim();
                    const plant = plantName(werks);
                    const otName = auartDesc[auart] || auart || '-';
                    const kunnr = (r.KUNNR || '').trim();

                    // Payload untuk redirect ke PO Report
                    const postData = {
                        redirect_to: 'po.report',
                        werks: werks,
                        auart: auart,
                        compact: 1,
                        highlight_kunnr: kunnr,
                        highlight_vbeln: so,
                        highlight_posnr: posnr6,
                        auto_expand: '1'
                    };

                    return `
            <tr class="js-remark-row" data-payload='${JSON.stringify(postData)}' style="cursor:pointer;" title="Klik untuk melihat laporan PO">
                <td class="text-center">${i + 1}</td>
                <td class="text-center">${po || '-'}</td>
                <td class="text-center">${so || '-'}</td>
                <td class="text-center">${posnrDisp || '-'}</td>
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
                        <th class="text-center" style="min-width:120px;">PO</th>
                        <th class="text-center" style="min-width:110px;">SO</th>
                        <th class="text-center" style="min-width:80px;">Item</th>
                        <th class="text-center" style="min-width:110px;">Plant</th>
                        <th class="text-center" style="min-width:160px;">Order Type</th>
                        <th style="min-width:240px;">Remark</th>
                        <th class="text-center" style="width:70px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>${body}</tbody>
            </table>
        </div>
        <div class="small text-muted mt-2">Klik baris untuk membuka laporan PO terkait.</div>`;
            }

            async function loadList() {
                const card = document.getElementById('po-remark-inline-container');
                if (card) card.style.display = '';
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

            // Klik baris => buka PO Report (via redirector)
            listBox.addEventListener('click', (ev) => {
                if (ev.target.closest('.js-del-remark')) return;
                const tr = ev.target.closest('.js-remark-row');
                if (!tr?.dataset.payload) return;

                const rowData = JSON.parse(tr.dataset.payload);
                const postData = {
                    ...rowData,
                    redirect_to: 'po.report',
                    compact: 1,
                    auto_expand: '1'
                };

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = "{{ route('dashboard.redirector') }}";

                const csrf = document.createElement('input');
                csrf.type = 'hidden';
                csrf.name = '_token';
                csrf.value = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                form.appendChild(csrf);

                const payload = document.createElement('input');
                payload.type = 'hidden';
                payload.name = 'payload';
                payload.value = JSON.stringify(postData);
                form.appendChild(payload);

                document.body.appendChild(form);
                form.submit();
            });

            // Hapus remark
            listBox.addEventListener('click', async (ev) => {
                const btn = ev.target.closest('.js-del-remark');
                if (!btn) return;
                ev.stopPropagation();

                const vbeln = btn.dataset.vbeln || '';
                const posnr = btn.dataset.posnr || ''; // sudah 6 digit
                const werks = btn.dataset.werks || '';
                const auart = btn.dataset.auart || '';

                const ok = confirm(`Hapus remark untuk SO ${vbeln} / Item ${stripZeros(posnr)}?`);
                if (!ok) return;

                btn.disabled = true;
                try {
                    const res = await fetch(apiRemarkDelete, {
                        method: 'DELETE',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                                .getAttribute('content'),
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            vbeln,
                            posnr,
                            werks,
                            auart
                        })
                    });
                    const json = await res.json();
                    if (!json.ok) throw new Error(json.error || 'Gagal menghapus remark.');
                    await loadList();
                } catch (e) {
                    alert(e.message || 'Gagal menghapus remark.');
                    btn.disabled = false;
                }
            });

            // mulai muat
            loadList();
        })();
    </script>
@endpush
