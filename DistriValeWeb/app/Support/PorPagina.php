<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Selector "Mostrar 10 · 25 · 50 · Todos" de las tablas paginadas. Siempre
 * arranca en 10: la elección viaja solo en la URL (?por_pagina=) y no se
 * recuerda entre visitas.
 */
class PorPagina
{
    public const OPCIONES = [10, 25, 50, 0]; // 0 = todos

    public const DEFECTO = 10;

    public static function desde(Request $request): int
    {
        // Comparar como texto: (int) de null o de basura da 0 y caería en "todos".
        $valor = (string) $request->get('por_pagina');

        return in_array($valor, array_map('strval', self::OPCIONES), true) ? (int) $valor : self::DEFECTO;
    }

    /**
     * Tamaño para ->paginate(): con "todos" usa el total real de la consulta
     * (paginate acepta un callable que recibe ese total).
     */
    public static function tamano(int $porPagina): int|\Closure
    {
        return $porPagina ?: fn (int $total) => max($total, 1);
    }
}
