<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Financiera;
use App\Models\NotaCliente;
use App\Models\Vale;
use App\Support\PorPagina;
use App\Support\Quincena;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q'));
        $porPagina = PorPagina::desde($request);

        $clientes = Cliente::query()
            ->with('vales.financiera')
            ->withCount('vales')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('nombre_completo', 'like', "%{$q}%")
                        ->orWhere('telefono', 'like', "%{$q}%")
                        ->orWhereHas('vales.financiera', fn ($f) => $f->where('nombre', 'like', "%{$q}%"));
                });
            })
            ->when($request->filled('id_financiera'), fn ($query) => $query->whereHas(
                'vales', fn ($v) => $v->where('id_financiera', $request->id_financiera)
            ))
            ->when($request->filled('estado'), fn ($query) => $query->where('activo', $request->estado === 'activo'))
            ->orderBy('nombre_completo')
            ->paginate(PorPagina::tamano($porPagina))
            ->withQueryString();

        $totalClientes = Cliente::count();

        $porFinanciera = Financiera::orderBy('nombre')->get()->map(fn ($f) => [
            'nombre' => $f->nombre,
            'id' => $f->id_financiera,
            'clientes' => Vale::where('id_financiera', $f->id_financiera)->distinct('id_cliente')->count('id_cliente'),
        ]);

        $financieras = Financiera::orderBy('nombre')->get();

        if ($request->ajax() || $request->boolean('partial')) {
            return view('clientes._table', compact('clientes', 'porPagina'));
        }

        return view('clientes.index', compact('clientes', 'totalClientes', 'porFinanciera', 'financieras', 'porPagina'));
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
        $cliente->load([
            'vales.financiera',
            'vales.detallesRecibo' => fn ($q) => $q->latest('created_at'),
            'recibos' => fn ($q) => $q->latest('fecha_corte'),
            'notas',
        ]);

        $ultimoPago = $cliente->ultimoPago();

        return view('clientes.show', compact('cliente', 'ultimoPago'));
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

    public function storeNota(Request $request, Cliente $cliente)
    {
        $request->validate(['contenido' => 'required|string|max:2000']);

        NotaCliente::create([
            'id_cliente' => $cliente->id_cliente,
            'contenido' => $request->contenido,
        ]);

        return back()->with('success', 'Nota agregada.');
    }

    public function pdf(Cliente $cliente)
    {
        // Vigentes + los que se liquidaron en la quincena actual (su último
        // pago es parte de este periodo; los de quincenas anteriores ya no).
        $quincena = Quincena::actual();
        $cliente->load(['vales' => fn ($q) => $q
            ->where(fn ($w) => $w->whereIn('estado', ['ACTIVO', 'EN_MORA'])
                ->orWhere(fn ($l) => $l->where('estado', 'LIQUIDADO')
                    ->whereBetween('fecha_ultimo_pago', [$quincena['inicio']->copy()->startOfDay(), $quincena['fin']->copy()->endOfDay()])))
            ->with('financiera')->orderBy('id_financiera')->orderBy('fecha_disposicion')]);

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
