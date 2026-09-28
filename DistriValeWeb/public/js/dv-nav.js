/**
 * DistriVale's in-place screen navigation ("pjax"-style, hand-rolled — no
 * framework/CDN dependency, consistent with the rest of the app).
 *
 * The Blade layout still renders one full, self-contained HTML document per
 * route (so a direct URL load, a refresh, or a bookmark all work exactly as
 * before with zero server changes). What changes is how *link clicks* are
 * handled: instead of a full page navigation, this fetches the target
 * page's HTML, and swaps only the parts that actually differ — the topbar
 * title/subtitle/actions, the sidebar's active link, and the central
 * #dv-view container — leaving the <head> (CSS/fonts/icons), the sidebar
 * chrome, bootstrap.js and chart.js exactly as they are. Nothing gets
 * re-downloaded or re-parsed, so navigating between screens is just the
 * cost of one HTML fetch instead of a full document reload.
 *
 * Deliberately NOT intercepted (real, full navigation): links to another
 * origin, links opening in a new tab, download links (PDFs/exports), and
 * every <form> submit — the app's create/edit screens keep working exactly
 * as a normal Laravel POST + redirect, no extra risk taken there.
 */
(function () {
    'use strict';

    var SEL = {
        view: '#dv-view',
        title: '.dv-topbar .dv-title',
        subtitle: '.dv-topbar .dv-subtitle',
        actions: '#dv-topbar-actions',
        sidebarNav: '#dv-sidebar-nav',
        scripts: '#dv-page-scripts',
    };

    var inFlight = null;
    var slowTimer = null;

    // Fetches under this are effectively instant and get just the slim top
    // progress bar; only a genuinely slow one (a cold cache, a heavier
    // query) also dims #dv-view and shows a spinner — so a normal click
    // never flashes a loading state at all.
    var SLOW_MS = 250;

    function isDownloadPath(pathname) {
        return /\.(pdf|xlsx?|csv|zip|docx?)$/i.test(pathname);
    }

    function findLink(target) {
        return target.closest ? target.closest('a[href]') : null;
    }

    function isPjaxable(link) {
        if (!link) return false;
        if (link.target && link.target !== '_self') return false;
        if (link.hasAttribute('download')) return false;
        if ('noPjax' in link.dataset) return false;

        var url;
        try {
            url = new URL(link.href, window.location.href);
        } catch (e) {
            return false;
        }

        if (url.origin !== window.location.origin) return false;
        if (url.protocol !== 'http:' && url.protocol !== 'https:') return false;
        if (isDownloadPath(url.pathname)) return false;
        if (url.pathname === window.location.pathname && url.search === window.location.search) {
            // Same screen (only the #hash differs, or literally the same link): let the
            // browser handle in-page anchors natively instead of re-fetching.
            if (url.hash) return false;
        }
        return true;
    }

    function setLoading(active) {
        document.documentElement.classList.toggle('dv-nav-loading', active);

        clearTimeout(slowTimer);
        if (active) {
            slowTimer = setTimeout(function () {
                document.documentElement.classList.add('dv-nav-slow');
            }, SLOW_MS);
        } else {
            document.documentElement.classList.remove('dv-nav-slow');
        }
    }

    function swapText(selector, doc) {
        var next = doc.querySelector(selector);
        var current = document.querySelector(selector);
        if (next && current) current.innerHTML = next.innerHTML;
    }

    // The sidebar's links are identical on every screen — only which one is
    // "active" changes — so move that class between the *existing* <a>
    // elements instead of replacing #dv-sidebar-nav's innerHTML. Destroying
    // and recreating the links on every navigation was killing any Bootstrap
    // Tooltip instance attached to whichever one was hovered mid-swap: its
    // popup (a sibling appended to <body>, not a child of the link) never
    // got told to hide, so it was orphaned on screen forever. Reusing the
    // same DOM nodes avoids the problem instead of chasing cleanup for it.
    function syncSidebarActive(doc) {
        var next = doc.querySelector(SEL.sidebarNav);
        var current = document.querySelector(SEL.sidebarNav);
        if (!next || !current) return;

        var nextActive = next.querySelector('a.active');
        var currentLinks = current.querySelectorAll('a');
        currentLinks.forEach(function (a) {
            a.classList.toggle('active', !!nextActive && a.getAttribute('href') === nextActive.getAttribute('href'));
        });
    }

    function runPageScripts(doc) {
        var currentContainer = document.querySelector(SEL.scripts);
        var nextContainer = doc.querySelector(SEL.scripts);
        if (!currentContainer) return;
        currentContainer.innerHTML = '';
        if (!nextContainer) return;

        nextContainer.querySelectorAll('script').forEach(function (old) {
            var fresh = document.createElement('script');
            for (var i = 0; i < old.attributes.length; i++) {
                fresh.setAttribute(old.attributes[i].name, old.attributes[i].value);
            }
            fresh.textContent = old.textContent;
            currentContainer.appendChild(fresh);
        });
    }

    function applySwap(html, url, push) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var nextView = doc.querySelector(SEL.view);
        var currentView = document.querySelector(SEL.view);

        // Unexpected shape (e.g. a login/error page without our layout) — fall
        // back to a real navigation rather than rendering something broken.
        if (!nextView || !currentView) {
            window.location.href = url;
            return;
        }

        document.title = doc.title;
        swapText(SEL.title, doc);
        swapText(SEL.subtitle, doc);
        swapText(SEL.actions, doc);
        syncSidebarActive(doc);
        currentView.innerHTML = nextView.innerHTML;
        runPageScripts(doc);

        var scroller = document.querySelector('.dv-main');
        if (scroller) scroller.scrollTop = 0;

        if (push) history.pushState({ dvNav: true }, '', url);

        // Lets UI widgets that need to (re-)wire themselves against the new
        // DOM — Bootstrap tooltips/popovers, anything added later — do so
        // without dv-nav.js needing to know they exist. See dv-ui.js.
        document.dispatchEvent(new CustomEvent('dv:nav-swapped'));
    }

    function navigate(url, push) {
        // Safety net: lets widgets like Bootstrap tooltips hide/dispose
        // themselves *before* anything moves, covering the case where the
        // element the user is hovering is the very link being clicked.
        document.dispatchEvent(new CustomEvent('dv:nav-start'));
        setLoading(true);
        if (inFlight) inFlight.abort();
        var controller = new AbortController();
        inFlight = controller;

        fetch(url, {
            headers: { 'X-DV-Nav': '1' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(function (res) {
                var type = res.headers.get('Content-Type') || '';
                if (!res.ok || type.indexOf('text/html') === -1) {
                    window.location.href = url;
                    return null;
                }
                return res.text();
            })
            .then(function (html) {
                if (html !== null) applySwap(html, url, push);
            })
            .catch(function (err) {
                if (err && err.name !== 'AbortError') window.location.href = url;
            })
            .finally(function () {
                if (inFlight === controller) {
                    inFlight = null;
                    setLoading(false);
                }
            });
    }

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

        var link = findLink(e.target);
        if (!isPjaxable(link)) return;
        if (link.href === window.location.href) { e.preventDefault(); return; }

        e.preventDefault();
        navigate(link.href, true);
    });

    window.addEventListener('popstate', function () {
        navigate(window.location.href, false);
    });

    history.replaceState({ dvNav: true }, '', window.location.href);

    // Lets a page's own script (e.g. one polling for a change that happened
    // outside the WebView, like finishing Google's login in the system
    // browser) re-fetch and swap the current screen through the same pjax
    // path a link click would use, instead of a jarring full reload.
    window.DvNav = { refresh: function () { navigate(window.location.href, false); } };
})();
