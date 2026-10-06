{{-- Modal global (solo en la PC): muestra el código de 6 dígitos de cada
     dispositivo que pide acceso remoto. Lo alimenta public/js/dv-remoto.js. --}}
<div class="modal fade" id="modalSolicitudRemota" tabindex="-1" aria-hidden="true"
     data-url-pendientes="{{ route('acceso-remoto.pendientes') }}"
     data-url-rechazar="{{ url('acceso-remoto/solicitudes/__ID__/rechazar') }}"
     data-activo="{{ $remotoActivo ? '1' : '0' }}">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content dv-confirm-modal">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title"><i class="bi bi-phone"></i> Un dispositivo quiere conectarse</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="small mb-3">Escribe este código en el dispositivo para darle acceso. Si no reconoces la solicitud, recházala.</p>
                <div id="dv-solicitudes-lista"></div>
            </div>
        </div>
    </div>
</div>
