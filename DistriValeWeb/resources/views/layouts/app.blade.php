<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
    <title>DistriVale - @yield('title', 'Panel')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --dv-sidebar-w: 230px; }
        body { background: #f4f6f9; overflow: hidden; }
        .dv-sidebar {
            position: fixed; top: 0; left: 0; bottom: 0; width: var(--dv-sidebar-w);
            background: #1e2a3a; color: #cfd8e3; padding: 0; overflow-y: auto;
        }
        .dv-sidebar .brand { padding: 1rem 1.25rem; font-weight: 700; color: #fff; border-bottom: 1px solid #2c3c50; }
        .dv-sidebar a { color: #cfd8e3; display: block; padding: .65rem 1.25rem; text-decoration: none; font-size: .92rem; }
        .dv-sidebar a:hover, .dv-sidebar a.active { background: #2c3c50; color: #fff; }
        .dv-sidebar .section-title { padding: .75rem 1.25rem .25rem; font-size: .72rem; text-transform: uppercase; color: #7c8ba1; letter-spacing: .05em; }
        .dv-main { margin-left: var(--dv-sidebar-w); height: 100vh; overflow-y: auto; padding: 1.5rem 1.75rem; }
        .dv-titlebar { -webkit-app-region: drag; height: 6px; }
        .card { border: none; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .stat-card .stat-value { font-size: 1.6rem; font-weight: 700; }
        .table thead th { font-size: .75rem; text-transform: uppercase; color: #6c757d; border-top: none; }
        .badge-estado-ACTIVO { background: #198754; }
        .badge-estado-EN_MORA { background: #dc3545; }
        .badge-estado-LIQUIDADO { background: #6c757d; }
    </style>
</head>
<body>
    <div class="dv-titlebar"></div>
    <nav class="dv-sidebar">
        <div class="brand"><i class="bi bi-cash-coin"></i> DistriVale</div>

        <div class="section-title">General</div>
        <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <i class="bi bi-speedometer2 me-2"></i> Dashboard
        </a>

        <div class="section-title">Operación</div>
        <a href="{{ route('clientes.index') }}" class="{{ request()->routeIs('clientes.*') ? 'active' : '' }}">
            <i class="bi bi-people me-2"></i> Clientes
        </a>
        <a href="{{ route('financieras.index') }}" class="{{ request()->routeIs('financieras.*') ? 'active' : '' }}">
            <i class="bi bi-bank me-2"></i> Financieras
        </a>
        <a href="{{ route('vales.index') }}" class="{{ request()->routeIs('vales.*') ? 'active' : '' }}">
            <i class="bi bi-ticket-perforated me-2"></i> Vales
        </a>

        <div class="section-title">Cobranza</div>
        <a href="{{ route('recibos.index') }}" class="{{ request()->routeIs('recibos.*') ? 'active' : '' }}">
            <i class="bi bi-receipt me-2"></i> Recibos consolidados
        </a>
        <a href="{{ route('liquidaciones.index') }}" class="{{ request()->routeIs('liquidaciones.*') ? 'active' : '' }}">
            <i class="bi bi-calculator me-2"></i> Liquidación quincenal
        </a>
    </nav>

    <main class="dv-main">
        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">@yield('title', 'Panel')</h4>
            @hasSection('actions') <div>@yield('actions')</div> @endif
        </div>

        @yield('content')
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
