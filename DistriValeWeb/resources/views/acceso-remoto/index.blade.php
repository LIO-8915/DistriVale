@extends('layouts.app')

@section('title', 'Acceso remoto')
@section('subtitle', 'Usa DistriVale desde un iPad u otro dispositivo de tu red local')

@section('content')
<div id="dv-remoto-page"
     data-url-estado="{{ route('acceso-remoto.estado') }}"
     data-url-activar="{{ route('acceso-remoto.activar') }}"
     data-url-desactivar="{{ route('acceso-remoto.desactivar') }}"
     data-url-firewall="{{ route('acceso-remoto.firewall') }}"
     data-url-firewall-verificar="{{ route('acceso-remoto.firewall.verificar') }}"
     data-url-rechazar="{{ url('acceso-remoto/solicitudes/__ID__/rechazar') }}"
     data-url-revocar="{{ url('acceso-remoto/dispositivos/__ID__/revocar') }}">

    {{-- Interruptor --}}
    <div class="card p-4 mb-3">
        <div class="d-flex justify-content-between align-items-center gap-3">
            <div>
                <h6 class="mb-1">Acceso desde otros dispositivos</h6>
                <div class="small" id="remoto-resumen">Cargando…</div>
            </div>
            <div class="form-check form-switch fs-3 mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="remoto-switch" aria-label="Acceso remoto">
            </div>
        </div>

        <div class="alert alert-warning mt-3 mb-0 d-none" id="remoto-no-disponible">
            El acceso remoto necesita el servidor integrado de la app de escritorio (Caddy). En este modo (respaldo o servidor de desarrollo) no está disponible.
        </div>
        <div class="alert alert-danger mt-3 mb-0 d-none" id="remoto-error"></div>
        <div class="alert alert-secondary mt-3 mb-0 d-none" id="remoto-confirm-off">
            <div class="mb-2" id="remoto-confirm-off-texto"></div>
            <button type="button" class="btn btn-sm btn-danger" id="remoto-confirm-off-si">Apagar y desconectar</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="remoto-confirm-off-no">Cancelar</button>
        </div>

        <div class="d-none mt-4" id="remoto-activo">
            <div class="row g-4 align-items-center">
                <div class="col-md-8">
                    <div class="small text-muted">Dirección para el iPad (misma red WiFi)</div>
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-3">
                        <code class="fs-5" id="remoto-url"></code>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="remoto-copiar"><i class="bi bi-clipboard"></i> Copiar</button>
                    </div>
                    <ol class="small mb-0 ps-3">
                        <li>Conecta el iPad a la misma red WiFi que esta computadora.</li>
                        <li>Abre Safari y escribe la dirección de arriba, o escanea el código QR con la cámara.</li>
                        <li>Toca <strong>Pedir código</strong>: aparecerá aquí, en una ventana, con un aviso sonoro. Escríbelo en el iPad.</li>
                    </ol>
                </div>
                <div class="col-md-4 text-center">
                    <div id="remoto-qr" style="width: 190px; max-width: 100%; margin: 0 auto; border-radius: 12px; overflow: hidden;"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Firewall --}}
    <div class="card p-4 mb-3 d-none" id="remoto-firewall-card">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div>
                <h6 class="mb-1">Firewall de Windows</h6>
                <div class="small" id="remoto-fw-estado"></div>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-primary" id="remoto-fw-crear"><i class="bi bi-shield-check"></i> Crear regla de firewall</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="remoto-fw-verificar">Verificar</button>
            </div>
        </div>
        <div class="small text-muted mt-2">
            Para que el iPad llegue a esta computadora, Windows necesita una regla. El botón abre la ventana de permisos de administrador de Windows y la crea solo para redes <strong>privadas</strong> y tu <strong>subred local</strong> — no se abre a internet.
        </div>
        <div class="alert alert-warning mt-3 mb-0 d-none" id="remoto-fw-perfil"></div>
        <div class="alert alert-info mt-3 mb-0 d-none" id="remoto-fw-accion"></div>
    </div>

    {{-- Solicitudes y dispositivos --}}
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card p-4 h-100">
                <h6 class="mb-3">Solicitudes pendientes</h6>
                <div id="remoto-pendientes"></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card p-4 h-100">
                <h6 class="mb-3">Dispositivos autorizados</h6>
                <div id="remoto-dispositivos"></div>
            </div>
        </div>
    </div>

    <div class="card p-4">
        <h6 class="mb-2">Cosas a tener en cuenta</h6>
        <ul class="small mb-0 ps-3">
            <li>Los dispositivos solo siguen autorizados <strong>mientras DistriVale esté abierto</strong> con el acceso remoto encendido: al cerrar la app, o apagar el interruptor, hay que volver a emparejarlos con un código.</li>
            <li>Mientras esté encendido, DistriVale pide a Windows que <strong>no suspenda</strong> la computadora por inactividad (la pantalla sí puede apagarse). Si la suspendes a mano o cierras la tapa de una laptop, el iPad pierde la conexión.</li>
            <li>La conexión usa HTTP sin cifrar: úsala en una red de confianza (tu oficina), no en una WiFi pública.</li>
            <li>Desde un dispositivo remoto solo se puede <strong>respaldar</strong> a Google Drive; conectar la cuenta o restaurar se hace desde esta computadora.</li>
        </ul>
    </div>

    <script type="application/json" id="dv-remoto-datos">@json($datos)</script>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/vendor/qrcode.js') }}"></script>
