<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\DetalleReciboVale;
use App\Models\ReciboConsolidado;
use App\Support\Mora;
use App\Support\PorPagina;
use App\Support\Quincena;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReciboController extends Controller
{
    public function index(Request $request)
    {
        $porPagina = PorPagina::desde($request);
        $recibos = ReciboConsolidado::with('cliente')->latest('fecha_corte')
            ->paginate(PorPagina::tamano($porPagina))->withQueryString();

        return view('recibos.index', compact('recibos', 'porPagina'));
    }

    public function create()
    {
        $clientes = Cliente::where('activo', true)->orderBy('nombre_completo')->get();

        return view('recibos.create', compact('clientes'));
    }

    /**
     * Genera el recibo consolidado quincenal a partir de los vales ACTIVOS del cliente.
     */
    public function store(Request $request)
    {
        $request->validate([
            'id_cliente' => 'required|exists:clientes,id_cliente',
            'fecha_corte' => 'required|date',
            'nombre_distribuidora' => 'required|string|max:150',
        ]);

        $cliente = Cliente::with(['vales' => fn ($q) => $q->where('estado', '!=', 'LIQUIDADO')->with('financiera')])
            ->findOrFail($request->id_cliente);

        if ($cliente->vales->isEmpty()) {
            return back()->withErrors(['id_cliente' => 'Este cliente no tiene vales activos para consolidar.']);
        }

        $recibo = DB::transaction(function () use ($request, $cliente) {
            $totalOportuno = 0;
            $totalExtemporaneo = 0;

            $recibo = ReciboConsolidado::create([
                'id_cliente' => $cliente->id_cliente,
                'nombre_distribuidora' => $request->nombre_distribuidora,
                'periodo_quincena' => Quincena::paraFecha(\Carbon\Carbon::parse($request->fecha_corte))['periodo_quincena'],
                'fecha_corte' => $request->fecha_corte,
                'total_oportuno' => 0,
                'total_extemporaneo' => 0,
                'fecha_emision' => now(),
            ]);

            foreach ($cliente->vales as $vale) {
                // Lo que en realidad toca cobrar ahora: la cuota normal más
                // cualquier recargo que ya traiga acumulado de quincenas
                // incompletas o no pagadas (ver Vale::montoProximoPago()).
                $montoEsperado = $vale->montoProximoPago();
                $recargoPct = (float) ($vale->financiera->recargo_porcentaje ?? 0);
                // Si esto TAMBIÉN se paga incompleto o no se paga, así de
                // caro sale la siguiente — mismo cálculo que aplicará
                // Vale::registrarPago()/App\Support\Mora si de verdad pasa.
                $montoSiVuelveAFallar = round($montoEsperado * (1 + $recargoPct / 100), 2);
                $nuevoSaldo = max(0, (float) $vale->saldo_pendiente - $montoEsperado);

                DetalleReciboVale::create([
                    'id_recibo' => $recibo->id_recibo,
                    'id_vale' => $vale->id_vale,
                    'monto_pago' => $montoEsperado,
                    'numero_pago_texto' => $vale->numeroPagoTexto(),
                    'nuevo_saldo' => $nuevoSaldo,
                ]);

                $totalOportuno += $montoEsperado;
                $totalExtemporaneo += $montoSiVuelveAFallar;
            }

            $recibo->update([
                'total_oportuno' => $totalOportuno,
                'total_extemporaneo' => $totalExtemporaneo,
            ]);

            return $recibo;
        });

        return redirect()->route('recibos.show', $recibo)->with('success', 'Recibo consolidado generado.');
    }

    public function show(ReciboConsolidado $recibo)
    {
        $recibo->load(['cliente', 'detalles.vale.financiera']);

        return view('recibos.show', compact('recibo'));
    }

    /**
     * Marca el recibo como pagado: aplica el abono a cada vale (avanza
     * quincena y saldo). La fecha de pago y el monto de cada vale son
     * editables desde recibos/show justo antes de confirmar — si el
     * cliente pagó otro día o un monto distinto al sugerido, se captura
     * aquí en vez de forzar el valor calculado al generar el recibo.
     */
    public function confirmarPago(Request $request, ReciboConsolidado $recibo)
    {
        $validated = $request->validate([
            'fecha_pago' => 'required|date',
            'montos' => 'required|array',
            'montos.*' => 'required|numeric|min:0',
        ]);

        $recibo->load('detalles.vale.financiera');
        $fechaPago = \Carbon\Carbon::parse($validated['fecha_pago']);

        DB::transaction(function () use ($recibo, $validated, $fechaPago) {
            foreach ($recibo->detalles as $detalle) {
                $monto = (float) ($validated['montos'][$detalle->id_detalle] ?? $detalle->monto_pago);
                $detalle->vale->registrarPago($monto, $fechaPago);

                $detalle->update([
                    'monto_pago' => $monto,
                    'nuevo_saldo' => $detalle->vale->saldo_pendiente,
                ]);
            }

            $recibo->update(['fecha_pago' => $fechaPago]);
        });

        // Un vale en mora que se acaba de pagar vuelve a ACTIVO en el acto,
        // sin esperar a la revisión diaria.
        Mora::actualizar();

        return redirect()->route('recibos.show', $recibo)->with('success', 'Pago aplicado a los vales del cliente.');
    }
}
