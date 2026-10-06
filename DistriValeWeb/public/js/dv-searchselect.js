/**
 * Lightweight searchable dropdown used on forms with long option lists
 * (cliente, financiera): a text input filters the option list as the user
 * types, backed by a hidden input that carries the real value submitted
 * with the form. No CDN dependency, consistent with the rest of the app.
 *
 * Markup contract (see vales/form.blade.php):
 *   <div class="dv-searchselect">
 *     <input type="hidden" name="...">
 *     <input type="text" class="dv-searchselect-input">
 *     <div class="dv-searchselect-menu"></div>
 *     <script type="application/json">[{"value":1,"label":"..."}]</script>
 *   </div>
 */
(function () {
    'use strict';

    function init(root) {
        if (root.dataset.dvInit === '1') return;
        root.dataset.dvInit = '1';

        var input = root.querySelector('.dv-searchselect-input');
        var hidden = root.querySelector('input[type="hidden"]');
        var menu = root.querySelector('.dv-searchselect-menu');
        var dataEl = root.querySelector('script[type="application/json"]');
        var options = dataEl ? JSON.parse(dataEl.textContent) : [];
        var selected = null;

        function findById(id) {
            return options.find(function (o) { return String(o.value) === String(id); }) || null;
        }

        function renderMenu(query) {
            var q = (query || '').trim().toLowerCase();
            var matches = q === ''
                ? options
                : options.filter(function (o) { return o.label.toLowerCase().indexOf(q) !== -1; });
            var overflow = matches.length > 50;
            matches = matches.slice(0, 50);

            menu.innerHTML = '';
            if (matches.length === 0) {
                var empty = document.createElement('div');
                empty.className = 'dv-searchselect-empty';
                empty.textContent = 'Sin resultados';
                menu.appendChild(empty);
                return;
            }
            matches.forEach(function (o) {
                var item = document.createElement('div');
                item.className = 'dv-searchselect-option';
                if (selected && String(selected.value) === String(o.value)) item.classList.add('active');
                item.textContent = o.label;
                item.dataset.value = o.value;
                menu.appendChild(item);
            });
            if (overflow) {
                var more = document.createElement('div');
                more.className = 'dv-searchselect-empty';
                more.textContent = 'Sigue escribiendo para acotar la búsqueda…';
                menu.appendChild(more);
            }
        }

        function open() {
            renderMenu(input.value === (selected ? selected.label : '') ? '' : input.value);
            menu.classList.add('show');
        }

        function close() {
            menu.classList.remove('show');
            // A la salida, si quedó texto libre que no corresponde a ninguna
            // opción real, se revierte a la última selección válida (o se
            // vacía) — el valor que de verdad se envía es el del input oculto.
            input.value = selected ? selected.label : '';
        }

        if (hidden.value) {
            selected = findById(hidden.value);
            if (selected) input.value = selected.label;
        }

        input.addEventListener('focus', open);
        input.addEventListener('input', function () {
            selected = null;
            hidden.value = '';
            input.classList.remove('is-invalid');
            open();
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        });
        input.addEventListener('blur', function () {
            // El mousedown del menú también disparara un blur del input;
            // el timeout deja que ese click se procese primero.
            setTimeout(close, 150);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { input.blur(); }
        });

        menu.addEventListener('mousedown', function (e) {
            var opt = e.target.closest('.dv-searchselect-option');
            if (!opt) return;
            e.preventDefault();
            selected = findById(opt.dataset.value);
            hidden.value = selected ? selected.value : '';
            input.value = selected ? selected.label : '';
            input.classList.remove('is-invalid');
            menu.classList.remove('show');
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    function initAll() {
        document.querySelectorAll('.dv-searchselect').forEach(init);
    }

    initAll();
    document.addEventListener('dv:nav-swapped', initAll);
    // Para pantallas que agregan buscadores al vuelo (Generar recibo: "+ Agregar recibo").
    window.DvSearchSelect = { init: init, initAll: initAll };
})();
