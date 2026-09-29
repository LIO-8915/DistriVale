<?php

namespace App\Support\Wireframe;

use App\Support\Mora;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Reemplazo completo de la capa de base de datos para la versión wireframe:
 * todo lo que antes era Eloquent/SQLite vive acá como arrays planos en la
 * sesión de Laravel (nunca toca disco), hidratados en objetos que imitan la
 * forma de los modelos reales (relaciones, métodos de negocio) lo
 * suficiente para que las vistas Blade no se enteren del cambio.
 *
 * Todo se resetea solo: session()->flush() al cerrar la app (nadie llama
 * eso explícitamente) no hace falta — basta con que la sesión no
 * sobreviva entre arranques, y el driver de sesión de este build es
 * 'file' apuntando a storage/framework/sessions, que se puede vaciar
 * libremente sin que le importe a nada.
 */
class Store
{
    private const TABLAS = ['financieras', 'clientes', 'vales', 'recibos', 'detalles', 'notas', 'liquidaciones'];

    // --- Acceso crudo a la sesión -------------------------------------

    private static function tabla(string $nombre): array
    {
        return session("wf.$nombre", []);
    }

    private static function guardarTabla(string $nombre, array $filas): void
    {
        session(["wf.$nombre" => $filas]);
    }

    private static function nextId(string $nombre): int
    {
        $n = session("wf.next.$nombre", 0) + 1;
        session(["wf.next.$nombre" => $n]);

        return $n;
    }

    private static function fecha(?string $valor): ?Carbon
    {
        return $valor ? Carbon::parse($valor) : null;
    }

    public static function ensureSeeded(): void
    {
        if (! session('wf.seeded')) {
            Sample::seed();
            session(['wf.seeded' => true]);
        }
    }

    /** Solo para depuración/pruebas manuales — reinicia todos los datos de ejemplo. */
    public static function reiniciar(): void
    {
        foreach (self::TABLAS as $t) {
            session()->forget("wf.$t");
        }
        session()->forget(['wf.seeded', 'wf.next']);
    }

    // --- Hidratación (arrays crudos -> objetos con relaciones) ---------

