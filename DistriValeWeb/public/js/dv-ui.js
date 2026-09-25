/**
 * Small, declarative UI widgets for the vanilla Blade stack — the parts
 * React-Bootstrap gets "for free" (OverlayTrigger/Tooltip driven by Popper,
 * Collapse reacting to a boolean) but a server-rendered app has to wire up
 * itself. No React here, but the same idea: mark an element with a data
 * attribute, and a small script keeps it correctly positioned/behaved.
 *
 * Bootstrap's own bundle (already loaded) ships Tooltip/Popover — both
 * Popper.js-positioned — so this just instantiates them declaratively from
 * data-bs-toggle="tooltip"/"popover" and keeps them working across dv-nav.js's
 * in-place navigation.
 */
(function () {
    'use strict';

    function disposeOverlays(root) {
        root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            var existing = bootstrap.Tooltip.getInstance(el);
            if (existing) existing.dispose();
        });
        root.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
            var existing = bootstrap.Popover.getInstance(el);
            if (existing) existing.dispose();
        });

        // Belt-and-suspenders: Bootstrap appends a tooltip/popover's popup as
        // a *sibling* in <body>, not as a child of the trigger element, so a
        // bug anywhere that removes/replaces a trigger without going through
        // dispose() first leaves an orphaned bubble on screen forever (this
        // is exactly what used to happen when dv-nav.js still replaced the
        // whole sidebar's innerHTML on every navigation). Catch stragglers.
        document.querySelectorAll('.tooltip, .popover').forEach(function (el) {
            el.remove();
        });
    }

    function initOverlays(root) {
        root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
        });
        root.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
            if (!bootstrap.Popover.getInstance(el)) new bootstrap.Popover(el);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initOverlays(document);
    });
    // Fired right before dv-nav.js touches the DOM (see navigate() in
    // dv-nav.js) — hide/dispose first, so nothing gets orphaned mid-swap.
    document.addEventListener('dv:nav-start', function () {
        disposeOverlays(document);
    });
    // Fired after the swap — (re-)wire anything the new screen needs.
    document.addEventListener('dv:nav-swapped', function () {
        initOverlays(document);
    });
})();
