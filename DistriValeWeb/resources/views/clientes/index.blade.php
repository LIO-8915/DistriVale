@extends('layouts.app')

@section('title', 'Clientes')
@section('actions')
    <a href="{{ route('clientes.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nuevo cliente</a>
@endsection

@section('content')
<div class="card p-3 mb-3">
    <form method="GET" class="row g-2">
        <div class="col-md-6">
            <input type="text" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Buscar por nombre...">
        </div>
        <div class="col-md-2">
            <button class="btn btn-sm btn-outline-primary w-100">Buscar</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Teléfono</th>
                    <th># Vales</th>
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
                        <td>{{ $cliente->vales_count }}</td>
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
                    <tr><td colspan="5" class="text-center text-muted py-4">No hay clientes registrados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $clientes->links() }}</div>
@endsection