<script>
(function () {
    var root = document.getElementById('dv-remoto-page');
    if (!root) return;

    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    var $ = function (id) { return document.getElementById(id); };
    var estado = JSON.parse($('dv-remoto-datos').textContent);
    var qrUrl = null;

    function post(url) {
        return fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .catch(function () { return { ok: false, mensaje: 'No se pudo comunicar con la app.' }; });
    }
    function mostrar(el, texto) {
        el.textContent = texto || '';
        el.classList.toggle('d-none', !texto);
    }
    // Todo lo que llega de un dispositivo remoto (nombre, IP) se pinta con
    // textContent: nunca se interpreta como HTML.
    function el(tag, clase, texto) {
        var e = document.createElement(tag);
        if (clase) e.className = clase;
        if (texto != null) e.textContent = texto;
        return e;
    }

    function render(d) {
        estado = d;
        var sw = $('remoto-switch');
        sw.disabled = !d.disponible;
        if ($('remoto-confirm-off').classList.contains('d-none')) sw.checked = !!(d.deseado || d.activo);
        $('remoto-no-disponible').classList.toggle('d-none', d.disponible);
        mostrar($('remoto-error'), d.disponible ? d.error : '');

        $('remoto-resumen').textContent = !d.disponible ? 'No disponible en este modo.'
            : d.activo ? 'Encendido: los dispositivos de tu red pueden entrar con un código.'
            : d.deseado ? 'Encendiendo…'
            : 'Apagado: solo esta computadora puede usar DistriVale.';

        $('remoto-activo').classList.toggle('d-none', !d.activo);
        if (d.activo && d.url) {
            $('remoto-url').textContent = d.url;
            if (qrUrl !== d.url && window.qrcode) {
                qrUrl = d.url;
                var qr = window.qrcode(0, 'M');
                qr.addData(d.url);
                qr.make();
                $('remoto-qr').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 3, scalable: true });
            }
        }

        // Firewall
        $('remoto-firewall-card').classList.toggle('d-none', !d.disponible);
        $('remoto-fw-estado').textContent = d.firewall === 'ok' ? 'Regla creada y activa ✓'
            : d.firewall === 'falta' ? 'Falta crear la regla: sin ella el iPad no podrá conectarse.'
            : 'Sin verificar todavía.';
        mostrar($('remoto-fw-perfil'), d.perfil_red === 'Public'
            ? 'Tu red está marcada como Pública en Windows y la regla solo aplica a redes Privadas. Cámbiala en Configuración > Red e Internet > (tu conexión) > Propiedades > Tipo de perfil de red: Privada.'
            : '');
        var a = d.accion, ocupado = !!(a && a.estado === 'ejecutando');
        $('remoto-fw-crear').disabled = ocupado || d.firewall === 'ok';
        $('remoto-fw-verificar').disabled = ocupado;
        mostrar($('remoto-fw-accion'), ocupado ? 'Esperando el permiso de administrador de Windows… (revisa si apareció una ventana de Windows)' : (a && a.estado === 'error' ? a.mensaje : ''));

        // Solicitudes pendientes
        var pend = $('remoto-pendientes');
        pend.textContent = '';
        if (!d.pendientes.length) pend.appendChild(el('div', 'small text-muted', 'Sin solicitudes ahora.'));
        d.pendientes.forEach(function (p) {
            var fila = el('div', 'd-flex justify-content-between align-items-center gap-2 py-2 border-bottom');
            var izq = el('div');
            izq.appendChild(el('div', 'fw-semibold', p.nombre));
            izq.appendChild(el('div', 'small text-muted', p.ip + ' · código ' + String(p.codigo).replace(/(\d{3})(\d{3})/, '$1 $2')));
            var btn = el('button', 'btn btn-sm btn-outline-danger', 'Rechazar');
            btn.type = 'button';
            btn.addEventListener('click', function () {
                post(root.dataset.urlRechazar.replace('__ID__', p.id)).then(tick);
            });
            fila.appendChild(izq);
            fila.appendChild(btn);
            pend.appendChild(fila);
        });

        // Dispositivos autorizados
        var disp = $('remoto-dispositivos');
        disp.textContent = '';
        if (!d.dispositivos.length) disp.appendChild(el('div', 'small text-muted', 'Ningún dispositivo conectado.'));
        d.dispositivos.forEach(function (p) {
            var fila = el('div', 'd-flex justify-content-between align-items-center gap-2 py-2 border-bottom');
            var izq = el('div');
            izq.appendChild(el('div', 'fw-semibold', p.nombre));
            izq.appendChild(el('div', 'small text-muted', p.ip + ' · desde ' + (p.autorizado || '—') + (p.ultimo_uso ? ' · activo ' + p.ultimo_uso : '')));
            var btn = el('button', 'btn btn-sm btn-outline-danger', 'Revocar');
            btn.type = 'button';
            btn.addEventListener('click', function () {
                post(root.dataset.urlRevocar.replace('__ID__', p.id)).then(tick);
            });
            fila.appendChild(izq);
            fila.appendChild(btn);
            disp.appendChild(fila);
        });
    }

    function tick() {
        if (!document.body.contains(root)) { clearInterval(timer); return Promise.resolve(); }
        return fetch(root.dataset.urlEstado, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(render)
            .catch(function () {});
    }

    // Interruptor
    $('remoto-switch').addEventListener('change', function () {
        var sw = this;
        if (sw.checked) {
            mostrar($('remoto-error'), '');
            post(root.dataset.urlActivar).then(function (r) {
                if (!r.ok) { sw.checked = false; mostrar($('remoto-error'), r.mensaje || 'No se pudo encender el acceso remoto.'); return; }
                if (window.DvRemoto) window.DvRemoto.iniciar();
                tick();
            });
            return;
        }
        var n = estado.dispositivos.length;
        if (n > 0) {
            $('remoto-confirm-off-texto').textContent = 'Se desconectarán ' + n + (n === 1 ? ' dispositivo' : ' dispositivos') + ' y tendrán que pedir un código nuevo para volver a entrar.';
            $('remoto-confirm-off').classList.remove('d-none');
        } else {
            post(root.dataset.urlDesactivar).then(tick);
        }
    });
    $('remoto-confirm-off-si').addEventListener('click', function () {
        $('remoto-confirm-off').classList.add('d-none');
        post(root.dataset.urlDesactivar).then(tick);
    });
    $('remoto-confirm-off-no').addEventListener('click', function () {
        $('remoto-confirm-off').classList.add('d-none');
        $('remoto-switch').checked = true;
    });

    $('remoto-copiar').addEventListener('click', function () {
        if (estado.url && navigator.clipboard) navigator.clipboard.writeText(estado.url);
    });
    $('remoto-fw-crear').addEventListener('click', function () { post(root.dataset.urlFirewall).then(tick); });
    $('remoto-fw-verificar').addEventListener('click', function () { post(root.dataset.urlFirewallVerificar).then(tick); });

    render(estado);
    var timer = setInterval(tick, 2000);
})();
</script>
@endpush
