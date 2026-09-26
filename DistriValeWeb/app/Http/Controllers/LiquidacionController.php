<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\DetalleReciboVale;
use App\Models\Financiera;
use App\Models\LiquidacionQuincena;
use App\Support\PorPagina;
use App\Support\Quincena;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

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

        // La matriz se pagina en pantalla (10 por defecto, sin recordar la
        // elección entre visitas), pero $totales se calcula antes sobre todos
        // los clientes, así que la fila TOTALES no depende de la página.
        $porPagina = PorPagina::desde($request);
        $tamano = $porPagina ?: max(count($matriz), 1);
        $pagina = LengthAwarePaginator::resolveCurrentPage();
        $matrizPaginada = new LengthAwarePaginator(
            array_slice($matriz, ($pagina - 1) * $tamano, $tamano),
            count($matriz), $tamano, $pagina,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $liquidaciones = LiquidacionQuincena::with('financiera')->where('periodo_quincena', $periodo)->get();
        $totalesCaptura = [
            'cobrar' => $liquidaciones->sum('monto_cobrar'),
            'poner' => $liquidaciones->sum('monto_poner'),
            'depositar' => $liquidaciones->sum('monto_depositar'),
            'ganancias' => $liquidaciones->sum('monto_ganancias'),
        ];

        $saldoPorFinanciera = Financiera::withSum(['vales as saldo' => fn ($q) => $q->where('estado', '!=', 'LIQUIDADO')], 'saldo_pendiente')
            ->orderBy('nombre')->get();

        if ($request->ajax()) {
            return view('liquidaciones._contenido', compact(
                'financieras', 'matriz', 'totales', 'matrizPaginada', 'porPagina', 'liquidaciones', 'totalesCaptura', 'saldoPorFinanciera'
            ));
        }

        return view('liquidaciones.index', compact(
            'periodos', 'periodo', 'financieras', 'matriz', 'totales', 'matrizPaginada', 'porPagina',
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
        // dompdf arma la matriz completa en memoria: con un corte real (~200
        // clientes × 5 financieras) pasa de los 128 MB por defecto de PHP.
        ini_set('memory_limit', '512M');

        $periodo = $request->get('periodo') ?: Quincena::actual()['periodo_quincena'];
        [$financieras, $matriz, $totales] = $this->construirMatriz($periodo);
        $liquidaciones = LiquidacionQuincena::with('financiera')->where('periodo_quincena', $periodo)
            ->orderBy('fecha_corte')->get();

        $pdf = Pdf::loadView('liquidaciones.pdf', compact('periodo', 'financieras', 'matriz', 'totales', 'liquidaciones'))
            ->setPaper('a4', 'portrait');

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

        // Además de los vales vigentes, entran los que se liquidaron dentro de
        // esta quincena: su última cuota sí se cobró en el periodo, y sin ellos
        // los totales dejan de cuadrar con los estados de cuenta.
        $rango = Quincena::desdeEtiqueta($periodo);
        $valesDelPeriodo = function ($q) use ($rango) {
            $q->where(function ($w) use ($rango) {
                $w->whereIn('estado', ['ACTIVO', 'EN_MORA']);
                if ($rango) {
                    $w->orWhere(fn ($l) => $l->where('estado', 'LIQUIDADO')
                        ->whereBetween('fecha_ultimo_pago', [$rango['inicio']->copy()->startOfDay(), $rango['fin']->copy()->endOfDay()]));
                }
            });
        };

        $clientes = Cliente::whereHas('vales', $valesDelPeriodo)
            ->with(['vales' => $valesDelPeriodo])
            ->orderBy('nombre_completo')
            ->get();

        // Pagos del período, agregados por vale en una sola consulta en vez de
        // una consulta por cada combinación cliente × financiera (antes eran
        // cientos de queries en esta pantalla; ahora es una sola).
        $pagosPorVale = DetalleReciboVale::query()
            ->join('recibos_consolidados', 'recibos_consolidados.id_recibo', '=', 'detalle_recibo_vales.id_recibo')
            ->where('recibos_consolidados.periodo_quincena', $periodo)
            ->groupBy('detalle_recibo_vales.id_vale')
            ->selectRaw('detalle_recibo_vales.id_vale, SUM(detalle_recibo_vales.monto_pago) as total_pago')
            ->pluck('total_pago', 'id_vale');

        $matriz = [];
        $totales = ['por_financiera' => [], 'cuota' => 0, 'pago' => 0, 'saldo' => 0];

        foreach ($financieras as $f) {
            $totales['por_financiera'][$f->id_financiera] = ['cuota' => 0, 'pago' => 0, 'saldo' => 0];
        }

        foreach ($clientes as $cliente) {
            $fila = ['cliente' => $cliente, 'financieras' => [], 'total_cuota' => 0, 'total_pago' => 0, 'total_saldo' => 0, 'oportuno' => true];

            foreach ($financieras as $f) {
                // Un cliente suele tener varios vales en la misma financiera
                // (en los estados de cuenta reales hay hasta 7), así que la
                // celda suma todos en vez de tomar solo el primero.
                $vales = $cliente->vales->where('id_financiera', $f->id_financiera);

                if ($vales->isEmpty()) {
                    $fila['financieras'][$f->id_financiera] = null;
                    continue;
                }

                $cuota = (float) $vales->sum('cuota_quincenal');
                // Un vale liquidado en el periodo ya tiene registrado su último
                // pago aunque no haya recibo de por medio (viene del estado de cuenta).
                $pago = (float) $vales->sum(fn ($v) => $pagosPorVale[$v->id_vale]
                    ?? ($v->estado === 'LIQUIDADO' ? (float) $v->cuota_quincenal : 0));
                $saldo = (float) $vales->sum('saldo_pendiente');

                $fila['financieras'][$f->id_financiera] = ['cuota' => $cuota, 'pago' => $pago, 'saldo' => $saldo];

                $fila['total_cuota'] += $cuota;
                $fila['total_pago'] += $pago;
                $fila['total_saldo'] += $saldo;
                if ($vales->contains('estado', 'EN_MORA')) {
                    $fila['oportuno'] = false;
                }

                $totales['por_financiera'][$f->id_financiera]['cuota'] += $cuota;
                $totales['por_financiera'][$f->id_financiera]['pago'] += $pago;
                $totales['por_financiera'][$f->id_financiera]['saldo'] += $saldo;
            }

            $totales['cuota'] += $fila['total_cuota'];
            $totales['pago'] += $fila['total_pago'];
            $totales['saldo'] += $fila['total_saldo'];

            $matriz[] = $fila;
        }

        return [$financieras, $matriz, $totales];
    }
}
