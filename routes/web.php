<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

// === Auth (custom) ===
use App\Http\Controllers\AuthController;

// === Aplikasi ===
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;        // PO Dashboard (visual)
use App\Http\Controllers\SODashboardController;     // SO Dashboard (visual)
use App\Http\Controllers\MappingController;
use App\Http\Controllers\SalesOrderController;      // Outstanding SO (report lama)
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockDashboardController;
use App\Http\Controllers\PoReportController;

// === Breeze controllers yang dipakai hanya untuk REGISTER & RESET PASSWORD ===
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\NewPasswordController;

/*
|--------------------------------------------------------------------------
| GUEST AREA (hanya untuk tamu)
| - Login pakai AuthController (custom)
| - Register & Forgot/Reset Password pakai controller Breeze
| - Diberi middleware: guest + nocache.after
|--------------------------------------------------------------------------
*/

Route::middleware(['guest', 'nocache.after'])->group(function () {
	// Login (custom)
	Route::get('/login',  [AuthController::class, 'showLoginForm'])->name('login');
	Route::post('/login', [AuthController::class, 'login']);

	// Register (Breeze)
	Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
	Route::post('/register', [RegisteredUserController::class, 'store']);

	// Forgot / Reset Password (Breeze) — opsional, aman dibiarkan aktif
	Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])
		->name('password.request');
	Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
		->name('password.email');
	Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])
		->name('password.reset');
	Route::post('/reset-password', [NewPasswordController::class, 'store'])
		->name('password.store');
});

/*
|--------------------------------------------------------------------------
| LOGOUT (POST, wajib login)
|--------------------------------------------------------------------------
*/
Route::post('/logout', [AuthController::class, 'logout'])
	->middleware(['auth'])
	->name('logout');

/*
|--------------------------------------------------------------------------
| PROTECTED AREA (Wajib login)
| - Semua rute aplikasi dipindahkan ke dalam group ini!
| - Diberi middleware: auth + nocache
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'nocache'])->group(function () {

	// Root -> Dashboard
	Route::get('/', fn() => redirect()->route('dashboard'));

	// --------- PO Dashboard (visual) ---------
	Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

	// Util PO Dashboard
	Route::post('/dashboard/search', [DashboardController::class, 'search'])->name('dashboard.search');
	Route::post('/dashboard/redirect', [DashboardController::class, 'redirector'])->name('dashboard.redirector');
	Route::post('/dashboard/api/decrypt-payload', [DashboardController::class, 'apiDecryptPayload'])
		->name('dashboard.api.decrypt_payload');

	// API nested (T2/T3) & detail untuk PO (DASHBOARD)
	Route::get('/dashboard/api/t2', [DashboardController::class, 'apiT2'])->name('dashboard.api.t2');
	Route::get('/dashboard/api/t3', [DashboardController::class, 'apiT3'])->name('dashboard.api.t3');

	// API Khusus Dashboard PO
	Route::get('/api/po/outs-by-customer', [DashboardController::class, 'apiPoOutsByCustomer'])
		->name('api.po.outs_by_customer');

	// Detail Item Qty Kecil
	Route::get('/dashboard/api/small-qty-details', [DashboardController::class, 'apiSmallQtyDetails'])
		->name('dashboard.api.smallQtyDetails');

	// PO Status Details (Doughnut Chart Click)
	Route::get('/dashboard/api/po-status-details', [DashboardController::class, 'apiPoStatusDetails'])
		->name('dashboard.api.poStatusDetails');

	// Overdue Details di tabel Performance
	Route::get('/api/po/overdue-details', [DashboardController::class, 'apiPoOverdueDetails'])
		->name('dashboard.api.poOverdueDetails');

	// Export PDF Small Qty (jika dipakai)
	Route::post('/dashboard/export/small-qty-pdf', [DashboardController::class, 'exportSmallQtyPdf'])
		->name('dashboard.export.smallQtyPdf');

	// --------- SO Dashboard (visual) ---------
	Route::get('/so-dashboard', [SODashboardController::class, 'index'])->name('so.dashboard');

	// API untuk SO Dashboard
	Route::get('/api/so/outs-by-customer', [SODashboardController::class, 'apiSoOutsByCustomer'])
		->name('so.api.outs_by_customer');
	Route::get('/api/so/remark-summary', [SODashboardController::class, 'apiSoRemarkSummary'])
		->name('so.api.remark_summary');
	Route::get('/api/so/remark-items', [SODashboardController::class, 'apiSoRemarkItems'])
		->name('so.api.remark_items');
	Route::get('/api/so/bottlenecks-details', [SODashboardController::class, 'apiSoBottlenecksDetails'])
		->name('so.api.bottlenecks_details');
	Route::get('/api/so/urgency-details', [SODashboardController::class, 'apiSoUrgencyDetails'])
		->name('so.api.urgency_details');
	Route::get('/api/so/status-details', [SODashboardController::class, 'apiSoStatusDetails'])
		->name('so.api.status_details');
	Route::delete('/so/api/remark/delete', [SODashboardController::class, 'apiSoRemarkDelete'])
		->name('so.api.remark_delete');

	// --------- PO Report (mode tabel) ---------
	Route::get('/po-report', [PoReportController::class, 'index'])->name('po.report');
	Route::post('/po/export-data', [PoReportController::class, 'exportData'])->name('po.export');
	Route::get('api/po/performance-by-customer', [PoReportController::class, 'apiPerformanceByCustomer'])
		->name('po.api.performanceByCustomer');

	// --------- Outstanding SO (report lama) ---------
	Route::get('/outstanding-so', [SalesOrderController::class, 'index'])->name('so.index');
	Route::post('/outstanding-so/redirect', [SalesOrderController::class, 'redirector'])->name('so.redirector');
	Route::get('/api/so-by-customer', [SalesOrderController::class, 'apiGetSoByCustomer'])->name('so.api.by_customer');
	Route::get('/api/items-by-so', [SalesOrderController::class, 'apiGetItemsBySo'])->name('so.api.by_items');
	Route::post('/outstanding-so/export', [SalesOrderController::class, 'exportData'])->name('so.export');
	Route::post('/api/so-save-remark', [SalesOrderController::class, 'apiSaveRemark'])->name('so.api.save_remark');
	Route::get('/outstanding-so/export/summary', [SalesOrderController::class, 'exportCustomerSummary'])
		->name('so.export.summary');

	// --------- Stock report & dashboard ---------
	Route::get('/stock-report', [StockController::class, 'index'])->name('stock.index');
	Route::get('/api/stock/so-by-customer', [StockController::class, 'getSoByCustomer'])->name('stock.api.by_customer');
	Route::get('/api/stock/items-by-so', [StockController::class, 'getItemsBySo'])->name('stock.api.by_items');
	Route::post('/stock-report/redirect', [StockController::class, 'redirector'])->name('stock.redirector');
	Route::post('/stock-report/export', [StockController::class, 'exportData'])->name('stock.export');
	Route::get('/stock-dashboard', [StockDashboardController::class, 'index'])->name('stock.dashboard');

	// --------- Profil & CRUD Mapping (opsional) ---------
	Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
	Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
	Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

	Route::resource('mapping', MappingController::class);
});

/*
|--------------------------------------------------------------------------
| Fallback: tamu diarahkan ke login, user login dapat 404
|--------------------------------------------------------------------------
*/
Route::fallback(function () {
	return Auth::check()
		? abort(404)
		: redirect()->route('login');
});
