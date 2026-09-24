<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\DetalleReciboVale;
use App\Models\Financiera;
use App\Models\LiquidacionQuincena;
use App\Support\Quincena;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class LiquidacionController extends Controller
{
    public function index(Request $request)
    {
        $periodos = LiquidacionQuincena::select('periodo_quincena')->distinct()
            ->union(\App\Models\ReciboConsolidado::select('periodo_quincena')->distinct()->whereNotNull('periodo_quincena'))
            ->orderByDesc('periodo_quincena')
            ->pluck('periodo_quincena');

        $actual = Quincena::actual();
        if (! $periodos->contains($actual['periodo_quincena'])) {
            $periodos->prepend($actual['periodo_quincena']);
        }

        $periodo = $request->get('periodo') ?: $actual['periodo_quincena'];

        [$financieras, $matriz, $totales] = $this->construirMatriz($periodo);

        $liquidaciones = LiquidacionQuincena::with('financiera')->where('periodo_quincena', $periodo)->get();
        $totalesCaptura = [
            'cobrar' => $liquidaciones->sum('monto_cobrar'),
            'poner' => $liquidaciones->sum('monto_poner'),
            'depositar' => $liquidaciones->sum('monto_depositar'),
            'ganancias' => $liquidaciones->sum('monto_ganancias'),
        ];

        $saldoPorFinanciera = Financiera::withSum(['vales as saldo' => fn ($q) => $q->where('estado', '!=', 'LIQUIDADO')], 'saldo_pendiente')
            ->orderBy('nombre')->get();

        return view('liquidaciones.index', compact(
            'periodos', 'periodo', 'financieras', 'matriz', 'totales',
            'liquidaciones', 'totalesCaptura', 'saldoPorFinanciera'
        ));
    }

    public function create()
    {
        $financieras = Financiera::where('activo', true)->orderBy('nombre')->get();
        $quincenas = Quincena::listaReciente();
        $actual = Quincena::actual()['periodo_quincena'];

        return view('liquidaciones.create', compact('financieras', 'quincenas', 'actual'));
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

            LiquidacionQuincena::updateOrCreate(
                ['periodo_quincena' => $request->periodo_quincena, 'id_financiera' => $fila['id_financiera']],
                ['monto_cobrar' => $cobrar, 'monto_poner' => $poner, 'monto_depositar' => $cobrar - $poner, 'monto_ganancias' => $ganancias]
            );
        }

        return redirect()->route('liquidaciones.index', ['periodo' => $request->periodo_quincena])
            ->with('success', 'Liquidación de quincena guardada.');
    }

    public function pdf(Request $request)
    {
        $periodo = $request->get('periodo') ?: Quincena::actual()['periodo_quincena'];
        [$financieras, $matriz, $totales] = $this->construirMatriz($periodo);

        $pdf = Pdf::loadView('liquidaciones.pdf', compact('periodo', 'financieras', 'matriz', 'totales'))
            ->setPaper('a4', 'landscape');

        return $pdf->download('liquidacion-'.str($periodo)->slug().'.pdf');
    }

    /**
     * Client × Financiera matrix: for each client with an active/overdue vale,
     * shows Cuota / Pago / Saldo per financiera for the given quincena, a row
     * total, and whether the client is "Oportuno" (no overdue vale) or
     * "Extemporáneo" (at least one EN_MORA vale) that period.
     */
    private function construirMatriz(string $periodo): array
    {
        $financieras = Financiera::orderBy('nombre')->get();

        $clientes = Cliente::whereHas('vales', fn ($q) => $q->whereIn('estado', ['ACTIVO', 'EN_MORA']))
            ->with(['vales' => fn ($q) => $q->whereIn('estado', ['ACTIVO', 'EN_MORA'])])
            ->orderBy('nombre_completo')
            ->get();

        $matriz = [];
        $totales = ['por_financiera' => [], 'cuota' => 0, 'pago' => 0, 'saldo' => 0];

        foreach ($financieras as $f) {
            $totales['por_financiera'][$f->id_financiera] = ['cuota' => 0, 'pago' => 0, 'saldo' => 0];
        }

        foreach ($clientes as $cliente) {
            $fila = ['cliente' => $cliente, 'financieras' => [], 'total_cuota' => 0, 'total_pago' => 0, 'total_saldo' => 0, 'oportuno' => true];

            foreach ($financieras as $f) {
                $vale = $cliente->vales->firstWhere('id_financiera', $f->id_financiera);

                if (! $vale) {
                    $fila['financieras'][$f->id_financiera] = null;
                    continue;
                }

                $pago = (float) DetalleReciboVale::where('id_vale', $vale->id_vale)
                    ->whereHas('recibo', fn ($q) => $q->where('periodo_quincena', $periodo))
                    ->sum('monto_pago');

                $fila['financieras'][$f->id_financiera] = [
                    'cuota' => (float) $vale->cuota_quincenal,
                    'pago' => $pago,
                    'saldo' => (float) $vale->saldo_pendiente,
                ];

                $fila['total_cuota'] += (float) $vale->cuota_quincenal;
                $fila['total_pago'] += $pago;
                $fila['total_saldo'] += (float) $vale->saldo_pendiente;
                if ($vale->estado === 'EN_MORA') {
                    $fila['oportuno'] = false;
                }

                $totales['por_financiera'][$f->id_financiera]['cuota'] += (float) $vale->cuota_quincenal;
                $totales['por_financiera'][$f->id_financiera]['pago'] += $pago;
                $totales['por_financiera'][$f->id_financiera]['saldo'] += (float) $vale->saldo_pendiente;
            }

            $totales['cuota'] += $fila['total_cuota'];
            $totales['pago'] += $fila['total_pago'];
            $totales['saldo'] += $fila['total_saldo'];

            $matriz[] = $fila;
        }

        return [$financieras, $matriz, $totales];
    }
}
