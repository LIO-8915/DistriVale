<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Financiera;
use App\Models\Vale;
use App\Support\Mora;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * El PDF "Relación de cobranza" ponía las tarjetas de cliente con float:left y dompdf se
 * descomponía con alturas distintas: con el corte real (187 clientes) todo se apilaba en UNA
 * hoja, encimado y fuera del papel. Ahora es una tabla de 2 celdas por fila.
 */
class RelacionCobranzaPdfTest extends TestCase
{
    use RefreshDatabase;

    private function financieraConClientes(int $clientes): Financiera
    {
        Cache::put('mora:revisada:'.now(Mora::ZONA_HORARIA)->toDateString(), true, now()->addDay());
        $f = Financiera::create(['nombre' => 'CaptaVale', 'comision_porcentaje' => 20, 'recargo_porcentaje' => 20, 'activo' => true]);
        for ($i = 1; $i <= $clientes; $i++) {
            $c = Cliente::create(['nombre_completo' => 'CLIENTE NUMERO '.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'activo' => true]);
            // alturas distintas a propósito: de 1 a 4 vales por cliente
            for ($j = 1; $j <= (($i % 4) + 1); $j++) {
                Vale::create(['id_cliente' => $c->id_cliente, 'id_financiera' => $f->id_financiera, 'folio_vale' => "D{$i}0{$j}", 'fecha_disposicion' => now()->toDateString(),
                    'monto_original' => 1000, 'cuota_quincenal' => 100, 'total_quincenas' => 10, 'quincena_actual' => 1, 'saldo_pendiente' => 1000, 'recargo_acumulado' => 0, 'quincenas_vencidas' => 0, 'estado' => 'ACTIVO']);
            }
        }

        return $f;
    }

    public function test_el_pdf_se_genera_y_ocupa_varias_hojas_con_muchos_clientes(): void
    {
        $f = $this->financieraConClientes(60);

        $r = $this->get(route('financieras.pdf', ['id_financiera' => $f->id_financiera]))->assertOk();

        $this->assertStringContainsString('application/pdf', $r->headers->get('Content-Type'));
        $pdf = $r->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        // Cada /Type /Page es una hoja: con 60 clientes (30 filas) no pueden caber en una sola.
        $this->assertGreaterThan(1, preg_match_all('~/Type\s*/Page[^s]~', $pdf), 'varias hojas, no todo apilado en una');
    }

    public function test_las_tarjetas_van_en_una_cuadricula_de_tabla_sin_floats(): void
    {
        $f = $this->financieraConClientes(5);
        $vales = Vale::with(['cliente', 'financiera'])->get()->sortBy('cliente.nombre_completo');

        $html = view('financieras.pdf', [
            'periodo' => '01 AL 15 DE OCTUBRE 2026',
            'financieras' => collect([$f]),
            'porFinanciera' => $vales->groupBy('id_financiera'),
        ])->render();

        $this->assertStringContainsString('class="pdf-grid"', $html);
        $this->assertSame(3, preg_match_all('~<tr>\s*<td class="pdf-grid-cell~', $html), 'cinco clientes = tres filas de la cuadrícula (2 + 2 + 1)');
        $this->assertSame(5, substr_count($html, 'class="pdf-client-card"'), 'una tarjeta por cliente');
        $this->assertSame(5, substr_count($html, 'TOTALES</td>'), 'cada tarjeta cierra con sus totales');
        // El tema ya no usa float para las tarjetas
        $tema = file_get_contents(resource_path('views/pdf/_theme.blade.php'));
        $this->assertDoesNotMatchRegularExpression('/\.pdf-client-card\s*\{[^}]*float\s*:/', $tema);
    }
}
