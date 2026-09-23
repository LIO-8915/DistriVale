<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\Request;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        $clientes = Cliente::query()
            ->when($request->filled('q'), fn ($q) => $q->where('nombre_completo', 'like', '%'.$request->q.'%'))
            ->withCount('vales')
            ->orderBy('nombre_completo')
            ->paginate(15)
            ->withQueryString();

        return view('clientes.index', compact('clientes'));
    }

    public function create()
    {
        return view('clientes.form', ['cliente' => new Cliente]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        Cliente::create($data);

        return redirect()->route('clientes.index')->with('success', 'Cliente registrado correctamente.');
    }

    public function show(Cliente $cliente)
    {
        $cliente->load(['vales.financiera', 'recibos' => fn ($q) => $q->latest('fecha_corte')]);

        return view('clientes.show', compact('cliente'));
    }

    public function edit(Cliente $cliente)
    {
        return view('clientes.form', compact('cliente'));
    }

    public function update(Request $request, Cliente $cliente)
    {
        $data = $this->validated($request);
        $cliente->update($data);

        return redirect()->route('clientes.index')->with('success', 'Cliente actualizado correctamente.');
    }

    public function destroy(Cliente $cliente)
    {
        $cliente->delete();

        return redirect()->route('clientes.index')->with('success', 'Cliente eliminado.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'nombre_completo' => 'required|string|max:150',
            'telefono' => 'nullable|string|max:20',
            'direccion' => 'nullable|string',
            'activo' => 'nullable|boolean',
        ]) + ['activo' => $request->boolean('activo')];
    }
}
