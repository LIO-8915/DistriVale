/**
 * Reemplaza el confirm() nativo del navegador (una ventanita gris genérica,
 * sin estilo, con la URL como título) por el modal de cristal #dvConfirmModal
 * en todos los formularios de eliminar/confirmar de la app.
 *
 * Uso: en vez de `onsubmit="return confirm('¿Eliminar?')"`, el <form> lleva
 * `data-confirm="¿Eliminar?"`. Un solo listener delegado en document cubre
 * cualquier formulario que exista ahora o que dv-nav.js traiga después al
 * navegar — no hace falta re-inicializar nada por pantalla.
 */
(function () {
    'use strict';

    var modalEl = document.getElementById('dvConfirmModal');
    var messageEl = document.getElementById('dvConfirmMessage');
    var acceptBtn = document.getElementById('dvConfirmAccept');
    if (!modalEl || !messageEl || !acceptBtn) return;

    var modal = new bootstrap.Modal(modalEl);
    var pendingForm = null;

    document.addEventListener('submit', function (e) {
        var form = e.target.closest('form[data-confirm]');
        if (!form) return;
        e.preventDefault();
        pendingForm = form;
        messageEl.textContent = form.dataset.confirm;
        modal.show();
    });

    acceptBtn.addEventListener('click', function () {
        modal.hide();
        if (pendingForm) {
            // .submit() no dispara el evento 'submit' (a diferencia de un
            // clic real en el botón), así que no vuelve a pasar por este
            // mismo listener — se envía el formulario tal cual, sin loop.
            pendingForm.submit();
            pendingForm = null;
        }
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        pendingForm = null;
    });
})();
