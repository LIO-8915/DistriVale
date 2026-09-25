<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
    <title>DistriVale - @yield('title', 'Panel')</title>
    <!-- Every asset below is vendored locally (public/vendor/) instead of loaded from a CDN:
         this app runs inside a Tauri/WebView2 desktop shell that may have no internet
         connection, and a CDN-loaded stylesheet/font/script simply fails to load offline. -->
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fonts/inter/inter.css') }}">
    <style>
        /* Reveal gate: keeps the page hidden behind #dv-preloader until fonts,
           images and scripts have actually finished loading, then swaps to the
           fully-rendered page in one shot — no per-element pop-in, no FOUT
           reflow when Inter swaps in. See the script at the end of <body>. */
        body > *:not(#dv-preloader) { visibility: hidden; }
        html.dv-ready body > *:not(#dv-preloader) { visibility: visible; }
        /* Splash: Disney+/Netflix-style title card — a dark vignette tinted
           to the brand's own blue (not the app's photo background), and a
           mark with no filled plate behind it (transparent, just the glyph)
           that fades/scales in once and settles into a slow ambient glow
           pulse. The mark is inline SVG (not the bi-* icon font) so it
           paints on first frame regardless of whether bootstrap-icons has
           loaded yet — this screen's whole job is to cover the page while
           exactly those assets are still loading. */
        /* A CSS radial-gradient() here (even with fully opaque stops) shows a
           visible vertical seam in WebView2/Chromium — a real rasterization
           artifact of that gradient type, confirmed in the actual packaged
           app, not a test-only fluke. Built the same soft vignette a
           different way instead: a solid background plus a large, heavily
           blurred circle (filter: blur(), not a gradient) as the glow —
           a rendering path that doesn't hit that bug. */
        #dv-preloader {
            position: fixed; inset: 0; z-index: 9999;
            background: #05070c; overflow: hidden;
            display: flex; align-items: center; justify-content: center;
        }
        #dv-preloader::before {
            content: ""; position: absolute; top: 42%; left: 50%; transform: translate(-50%, -50%);
            width: min(70vmax, 900px); height: min(70vmax, 900px);
            background: #2e4d8f; border-radius: 50%; filter: blur(140px); opacity: .65;
        }
        html.dv-ready #dv-preloader { display: none; }
        #dv-preloader .dv-splash { text-align: center; position: relative; z-index: 1; }
        #dv-preloader .dv-splash-icon {
            display: block; margin: 0 auto 1.15rem;
            filter: drop-shadow(0 0 16px rgba(79, 124, 255, .45));
            animation: dv-splash-in .7s cubic-bezier(.34, 1.4, .64, 1) both,
                       dv-splash-pulse 2.6s ease-in-out .7s infinite;
        }
        #dv-preloader .dv-splash-text {
            font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; color: #fff;
            font-weight: 600; font-size: 1rem; letter-spacing: .16em; text-transform: uppercase; opacity: 0;
            animation: dv-splash-text-in .6s ease-out .4s forwards;
        }
        @keyframes dv-splash-in { from { opacity: 0; transform: scale(.78); } to { opacity: 1; transform: scale(1); } }
        @keyframes dv-splash-pulse {
            0%, 100% { filter: drop-shadow(0 0 14px rgba(79, 124, 255, .42)); }
            50% { filter: drop-shadow(0 0 28px rgba(122, 162, 255, .75)); }
        }
        @keyframes dv-splash-text-in { from { opacity: 0; transform: translateY(8px); } to { opacity: .92; transform: translateY(0); } }
        @media (prefers-reduced-motion: reduce) {
            #dv-preloader .dv-splash-icon { animation: dv-splash-in .3s ease both; }
        }

        /* Slim top progress bar shown while dv-nav.js fetches a screen. */
        #dv-progress {
            position: fixed; top: 0; left: 0; height: 3px; width: 0; z-index: 10000;
            background: linear-gradient(90deg, #4f7cff, #7aa2ff); opacity: 0;
            transition: width .25s ease, opacity .2s ease;
        }
        html.dv-nav-loading #dv-progress { width: 70%; opacity: 1; }

        #dv-topbar-actions:empty, .dv-topbar .dv-subtitle:empty { display: none; }
        .dv-topbar-heading { min-width: 0; flex: 1 1 240px; }

        /* Sidebar tooltips (Bootstrap Tooltip, Popper-positioned — see
           dv-ui.js) only earn their keep when the sidebar is the icon-only
           rail (<1200px, see below) — with labels visible there's nothing
           the tooltip adds. Bootstrap mounts .tooltip on <body>, not inside
           the trigger, so this is the reliable way to gate it by viewport. */
        @media (min-width: 1200px) { .tooltip { display: none !important; } }
        .tooltip .tooltip-inner {
            background: var(--dv-navy); font-size: .8rem; padding: .4rem .7rem; border-radius: 8px;
        }
        .tooltip .tooltip-arrow::before { border-right-color: var(--dv-navy) !important; }

        /* Slow in-place navigation (dv-nav.js): only kicks in past ~250ms so
           a normal click never shows it — dims the current screen (kept in
           place, not swapped to a skeleton) and centers a Bootstrap spinner
           over it. */
        .dv-nav-slow-spinner {
            position: fixed; inset: 0; margin-left: var(--dv-sidebar-w);
            display: none; align-items: center; justify-content: center; z-index: 20;
            pointer-events: none;
        }
        html.dv-nav-slow #dv-view { opacity: .45; transition: opacity .15s ease; }
        html.dv-nav-slow .dv-nav-slow-spinner { display: flex; }
    </style>
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
            /* Overshoot easing for the "liquid glass" press feedback below —
               settles past its target and eases back, reading as a soft
               bounce instead of a linear snap, in both directions (press
               and release use the same curve). */
            --dv-spring: cubic-bezier(.34, 1.56, .64, 1);
        }

        * { font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif; }

        body {
            overflow: hidden;
            background: url('{{ asset('images/fondo-app.png') }}') center / cover fixed, #0c4660;
            color: #000;
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
            border-radius: .75rem; margin-bottom: 4px;
            transition: background .15s ease, color .15s ease,
                        transform .32s var(--dv-spring), border-radius .32s var(--dv-spring), filter .15s ease;
        }
        .dv-sidebar a i { font-size: 1.15rem; width: 20px; text-align: center; opacity: .85; }
        .dv-sidebar a:hover { background: rgba(255, 255, 255, .06); color: #fff; }
        /* Liquid Glass press feedback: the pill compresses and rounds off
           further while held, its blur/darken deepen a touch (keeps the icon
           legible against whatever's behind it), then springs back via the
           overshoot easing above on release. */
        .dv-sidebar a:active {
            transform: scale(.95); border-radius: 1.1rem; filter: brightness(.9);
        }
        /* Active nav pill: plain CSS gradient + backdrop-filter instead of a
           liquid-glass-js WebGL Container wrapping the <a> — same look, no
           JS DOM replacement after DOMContentLoaded (which used to make the
           active item visibly "pop in" a beat after the rest of the page). */
        .dv-sidebar a.active {
            color: #fff; background: linear-gradient(135deg, rgba(79, 124, 255, .9), rgba(111, 155, 255, .9));
            -webkit-backdrop-filter: blur(16px) saturate(180%); backdrop-filter: blur(16px) saturate(180%);
            box-shadow: 0 6px 16px rgba(79, 124, 255, .35);
        }
        .dv-sidebar a.active i { opacity: 1; }

        /* Topbar flotante: misma superficie de vidrio que las .card (borde,
           radio, sombra), separada de los bordes de la pantalla. El `top`
           deja un hueco al quedar pegada al hacer scroll, así sigue viéndose
           como una tarjeta flotando sobre el contenido. */
        .dv-topbar {
            position: sticky; top: .85rem; z-index: 5;
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .75rem;
            padding: 1rem 1.4rem; margin: 0 0 1.75rem;
            border: 1px solid rgba(255, 255, 255, .5);
            border-radius: var(--dv-radius);
            background: linear-gradient(135deg, rgba(255, 255, 255, .7) 0%, rgba(255, 255, 255, .34) 55%, rgba(255, 255, 255, .5) 100%);
            -webkit-backdrop-filter: blur(30px) saturate(220%);
            backdrop-filter: blur(30px) saturate(220%);
            box-shadow: 0 14px 34px rgba(30, 41, 59, .12), 0 1px 0 rgba(255, 255, 255, .7) inset;
        }
        /* Fluid type: scales smoothly with the actual window width instead of
           jumping between fixed sizes at a couple of breakpoints — reads
           comfortably whether the Tauri window is snapped narrow or maximized
           on a large/high-DPI monitor. */
        .dv-topbar .dv-title {
            font-weight: 700; font-size: clamp(1.15rem, 1rem + 1vw, 1.6rem); letter-spacing: -.01em; margin: 0; color: #000;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .dv-topbar .dv-subtitle { font-size: .95rem; color: #000; margin: .2rem 0 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .dv-user { display: flex; align-items: center; gap: .75rem; }
        .dv-user .name { font-size: 1rem; font-weight: 600; line-height: 1.2; color: #000; }
        .dv-user .role { font-size: .82rem; color: #000; }

        /* Glass chips: avatar, notification bell and dashboard quick-access
           buttons. Plain CSS backdrop-filter instead of the old WebGL lens
           over an html2canvas snapshot of the page — same frosted-glass look,
           natively GPU-composited by WebView2/Chromium, no CDN dependency
           (html2canvas) and no post-load JS swap-in. Color comes from a
           semi-transparent gradient painted under the blur, same technique
           as .card below. */
        .dv-glass-chip {
            -webkit-backdrop-filter: blur(18px) saturate(200%); backdrop-filter: blur(18px) saturate(200%);
            border: 1px solid rgba(255, 255, 255, .45);
            box-shadow: 0 8px 20px rgba(30, 41, 59, .18), inset 0 1px 1px rgba(255, 255, 255, .5);
            transition: transform .32s var(--dv-spring), box-shadow .2s ease,
                        -webkit-backdrop-filter .2s ease, backdrop-filter .2s ease, filter .15s ease;
        }
        /* Liquid Glass press feedback: a slight non-uniform squash (rounder
           shapes deform rather than uniformly shrinking) plus a deeper
           blur/saturation and a touch of darkening — "el fondo se difumina y
           oscurece para mejorar la legibilidad" — then a spring back on release. */
        .dv-glass-chip:active {
            transform: scale(.92, .88);
            -webkit-backdrop-filter: blur(24px) saturate(230%); backdrop-filter: blur(24px) saturate(230%);
            filter: brightness(.92);
            box-shadow: 0 4px 10px rgba(30, 41, 59, .22), inset 0 1px 1px rgba(255, 255, 255, .5);
        }
        .glass-fill-blue { background: linear-gradient(135deg, rgba(79, 124, 255, .88), rgba(122, 162, 255, .88)); }
        .glass-fill-purple { background: linear-gradient(135deg, rgba(139, 107, 255, .88), rgba(169, 139, 255, .88)); }
        .glass-fill-orange { background: linear-gradient(135deg, rgba(255, 159, 67, .88), rgba(255, 185, 118, .88)); }
        .glass-fill-green { background: linear-gradient(135deg, rgba(43, 196, 138, .88), rgba(87, 217, 165, .88)); }

        .dv-avatar-glass, .dv-bell-glass {
            width: 46px; height: 46px; border-radius: 23px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
        }
        .dv-avatar-glass .dv-avatar-inner { color: #fff; font-weight: 700; font-size: 1rem; }
        .dv-bell-glass { cursor: pointer; padding: 0; background: rgba(255, 255, 255, .55); appearance: none; font: inherit; }
        .dv-bell-glass .dv-bell-inner { color: #33415a; font-size: 1.15rem; position: relative; }
        .dv-bell-glass .dv-bell-inner.has-alerts::after {
            content: ""; position: absolute; top: -3px; right: -4px; width: 8px; height: 8px;
            border-radius: 50%; background: #ff5c72; border: 2px solid #fff;
        }

        /* Dashboard quick-access buttons */
        .glass-quick-btn {
            display: flex; align-items: center; gap: .5rem; width: 100%;
            padding: .7rem 1rem; border-radius: .75rem; text-decoration: none;
            color: #fff; font-weight: 600; font-size: .88rem; cursor: pointer;
            transition: filter .15s ease, transform .32s var(--dv-spring), border-radius .32s var(--dv-spring),
                        -webkit-backdrop-filter .2s ease, backdrop-filter .2s ease;
        }
        .glass-quick-btn:hover { color: #fff; filter: brightness(1.06); }
        .glass-quick-btn:active {
            transform: scale(.96); border-radius: 1.05rem; filter: brightness(.9);
            -webkit-backdrop-filter: blur(24px) saturate(230%); backdrop-filter: blur(24px) saturate(230%);
        }

        .dv-main {
            margin-left: var(--dv-sidebar-w); height: 100vh; overflow-y: auto; overflow-x: hidden;
            padding: 1.5rem clamp(1rem, 1rem + 1.5vw, 1.75rem) 2.5rem;
            transition: margin-left .2s ease;
        }
        .dv-content { padding-top: .5rem; min-width: 0; }

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
        .stat-card .stat-value { font-size: clamp(1.35rem, 1.1rem + .9vw, 1.75rem); font-weight: 800; letter-spacing: -.01em; color: #000; }
        .stat-card .text-muted { font-size: .9rem !important; }
        .stat-card .stat-icon {
            width: 46px; height: 46px; border-radius: 13px; display: flex; align-items: center;
            justify-content: center; font-size: 1.2rem; color: #fff;
        }
        .card h6 { font-size: 1.08rem; font-weight: 700; color: #000; }
        /* Base text color set once on .card and inherited — badges, links and
           .text-muted already carry their own explicit (higher-specificity)
           colors, so inheritance never fights them; this just darkens the
           plain unstyled text (labels, cell values, headings). Kept black
           throughout for contrast, except the .text-success/.text-danger
           saldo amounts, which keep Bootstrap's green/red. */
        .card { color: #000; }
        .card .text-muted { color: #000 !important; }
        .icon-blue { background: linear-gradient(135deg, #4f7cff, #7aa2ff); }
        .icon-orange { background: linear-gradient(135deg, #ff9f43, #ffb976); }
        .icon-purple { background: linear-gradient(135deg, #8b6bff, #a98bff); }
        .icon-green { background: linear-gradient(135deg, #2bc48a, #57d9a5); }

        .table { margin-bottom: 0; }
        .table thead th {
            font-size: .78rem; text-transform: uppercase; letter-spacing: .04em;
            color: #000; border-top: none; border-bottom: 2px solid rgba(20, 30, 50, .14);
            font-weight: 700; padding: 1.05rem 1.15rem;
        }
        .table td {
            vertical-align: middle; border-bottom: 1px solid rgba(20, 30, 50, .1);
            font-size: 1rem; color: #000; padding: 1rem 1.15rem;
        }
        .table tbody tr:hover { background: rgba(79, 124, 255, .07); }

        /* Wide pivot tables (e.g. liquidación's cliente × financiera matrix)
           scroll horizontally on narrow windows via .table-responsive; pin
           the leading "Cliente"/"TOTALES" column so it stays in view while
           scrolling through the rest — otherwise a scrolled row is just
           numbers with no idea whose they are. */
        .dv-matrix-scroll .dv-matrix-pin {
            position: sticky; left: 0; z-index: 1;
            background: rgba(255, 255, 255, .92); box-shadow: 1px 0 0 rgba(20, 30, 50, .1);
        }
        .dv-matrix-scroll thead .dv-matrix-pin { z-index: 2; }

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
           back up app-wide instead of editing every view. A plain shorthand
           `padding: Y X` here was clobbering .form-select's right padding
           (2.25rem, reserved for the dropdown chevron) down to .85rem on
           every -sm select — with less room reserved than the chevron
           needs, long option text ran under/behind the arrow instead of
           stopping short of it. Give selects their own rule that keeps that
           space. */
        .form-control-sm { font-size: 1rem; padding: .55rem .85rem; }
        .form-select-sm { font-size: 1rem; padding: .55rem 2.25rem .55rem .85rem; }
        .btn-sm { font-size: .92rem; padding: .5rem .9rem; }
        .table td.text-center.text-muted { font-size: 1.05rem; padding: 2.5rem 1rem; }

        /* Liquid Glass press feedback, app-wide on every Bootstrap button:
           compress + round off further while held (physical deformation),
           darken a touch (keeps label legible against the press state), then
           spring back past their resting size on release via the overshoot
           easing — reads as a soft bounce rather than a linear snap. */
        .btn {
            transition: transform .32s var(--dv-spring), border-radius .32s var(--dv-spring),
                        filter .15s ease, box-shadow .15s ease, background-color .15s ease;
        }
        .btn:active { transform: scale(.96); filter: brightness(.92); }

        .btn-primary {
            background: linear-gradient(135deg, var(--dv-accent), #6f9bff); border: none;
            box-shadow: 0 6px 14px rgba(79, 124, 255, .3); border-radius: 12px;
        }
        .btn-primary:active { border-radius: 16px; box-shadow: 0 2px 6px rgba(79, 124, 255, .3); }
        .btn-outline-primary { border-radius: 12px; border-color: var(--dv-accent); color: var(--dv-accent); }
        .btn-outline-primary:active, .btn-outline-secondary:active, .btn-outline-danger:active { border-radius: 14px; }
        .btn-outline-secondary, .btn-outline-danger { border-radius: 10px; }
        .form-control, .form-select { border-radius: 10px; border-color: rgba(0, 0, 0, .1); }
        .alert { border-radius: 14px; border: none; }

        /* Liquid Glass press feedback keeps its darken/blur cues either way
           (still real affordance that a press registered) but drops the
           scale/bounce motion for anyone who's asked the OS for less of it. */
        @media (prefers-reduced-motion: reduce) {
            .btn, .btn:active, .dv-sidebar a, .dv-sidebar a:active,
            .dv-glass-chip, .dv-glass-chip:active, .glass-quick-btn, .glass-quick-btn:active {
                transform: none !important; transition-duration: .12s;
            }
        }

        /* ===== Responsive =====
           Adapts continuously to the actual window/viewport size — not a
           single "mobile" breakpoint. Fluid type above (clamp()) covers the
           gradual scaling; these breakpoints cover structural changes the
           layout needs at specific widths. */

        /* Paginación (plantilla bootstrap-5 de Laravel): mismo vidrio que las
           .card. Fuera de una tarjeta queda sobre el fondo oscuro, así que el
           texto "Mostrando X a Y" (un div .small.text-muted) va en su propia píldora para que se lea. */
        .dv-pagination .pagination { margin: 0; gap: .3rem; flex-wrap: wrap; }
        .dv-pagination .page-link {
            border-radius: 10px !important; border: 1px solid rgba(255, 255, 255, .55);
            background: rgba(255, 255, 255, .8); color: #000; min-width: 2.2rem; text-align: center;
            -webkit-backdrop-filter: blur(14px) saturate(200%); backdrop-filter: blur(14px) saturate(200%);
        }
        .dv-pagination .page-link:hover { background: rgba(255, 255, 255, .85); color: #000; }
        .dv-pagination .page-item.active .page-link { background: var(--dv-accent); border-color: var(--dv-accent); color: #fff; }
        .dv-pagination .page-item.disabled .page-link { background: rgba(255, 255, 255, .55); color: rgba(0, 0, 0, .45); }
        .dv-pagination .small.text-muted {
            display: inline-block; margin: 0; padding: .35rem .85rem; border-radius: 999px;
            background: rgba(255, 255, 255, .8); color: #000 !important;
            -webkit-backdrop-filter: blur(14px) saturate(200%); backdrop-filter: blur(14px) saturate(200%);
        }

        /* Icon-rail sidebar: below 1200px there isn't room to spare 232px of
           permanent label text. Icons stay, labels collapse (still reachable
           via each link's native title="" tooltip on hover). */
        @media (max-width: 1199.98px) {
            :root { --dv-sidebar-w: 72px; }
            .dv-sidebar .brand { justify-content: center; padding-left: .5rem; padding-right: .5rem; }
            .dv-sidebar a { justify-content: center; padding: .8rem; }
            .dv-sidebar a i { width: auto; font-size: 1.3rem; }
            .dv-sidebar-footer { justify-content: center; padding-left: .5rem; padding-right: .5rem; }
            .dv-sidebar .dv-label { display: none; }
        }

        /* Narrow window: the admin's own name/role competes with the screen
           title for space and isn't information the screen depends on, so
           drop to just the avatar. Topbar chrome tightens up too. */
        @media (max-width: 860px) {
            .dv-user-text { display: none; }
            .dv-topbar { padding: .85rem 1rem; margin-bottom: 1.25rem; top: .6rem; }
        }

        @media (max-width: 640px) {
            .dv-main { padding-left: .85rem; padding-right: .85rem; }
            .stat-card .stat-icon { width: 38px; height: 38px; font-size: 1rem; }
            .dv-topbar .dv-title { max-width: 60vw; }
        }

        /* Very large / high-DPI monitors: let data-dense tables and forms
           keep using the extra width (more columns/whitespace visible is a
           win), but stop dashboard stat cards from stretching absurdly thin
           relative to their icon+number content — add a 5th/6th column
           worth of breathing room instead of one giant row. */
        @media (min-width: 1800px) {
            .dv-main { padding-left: clamp(1.75rem, 2vw, 3rem); padding-right: clamp(1.75rem, 2vw, 3rem); }
        }
    </style>
</head>
<body>
    <div id="dv-progress"></div>
    <div class="dv-nav-slow-spinner"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Cargando…</span></div></div>
    <div id="dv-preloader">
        <div class="dv-splash">
            <svg class="dv-splash-icon" width="72" height="72" viewBox="0 0 64 64" fill="none" aria-hidden="true">
                <defs>
                    <linearGradient id="dvSplashGrad" x1="4" y1="4" x2="60" y2="60" gradientUnits="userSpaceOnUse">
                        <stop offset="0" stop-color="#7aa2ff"/>
                        <stop offset="1" stop-color="#4f7cff"/>
                    </linearGradient>
                </defs>
                <circle cx="32" cy="32" r="27" stroke="url(#dvSplashGrad)" stroke-width="3"/>
                <path d="M32 17v30M24.5 23.5c0-3.3 3.4-6 7.5-6s7.5 2.4 7.5 5.4-3.4 4.6-7.5 5.6-7.5 2.6-7.5 5.6S28.4 40 32.5 40s7.5-2.7 7.5-6"
                      stroke="url(#dvSplashGrad)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <div class="dv-splash-text">DistriVale</div>
        </div>
    </div>
    <noscript><style>body > *:not(#dv-preloader) { visibility: visible !important; } #dv-preloader { display: none !important; }</style></noscript>

    <div class="dv-titlebar"></div>
    <nav class="dv-sidebar">
        <div class="brand">
            <span class="brand-icon"><i class="bi bi-cash-coin"></i></span> <span class="dv-label">DistriVale</span>
        </div>

        <nav id="dv-sidebar-nav">
            <a href="{{ route('dashboard') }}" title="Inicio" data-bs-toggle="tooltip" data-bs-placement="right" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <i class="bi bi-house-door"></i> <span class="dv-label">Inicio</span>
            </a>
            <a href="{{ route('clientes.index') }}" title="Clientes" data-bs-toggle="tooltip" data-bs-placement="right" class="{{ request()->routeIs('clientes.*') ? 'active' : '' }}">
                <i class="bi bi-people"></i> <span class="dv-label">Clientes</span>
            </a>
            <a href="{{ route('vales.index') }}" title="Créditos / Vales" data-bs-toggle="tooltip" data-bs-placement="right" class="{{ request()->routeIs('vales.*') ? 'active' : '' }}">
                <i class="bi bi-ticket-perforated"></i> <span class="dv-label">Créditos / Vales</span>
            </a>
            <a href="{{ route('financieras.index') }}" title="Financieras" data-bs-toggle="tooltip" data-bs-placement="right" class="{{ request()->routeIs('financieras.*') ? 'active' : '' }}">
                <i class="bi bi-bank"></i> <span class="dv-label">Financieras</span>
            </a>
            <a href="{{ route('recibos.index') }}" title="Cobranza" data-bs-toggle="tooltip" data-bs-placement="right" class="{{ request()->routeIs('recibos.*') ? 'active' : '' }}">
                <i class="bi bi-receipt"></i> <span class="dv-label">Cobranza</span>
            </a>
            <a href="{{ route('liquidaciones.index') }}" title="Liquidación" data-bs-toggle="tooltip" data-bs-placement="right" class="{{ request()->routeIs('liquidaciones.*') ? 'active' : '' }}">
                <i class="bi bi-calculator"></i> <span class="dv-label">Liquidación</span>
            </a>
            <a href="{{ route('drive.index') }}" title="Respaldo" data-bs-toggle="tooltip" data-bs-placement="right" class="{{ request()->routeIs('drive.*') ? 'active' : '' }}">
                <i class="bi bi-cloud-arrow-up"></i> <span class="dv-label">Respaldo</span>
            </a>
        </nav>

        <div class="dv-sidebar-footer"><i class="bi bi-hdd-network"></i> <span class="dv-label">Sistema local</span></div>
    </nav>

    <main class="dv-main">
        <div class="dv-topbar">
            <div class="dv-topbar-heading">
                <p class="dv-title">@yield('title', 'Panel')</p>
                <p class="dv-subtitle">@yield('subtitle')</p>
            </div>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div id="dv-topbar-actions">@yield('actions')</div>
                <button type="button" class="dv-glass-chip dv-bell-glass" data-bs-toggle="modal" data-bs-target="#modalVencimientos">
                    <span class="dv-bell-inner{{ $vencimientos->count() > 0 ? ' has-alerts' : '' }}"><i class="bi bi-bell"></i></span>
                </button>
                <div class="dv-user">
                    <div class="dv-glass-chip dv-avatar-glass glass-fill-blue">
                        <span class="dv-avatar-inner">EV</span>
                    </div>
                    <div class="dv-user-text">
                        <div class="name">Elia Véliz</div>
                        <div class="role">Administradora</div>
                    </div>
                </div>
            </div>
        </div>

        <div id="dv-view">
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

    <script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
    {{-- Loaded once here (persistent shell), not per-page: with in-place
         navigation (dv-nav.js) a per-page <script src> would re-fetch and
         re-execute the whole library every time the user visits a chart
         screen again. Only the per-chart `new Chart(...)` calls live in
         each view's @push('scripts'), re-run on every navigation to it. --}}
    <script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>

    <div id="dv-page-scripts">@stack('scripts')</div>

    <script src="{{ asset('js/dv-nav.js') }}"></script>
    <script src="{{ asset('js/dv-ui.js') }}"></script>

    <script>
        // Reveal gate: the whole page starts hidden behind #dv-preloader (see
        // <head>). Wait for window 'load' (scripts, stylesheets and images —
        // including the background photo) AND document.fonts.ready (Inter)
        // before showing anything, so the app appears fully formed in one
        // shot instead of assets/elements popping in one after another.
        (function () {
            function fontsReady() {
                return (document.fonts && document.fonts.ready) ? document.fonts.ready : Promise.resolve();
            }
            var pageLoaded = new Promise(function (resolve) {
                if (document.readyState === 'complete') resolve();
                else window.addEventListener('load', resolve);
            });
            function reveal() { document.documentElement.classList.add('dv-ready'); }
            Promise.all([pageLoaded, fontsReady()]).then(reveal);
            // Safety net: never leave the app hidden if a resource stalls.
            setTimeout(reveal, 4000);
        })();
    </script>
</body>
</html>
