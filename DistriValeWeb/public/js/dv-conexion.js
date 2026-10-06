/**
 * Conexión con la computadora — solo en dispositivos REMOTOS (iPad, celular por la red local).
 * Si la PC cierra DistriVale, se duerme o el Wi‑Fi se cae, el navegador del dispositivo solo
 * mostraría su página de error (y un formulario a medio llenar se perdería al enviarlo).
 * Tres etapas, según cuánto dure la caída:
 *
 *  1. Un latido (public/dv-ping.txt, un archivo estático que sirve el propio Caddy) cada 3 s
 *     detecta que la PC dejó de responder → aviso naranja con "Reintentar". Mientras tanto:
 *     tocar otra pestaña no manda a la página de error del navegador y un formulario no se
 *     envía (se conserva lo escrito).
 *  2. Si la caída llega a ~10 s (contados desde el último latido bueno: entre 7 y 10 s reales) la pantalla de la app ya no sirve: se tapa con una pantalla de
 *     "conexión perdida" (no queda congelada ni con esqueletos a medio cargar) que sigue
 *     intentando sola.
 *  3. Al volver la computadora se manda a /remoto/acceso: si la autorización sigue vigente
 *     entra directo a la app; si la app de la PC se reinició o se apagó el acceso remoto, esa
 *     pantalla es la de "pedir código".
 *
 * Si la caída fue corta (< 7 s) basta el aviso verde "Conexión restablecida" y un refresco
 * silencioso. En la PC (WebView de escritorio) no hace nada: <body data-remoto="0">.
 */
