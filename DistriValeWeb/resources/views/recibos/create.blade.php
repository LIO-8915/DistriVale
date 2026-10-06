@extends('layouts.app')

@section('title', 'Generar recibo consolidado')

@section('content')
<div class="row justify-content-center">
<div class="col-xxl-7 col-xl-8 col-lg-9 col-md-11">

<form action="{{ route('recibos.store') }}" method="POST" id="form-recibo"
      data-url-vales="{{ route('recibos.vales-cliente', ['cliente' => '__ID__']) }}"
      data-max="{{ \App\Http\Controllers\ReciboController::MAX_RECIBOS }}"
      data-fecha="{{ now()->format('Y-m-d') }}"
      data-distribuidora="{{ 'ELIA MARIA VELIZ MURILLO' }}"
      data-old="{{ json_encode(old('recibos', []), JSON_FORCE_OBJECT) }}">
    @csrf

    <div class="card p-3 mb-3">
        <p class="text-muted small mb-0">Elige al cliente y los créditos a los que va a abonar; el monto de cada uno viene sugerido (cuota más recargo acumulado) y se puede cambiar. Con <strong>+ Agregar otro recibo</strong> generas varios recibos a la vez, cada uno con su propio cliente. Solo aparecen clientes con créditos pendientes de pago ({{ $clientes->count() }}) y, de cada uno, sus créditos activos o en mora. Un crédito solo puede ir en un recibo.</p>
    </div>

    <div id="recibos-lista" class="d-flex flex-column gap-3"></div>

    <div class="d-flex justify-content-center my-3">
        <button type="button" id="btn-agregar" class="btn btn-outline-secondary dv-btn-mas"><i class="bi bi-plus-lg"></i> Agregar otro recibo</button>
    </div>

    <div class="card p-3 dv-acciones">
        <div class="dv-abonos-total d-flex justify-content-between gap-3 mb-3">
            <span>Total a abonar (<span id="total-recibos">1 recibo</span>)</span>
            <strong id="total-general">$0.00</strong>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary" id="btn-generar">Generar recibo</button>
            <a href="{{ route('recibos.index') }}" class="btn btn-outline-secondary dv-btn-cancelar">Cancelar</a>
        </div>
    </div>

    {{-- Celular: resumen fijo con el total y el botón de generar siempre a la mano (CSS: solo < 600px) --}}
    <div class="dv-resumen-fijo" aria-live="polite">
        <div class="dv-resumen-fijo-info">
            <strong id="fijo-total">$0.00</strong>
            <span id="fijo-detalle">1 recibo · 0 créditos</span>
        </div>
        <button type="submit" class="btn btn-primary" id="fijo-generar">Generar recibo</button>
    </div>
    <div class="dv-resumen-espacio"></div>
</form>

{{-- Un recibo = una tarjeta. JS la clona al agregar otro; los nombres de los campos (recibos[k][...]) los pone JS. --}}
<template id="tpl-recibo">
    <article class="card p-4 dv-bloque">
        <header class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
            <h6 class="mb-0 dv-bloque-titulo">Recibo <span data-x="numero">1</span> <span class="text-muted fw-normal" data-x="de"></span></h6>
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-x="separar" hidden title="Un recibo por cada financiera de los créditos marcados"><i class="bi bi-diagram-2"></i> Separar por financiera</button>
                <button type="button" class="btn btn-sm btn-outline-danger" data-x="quitar" hidden aria-label="Quitar este recibo"><i class="bi bi-trash"></i> <span data-x="quitar-texto">Quitar</span></button>
            </div>
        </header>

        <div class="mb-3">
            <label class="form-label" data-x="et-cliente">Cliente</label>
            <div class="dv-searchselect" data-label="Cliente" data-x="buscador">
                <input type="hidden" data-campo="id_cliente">
                <input type="text" class="form-control dv-searchselect-input" placeholder="Buscar cliente..." autocomplete="off">
                <div class="dv-searchselect-menu"></div>
            </div>
            <button type="button" class="btn btn-link btn-sm p-0 mt-1" data-x="mismo" hidden></button>
        </div>

        <div class="mb-3 d-none" data-x="panel">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <label class="form-label mb-0">Créditos a abonar <span class="text-muted fw-normal" data-x="conteo"></span></label>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-x="todos">Marcar todos</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-x="ninguno">Quitar todos</button>
                </div>
            </div>
            <div class="text-muted small mb-2" data-x="estado" aria-live="polite"></div>
            <div class="d-flex flex-column gap-2" data-x="creditos"></div>
            <div class="dv-abonos-total mt-3 d-flex justify-content-between gap-3">
                <span>Subtotal de este recibo</span>
                <strong data-x="subtotal">$0.00</strong>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-7">
                <label class="form-label">Nombre de la distribuidora</label>
                <input type="text" class="form-control" data-campo="nombre_distribuidora" required maxlength="150">
            </div>
            <div class="col-md-5">
                <label class="form-label">Fecha de corte (quincena)</label>
                <input type="date" class="form-control" data-campo="fecha_corte" required>
            </div>
        </div>
    </article>
