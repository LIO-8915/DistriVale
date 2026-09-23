/**
 * Wires liquid-glass-js (Container/Button) into DistriVale's server-rendered
 * layout. Only used for fixed chrome elements (avatar, bell, active nav item,
 * dashboard quick-access buttons) — NOT for content cards/tables, since the
 * library renders a WebGL lens over a *static* html2canvas snapshot taken
 * once on load, which would go stale the moment a table/filter/form changes.
 */
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Container === 'undefined' || typeof Button === 'undefined') return;

    // --- Avatar (circular) ---
    var avatarSlot = document.querySelector('[data-glass-avatar]');
    if (avatarSlot) {
        var avatarGlass = new Container({ type: 'circle', borderRadius: 23, tintOpacity: 0.35 });
        avatarGlass.element.classList.add('dv-avatar-glass', 'glass-fill-blue');
        var avatarInner = document.createElement('div');
        avatarInner.className = 'dv-avatar-inner';
        avatarInner.textContent = avatarSlot.dataset.initials || '';
        avatarGlass.addChild({ element: avatarInner });
        avatarSlot.replaceWith(avatarGlass.element);
    }

    // --- Notification bell (circular): opens the #modalVencimientos modal ---
    var bellSlot = document.querySelector('[data-glass-bell]');
    if (bellSlot) {
        var hasAlerts = bellSlot.dataset.hasAlerts === '1';
        var bellGlass = new Container({ type: 'circle', borderRadius: 23, tintOpacity: 0.4 });
        bellGlass.element.classList.add('dv-bell-glass');
        bellGlass.element.style.cursor = 'pointer';
        var bellInner = document.createElement('div');
        bellInner.className = 'dv-bell-inner' + (hasAlerts ? ' has-alerts' : '');
        bellInner.innerHTML = '<i class="bi bi-bell"></i>';
        bellGlass.addChild({ element: bellInner });
        bellSlot.replaceWith(bellGlass.element);

        bellGlass.element.addEventListener('click', function () {
            var modalEl = document.getElementById('modalVencimientos');
            if (modalEl && window.bootstrap) {
                new bootstrap.Modal(modalEl).show();
            }
        });
    }

    // --- Sidebar active item: host the real <a> inside a glass pill ---
    var activeLink = document.querySelector('.dv-sidebar a.active');
    if (activeLink) {
        var navGlass = new Container({ type: 'pill', borderRadius: 13, tintOpacity: 0.3 });
        navGlass.element.classList.add('glass-fill-accent');
        navGlass.element.style.width = '100%';
        activeLink.classList.add('dv-glass-hosted');
        activeLink.classList.remove('active');
        activeLink.parentNode.insertBefore(navGlass.element, activeLink);
        navGlass.addChild({ element: activeLink });
    }

    // --- Quick-access buttons (dashboard shortcuts): solid color glass buttons ---
    document.querySelectorAll('[data-glass-button]').forEach(function (slot) {
        var btn = new Button({
            text: slot.textContent.trim(),
            size: 15,
            type: 'rounded',
            tintOpacity: 0.3,
            onClick: function () { window.location.href = slot.dataset.href; }
        });
        btn.element.classList.add('glass-quick-btn', 'glass-fill-' + (slot.dataset.color || 'blue'));
        var icon = slot.dataset.icon;
        if (icon) {
            btn.textElement.innerHTML = '<i class="bi ' + icon + '"></i><span>' + slot.textContent.trim() + '</span>';
        }
        slot.replaceWith(btn.element);
    });
});
