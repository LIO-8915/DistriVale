<?php

namespace App\Http\Controllers;

use App\Support\PorPagina;
use App\Support\Quincena;
use App\Support\Wireframe\Store;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReciboController extends Controller
{
    public function index(Request $request)
    {
        $porPagina = PorPagina::desde($request);
        $recibos = Store::recibos()->sortByDesc('fecha_corte')->values();
        $recibos = Store::paginar($recibos, PorPagina::tamano($porPagina))->withQueryString();

        return view('recibos.index', compact('recibos', 'porPagina'));
    }

    public function create()
    {
        $clientes = Store::clientes()->where('activo', true)->values();

        return view('recibos.create', compact('clientes'));
    }

    /**
     * Genera el recibo consolidado quincenal a partir de los vales ACTIVOS/EN_MORA del cliente.
     */
    public function store(Request $request)
    {
        $request->validate([
            'id_cliente' => 'required|integer',
            'fecha_corte' => 'required|date',
            'nombre_distribuidora' => 'required|string|max:150',
        ]);

        $cliente = Store::cliente($request->id_cliente) ?? abort(404);
        $valesActivos = $cliente->vales->where('estado', '!=', 'LIQUIDADO')->values();

        if ($valesActivos->isEmpty()) {
            return back()->withErrors(['id_cliente' => 'Este cliente no tiene vales activos para consolidar.']);
        }

        $totalOportuno = 0;
        $totalExtemporaneo = 0;
        $filasDetalle = [];

        foreach ($valesActivos as $vale) {
            $cuota = (float) $vale->cuota_quincenal;
            $recargoPct = (float) ($vale->financiera->recargo_porcentaje ?? 0);
            $cuotaExtemporanea = round($cuota * (1 + $recargoPct / 100), 2);
            $nuevoSaldo = max(0, (float) $vale->saldo_pendiente - $cuota);

            $filasDetalle[] = [
                'id_vale' => $vale->id_vale,
                'monto_pago' => $cuota,
                'numero_pago_texto' => $vale->numeroPagoTexto(),
                'nuevo_saldo' => $nuevoSaldo,
            ];

            $totalOportuno += $cuota;
            $totalExtemporaneo += $cuotaExtemporanea;
        }

        $recibo = Store::crearReciboConDetalles([
            'id_cliente' => $cliente->id_cliente,
            'nombre_distribuidora' => $request->nombre_distribuidora,
            'periodo_quincena' => Quincena::paraFecha(Carbon::parse($request->fecha_corte))['periodo_quincena'],
            'fecha_corte' => $request->fecha_corte,
            'total_oportuno' => round($totalOportuno, 2),
            'total_extemporaneo' => round($totalExtemporaneo, 2),
            'fecha_emision' => now()->toDateTimeString(),
        ], $filasDetalle);

        return redirect()->route('recibos.show', $recibo->id_recibo)->with('success', 'Recibo consolidado generado.');
    }

    public function show($recibo)
    {
        $recibo = Store::recibo($recibo) ?? abort(404);

        return view('recibos.show', compact('recibo'));
    }

    /**
     * Marca el recibo como pagado: aplica el abono a cada vale (avanza quincena y saldo).
     */
    public function confirmarPago($recibo)
    {
        $recibo = Store::recibo($recibo) ?? abort(404);
        Store::confirmarPago($recibo->id_recibo);

        return redirect()->route('recibos.show', $recibo->id_recibo)->with('success', 'Pago aplicado a los vales del cliente.');
    }
}
