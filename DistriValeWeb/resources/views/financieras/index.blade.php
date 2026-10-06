@extends('layouts.app')

@section('title', 'Financieras')
@section('actions')
    <a href="{{ route('financieras.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nueva financiera</a>
@endsection

@section('content')
<div class="card p-3 mb-3">
    <form action="{{ route('financieras.pdf') }}" method="GET" class="row g-2 align-items-end">
        <div class="col-auto">
            <label class="form-label mb-0 small">Financiera</label>
            <select name="id_financiera" class="form-select form-select-sm">
                <option value="">Todas las financieras</option>
                @foreach ($financieras as $financiera)
                    <option value="{{ $financiera->id_financiera }}">{{ $financiera->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-pdf"></i> PDF de relación de cobranza</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Comisión %</th>
                    <th>Ganancia quincenal %</th>
                    <th>Recargo financiera %</th>
                    <th>Recargo personal %</th>
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
                        <td>{{ $financiera->ganancia_quincenal_porcentaje !== null ? number_format($financiera->ganancia_quincenal_porcentaje, 2).'%' : '—' }}</td>
                        <td>{{ number_format($financiera->recargo_porcentaje, 2) }}%</td>
                        <td>{{ $financiera->recargo_personal_porcentaje !== null ? number_format($financiera->recargo_personal_porcentaje, 2).'%' : '—' }}</td>
                        <td>{{ $financiera->vales_count }}</td>
                        <td>
                            <span class="badge {{ $financiera->activo ? 'bg-success' : 'bg-secondary' }}">
                                {{ $financiera->activo ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="{{ route('financieras.edit', $financiera) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            <form action="{{ route('financieras.destroy', $financiera) }}" method="POST" class="d-inline" data-confirm="¿Eliminar esta financiera?">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No hay financieras registradas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
