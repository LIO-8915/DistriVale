<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Financiera;
use App\Models\LiquidacionQuincena;
use App\Models\Vale;

class DashboardController extends Controller
{
    public function index()
    {
        $totalClientes = Cliente::where('activo', true)->count();
        $totalFinancieras = Financiera::where('activo', true)->count();
        $valesActivos = Vale::where('estado', 'ACTIVO')->count();
        $valesEnMora = Vale::where('estado', 'EN_MORA')->count();
        $totalAdministrado = Vale::sum('monto_original');
        $totalPendiente = Vale::where('estado', '!=', 'LIQUIDADO')->sum('saldo_pendiente');

        $porFinanciera = Financiera::withCount('vales')
            ->withSum(['vales as saldo_financiera' => fn ($q) => $q->where('estado', '!=', 'LIQUIDADO')], 'saldo_pendiente')
            ->orderBy('nombre')
            ->get();

        $ultimaQuincena = LiquidacionQuincena::select('periodo_quincena')
            ->orderByDesc('periodo_quincena')
            ->value('periodo_quincena');

        return view('dashboard', compact(
            'totalClientes',
            'totalFinancieras',
            'valesActivos',
            'valesEnMora',
            'totalAdministrado',
            'totalPendiente',
            'porFinanciera',
            'ultimaQuincena',
        ));
    }
}
