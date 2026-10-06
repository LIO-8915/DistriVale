@extends('layouts.app')

@section('title', 'Recibos generados')
@section('subtitle', $recibos->count().' recibos en esta operación')
@section('actions')
    <button type="button" id="btn-copiar-todo" class="btn btn-sm btn-outline-secondary" onclick="copiarTodo()"><i class="bi bi-clipboard"></i> Copiar todo</button>
    <a href="{{ route('recibos.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Generar más</a>
    <a href="{{ route('recibos.index') }}" class="btn btn-sm btn-outline-secondary" aria-label="Ir al listado de recibos" title="Listado de recibos"><i class="bi bi-list-ul"></i></a>
@endsection

@section('content')
@php
    $dinero = fn ($n) => '$'.number_format((float) $n, 2);
    $totalOportuno = $recibos->sum(fn ($r) => (float) $r->total_oportuno);
    $totalTardio = $recibos->sum(fn ($r) => (float) $r->total_extemporaneo);
@endphp

<div class="card mx-auto dv-recibo" id="resumen-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Cliente</th><th>Corte</th><th class="text-end"># Créditos</th>
                    <th class="text-end">Pago oportuno</th><th class="text-end">Después del corte</th><th class="text-end">Recibo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recibos as $recibo)
                    <tr>
                        <td>
                            <span class="dv-nombre-cliente" title="{{ $recibo->cliente->nombre_completo }}">{{ $recibo->cliente->nombre_completo }}</span>
                            <div class="small text-muted">{{ $recibo->codigoCliente() }}</div>
                        </td>
                        <td>{{ $recibo->fecha_corte->format('d/m/Y') }}</td>
                        <td class="text-end">{{ $recibo->detalles->count() }}</td>
                        <td class="text-end">{{ $dinero($recibo->total_oportuno) }}</td>
                        <td class="text-end">{{ $dinero($recibo->total_extemporaneo) }}</td>
                        <td class="text-end"><a href="{{ route('recibos.show', $recibo) }}" class="btn btn-sm btn-outline-secondary">Ver recibo</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="dv-recibo-totales m-3 mt-0">
        <div class="d-flex justify-content-between gap-3">
            <span>Total pago oportuno ({{ $recibos->count() }} recibos)</span>
            <strong>{{ $dinero($totalOportuno) }}</strong>
        </div>
        <div class="d-flex justify-content-between gap-3 dv-tardio">
            <span>Si se paga después del corte</span>
            <strong>{{ $dinero($totalTardio) }}</strong>
        </div>
    </div>
</div>

<textarea id="resumen-texto" class="d-none" readonly>{{ $textoTodos }}</textarea>

<style>
    .dv-recibo { width: 100%; max-width: 980px; }
    .dv-recibo-totales { background: rgba(255, 255, 255, .82); border-radius: 14px; padding: .8rem 1rem; color: #000; }
    .dv-recibo-totales .dv-tardio { color: #9b1c2c; }
</style>

@push('scripts')
<script>
    // Copia el texto de TODOS los recibos juntos. navigator.clipboard solo existe en conexión segura
    // (https/localhost): por HTTP desde un iPad o celular se cae al método clásico.
    function copiarTodo() {
        var texto = document.getElementById('resumen-texto').value;
        var boton = document.getElementById('btn-copiar-todo');
        function listo(ok) {
            var original = boton.dataset.original || boton.innerHTML;
            boton.dataset.original = original;
            boton.innerHTML = ok ? '<i class="bi bi-check2"></i> Copiado' : '<i class="bi bi-exclamation-triangle"></i> No se pudo copiar';
            clearTimeout(boton._t);
            boton._t = setTimeout(function () { boton.innerHTML = original; }, 2200);
        }
        function clasico() {
            var campo = document.createElement('textarea');
            campo.value = texto;
            campo.setAttribute('readonly', '');
            campo.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
            document.body.appendChild(campo);
            campo.select();
            campo.setSelectionRange(0, texto.length);
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(campo);
            listo(ok);
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(texto).then(function () { listo(true); }, clasico);
        } else {
            clasico();
        }
    }
</script>
@endpush
@endsection
