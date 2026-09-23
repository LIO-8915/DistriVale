<?php

use App\Http\Controllers\ClienteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinancieraController;
use App\Http\Controllers\LiquidacionController;
use App\Http\Controllers\ReciboController;
use App\Http\Controllers\ValeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::resource('financieras', FinancieraController::class)->except(['show']);
Route::resource('clientes', ClienteController::class);
Route::resource('vales', ValeController::class)->except(['show']);

Route::controller(ReciboController::class)->prefix('recibos')->name('recibos.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/nuevo', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('/{recibo}', 'show')->name('show');
    Route::post('/{recibo}/confirmar-pago', 'confirmarPago')->name('confirmar-pago');
});

Route::controller(LiquidacionController::class)->prefix('liquidaciones')->name('liquidaciones.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/nueva', 'create')->name('create');
    Route::post('/', 'store')->name('store');
});
