@extends('layouts.app')

@section('title', 'Generar recibo consolidado')

@section('content')
<div class="row justify-content-center">
<div class="col-xxl-6 col-xl-7 col-lg-8 col-md-10">
<div class="card p-4">
    <p class="text-muted small">Elige al cliente y los créditos a los que va a abonar. El monto de cada uno viene sugerido (cuota más recargo acumulado) y se puede cambiar. Solo aparecen clientes con créditos pendientes de pago, y de cada uno solo sus créditos activos o en mora.</p>

    <form action="{{ route('recibos.store') }}" method="POST" id="form-recibo"
          data-url="{{ route('recibos.vales-cliente', ['cliente' => '__ID__']) }}"
          data-old="{{ json_encode(old('abonos', []), JSON_FORCE_OBJECT) }}">
        @csrf

        <div class="mb-3">
            <label class="form-label">Cliente</label>
            {{-- Buscador (hay cientos de clientes): escribe parte del nombre y elige. Al elegir, abajo
                 aparecen sus créditos activos. Mismo componente que el formulario de Créditos. --}}
            <div class="dv-searchselect" data-label="Cliente">
                <input type="hidden" name="id_cliente" value="{{ old('id_cliente') }}" required>
                <input type="text" class="form-control dv-searchselect-input" placeholder="Buscar cliente..." autocomplete="off">
                <div class="dv-searchselect-menu"></div>
                <script type="application/json">{!! json_encode($clientes->map(fn ($c) => ['value' => $c->id_cliente, 'label' => $c->nombre_completo])->values()) !!}</script>
            </div>
            <div class="form-text">{{ $clientes->count() }} {{ $clientes->count() === 1 ? 'cliente con créditos pendientes' : 'clientes con créditos pendientes' }}.</div>
        </div>

        <div id="abonos-panel" class="mb-3 d-none">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <label class="form-label mb-0">Créditos a abonar <span class="text-muted fw-normal" id="abonos-conteo"></span></label>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="abonos-todos">Marcar todos</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="abonos-ninguno">Quitar todos</button>
                </div>
            </div>
            <div id="abonos-estado" class="text-muted small mb-2" aria-live="polite"></div>
            <div id="abonos-lista" class="d-flex flex-column gap-2"></div>
            <div class="dv-abonos-total mt-3 d-flex justify-content-between gap-3">
                <span>Total a abonar</span>
                <strong id="abonos-total">$0.00</strong>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Nombre de la distribuidora</label>
            <input type="text" name="nombre_distribuidora" class="form-control" value="{{ old('nombre_distribuidora', 'ELIA MARIA VELIZ MURILLO') }}" required maxlength="150">
        </div>

        <div class="mb-3">
            <label class="form-label">Fecha de corte (quincena)</label>
            <input type="date" name="fecha_corte" class="form-control" value="{{ old('fecha_corte', now()->format('Y-m-d')) }}" required>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary">Generar recibo</button>
            <a href="{{ route('recibos.index') }}" class="btn btn-outline-secondary dv-btn-cancelar">Cancelar</a>
        </div>
    </form>
</div>
</div>
</div>

