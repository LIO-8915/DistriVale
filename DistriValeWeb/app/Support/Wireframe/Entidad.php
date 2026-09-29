<?php

namespace App\Support\Wireframe;

use Illuminate\Contracts\Routing\UrlRoutable;

/**
 * Reemplazo liviano de un modelo Eloquent para la versión wireframe: no
 * toca base de datos, solo carga las columnas que le pasás al construirla
 * (o que le agrega Store al hidratar relaciones) como propiedades públicas
 * dinámicas — así las vistas Blade (`$cliente->nombre_completo`,
 * `$vale->financiera->nombre`, etc.) funcionan exactamente igual que con
 * Eloquent, sin que esta clase sepa nada de cada campo en particular.
 *
 * Implementa UrlRoutable (lo mismo que hace Eloquent\Model) para que
 * `route('clientes.show', $cliente)` — el patrón que usan todas las vistas
 * en vez de armar la URL a mano — resuelva sola al id correcto en vez de
 * intentar convertir el objeto entero a string.
 */
#[\AllowDynamicProperties]
class Entidad implements UrlRoutable
{
    public function __construct(array $atributos = [], private string $routeKeyName = 'id')
    {
        foreach ($atributos as $clave => $valor) {
            $this->$clave = $valor;
        }
    }

    // Eloquent devuelve null para cualquier atributo que no exista, sin
    // avisar — acá se replica lo mismo: sin esto, una vista que lee un
    // campo que este objeto en particular nunca llegó a tener (por
    // ejemplo, un campo opcional que no vino en el form) tira "Undefined
    // property" en vez de mostrarse vacío como pasaría con un modelo real.
    public function __get($nombre)
    {
        return null;
    }

    public function __isset($nombre): bool
    {
        return false;
    }

    public function getRouteKey(): mixed
    {
        return $this->{$this->routeKeyName};
    }

    public function getRouteKeyName(): string
    {
        return $this->routeKeyName;
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return null;
    }

    public function resolveChildRouteBinding($childType, $value, $field): ?self
    {
        return null;
    }
}
