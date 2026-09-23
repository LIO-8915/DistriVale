@extends('layouts.app')

@section('title', $financiera->exists ? 'Editar financiera' : 'Nueva financiera')

@section('content')
<div class="card p-4" style="max-width: 520px;">
    <form action="{{ $financiera->exists ? route('financieras.update', $financiera) : route('financieras.store') }}" method="POST">
        @csrf
        @if ($financiera->exists) @method('PUT') @endif

        <div class="mb-3">
            <label class="form-label">Nombre</label>
            <input type="text" name="nombre" class="form-control" value="{{ old('nombre', $financiera->nombre) }}" required maxlength="50">
        </div>

        <div class="row">
            <div class="col-6 mb-3">
                <label class="form-label">Comisión (%)</label>
                <input type="number" step="0.01" name="comision_porcentaje" class="form-control" value="{{ old('comision_porcentaje', $financiera->comision_porcentaje ?? 0) }}">
            </div>
            <div class="col-6 mb-3">
                <label class="form-label">Recargo por mora (%)</label>
                <input type="number" step="0.01" name="recargo_porcentaje" class="form-control" value="{{ old('recargo_porcentaje', $financiera->recargo_porcentaje ?? 0) }}">
            </div>
        </div>

        <div class="form-check mb-3">
            <input type="checkbox" name="activo" value="1" class="form-check-input" id="activo" {{ old('activo', $financiera->activo ?? true) ? 'checked' : '' }}>
            <label class="form-check-label" for="activo">Financiera activa</label>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary">Guardar</button>
            <a href="{{ route('financieras.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </form>
</div>
@endsection
