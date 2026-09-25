@extends('layouts.app')

@section('title', 'Respaldo')
@section('subtitle', 'Guardá y traé la base de datos desde tu Google Drive.')

@section('content')

@unless ($configured)
    <div class="card p-4 mb-3">
        <h6 class="mb-2"><i class="bi bi-exclamation-triangle text-danger me-1"></i> Google Drive no está configurado</h6>
        <p class="text-muted mb-0">
            Falta cargar las credenciales de un cliente OAuth de Google en el archivo <code>.env</code>
            (<code>GOOGLE_DRIVE_CLIENT_ID</code> y <code>GOOGLE_DRIVE_CLIENT_SECRET</code>).
            Los pasos para crearlas están en <code>ARQUITECTURA_TAURI.md</code>.
        </p>
    </div>
@endunless

<div class="card p-4 mb-3">
    <h6 class="mb-3">Cuenta de Google Drive</h6>
    @if ($connected)
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-success">Conectado</span>
                <span>{{ $accountEmail }}</span>
            </div>
            <form method="POST" action="{{ route('drive.disconnect') }}" onsubmit="return confirm('¿Desconectar esta cuenta de Google Drive? Vas a poder volver a conectarla cuando quieras.');">
                @csrf
                <button class="btn btn-outline-danger btn-sm">Desconectar</button>
            </form>
        </div>
    @else
        <p class="text-muted">No hay ninguna cuenta conectada todavía.</p>
        <form method="POST" action="{{ route('drive.connect') }}">
            @csrf
            <button class="btn btn-primary" {{ $configured ? '' : 'disabled' }}>
                <i class="bi bi-google me-1"></i> Conectar con Google Drive
            </button>
        </form>
    @endif
</div>

@if ($connected)
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card p-4 h-100">
                <h6 class="mb-2"><i class="bi bi-cloud-arrow-up text-primary me-1"></i> Respaldar en Drive</h6>
                <p class="text-muted">
                    Sube una copia consistente de la base de datos actual a tu Drive.
                    @if ($lastBackupAt)
                        Último respaldo: <strong>{{ $lastBackupAt->diffForHumans() }}</strong>
                        ({{ $lastBackupAt->format('d/m/Y H:i') }}).
                    @else
                        Todavía no hiciste ningún respaldo.
                    @endif
                </p>
                <form method="POST" action="{{ route('drive.backup') }}">
                    @csrf
                    <button class="btn btn-primary"><i class="bi bi-cloud-arrow-up me-1"></i> Respaldar ahora</button>
                </form>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card p-4 h-100">
                <h6 class="mb-2"><i class="bi bi-cloud-arrow-down text-primary me-1"></i> Restaurar desde Drive</h6>
                <p class="text-muted">
                    Trae el último respaldo de Drive y reemplaza la base de datos local.
                    Se guarda una copia de la base actual por si hay que deshacerlo.
                </p>
                <form method="POST" action="{{ route('drive.restore') }}"
                      onsubmit="return confirm('Esto va a reemplazar TODOS los datos locales por el último respaldo de Drive. ¿Continuar?');">
                    @csrf
                    <button class="btn btn-outline-primary"><i class="bi bi-cloud-arrow-down me-1"></i> Restaurar</button>
                </form>
            </div>
        </div>
    </div>

    @if ($rollbackAvailable)
        <div class="card p-4 mt-3" style="border-color: rgba(255,159,67,.5);">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                <div>
                    <h6 class="mb-1"><i class="bi bi-arrow-counterclockwise text-warning me-1"></i> Hay una restauración reciente</h6>
                    <p class="text-muted mb-0">
                        Se restauró la base desde Drive
                        {{ \Illuminate\Support\Carbon::parse($rollbackMeta['restored_at'])->diffForHumans() }}.
                        Si no era lo que esperabas, podés volver a como estaba antes.
                    </p>
                </div>
                <form method="POST" action="{{ route('drive.rollback') }}"
                      onsubmit="return confirm('Esto va a reemplazar la base actual por la que había ANTES de la última restauración. Los cambios hechos después de restaurar se van a perder. ¿Continuar?');">
                    @csrf
                    <button class="btn btn-outline-warning"><i class="bi bi-arrow-counterclockwise me-1"></i> Devolver cambios de la db</button>
                </form>
            </div>
        </div>
    @endif
@endif

@endsection
