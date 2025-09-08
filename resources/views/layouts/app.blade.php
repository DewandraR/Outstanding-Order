<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name', 'Laravel'))</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- [DIUBAH] Google Fonts: Poppins (Lokal) --}}
    <link rel="stylesheet" href="{{ asset('vendor/fonts/poppins/poppins.css') }}">

    {{-- [DIUBAH] Bootstrap 5 CSS (Lokal) --}}
    <link href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">

    {{-- [DIUBAH] Font Awesome (Lokal) --}}
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}" />

    {{-- Custom Styles for Sidebar Layout --}}
    <style>
        :root {
            --sidebar-width: 280px;
            --sidebar-bg: #2e7d32;
            --sidebar-link-color: #e8f5e9;
            --sidebar-link-hover-bg: #388e3c;
            --sidebar-link-active-bg: #1b5e20;
            --font-family-sans-serif: 'Poppins', sans-serif;
        }

        body {
            background-color: #f8f9fa;
            min-height: 100vh;
            font-family: var(--font-family-sans-serif);
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23e9ecef' fill-opacity='0.4'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }

        .mobile-header {
            display: none;
            background-color: var(--sidebar-bg);
            color: white;
            padding: 0.75rem 1rem;
            position: sticky;
            top: 0;
            z-index: 1020;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        .mobile-header .app-name-mobile {
            font-weight: 600;
        }

        /* [STRUKTUR UTAMA SIDEBAR] */
        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background: linear-gradient(180deg, #388e3c 0%, #2e7d32 100%);
            color: #fff;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease-in-out;
            z-index: 1030;
            box-shadow: 0 4px 25px rgba(0, 0, 0, 0.1);
            padding: 0;
            /* Padding diatur di child element */
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1025;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            animation: contentFadeIn 0.6s ease-out forwards;
            transition: margin-left 0.3s ease-in-out;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        @keyframes contentFadeIn {
            from {
                opacity: 0;
                transform: translateY(15px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 991.98px) {
            .mobile-header {
                display: flex;
            }

            .sidebar {
                transform: translateX(-100%);
                box-shadow: 0 0 15px rgba(0, 0, 0, 0.2);
            }

            .sidebar.is-open {
                transform: translateX(0);
            }

            .sidebar.is-open+.sidebar-overlay {
                display: block;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
            }
        }

        /* [BARU] HEADER & BRANDING */
        .sidebar-header {
            display: flex;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-decoration: none;
            color: #fff;
            flex-shrink: 0;
            transition: background-color 0.2s ease-in-out;
        }

        .sidebar-header:hover {
            background-color: rgba(0, 0, 0, 0.15);
        }

        .sidebar-logo {
            height: 60px;
            width: 60px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 1rem;
            transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .sidebar-header:hover .sidebar-logo {
            transform: rotate(10deg) scale(1.1);
        }

        .sidebar-title-wrapper {
            line-height: 1.2;
        }

        .sidebar-app-name {
            font-size: 1.20rem;
            font-weight: 600;
            margin: 0;
            color: #fff;
        }

        .sidebar-app-subtitle {
            font-size: 0.75rem;
            font-weight: 400;
            color: rgba(255, 255, 255, 0.7);
        }

        /* Sembunyikan class .sidebar-user lama jika masih ada */
        .sidebar-user {
            display: none;
        }

        /* [MODIFIKASI] NAVIGASI UTAMA */
        .sidebar-nav {
            list-style: none;
            padding: 1rem;
            flex-grow: 1;
            overflow-y: auto;
        }

        .sidebar-nav::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar-nav::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, .1);
            border-radius: 10px;
        }

        .sidebar-nav::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, .3);
            border-radius: 10px;
        }

        .sidebar-nav::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, .5);
        }

        .sidebar-nav .nav-item .nav-link {
            display: flex;
            align-items: center;
            padding: .75rem 1rem;
            color: var(--sidebar-link-color);
            text-decoration: none;
            border-radius: .5rem;
            margin-bottom: .25rem;
            font-weight: 500;
            transition: all .2s ease-in-out;
            transform: translateX(0);
        }

        .sidebar-nav .nav-item .nav-link .nav-icon {
            width: 20px;
            margin-right: .75rem;
            text-align: center;
            transition: transform .2s ease-in-out;
        }

        .sidebar-nav .nav-item .nav-link:hover {
            background-color: var(--sidebar-link-hover-bg);
            color: #fff;
            transform: translateX(5px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, .2);
        }

        .sidebar-nav .nav-item .nav-link:hover .nav-icon {
            transform: scale(1.1);
        }

        .sidebar-nav .nav-item .nav-link.active {
            background-color: var(--sidebar-link-active-bg);
            color: #fff;
            font-weight: 600;
        }

        .sidebar-nav .nav-item .nav-link[data-bs-toggle=collapse]::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            margin-left: auto;
            transition: transform .2s;
        }

        .sidebar-nav .nav-item .nav-link[aria-expanded=true]::after {
            transform: rotate(180deg);
        }

        .collapse .nav-link {
            padding-left: 2.5rem !important;
            font-size: .9rem;
            background-color: rgba(0, 0, 0, .15);
        }

        .collapse .nav-link:hover {
            background-color: rgba(0, 0, 0, .3);
            transform: translateX(0);
            box-shadow: none;
        }

        .collapse .collapse .nav-link {
            padding-left: 4rem !important;
            font-size: .85rem;
            background-color: rgba(0, 0, 0, .25);
        }

        .collapse .collapse .nav-link:hover {
            background-color: rgba(0, 0, 0, .4);
        }

        .nav-link-text {
            line-height: 1.2;
        }

        .nav-link-main {
            display: block;
            font-weight: 500;
        }

        .nav-link-sub {
            display: block;
            font-size: .75rem;
            color: rgba(255, 255, 255, .6);
        }

        .nav-heading {
            padding: 1.5rem 1rem .5rem;
            font-size: .7rem;
            font-weight: 700;
            color: rgba(255, 255, 255, .4);
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        /* [BARU & DIUBAH] PROFIL PENGGUNA DI BAWAH */
        .user-profile {
            margin-top: auto;
            padding: 0.75rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            flex-shrink: 0;
        }

        .user-profile .dropdown-toggle {
            display: flex;
            align-items: center;
            text-align: left;
            background: none;
            border: none;
            color: #fff;
            padding: 0.75rem;
            border-radius: 0.5rem;
            width: 100%;
        }

        .user-profile .dropdown-toggle:hover {
            background-color: var(--sidebar-link-hover-bg);
        }

        .user-profile .user-info {
            display: flex;
            flex-direction: column;
            line-height: 1.3;
            text-align: left;
        }

        .user-profile .user-name {
            font-weight: 600;
            color: #fff;
        }

        .user-profile .user-status {
            font-size: 0.75rem;
            color: #a5d6a7;
        }

        .user-profile .user-status::before {
            content: '●';
            color: #4caf50;
            font-size: 0.7rem;
            margin-right: 5px;
            vertical-align: middle;
        }

        .user-profile .dropdown-menu {
            background-color: #fff !important;
            border: 1px solid #c8e6c9;
            width: calc(var(--sidebar-width) - 1.5rem);
            box-shadow: 0 4px 12px rgba(0, 0, 0, .15);
        }

        .user-profile .dropdown-item {
            color: #2e7d32 !important;
        }

        .user-profile .dropdown-item:hover {
            background-color: #e8f5e9 !important;
        }

        .user-profile .dropdown-item i {
            margin-right: .75rem;
            width: 16px;
        }

        /* FOOTER & SEARCH */
        footer {
            background: #fff;
            padding: 1.5rem;
            text-align: center;
            color: #6c757d;
            margin-top: 2rem;
            border-radius: .75rem;
            box-shadow: 0 -4px 15px rgba(0, 0, 0, .05);
        }

        .alert {
            animation: fadeIn .5s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .sidebar-search-form .input-group {
            border-radius: 0.5rem;
            background-color: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.2s ease-in-out;
        }

        .sidebar-search-form .input-group:focus-within {
            background-color: rgba(0, 0, 0, 0.3);
            border-color: rgba(255, 255, 255, 0.5);
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.2);
        }

        .sidebar-search-form .form-control {
            background: transparent;
            border: none;
            color: #fff;
            box-shadow: none;
            border-top-left-radius: inherit;
            border-bottom-left-radius: inherit;
        }

        .sidebar-search-form .form-control::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }

        .sidebar-search-form .btn-search {
            background-color: transparent;
            border: none;
            color: rgba(255, 255, 255, 0.6);
            transition: color 0.2s ease-in-out, background-color 0.2s ease-in-out;
            border-top-right-radius: inherit;
            border-bottom-right-radius: inherit;
        }

        .sidebar-search-form .btn-search:hover,
        .sidebar-search-form .btn-search:focus {
            color: #fff;
            background-color: rgba(0, 0, 0, 0.1);
            box-shadow: none;
        }
    </style>

    @stack('styles')
