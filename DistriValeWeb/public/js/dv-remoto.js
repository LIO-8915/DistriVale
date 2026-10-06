/**
 * Modal global de solicitudes de acceso remoto (solo se carga en la PC).
 *
 * Mientras el acceso remoto está encendido sondea /acceso-remoto/pendientes
 * cada 2 s y abre solo el modal #modalSolicitudRemota cuando llega una
 * solicitud NUEVA, con su código de 6 dígitos. El aviso sonoro, el parpadeo y
 * el traer la ventana al frente los hace el shell de escritorio (main.rs).
 *
 * El nombre y la IP vienen del dispositivo remoto: se pintan SIEMPRE con
 * textContent, nunca como HTML.
 */
(function () {
    'use strict';

    var modalEl = document.getElementById('modalSolicitudRemota');
    var lista = document.getElementById('dv-solicitudes-lista');
    if (!modalEl || !lista || !window.bootstrap) return;

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var modal = new bootstrap.Modal(modalEl);
    var vistos = {};
    var timer = null;
    var intervalo = 0;
    // Rápido mientras el acceso remoto está encendido (o hay solicitudes); lento
    // mientras está apagado, solo para enterarse si se enciende sin recargar la
    // ventana (p. ej. si no se encendió desde el interruptor de esta pantalla).
    var RAPIDO_MS = 2000, LENTO_MS = 5000;

    function el(tag, clase, texto) {
        var e = document.createElement(tag);
        if (clase) e.className = clase;
        if (texto != null) e.textContent = texto;
        return e;
    }

    function formato(codigo) {
        return String(codigo).replace(/(\d{3})(\d{3})/, '$1 $2');
    }

    function rechazar(id) {
        fetch(modalEl.dataset.urlRechazar.replace('__ID__', id), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfMeta ? csrfMeta.content : '', 'Accept': 'application/json' },
            credentials: 'same-origin',
        }).then(tick, tick);
    }

    function render(items) {
        lista.textContent = '';
        items.forEach(function (p) {
            var caja = el('div', 'text-center border rounded-4 p-3 mb-3');
            caja.appendChild(el('div', 'fw-semibold', p.nombre));
            caja.appendChild(el('div', 'small text-muted mb-2', p.ip));
            var codigo = el('div', null, formato(p.codigo));
            codigo.style.cssText = 'font-size:2.6rem;font-weight:800;letter-spacing:.18em;font-variant-numeric:tabular-nums;line-height:1.1';
            caja.appendChild(codigo);
            var min = Math.floor(p.segundos / 60), seg = p.segundos % 60;
            caja.appendChild(el('div', 'small text-muted mt-1', 'Vence en ' + min + ':' + (seg < 10 ? '0' : '') + seg));
            var btn = el('button', 'btn btn-sm btn-outline-danger mt-2', 'Rechazar');
            btn.type = 'button';
            btn.addEventListener('click', function () { rechazar(p.id); });
            caja.appendChild(btn);
            lista.appendChild(caja);
        });
    }

    function tick() {
        return fetch(modalEl.dataset.urlPendientes, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var items = d.pendientes || [];
                programar(d.activo === false && !items.length ? LENTO_MS : RAPIDO_MS);
                render(items);
                var hayNuevo = items.some(function (p) { return !vistos[p.id + ':' + p.codigo]; });
                items.forEach(function (p) { vistos[p.id + ':' + p.codigo] = true; });
                if (!items.length) modal.hide();
                else if (hayNuevo) modal.show();
            })
            .catch(function () {});
    }

    function programar(ms) {
        if (timer && intervalo === ms) return;
        clearInterval(timer);
        intervalo = ms;
        timer = setInterval(tick, ms);
    }

    // `iniciar()` (lo llama el interruptor de la pantalla "Acceso remoto") pasa a
    // modo rápido y consulta de inmediato.
    function iniciar() {
        programar(RAPIDO_MS);
        tick();
    }

    window.DvRemoto = { iniciar: iniciar };
    // Siempre vigila: si ya estaba encendido, rápido; si no, lento.
    if (modalEl.dataset.activo === '1') iniciar();
    else { programar(LENTO_MS); tick(); }
})();
