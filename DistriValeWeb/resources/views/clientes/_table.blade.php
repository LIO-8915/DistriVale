@include('partials.tabla-controles', ['paginador' => $clientes, 'porPagina' => $porPagina])
<div class="table-responsive">
    {{-- Columnas que se ocultan al angostar la ventana, en este orden:
         Teléfono (<1200px), # Vales (<992px), Estado (<768px). --}}
    <table class="table align-middle mb-0">
        <thead>
            <tr>
                <th class="dv-col-cliente">Nombre</th>
                <th class="d-none d-xl-table-cell">Teléfono</th>
                <th>Financiera</th>
                <th class="d-none d-lg-table-cell"># Vales</th>
                <th>Saldo global</th>
                <th class="d-none d-md-table-cell">Estado</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($clientes as $cliente)
                <tr>
                    <td>
                        <a href="{{ route('clientes.show', $cliente) }}" class="fw-semibold text-decoration-none dv-nombre-cliente" title="{{ $cliente->nombre_completo }}">
                            {{ $cliente->nombre_completo }}
                        </a>
                    </td>
                    <td class="d-none d-xl-table-cell">{{ $cliente->telefono ?: '—' }}</td>
                    <td>{{ $cliente->financierasNombres() }}</td>
                    <td class="d-none d-lg-table-cell">{{ $cliente->vales_count }}</td>
                    <td>${{ number_format($cliente->saldoTotal(), 2) }}</td>
                    <td class="d-none d-md-table-cell">
                        <span class="badge {{ $cliente->activo ? 'bg-success' : 'bg-secondary' }}">
                            {{ $cliente->activo ? 'Activo' : 'Inactivo' }}
                        </span>
                    </td>
                    <td class="text-end">
                        <a href="{{ route('clientes.show', $cliente) }}" class="btn btn-sm btn-outline-secondary" aria-label="Ver cliente" title="Ver cliente"><i class="bi bi-eye"></i></a>
                        <a href="{{ route('clientes.edit', $cliente) }}" class="btn btn-sm btn-outline-secondary" aria-label="Editar cliente" title="Editar cliente"><i class="bi bi-pencil"></i></a>
                        <form action="{{ route('clientes.destroy', $cliente) }}" method="POST" class="d-inline" data-confirm="¿Eliminar este cliente?">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" aria-label="Eliminar cliente" title="Eliminar cliente"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No hay clientes registrados.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
