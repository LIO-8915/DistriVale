<div class="px-3 pt-3 pb-2 d-flex justify-content-end">
    @include('partials.por-pagina', ['porPagina' => $porPagina])
</div>
<div class="table-responsive">
    <table class="table align-middle mb-0">
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Teléfono</th>
                <th>Financiera</th>
                <th># Vales</th>
                <th>Saldo global</th>
                <th>Estado</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($clientes as $cliente)
                <tr>
                    <td>
                        <a href="{{ route('clientes.show', $cliente) }}" class="fw-semibold text-decoration-none">
                            {{ $cliente->nombre_completo }}
                        </a>
                    </td>
                    <td>{{ $cliente->telefono ?: '—' }}</td>
                    <td>{{ $cliente->financierasNombres() }}</td>
                    <td>{{ $cliente->vales_count }}</td>
                    <td>${{ number_format($cliente->saldoTotal(), 2) }}</td>
                    <td>
                        <span class="badge {{ $cliente->activo ? 'bg-success' : 'bg-secondary' }}">
                            {{ $cliente->activo ? 'Activo' : 'Inactivo' }}
                        </span>
                    </td>
                    <td class="text-end">
                        <a href="{{ route('clientes.show', $cliente) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                        <a href="{{ route('clientes.edit', $cliente) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                        <form action="{{ route('clientes.destroy', $cliente) }}" method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este cliente?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No hay clientes registrados.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3 px-3 pb-2 dv-pagination">{{ $clientes->links() }}</div>
