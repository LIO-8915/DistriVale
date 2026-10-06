@extends('layouts.app')

@section('title', 'Recibo consolidado')
@section('actions')
    <button type="button" id="btn-copiar" class="btn btn-sm btn-outline-secondary" onclick="copiarRecibo()"><i class="bi bi-clipboard"></i> Copiar texto</button>
    @if ($recibo->estaPagado())
        <button type="submit" form="form-confirmar-pago" class="btn btn-sm btn-outline-warning" title="Este recibo ya se pagó: confirmarlo otra vez aplica los pagos de nuevo"><i class="bi bi-arrow-repeat"></i> Aplicar pago otra vez</button>
    @else
        <button type="submit" form="form-confirmar-pago" class="btn btn-sm btn-success"><i class="bi bi-check2-circle"></i> Confirmar pago</button>
    @endif
    <a href="{{ route('recibos.index') }}" class="btn btn-sm btn-outline-secondary" aria-label="Volver a los recibos" title="Volver"><i class="bi bi-arrow-left"></i></a>
@endsection

@section('content')
@php
    $pagado = $recibo->estaPagado();
    $detalles = $recibo->detallesOrdenados();
    $resumen = $recibo->resumenPorFinanciera();
    $dinero = fn ($n) => '$'.number_format((float) $n, 2);
    $fechaCorte = $recibo->fecha_corte->format('d/m/Y');
    $conPena = collect($resumen)->filter(fn ($f) => $f['pct'] > 0);

    $textoRecibo = $recibo->textoParaCopiar();
@endphp

