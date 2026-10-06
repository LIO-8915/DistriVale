<?php

namespace Tests\Feature;

use App\Models\PerfilUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use App\Support\Mora;
use Tests\TestCase;

/**
 * "Tema contrastado" del perfil: apagado por defecto (diseño de cristal original); al
 * encenderlo el layout pone la clase `dv-contraste` en <html> y public/css/dv-contraste.css
 * (todo acotado a esa clase) toma efecto.
 */
class PerfilContrasteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('mora:revisada:'.now(Mora::ZONA_HORARIA)->toDateString(), true, now()->addDay());
    }

    private function datosPerfil(array $extra = []): array
    {
        return array_merge(['nombre' => 'Elia Véliz', 'cargo' => 'Administradora', 'color' => 'blue'], $extra);
    }

    public function test_por_defecto_el_tema_contrastado_esta_apagado(): void
    {
        $this->assertFalse((bool) PerfilUsuario::actual()->alto_contraste);   // recién creado: aún sin leer el valor por defecto de la BD

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="es">', $html);
        $this->assertStringNotContainsString('dv-contraste"', explode('</head>', $html)[0] . '', 'sin clase en <html> por defecto');
        $this->assertMatchesRegularExpression('/id="perfilAltoContraste"(?![^>]*checked)/', $html, 'el interruptor se ve apagado');
    }

    public function test_se_enciende_desde_el_perfil_y_el_layout_lo_aplica(): void
    {
        $this->put(route('perfil.update'), $this->datosPerfil(['alto_contraste' => '1']))->assertRedirect();

        $this->assertTrue(PerfilUsuario::actual()->alto_contraste);
        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('<html lang="es" class="dv-contraste">', $html);
        $this->assertMatchesRegularExpression('/id="perfilAltoContraste"[^>]*checked/', $html, 'el interruptor se ve encendido');
    }

    public function test_se_vuelve_a_apagar(): void
    {
        $this->put(route('perfil.update'), $this->datosPerfil(['alto_contraste' => '1']));
        // Un checkbox sin marcar manda solo el campo oculto "0"
        $this->put(route('perfil.update'), $this->datosPerfil(['alto_contraste' => '0']))->assertRedirect();

        $this->assertFalse(PerfilUsuario::actual()->alto_contraste);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('class="dv-contraste"', false);
    }

    public function test_editar_el_perfil_sin_mandar_el_campo_lo_deja_apagado(): void
    {
        $this->put(route('perfil.update'), $this->datosPerfil(['alto_contraste' => '1']));
        $this->put(route('perfil.update'), $this->datosPerfil())->assertRedirect();   // formularios viejos / sin el campo

        $this->assertFalse(PerfilUsuario::actual()->alto_contraste);
    }

    public function test_la_hoja_de_contraste_no_aplica_nada_sin_la_clase(): void
    {
        $css = file_get_contents(public_path('css/dv-contraste.css'));
        // Quitamos comentarios y buscamos selectores que NO estén acotados a html.dv-contraste
        $sinComentarios = preg_replace('~/\*.*?\*/~s', '', $css);
        preg_match_all('/(^|\})\s*([^{}@]+)\{/m', $sinComentarios, $m);
        foreach ($m[2] as $selectores) {
            foreach (explode(',', $selectores) as $sel) {
                $sel = trim($sel);
                if ($sel === '') {
                    continue;
                }
                $this->assertStringStartsWith('html.dv-contraste', $sel, "regla sin acotar: {$sel}");
            }
        }
    }
}
