<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MappingController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockDashboardController;


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

    // [DIUBAH] search -> POST
    Route::post('/dashboard/search', [DashboardController::class, 'search'])->name('dashboard.search');

    // [BARU] redirector aman (dashboard)
    Route::post('/dashboard/redirect', [DashboardController::class, 'redirector'])->name('dashboard.redirector');

    // [BARU] API decrypt payload (dashboard)
    Route::post('/dashboard/api/decrypt-payload', [DashboardController::class, 'apiDecryptPayload'])->name('dashboard.api.decrypt_payload');
    Route::get('/api/po/outs-by-customer', [DashboardController::class, 'apiPoOutsByCustomer'])->name('api.po.outs_by_customer');

    // API untuk Dashboard (existing)
    Route::get('/dashboard/api/t2', [DashboardController::class, 'apiT2'])->name('dashboard.api.t2');
    Route::get('/dashboard/api/t3', [DashboardController::class, 'apiT3'])->name('dashboard.api.t3');
    Route::get('/dashboard/api/small-qty-details', [DashboardController::class, 'apiSmallQtyDetails'])->name('dashboard.api.smallQtyDetails');
    Route::get('/dashboard/api/so-status-details', [DashboardController::class, 'apiSoStatusDetails'])->name('dashboard.api.soStatusDetails');
    Route::get('/dashboard/api/so-urgency-details', [DashboardController::class, 'apiSoUrgencyDetails'])->name('dashboard.api.soUrgencyDetails');
    Route::get('/dashboard/api/po-overdue-details', [DashboardController::class, 'apiPoOverdueDetails'])->name('dashboard.api.poOverdueDetails');
    Route::get('/dashboard/api/so-bottlenecks-details', [DashboardController::class, 'apiSoBottlenecksDetails'])->name('dashboard.api.soBottlenecksDetails');
    Route::get('/api/so-remark-summary', [DashboardController::class, 'apiSoRemarkSummary'])->name('so.api.remark_summary');
    Route::get('/api/so-remark-items',   [DashboardController::class, 'apiSoRemarkItems'])->name('so.api.remark_items');
    Route::get('/dashboard/api/remark-details', [DashboardController::class, 'apiRemarkDetails'])->name('dashboard.api.remarkDetails');
    Route::get('/api/so/outs_by_customer', [\App\Http\Controllers\DashboardController::class, 'apiSoOutsByCustomer'])
        ->name('api.so.outs_by_customer');
});

// Grup route untuk semua fitur yang memerlukan autentikasi
Route::middleware('auth')->group(function () {
    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // CRUD Mapping
    Route::resource('mapping', MappingController::class);

    // ==========================================================
    // == OUTSTANDING SO
    // ==========================================================
    Route::get('/outstanding-so', [SalesOrderController::class, 'index'])->name('so.index');

    // [BARU] Redirector aman untuk membentuk URL terenkripsi ?q=...
    Route::post('/outstanding-so/redirect', [SalesOrderController::class, 'redirector'])->name('so.redirector');

    Route::get('/api/so-by-customer', [SalesOrderController::class, 'apiGetSoByCustomer'])->name('so.api.by_customer');
    Route::get('/api/items-by-so', [SalesOrderController::class, 'apiGetItemsBySo'])->name('so.api.by_items');

    Route::post('/outstanding-so/export', [SalesOrderController::class, 'exportData'])->name('so.export');

    Route::post('/api/so-save-remark', [SalesOrderController::class, 'apiSaveRemark'])->name('so.api.save_remark');

    // Export PDF: Overview Customer (menerima ?q terenkripsi ATAU werks+auart biasa)
    Route::get('/outstanding-so/export/summary', [SalesOrderController::class, 'exportCustomerSummary'])
        ->name('so.export.summary');

    // ==========================================================
    // == LAPORAN STOK (DETAIL)
    // ==========================================================
    Route::get('/stock-report', [StockController::class, 'index'])->name('stock.index');
    Route::get('/api/stock/so-by-customer', [StockController::class, 'getSoByCustomer'])->name('stock.api.by_customer');
    Route::get('/api/stock/items-by-so', [StockController::class, 'getItemsBySo'])->name('stock.api.by_items');
    Route::post('/stock-report/redirect', [StockController::class, 'redirector'])
        ->name('stock.redirector');

    // ==========================================================
    // == DASHBOARD STOK (VISUAL)
    // ==========================================================
    Route::get('/stock-dashboard', [StockDashboardController::class, 'index'])->name('stock.dashboard');
});

// Route untuk autentikasi (login, register, dll.)
require __DIR__ . '/auth.php';