</template>

<script type="application/json" id="clientes-json">{!! json_encode($clientes->map(fn ($c) => ['value' => $c->id_cliente, 'label' => $c->nombre_completo])->values()) !!}</script>
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
    .dv-abono-bloq { opacity: .45; }
    .dv-abono .form-check { margin: 0; }
    .dv-abono-datos { margin-top: .2rem; padding-left: 1.5em; font-size: .85rem; }
    .dv-abono-nota { margin-top: .25rem; padding-left: 1.5em; font-size: .8rem; font-weight: 600; color: #8a4a00; }
    .dv-abono-nota a { color: inherit; }
    .dv-abono-monto label { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-bottom: .15rem; }
    .dv-abono-sug { padding: 0; font-size: .82rem; }
    /* Los totales van sobre un fondo casi blanco: se leen igual con o sin tema contrastado. */
    .dv-abonos-total { background: rgba(255, 255, 255, .82); border-radius: 14px; padding: .7rem 1rem; color: #000; }
    .dv-bloque-titulo { font-size: 1.1rem; }
    .dv-btn-mas { border-radius: 999px; padding: .6rem 1.4rem; font-weight: 700; box-shadow: 0 10px 24px rgba(30, 41, 59, .22); }
    /* Resumen fijo: solo celular. Va por encima de la barra inferior de navegación. */
    .dv-resumen-fijo, .dv-resumen-espacio { display: none; }
    @media (max-width: 575.98px) { .dv-abono { grid-template-columns: 1fr; } }
    @media (max-width: 599.98px) {
        .dv-resumen-espacio { display: block; height: 4.6rem; }
        .dv-resumen-fijo {
            display: flex; align-items: center; justify-content: space-between; gap: .75rem;
            position: fixed; z-index: 14;
            left: calc(var(--dv-m-gutter) + env(safe-area-inset-left, 0px));
            right: calc(var(--dv-m-gutter) + env(safe-area-inset-right, 0px));
            bottom: calc(var(--dv-m-bar-h) + var(--dv-m-bar-gap) + env(safe-area-inset-bottom, 0px) + .55rem);
            padding: .55rem .6rem .55rem .9rem; border-radius: 18px;
            background: rgba(255, 255, 255, .92); border: 1px solid rgba(255, 255, 255, .8);
            box-shadow: 0 12px 30px rgba(30, 41, 59, .3); color: #000;
        }
        .dv-resumen-fijo-info { display: flex; flex-direction: column; line-height: 1.2; min-width: 0; }
        .dv-resumen-fijo-info strong { font-size: 1.15rem; }
        .dv-resumen-fijo-info span { font-size: .8rem; color: #3a3a3a; }
        html.dv-kb .dv-resumen-fijo { display: none; }
    }
</style>

@push('scripts')
<script>
    (function () {
        var form = document.getElementById('form-recibo');
        if (!form) return;
        var plantilla = document.getElementById('tpl-recibo');
        var lista = document.getElementById('recibos-lista');
        var btnAgregar = document.getElementById('btn-agregar');
        var clientesTxt = document.getElementById('clientes-json').textContent;
        var clientes = JSON.parse(clientesTxt);
        var MAX = parseInt(form.dataset.max, 10) || 8;
        var urlVales = form.dataset.urlVales;
        var viejos = [];
        try { var o = JSON.parse(form.dataset.old || '[]'); viejos = Array.isArray(o) ? o : Object.keys(o).map(function (k) { return o[k]; }); } catch (e) { viejos = []; }
        var contador = 0;
        var bloques = [];          // un objeto por tarjeta, en el orden en que se ven
        var cache = {};            // idCliente -> Promise con sus créditos

        function dinero(n) { return '$' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
        function el(tag, clase, texto) {
            var e = document.createElement(tag);
            if (clase) e.className = clase;
            if (texto !== undefined && texto !== null) e.textContent = texto;   // textContent: nombres y folios nunca se interpretan como HTML
            return e;
        }
        function nombreDe(id) { var c = clientes.filter(function (x) { return String(x.value) === String(id); })[0]; return c ? c.label : ''; }
        function numero(b) { return bloques.indexOf(b) + 1; }

        // ------------------------------------------------------------ créditos de un cliente (una sola petición por cliente)
        function valesDe(id) {
            if (!cache[id]) {
                cache[id] = fetch(urlVales.replace('__ID__', encodeURIComponent(id)), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
                    .catch(function (e) { delete cache[id]; throw e; });
            }
            return cache[id];
        }

        // ------------------------------------------------------------ una tarjeta de recibo
        function crearBloque(op) {
            op = op || {};
            var k = contador++;
            var nodo = plantilla.content.firstElementChild.cloneNode(true);
            var b = { k: k, el: nodo, idCliente: '', filas: [], pidioQuitar: false };
            var q = function (x) { return nodo.querySelector('[data-x="' + x + '"]'); };
            b.hidden = nodo.querySelector('[data-campo="id_cliente"]');
            b.distribuidora = nodo.querySelector('[data-campo="nombre_distribuidora"]');
            b.fecha = nodo.querySelector('[data-campo="fecha_corte"]');
            b.visible = nodo.querySelector('.dv-searchselect-input');
            b.panel = q('panel'); b.estado = q('estado'); b.creditos = q('creditos'); b.subtotal = q('subtotal'); b.conteo = q('conteo');
            b.btnSeparar = q('separar'); b.btnQuitar = q('quitar'); b.btnMismo = q('mismo');

            ['id_cliente', 'nombre_distribuidora', 'fecha_corte'].forEach(function (c) { nodo.querySelector('[data-campo="' + c + '"]').name = 'recibos[' + k + '][' + c + ']'; });
            b.distribuidora.value = op.distribuidora || form.dataset.distribuidora;
            b.fecha.value = op.fecha || form.dataset.fecha;
            if (op.cliente) { b.hidden.value = op.cliente; }

            // Buscador de cliente: el mismo componente del formulario de Créditos, con su lista de opciones.
            var datos = document.createElement('script');
            datos.type = 'application/json';
            datos.textContent = clientesTxt;
            q('buscador').appendChild(datos);

            var despues = op.despuesDe ? bloques.indexOf(op.despuesDe) : bloques.length - 1;
            bloques.splice(despues + 1, 0, b);
            if (despues + 1 >= lista.children.length) lista.appendChild(nodo); else lista.insertBefore(nodo, lista.children[despues + 1]);
            if (window.DvSearchSelect) window.DvSearchSelect.init(q('buscador'));

            b.hidden.addEventListener('change', function () {
                // Al elegir un cliente se cierra el teclado (en celular tapaba la lista de créditos y el resumen fijo).
                if (b.hidden.value) { b.visible.blur(); cargarCliente(b, b.hidden.value, null); }
                else { b.idCliente = ''; b.creditos.textContent = ''; b.filas = []; b.panel.classList.add('d-none'); recalcular(); }
            });
            q('todos').addEventListener('click', function () { b.filas.forEach(function (f) { if (!f.chk.disabled && !f.chk.checked) { f.chk.checked = true; f.chk.dispatchEvent(new Event('change')); } }); });
            q('ninguno').addEventListener('click', function () { b.filas.forEach(function (f) { if (f.chk.checked) { f.chk.checked = false; f.chk.dispatchEvent(new Event('change')); } }); });
            b.btnSeparar.addEventListener('click', function () { separar(b); });
            b.btnMismo.addEventListener('click', function () {
                var previo = bloques[bloques.indexOf(b) - 1];
                if (!previo || !previo.idCliente) return;
                b.hidden.value = previo.idCliente;
                b.visible.value = nombreDe(previo.idCliente);
                b.visible.classList.remove('is-invalid');
                b.hidden.dispatchEvent(new Event('change'));
            });
            // Quitar pide un segundo toque (para no perder lo capturado por un toque accidental).
            b.btnQuitar.addEventListener('click', function () {
                if (!b.pidioQuitar && b.filas.some(function (f) { return f.chk.checked; })) {
                    b.pidioQuitar = true;
                    q('quitar-texto').textContent = '¿Seguro?';
                    setTimeout(function () { b.pidioQuitar = false; q('quitar-texto').textContent = 'Quitar'; }, 3000);
                    return;
                }
                quitar(b);
            });
            return b;
        }

        function quitar(b) {
            if (bloques.length === 1) return;
            bloques.splice(bloques.indexOf(b), 1);
            b.el.remove();
            recalcular();
        }

        // ------------------------------------------------------------ créditos del cliente elegido
        // seleccion: {id_vale: monto} para restaurar/transferir; null = valor por defecto de la tarjeta
        // (la primera marca todos los créditos sin recibo pendiente; las demás nacen limpias).
        function cargarCliente(b, id, seleccion) {
            b.idCliente = id;
            b.creditos.textContent = ''; b.filas = [];
            b.panel.classList.remove('d-none');
            b.estado.textContent = 'Buscando los créditos del cliente…';
            recalcular();
            return valesDe(id).then(function (vales) {
                if (b.idCliente !== id) return;
                if (!vales.length) { b.estado.textContent = 'Este cliente ya no tiene créditos pendientes de pago.'; recalcular(); return; }
                b.estado.textContent = '';
                var esPrimera = bloques.indexOf(b) === 0;
                vales.forEach(function (v) {
                    var marcado, monto;
                    if (seleccion) { marcado = seleccion[v.id] !== undefined; monto = marcado ? seleccion[v.id] : null; }
                    else { marcado = esPrimera && !v.recibo_pendiente; monto = null; }
                    var f = fila(b, v, marcado, monto);
                    b.filas.push(f);
                    b.creditos.appendChild(f.fila);
                });
                recalcular();
            }).catch(function () {
                if (b.idCliente === id) b.estado.textContent = 'No se pudieron cargar los créditos. Vuelve a elegir al cliente.';
            });
        }

        function fila(b, v, marcado, montoGuardado) {
            var f = { v: v };
            f.fila = el('div', 'dv-abono' + (marcado ? '' : ' dv-abono-off'));
            var cab = el('div', 'dv-abono-cab');
            var check = el('div', 'form-check');
            f.chk = el('input', 'form-check-input');
            f.chk.type = 'checkbox'; f.chk.id = 'ab-' + b.k + '-' + v.id; f.chk.checked = marcado;
            var etiqueta = el('label', 'form-check-label');
            etiqueta.htmlFor = f.chk.id;
            etiqueta.appendChild(el('strong', null, v.folio));
            etiqueta.appendChild(document.createTextNode(' '));
            etiqueta.appendChild(el('span', 'badge bg-secondary', v.financiera));
            if (v.estado === 'EN_MORA') { etiqueta.appendChild(document.createTextNode(' ')); etiqueta.appendChild(el('span', 'badge badge-estado-EN_MORA', v.estado_texto)); }
            if (v.ultimo_pago) { etiqueta.appendChild(document.createTextNode(' ')); etiqueta.appendChild(el('span', 'badge badge-estado-LIQUIDADO', 'Último pago')); }
            check.appendChild(f.chk); check.appendChild(etiqueta);
            cab.appendChild(check);
            cab.appendChild(el('div', 'dv-abono-datos text-muted',
                'Pago ' + v.pago + ' · Cuota ' + dinero(v.cuota) + (v.recargo > 0 ? ' + recargo ' + dinero(v.recargo) : '') + ' · Saldo ' + dinero(v.saldo)
                + (v.pena > 0 ? ' · Pena ' + v.pena + ' %' : '')));
            f.nota = el('div', 'dv-abono-nota');
            cab.appendChild(f.nota);

            var monto = el('div', 'dv-abono-monto');
            var etMonto = el('label', null, 'Abono');
            etMonto.htmlFor = 'monto-' + b.k + '-' + v.id;
            var grupo = el('div', 'input-group');
            grupo.appendChild(el('span', 'input-group-text', '$'));
            f.campo = el('input', 'form-control text-end');
            f.campo.type = 'number'; f.campo.id = 'monto-' + b.k + '-' + v.id; f.campo.name = 'recibos[' + b.k + '][abonos][' + v.id + ']';
            f.campo.step = '0.01'; f.campo.min = '0.01'; f.campo.max = String(v.tope); f.campo.inputMode = 'decimal';
            f.campo.value = montoGuardado !== null && montoGuardado !== undefined ? montoGuardado : v.sugerido.toFixed(2);
            f.campo.disabled = !marcado;                     // un campo deshabilitado no se envía: "no marcado" = "no entra al recibo"
            f.campo.setAttribute('aria-label', 'Abono al crédito ' + v.folio);
            grupo.appendChild(f.campo);
            f.sug = el('button', 'btn btn-link dv-abono-sug', 'Usar sugerido ' + dinero(v.sugerido));
            f.sug.type = 'button';
            monto.appendChild(etMonto); monto.appendChild(grupo); monto.appendChild(f.sug);

            f.chk.addEventListener('change', function () { f.campo.disabled = !f.chk.checked; f.fila.classList.toggle('dv-abono-off', !f.chk.checked); recalcular(); });
            f.campo.addEventListener('input', recalcular);
            f.sug.addEventListener('click', function () {
                f.campo.value = v.sugerido.toFixed(2);
                if (!f.chk.checked && !f.chk.disabled) { f.chk.checked = true; f.chk.dispatchEvent(new Event('change')); } else { recalcular(); }
            });
            f.fila.appendChild(cab); f.fila.appendChild(monto);
            return f;
        }

        // ------------------------------------------------------------ totales, bloqueos entre recibos y botones
        function recalcular() {
            // Un crédito marcado en un recibo se bloquea en los demás (si no, se cobraría dos veces).
            var usados = {};
            bloques.forEach(function (b) { b.filas.forEach(function (f) { if (f.chk.checked && usados[f.v.id] === undefined) usados[f.v.id] = b; }); });

            var totalGeneral = 0, creditos = 0;
            bloques.forEach(function (b, i) {
                var suma = 0, marcados = 0, financieras = {};
                b.filas.forEach(function (f) {
                    var otro = usados[f.v.id];
                    var bloqueado = otro !== undefined && otro !== b;
                    f.chk.disabled = bloqueado;
                    f.campo.disabled = bloqueado || !f.chk.checked;
                    f.fila.classList.toggle('dv-abono-bloq', bloqueado);
                    f.nota.textContent = '';
                    if (bloqueado) {
                        f.nota.textContent = 'Ya está en el recibo ' + numero(otro);
                    } else if (f.v.recibo_pendiente) {
                        f.nota.textContent = 'Ya está en el recibo del ' + f.v.recibo_pendiente.fecha + ' que sigue sin pagar. ';
                        var a = el('a', null, 'Ver recibo'); a.href = f.v.recibo_pendiente.url; a.target = '_blank'; a.rel = 'noopener';
                        f.nota.appendChild(a);
                    }
                    if (f.chk.checked && !bloqueado) { marcados++; suma += parseFloat(f.campo.value) || 0; financieras[f.v.id_financiera] = true; }
                });
                suma = Math.round(suma * 100) / 100;
                b.subtotal.textContent = dinero(suma);
                b.conteo.textContent = b.filas.length ? '(' + marcados + ' de ' + b.filas.length + ')' : '';
                totalGeneral += suma; creditos += marcados;
                b.el.querySelector('[data-x="numero"]').textContent = String(i + 1);
                b.el.querySelector('[data-x="de"]').textContent = bloques.length > 1 ? 'de ' + bloques.length : '';
                b.btnQuitar.hidden = bloques.length === 1;
                b.btnSeparar.hidden = Object.keys(financieras).length < 2;
                var previo = bloques[i - 1];
                b.btnMismo.hidden = !(i > 0 && !b.hidden.value && previo && previo.idCliente);
                if (!b.btnMismo.hidden) b.btnMismo.textContent = 'Usar el cliente del recibo anterior (' + nombreDe(previo.idCliente) + ')';
            });

            var n = bloques.length;
            var etiqueta = n + (n === 1 ? ' recibo' : ' recibos');
            document.getElementById('total-general').textContent = dinero(totalGeneral);
            document.getElementById('total-recibos').textContent = etiqueta;
            document.getElementById('fijo-total').textContent = dinero(totalGeneral);
            document.getElementById('fijo-detalle').textContent = etiqueta + ' · ' + creditos + (creditos === 1 ? ' crédito' : ' créditos');
            var texto = n === 1 ? 'Generar recibo' : 'Generar ' + n + ' recibos';
            document.getElementById('btn-generar').textContent = texto;
            document.getElementById('fijo-generar').textContent = texto;
            btnAgregar.disabled = n >= MAX;
            btnAgregar.title = n >= MAX ? 'Máximo ' + MAX + ' recibos por operación' : '';
        }

        // ------------------------------------------------------------ Separar por financiera
        // Deja en esta tarjeta los créditos marcados de la primera financiera y manda los de cada
        // una de las demás a un recibo nuevo (mismo cliente, distribuidora y fecha), con sus montos.
        function separar(b) {
            var grupos = [], porFin = {};
            b.filas.forEach(function (f) {
                if (!f.chk.checked || f.chk.disabled) return;
                var id = f.v.id_financiera;
                if (!porFin[id]) { porFin[id] = []; grupos.push(porFin[id]); }
                porFin[id].push(f);
            });
            if (grupos.length < 2) return;
            var cliente = b.idCliente, distribuidora = b.distribuidora.value, fecha = b.fecha.value;
            var ultimo = b;
            var nuevos = grupos.slice(1).map(function (g) {
                var seleccion = {};
                g.forEach(function (f) { seleccion[f.v.id] = f.campo.value; f.chk.checked = false; });   // se liberan aquí para poder marcarse en el nuevo
                return { g: g, seleccion: seleccion };
            });
            recalcular();
            nuevos.forEach(function (n) {
                var nb = crearBloque({ cliente: cliente, distribuidora: distribuidora, fecha: fecha, despuesDe: ultimo });
                nb.visible.value = nombreDe(cliente);
                ultimo = nb;
                cargarCliente(nb, cliente, n.seleccion);
            });
            recalcular();
            ultimo.el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        // ------------------------------------------------------------ agregar otro recibo y envío
        btnAgregar.addEventListener('click', function () {
            if (bloques.length >= MAX) return;
            var b = crearBloque({});
            recalcular();
            b.el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            setTimeout(function () { b.visible.focus({ preventScroll: true }); }, 350);
        });

        form.addEventListener('submit', function (e) {
            var problema = null;
            bloques.forEach(function (b) {
                if (problema) return;
                if (!b.hidden.value) { b.visible.classList.add('is-invalid'); problema = { b: b, campo: b.visible, msg: null }; return; }
                b.visible.classList.remove('is-invalid');
                if (!b.filas.some(function (f) { return f.chk.checked && !f.chk.disabled; })) {
                    b.panel.classList.remove('d-none');
                    b.estado.textContent = 'Elige al menos un crédito al que abonar.';
                    problema = { b: b, campo: b.panel, msg: true };
                }
            });
            if (problema) {
                e.preventDefault();
                e.stopImmediatePropagation();
                problema.b.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                if (!problema.msg) problema.campo.focus({ preventScroll: true });
            }
        });

        // ------------------------------------------------------------ arranque (o restauración tras un error de validación)
        if (viejos.length) {
            viejos.slice(0, MAX).forEach(function (r) {
                var b = crearBloque({ cliente: r.id_cliente || '', distribuidora: r.nombre_distribuidora, fecha: r.fecha_corte });
                if (r.id_cliente) { b.visible.value = nombreDe(r.id_cliente); cargarCliente(b, r.id_cliente, r.abonos || {}); }
            });
        } else {
            crearBloque({});
        }
        recalcular();
    })();
</script>
@endpush
@endsection
