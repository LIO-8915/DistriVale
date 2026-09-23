<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Financiera;
use App\Models\Vale;
use Illuminate\Http\Request;

class ValeController extends Controller
{
    public function index(Request $request)
    {
        $vales = Vale::query()
            ->with(['cliente', 'financiera'])
            ->when($request->filled('id_financiera'), fn ($q) => $q->where('id_financiera', $request->id_financiera))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->estado))
            ->orderByDesc('id_vale')
            ->paginate(15)
            ->withQueryString();

        $financieras = Financiera::orderBy('nombre')->get();

        return view('vales.index', compact('vales', 'financieras'));
    }

    public function create()
    {
        return $this->form(new Vale);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['saldo_pendiente'] = $data['monto_original'];
        Vale::create($data);

        return redirect()->route('vales.index')->with('success', 'Vale registrado correctamente.');
    }

    public function edit(Vale $vale)
    {
        return $this->form($vale);
    }

    public function update(Request $request, Vale $vale)
    {
        $data = $this->validated($request);
        $vale->update($data);

        return redirect()->route('vales.index')->with('success', 'Vale actualizado correctamente.');
    }

    public function destroy(Vale $vale)
    {
        $vale->delete();

        return redirect()->route('vales.index')->with('success', 'Vale eliminado.');
    }

    private function form(Vale $vale)
    {
        return view('vales.form', [
            'vale' => $vale,
            'clientes' => Cliente::orderBy('nombre_completo')->get(),
            'financieras' => Financiera::orderBy('nombre')->get(),
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'id_cliente' => 'required|exists:clientes,id_cliente',
            'id_financiera' => 'required|exists:cat_financieras,id_financiera',
            'folio_vale' => 'required|string|max:50',
            'monto_original' => 'required|numeric|min:0',
            'cuota_quincenal' => 'required|numeric|min:0',
            'total_quincenas' => 'required|integer|min:1',
            'quincena_actual' => 'required|integer|min:1',
            'saldo_pendiente' => 'nullable|numeric|min:0',
            'estado' => 'required|in:ACTIVO,LIQUIDADO,EN_MORA',
        ]);
    }
}
