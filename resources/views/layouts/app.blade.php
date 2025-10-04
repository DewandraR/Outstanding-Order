<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name', 'Laravel'))</title>

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="stylesheet" href="{{ asset('vendor/fonts/poppins/poppins.css') }}">
    <link href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}" />
    <link rel="stylesheet" href="{{ asset('css/app-layout.css') }}">

    @stack('styles')
</head>

<body>
    {{-- =========================
         SIDEBAR
    ========================== --}}
    <aside class="sidebar d-flex flex-column min-vh-100">
        {{-- HEADER BRANDING --}}
        <a href="{{ route('dashboard') }}" class="sidebar-header">
            <img src="{{ asset('images/KMI.png') }}" alt="Logo KMI" class="sidebar-logo">
            <div class="sidebar-title-wrapper">
                <h5 class="sidebar-app-name">{{ config('app.name', 'Laravel') }}</h5>
                <span class="sidebar-app-subtitle">PT Kayu Mabel Indonesia</span>
            </div>
        </a>

        {{-- AREA NAV --}}
        <div class="flex-grow-1 d-flex flex-column overflow-auto">
            @php
                use Illuminate\Support\Facades\Crypt;
                $decryptedQ = [];
            @endphp

            @if (request()->has('q'))
                @php
                    $decryptedQ = Crypt::decrypt(request('q'));
                @endphp
            @endif

            @php
                // Status route aktif
                $isDashboard = request()->routeIs('dashboard'); // PO Dashboard
                $isSoDashboard = request()->routeIs('so.dashboard'); // SO Dashboard (baru)
                $isPoReportRoute = request()->routeIs('po.report'); // PO Report (mode tabel)
                $isSoRoute = request()->routeIs('so.index'); // SO Report lama (SalesOrderController)
                $isStockRoute = request()->routeIs('stock.index');

                // Penentuan "view" untuk PO dashboard lama (dibiarkan, tapi tak lagi dipakai untuk toggle SO)
                $view = $decryptedQ['view'] ?? request('view');
                $curViewSidebar = $isDashboard ? $view ?? 'po' : null;

                $locationMap = ['2000' => 'Surabaya', '3000' => 'Semarang'];

                // Aktif plant pada PO report
                $activeReportPlant = $isPoReportRoute && request()->filled('werks');
                // Aktif plant pada SO report (lama)
                $activeSoReportPlant = $isSoRoute && request()->filled('werks');
            @endphp

            <ul class="sidebar-nav">
                {{-- Pencarian --}}
                <li class="nav-item px-2 mt-2 mb-2">
                    <form action="{{ route('dashboard.search') }}" method="POST" class="sidebar-search-form">
                        @csrf
                        <div class="input-group">
                            <input type="text" class="form-control" name="term" placeholder="Search PO / SO No..."
                                required value="{{ request('term') ?? ($decryptedQ['search_term'] ?? '') }}"
                                aria-label="Search SO or PO Number">
                            <button class="btn btn-search" type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </form>
                </li>

                {{-- Dashboard View --}}
                <li class="nav-heading">Dashboard View</li>

                {{-- PO Dashboard --}}
                <li class="nav-item">
                    <a class="nav-link {{ $isDashboard ? 'active' : '' }}" href="{{ route('dashboard') }}">
                        <i class="fas fa-chart-line nav-icon"></i> Outstanding PO
                    </a>
                </li>

                {{-- SO Dashboard (BARU, route terpisah) --}}
                <li class="nav-item">
                    <a class="nav-link {{ $isSoDashboard ? 'active' : '' }}" href="{{ route('so.dashboard') }}">
                        <i class="fas fa-chart-pie nav-icon"></i> Outstanding SO
                    </a>
                </li>

                {{-- (BARU) Link ke Dasbor Stok --}}
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('stock.dashboard') ? 'active' : '' }}"
                        href="{{ route('stock.dashboard') }}">
                        <i class="fas fa-warehouse nav-icon"></i> Stock Dashboard
                    </a>
                </li>

                {{-- Report --}}
                <li class="nav-heading">Report</li>

                {{-- OUTSTANDING PO: Report Mode --}}
                <li class="nav-item">
                    <a class="nav-link {{ $activeReportPlant ? '' : 'collapsed' }}" href="#submenu-outstanding"
                        data-bs-toggle="collapse" role="button"
                        aria-expanded="{{ $activeReportPlant ? 'true' : 'false' }}"
                        aria-controls="submenu-outstanding">
                        <i class="fas fa-file-invoice nav-icon"></i> <span>Outstanding PO</span>
                    </a>
                    <div class="collapse {{ $activeReportPlant ? 'show' : '' }}" id="submenu-outstanding">
                        <ul class="nav flex-column">
                            @isset($mapping)
                                @foreach ($mapping as $werks => $auarts)
                                    @php
                                        $locationName = $locationMap[$werks] ?? $werks;
                                        $isActivePlant = $isPoReportRoute && request('werks') == $werks;
                                    @endphp
                                    <li class="nav-item">
                                        <a class="nav-link {{ $isActivePlant ? 'active' : '' }}"
                                            href="{{ route('po.report', ['werks' => $werks]) }}">
                                            <i class="fas fa-map-marker-alt nav-icon"></i>
                                            <div>{{ $locationName }}</div>
                                        </a>
                                    </li>
                                @endforeach
                            @endisset
                        </ul>
                    </div>
                </li>

                {{-- OUTSTANDING SO: Report Mode (lama, tetap pakai SalesOrderController) --}}
                <li class="nav-item">
                    <a class="nav-link {{ $isSoRoute && request()->filled('werks') ? '' : 'collapsed' }}"
                        href="#submenu-outstanding-so" data-bs-toggle="collapse" role="button"
                        aria-expanded="{{ $isSoRoute && request()->filled('werks') ? 'true' : 'false' }}"
                        aria-controls="submenu-outstanding-so">
                        <i class="fas fa-file-alt nav-icon"></i> <span>Outstanding SO</span>
                    </a>
                    <div class="collapse {{ $isSoRoute && request()->filled('werks') ? 'show' : '' }}"
                        id="submenu-outstanding-so">
                        <ul class="nav flex-column">
                            @isset($mapping)
                                @foreach ($mapping as $werks => $auarts)
                                    @php
                                        $locationName = $locationMap[$werks] ?? $werks;
                                        $activePlant = $isSoRoute && request('werks') == $werks;
                                    @endphp
                                    <li class="nav-item">
                                        <a class="nav-link {{ $activePlant ? 'active' : '' }}"
                                            href="{{ route('so.index', ['werks' => $werks]) }}">
                                            <i class="fas fa-map-marker-alt nav-icon"></i>
                                            <div>{{ $locationName }}</div>
                                        </a>
                                    </li>
                                @endforeach
                            @endisset
                        </ul>
                    </div>
                </li>

                {{-- Menu Lama: Laporan Stok (Detail) --}}
                <li class="nav-item">
                    <a class="nav-link {{ $isStockRoute ? '' : 'collapsed' }}" href="#submenu-stock"
                        data-bs-toggle="collapse" aria-expanded="{{ $isStockRoute ? 'true' : 'false' }}"
                        aria-controls="submenu-stock">
                        <i class="fas fa-clipboard-list fa-fw me-3"></i><span class="align-middle">Stock Report</span>
                    </a>
                    <div id="submenu-stock" class="collapse {{ $isStockRoute ? 'show' : '' }}">
                        <ul class="nav flex-column ms-3">
                            <li class="nav-item">
                                <a class="nav-link {{ request('werks') == '2000' && $isStockRoute ? 'active' : '' }}"
                                    href="{{ route('stock.index', ['werks' => '2000']) }}">
                                    <i class="fas fa-map-marker-alt fa-fw me-2"></i> Surabaya
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link {{ request('werks') == '3000' && $isStockRoute ? 'active' : '' }}"
                                    href="{{ route('stock.index', ['werks' => '3000']) }}">
                                    <i class="fas fa-map-marker-alt fa-fw me-2"></i> Semarang
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>
            </ul>

            {{-- PROFIL USER --}}
            <div class="user-profile mt-auto pt-2">
                <div class="dropdown dropup w-100">
                    <button class="btn w-100 d-flex align-items-center justify-content-between" type="button"
                        id="userMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="d-flex align-items-center">
                            <i class="fas fa-user-circle fa-lg me-2"></i>
                            <span class="user-info text-start">
                                <span class="user-name d-block">{{ Auth::user()->name ?? 'Guest' }}</span>
                                <span class="user-status d-block">Online</span>
                            </span>
                        </span>
                        <i class="fas fa-chevron-up ms-2"></i>
                    </button>
                    <ul class="dropdown-menu" aria-labelledby="userMenuButton">
                        <li><a class="dropdown-item" href="{{ route('profile.edit') }}"><i
                                    class="fas fa-user-edit"></i> Profile</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item">
                                    <i class="fas fa-sign-out-alt"></i> Sign Out
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>

        </div> {{-- /flex-grow wrapper --}}
    </aside>

    {{-- =========================
         MAIN AREA
    ========================== --}}
    <div class="sidebar-overlay"></div>

    <div class="main-wrapper">
        <header class="mobile-header justify-content-between align-items-center">
            <button class="btn btn-link text-white fs-4" id="sidebar-toggler"><i class="fas fa-bars"></i></button>
            <span class="app-name-mobile">{{ config('app.name', 'Laravel') }}</span>
        </header>

        <main class="main-content">
            <div class="container-fluid flex-grow-1 p-3 p-lg-4">
                @if (session('status'))
                    <div class="alert alert-info">{{ session('status') }}</div>
                @endif
                @if (session('ok'))
                    <div class="alert alert-success">{{ session('ok') }}</div>
                @endif
                @if ($errors->any())
                    <div class="alert alert-danger">
                        <div class="fw-bold mb-1">Terjadi kesalahan:</div>
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @yield('content')
            </div>

            <footer>
                <strong>PT Kayu Mabel Indonesia</strong> &copy; 2025
            </footer>
        </main>
    </div>

    <script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarToggler = document.getElementById('sidebar-toggler');
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            if (sidebarToggler && sidebar && overlay) {
                const toggleSidebar = () => sidebar.classList.toggle('is-open');
                sidebarToggler.addEventListener('click', toggleSidebar);
                overlay.addEventListener('click', toggleSidebar);
            }
        });
    </script>

    <script src="{{ asset('js/chart-help.js') }}" data-json="{{ asset('chart-help.json') }}" defer></script>
    @if (session()->has('js_script'))
        <script>
            // Ambil script dari flash session
            const script = @json(session('js_script'));

            if (script) {
                try {
                    // Jalankan string script sebagai kode JavaScript
                    eval(script);
                    console.log('Pembersihan client-side (session storage/cookies) berhasil dijalankan.');
                } catch (e) {
                    console.error('Error saat menjalankan script pembersihan:', e);
                }
            }
        </script>
    @endif
    @stack('scripts')
</body>

</html>
