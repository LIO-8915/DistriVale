/**
 * Movimiento de pantallas de DistriVale: salida/entrada de #dv-view y entrada
 * escalonada de tarjetas y filas. Solo CSS-friendly (opacity + transform) vía
 * Web Animations API — cero librerías.
 *
 * Reglas que evitan los bugs ya vividos en este proyecto:
 *  - Ninguna animación usa fill:'forwards' en los elementos hijos: al terminar
 *    se descartan solos y no queda ningún transform residual (un transform
 *    residual crearía un contexto de apilamiento y rompería dropdowns,
 *    sticky o position:fixed dentro de la tarjeta).
 *  - El único DOM que se reemplaza aquí es #dv-view al mostrar un esqueleto
 *    (skeleton()); el resto solo anima lo que dv-nav.js ya intercambió. Nunca
 *    se toca el sidebar, así que los tooltips/modales no se ven afectados.
 *  - Con prefers-reduced-motion todo se omite (incluida la espera mínima).
 *  - Los contadores restauran al final el texto ORIGINAL exacto del servidor:
 *    la animación nunca puede dejar una cifra distinta a la real.
 */
(function () {
    'use strict';

    var EASE = 'cubic-bezier(.22,1,.36,1)';
    var OUT_MS = 120;
    var IN_MS = 200;
    var ITEM_MS = 260;
    var STAGGER_MS = 30;
    var MAX_ITEMS = 12;
    var MAX_CARDS = 6;
    var DIM_OPACITY = 0.4;

    var mq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

    function enabled() {
        return !!(document.documentElement.animate) && !(mq && mq.matches);
    }

    // Sale el contenido actual mientras corre el fetch. Queda atenuado (no
    // vacío) con fill:'forwards' para no parpadear a opacidad 1 antes del
    // swap; quien la inicia debe llamar a .cancel() al terminar.
    function out(view) {
        if (!enabled() || !view) return null;
        return view.animate(
            [{ opacity: 1 }, { opacity: DIM_OPACITY }],
            { duration: OUT_MS, easing: EASE, fill: 'forwards' }
        );
    }

    function collectItems(view) {
        var cards = Array.prototype.filter.call(
            view.querySelectorAll('.card, .alert'),
            function (el) {
                if (el.hasAttribute('data-no-motion')) return false;
                // Solo tarjetas de primer nivel: una anidada ya se mueve con su padre.
                var parent = el.parentElement && el.parentElement.closest('.card, .alert');
                return !parent;
            }
        ).slice(0, MAX_CARDS);

        var items = cards.slice();

        var table = view.querySelector('table:not([data-no-motion])');
        if (table) {
            var rows = table.querySelectorAll('tbody > tr');
            for (var i = 0; i < rows.length && items.length < MAX_ITEMS; i++) {
                items.push(rows[i]);
            }
        }
        return items.slice(0, MAX_ITEMS);
    }

    // Entra el contenido nuevo: la vista se aclara desde el nivel atenuado y
    // los elementos clave suben con 30 ms de diferencia (máx. 12 en total).
    function enter(view) {
        if (!enabled() || !view) return;

        view.animate(
            [{ opacity: DIM_OPACITY }, { opacity: 1 }],
            { duration: IN_MS, easing: EASE }
        );

        countUp(view);

        collectItems(view).forEach(function (el, i) {
            el.animate(
                [
                    { opacity: 0, transform: 'translateY(8px)' },
                    { opacity: 1, transform: 'translateY(0)' },
                ],
                {
                    duration: ITEM_MS,
                    delay: i * STAGGER_MS,
                    easing: EASE,
                    fill: 'backwards', // oculto durante el delay, sin residuo al terminar
                }
            );
        });
    }

    // ---- Contadores: los totales suben de 0 a su valor en ~600 ms ----------

    var COUNT_MS = 600;
    var NUM_RE = /^([^\d-]*)(-?\d[\d,]*(?:\.\d+)?)(\D*)$/;

    // Primer nodo de texto con una cifra dentro del elemento (así "12 / 3"
    // con un <span> hermano solo anima el primer número).
    function numericNode(el) {
        for (var n = el.firstChild; n; n = n.nextSibling) {
            if (n.nodeType === 3 && /\d/.test(n.nodeValue)) return n;
        }
        return null;
    }

    function group(intStr) {
        return intStr.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function collectCounters(view) {
        var els = view.querySelectorAll('.stat-value, tfoot td.text-end, [data-countup]');
        var list = [];
        Array.prototype.forEach.call(els, function (el) {
            if (el.hasAttribute('data-no-motion')) return;
            var node = numericNode(el);
            if (!node) return;
            var original = node.nodeValue;
            var m = original.trim().match(NUM_RE);
            if (!m) return;
            var raw = m[2];
            var target = parseFloat(raw.replace(/,/g, ''));
            if (!isFinite(target) || target === 0) return;
            var dot = raw.indexOf('.');
            list.push({
                el: el,
                node: node,
                original: original,
                lead: original.match(/^\s*/)[0],
                trail: original.match(/\s*$/)[0],
                prefix: m[1],
                suffix: m[3],
                target: target,
                decimals: dot === -1 ? 0 : raw.length - dot - 1,
                commas: raw.indexOf(',') !== -1,
            });
        });
        return list;
    }

    function countUp(view) {
        if (!enabled() || !view) return;
        var items = collectCounters(view);
        if (!items.length) return;

        items.forEach(function (it) { it.el.classList.add('dv-counting'); });
        var start = performance.now();

        function frame(now) {
            var t = Math.min(1, (now - start) / COUNT_MS);
            var eased = 1 - Math.pow(1 - t, 3);
            items.forEach(function (it) {
                // Si la pantalla cambió a media animación el nodo ya no está: no tocarlo.
                if (!it.node.parentNode) return;
                if (t >= 1) {
                    it.node.nodeValue = it.original;
                    it.el.classList.remove('dv-counting');
                    return;
                }
                var parts = (it.target * eased).toFixed(it.decimals).split('.');
                var intPart = it.commas ? group(parts[0]) : parts[0];
                it.node.nodeValue = it.lead + it.prefix + intPart + (parts[1] ? '.' + parts[1] : '') + it.suffix + it.trail;
            });
            if (t < 1) requestAnimationFrame(frame);
        }
        requestAnimationFrame(frame);
    }

    // ---- Esqueletos de carga (solo cuando la espera pasa de ~250 ms) -----

    function bar(w, h) {
        return '<span class="dv-sk" style="display:block;width:' + w + ';height:' + (h || '.9rem') + '"></span>';
    }

    function skStat() {
        return '<div class="col-6 col-xl-3"><div class="card p-3 h-100" data-no-motion><div class="d-flex align-items-center gap-3">' +
            '<span class="dv-sk" style="width:44px;height:44px;border-radius:12px;flex:none"></span>' +
            '<div class="flex-grow-1 d-flex flex-column gap-2">' + bar('55%', '.7rem') + bar('80%', '1.4rem') + '</div></div></div></div>';
    }

    function skTable(rows) {
        var out = '<div class="card p-3 mb-3" data-no-motion><div class="d-flex flex-column gap-3">' + bar('35%', '1.1rem');
        for (var i = 0; i < rows; i++) {
            out += '<div class="d-flex gap-3">' + bar('28%') + bar('18%') + bar('22%') + bar('14%') + '</div>';
        }
        return out + '</div></div>';
    }

    function skForm() {
        var out = '<div class="card p-4 mx-auto" style="max-width:720px" data-no-motion><div class="d-flex flex-column gap-4">' + bar('30%', '1.2rem');
        for (var i = 0; i < 4; i++) out += '<div class="d-flex flex-column gap-2">' + bar('18%', '.7rem') + bar('100%', '2.4rem') + '</div>';
        return out + '</div></div>';
    }

    // La forma se elige por la ruta de destino, que ya se conoce antes de
    // que responda el servidor.
    function skeletonHtml(pathname) {
        var p = pathname.replace(/\/+$/, '') || '/';
        var body;
        if (p === '/') {
            body = '<div class="row g-3 mb-3">' + skStat() + skStat() + skStat() + skStat() + '</div>' +
                '<div class="row g-3"><div class="col-lg-8">' + skTable(6) + '</div><div class="col-lg-4">' + skTable(4) + '</div></div>';
        } else if (/\/(create|nuevo|nueva|edit)$/.test(p)) {
            body = skForm();
        } else {
            body = skTable(9);
        }
        return '<div class="dv-skeleton" role="status" aria-label="Cargando">' + body + '</div>';
    }

    // Reemplaza el contenido ya atenuado por un esqueleto. Quien lo llama
    // debe haber soltado antes la animación de salida.
    function skeleton(view, pathname) {
        if (!enabled() || !view) return false;
        view.innerHTML = skeletonHtml(pathname);
        view.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 150, easing: EASE });
        return true;
    }

    // ---- Indicador deslizante del ítem activo del sidebar -----------------
    //
    // Un único <div> dentro de #dv-sidebar-nav que se desplaza hasta el link
    // activo. Se crea una sola vez y NUNCA se recrea el sidebar (hacerlo
    // dejaba tooltips huérfanos): solo se mueve este elemento. Si el JS falla,
    // la clase dv-has-indicator no se agrega y la píldora CSS de siempre
    // sigue funcionando.

    function initNavIndicator() {
        var nav = document.getElementById('dv-sidebar-nav');
        if (!nav || nav.querySelector('.dv-nav-indicator')) return;

        var ind = document.createElement('div');
        ind.className = 'dv-nav-indicator';
        ind.setAttribute('aria-hidden', 'true');
        nav.insertBefore(ind, nav.firstChild);

        function place(link, animate) {
            if (!link || !link.offsetHeight) return; // oculto o aún sin layout
            // Medidas fraccionarias (no offsetTop/Height, que redondean) para
            // que el indicador calce con la píldora al píxel.
            var l = link.getBoundingClientRect();
            var n = nav.getBoundingClientRect();
            ind.style.transition = animate && enabled() ? '' : 'none';
            ind.style.width = l.width + 'px';
            ind.style.height = l.height + 'px';
            ind.style.transform = 'translate(' + (l.left - n.left) + 'px,' + (l.top - n.top) + 'px)';
            ind.style.opacity = '1';
            document.documentElement.classList.add('dv-has-indicator');
        }

        function placeActive(animate) {
            place(nav.querySelector('a.active'), animate);
        }

        placeActive(false);

        // Al hacer clic se mueve de inmediato, sin esperar al servidor: es lo que
        // hace que el cambio se sienta instantáneo. Si la navegación no ocurre
        // (error, descarga), el siguiente dv:nav-swapped o resize lo reubica.
        nav.addEventListener('click', function (e) {
            var link = e.target.closest && e.target.closest('a[href]');
            if (link && nav.contains(link) && e.button === 0 && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
                place(link, true);
            }
        });

        document.addEventListener('dv:nav-swapped', function () { placeActive(true); });

        // El sidebar cambia de tamaño al colapsar (<1200px) o al cargar la
        // fuente: reubicar sin animar.
        if (window.ResizeObserver) new ResizeObserver(function () { placeActive(false); }).observe(nav);
        window.addEventListener('resize', function () { placeActive(false); });
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { placeActive(false); });
    }

    // Primera carga completa (no pjax): arranca cuando se levanta el preloader.
    function onFirstLoad() {
        var root = document.documentElement;
        var view = document.getElementById('dv-view');
        if (root.classList.contains('dv-ready')) { countUp(view); return; }
        var obs = new MutationObserver(function () {
            if (root.classList.contains('dv-ready')) { obs.disconnect(); countUp(view); }
        });
        obs.observe(root, { attributes: true, attributeFilter: ['class'] });
    }
    function init() { onFirstLoad(); initNavIndicator(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();

    window.DvMotion = { enabled: enabled, out: out, enter: enter, countUp: countUp, skeleton: skeleton };
})();
