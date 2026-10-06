<?php

use App\Http\Controllers\AccesoRemotoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinancieraController;
use App\Http\Controllers\GoogleDriveController;
use App\Http\Controllers\LiquidacionController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\ReciboController;
use App\Http\Controllers\RemotoController;
use App\Http\Controllers\ValeController;
use Illuminate\Support\Facades\Route;

// El callback de OAuth de Google Drive vuelve aquí, a la raíz sin ninguna
// ruta después del puerto (ver GoogleDriveController::connect) — los
// clientes OAuth tipo "Desktop app" solo reconocen automáticamente
// http://127.0.0.1:PUERTO como redirect_uri para cualquier puerto, sin
// registrar nada; agregarle un path (p. ej. /drive/callback) hace que
// Google lo rechace con "Error 400: invalid_request".
Route::get('/', function (\Illuminate\Http\Request $request) {
    if ($request->has('state') && ($request->has('code') || $request->has('error'))) {
        return app(GoogleDriveController::class)->callback($request);
    }

    return app(DashboardController::class)->index();
})->name('dashboard');

Route::resource('financieras', FinancieraController::class)->except(['show']);
Route::get('financieras-pdf', [FinancieraController::class, 'pdf'])->name('financieras.pdf');
Route::resource('clientes', ClienteController::class);
Route::post('clientes/{cliente}/notas', [ClienteController::class, 'storeNota'])->name('clientes.notas.store');
Route::get('clientes/{cliente}/pdf', [ClienteController::class, 'pdf'])->name('clientes.pdf');
Route::resource('vales', ValeController::class);

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

Route::put('perfil', [PerfilController::class, 'update'])->name('perfil.update');

// Respaldo a Google Drive. Desde un dispositivo remoto solo se puede respaldar
// (subir una copia, no cambia nada): conectar la cuenta abre el login de Google
// en el navegador de LA PC, y restaurar/deshacer reemplazan la base de todos.
Route::controller(GoogleDriveController::class)->prefix('drive')->name('drive.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/respaldar', 'backup')->name('backup');

    Route::middleware('solo.local')->group(function () {
        Route::post('/conectar', 'connect')->name('connect');
        Route::post('/desconectar', 'disconnect')->name('disconnect');
        Route::post('/restaurar', 'restore')->name('restore');
        Route::post('/deshacer', 'rollback')->name('rollback');
    });
});

// Acceso remoto — lado dispositivo (iPad, etc.). Alcanzable desde la red local
// cuando el acceso está encendido; ControlAcceso deja pasar estas rutas sin
// autorización previa porque son justo las que la otorgan.
Route::prefix('remoto')->name('remoto.')->controller(RemotoController::class)->group(function () {
    Route::get('/acceso', 'acceso')->name('acceso');
    Route::post('/solicitar', 'solicitar')->name('solicitar');
    Route::post('/verificar', 'verificar')->name('verificar');
    Route::post('/regenerar', 'regenerar')->name('regenerar');
    Route::get('/estado', 'estado')->name('estado');
    Route::post('/salir', 'salir')->name('salir');
});

// Acceso remoto — administración. Solo desde la propia PC.
Route::prefix('acceso-remoto')->name('acceso-remoto.')->middleware('solo.local')
    ->controller(AccesoRemotoController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/estado', 'estado')->name('estado');
        Route::get('/pendientes', 'pendientes')->name('pendientes');
        Route::post('/activar', 'activar')->name('activar');
        Route::post('/desactivar', 'desactivar')->name('desactivar');
        Route::post('/firewall', 'firewall')->name('firewall');
        Route::post('/firewall/verificar', 'verificarFirewall')->name('firewall.verificar');
        Route::post('/solicitudes/{dispositivo}/rechazar', 'rechazar')->name('rechazar');
        Route::post('/dispositivos/{dispositivo}/revocar', 'revocar')->name('revocar');
    });
