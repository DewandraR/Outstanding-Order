<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

// === Auth (custom) ===
use App\Http\Controllers\AuthController;

// === Aplikasi ===
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SODashboardController;
use App\Http\Controllers\MappingController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockDashboardController;
use App\Http\Controllers\PoReportController;
// use App\Http\Controllers\SoItemRemarkController; // <- tidak dipakai, hapus
use App\Http\Controllers\StockIssueController;      // [BARU]

// === Breeze controllers yang dipakai ===
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\NewPasswordController;

// === Controller untuk Verifikasi Email ===
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;

// === Controller untuk Update Password ===
use App\Http\Controllers\Auth\PasswordController;

/*
|--------------------------------------------------------------------------
| GUEST AREA (hanya untuk tamu)
|--------------------------------------------------------------------------
*/

Route::middleware(['guest', 'nocache.after'])->group(function () {
	// Login (custom)
	Route::get('/login',  [AuthController::class, 'showLoginForm'])->name('login');
	Route::post('/login', [AuthController::class, 'login']);

	// Register (Breeze)
	Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
	Route::post('/register', [RegisteredUserController::class, 'store']);

	// Forgot / Reset Password (Breeze)
	Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
	Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
	Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
	Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
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
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'nocache'])->group(function () {

	// Root -> Dashboard
	Route::get('/', fn() => redirect()->route('dashboard'));

	// ---------------------- VERIFIKASI EMAIL ---------------------
	Route::get('/email/verify', EmailVerificationPromptController::class)
		->name('verification.notice');

	Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
		->middleware(['signed', 'throttle:6,1'])
		->name('verification.verify');

	Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
		->middleware('throttle:6,1')
		->name('verification.send');
	// -------------------------------------------------------------

	// ------------------- UPDATE PASSWORD -------------------------
	Route::put('/password', [PasswordController::class, 'update'])->name('password.update');
	// -------------------------------------------------------------

	/*
    |--------------------------- PO Dashboard ---------------------------
    */
	Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

	// Util PO Dashboard
	Route::post('/dashboard/search', [DashboardController::class, 'search'])->name('dashboard.search');
	Route::post('/dashboard/redirect', [DashboardController::class, 'redirector'])->name('dashboard.redirector');
	Route::post('/dashboard/api/decrypt-payload', [DashboardController::class, 'apiDecryptPayload'])->name('dashboard.api.decrypt_payload');

	// API nested (T2/T3) & detail untuk PO (DASHBOARD)
	Route::get('/dashboard/api/t2', [DashboardController::class, 'apiT2'])->name('dashboard.api.t2');
	Route::get('/dashboard/api/t3', [DashboardController::class, 'apiT3'])->name('dashboard.api.t3');

	// API Khusus Dashboard PO
	Route::get('/api/po/outs-by-customer', [DashboardController::class, 'apiPoOutsByCustomer'])->name('api.po.outs_by_customer');

	// Detail Item Qty Kecil
	Route::get('/dashboard/api/small-qty-details', [DashboardController::class, 'apiSmallQtyDetails'])->name('dashboard.api.smallQtyDetails');

	// Small Qty PDF (PO Dashboard) — pola POST -> GET (starter -> streamer)
	Route::post('/dashboard/export/small-qty-pdf', [DashboardController::class, 'exportSmallQtyStart'])->name('dashboard.export.smallQtyPdf');
	Route::get('/dashboard/export/small-qty-pdf',  [DashboardController::class, 'exportSmallQtyShow'])->name('dashboard.export.smallQtyPdf.show');

	// PO Status Details (Doughnut Chart Click)
	Route::get('/dashboard/api/po-status-details', [DashboardController::class, 'apiPoStatusDetails'])->name('dashboard.api.poStatusDetails');

	// Overdue Details di tabel Performance
	Route::get('/api/po/overdue-details', [DashboardController::class, 'apiPoOverdueDetails'])->name('dashboard.api.poOverdueDetails');

	// PO remark (dashboard)
	Route::get('/po/api/remark-items',  [DashboardController::class, 'apiPoRemarkItems'])->name('po.api.remark_items');
	// NOTE: pastikan method apiPoRemarkDelete ada; jika belum, komentar baris berikut
	// Route::delete('/po/api/remark-delete', [DashboardController::class, 'apiPoRemarkDelete'])->name('po.api.remark_delete');

	/*
    |--------------------------- SO Dashboard ---------------------------
    */
	Route::get('/so-dashboard', [SODashboardController::class, 'index'])->name('so.dashboard');

	// API untuk SO Dashboard
	Route::get('/api/so/outs-by-customer', [SODashboardController::class, 'apiSoOutsByCustomer'])->name('so.api.outs_by_customer');
	Route::get('/api/so/remark-summary', [SODashboardController::class, 'apiSoRemarkSummary'])->name('so.api.remark_summary');
	Route::get('/api/so/remark-items', [SODashboardController::class, 'apiSoRemarkItems'])->name('so.api.remark_items');
	Route::get('/api/so/bottlenecks-details', [SODashboardController::class, 'apiSoBottlenecksDetails'])->name('so.api.bottlenecks_details');
	Route::get('/api/so/urgency-details', [SODashboardController::class, 'apiSoUrgencyDetails'])->name('so.api.urgency_details');
	Route::get('/api/so/status-details', [SODashboardController::class, 'apiSoStatusDetails'])->name('so.api.status_details');
	Route::delete('/so/api/remark/delete', [SODashboardController::class, 'apiSoRemarkDelete'])->name('so.api.remark_delete');

	/*
    |----------------------------- PO Report ----------------------------
    */
	Route::get('/po-report', [PoReportController::class, 'index'])->name('po.report');
	Route::post('/po/export-data', [PoReportController::class, 'exportDataStart'])->name('po.export');
	Route::get('/po/export-data',  [PoReportController::class, 'exportDataShow'])->name('po.export.show');
	Route::get('/api/po/performance-by-customer', [PoReportController::class, 'apiPerformanceByCustomer'])->name('po.api.performanceByCustomer');
	Route::get('/api/po/item-search', [PoReportController::class, 'apiItemSearch'])
		->name('po.api.item_search');

	Route::post('/api/po/remark/save', [PoReportController::class, 'apiSavePoRemark'])->name('api.po.remark.save');
	Route::get('/po/remark/list', [PoReportController::class, 'apiListPoRemarks'])->name('api.po.remark.list');
	Route::post('/po/remark', [PoReportController::class, 'apiCreatePoRemark'])->name('api.po.remark.create');
	Route::put('/po/remark/{id}', [PoReportController::class, 'apiUpdatePoRemark'])->name('api.po.remark.update');
	Route::delete('/po/remark/{id}', [PoReportController::class, 'apiDeletePoRemark'])->name('api.po.remark.delete');

	/*
    |------------------------- Outstanding SO Report --------------------
    */
	Route::get('/outstanding-so', [SalesOrderController::class, 'index'])->name('so.index');
	Route::post('/outstanding-so/redirect', [SalesOrderController::class, 'redirector'])->name('so.redirector');

	// Level 2 & 3 (data)
	Route::get('/api/so-by-customer', [SalesOrderController::class, 'apiGetSoByCustomer'])->name('so.api.by_customer');
	Route::get('/api/items-by-so', [SalesOrderController::class, 'apiGetItemsBySo'])->name('so.api.by_items');

	// Export items: POST starter -> GET streamer
	Route::post('/outstanding-so/export', [SalesOrderController::class, 'exportDataStart'])->name('so.export');
	Route::get('/outstanding-so/export',  [SalesOrderController::class, 'exportDataShow'])->name('so.export.show');

	// Summary (inline stream)
	Route::get('/outstanding-so/export/summary', [SalesOrderController::class, 'exportCustomerSummary'])->name('so.export.summary');

	// Small Qty chart data
	Route::get('/api/so/small-qty-by-customer', [SalesOrderController::class, 'apiSmallQtyByCustomer'])->name('so.api.small_qty_by_customer');
	Route::get('/api/so/small-qty-details', [SalesOrderController::class, 'apiSmallQtyDetails'])->name('so.api.small_qty_details');

	// Small Qty PDF (SO): POST starter -> GET streamer
	Route::post('/outstanding-so/export/small-qty-pdf', [SalesOrderController::class, 'exportSmallQtyStart'])->name('so.export.small_qty_pdf');
	Route::get('/outstanding-so/export/small-qty-pdf',  [SalesOrderController::class, 'exportSmallQtyShow'])->name('so.export.small_qty_pdf.show');

	// (LEGACY) Simpan remark tunggal — dipertahankan
	Route::post('/api/so-save-remark', [SalesOrderController::class, 'apiSaveRemark'])->name('so.api.save_remark');

	// ====== BARU: Multi-remark per item (SO) ======
	Route::get('/api/so/item-remarks', [SalesOrderController::class, 'apiListItemRemarks'])->name('so.api.item_remarks');
	Route::post('/api/so/item-remarks', [SalesOrderController::class, 'apiAddItemRemark'])->name('so.api.item_remarks.store');
	Route::delete('/api/so/item-remarks/{id}', [SalesOrderController::class, 'apiDeleteItemRemark'])->name('so.api.item_remarks.delete');
	Route::put('/api/so/item-remarks/{id}', [SalesOrderController::class, 'apiUpdateItemRemark'])->name('so.api.item_remarks.update'); // <- diseragamkan (bukan /so/api/..)

	Route::get('/so/api/machining-lines', [SalesOrderController::class, 'apiMachiningLines'])->name('so.api.machining_lines');
	Route::get('/so/api/pembahanan-lines', [SalesOrderController::class, 'apiPembahananLines'])->name('so.api.pembahanan_lines');

	/*
    |------------------------ Stock report & dashboard ------------------
    */
	Route::get('/stock-report', [StockController::class, 'index'])->name('stock.index');
	Route::get('/api/stock/so-by-customer', [StockController::class, 'getSoByCustomer'])->name('stock.api.by_customer');
	Route::get('/api/stock/items-by-so', [StockController::class, 'getItemsBySo'])->name('stock.api.by_items');
	Route::post('/stock-report/redirect', [StockController::class, 'redirector'])->name('stock.redirector');

	// Export (Stock): POST starter -> GET streamer
	Route::post('/stock-report/export', [StockController::class, 'exportDataStart'])->name('stock.export');
	Route::get('/stock-report/export',  [StockController::class, 'exportDataShow'])->name('stock.export.show');

	Route::get('/stock-dashboard', [StockDashboardController::class, 'index'])->name('stock.dashboard');

	// [BARU] Stock Issue
	Route::get('/stock-issue', [StockIssueController::class, 'index'])->name('stock.issue');
	Route::get('/stock-issue/export-pdf', [StockIssueController::class, 'exportPdf'])
    ->name('stock.issue.exportPdf');
	Route::get('/stock-issue/export-excel', [StockIssueController::class, 'exportExcel'])
    ->name('stock.issue.exportExcel');

	/*
    |----------------------- Profil & CRUD Mapping ----------------------
    */
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
