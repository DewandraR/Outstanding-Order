<?php

namespace App\Http\Controllers;

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MappingController;


Route::get('/', function () {
    return redirect()->route('dashboard');
});


// routes/web.php
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::get('/dashboard/api/t2', [DashboardController::class, 'apiT2'])
    ->name('dashboard.api.t2')->middleware(['auth', 'verified']);

Route::get('/dashboard/api/t3', [DashboardController::class, 'apiT3'])
    ->name('dashboard.api.t3')->middleware(['auth', 'verified']);

Route::get('/dashboard/api/small-qty-details', [DashboardController::class, 'apiSmallQtyDetails'])
    ->name('dashboard.api.smallQtyDetails')->middleware(['auth', 'verified']);

// =================================================================
// TAMBAHKAN RUTE PENCARIAN DI SINI
// =================================================================
Route::get('/dashboard/search', [DashboardController::class, 'search'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard.search');
// =================================================================


Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::resource('mapping', MappingController::class);
});

require __DIR__ . '/auth.php';
