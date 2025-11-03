<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name', 'Laravel'))</title>

    {{-- CSRF Token for form/ajax --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Fonts & CSS vendor --}}
    {{-- PASTIKAN path aset ini benar di project Laravel Anda --}}
    <link rel="stylesheet" href="{{ asset('vendor/fonts/poppins/poppins.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
    {{-- Main layout style --}}
    <link rel="stylesheet" href="{{ asset('css/app-layout.css') }}">

    @stack('styles')
</head>

<body>
    {{-- ========================= SIDEBAR ========================== --}}
    <aside class="sidebar d-flex flex-column min-vh-100">
        {{-- HEADER BRANDING --}}
        <a href="{{ route('dashboard') }}" class="sidebar-header">
            <img src="{{ asset('Images/KMI.png') }}" alt="Logo KMI" class="sidebar-logo">
            <div class="sidebar-title-wrapper">
                <h5 class="sidebar-app-name">{{ config('app.name', 'Laravel') }}</h5>
                <span class="sidebar-app-subtitle">PT Kayu Mabel Indonesia</span>
            </div>
        </a>

        {{-- NAV AREA --}}
        <div class="flex-grow-1 d-flex flex-column overflow-auto">
            @php
                use Illuminate\Support\Facades\Crypt;

                $decryptedQ = [];
                if (request()->has('q')) {
                    try {
                        $decryptedQ = Crypt::decrypt(request('q'));
                    } catch (\Throwable $e) {
                        $decryptedQ = [];
                    }
                }

                // --- LOGIKA AKTIVASI SIDEBAR ---
                // Aktif jika di Dashboard ATAU Report Detail PO
                $isPoActive = request()->routeIs('dashboard') || request()->routeIs('po.report');

                // Aktif jika di Dashboard ATAU Report Detail SO
                $isSoActive = request()->routeIs('so.dashboard') || request()->routeIs('so.index');

                // Aktif jika di Stock Dashboard, Stock Report, atau Stock Issue
                $isStockActive =
                    request()->routeIs('stock.dashboard') ||
                    request()->routeIs('stock.index') ||
                    request()->routeIs('stock.issue');

                // ====== TARGET SEARCH BERDASARKAN KONTEN AKTIF ======
                // Jika SO aktif -> 'so'; jika Stock aktif -> 'stock'; selain itu default 'po'
                $searchTarget = $isSoActive ? 'so' : ($isStockActive ? 'stock' : 'po');
            @endphp

            {{-- NAV & SEARCH only for logged-in user --}}
            @auth
                <ul class="sidebar-nav">
                    {{-- Search --}}
                    <li class="nav-item px-2 mt-2 mb-2">
                        {{-- Route search dashboard --}}
                        <form action="{{ route('dashboard.search') }}" method="POST" class="sidebar-search-form"
                            id="sidebar-global-search">
                            @csrf
                            <div class="input-group">
                                <input type="text" class="form-control" name="term" placeholder="Search PO / SO No..."
                                    required value="{{ request('term') ?? ($decryptedQ['search_term'] ?? '') }}"
                                    aria-label="Search SO or PO Number">
                                {{-- HIDDEN: target dikirim ke controller untuk menentukan report tujuan --}}
                                <input type="hidden" name="target" id="search-target" value="{{ $searchTarget }}">
                                <button class="btn btn-search" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </form>
                    </li>

                    {{-- Dashboard View --}}
                    <li class="nav-heading">Dashboard View</li>

                    {{-- PO Dashboard (visual) & Report --}}
                    <li class="nav-item">
                        <a class="nav-link {{ $isPoActive ? 'active' : '' }}" href="{{ route('dashboard') }}"
                            data-report-target="po">
                            <i class="fas fa-chart-line nav-icon"></i> Outstanding PO
                        </a>
                    </li>

                    {{-- SO Dashboard (visual) & Report --}}
                    <li class="nav-item">
                        <a class="nav-link {{ $isSoActive ? 'active' : '' }}" href="{{ route('so.dashboard') }}"
                            data-report-target="so">
                            <i class="fas fa-chart-pie nav-icon"></i> Outstanding SO
                        </a>
                    </li>

                    {{-- Stock Dashboard & Report (Termasuk Stock Issue) --}}
                    <li class="nav-item">
                        <a class="nav-link {{ $isStockActive ? 'active' : '' }}" href="{{ route('stock.dashboard') }}"
                            data-report-target="stock">
                            <i class="fas fa-warehouse nav-icon"></i> Stock Dashboard
                        </a>
                    </li>
                </ul>
            @endauth

            {{-- AUTH AREA (bottom) --}}
            <div class="user-profile mt-auto pt-2 px-2">
                {{-- Guest: Sign In button (+optional Register if available) --}}
                @guest
                    <div class="p-3">
                        <a class="btn btn-success w-100 mb-2" href="{{ route('login') }}">
                            <i class="fas fa-sign-in-alt me-2"></i> Sign In
                        </a>
                        @if (Route::has('register'))
                            <a class="btn btn-outline-light w-100" href="{{ route('register') }}">
                                <i class="fas fa-user-plus me-2"></i> Register
                            </a>
                        @endif
                    </div>
                @endguest

                {{-- Logged-in user: dropdown + logout --}}
                @auth
                    <div class="dropdown dropup w-100">
                        <button class="btn w-100 d-flex align-items-center justify-content-between" type="button"
                            id="userMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="d-flex align-items-center">
                                <i class="fas fa-user-circle fa-lg me-2"></i>
                                <span class="user-info text-start">
                                    <span class="user-name d-block">{{ Auth::user()->name }}</span>
                                    <span class="user-status d-block">Online</span>
                                </span>
                            </span>
                            <i class="fas fa-chevron-up ms-2"></i>
                        </button>
                        <ul class="dropdown-menu" aria-labelledby="userMenuButton">
                            {{-- PROFILE LINK --}}
                            <li>
                                <a class="dropdown-item" href="{{ route('profile.edit') }}">
                                    <i class="fas fa-user-edit"></i> Profile
                                </a>
                            </li>
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
                @endauth
            </div>

        </div> {{-- /flex-grow wrapper --}}

    </aside>

    {{-- ========================= MAIN AREA ========================== --}}
    <div class="sidebar-overlay"></div>

    <div class="main-wrapper">
        {{-- Mobile header --}}
        <header class="mobile-header justify-content-between align-items-center">
            <button class="btn btn-link text-white fs-4" id="sidebar-toggler"><i class="fas fa-bars"></i></button>
            <span class="app-name-mobile">{{ config('app.name', 'Laravel') }}</span>
        </header>

        {{-- Content --}}
        <main class="main-content">
            <div class="container-fluid flex-grow-1 p-3 p-lg-4">
                {{-- Flash messages --}}
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
                <strong>PT Kayu Mabel Indonesia</strong> &copy; {{ date('Y') }}
            </footer>
        </main>
    </div>

    {{-- Script vendor --}}
    <script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>

    {{-- Toggle sidebar (mobile) + sinkronisasi target search --}}
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

            // === Sinkronkan hidden "target" berdasarkan link sidebar yang aktif ===
            const targetInput = document.getElementById('search-target');
            if (targetInput) {
                // Saat load: jika ada nav-link.active yang punya data-report-target, pakai itu
                const activeLink = document.querySelector('.sidebar-nav .nav-link.active[data-report-target]');
                if (activeLink && activeLink.dataset.reportTarget) {
                    targetInput.value = activeLink.dataset.reportTarget;
                }

                // Jika user mengklik menu sebelum submit search (SPA-like), set target sementara
                document.querySelectorAll('.sidebar-nav .nav-link[data-report-target]').forEach(a => {
                    a.addEventListener('click', () => {
                        if (a.dataset.reportTarget) {
                            targetInput.value = a.dataset.reportTarget;
                        }
                    }, {
                        passive: true
                    });
                });
            }
        });

        // Hardening: prevent bfcache from showing protected pages after logout
        window.addEventListener('pageshow', function(e) {
            if (e.persisted) window.location.reload();
        });
    </script>

    {{-- Chart help icon (popover) --}}
    {{-- ASUMSI: file chart-help.js dan chart-help.json ada --}}
    <script src="{{ asset('js/chart-help.js') }}" data-json="{{ asset('chart-help.json') }}" defer></script>

    {{-- Optional: inject js via flash session (e.g., storage cleanup on logout) --}}
    @if (session()->has('js_script'))
        <script>
            const script = @json(session('js_script'));
            if (script) {
                try {
                    eval(script);
                } catch (e) {
                    console.error('Error pembersihan:', e);
                }
            }
        </script>
    @endif

    @stack('scripts')
</body>

</html>
