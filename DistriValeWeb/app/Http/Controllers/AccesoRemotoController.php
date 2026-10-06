<?php

namespace App\Http\Controllers;

use App\Models\DispositivoRemoto;
use App\Support\AccesoRemoto;
use App\Support\Mora;
use Illuminate\Http\JsonResponse;

/**
 * Administración del acceso remoto — solo desde la PC (middleware solo.local
 * en las rutas). El interruptor, el firewall y la lista de dispositivos viven
 * aquí; la decisión de "quién entra" la toma ControlAcceso con los códigos
 * que RemotoController genera.
 */
class AccesoRemotoController extends Controller
{
    public function index()
    {
        AccesoRemoto::limpiar();

        return view('acceso-remoto.index', ['datos' => $this->datos()]);
    }

    public function estado(): JsonResponse
    {
        AccesoRemoto::limpiarSiToca();

        return response()->json($this->datos());
    }

    /** Lo sondea el modal global (todas las pantallas) mientras el acceso remoto está encendido. */
    public function pendientes(): JsonResponse
    {
        return response()->json(['pendientes' => AccesoRemoto::activo() ? $this->pendientesVigentes() : []]);
    }

    public function activar(): JsonResponse
    {
        if (! AccesoRemoto::estado()['disponible']) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'El acceso remoto no está disponible en este modo: necesita el servidor integrado de la app de escritorio.',
            ], 422);
        }

        AccesoRemoto::pedirHabilitado(true);

        return response()->json(['ok' => true]);
    }

    public function desactivar(): JsonResponse
    {
        // Al apagarlo nadie queda autorizado: encenderlo de nuevo exige emparejar otra vez.
        AccesoRemoto::revocarTodos();
        AccesoRemoto::pedirHabilitado(false);

        return response()->json(['ok' => true]);
    }

    public function rechazar(DispositivoRemoto $dispositivo): JsonResponse
    {
        if ($dispositivo->estado === 'pendiente') {
            $dispositivo->update(['estado' => 'rechazado', 'codigo' => null]);
        }

        return response()->json(['ok' => true]);
    }

    public function revocar(DispositivoRemoto $dispositivo): JsonResponse
    {
        if ($dispositivo->estado === 'autorizado') {
            $dispositivo->update(['estado' => 'revocado']);
        }

        return response()->json(['ok' => true]);
    }

    public function firewall(): JsonResponse
    {
        return $this->accion('crear_regla_firewall');
    }

    public function verificarFirewall(): JsonResponse
    {
        return $this->accion('verificar_firewall');
    }

    private function accion(string $nombre): JsonResponse
    {
        $id = AccesoRemoto::pedirAccion($nombre);

        return $id
            ? response()->json(['ok' => true, 'id' => $id])
            : response()->json(['ok' => false, 'mensaje' => 'Esto necesita el servidor integrado de la app de escritorio.'], 422);
    }

    private function pendientesVigentes(): array
    {
        return DispositivoRemoto::pendientesVigentes()->orderBy('id')->get()->map(fn (DispositivoRemoto $d) => [
            'id' => $d->id,
            'nombre' => $d->nombre,
            'ip' => $d->ip,
            // $hidden solo oculta en toArray()/JSON; aquí se pide el atributo a propósito (es para la PC).
            'codigo' => $d->codigo,
            'segundos' => max(0, $d->codigo_expira_en->timestamp - now()->timestamp),
            'intentos' => $d->intentos,
        ])->all();
    }

    private function datos(): array
    {
        $estado = AccesoRemoto::estado();

        return $estado + [
            'deseado' => AccesoRemoto::deseado(),
            'puerto_defecto' => AccesoRemoto::PUERTO_POR_DEFECTO,
            'pendientes' => $this->pendientesVigentes(),
            'dispositivos' => DispositivoRemoto::autorizadosEnEstaSesion(AccesoRemoto::bootId())
                ->orderByDesc('autorizado_en')->get()->map(fn (DispositivoRemoto $d) => [
                    'id' => $d->id,
                    'nombre' => $d->nombre,
                    'ip' => $d->ip,
                    // La app guarda en UTC; el negocio opera en La Paz (ver Mora::ZONA_HORARIA).
                    'autorizado' => $d->autorizado_en?->timezone(Mora::ZONA_HORARIA)->format('H:i'),
                    'ultimo_uso' => $d->ultimo_uso_en?->diffForHumans(),
                ])->all(),
        ];
    }
}