    private static function hidratarTodo(): array
    {
        self::ensureSeeded();

        $financieras = collect(self::tabla('financieras'))
            ->map(fn ($r) => new Entidad($r + ['exists' => true], 'id_financiera'))
            ->keyBy('id_financiera');

        $clientes = collect(self::tabla('clientes'))
            ->map(function ($r) {
                $r['created_at'] = self::fecha($r['created_at']);
                $r['updated_at'] = self::fecha($r['updated_at']);

                return new WCliente($r + ['exists' => true], 'id_cliente');
            })
            ->keyBy('id_cliente');

        $vales = collect(self::tabla('vales'))
            ->map(function ($r) use ($financieras, $clientes) {
                $r['fecha_disposicion'] = self::fecha($r['fecha_disposicion']);
                $r['fecha_ultimo_pago'] = self::fecha($r['fecha_ultimo_pago']);
                $r['created_at'] = self::fecha($r['created_at']);
                $r['updated_at'] = self::fecha($r['updated_at']);

                $vale = new WVale($r + ['exists' => true], 'id_vale');
                $vale->financiera = $financieras->get($r['id_financiera']);
                $vale->cliente = $clientes->get($r['id_cliente']);
                $vale->detallesRecibo = collect();

                return $vale;
            })
            ->keyBy('id_vale');

        $recibos = collect(self::tabla('recibos'))
            ->map(function ($r) use ($clientes) {
                $r['fecha_corte'] = self::fecha($r['fecha_corte']);
                $r['fecha_emision'] = self::fecha($r['fecha_emision']);
                $r['created_at'] = self::fecha($r['created_at']);
                $r['updated_at'] = self::fecha($r['updated_at']);

                $recibo = new Entidad($r + ['exists' => true], 'id_recibo');
                $recibo->cliente = $clientes->get($r['id_cliente']);
                $recibo->detalles = collect();

                return $recibo;
            })
            ->keyBy('id_recibo');

        $detalles = collect(self::tabla('detalles'))
            ->map(function ($r) use ($vales, $recibos) {
                $r['created_at'] = self::fecha($r['created_at']);
                $r['updated_at'] = self::fecha($r['updated_at']);

                $detalle = new Entidad($r + ['exists' => true], 'id_detalle');
                $detalle->vale = $vales->get($r['id_vale']);
                $detalle->recibo = $recibos->get($r['id_recibo']);

                return $detalle;
            })
            ->keyBy('id_detalle');

        foreach ($detalles as $detalle) {
            $detalle->vale?->detallesRecibo->push($detalle);
            $detalle->recibo?->detalles->push($detalle);
        }
        foreach ($vales as $vale) {
            $vale->detallesRecibo = $vale->detallesRecibo->sortByDesc('created_at')->values();
        }

        $notas = collect(self::tabla('notas'))
            ->map(function ($r) {
                $r['created_at'] = self::fecha($r['created_at']);
                $r['updated_at'] = self::fecha($r['updated_at']);

                return new Entidad($r + ['exists' => true], 'id_nota');
            })
            ->keyBy('id_nota');

        foreach ($clientes as $cliente) {
            $cliente->vales = $vales->filter(fn ($v) => $v->id_cliente == $cliente->id_cliente)->values();
            $cliente->recibos = $recibos->filter(fn ($r) => $r->id_cliente == $cliente->id_cliente)
                ->sortByDesc('fecha_corte')->values();
            $cliente->notas = $notas->filter(fn ($n) => $n->id_cliente == $cliente->id_cliente)
                ->sortByDesc('created_at')->values();
            $cliente->vales_count = $cliente->vales->count();
        }

        foreach ($financieras as $financiera) {
            $financiera->vales = $vales->filter(fn ($v) => $v->id_financiera == $financiera->id_financiera)->values();
            $financiera->vales_count = $financiera->vales->count();
            $saldo = (float) $financiera->vales->where('estado', '!=', 'LIQUIDADO')
                ->sum(fn ($v) => (float) $v->saldo_pendiente);
            $financiera->saldo = $saldo;
            $financiera->saldo_financiera = $saldo;
        }

        $liquidaciones = collect(self::tabla('liquidaciones'))
            ->map(function ($r) use ($financieras) {
                $r['fecha_corte'] = self::fecha($r['fecha_corte']);
                $r['fecha_limite_pago'] = self::fecha($r['fecha_limite_pago']);
                $r['fecha_deposito'] = self::fecha($r['fecha_deposito']);
                $r['created_at'] = self::fecha($r['created_at']);
                $r['updated_at'] = self::fecha($r['updated_at']);

                $liq = new Entidad($r + ['exists' => true], 'id_liquidacion');
                $liq->financiera = $financieras->get($r['id_financiera']);

                return $liq;
            })
            ->keyBy('id_liquidacion');

        return compact('financieras', 'clientes', 'vales', 'recibos', 'detalles', 'notas', 'liquidaciones');
    }

    // --- Lecturas --------------------------------------------------------

    public static function financieras(): Collection
    {
        return self::hidratarTodo()['financieras']->sortBy('nombre')->values();
    }

    public static function financierasActivas(): Collection
    {
        return self::financieras()->where('activo', true)->values();
    }

    public static function financiera($id): ?Entidad
    {
        return self::hidratarTodo()['financieras']->get((int) $id);
    }

    public static function clientes(): Collection
    {
        return self::hidratarTodo()['clientes']->sortBy('nombre_completo')->values();
    }

    public static function cliente($id): ?WCliente
    {
        return self::hidratarTodo()['clientes']->get((int) $id);
    }

    public static function vales(): Collection
    {
        return self::hidratarTodo()['vales']->values();
    }

    public static function vale($id): ?WVale
    {
        return self::hidratarTodo()['vales']->get((int) $id);
    }

    public static function recibos(): Collection
    {
        return self::hidratarTodo()['recibos']->values();
    }

    public static function recibo($id): ?Entidad
    {
        return self::hidratarTodo()['recibos']->get((int) $id);
    }

    public static function liquidaciones(): Collection
    {
        return self::hidratarTodo()['liquidaciones']->values();
    }

