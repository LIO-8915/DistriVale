<?php

use App\Http\Controllers\ClienteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinancieraController;
use App\Http\Controllers\GoogleDriveController;
use App\Http\Controllers\LiquidacionController;
use App\Http\Controllers\ReciboController;
use App\Http\Controllers\ValeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::resource('financieras', FinancieraController::class)->except(['show']);
Route::resource('clientes', ClienteController::class);
Route::post('clientes/{cliente}/notas', [ClienteController::class, 'storeNota'])->name('clientes.notas.store');
Route::get('clientes/{cliente}/pdf', [ClienteController::class, 'pdf'])->name('clientes.pdf');
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
    Route::get('/pdf', 'pdf')->name('pdf');
});

Route::controller(GoogleDriveController::class)->prefix('drive')->name('drive.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/conectar', 'connect')->name('connect');
    Route::get('/callback', 'callback')->name('callback');
    Route::post('/desconectar', 'disconnect')->name('disconnect');
    Route::post('/respaldar', 'backup')->name('backup');
    Route::post('/restaurar', 'restore')->name('restore');
    Route::post('/deshacer', 'rollback')->name('rollback');
});
