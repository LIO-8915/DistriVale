@extends('layouts.app')

@section('title', 'Registrar liquidación quincenal')

@section('content')
<div class="card p-4">
    <form action="{{ route('liquidaciones.store') }}" method="POST">
        @csrf

        <div class="mb-3" style="max-width: 320px;">
            <label class="form-label">Periodo de quincena</label>
            <select name="periodo_quincena" class="form-select" required>
                @foreach ($quincenas as $q)
                    <option value="{{ $q['periodo_quincena'] }}" @selected(old('periodo_quincena', $actual) === $q['periodo_quincena'])>
                        {{ $q['label'] }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="table-responsive mb-3">
            <table class="table align-middle">
                <thead>
                    <tr><th>Financiera</th><th>Cobrar</th><th>Poner</th><th>Ganancias</th></tr>
                </thead>
                <tbody>
                    @foreach ($financieras as $i => $f)
                        <tr>
                            <td class="align-middle">
                                {{ $f->nombre }}
                                <input type="hidden" name="filas[{{ $i }}][id_financiera]" value="{{ $f->id_financiera }}">
                            </td>
                            <td><input type="number" step="0.01" name="filas[{{ $i }}][monto_cobrar]" class="form-control form-control-sm" value="0"></td>
                            <td><input type="number" step="0.01" name="filas[{{ $i }}][monto_poner]" class="form-control form-control-sm" value="0"></td>
                            <td><input type="number" step="0.01" name="filas[{{ $i }}][monto_ganancias]" class="form-control form-control-sm" value="0"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="text-muted small">Monto a depositar = Cobrar − Poner (se calcula automáticamente al guardar).</p>

        <div class="d-flex gap-2">
            <button class="btn btn-primary">Guardar liquidación</button>
            <a href="{{ route('liquidaciones.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </form>
</div>
@endsection