    /** Mismo patrón que ya usaba LiquidacionController::index() para paginar un array plano. */
    public static function paginar(Collection $items, int|\Closure $porPagina, ?int $pagina = null, array $opciones = []): LengthAwarePaginator
    {
        $total = $items->count();
        $tamano = max((int) (is_callable($porPagina) ? $porPagina($total) : $porPagina), 1);
        $pagina = $pagina ?? LengthAwarePaginator::resolveCurrentPage();
        $slice = $items->slice(($pagina - 1) * $tamano, $tamano)->values();

        return new LengthAwarePaginator($slice, $total, $tamano, $pagina, $opciones);
    }

    // --- Objetos vacíos para los formularios "nuevo" --------------------

    public static function nuevaFinanciera(): Entidad
    {
        return new Entidad([
            'id_financiera' => null, 'nombre' => null, 'comision_porcentaje' => null,
            'recargo_porcentaje' => null, 'activo' => null, 'exists' => false,
        ], 'id_financiera');
    }

    public static function nuevoCliente(): WCliente
    {
        return new WCliente([
            'id_cliente' => null, 'nombre_completo' => null, 'telefono' => null,
            'direccion' => null, 'activo' => null, 'exists' => false,
            'vales' => collect(), 'recibos' => collect(), 'notas' => collect(), 'vales_count' => 0,
        ], 'id_cliente');
    }

    public static function nuevoVale(): WVale
    {
        return new WVale([
            'id_vale' => null, 'id_cliente' => null, 'id_financiera' => null, 'folio_vale' => null,
            'fecha_disposicion' => null, 'monto_original' => null, 'cuota_quincenal' => null,
            'total_quincenas' => null, 'quincena_actual' => null, 'saldo_pendiente' => null,
            'estado' => null, 'fecha_ultimo_pago' => null, 'exists' => false,
            'cliente' => null, 'financiera' => null, 'detallesRecibo' => collect(),
        ], 'id_vale');
    }

    // --- Escrituras --------------------------------------------------------

    public static function crearFinanciera(array $datos): Entidad
    {
        $filas = self::tabla('financieras');
        $id = self::nextId('financieras');
        $ahora = now()->toDateTimeString();
        $filas[$id] = $datos + ['id_financiera' => $id, 'created_at' => $ahora, 'updated_at' => $ahora];
        self::guardarTabla('financieras', $filas);

        return self::financiera($id);
    }

    public static function actualizarFinanciera($id, array $datos): Entidad
    {
        $id = (int) $id;
        $filas = self::tabla('financieras');
        $filas[$id] = array_merge($filas[$id], $datos, ['updated_at' => now()->toDateTimeString()]);
        self::guardarTabla('financieras', $filas);

        return self::financiera($id);
    }

    public static function eliminarFinanciera($id): void
    {
        $id = (int) $id;

        foreach (collect(self::tabla('vales'))->where('id_financiera', $id)->pluck('id_vale') as $idVale) {
            self::eliminarValeInterno($idVale);
        }

        self::guardarTabla('liquidaciones', collect(self::tabla('liquidaciones'))
            ->reject(fn ($l) => (int) $l['id_financiera'] === $id)->all());

        $filas = self::tabla('financieras');
        unset($filas[$id]);
        self::guardarTabla('financieras', $filas);
    }

    public static function crearCliente(array $datos): WCliente
    {
        $filas = self::tabla('clientes');
        $id = self::nextId('clientes');
        $ahora = now()->toDateTimeString();
        $filas[$id] = $datos + ['id_cliente' => $id, 'created_at' => $ahora, 'updated_at' => $ahora];
        self::guardarTabla('clientes', $filas);

        return self::cliente($id);
    }

    public static function actualizarCliente($id, array $datos): WCliente
    {
        $id = (int) $id;
        $filas = self::tabla('clientes');
        $filas[$id] = array_merge($filas[$id], $datos, ['updated_at' => now()->toDateTimeString()]);
        self::guardarTabla('clientes', $filas);

        return self::cliente($id);
    }