(function () {
    'use strict';

    var doc = document;
    var PING = '/dv-ping.txt';
    var ACCESO = '/remoto/acceso';
    var CADA_MS = 3000;           // revisión normal
    var CONFIRMA_MS = 1500;       // nueva revisión tras un latido perdido
    var CAIDO_CADA_MS = 2000;     // reintento mientras no hay conexión
    var TIMEOUT_MS = 3000;        // un latido que tarda más cuenta como fallido
    var FALLOS_PARA_CAER = 2;     // un solo latido perdido (Wi‑Fi parpadeando) no basta
    var DESCONECTAR_MS = 10000;   // sin respuesta desde el último latido bueno: se tapa la app (entre 7 y 10 s de la caída real)

    var activo = false;
    var estado = 'ok';            // 'ok' | 'caido' | 'desconectado'
    var fallos = 0;
    var ultimoOk = Date.now();    // último momento en que la computadora respondió: de ahí se cuenta la caída
    var timer = null;
    var limiteTimer = null;
    var yendo = false;
    var banner = null, textoBanner = null, subBanner = null, ocultarTimer = null;
    var pantalla = null, tituloPantalla = null, subPantalla = null, giroPantalla = null, botonPantalla = null;

    function ping() {
        return new Promise(function (resolve) {
            var ctl = window.AbortController ? new AbortController() : null;
            var venció = setTimeout(function () { if (ctl) ctl.abort(); resolve(false); }, TIMEOUT_MS);
            fetch(PING + '?_=' + Date.now(), { cache: 'no-store', credentials: 'omit', signal: ctl ? ctl.signal : undefined })
                .then(function (r) { clearTimeout(venció); resolve(r.ok); },
                      function () { clearTimeout(venció); resolve(false); });
        });
    }

    // ------------------------------------------------------------------ aviso (etapa 1)
    function crearBanner() {
        if (banner) return;
        banner = doc.createElement('div');
        banner.className = 'dv-conexion';
        banner.setAttribute('role', 'status');
        banner.setAttribute('aria-live', 'polite');

        var icono = doc.createElement('i');
        icono.className = 'bi bi-wifi-off dv-conexion-icono';
        icono.setAttribute('aria-hidden', 'true');

        var cuerpo = doc.createElement('div');
        cuerpo.className = 'dv-conexion-txt';
        textoBanner = doc.createElement('strong');
        subBanner = doc.createElement('span');
        cuerpo.appendChild(textoBanner);
        cuerpo.appendChild(subBanner);

        var boton = doc.createElement('button');
        boton.type = 'button';
        boton.className = 'dv-conexion-btn';
        boton.textContent = 'Reintentar';
        boton.addEventListener('click', function () {
            boton.disabled = true;
            revisar().then(function () { boton.disabled = false; });
        });

        banner.appendChild(icono);
        banner.appendChild(cuerpo);
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
        void banner.offsetWidth;   // forzar estilos para que se vea la transición de entrada
        banner.classList.add('show');
    }

    function ocultar() { if (banner) banner.classList.remove('show'); }

    // ------------------------------------------------------------------ pantalla de conexión perdida (etapa 2)
    function crearPantalla() {
        if (pantalla) return;
        pantalla = doc.createElement('div');
        pantalla.className = 'dv-sin-conexion';
        pantalla.setAttribute('role', 'alertdialog');
        pantalla.setAttribute('aria-live', 'assertive');
        pantalla.setAttribute('aria-label', 'Conexión perdida');

        var tarjeta = doc.createElement('div');
        tarjeta.className = 'dv-sin-conexion-card';

        var icono = doc.createElement('i');
        icono.className = 'bi bi-wifi-off dv-sin-conexion-icono';
        icono.setAttribute('aria-hidden', 'true');

        tituloPantalla = doc.createElement('h1');
        subPantalla = doc.createElement('p');

        giroPantalla = doc.createElement('div');
        giroPantalla.className = 'spinner-border dv-sin-conexion-giro';
        giroPantalla.setAttribute('role', 'status');

        botonPantalla = doc.createElement('button');
        botonPantalla.type = 'button';
        botonPantalla.className = 'btn btn-dv-sc';
        botonPantalla.textContent = 'Reintentar ahora';
        botonPantalla.addEventListener('click', function () {
            botonPantalla.disabled = true;
            revisar().then(function () { botonPantalla.disabled = false; });
        });

        tarjeta.appendChild(icono);
        tarjeta.appendChild(tituloPantalla);
        tarjeta.appendChild(subPantalla);
        tarjeta.appendChild(giroPantalla);
        tarjeta.appendChild(botonPantalla);
        pantalla.appendChild(tarjeta);
        doc.body.appendChild(pantalla);
    }

    function desconectar() {
        if (estado === 'desconectado' || !activo) return;
        estado = 'desconectado';
        ocultar();
        crearPantalla();
        tituloPantalla.textContent = 'Se perdió la conexión con la computadora';
        subPantalla.textContent = 'La comunicación se cortó por varios segundos. Cuando la computadora vuelva a responder te llevaremos a la pantalla de acceso: si la app sigue abierta entrarás directo; si se reinició, pedirá un código nuevo.';
        giroPantalla.hidden = false;
        botonPantalla.hidden = false;
        pantalla.classList.add('show');
        doc.documentElement.classList.add('dv-sin-conexion-activa');
        // Lo que quedó cargándose (esqueletos, indicadores) ya no debe seguir "vivo".
        doc.documentElement.classList.remove('dv-nav-loading', 'dv-nav-slow', 'dv-nav-skeleton');
        programar();
    }

    function reconectado() {
        if (yendo) return;
        yendo = true;
        tituloPantalla.textContent = 'Conexión recuperada';
        subPantalla.textContent = 'Un momento…';
        botonPantalla.hidden = true;
        setTimeout(function () { window.location.replace(ACCESO); }, 600);
    }

    // ------------------------------------------------------------------ estado
    function hayCambiosSinGuardar() { return !!doc.querySelector('#dv-view form[data-dv-sucio]'); }

    function programar() {
        clearTimeout(timer);
        if (!activo) return;
        // Tras un latido perdido se confirma enseguida en vez de esperar un ciclo completo.
        var cada = estado === 'ok' ? (fallos > 0 ? CONFIRMA_MS : CADA_MS) : CAIDO_CADA_MS;
        timer = setTimeout(revisar, cada);
    }

    function armarLimite() {
        if (limiteTimer || estado === 'desconectado') return;
        var resta = Math.max(0, DESCONECTAR_MS - (Date.now() - ultimoOk));
        limiteTimer = setTimeout(desconectar, resta);
    }

    function desarmarLimite() {
        clearTimeout(limiteTimer);
        limiteTimer = null;
    }

    function caer() {
        if (estado === 'desconectado') return;
        armarLimite();
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
        desarmarLimite();
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
                ultimoOk = Date.now();
                if (estado === 'desconectado') { reconectado(); return; }
                if (estado === 'caido') recuperar();
                else desarmarLimite();
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
            if (estado === 'ok') return;
            var form = e.target;
            var quien = e.submitter;
            e.preventDefault();
            e.stopImmediatePropagation();
            if (estado === 'desconectado') return;
            ping().then(function (ok) {
                if (ok) {
                    fallos = 0;
                    ultimoOk = Date.now();
                    estado = 'ok';
                    desarmarLimite();
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