<style>
    /* Un crédito por tarjeta: datos a la izquierda, monto a abonar a la derecha (en celular, uno bajo el otro). */
    .dv-abono {
        display: grid; grid-template-columns: minmax(0, 1fr) minmax(11rem, 14rem); gap: .5rem 1rem; align-items: center;
        padding: .75rem .9rem; border: 1px solid rgba(20, 30, 50, .14); border-radius: 14px; background: rgba(255, 255, 255, .55);
        transition: opacity .15s ease;
    }
    .dv-abono-off { opacity: .55; }
    .dv-abono .form-check { margin: 0; }
    .dv-abono-datos { margin-top: .2rem; padding-left: 1.5em; font-size: .85rem; }
    .dv-abono-monto label { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-bottom: .15rem; }
    .dv-abono-sug { padding: 0; font-size: .82rem; }
    /* El total va sobre un fondo casi blanco: se lee igual con o sin tema contrastado. */
    .dv-abonos-total { background: rgba(255, 255, 255, .82); border-radius: 14px; padding: .7rem 1rem; color: #000; }
    @media (max-width: 575.98px) { .dv-abono { grid-template-columns: 1fr; } }
</style>

@push('scripts')
<script>
    (function () {
        var form = document.getElementById('form-recibo');
        if (!form) return;
        var hidden = form.querySelector('input[name="id_cliente"]');
        var visible = form.querySelector('.dv-searchselect-input');
        var panel = document.getElementById('abonos-panel');
        var lista = document.getElementById('abonos-lista');
        var estado = document.getElementById('abonos-estado');
        var total = document.getElementById('abonos-total');
        var conteo = document.getElementById('abonos-conteo');
        var urlBase = form.dataset.url;
        var viejos = {};
        try { viejos = JSON.parse(form.dataset.old || '{}') || {}; } catch (e) { viejos = {}; }
        var hayViejos = Object.keys(viejos).length > 0;   // tras un error de validación se restaura lo que se había elegido
        var peticion = 0;

        function dinero(n) { return '$' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
        function el(tag, clase, texto) {
            var e = document.createElement(tag);
            if (clase) e.className = clase;
            if (texto !== undefined && texto !== null) e.textContent = texto;   // textContent: nombres y folios nunca se interpretan como HTML
            return e;
        }

        function recalcular() {
            var suma = 0, marcados = 0, filas = lista.querySelectorAll('.dv-abono');
            filas.forEach(function (f) {
                var chk = f.querySelector('input[type="checkbox"]');
                var campo = f.querySelector('input[type="number"]');
                if (chk.checked) { marcados++; suma += parseFloat(campo.value) || 0; }
            });
            total.textContent = dinero(Math.round(suma * 100) / 100);
            conteo.textContent = filas.length ? '(' + marcados + ' de ' + filas.length + ')' : '';
        }

        function fila(v) {
            var guardado = viejos[v.id];
            var marcado = hayViejos ? guardado !== undefined : true;
            var f = el('div', 'dv-abono' + (marcado ? '' : ' dv-abono-off'));

            var cab = el('div', 'dv-abono-cab');
            var check = el('div', 'form-check');
            var chk = el('input', 'form-check-input');
            chk.type = 'checkbox'; chk.id = 'ab-' + v.id; chk.checked = marcado;
            var etiqueta = el('label', 'form-check-label');
            etiqueta.htmlFor = chk.id;
            etiqueta.appendChild(el('strong', null, v.folio));
            etiqueta.appendChild(document.createTextNode(' '));
            etiqueta.appendChild(el('span', 'badge bg-secondary', v.financiera));
            if (v.estado === 'EN_MORA') { etiqueta.appendChild(document.createTextNode(' ')); etiqueta.appendChild(el('span', 'badge badge-estado-EN_MORA', v.estado_texto)); }
            if (v.ultimo_pago) { etiqueta.appendChild(document.createTextNode(' ')); etiqueta.appendChild(el('span', 'badge badge-estado-LIQUIDADO', 'Último pago')); }
            check.appendChild(chk); check.appendChild(etiqueta);
            cab.appendChild(check);
            cab.appendChild(el('div', 'dv-abono-datos text-muted',
                'Pago ' + v.pago + ' · Cuota ' + dinero(v.cuota) + (v.recargo > 0 ? ' + recargo ' + dinero(v.recargo) : '') + ' · Saldo ' + dinero(v.saldo)
                + (v.pena > 0 ? ' · Pena ' + v.pena + ' %' : '')));

            var monto = el('div', 'dv-abono-monto');
            var etMonto = el('label', null, 'Abono');
            etMonto.htmlFor = 'monto-' + v.id;
            var grupo = el('div', 'input-group');
            grupo.appendChild(el('span', 'input-group-text', '$'));
            var campo = el('input', 'form-control text-end');
            campo.type = 'number'; campo.id = 'monto-' + v.id; campo.name = 'abonos[' + v.id + ']';
            campo.step = '0.01'; campo.min = '0.01'; campo.max = String(v.tope); campo.inputMode = 'decimal';
            campo.value = guardado !== undefined ? guardado : v.sugerido.toFixed(2);
            campo.disabled = !marcado;                       // un campo deshabilitado no se envía: así "no marcado" = "no entra al recibo"
            campo.setAttribute('aria-label', 'Abono al crédito ' + v.folio);
            grupo.appendChild(campo);
            var sug = el('button', 'btn btn-link dv-abono-sug', 'Usar sugerido ' + dinero(v.sugerido));
            sug.type = 'button';
            monto.appendChild(etMonto); monto.appendChild(grupo); monto.appendChild(sug);

            chk.addEventListener('change', function () {
                campo.disabled = !chk.checked;
                f.classList.toggle('dv-abono-off', !chk.checked);
                recalcular();
            });
            campo.addEventListener('input', recalcular);
            sug.addEventListener('click', function () {
                campo.value = v.sugerido.toFixed(2);
                if (!chk.checked) { chk.checked = true; chk.dispatchEvent(new Event('change')); } else { recalcular(); }
            });

            f.appendChild(cab); f.appendChild(monto);
            return f;
        }

        function cargar(id) {
            var mia = ++peticion;
            lista.textContent = '';
            panel.classList.remove('d-none');
            estado.textContent = 'Buscando los créditos del cliente…';
            recalcular();
            fetch(urlBase.replace('__ID__', encodeURIComponent(id)), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
                .then(function (vales) {
                    if (mia !== peticion) return;           // se eligió otro cliente mientras tanto
                    if (!vales.length) { estado.textContent = 'Este cliente ya no tiene créditos pendientes de pago.'; recalcular(); return; }
                    estado.textContent = '';
                    vales.forEach(function (v) { lista.appendChild(fila(v)); });
                    recalcular();
                })
                .catch(function () {
                    if (mia === peticion) estado.textContent = 'No se pudieron cargar los créditos. Vuelve a elegir al cliente.';
                });
        }

        hidden.addEventListener('change', function () {
            viejos = {}; hayViejos = false;                 // lo restaurado solo vale para el cliente con el que falló el envío
            if (hidden.value) { cargar(hidden.value); }
            else { peticion++; lista.textContent = ''; panel.classList.add('d-none'); recalcular(); }
        });
        document.getElementById('abonos-todos').addEventListener('click', function () {
            lista.querySelectorAll('input[type="checkbox"]').forEach(function (c) { if (!c.checked) { c.checked = true; c.dispatchEvent(new Event('change')); } });
        });
        document.getElementById('abonos-ninguno').addEventListener('click', function () {
            lista.querySelectorAll('input[type="checkbox"]').forEach(function (c) { if (c.checked) { c.checked = false; c.dispatchEvent(new Event('change')); } });
        });

        form.addEventListener('submit', function (e) {
            if (!hidden.value) {
                e.preventDefault();
                visible.classList.add('is-invalid');
                visible.focus();
                return;
            }
            visible.classList.remove('is-invalid');
            if (!lista.querySelector('input[type="checkbox"]:checked')) {
                e.preventDefault();
                panel.classList.remove('d-none');
                estado.textContent = 'Elige al menos un crédito al que abonar.';
                panel.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        });

        if (hidden.value) cargar(hidden.value);             // volver a la pantalla tras un error de validación
    })();
</script>
@endpush
@endsection