    public static function eliminarCliente($id): void
    {
        $id = (int) $id;

        foreach (collect(self::tabla('vales'))->where('id_cliente', $id)->pluck('id_vale') as $idVale) {
            self::eliminarValeInterno($idVale);
        }

        foreach (collect(self::tabla('recibos'))->where('id_cliente', $id)->pluck('id_recibo') as $idRecibo) {
            self::guardarTabla('detalles', collect(self::tabla('detalles'))
                ->reject(fn ($d) => (int) $d['id_recibo'] === $idRecibo)->all());
        }
        self::guardarTabla('recibos', collect(self::tabla('recibos'))
            ->reject(fn ($r) => (int) $r['id_cliente'] === $id)->all());

        self::guardarTabla('notas', collect(self::tabla('notas'))
            ->reject(fn ($n) => (int) $n['id_cliente'] === $id)->all());

        $filas = self::tabla('clientes');
        unset($filas[$id]);
        self::guardarTabla('clientes', $filas);
    }

    public static function crearNota(int $idCliente, string $contenido): Entidad
    {
        $filas = self::tabla('notas');
        $id = self::nextId('notas');
        $ahora = now()->toDateTimeString();
        $filas[$id] = ['id_nota' => $id, 'id_cliente' => $idCliente, 'contenido' => $contenido, 'created_at' => $ahora, 'updated_at' => $ahora];
        self::guardarTabla('notas', $filas);

        return self::hidratarTodo()['notas']->get($id);
    }

    public static function crearVale(array $datos): WVale
    {
        $filas = self::tabla('vales');
        $id = self::nextId('vales');
        $ahora = now()->toDateTimeString();
        $datos['fecha_disposicion'] = $datos['fecha_disposicion'] ?? null;
        $datos['fecha_ultimo_pago'] = $datos['fecha_ultimo_pago'] ?? null;
        $filas[$id] = $datos + ['id_vale' => $id, 'created_at' => $ahora, 'updated_at' => $ahora];
        self::guardarTabla('vales', $filas);

        return self::vale($id);
    }

    public static function actualizarVale($id, array $datos): WVale
    {
        $id = (int) $id;
        $filas = self::tabla('vales');
        $filas[$id] = array_merge($filas[$id], $datos, ['updated_at' => now()->toDateTimeString()]);
        self::guardarTabla('vales', $filas);

        return self::vale($id);
    }

    public static function eliminarVale($id): void
    {
        self::eliminarValeInterno((int) $id);
    }

    private static function eliminarValeInterno(int $idVale): void
    {
        self::guardarTabla('detalles', collect(self::tabla('detalles'))
            ->reject(fn ($d) => (int) $d['id_vale'] === $idVale)->all());

        $filas = self::tabla('vales');
        unset($filas[$idVale]);
        self::guardarTabla('vales', $filas);
    }

    public static function crearReciboConDetalles(array $datosRecibo, array $filasDetalle): Entidad
    {
        $ahora = now()->toDateTimeString();

        $recibos = self::tabla('recibos');
        $idRecibo = self::nextId('recibos');
        $recibos[$idRecibo] = $datosRecibo + ['id_recibo' => $idRecibo, 'created_at' => $ahora, 'updated_at' => $ahora];
        self::guardarTabla('recibos', $recibos);

        $detalles = self::tabla('detalles');
        foreach ($filasDetalle as $fila) {
            $idDetalle = self::nextId('detalles');
            $detalles[$idDetalle] = $fila + ['id_detalle' => $idDetalle, 'id_recibo' => $idRecibo, 'created_at' => $ahora, 'updated_at' => $ahora];
        }
        self::guardarTabla('detalles', $detalles);

        return self::recibo($idRecibo);
    }

    public static function confirmarPago($idRecibo): void
    {
        $recibo = self::recibo($idRecibo);
        foreach ($recibo->detalles as $detalle) {
            self::registrarPagoVale($detalle->vale->id_vale, (float) $detalle->monto_pago);
        }
        self::actualizarMora();
    }

    public static function registrarPagoVale($idVale, float $monto): WVale
    {
        $idVale = (int) $idVale;
        $filas = self::tabla('vales');
        $v = $filas[$idVale];

        $nuevoSaldo = max(0, (float) $v['saldo_pendiente'] - $monto);
        $v['saldo_pendiente'] = $nuevoSaldo;
        $v['quincena_actual'] = min((int) $v['total_quincenas'], (int) $v['quincena_actual'] + 1);
        $v['estado'] = $nuevoSaldo <= 0 ? 'LIQUIDADO' : $v['estado'];
        $v['fecha_ultimo_pago'] = now()->toDateTimeString();
        $v['updated_at'] = now()->toDateTimeString();

        $filas[$idVale] = $v;
        self::guardarTabla('vales', $filas);

        return self::vale($idVale);
    }

