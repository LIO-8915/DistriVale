<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
    <title>DistriVale - @yield('title', 'Panel')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('vendor/liquid-glass-js/glass.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/liquid-glass-js/glass-fills.css') }}">
    <style>
        :root {
            --dv-sidebar-w: 232px;
            --dv-navy: #131c2b;
            --dv-navy-soft: #1b2739;
            --dv-accent: #4f7cff;
            --dv-accent-soft: rgba(79, 124, 255, .16);
            --dv-glass-bg: rgba(255, 255, 255, .40);
            --dv-glass-border: rgba(255, 255, 255, .55);
            --dv-radius: 18px;
        }

        * { font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif; }

        body {
            overflow: hidden;
            background: url('{{ asset('images/fondo-app.png') }}') center / cover fixed, #0c4660;
            color: #1c2433;
        }

        .dv-titlebar { -webkit-app-region: drag; height: 6px; }

        /* Sidebar */
        .dv-sidebar {
            position: fixed; top: 0; left: 0; bottom: 0; width: var(--dv-sidebar-w);
            background: linear-gradient(180deg, var(--dv-navy) 0%, #0c1420 100%);
            color: #aab4c6; padding: 0; overflow-y: auto;
            box-shadow: 4px 0 24px rgba(0, 0, 0, .12);
        }
        .dv-sidebar .brand {
            display: flex; align-items: center; gap: .6rem;
            padding: 1.15rem 1.25rem; font-weight: 700; color: #fff;
            border-bottom: 1px solid rgba(255, 255, 255, .06);
        }
        .dv-sidebar .brand .brand-icon {
            width: 34px; height: 34px; border-radius: 10px;
            background: linear-gradient(135deg, var(--dv-accent), #7aa2ff);
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 14px rgba(79, 124, 255, .45);
        }
        .dv-sidebar .section-title {
            padding: 1.1rem 1.25rem .35rem; font-size: .68rem; text-transform: uppercase;
            color: #5b6779; letter-spacing: .08em; font-weight: 600;
        }
        .dv-sidebar { display: flex; flex-direction: column; }
        .dv-sidebar nav { padding: .5rem .75rem; flex: 1; }
        .dv-sidebar-footer {
            padding: .9rem 1.25rem; font-size: .74rem; color: #5b6779;
            border-top: 1px solid rgba(255, 255, 255, .06);
            display: flex; align-items: center; gap: .5rem;
        }
        .dv-sidebar a {
            color: #aab4c6; display: flex; align-items: center; gap: .75rem;
            padding: .8rem .9rem; text-decoration: none; font-size: .96rem; font-weight: 500;
            border-radius: 13px; margin-bottom: 4px; transition: background .15s ease, color .15s ease;
        }
        .dv-sidebar a i { font-size: 1.15rem; width: 20px; text-align: center; opacity: .85; }
        .dv-sidebar a:hover { background: rgba(255, 255, 255, .06); color: #fff; }
        /* The active item's pill background is now a real liquid-glass-js
           Container (see dv-init.js) — .dv-glass-hosted just strips this
           anchor's own background so it doesn't double up. The library's
           default .glass-container adds its own padding/gap, which stacked
           on top of the anchor's own padding and made the pill oversized
           and misaligned vs. the other rows — zero it out here so the pill
           matches every other row exactly, only the fill differs. */
        .dv-sidebar .glass-container {
            padding: 0; gap: 0; margin-bottom: 4px; display: block;
        }
        .dv-sidebar .glass-container a.dv-glass-hosted { margin-bottom: 0; }

        /* Topbar */
        .dv-topbar {
            position: sticky; top: 0; z-index: 5;
            display: flex; align-items: center; justify-content: space-between;
            padding: 1.15rem 1.5rem; margin: -1.5rem -1.75rem 2rem;
            background: linear-gradient(120deg, rgba(255, 255, 255, .5) 0%, rgba(255, 255, 255, .28) 100%);
            -webkit-backdrop-filter: blur(30px) saturate(220%);
            backdrop-filter: blur(30px) saturate(220%);
            box-shadow: 0 1px 0 rgba(255, 255, 255, .8) inset, 0 8px 24px rgba(30, 41, 59, .05);
            border-bottom: 1px solid transparent;
            border-image: linear-gradient(90deg, rgba(255,255,255,0) 0%, rgba(150,195,255,.6) 20%, rgba(255,255,255,.9) 50%, rgba(255,175,215,.55) 80%, rgba(255,255,255,0) 100%) 1;
        }
        .dv-topbar .dv-title { font-weight: 700; font-size: 1.6rem; letter-spacing: -.01em; margin: 0; color: #101828; }
        .dv-topbar .dv-subtitle { font-size: .95rem; color: #45526b; margin: .2rem 0 0; }
        .dv-user { display: flex; align-items: center; gap: .75rem; }
        .dv-user .name { font-size: 1rem; font-weight: 600; line-height: 1.2; color: #16202f; }
        .dv-user .role { font-size: .82rem; color: #58657c; }
        /* Avatar (.dv-avatar-glass) and bell (.dv-bell-glass) are built at
           runtime by dv-init.js as real liquid-glass-js Containers — see
           glass-fills.css for their circular sizing/inner content styles. */

        .dv-main { margin-left: var(--dv-sidebar-w); height: 100vh; overflow-y: auto; padding: 1.5rem 1.75rem 2.5rem; }
        .dv-content { padding-top: .5rem; }

        /* Liquid-glass refraction ring: a conic-gradient "rim light" masked into a thin
           border, simulating how light bends/splits at the edge of curved glass.
           Applied to every glass container automatically (no markup changes needed). */
        .card, .stat-icon { position: relative; isolation: isolate; }
        .card::before, .stat-icon::before {
            content: ""; position: absolute; inset: 0; border-radius: inherit; z-index: 2;
            padding: 1.4px; pointer-events: none;
            background: conic-gradient(from 200deg at 50% 50%,
                rgba(255, 255, 255, .95) 0deg,
                rgba(150, 195, 255, .55) 55deg,
                rgba(255, 255, 255, .12) 120deg,
                rgba(255, 255, 255, .12) 200deg,
                rgba(255, 175, 215, .5) 270deg,
                rgba(255, 255, 255, .95) 360deg);
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor; mask-composite: exclude;
        }
        .card::after, .stat-icon::after {
            content: ""; position: absolute; inset: 0; border-radius: inherit; z-index: 1;
            pointer-events: none;
            box-shadow: inset 0 1px 1px rgba(255, 255, 255, .8), inset 0 -10px 18px rgba(255, 255, 255, .08);
        }

        /* Glass cards: a diagonal sheen gradient (not flat alpha) so the card
           reads as translucent glass even where backdrop-filter isn't honored,
           plus a much lower base opacity so the colored body gradients bleed
           through strongly once blur *is* applied. */
        .card {
            border: 1px solid rgba(255, 255, 255, .5);
            border-radius: var(--dv-radius);
            background: linear-gradient(135deg, rgba(255, 255, 255, .62) 0%, rgba(255, 255, 255, .22) 55%, rgba(255, 255, 255, .38) 100%);
            -webkit-backdrop-filter: blur(30px) saturate(220%);
            backdrop-filter: blur(30px) saturate(220%);
            box-shadow: 0 14px 34px rgba(30, 41, 59, .12), 0 1px 0 rgba(255, 255, 255, .7) inset;
            /* Table headers, form inputs, etc. paint their own opaque
               background right up to the card's edge; without clipping,
               their square corners poke out past the card's rounded ones. */
            overflow: hidden;
        }
        .stat-card .stat-value { font-size: 1.75rem; font-weight: 800; letter-spacing: -.01em; color: #101828; }
        .stat-card .text-muted { font-size: .9rem !important; }
        .stat-card .stat-icon {
            width: 46px; height: 46px; border-radius: 13px; display: flex; align-items: center;
            justify-content: center; font-size: 1.2rem; color: #fff;
        }
        .card h6 { font-size: 1.08rem; font-weight: 700; color: #101828; }
        /* Base text color set once on .card and inherited — badges, links and
           .text-muted already carry their own explicit (higher-specificity)
           colors, so inheritance never fights them; this just darkens the
           plain unstyled text (labels, cell values, headings). */
        .card { color: #16202f; }
        .card .text-muted { color: #4b5568 !important; }
        .icon-blue { background: linear-gradient(135deg, #4f7cff, #7aa2ff); }
        .icon-orange { background: linear-gradient(135deg, #ff9f43, #ffb976); }
        .icon-purple { background: linear-gradient(135deg, #8b6bff, #a98bff); }
        .icon-green { background: linear-gradient(135deg, #2bc48a, #57d9a5); }

        .table { margin-bottom: 0; }
        .table thead th {
            font-size: .78rem; text-transform: uppercase; letter-spacing: .04em;
            color: #43516b; border-top: none; border-bottom: 2px solid rgba(20, 30, 50, .14);
            font-weight: 700; padding: 1.05rem 1.15rem;
        }
        .table td {
            vertical-align: middle; border-bottom: 1px solid rgba(20, 30, 50, .1);
            font-size: 1rem; color: #16202f; padding: 1rem 1.15rem;
        }
        .table tbody tr:hover { background: rgba(79, 124, 255, .07); }

        /* Pill badges with status dot */
        .badge {
            font-weight: 600; font-size: .72rem; padding: .38rem .65rem; border-radius: 999px;
            display: inline-flex; align-items: center; gap: .4rem;
        }
        .badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .badge.bg-success { background: rgba(43, 196, 138, .14) !important; color: #1a9c6c; }
        .badge.bg-secondary { background: rgba(120, 130, 145, .14) !important; color: #6b7688; }
        .badge.bg-danger { background: rgba(255, 92, 114, .14) !important; color: #e14059; }
        .badge-estado-ACTIVO { background: rgba(43, 196, 138, .14); color: #1a9c6c; }
        .badge-estado-EN_MORA { background: rgba(255, 159, 67, .16); color: #c97316; }
        .badge-estado-LIQUIDADO { background: rgba(120, 130, 145, .14); color: #6b7688; }

        /* The app's search/filter bars use Bootstrap's -sm inputs/buttons,
           which read too small next to the larger table text — bump them
           back up app-wide instead of editing every view. */
        .form-control-sm, .form-select-sm { font-size: 1rem; padding: .55rem .85rem; }
        .btn-sm { font-size: .92rem; padding: .5rem .9rem; }
        .table td.text-center.text-muted { font-size: 1.05rem; padding: 2.5rem 1rem; }

        .btn-primary {
            background: linear-gradient(135deg, var(--dv-accent), #6f9bff); border: none;
            box-shadow: 0 6px 14px rgba(79, 124, 255, .3); border-radius: 12px;
        }
        .btn-outline-primary { border-radius: 12px; border-color: var(--dv-accent); color: var(--dv-accent); }
        .btn-outline-secondary, .btn-outline-danger { border-radius: 10px; }
        .form-control, .form-select { border-radius: 10px; border-color: rgba(0, 0, 0, .1); }
        .alert { border-radius: 14px; border: none; }
    </style>
</head>
<body>
    <div class="dv-titlebar"></div>
    <nav class="dv-sidebar">
        <div class="brand">
            <span class="brand-icon"><i class="bi bi-cash-coin"></i></span> DistriVale
        </div>

        <nav>
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <i class="bi bi-house-door"></i> Inicio
            </a>
            <a href="{{ route('clientes.index') }}" class="{{ request()->routeIs('clientes.*') ? 'active' : '' }}">
                <i class="bi bi-people"></i> Clientes
            </a>
            <a href="{{ route('vales.index') }}" class="{{ request()->routeIs('vales.*') ? 'active' : '' }}">
                <i class="bi bi-ticket-perforated"></i> Créditos / Vales
            </a>
            <a href="{{ route('financieras.index') }}" class="{{ request()->routeIs('financieras.*') ? 'active' : '' }}">
                <i class="bi bi-bank"></i> Financieras
            </a>
            <a href="{{ route('recibos.index') }}" class="{{ request()->routeIs('recibos.*') ? 'active' : '' }}">
                <i class="bi bi-receipt"></i> Cobranza
            </a>
            <a href="{{ route('liquidaciones.index') }}" class="{{ request()->routeIs('liquidaciones.*') ? 'active' : '' }}">
                <i class="bi bi-calculator"></i> Liquidación
            </a>
        </nav>

        <div class="dv-sidebar-footer"><i class="bi bi-hdd-network"></i> Sistema local</div>
    </nav>

    <main class="dv-main">
        <div class="dv-topbar">
            <div>
                <p class="dv-title">@yield('title', 'Panel')</p>
                @hasSection('subtitle')<p class="dv-subtitle">@yield('subtitle')</p>@endif
            </div>
            <div class="d-flex align-items-center gap-3">
                @hasSection('actions') <div>@yield('actions')</div> @endif
                <div data-glass-bell data-has-alerts="{{ $vencimientos->count() > 0 ? '1' : '0' }}"></div>
                <div class="dv-user">
                    <div data-glass-avatar data-initials="EV"></div>
                    <div>
                        <div class="name">Elia Véliz</div>
                        <div class="role">Administradora</div>
                    </div>
                </div>
            </div>
        </div>

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

        <div class="dv-content">
            @yield('content')
        </div>
    </main>

    <!-- Vencimientos: auto-calculated from vales in EN_MORA, shared to every page via a view composer -->
    <div class="modal fade" id="modalVencimientos" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content" style="border-radius: 18px; border: none;">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Pagos vencidos</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @forelse ($vencimientos as $v)
                        <div class="d-flex justify-content-between align-items-center py-2 {{ !$loop->last ? 'border-bottom' : '' }}">
                            <div>
                                <div class="fw-semibold">{{ $v->cliente->nombre_completo }}</div>
                                <div class="text-muted small">{{ $v->financiera->nombre }} · Vale {{ $v->folio_vale }}</div>
                            </div>
                            <div class="text-danger fw-bold">${{ number_format($v->cuota_quincenal, 2) }}</div>
                        </div>
                    @empty
                        <p class="text-muted text-center py-4 mb-0">Sin pagos vencidos por el momento.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- liquid-glass-js: vendored (no npm/CDN bundle exists upstream), used only for
         fixed chrome (avatar, bell, active nav pill, dashboard quick-access buttons).
         Needs html2canvas to snapshot the page once, then renders a WebGL lens per element. -->
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script src="{{ asset('vendor/liquid-glass-js/container.js') }}"></script>
    <script src="{{ asset('vendor/liquid-glass-js/button.js') }}"></script>
    <script src="{{ asset('vendor/liquid-glass-js/dv-init.js') }}"></script>

    @stack('scripts')
</body>
</html>
