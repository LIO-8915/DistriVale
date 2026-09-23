<?php

namespace App\Http\Controllers;

use App\Models\Financiera;
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