    public static function guardarLiquidacion(string $periodo, array $filas): void
    {
        $liquidaciones = self::tabla('liquidaciones');
        $ahora = now()->toDateTimeString();

        foreach ($filas as $fila) {
            $idFinanciera = (int) $fila['id_financiera'];
            $cobrar = (float) ($fila['monto_cobrar'] ?? 0);
            $poner = (float) ($fila['monto_poner'] ?? 0);
            $ganancias = (float) ($fila['monto_ganancias'] ?? 0);

            $existenteId = null;
            foreach ($liquidaciones as $id => $l) {
                if ($l['periodo_quincena'] === $periodo && (int) $l['id_financiera'] === $idFinanciera) {
                    $existenteId = $id;
                    break;
                }
            }

            $datos = [
                'periodo_quincena' => $periodo, 'id_financiera' => $idFinanciera,
                'monto_cobrar' => $cobrar, 'monto_poner' => $poner,
                'monto_depositar' => $cobrar - $poner, 'monto_ganancias' => $ganancias,
                'updated_at' => $ahora,
            ];

            if ($existenteId !== null) {
                $liquidaciones[$existenteId] = array_merge($liquidaciones[$existenteId], $datos);
            } else {
                $id = self::nextId('liquidaciones');
                $liquidaciones[$id] = $datos + [
                    'id_liquidacion' => $id, 'fecha_corte' => null,
                    'fecha_limite_pago' => null, 'fecha_deposito' => null, 'created_at' => $ahora,
                ];
            }
        }

        self::guardarTabla('liquidaciones', $liquidaciones);
    }

    /**
     * Mismo cálculo que App\Support\Mora::actualizar() (ver ese archivo),
     * pero sobre los arrays de sesión en vez de una consulta/transacción a
     * la base de datos real.
     *
     * @return array{en_mora: int, regularizados: int}
     */
    public static function actualizarMora(): array
    {
        $hoy = now(Mora::ZONA_HORARIA)->copy()->startOfDay();
        $resultado = ['en_mora' => 0, 'regularizados' => 0];

        $cortes = collect(self::tabla('liquidaciones'))
            ->filter(fn ($l) => ! empty($l['fecha_corte']) && ! empty($l['fecha_limite_pago']))
            ->filter(fn ($l) => Carbon::parse($l['fecha_corte'])->lte($hoy))
            ->sortByDesc('fecha_corte')
            ->unique('id_financiera');

        $vales = self::tabla('vales');

        foreach ($cortes as $corte) {
            $idFinanciera = (int) $corte['id_financiera'];
            $fechaCorte = Carbon::parse($corte['fecha_corte'])->toDateString();

            foreach ($vales as $id => $v) {
                if ((int) $v['id_financiera'] !== $idFinanciera || $v['estado'] !== 'EN_MORA') {
                    continue;
                }
                if (! empty($v['fecha_ultimo_pago']) && Carbon::parse($v['fecha_ultimo_pago'])->toDateString() >= $fechaCorte) {
                    $vales[$id]['estado'] = 'ACTIVO';
                    $vales[$id]['updated_at'] = now()->toDateTimeString();
                    $resultado['regularizados']++;
                }
            }

            if (Carbon::parse($corte['fecha_limite_pago'])->toDateString() >= $hoy->toDateString()) {
                continue;
            }

            foreach ($vales as $id => $v) {
                if ((int) $v['id_financiera'] !== $idFinanciera || $v['estado'] !== 'ACTIVO') {
                    continue;
                }
                $disposicionOk = empty($v['fecha_disposicion']) || Carbon::parse($v['fecha_disposicion'])->toDateString() <= $fechaCorte;
                $sinPagoReciente = empty($v['fecha_ultimo_pago']) || Carbon::parse($v['fecha_ultimo_pago'])->toDateString() < $fechaCorte;
                if ($disposicionOk && $sinPagoReciente) {
                    $vales[$id]['estado'] = 'EN_MORA';
                    $vales[$id]['updated_at'] = now()->toDateTimeString();
                    $resultado['en_mora']++;
                }
            }
        }

        self::guardarTabla('vales', $vales);

        return $resultado;
    }
}
