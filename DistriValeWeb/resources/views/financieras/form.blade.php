@extends('layouts.app')

@section('title', $financiera->exists ? 'Editar financiera' : 'Nueva financiera')

@section('content')
<div class="row justify-content-center">
<div class="col-xxl-5 col-xl-6 col-lg-7 col-md-9">
<div class="card p-4">
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
                <label class="form-label">Ganancia quincenal (%)</label>
                <input type="number" step="0.01" name="ganancia_quincenal_porcentaje" class="form-control" value="{{ old('ganancia_quincenal_porcentaje', $financiera->ganancia_quincenal_porcentaje) }}" placeholder="Sin definir">
                <div class="form-text">% del total cobrado en la quincena a esta financiera que se queda el distribuidor. Déjalo vacío si aún no está definido.</div>
            </div>
        </div>

        <div class="row">
            <div class="col-6 mb-3">
                <label class="form-label">Recargo de financiera (%)</label>
                <input type="number" step="0.01" name="recargo_porcentaje" class="form-control" value="{{ old('recargo_porcentaje', $financiera->recargo_porcentaje ?? 0) }}">
                <div class="form-text">Recargo por mora que cobra la financiera al cliente — el que usa el sistema para calcular recargos automáticos.</div>
            </div>
            <div class="col-6 mb-3">
                <label class="form-label">Recargo personal (%)</label>
                <input type="number" step="0.01" name="recargo_personal_porcentaje" class="form-control" value="{{ old('recargo_personal_porcentaje', $financiera->recargo_personal_porcentaje) }}" placeholder="Pendiente de definir">
                <div class="form-text text-warning">Pendiente de definir — por ahora solo se guarda el dato, no afecta ningún cálculo.</div>
            </div>
        </div>

        <div class="form-check mb-3">
            <input type="checkbox" name="activo" value="1" class="form-check-input" id="activo" {{ old('activo', $financiera->activo ?? true) ? 'checked' : '' }}>
            <label class="form-check-label" for="activo">Financiera activa</label>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary">Guardar</button>
            <a href="{{ route('financieras.index') }}" class="btn btn-outline-secondary dv-btn-cancelar">Cancelar</a>
        </div>
    </form>
</div>
</div>
</div>
@endsection
