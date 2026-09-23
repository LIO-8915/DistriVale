<?php

namespace App\Http\Controllers;

use App\Models\Financiera;
use App\Models\LiquidacionQuincena;
use Illuminate\Http\Request;

class LiquidacionController extends Controller
{
    public function index(Request $request)
    {
        $periodo = $request->get('periodo');

        $periodos = LiquidacionQuincena::select('periodo_quincena')
            ->distinct()
            ->orderByDesc('periodo_quincena')
            ->pluck('periodo_quincena');

        $periodo = $periodo ?: $periodos->first();

        $liquidaciones = collect();
        if ($periodo) {
            $liquidaciones = LiquidacionQuincena::with('financiera')
                ->where('periodo_quincena', $periodo)
                ->get();
        }

        $totales = [
            'cobrar' => $liquidaciones->sum('monto_cobrar'),
            'poner' => $liquidaciones->sum('monto_poner'),
            'depositar' => $liquidaciones->sum('monto_depositar'),
            'ganancias' => $liquidaciones->sum('monto_ganancias'),
        ];

        return view('liquidaciones.index', compact('liquidaciones', 'periodos', 'periodo', 'totales'));
    }

    public function create()
    {
        $financieras = Financiera::where('activo', true)->orderBy('nombre')->get();

        return view('liquidaciones.create', compact('financieras'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'periodo_quincena' => 'required|string|max:50',
            'filas' => 'required|array|min:1',
            'filas.*.id_financiera' => 'required|exists:cat_financieras,id_financiera',
            'filas.*.monto_cobrar' => 'nullable|numeric',
            'filas.*.monto_poner' => 'nullable|numeric',
            'filas.*.monto_ganancias' => 'nullable|numeric',
        ]);

        foreach ($request->filas as $fila) {
            $cobrar = (float) ($fila['monto_cobrar'] ?? 0);
            $poner = (float) ($fila['monto_poner'] ?? 0);
            $ganancias = (float) ($fila['monto_ganancias'] ?? 0);

            // Monto a Depositar = Cobrar - Poner
            $depositar = $cobrar - $poner;

            LiquidacionQuincena::updateOrCreate(
                [
                    'periodo_quincena' => $request->periodo_quincena,
                    'id_financiera' => $fila['id_financiera'],
                ],
                [
                    'monto_cobrar' => $cobrar,
                    'monto_poner' => $poner,
                    'monto_depositar' => $depositar,
                    'monto_ganancias' => $ganancias,
                ]
            );
        }

        return redirect()->route('liquidaciones.index', ['periodo' => $request->periodo_quincena])
            ->with('success', 'Liquidación de quincena guardada.');
    }
}
