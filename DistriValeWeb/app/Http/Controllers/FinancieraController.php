<?php

namespace App\Http\Controllers;

use App\Models\Financiera;
use App\Models\Vale;
use App\Support\Quincena;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class FinancieraController extends Controller
{
    public function index()
    {
        $financieras = Financiera::withCount('vales')->orderBy('nombre')->get();

        return view('financieras.index', compact('financieras'));
    }

    public function create()
    {
        return view('financieras.form', ['financiera' => new Financiera]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        Financiera::create($data);

        return redirect()->route('financieras.index')->with('success', 'Financiera registrada correctamente.');
    }

    public function edit(Financiera $financiera)
    {
        return view('financieras.form', compact('financiera'));
    }

    public function update(Request $request, Financiera $financiera)
    {
        $data = $this->validated($request);
        $financiera->update($data);

        return redirect()->route('financieras.index')->with('success', 'Financiera actualizada correctamente.');
    }

    public function destroy(Financiera $financiera)
    {
        $financiera->delete();

        return redirect()->route('financieras.index')->with('success', 'Financiera eliminada.');
    }

    /**
     * PDF "Relación de cobranza": todos los clientes con vales ACTIVO o
     * EN_MORA de la financiera seleccionada (o de todas), agrupados por
     * financiera y luego por cliente, en tarjetas de 2 columnas que no se
     * cortan entre hojas — igual que el PDF de recibos CaptaVale que sirvió
     * de referencia: por cada vale, lo que YA debe (saldo actual), lo que
     * le toca pagar esta quincena (Vale::montoProximoPago()) y cómo queda
     * el saldo si lo paga, con un TOTALES por cliente. No depende de que ya
     * exista un recibo/pago capturado — es la proyección de cobro de la
     * quincena, no un historial, por eso incluye a TODOS los clientes con
     * crédito vigente aunque todavía no se les haya cobrado nada en la app.
     * `id_financiera` es opcional: sin él, incluye todas las financieras.
     */
    public function pdf(Request $request)
    {
        // dompdf arma todo en memoria: con el corte real de varias
        // financieras y decenas de clientes, el límite por defecto de PHP
        // (128 MB) no alcanza (mismo ajuste que ya usa LiquidacionController).
        ini_set('memory_limit', '512M');

        $periodo = $request->get('periodo') ?: Quincena::actual()['periodo_quincena'];
        $idFinanciera = $request->get('id_financiera');

        $financieras = $idFinanciera
            ? Financiera::where('id_financiera', $idFinanciera)->get()
            : Financiera::orderBy('nombre')->get();

        $vales = Vale::whereIn('estado', ['ACTIVO', 'EN_MORA'])
            ->when($idFinanciera, fn ($q) => $q->where('id_financiera', $idFinanciera))
            ->with(['cliente', 'financiera'])
            ->get()
            ->sortBy('cliente.nombre_completo');

        $porFinanciera = $vales->groupBy('id_financiera');

        $pdf = Pdf::loadView('financieras.pdf', compact('periodo', 'financieras', 'porFinanciera'))
            ->setPaper('a4', 'portrait');

        $sufijo = $idFinanciera && $financieras->first() ? str($financieras->first()->nombre)->slug() : 'todas';

        return $pdf->download('relacion-cobranza-'.str($periodo)->slug().'-'.$sufijo.'.pdf');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'nombre' => 'required|string|max:50',
            'comision_porcentaje' => 'nullable|numeric|min:0|max:100',
            'recargo_porcentaje' => 'nullable|numeric|min:0|max:100',
            'recargo_personal_porcentaje' => 'nullable|numeric|min:0|max:100',
            'ganancia_quincenal_porcentaje' => 'nullable|numeric|min:0|max:100',
            'activo' => 'nullable|boolean',
        ]) + ['activo' => $request->boolean('activo')];
    }
}
