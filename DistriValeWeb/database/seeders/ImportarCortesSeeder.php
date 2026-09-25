<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Financiera;
use App\Models\LiquidacionQuincena;
use App\Models\Vale;
use App\Support\Quincena;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Importa los estados de cuenta de las financieras transcritos a CSV en
 * database/importaciones/<corte>/ (carpeta fuera de git: son datos reales de
 * clientes). Cada carpeta trae un corte.json con los totales de los PDFs, que
 * se usan para validar la transcripción antes de confirmar la transacción.
 *
 * Es idempotente: vales por (financiera, folio) y clientes por nombre
 * normalizado, así que volver a correrlo actualiza en vez de duplicar.
 *
 *   php artisan db:seed --class=ImportarCortesSeeder
 */
class ImportarCortesSeeder extends Seeder
{
    /** @var array<string, Cliente> */
    private array $clientes = [];

    public function run(): void
    {
        $base = database_path('importaciones');
        $cortes = glob($base.'/*/corte.json') ?: [];

        if (! $cortes) {
            $this->command?->warn("No hay cortes para importar en {$base}.");

            return;
        }

        foreach (Cliente::all() as $cliente) {
            $this->clientes[$this->normalizar($cliente->nombre_completo)] = $cliente;
        }

        foreach ($cortes as $archivo) {
            DB::transaction(fn () => $this->importarCorte(dirname($archivo), json_decode(file_get_contents($archivo), true, flags: JSON_THROW_ON_ERROR)));
        }
    }

    private function importarCorte(string $dir, array $corte): void
    {
        foreach ($corte['financieras'] as $conf) {
            $financiera = Financiera::where('nombre', $conf['nombre'])->firstOrFail();
            $filas = $this->leerCsv($dir.'/'.$conf['archivo']);

            // El periodo sale de la fecha de corte de cada financiera, nunca de
            // la fecha de pago: los pagos caen en la quincena siguiente.
            $fechaCorte = Carbon::parse($conf['fecha_corte']);
            $periodo = Quincena::paraFecha($fechaCorte)['periodo_quincena'];

            $sumaCuotas = 0;
            foreach ($filas as $fila) {
                $this->validarFila($conf['nombre'], $fila, $fechaCorte);
                $cliente = $this->cliente($fila['cliente']);
                $montoOriginal = $conf['monto_original'] === 'cuota_por_plazo'
                    ? $fila['cuota'] * $fila['m']
                    : $fila['importe'];

                Vale::updateOrCreate(
                    ['id_financiera' => $financiera->id_financiera, 'folio_vale' => $fila['folio']],
                    [
                        'id_cliente' => $cliente->id_cliente,
                        'fecha_disposicion' => $fila['fecha'],
                        'monto_original' => $montoOriginal,
                        'cuota_quincenal' => $fila['cuota'],
                        'total_quincenas' => $fila['m'],
                        'quincena_actual' => $fila['n'],
                        'saldo_pendiente' => $fila['saldo_anterior'],
                        'estado' => 'ACTIVO',
                    ]
                );
                $sumaCuotas += $fila['cuota'];
            }

            // Si la transcripción no cuadra con los totales impresos en el PDF,
            // se aborta todo el corte (la transacción hace rollback).
            if (count($filas) !== $conf['vales'] || abs($sumaCuotas - $conf['suma_cuotas']) > 0.01) {
                throw new RuntimeException(sprintf(
                    '%s no cuadra con el PDF: %d vales / $%s en cuotas (esperado %d / $%s).',
                    $conf['nombre'], count($filas), number_format($sumaCuotas, 2), $conf['vales'], number_format($conf['suma_cuotas'], 2)
                ));
            }

            LiquidacionQuincena::updateOrCreate(
                ['periodo_quincena' => $periodo, 'id_financiera' => $financiera->id_financiera],
                [
                    'fecha_corte' => $fechaCorte,
                    'fecha_limite_pago' => $conf['fecha_limite_pago'],
                    'fecha_deposito' => $conf['fecha_deposito'],
                    'monto_cobrar' => $conf['cobrar'],
                    'monto_poner' => 0,
                    'monto_depositar' => $conf['depositar'],
                    'monto_ganancias' => round($conf['cobrar'] - $conf['depositar'], 2),
                ]
            );

            $this->command?->line(sprintf('  %-12s %s  corte %s  %3d vales  cuotas $%s',
                $conf['nombre'], $periodo, $fechaCorte->format('d/m/Y'), count($filas), number_format($sumaCuotas, 2)));
        }
    }

    private function validarFila(string $financiera, array $fila, Carbon $fechaCorte): void
    {
        $error = match (true) {
            $fila['fecha'] === null => 'fecha inválida',
            $fila['fecha']->gt($fechaCorte) => 'fecha posterior al corte ('.$fechaCorte->format('d/m/Y').')',
            $fila['n'] < 1 || $fila['n'] > $fila['m'] => "pago {$fila['n']} de {$fila['m']} inválido",
            $fila['cuota'] > $fila['saldo_anterior'] + 0.01 => 'cuota mayor al saldo',
            default => null,
        };

        if ($error) {
            throw new RuntimeException("{$financiera} folio {$fila['folio']} ({$fila['cliente']}): {$error}.");
        }
    }

    /**
     * Formato: cliente;folios;fecha;n;m;importe;saldo_anterior;cuota
     * Un cliente vacío repite el del renglón anterior, y `folios` puede traer
     * varios separados por coma cuando comparten todos los demás valores (así
     * vienen agrupados en los estados de cuenta).
     */
    private function leerCsv(string $ruta): array
    {
        $lineas = file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        array_shift($lineas);

        $filas = [];
        $clienteAnterior = null;
        foreach ($lineas as $linea) {
            [$cliente, $folios, $fecha, $n, $m, $importe, $saldo, $cuota] = array_map('trim', explode(';', $linea));
            try {
                $fecha = Carbon::createFromFormat('!d/m/Y', $fecha) ?: null;
            } catch (\Throwable) {
                $fecha = null;
            }
            $cliente = $cliente !== '' ? $cliente : $clienteAnterior;
            $clienteAnterior = $cliente;

            foreach (explode(',', $folios) as $folio) {
                $filas[] = [
                    'cliente' => $cliente,
                    'folio' => trim($folio),
                    'fecha' => $fecha,
                    'n' => (int) $n,
                    'm' => (int) $m,
                    'importe' => (float) $importe,
                    'saldo_anterior' => (float) $saldo,
                    'cuota' => (float) $cuota,
                ];
            }
        }

        return $filas;
    }

    private function cliente(string $nombre): Cliente
    {
        $clave = $this->normalizar($nombre);

        return $this->clientes[$clave] ??= Cliente::create([
            'nombre_completo' => mb_strtoupper(preg_replace('/\s+/', ' ', trim($nombre))),
            'activo' => true,
        ]);
    }

    /**
     * Cada financiera escribe el mismo nombre distinto (BAÑAGA/BANAGA,
     * JESÚS/JESUS, mayúsculas/minúsculas, espacios dobles, "ROMERO ."), así que
     * los clientes se empatan por esta clave y no por el texto literal.
     */
    private function normalizar(string $nombre): string
    {
        $nombre = Str::upper(Str::ascii($nombre));

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z ]/', ' ', $nombre)));
    }
}
