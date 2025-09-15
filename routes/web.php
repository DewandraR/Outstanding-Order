<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MappingController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockDashboardController; // <-- DITAMBAHKAN

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Route utama akan langsung mengarah ke dashboard PO
Route::get('/', function () {
    return redirect()->route('dashboard', ['view' => 'po']);
});

// Route untuk Dashboard Utama dan API-nya
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/search', [DashboardController::class, 'search'])->name('dashboard.search');

    // API untuk Dashboard
    Route::get('/dashboard/api/t2', [DashboardController::class, 'apiT2'])->name('dashboard.api.t2');
    Route::get('/dashboard/api/t3', [DashboardController::class, 'apiT3'])->name('dashboard.api.t3');
    Route::get('/dashboard/api/small-qty-details', [DashboardController::class, 'apiSmallQtyDetails'])->name('dashboard.api.smallQtyDetails');
});


// Grup route untuk semua fitur yang memerlukan autentikasi
Route::middleware('auth')->group(function () {
    // Route untuk Profile Pengguna
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Route untuk CRUD Mapping
    Route::resource('mapping', MappingController::class);

    // ==========================================================
    // == ROUTE UNTUK FITUR OUTSTANDING SO
    // ==========================================================
    Route::get('/outstanding-so', [SalesOrderController::class, 'index'])->name('so.index');
    Route::get('/api/so-by-customer', [SalesOrderController::class, 'apiGetSoByCustomer'])->name('so.api.by_customer');
    Route::get('/api/items-by-so', [SalesOrderController::class, 'apiGetItemsBySo'])->name('so.api.by_items');
    Route::post('/outstanding-so/export', [SalesOrderController::class, 'exportData'])->name('so.export');
    Route::post('/api/so-save-remark', [SalesOrderController::class, 'apiSaveRemark'])->name('so.api.save_remark');


    // ==========================================================
    // == ROUTE UNTUK LAPORAN STOK (DETAIL)
    // ==========================================================
    Route::get('/stock-report', [StockController::class, 'index'])->name('stock.index');
    Route::get('/api/stock/so-by-customer', [StockController::class, 'getSoByCustomer'])->name('stock.api.by_customer');
    Route::get('/api/stock/items-by-so', [StockController::class, 'getItemsBySo'])->name('stock.api.by_items');

    // ==========================================================
    // == (BARU) ROUTE UNTUK DASHBOARD STOK (VISUAL)
    // ==========================================================
    Route::get('/stock-dashboard', [StockDashboardController::class, 'index'])->name('stock.dashboard');
});

// Route untuk autentikasi (login, register, dll.)
require __DIR__ . '/auth.php';
