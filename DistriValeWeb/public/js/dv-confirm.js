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
    var cancelBtn = document.getElementById('dvConfirmCancel');
    if (!modalEl || !messageEl || !acceptBtn || !cancelBtn) return;

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

    // Nada de data-bs-dismiss="modal" en los botones: eso cierra el modal
    // en el mismo instante del clic, y el squash de "Liquid Glass press
    // feedback" (.btn:active) apenas alcanza a empezar antes de que todo
    // se desvanezca — se sentía como si el botón no reaccionara. Este
    // pequeño respiro deja que el squash se vea antes de que el modal
    // arranque su propio fundido.
    function conFeedback(accion) {
        return function () {
            setTimeout(accion, 140);
        };
    }

    acceptBtn.addEventListener('click', conFeedback(function () {
        modal.hide();
        if (pendingForm) {
            // .submit() no dispara el evento 'submit' (a diferencia de un
            // clic real en el botón), así que no vuelve a pasar por este
            // mismo listener — se envía el formulario tal cual, sin loop.
            pendingForm.submit();
            pendingForm = null;
        }
    }));

    cancelBtn.addEventListener('click', conFeedback(function () {
        modal.hide();
    }));

    modalEl.addEventListener('hidden.bs.modal', function () {
        pendingForm = null;
    });
})();