</head>

<body>
    <div class="sidebar">
        {{-- BAGIAN 1: HEADER BRANDING --}}
        <a href="{{ route('dashboard') }}" class="sidebar-header">
            <img src="{{ asset('images/KMI.png') }}" alt="Logo KMI" class="sidebar-logo">
            <div class="sidebar-title-wrapper">
                <h5 class="sidebar-app-name">{{ config('app.name', 'Laravel') }}</h5>
                <span class="sidebar-app-subtitle">PT Kayu Mabel Indonesia</span>
            </div>
        </a>

        {{-- BAGIAN 2: NAVIGASI UTAMA (BISA DI-SCROLL) --}}
        <ul class="sidebar-nav">
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('dashboard') && !request()->has('werks') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                    <i class="fas fa-home nav-icon"></i> Dashboard
                </a>
            </li>

            {{-- Form pencarian menjadi lebih terintegrasi --}}
            <li class="nav-item px-2 mt-2 mb-2">
                <form action="{{ route('dashboard.search') }}" method="GET" class="sidebar-search-form">
                    <div class="input-group">
                        <input type="text" class="form-control" name="term" placeholder="Search PO / SO No..." required value="{{ request('term') ?? request('search_term') ?? '' }}" aria-label="Search SO or PO Number">
                        <button class="btn btn-search" type="submit">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </form>
            </li>

            <li class="nav-heading">Report</li>
            <li class="nav-item">
                <a class="nav-link {{ request()->has('werks') ? '' : 'collapsed' }}" href="#submenu-outstanding" data-bs-toggle="collapse" role="button"
                    aria-expanded="{{ request()->has('werks') ? 'true' : 'false' }}" aria-controls="submenu-outstanding">
                    <i class="fas fa-file-invoice nav-icon"></i> <span>Outstanding SO</span>
                </a>
                <div class="collapse {{ request()->has('werks') ? 'show' : '' }}" id="submenu-outstanding">
                    <ul class="nav flex-column">
                        @isset($mapping)
                        @foreach ($mapping as $werks => $auarts)
                        @php
                        $locationMap = ['2000' => 'Surabaya', '3000' => 'Semarang'];
                        $locationName = $locationMap[$werks] ?? $werks;
                        @endphp
                        <li class="nav-item">
                            <a class="nav-link {{ request('werks') == $werks ? '' : 'collapsed' }}" href="#submenu-{{ $werks }}" data-bs-toggle="collapse" role="button"
                                aria-expanded="{{ request('werks') == $werks ? 'true' : 'false' }}" aria-controls="submenu-{{ $werks }}">
                                <i class="fas fa-map-marker-alt nav-icon"></i> <span>{{ $locationName }}</span>
                            </a>
                            <div class="collapse {{ request('werks') == $werks ? 'show' : '' }}" id="submenu-{{ $werks }}">
                                <ul class="nav flex-column">
                                    @foreach ($auarts as $item)
                                    <li class="nav-item">
                                        <a class="nav-link {{ (request('werks') == $werks && request('auart') == $item->IV_AUART) ? 'active' : '' }}"
                                            href="{{ route('dashboard', ['werks' => $werks, 'auart' => $item->IV_AUART, 'compact' => 1]) }}">
                                            <i class="fas fa-file-alt nav-icon"></i>
                                            <div>{{ $item->Deskription }}</div>
                                        </a>
                                    </li>
                                    @endforeach
                                </ul>
                            </div>
                        </li>
                        @endforeach
                        @endisset
                    </ul>
                </div>
            </li>
        </ul>

        {{-- BAGIAN 3: PROFIL PENGGUNA (SELALU DI BAWAH) --}}
        <div class="user-profile">
            <div class="dropdown dropup">
                <button class="btn dropdown-toggle" type="button" id="userMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle fa-lg me-2"></i>
                    <div class="user-info">
                        <span class="user-name">{{ Auth::user()->name ?? 'User' }}</span>
                        <span class="user-status">Online</span>
                    </div>
                </button>
                <ul class="dropdown-menu" aria-labelledby="userMenuButton">
                    <li><a class="dropdown-item" href="{{ route('profile.edit') }}"><i class="fas fa-user-edit"></i> Profile</a></li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Sign Out</button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>

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

    {{-- [DIUBAH] Bootstrap 5 JS (Lokal) --}}
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
    @stack('scripts')
</body>

</html>