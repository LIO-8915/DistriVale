@extends('layouts.app')

@section('title', 'Financieras')
@section('actions')
    <a href="{{ route('financieras.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nueva financiera</a>
@endsection

@section('content')
<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Comisión %</th>
                    <th>Recargo %</th>
                    <th># Vales</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($financieras as $financiera)
                    <tr>
                        <td class="fw-semibold">{{ $financiera->nombre }}</td>
                        <td>{{ number_format($financiera->comision_porcentaje, 2) }}%</td>
                        <td>{{ number_format($financiera->recargo_porcentaje, 2) }}%</td>
                        <td>{{ $financiera->vales_count }}</td>
                        <td>
                            <span class="badge {{ $financiera->activo ? 'bg-success' : 'bg-secondary' }}">
                                {{ $financiera->activo ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="{{ route('financieras.edit', $financiera) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            <form action="{{ route('financieras.destroy', $financiera) }}" method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar esta financiera?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No hay financieras registradas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
