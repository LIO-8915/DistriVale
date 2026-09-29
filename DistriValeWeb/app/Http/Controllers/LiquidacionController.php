<?php

namespace App\Http\Controllers;

use App\Support\PorPagina;
use App\Support\Quincena;
use App\Support\Wireframe\Store;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class LiquidacionController extends Controller
{
    public function index(Request $request)
    {
        $periodos = Store::liquidaciones()->pluck('periodo_quincena')
            ->merge(Store::recibos()->pluck('periodo_quincena')->filter())
            ->unique()
            ->sortByDesc(fn ($p) => $p)
            ->values();

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
        $matrizPaginada = Store::paginar(collect($matriz), $tamano, null, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);

        $liquidaciones = Store::liquidaciones()->where('periodo_quincena', $periodo)->values();
        $totalesCaptura = [
            'cobrar' => $liquidaciones->sum('monto_cobrar'),
            'poner' => $liquidaciones->sum('monto_poner'),
            'depositar' => $liquidaciones->sum('monto_depositar'),
            'ganancias' => $liquidaciones->sum('monto_ganancias'),
        ];

        $saldoPorFinanciera = Store::financieras();

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
        $financieras = Store::financierasActivas();
        $quincenas = Quincena::listaReciente();
        $actual = Quincena::actual()['periodo_quincena'];

        return view('liquidaciones.create', compact('financieras', 'quincenas', 'actual'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'periodo_quincena' => 'required|string|max:50',
            'filas' => 'required|array|min:1',
            'filas.*.id_financiera' => 'required|integer',
            'filas.*.monto_cobrar' => 'nullable|numeric',
            'filas.*.monto_poner' => 'nullable|numeric',
            'filas.*.monto_ganancias' => 'nullable|numeric',
        ]);

        Store::guardarLiquidacion($request->periodo_quincena, $request->filas);

        return redirect()->route('liquidaciones.index', ['periodo' => $request->periodo_quincena])
            ->with('success', 'Liquidación de quincena guardada.');
    }

    public function pdf(Request $request)
    {
        $periodo = $request->get('periodo') ?: Quincena::actual()['periodo_quincena'];
        [$financieras, $matriz, $totales] = $this->construirMatriz($periodo);
        $liquidaciones = Store::liquidaciones()->where('periodo_quincena', $periodo)->sortBy('fecha_corte')->values();

        $pdf = Pdf::loadView('liquidaciones.pdf', compact('periodo', 'financieras', 'matriz', 'totales', 'liquidaciones'))
            ->setPaper('a4', 'portrait');

        return $pdf->download('liquidacion-'.str($periodo)->slug().'.pdf');
    }

    /**
     * Client × Financiera matrix: para cada cliente con vales vigentes ese
     * periodo, muestra Cuota / Pago / Saldo por financiera, un total por
     * fila, y si el cliente es "Oportuno" (sin ningún vale EN_MORA) o
     * "Extemporáneo" ese periodo. Misma lógica que la versión con base de
     * datos real, pero operando sobre las colecciones de Store.
     */
    private function construirMatriz(string $periodo): array
    {
        $financieras = Store::financieras();
        $rango = Quincena::desdeEtiqueta($periodo);

        // Además de los vales vigentes, entran los que se liquidaron dentro de
        // esta quincena: su última cuota sí se cobró en el periodo.
        $valeEntraEnPeriodo = function ($vale) use ($rango) {
            if (in_array($vale->estado, ['ACTIVO', 'EN_MORA'], true)) {
                return true;
            }

            return $rango && $vale->estado === 'LIQUIDADO' && $vale->fecha_ultimo_pago
                && $vale->fecha_ultimo_pago->between($rango['inicio']->copy()->startOfDay(), $rango['fin']->copy()->endOfDay());
        };

        $clientes = Store::clientes()
            ->filter(fn ($c) => $c->vales->contains($valeEntraEnPeriodo))
            ->map(function ($c) use ($valeEntraEnPeriodo) {
                $c->vales = $c->vales->filter($valeEntraEnPeriodo)->values();

                return $c;
            })
            ->values();

        // Pagos del período, agregados por vale.
        $pagosPorVale = Store::recibos()
            ->where('periodo_quincena', $periodo)
            ->flatMap(fn ($r) => $r->detalles)
            ->groupBy('id_vale')
            ->map(fn ($grupo) => $grupo->sum('monto_pago'));

        $matriz = [];
        $totales = ['por_financiera' => [], 'cuota' => 0, 'pago' => 0, 'saldo' => 0];

        foreach ($financieras as $f) {
            $totales['por_financiera'][$f->id_financiera] = ['cuota' => 0, 'pago' => 0, 'saldo' => 0];
        }

        foreach ($clientes as $cliente) {
            $fila = ['cliente' => $cliente, 'financieras' => [], 'total_cuota' => 0, 'total_pago' => 0, 'total_saldo' => 0, 'oportuno' => true];

            foreach ($financieras as $f) {
                $vales = $cliente->vales->where('id_financiera', $f->id_financiera);

                if ($vales->isEmpty()) {
                    $fila['financieras'][$f->id_financiera] = null;
                    continue;
                }

                $cuota = (float) $vales->sum('cuota_quincenal');
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
