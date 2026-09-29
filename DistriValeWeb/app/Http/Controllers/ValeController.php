<?php

namespace App\Http\Controllers;

use App\Support\PorPagina;
use App\Support\Wireframe\Store;
use App\Support\Wireframe\WVale;
use Illuminate\Http\Request;

class ValeController extends Controller
{
    public function index(Request $request)
    {
        $porPagina = PorPagina::desde($request);

        $vales = Store::vales()
            ->when($request->filled('id_financiera'), fn ($col) => $col->where('id_financiera', (int) $request->id_financiera))
            ->when($request->filled('estado'), fn ($col) => $col->where('estado', $request->estado))
            ->sortByDesc('id_vale')
            ->values();

        $vales = Store::paginar($vales, PorPagina::tamano($porPagina))->withQueryString();

        $financieras = Store::financieras();

        if ($request->ajax()) {
            return view('vales._table', compact('vales', 'porPagina'));
        }

        return view('vales.index', compact('vales', 'financieras', 'porPagina'));
    }

    public function create()
    {
        return $this->form(Store::nuevoVale());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['saldo_pendiente'] = $data['monto_original'];
        Store::crearVale($data);

        return redirect()->route('vales.index')->with('success', 'Vale registrado correctamente.');
    }

    public function edit($vale)
    {
        $vale = Store::vale($vale) ?? abort(404);

        return $this->form($vale);
    }

    public function update(Request $request, $vale)
    {
        Store::vale($vale) ?? abort(404);
        $data = $this->validated($request);
        Store::actualizarVale($vale, $data);

        return redirect()->route('vales.index')->with('success', 'Vale actualizado correctamente.');
    }

    public function destroy($vale)
    {
        Store::eliminarVale($vale);

        return redirect()->route('vales.index')->with('success', 'Vale eliminado.');
    }

    private function form(WVale $vale)
    {
        return view('vales.form', [
            'vale' => $vale,
            'clientes' => Store::clientes(),
            'financieras' => Store::financieras(),
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'id_cliente' => 'required|integer',
            'id_financiera' => 'required|integer',
            'folio_vale' => 'required|string|max:50',
            'fecha_disposicion' => 'nullable|date',
            'monto_original' => 'required|numeric|min:0',
            'cuota_quincenal' => 'required|numeric|min:0',
            'total_quincenas' => 'required|integer|min:1',
            'quincena_actual' => 'required|integer|min:1',
            'saldo_pendiente' => 'nullable|numeric|min:0',
            'estado' => 'required|in:ACTIVO,LIQUIDADO,EN_MORA',
        ]);

        // La regla "integer" de arriba solo valida, no convierte — sin este
        // cast, id_cliente/id_financiera quedan como string y ya no calzan
        // (Store compara por === en algunos lados) contra los ids reales,
        // que sí son int (asignados por Store::nextId()).
        $data['id_cliente'] = (int) $data['id_cliente'];
        $data['id_financiera'] = (int) $data['id_financiera'];
        $data['total_quincenas'] = (int) $data['total_quincenas'];
        $data['quincena_actual'] = (int) $data['quincena_actual'];

        return $data;
    }
}
