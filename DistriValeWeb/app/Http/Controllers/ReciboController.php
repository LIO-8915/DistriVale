<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\DetalleReciboVale;
use App\Models\ReciboConsolidado;
use App\Models\Vale;
use App\Support\Mora;
use App\Support\PorPagina;
use App\Support\Quincena;
use Illuminate\Http\JsonResponse;
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
        // Solo clientes a los que de verdad se les puede cobrar: al menos un crédito que no
        // esté liquidado. Un cliente sin nada pendiente no aparece en la lista.
        $clientes = Cliente::where('activo', true)
            ->whereHas('vales', fn ($q) => $q->where('estado', '!=', 'LIQUIDADO'))
            ->withCount(['vales as vales_pendientes' => fn ($q) => $q->where('estado', '!=', 'LIQUIDADO')])
            ->orderBy('nombre_completo')
            ->get();

        return view('recibos.create', compact('clientes'));
    }

    /**
     * Créditos a los que se le puede abonar a un cliente (activos y en mora; los liquidados
     * no), ordenados como el recibo: por financiera y folio.
     */
    private function valesPendientes(Cliente $cliente)
    {
        return $cliente->vales()
            ->where('estado', '!=', 'LIQUIDADO')
            ->with('financiera')
            ->get()
            ->sortBy(fn (Vale $v) => [$v->financiera->nombre, $v->folio_vale])
            ->values();
    }

    /** Lo máximo que tiene sentido abonar a un vale: lo que debe, o la cuota si esta es mayor (último pago). */
    private function topeDeAbono(Vale $vale): float
    {
        return max((float) $vale->saldo_pendiente, $vale->montoProximoPago());
    }

    /** Alimenta el selector de créditos de "Generar recibo" al elegir un cliente. */
    public function valesDeCliente(Cliente $cliente): JsonResponse
    {
        return response()->json($this->valesPendientes($cliente)->map(fn (Vale $v) => [
            'id' => $v->id_vale,
            'folio' => $v->folio_vale,
            'financiera' => $v->financiera->nombre,
            'pena' => (float) ($v->financiera->recargo_porcentaje ?? 0),
            'estado' => $v->estado,
            'estado_texto' => $v->estadoLegible(),
            'pago' => $v->numeroPagoTexto(),
            'cuota' => (float) $v->cuota_quincenal,
            'recargo' => (float) $v->recargo_acumulado,
            'sugerido' => $v->montoProximoPago(),
            'saldo' => (float) $v->saldo_pendiente,
            'tope' => round($this->topeDeAbono($v), 2),
            'ultimo_pago' => $v->esUltimoPago(),
        ])->all());
    }

    /**
     * Genera el recibo a partir de los créditos que se eligieron en pantalla (activos o en
     * mora del cliente) y del monto que se va a abonar a cada uno (`abonos[id_vale]`). El
     * monto sugerido es cuota + recargo acumulado, pero se puede cambiar.
     */
    public function store(Request $request)
    {
        $request->validate([
            'id_cliente' => 'required|exists:clientes,id_cliente',
            'fecha_corte' => 'required|date',
            'nombre_distribuidora' => 'required|string|max:150',
            'abonos' => 'required|array|min:1',
            'abonos.*' => 'required|numeric|min:0.01',
        ], [
            'abonos.required' => 'Elige al menos un crédito al que abonar.',
            'abonos.min' => 'Elige al menos un crédito al que abonar.',
            'abonos.*.required' => 'Captura el monto a abonar de cada crédito elegido.',
            'abonos.*.numeric' => 'El monto a abonar debe ser un número.',
            'abonos.*.min' => 'El monto a abonar de cada crédito debe ser mayor a $0.00.',
        ]);

        $cliente = Cliente::findOrFail($request->id_cliente);
        $pendientes = $this->valesPendientes($cliente)->keyBy('id_vale');

        if ($pendientes->isEmpty()) {
            return back()->withInput()->withErrors(['id_cliente' => 'Este cliente no tiene créditos pendientes de pago.']);
        }

        // Solo se aceptan créditos de ESTE cliente que sigan sin liquidar, y montos razonables.
        $errores = [];
        $elegidos = [];
        foreach ($request->input('abonos') as $idVale => $monto) {
            $vale = $pendientes->get((int) $idVale);
            if (! $vale) {
                $errores['abonos'] = 'Uno de los créditos elegidos ya no está disponible para este cliente (puede estar liquidado). Vuelve a elegir el cliente.';
                continue;
            }
            $tope = $this->topeDeAbono($vale);
            if ((float) $monto > $tope + 0.01) {
                $errores['abonos.'.$idVale] = 'El abono del crédito '.$vale->folio_vale.' ($'.number_format((float) $monto, 2).') es mayor a lo que debe ($'.number_format($tope, 2).').';
                continue;
            }
            $elegidos[] = [$vale, round((float) $monto, 2)];
        }
        if ($errores) {
            return back()->withInput()->withErrors($errores);
        }

        $recibo = DB::transaction(function () use ($request, $cliente, $elegidos) {
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

            foreach ($elegidos as [$vale, $monto]) {
                // El monto que se capturó para abonar a este crédito (por defecto, lo que toca
                // cobrar ahora: la cuota más el recargo acumulado — Vale::montoProximoPago()).
                $recargoPct = (float) ($vale->financiera->recargo_porcentaje ?? 0);
                // Si esto se paga después del corte, así de caro sale (monto + pena de la
                // financiera): lo que imprime el recibo de la financiera.
                $montoSiVuelveAFallar = round($monto * (1 + $recargoPct / 100), 2);
                $nuevoSaldo = max(0, (float) $vale->saldo_pendiente - $monto);

                DetalleReciboVale::create([
                    'id_recibo' => $recibo->id_recibo,
                    'id_vale' => $vale->id_vale,
                    'monto_pago' => $monto,
                    'numero_pago_texto' => $vale->numeroPagoTexto(),
                    'nuevo_saldo' => $nuevoSaldo,
                ]);

                $totalOportuno += $monto;
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
