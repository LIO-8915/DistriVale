<?php

namespace App\Http\Controllers;

use App\Support\PorPagina;
use App\Support\Quincena;
use App\Support\Wireframe\Store;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q'));
        $porPagina = PorPagina::desde($request);

        $clientes = Store::clientes()
            ->when($q !== '', fn ($col) => $col->filter(fn ($c) => str_contains(mb_strtolower($c->nombre_completo), mb_strtolower($q))
                || str_contains((string) $c->telefono, $q)
                || $c->vales->contains(fn ($v) => str_contains(mb_strtolower($v->financiera->nombre ?? ''), mb_strtolower($q)))
            ))
            ->when($request->filled('id_financiera'), fn ($col) => $col->filter(fn ($c) => $c->vales->contains('id_financiera', (int) $request->id_financiera)
            ))
            ->when($request->filled('estado'), fn ($col) => $col->filter(fn ($c) => $c->activo === ($request->estado === 'activo')
            ))
            ->values();

        $clientes = Store::paginar($clientes, PorPagina::tamano($porPagina))->withQueryString();

        $totalClientes = Store::clientes()->count();

        $porFinanciera = Store::financieras()->map(fn ($f) => [
            'nombre' => $f->nombre,
            'id' => $f->id_financiera,
            'clientes' => Store::vales()->where('id_financiera', $f->id_financiera)->pluck('id_cliente')->unique()->count(),
        ]);

        $financieras = Store::financieras();

        if ($request->ajax() || $request->boolean('partial')) {
            return view('clientes._table', compact('clientes', 'porPagina'));
        }

        return view('clientes.index', compact('clientes', 'totalClientes', 'porFinanciera', 'financieras', 'porPagina'));
    }

    public function create()
    {
        return view('clientes.form', ['cliente' => Store::nuevoCliente()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        Store::crearCliente($data);

        return redirect()->route('clientes.index')->with('success', 'Cliente registrado correctamente.');
    }

    public function show($cliente)
    {
        $cliente = Store::cliente($cliente) ?? abort(404);
        $ultimoPago = $cliente->ultimoPago();

        return view('clientes.show', compact('cliente', 'ultimoPago'));
    }

    public function edit($cliente)
    {
        $cliente = Store::cliente($cliente) ?? abort(404);

        return view('clientes.form', compact('cliente'));
    }

    public function update(Request $request, $cliente)
    {
        Store::cliente($cliente) ?? abort(404);
        $data = $this->validated($request);
        Store::actualizarCliente($cliente, $data);

        return redirect()->route('clientes.index')->with('success', 'Cliente actualizado correctamente.');
    }

    public function destroy($cliente)
    {
        Store::eliminarCliente($cliente);

        return redirect()->route('clientes.index')->with('success', 'Cliente eliminado.');
    }

    public function storeNota(Request $request, $cliente)
    {
        $cliente = Store::cliente($cliente) ?? abort(404);
        $request->validate(['contenido' => 'required|string|max:2000']);

        Store::crearNota($cliente->id_cliente, $request->contenido);

        return back()->with('success', 'Nota agregada.');
    }

    public function pdf($cliente)
    {
        $cliente = Store::cliente($cliente) ?? abort(404);

        // Vigentes + los que se liquidaron en la quincena actual (su último
        // pago es parte de este periodo; los de quincenas anteriores ya no).
        $quincena = Quincena::actual();
        $cliente->vales = $cliente->vales
            ->filter(fn ($v) => in_array($v->estado, ['ACTIVO', 'EN_MORA'], true)
                || ($v->estado === 'LIQUIDADO' && $v->fecha_ultimo_pago
                    && $v->fecha_ultimo_pago->between($quincena['inicio']->copy()->startOfDay(), $quincena['fin']->copy()->endOfDay())))
            ->sortBy(['id_financiera', 'fecha_disposicion'])
            ->values();

        $pdf = Pdf::loadView('clientes.pdf', compact('cliente'));

        return $pdf->download('creditos-'.str($cliente->nombre_completo)->slug().'.pdf');
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
