/**
 * DistriVale en celulares y pantallas táctiles (estilos: css/dv-mobile.css).
 *
 *  1. Categoría de pantalla  → <html data-pantalla="compacto|estandar|grande|extra">
 *  2. Tablas como tarjetas   → agrega data-label a cada celda a partir del <thead>
 *  3. Barra inferior         → se esconde con el teclado; cierra la hoja "Más" al navegar
 *  4. Jalar para actualizar  → deslizar hacia abajo estando arriba del todo
 *
 * Todo es JS propio, sin librerías (igual que dv-nav.js).
 */
(function () {
    'use strict';

    var doc = document;
    var root = doc.documentElement;

    // ---------------------------------------------------------------- 1. Categoría
    // Mismos cortes que dv-mobile.css (ancho CSS en vertical, no píxeles físicos).
    function categoria(ancho) {
        if (ancho <= 374) return 'compacto';
        if (ancho <= 399) return 'estandar';
        if (ancho <= 429) return 'grande';
        if (ancho <= 599) return 'extra';
        return '';
    }

    function marcarPantalla() {
        var c = categoria(window.innerWidth);
        if (c) root.dataset.pantalla = c;
        else delete root.dataset.pantalla;
    }

    // ---------------------------------------------------------------- 2. Tablas
    // Si una celda trae varios nodos (un monto y debajo "+$1,650.00 recargo"),
    // se agrupan en un solo bloque para que el diseño de tarjeta no los separe.
    function envolverValor(td) {
        if (td.childNodes.length <= 1) return;
        if (td.children.length === 1 && td.children[0].classList.contains('dv-stack-val')) return;
        var caja = doc.createElement('span');
        caja.className = 'dv-stack-val';
        while (td.firstChild) caja.appendChild(td.firstChild);
        td.appendChild(caja);
    }

    // Las tablas de listas (Clientes, Vales, Cobranza, Financieras, Liquidación)
    // pasan a tarjetas en celular. En vez de editar cada vista, la etiqueta de
    // cada dato se saca del encabezado de su propia columna.
    // Solo en ancho de celular: en tablet/PC el DOM de las tablas se queda como lo
    // entrega el servidor.
    var mqMovil = window.matchMedia ? window.matchMedia('(max-width: 599.98px)') : null;

    function etiquetarTablas(raiz) {
        if (!mqMovil || !mqMovil.matches) return;
        (raiz || doc).querySelectorAll('#dv-view table.table').forEach(function (tabla) {
            // La matriz cliente × financiera es ancha por naturaleza: scroll lateral.
            if (tabla.closest('.dv-matrix-scroll')) return;
            var cabeza = tabla.querySelector('thead > tr');
            if (!cabeza) return;
            var etiquetas = [];
            Array.prototype.forEach.call(cabeza.children, function (th) {
                var n = th.colSpan || 1;
                for (var i = 0; i < n; i++) etiquetas.push(i === 0 ? th.textContent.trim() : '');
            });

            tabla.classList.add('dv-stack');
            var envoltura = tabla.closest('.table-responsive');
            if (envoltura) envoltura.classList.add('dv-stack-wrap');

            tabla.querySelectorAll('tbody > tr').forEach(function (fila) {
                var celdas = fila.children;
                if (celdas.length === 1 && celdas[0].colSpan > 1) {   // "No hay registros…"
                    fila.classList.add('dv-stack-vacia');
                    return;
                }
                var col = 0;
                Array.prototype.forEach.call(celdas, function (td, i) {
                    var etiqueta = etiquetas[col] || '';
                    col += td.colSpan || 1;
                    var esUltima = i === celdas.length - 1;
                    var esAcciones = esUltima && celdas.length > 1 && (etiqueta === '' || /acciones/i.test(etiqueta));
                    td.dataset.label = etiqueta;
                    // Solo la primera columna que nombra a la persona/cosa (Cliente, Financiera, Vale…) hace de título de la tarjeta;
                    // si es otra cosa (p. ej. "# Pago") se deja como un renglón más, con su etiqueta.
                    td.classList.toggle('dv-stack-head', i === 0 && /cliente|nombre|vale|financiera/i.test(etiqueta));
                    td.classList.toggle('dv-stack-acciones', esAcciones);
                    if (!esAcciones && !td.classList.contains('dv-stack-head')) envolverValor(td);
                });
            });
        });
    }

    var etiquetarPendiente = null;
    function etiquetarPronto() {
        if (etiquetarPendiente) return;
        etiquetarPendiente = setTimeout(function () {
            etiquetarPendiente = null;
            etiquetarTablas();
        }, 30);
    }

    // ---------------------------------------------------------------- 3. Barra inferior
    var CAMPOS_DE_TEXTO = 'input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]):not([type=file]):not([type=range]), textarea, select';
    var tecladoTimer = null;

    function iniciarBarra() {
        doc.addEventListener('focusin', function (e) {
            if (!e.target.matches || !e.target.matches(CAMPOS_DE_TEXTO)) return;
            clearTimeout(tecladoTimer);
            root.classList.add('dv-kb');
        });
        doc.addEventListener('focusout', function () {
            clearTimeout(tecladoTimer);
            // Pequeña espera: al pasar de un campo a otro no debe "parpadear" la barra.
            tecladoTimer = setTimeout(function () {
                if (!doc.activeElement || !doc.activeElement.matches || !doc.activeElement.matches(CAMPOS_DE_TEXTO)) {
                    root.classList.remove('dv-kb');
                }
            }, 150);
        });

        // Al elegir una opción de la hoja "Más", dv-nav.js navega sin recargar:
        // la hoja se cierra para no quedarse tapando la pantalla nueva.
        doc.addEventListener('dv:nav-start', function () {
            var hoja = doc.getElementById('dvMasSheet');
            if (!hoja || !window.bootstrap || !hoja.classList.contains('show')) return;
            var inst = window.bootstrap.Offcanvas.getInstance(hoja);
            if (inst) inst.hide();
        });
    }

    // ---------------------------------------------------------------- 4. Jalar para actualizar
    var HOLD_PX = 56;          // dónde se queda el círculo mientras actualiza
    var MAX_PX = 120;          // tope del jalón
    var RESISTENCIA = 0.55;    // el dedo recorre más de lo que baja el círculo

    function iniciarPTR() {
        var main = doc.querySelector('.dv-main');
        if (!main) return;
        // Solo pantallas táctiles: en la PC con mouse no hay nada que jalar.
        var tactil = ('ontouchstart' in window) || (window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
        if (!tactil) return;

        var ind = doc.createElement('div');
        ind.className = 'dv-ptr';
        ind.setAttribute('role', 'status');
        ind.setAttribute('aria-live', 'polite');
        ind.dataset.estado = 'reposo';
        ind.innerHTML = '<i class="bi bi-arrow-down" aria-hidden="true"></i>';
        doc.body.appendChild(ind);

        var msg = doc.createElement('div');
        msg.className = 'dv-ptr-msg';
        msg.setAttribute('role', 'status');
        doc.body.appendChild(msg);
        var msgTimer = null;

        var siguiendo = false;   // hay un dedo que empezó arriba del todo
        var decidido = false;    // ya se sabe si el gesto es un jalón vertical o no
        var activo = false;      // se está jalando de verdad
        var ocupado = false;     // actualizando: no se acepta otro jalón
        var x0 = 0, y0 = 0, jalon = 0, cruzoUmbral = false;

        function umbral() {
            return Math.max(56, Math.min(72, Math.round(window.innerHeight * 0.075)));
        }

        function poner(px) {
            jalon = px;
            main.style.setProperty('--dv-pull', px + 'px');
            ind.style.setProperty('--dv-pull', px + 'px');
            ind.style.setProperty('--dv-pull-n', String(px));
        }

        function estado(nombre) {
            ind.dataset.estado = nombre;
            if (nombre === 'actualizando') ind.innerHTML = '<div class="spinner-border" aria-hidden="true"></div><span class="visually-hidden">Actualizando…</span>';
            else if (nombre === 'ok') ind.innerHTML = '<i class="bi bi-check2-circle" aria-hidden="true"></i><span class="visually-hidden">Actualizado</span>';
            else if (nombre === 'bloqueado') ind.innerHTML = '<i class="bi bi-exclamation-lg" aria-hidden="true"></i>';
            else if (!ind.querySelector('.bi-arrow-down')) ind.innerHTML = '<i class="bi bi-arrow-down" aria-hidden="true"></i>';
        }

        function aviso(texto) {
            msg.textContent = texto;
            msg.classList.add('show');
            clearTimeout(msgTimer);
            msgTimer = setTimeout(function () { msg.classList.remove('show'); }, 2200);
        }

        function soltarAnimado() {
            ind.classList.add('dv-ptr-soltado');
            main.classList.add('dv-ptr-soltado');
            poner(0);
            setTimeout(function () {
                ind.classList.remove('dv-ptr-soltado', 'dv-ptr-visible');
                main.classList.remove('dv-ptr-soltado', 'dv-ptr-activo');
                main.style.removeProperty('--dv-pull');
                estado('reposo');
                ocupado = false;
            }, 330);
        }

        function vibrar(ms) {
            try { if (navigator.vibrate) navigator.vibrate(ms); } catch (e) { /* no todos lo permiten */ }
        }

        // Un formulario a medio llenar se perdería al recargar la pantalla.
        function hayCambiosSinGuardar() {
            return !!doc.querySelector('#dv-view form[data-dv-sucio]');
        }
        doc.addEventListener('input', function (e) {
            var form = e.target.closest && e.target.closest('#dv-view form');
            if (form && (form.getAttribute('method') || 'get').toLowerCase() !== 'get') form.dataset.dvSucio = '1';
        }, true);

        function hayCapaAbierta() {
            return !!doc.querySelector('.modal.show, .offcanvas.show');
        }

        function refrescar() {
            ocupado = true;
            estado('actualizando');
            poner(HOLD_PX);
            var inicio = Date.now();
            var tarea;
            try {
                tarea = window.DvNav && window.DvNav.refresh ? window.DvNav.refresh() : (window.location.reload(), null);
            } catch (e) { tarea = null; }

            // actualizado === false: no se pudo (sin conexión con la computadora, o la
            // petición se canceló). No debe verse como "Actualizado".
            function terminar(actualizado) {
                // Que el círculo se alcance a ver aunque el servidor responda al instante.
                var resta = Math.max(0, 550 - (Date.now() - inicio));
                setTimeout(function () {
                    if (actualizado === false) {
                        var sinRed = window.DvConexion && window.DvConexion.estado() === 'caido';
                        estado('bloqueado');
                        vibrar([10, 40, 10]);
                        aviso(sinRed ? 'Sin conexión con la computadora' : 'No se pudo actualizar');
                        setTimeout(soltarAnimado, 600);
                        return;
                    }
                    estado('ok');
                    vibrar(12);
                    setTimeout(soltarAnimado, 420);
                }, resta);
            }
            Promise.resolve(tarea).then(terminar, function () { terminar(false); });
        }

        main.addEventListener('touchstart', function (e) {
            if (ocupado || e.touches.length !== 1 || main.scrollTop > 0 || hayCapaAbierta()) { siguiendo = false; return; }
            if (e.target.closest && e.target.closest('.modal, .offcanvas, .dv-searchselect-menu, [data-no-ptr], input, textarea, select')) { siguiendo = false; return; }
            siguiendo = true; decidido = false; activo = false; cruzoUmbral = false;
            x0 = e.touches[0].clientX; y0 = e.touches[0].clientY;
        }, { passive: true });

        main.addEventListener('touchmove', function (e) {
            if (!siguiendo) return;
            var dy = e.touches[0].clientY - y0;
            var dx = e.touches[0].clientX - x0;

            if (!decidido) {
                if (Math.abs(dy) < 8 && Math.abs(dx) < 8) return;   // todavía es un toque, no un gesto
                decidido = true;
                // Hacia arriba, de lado (tablas con scroll horizontal) o ya con scroll: no es un jalón.
                if (dy <= 0 || Math.abs(dx) > Math.abs(dy) * 0.8 || main.scrollTop > 0) { siguiendo = false; return; }
                activo = true;
                main.classList.add('dv-ptr-activo');
                ind.classList.add('dv-ptr-visible');
                estado('reposo');
            }
            if (!activo) return;

            if (main.scrollTop > 0 || dy <= 0) {   // el dedo volvió: se cancela sin recargar
                activo = false; siguiendo = false; soltarAnimado(); return;
            }
            if (e.cancelable) e.preventDefault();  // sin esto el navegador intenta su propio rebote/recarga

            var px = Math.min(MAX_PX, dy * RESISTENCIA);
            poner(px);
            var listo = px >= umbral();
            if (listo !== cruzoUmbral) {
                cruzoUmbral = listo;
                estado(listo ? 'listo' : 'reposo');
                if (listo) vibrar(8);
            }
        }, { passive: false });

        function terminarGesto() {
            if (!siguiendo && !activo) return;
            var estabaActivo = activo;
            siguiendo = false; activo = false;
            if (!estabaActivo) return;

            if (jalon >= umbral()) {
                if (hayCambiosSinGuardar()) {
                    estado('bloqueado');
                    vibrar([10, 40, 10]);
                    aviso('Tienes cambios sin guardar');
                    soltarAnimado();
                } else {
                    refrescar();
                }
            } else {
                soltarAnimado();
            }
        }
        main.addEventListener('touchend', terminarGesto, { passive: true });
        main.addEventListener('touchcancel', function () {
            if (activo) { siguiendo = false; activo = false; soltarAnimado(); }
        }, { passive: true });
    }

    // ---------------------------------------------------------------- Arranque
    function iniciar() {
        marcarPantalla();
        etiquetarTablas();
        iniciarBarra();
        iniciarPTR();

        // Cada navegación sin recarga (dv-nav.js) y cada cambio dentro de la vista
        // (búsquedas que reemplazan la tabla) vuelven a etiquetar.
        doc.addEventListener('dv:nav-swapped', etiquetarPronto);
        var vista = doc.getElementById('dv-view');
        if (vista && window.MutationObserver) {
            new MutationObserver(etiquetarPronto).observe(vista, { childList: true, subtree: true });
        }
        // Al girar el teléfono o abrir un plegable, las tablas se etiquetan en cuanto cabe el diseño de celular.
        if (mqMovil) {
            if (mqMovil.addEventListener) mqMovil.addEventListener('change', etiquetarPronto);
            else if (mqMovil.addListener) mqMovil.addListener(etiquetarPronto);
        }
        window.addEventListener('resize', marcarPantalla);
        window.addEventListener('orientationchange', marcarPantalla);
    }

    window.DvMobile = { categoria: function () { return root.dataset.pantalla || ''; } };

    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', iniciar);
    else iniciar();
})();