<div class="card p-4 mx-auto dv-recibo" id="recibo-card">
    <div class="text-center mb-3">
        <div class="fw-bold fs-5">{{ $recibo->nombre_distribuidora }}</div>
        <div class="text-muted">Recibo consolidado — corte {{ $fechaCorte }}</div>
        <div class="mt-2">
            @if ($pagado)
                <span class="badge bg-success">Pagado el {{ $recibo->fecha_pago->format('d/m/Y') }}</span>
            @else
                <span class="badge badge-estado-EN_MORA">Pendiente de pago</span>
            @endif
        </div>
    </div>

    <div class="mb-2"><strong>Cliente:</strong> {{ $recibo->cliente->nombre_completo }} <span class="text-muted">({{ $recibo->codigoCliente() }})</span></div>
    @if ($recibo->cliente->telefono)
        <div class="mb-3"><strong>Teléfono:</strong> {{ $recibo->cliente->telefono }}</div>
    @endif

    {{-- Fecha de pago y monto por vale son editables: lo que calcula el
         sistema al generar el recibo es solo una sugerencia, por si el
         cliente termina pagando otro día o un monto distinto. Al confirmar,
         estos son los valores que se aplican de verdad a cada vale. --}}
    <form id="form-confirmar-pago" action="{{ route('recibos.confirmar-pago', $recibo) }}" method="POST"
          data-confirm="{{ $pagado
              ? 'Este recibo ya se marcó como pagado el '.$recibo->fecha_pago->format('d/m/Y').'. Si lo confirmas otra vez, los pagos se aplicarán DE NUEVO a cada vale (avanza la quincena y baja el saldo otra vez). ¿Seguro que quieres hacerlo?'
              : '¿Confirmar que este recibo fue pagado? Esto actualizará el saldo y la quincena de cada vale con la fecha y los montos capturados aquí.' }}">
        @csrf

        <div class="mb-3">
            <label class="form-label">Fecha de pago</label>
            <input type="date" name="fecha_pago" class="form-control" value="{{ old('fecha_pago', optional($recibo->fecha_pago ?? $recibo->fecha_corte)->format('Y-m-d')) }}" required>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0 dv-recibo-tabla">
                <thead>
                    <tr><th>Financiera</th><th>Folio</th><th># Pago</th><th class="text-end">Monto</th><th class="text-end">Nuevo saldo</th></tr>
                </thead>
                <tbody>
                    @foreach ($detalles as $detalle)
                        <tr>
                            <td>{{ $detalle->vale->financiera->nombre }}</td>
                            <td>{{ $detalle->vale->folio_vale }}</td>
                            <td>{{ $detalle->numero_pago_texto }}</td>
                            <td class="text-end">
                                <input type="number" step="0.01" min="0" inputmode="decimal" name="montos[{{ $detalle->id_detalle }}]" class="form-control form-control-sm text-end dv-monto-input" aria-label="Monto de {{ $detalle->vale->folio_vale }}" value="{{ old('montos.'.$detalle->id_detalle, $detalle->monto_pago) }}">
                            </td>
                            <td class="text-end text-nowrap">
                                {{ $dinero($detalle->nuevo_saldo) }}
                                @if ((float) $detalle->nuevo_saldo <= 0)
                                    <span class="badge badge-estado-LIQUIDADO ms-1" title="Con este pago el vale queda liquidado">Liquida</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </form>

    {{-- Totales por financiera (como el recibo impreso de la financiera) y total general --}}
    <h6 class="mt-4 mb-2">Resumen por financiera</h6>
    <div class="table-responsive">
        <table class="table align-middle mb-0 dv-recibo-resumen">
            <thead>
                <tr>
                    <th>Financiera</th><th class="text-end"># Vales</th>
                    <th class="text-end">{{ $pagado ? 'Pagado' : 'Pago oportuno' }}</th>
                    @unless ($pagado)<th class="text-end">Después del {{ $fechaCorte }}</th>@endunless
                    <th class="text-end">Pena</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($resumen as $f)
                    <tr>
                        <td>{{ $f['nombre'] }}</td>
                        <td class="text-end">{{ $f['vales'] }}</td>
                        <td class="text-end">{{ $dinero($f['monto']) }}</td>
                        @unless ($pagado)<td class="text-end">{{ $dinero($f['tardio']) }}</td>@endunless
                        <td class="text-end">{{ $f['pct'] > 0 ? rtrim(rtrim(number_format($f['pct'], 2), '0'), '.').'%' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="dv-recibo-totales mt-3">
        <div class="d-flex justify-content-between gap-3">
            <span>Total pago oportuno</span>
            <strong>{{ $dinero($recibo->total_oportuno) }}</strong>
        </div>
        <div class="d-flex justify-content-between gap-3 dv-tardio">
            <span>Pago después del {{ $fechaCorte }}</span>
            <strong>{{ $dinero($recibo->total_extemporaneo) }}</strong>
        </div>
        <div class="d-flex justify-content-between gap-3 border-top mt-2 pt-2">
            <span>{{ $pagado ? 'Total pagado' : 'Total capturado arriba' }}</span>
            <strong id="total-capturado" data-sugerido="{{ number_format($recibo->total_oportuno, 2, '.', '') }}">{{ $dinero($recibo->totalCapturado()) }}</strong>
        </div>
        <div id="aviso-diferencia" class="small mt-1 d-none"></div>
        @if ($conPena->isNotEmpty())
            <div class="small mt-2 dv-pena-nota">
                Si el pago llega después del {{ $fechaCorte }}, se carga pena:
                {{ $conPena->map(fn ($f) => $f['nombre'].' '.rtrim(rtrim(number_format($f['pct'], 2), '0'), '.').'%')->implode(' · ') }}.
            </div>
        @endif
    </div>
</div>

<textarea id="recibo-texto" class="d-none" readonly>{{ $textoRecibo }}</textarea>

<style>
    /* Antes la tarjeta topaba en 560px y la tabla necesita ~523px dentro de un interior de 510px:
       "Nuevo saldo" salía recortado. */
    .dv-recibo { width: 100%; max-width: 860px; }
    .dv-recibo .dv-monto-input { width: 9.5rem; margin-left: auto; }
    /* Los totales van sobre un fondo casi blanco: el rojo/gris sobre el degradado azul del
       vidrio no se leía (contraste ~2:1). */
    .dv-recibo-totales { background: rgba(255, 255, 255, .82); border-radius: 14px; padding: .8rem 1rem; color: #000; }
    .dv-recibo-totales .dv-tardio { color: #9b1c2c; }
    .dv-recibo-totales .dv-diferencia { color: #7a4a00; font-weight: 600; }
    .dv-recibo-totales .dv-pena-nota { color: #3a3a3a; }
</style>

@push('scripts')
<script>
    // Copiar el recibo. navigator.clipboard SOLO existe en conexión segura (https o localhost):
    // desde un iPad o celular por la red local (http://192.168...) no está definido, así que
    // se cae al método clásico con un campo de texto temporal.
    function copiarRecibo() {
        var texto = document.getElementById('recibo-texto').value;
        var boton = document.getElementById('btn-copiar');
        function listo(ok) {
            if (!boton) return;
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

    // Total capturado en vivo: suma lo que hay en los campos "Monto" y avisa si difiere de lo sugerido.
    (function () {
        var total = document.getElementById('total-capturado');
        var aviso = document.getElementById('aviso-diferencia');
        var campos = document.querySelectorAll('#form-confirmar-pago .dv-monto-input');
        if (!total || !campos.length) return;
        var sugerido = parseFloat(total.dataset.sugerido || '0');
        var dinero = function (n) { return '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
        function recalcular() {
            var suma = 0;
            campos.forEach(function (c) { suma += parseFloat(c.value) || 0; });
            suma = Math.round(suma * 100) / 100;
            total.textContent = dinero(suma);
            var dif = Math.round((suma - sugerido) * 100) / 100;
            if (Math.abs(dif) < 0.005) { aviso.className = 'small mt-1 d-none'; aviso.textContent = ''; return; }
            aviso.className = 'small mt-1 dv-diferencia';
            aviso.textContent = (dif > 0 ? 'Es ' + dinero(dif) + ' más que' : 'Es ' + dinero(-dif) + ' menos que') + ' el pago oportuno sugerido (' + dinero(sugerido) + ').';
        }
        campos.forEach(function (c) { c.addEventListener('input', recalcular); });
        recalcular();
    })();
</script>
@endpush
@endsection
