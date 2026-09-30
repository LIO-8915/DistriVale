@extends('layouts.app')

@section('title', $vale->exists ? 'Editar crédito' : 'Nuevo crédito')

@section('content')
<div class="card p-4" style="max-width: 640px;">
    <div id="valeFormError" class="alert alert-danger d-none"></div>

    <form action="{{ $vale->exists ? route('vales.update', $vale) : route('vales.store') }}" method="POST" id="valeForm" novalidate>
        @csrf
        @if ($vale->exists) @method('PUT') @endif

        <div class="row">
            <div class="col-6 mb-3">
                <label class="form-label">Cliente</label>
                <div class="dv-searchselect" data-label="Cliente">
                    <input type="hidden" name="id_cliente" value="{{ old('id_cliente', $vale->id_cliente) }}" required>
                    <input type="text" class="form-control dv-searchselect-input" placeholder="Buscar cliente..." autocomplete="off">
                    <div class="dv-searchselect-menu"></div>
                    <script type="application/json">{!! json_encode($clientes->map(fn ($c) => ['value' => $c->id_cliente, 'label' => $c->nombre_completo])->values()) !!}</script>
                </div>
            </div>
            <div class="col-6 mb-3">
                <label class="form-label">Financiera</label>
                <div class="dv-searchselect" data-label="Financiera">
                    <input type="hidden" name="id_financiera" value="{{ old('id_financiera', $vale->id_financiera) }}" required>
                    <input type="text" class="form-control dv-searchselect-input" placeholder="Buscar financiera..." autocomplete="off">
                    <div class="dv-searchselect-menu"></div>
                    <script type="application/json">{!! json_encode($financieras->map(fn ($f) => ['value' => $f->id_financiera, 'label' => $f->nombre])->values()) !!}</script>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-6 mb-3">
                <label class="form-label">Folio del vale</label>
                <input type="text" name="folio_vale" class="form-control" value="{{ old('folio_vale', $vale->folio_vale) }}" data-label="Folio del vale" required maxlength="50">
            </div>
            <div class="col-6 mb-3">
                <label class="form-label">Fecha de disposición</label>
                <input type="date" name="fecha_disposicion" class="form-control" value="{{ old('fecha_disposicion', $vale->fecha_disposicion?->format('Y-m-d')) }}">
            </div>
        </div>

        <div class="row">
            <div class="col-6 mb-3">
                <label class="form-label">Monto original</label>
                <input type="number" step="0.01" min="0.01" id="valeMonto" name="monto_original" class="form-control" value="{{ old('monto_original', $vale->monto_original) }}" data-label="Monto original" required>
            </div>
            <div class="col-6 mb-3">
                <label class="form-label">Cuota quincenal</label>
                <input type="number" step="0.01" id="valeCuota" name="cuota_quincenal" class="form-control" value="{{ old('cuota_quincenal', $vale->cuota_quincenal) }}" data-label="Cuota quincenal" readonly required>
                <div class="form-text">Se calcula sola: monto original ÷ total de quincenas.</div>
            </div>
        </div>

        <div class="row">
            <div class="col-4 mb-3">
                <label class="form-label">Total quincenas</label>
                <input type="number" id="valeTotalQuincenas" name="total_quincenas" class="form-control" value="{{ old('total_quincenas', $vale->total_quincenas ?? 12) }}" data-label="Total quincenas" required min="1">
            </div>
            <div class="col-4 mb-3">
                <label class="form-label">Quincena actual</label>
                <select id="valeQuincenaActual" name="quincena_actual" class="form-select" data-label="Quincena actual" required>
                    <option value="">Seleccione...</option>
                </select>
            </div>
            <div class="col-4 mb-3">
                <label class="form-label">Estado</label>
                <select name="estado" class="form-select" required>
                    @foreach (['ACTIVO', 'EN_MORA', 'LIQUIDADO'] as $estado)
                        <option value="{{ $estado }}" @selected(old('estado', $vale->estado ?? 'ACTIVO') == $estado)>{{ \App\Models\Vale::estadoTexto($estado) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if ($vale->exists)
            <div class="mb-3">
                <label class="form-label">Saldo pendiente</label>
                <input type="number" step="0.01" name="saldo_pendiente" class="form-control" value="{{ old('saldo_pendiente', $vale->saldo_pendiente) }}" required>
            </div>
        @else
            <p class="text-muted small">El saldo pendiente inicial se establece automáticamente igual al monto original.</p>
        @endif

        <div class="d-flex gap-2">
            <button class="btn btn-primary">Guardar</button>
            <a href="{{ route('vales.index') }}" class="btn btn-outline-secondary dv-btn-cancelar">Cancelar</a>
        </div>
    </form>
</div>

@push('scripts')
<script>
(function () {
    var form = document.getElementById('valeForm');
    var montoInput = document.getElementById('valeMonto');
    var cuotaInput = document.getElementById('valeCuota');
    var totalQuincenasInput = document.getElementById('valeTotalQuincenas');
    var quincenaActualSelect = document.getElementById('valeQuincenaActual');
    var errorBox = document.getElementById('valeFormError');

    // La cuota quincenal siempre es monto ÷ quincenas; el campo es de solo
    // lectura, así que nunca se calcula distinto de lo que el usuario ve
    // en monto/plazo.
    function recalcularCuota() {
        var monto = parseFloat(montoInput.value);
        var quincenas = parseInt(totalQuincenasInput.value, 10);
        if (!isFinite(monto) || !quincenas || quincenas < 1) {
            cuotaInput.value = '';
            return;
        }
        cuotaInput.value = (monto / quincenas).toFixed(2);
    }

    // Reconstruye 1..N; cambiar el plazo invalida cualquier "quincena
    // actual" ya elegida, así que siempre se limpia (no se intenta
    // conservar la selección previa).
    function reconstruirQuincenaActual(preservarValor) {
        var total = parseInt(totalQuincenasInput.value, 10);
        var valorPrevio = preservarValor ? quincenaActualSelect.value : '';
        quincenaActualSelect.innerHTML = '<option value="">Seleccione...</option>';
        if (total && total >= 1) {
            for (var i = 1; i <= total; i++) {
                var opt = document.createElement('option');
                opt.value = i;
                opt.textContent = i;
                quincenaActualSelect.appendChild(opt);
            }
        }
        if (valorPrevio && parseInt(valorPrevio, 10) <= total) {
            quincenaActualSelect.value = valorPrevio;
        }
    }

    montoInput.addEventListener('input', recalcularCuota);
    totalQuincenasInput.addEventListener('input', function () {
        recalcularCuota();
        reconstruirQuincenaActual(false);
    });

    // Carga inicial: conserva la quincena actual ya guardada (editar) o la
    // que el usuario tenía tecleada si el envío anterior fue rechazado.
    recalcularCuota();
    reconstruirQuincenaActual(true);
    quincenaActualSelect.value = '{{ old('quincena_actual', $vale->quincena_actual ?? 1) }}';

    form.addEventListener('submit', function (e) {
        var faltantes = [];

        form.querySelectorAll('.dv-searchselect').forEach(function (root) {
            var hidden = root.querySelector('input[type="hidden"]');
            var visible = root.querySelector('.dv-searchselect-input');
            if (!hidden.value) {
                faltantes.push(root.dataset.label);
                visible.classList.add('is-invalid');
            } else {
                visible.classList.remove('is-invalid');
            }
        });

        [montoInput, cuotaInput, totalQuincenasInput, quincenaActualSelect,
         form.querySelector('[name="folio_vale"]')].forEach(function (el) {
            var vacio = el.value === null || String(el.value).trim() === '';
            if (vacio) {
                faltantes.push(el.dataset.label || el.name);
                el.classList.add('is-invalid');
            } else {
                el.classList.remove('is-invalid');
            }
        });

        if (faltantes.length > 0) {
            e.preventDefault();
            errorBox.textContent = 'Completa estos campos antes de guardar: ' + faltantes.join(', ') + '.';
            errorBox.classList.remove('d-none');
            errorBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            errorBox.classList.add('d-none');
        }
    });
})();
</script>
@endpush
@endsection
