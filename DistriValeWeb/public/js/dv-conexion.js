/**
 * Aviso "Sin conexión con la computadora" — solo en dispositivos REMOTOS (iPad,
 * celular por la red local). Si la PC cierra DistriVale, se duerme o el Wi‑Fi se
 * cae, el navegador del dispositivo solo mostraría su página de error (y un
 * formulario a medio llenar se perdería al enviarlo). Aquí:
 *
 *  - un latido (public/dv-ping.txt, un archivo estático que sirve el propio Caddy)
 *    detecta que la PC dejó de responder → banner con "Reintentar";
 *  - dv-nav.js avisa cuando una navegación/actualización falla por red (caida());
 *  - mientras no hay conexión, enviar un formulario se detiene (y se conserva lo
 *    escrito) en vez de mandar al usuario a la página de error;
 *  - al volver la conexión se avisa y se refresca la pantalla en silencio, salvo
 *    que haya un formulario con cambios sin guardar.
 *
 * En la PC (WebView de escritorio) no hace nada: <body data-remoto="0">.
 */
(function () {
    'use strict';

    var doc = document;
    var PING = '/dv-ping.txt';
    var CADA_MS = 10000;          // revisión normal
    var CAIDO_CADA_MS = 3000;     // reintento mientras no hay conexión
    var CONFIRMA_MS = 2000;       // nueva revisión tras un latido perdido
    var TIMEOUT_MS = 4000;        // un latido que tarda más cuenta como fallido
    var FALLOS_PARA_CAER = 2;     // un solo latido perdido (Wi‑Fi parpadeando) no basta

    var activo = false;
    var estado = 'ok';            // 'ok' | 'caido'
    var fallos = 0;
    var timer = null;
    var banner = null, cuerpoBanner = null, textoBanner = null, subBanner = null;
    var ocultarTimer = null;

    function ping() {
        return new Promise(function (resolve) {
            var ctl = window.AbortController ? new AbortController() : null;
            var venció = setTimeout(function () { if (ctl) ctl.abort(); resolve(false); }, TIMEOUT_MS);
            fetch(PING + '?_=' + Date.now(), { cache: 'no-store', credentials: 'omit', signal: ctl ? ctl.signal : undefined })
                .then(function (r) { clearTimeout(venció); resolve(r.ok); },
                      function () { clearTimeout(venció); resolve(false); });
        });
    }

    function crearBanner() {
        if (banner) return;
        banner = doc.createElement('div');
        banner.className = 'dv-conexion';
        banner.setAttribute('role', 'status');
        banner.setAttribute('aria-live', 'polite');

        var icono = doc.createElement('i');
        icono.className = 'bi bi-wifi-off dv-conexion-icono';
        icono.setAttribute('aria-hidden', 'true');

        cuerpoBanner = doc.createElement('div');
        cuerpoBanner.className = 'dv-conexion-txt';
        textoBanner = doc.createElement('strong');
        subBanner = doc.createElement('span');
        cuerpoBanner.appendChild(textoBanner);
        cuerpoBanner.appendChild(subBanner);

        var boton = doc.createElement('button');
        boton.type = 'button';
        boton.className = 'dv-conexion-btn';
        boton.textContent = 'Reintentar';
        boton.addEventListener('click', function () {
            boton.disabled = true;
            revisar().then(function () { boton.disabled = false; });
        });

        banner.appendChild(icono);
        banner.appendChild(cuerpoBanner);
        banner.appendChild(boton);
        doc.body.appendChild(banner);
    }

    function pintar(tipo) {
        crearBanner();
        clearTimeout(ocultarTimer);
        banner.classList.toggle('dv-conexion-ok', tipo === 'ok');
        banner.querySelector('.dv-conexion-icono').className = 'bi ' + (tipo === 'ok' ? 'bi-wifi' : 'bi-wifi-off') + ' dv-conexion-icono';
        banner.querySelector('.dv-conexion-btn').hidden = tipo === 'ok';
        if (tipo === 'ok') {
            textoBanner.textContent = 'Conexión restablecida';
            subBanner.textContent = '';
        } else {
            textoBanner.textContent = 'Sin conexión con la computadora';
            subBanner.textContent = 'Revisa que DistriVale siga abierto en la PC y que estés en la misma red Wi‑Fi. Reintentando…';
        }
        // forzar el cálculo de estilos para que la transición de entrada se vea
        void banner.offsetWidth;
        banner.classList.add('show');
    }

    function ocultar() { if (banner) banner.classList.remove('show'); }

    function hayCambiosSinGuardar() { return !!doc.querySelector('#dv-view form[data-dv-sucio]'); }

    function programar() {
        clearTimeout(timer);
        if (!activo) return;
        // Tras un latido perdido se confirma enseguida (2 s) en vez de esperar otro
        // ciclo completo: el aviso sale en ~13 s en el peor caso, no en 20.
        timer = setTimeout(revisar, estado === 'caido' ? CAIDO_CADA_MS : (fallos > 0 ? CONFIRMA_MS : CADA_MS));
    }

    function caer() {
        if (estado === 'caido') { pintar('caido'); return; }
        estado = 'caido';
        fallos = FALLOS_PARA_CAER;
        pintar('caido');
        programar();
    }

    function recuperar() {
        if (estado !== 'caido') return;
        estado = 'ok';
        fallos = 0;
        pintar('ok');
        ocultarTimer = setTimeout(ocultar, 2200);
        // Lo que se veía pudo quedar viejo mientras no hubo conexión. No se toca nada
        // si la persona estaba escribiendo.
        if (!hayCambiosSinGuardar() && window.DvNav && window.DvNav.refresh) window.DvNav.refresh();
    }

    function revisar() {
        if (!activo) return Promise.resolve();
        return ping().then(function (ok) {
            if (ok) {
                fallos = 0;
                recuperar();
            } else {
                fallos++;
                if (fallos >= FALLOS_PARA_CAER) caer();
            }
            programar();
        });
    }

    function iniciar() {
        if (activo) return;
        activo = true;

        window.addEventListener('offline', caer);                       // el sistema avisó que no hay red
        window.addEventListener('online', function () { revisar(); });
        doc.addEventListener('visibilitychange', function () {
            if (!doc.hidden) revisar();                                 // al volver a la app se comprueba al instante
            else clearTimeout(timer);
        });

        // Sin conexión no se manda ningún formulario: se conserva lo escrito. Se
        // vuelve a comprobar en ese momento por si la conexión ya regresó.
        doc.addEventListener('submit', function (e) {
            if (estado !== 'caido') return;
            var form = e.target;
            var quien = e.submitter;
            e.preventDefault();
            e.stopImmediatePropagation();
            ping().then(function (ok) {
                if (ok) {
                    fallos = 0;
                    estado = 'ok';
                    ocultar();
                    if (form.requestSubmit) form.requestSubmit(quien || undefined);
                    else form.submit();
                } else {
                    pintar('caido');
                    banner.classList.remove('dv-conexion-sacudir');
                    void banner.offsetWidth;
                    banner.classList.add('dv-conexion-sacudir');
                }
            });
        }, true);

        programar();
    }

    window.DvConexion = {
        iniciar: iniciar,
        revisar: revisar,
        // dv-nav.js: una navegación falló por red. true = ya se avisó (no navegar a la página de error).
        caida: function () {
            if (!activo) return false;
            caer();
            return true;
        },
        estado: function () { return estado; },
    };

    if (doc.body && doc.body.dataset.remoto === '1') iniciar();
    else if (doc.readyState === 'loading') {
        doc.addEventListener('DOMContentLoaded', function () { if (doc.body.dataset.remoto === '1') iniciar(); });
    }
})();
