<!DOCTYPE html>
<html lang="es-MX">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>DistriVale — Conectar dispositivo</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fonts/inter/inter.css') }}">
    <style>
        :root { --dv-accent: #4f7cff; --dv-spring: cubic-bezier(.34, 1.56, .64, 1); }
        * { font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif; }
        html, body { min-height: 100%; }
        body {
            margin: 0; color: #eef1f8; display: flex; align-items: center; justify-content: center;
            padding: max(1.25rem, env(safe-area-inset-top)) 1.25rem max(1.25rem, env(safe-area-inset-bottom));
            background:
                radial-gradient(900px 560px at 14% -8%, rgba(79, 124, 255, .35), transparent 60%),
                radial-gradient(760px 520px at 100% 8%, rgba(139, 107, 255, .28), transparent 55%),
                radial-gradient(900px 620px at 20% 100%, rgba(43, 196, 138, .16), transparent 55%),
                linear-gradient(180deg, #131c2b 0%, #0a111c 100%) fixed;
        }
        .dv-card {
            width: 100%; max-width: 460px; padding: 2rem 1.75rem; border-radius: 22px;
            border: 1px solid rgba(255, 255, 255, .28);
            background: linear-gradient(135deg, rgba(255, 255, 255, .14), rgba(255, 255, 255, .05) 55%, rgba(255, 255, 255, .09));
            -webkit-backdrop-filter: blur(30px) saturate(220%); backdrop-filter: blur(30px) saturate(220%);
            box-shadow: 0 14px 34px rgba(0, 0, 0, .35), 0 1px 0 rgba(255, 255, 255, .12) inset;
        }
        .dv-logo { width: 56px; height: 56px; border-radius: 16px; display: block; margin: 0 auto 1rem; }
        h1 { font-size: 1.5rem; font-weight: 800; letter-spacing: -.02em; text-align: center; margin: 0 0 .5rem; }
        .dv-sub { text-align: center; color: rgba(238, 241, 248, .72); line-height: 1.55; margin: 0 0 1.5rem; }
        label { font-size: .85rem; font-weight: 600; color: rgba(238, 241, 248, .8); margin-bottom: .35rem; }
        .form-control {
            background: rgba(255, 255, 255, .1); border: 1px solid rgba(255, 255, 255, .25); color: #fff;
            border-radius: 14px; padding: .8rem 1rem; font-size: 1.05rem; min-height: 52px;
        }
        .form-control:focus { background: rgba(255, 255, 255, .14); color: #fff; border-color: var(--dv-accent); box-shadow: 0 0 0 .25rem rgba(79, 124, 255, .25); }
        .form-control::placeholder { color: rgba(238, 241, 248, .4); }
        .dv-codigo {
            text-align: center; font-size: 2.2rem; font-weight: 800; letter-spacing: .5em; padding-left: .5em;
            font-variant-numeric: tabular-nums; min-height: 70px;
        }
        .btn { border-radius: 14px; min-height: 52px; font-weight: 700; font-size: 1.02rem; border: 0; }
        .btn-dv { background: linear-gradient(135deg, var(--dv-accent), #6f9bff); color: #fff; box-shadow: 0 10px 24px rgba(79, 124, 255, .35); }
        .btn-dv:active { transform: scale(.97, .94); }
        .btn-dv:hover { color: #fff; }
        .btn-link-dv { background: none; color: #9db8ff; font-weight: 600; text-decoration: underline; min-height: 44px; }
        .alert-dv {
            border-radius: 14px; border: 1px solid rgba(255, 159, 67, .5); background: rgba(255, 159, 67, .15);
            color: #ffd9b0; padding: .75rem 1rem; margin-bottom: 1.1rem; font-size: .95rem;
        }
        .dv-meta { text-align: center; color: rgba(238, 241, 248, .65); font-size: .88rem; margin-top: 1rem; }
        .dv-pasos { margin: 0 0 1.4rem; padding-left: 1.15rem; color: rgba(238, 241, 248, .75); font-size: .93rem; line-height: 1.6; }
    </style>
</head>
@php
    $vigente = $solicitud && $solicitud->estado === 'pendiente' && $solicitud->codigo
        && ! $solicitud->codigoVencido() && $solicitud->intentos < $maxIntentos;
    $clave = ! $solicitud ? 'sin_solicitud' : ($solicitud->estado === 'rechazado' ? 'rechazado' : ($vigente ? 'esperando' : 'codigo_invalido'));
    $segundos = $vigente ? max(0, $solicitud->codigo_expira_en->timestamp - now()->timestamp) : 0;
    $restantes = $solicitud ? max(0, $maxIntentos - $solicitud->intentos) : $maxIntentos;
@endphp
<body>
<main class="dv-card" id="dv-remoto-acceso" data-estado="{{ $clave }}" data-segundos="{{ $segundos }}" data-url-estado="{{ route('remoto.estado') }}">
    <img class="dv-logo" src="{{ asset('images/logo-distrivale.png') }}" alt="DistriVale">
    <h1>Conecta este dispositivo</h1>

    @if ($errors->any())
        <div class="alert-dv" role="alert">{{ $errors->first() }}</div>
    @endif

    @if ($clave === 'sin_solicitud' || $clave === 'rechazado')
        @if ($clave === 'rechazado')
            <div class="alert-dv" role="alert">La solicitud anterior fue rechazada en la computadora. Si fuiste tú, vuelve a pedir el código.</div>
        @endif
        <p class="dv-sub">Para usar DistriVale desde aquí, pide un código: aparecerá en la pantalla de la computadora donde está abierta la app.</p>
        <ol class="dv-pasos">
            <li>Toca <strong>Pedir código</strong>.</li>
            <li>Mira la pantalla de la computadora: ahí se muestra tu código de 6 dígitos.</li>
            <li>Escríbelo aquí para entrar.</li>
        </ol>
        <form method="POST" action="{{ route('remoto.solicitar') }}">
            @csrf
            <div class="mb-3">
                <label for="nombre">Nombre de este dispositivo</label>
                <input id="nombre" name="nombre" type="text" class="form-control" maxlength="40" value="{{ old('nombre', $nombreSugerido) }}" autocomplete="off">
            </div>
            <button type="submit" class="btn btn-dv w-100">Pedir código</button>
        </form>
    @elseif ($clave === 'esperando')
        <p class="dv-sub">Escribe el código de 6 dígitos que aparece en la pantalla de la computadora.</p>
        <form method="POST" action="{{ route('remoto.verificar') }}">
            @csrf
            <input name="codigo" type="text" class="form-control dv-codigo mb-3" inputmode="numeric" pattern="[0-9]*"
                   maxlength="6" autocomplete="one-time-code" placeholder="••••••" autofocus required>
            <button type="submit" class="btn btn-dv w-100">Verificar</button>
        </form>
        <div class="dv-meta">
            Te quedan <strong>{{ $restantes }}</strong> {{ $restantes === 1 ? 'intento' : 'intentos' }} con este código ·
            vence en <strong id="dv-cuenta">--:--</strong>
        </div>
        <form method="POST" action="{{ route('remoto.regenerar') }}" class="text-center mt-2">
            @csrf
            <button type="submit" class="btn btn-link-dv">Generar un código nuevo</button>
        </form>
    @else
        <p class="dv-sub">Ese código ya no sirve (se agotaron los intentos o venció). Genera uno nuevo: se mostrará de nuevo en la computadora.</p>
        <form method="POST" action="{{ route('remoto.regenerar') }}">
            @csrf
            <button type="submit" class="btn btn-dv w-100">Generar código nuevo</button>
        </form>
    @endif
</main>

<script>
(function () {
    var root = document.getElementById('dv-remoto-acceso');
    var restante = parseInt(root.dataset.segundos, 10) || 0;
    var cuenta = document.getElementById('dv-cuenta');

    function pintar() {
        if (!cuenta) return;
        var m = Math.floor(restante / 60), s = restante % 60;
        cuenta.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }
    pintar();
    setInterval(function () {
        if (restante > 0 && --restante === 0) { window.location.reload(); return; }
        pintar();
    }, 1000);

    // La computadora puede rechazar la solicitud o el código vencer: aquí se entera sin recargar a mano.
    setInterval(function () {
        fetch(root.dataset.urlEstado, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.estado === 'autorizado') { window.location.href = '/'; }
                else if (d.estado && d.estado !== root.dataset.estado) { window.location.reload(); }
            })
            .catch(function () {});
    }, 3000);
})();
</script>
</body>
</html>
