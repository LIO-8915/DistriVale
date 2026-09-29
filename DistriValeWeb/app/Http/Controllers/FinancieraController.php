<?php

namespace App\Http\Controllers;

use App\Support\Wireframe\Store;
use Illuminate\Http\Request;

class FinancieraController extends Controller
{
    public function index()
    {
        $financieras = Store::financieras();

        return view('financieras.index', compact('financieras'));
    }

    public function create()
    {
        return view('financieras.form', ['financiera' => Store::nuevaFinanciera()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        Store::crearFinanciera($data);

        return redirect()->route('financieras.index')->with('success', 'Financiera registrada correctamente.');
    }

    public function edit($financiera)
    {
        $financiera = Store::financiera($financiera) ?? abort(404);

        return view('financieras.form', compact('financiera'));
    }

    public function update(Request $request, $financiera)
    {
        Store::financiera($financiera) ?? abort(404);
        $data = $this->validated($request);
        Store::actualizarFinanciera($financiera, $data);

        return redirect()->route('financieras.index')->with('success', 'Financiera actualizada correctamente.');
    }

    public function destroy($financiera)
    {
        Store::eliminarFinanciera($financiera);

        return redirect()->route('financieras.index')->with('success', 'Financiera eliminada.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'nombre' => 'required|string|max:50',
            'comision_porcentaje' => 'nullable|numeric|min:0|max:100',
            'recargo_porcentaje' => 'nullable|numeric|min:0|max:100',
            'activo' => 'nullable|boolean',
        ]) + ['activo' => $request->boolean('activo')];
    }
}
