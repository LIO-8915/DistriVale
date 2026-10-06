<?php

namespace Tests\Feature;

use App\Models\DispositivoRemoto;
use App\Support\AccesoRemoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AccesoRemotoTest extends TestCase
{
    use RefreshDatabase;

    private string $control;

    private const IP_REMOTA = '192.168.1.50';

    protected function setUp(): void
    {
        parent::setUp();

        // Imita lo que main.rs le deja a los php-cgi: una carpeta de control
        // con el id de esta ejecución y el estado real del acceso remoto.
        $this->control = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dv-control-'.uniqid();
        mkdir($this->control);
        putenv('DV_CONTROL_DIR='.$this->control);
        file_put_contents($this->control.'/boot_id', 'boot-A');
        $this->estadoRust(activo: true);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        putenv('DV_CONTROL_DIR');
        foreach (glob($this->control.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->control);
        parent::tearDown();
    }

    private function estadoRust(bool $activo): void
    {
        file_put_contents($this->control.'/remoto-estado.json', json_encode([
            'stack' => true, 'activo' => $activo, 'ip' => self::IP_REMOTA, 'puerto' => 8712,
            'url' => 'http://'.self::IP_REMOTA.':8712', 'firewall' => 'ok', 'perfil_red' => 'Private',
        ]));
    }

    private function remoto(): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::IP_REMOTA]);
    }

    // withServerVariables se queda pegado entre peticiones: las de "la PC" lo restablecen a propósito.
    private function pc(): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    }

    /** Pide un código y devuelve el dispositivo pendiente creado. */
    private function pedirCodigo(string $nombre = 'iPad de prueba'): DispositivoRemoto
    {
        $this->remoto()->post(route('remoto.solicitar'), ['nombre' => $nombre])->assertRedirect(route('remoto.acceso'));

        return DispositivoRemoto::latest('id')->firstOrFail();
    }

    /** Empareja un dispositivo completo y devuelve el token de su cookie. */
    private function emparejar(): string
    {
        $d = $this->pedirCodigo();
        $resp = $this->remoto()->post(route('remoto.verificar'), ['codigo' => $d->codigo]);
        $resp->assertRedirect('/');

        return $resp->getCookie(AccesoRemoto::COOKIE)->getValue();
    }

    // --- Quién pasa y quién no ---

    public function test_la_pc_entra_directo_sin_codigo(): void
    {
        $this->get('/clientes')->assertOk();
    }

    public function test_un_equipo_remoto_con_el_acceso_apagado_recibe_403(): void
    {
        $this->estadoRust(activo: false);

        $this->remoto()->get('/clientes')->assertForbidden();
        $this->remoto()->get(route('remoto.acceso'))->assertForbidden();
    }

    public function test_sin_servidor_integrado_un_equipo_remoto_no_pasa_aunque_el_archivo_diga_activo(): void
    {
        putenv('DV_CONTROL_DIR'); // como `php artisan serve` a mano o el camino de respaldo

        $this->remoto()->get('/clientes')->assertForbidden();
    }

    public function test_si_rust_dejo_de_actualizar_el_estado_no_se_confia_en_el(): void
    {
        touch($this->control.'/remoto-estado.json', time() - 120);

        $this->remoto()->get('/clientes')->assertForbidden();
    }

    public function test_un_equipo_remoto_sin_autorizar_va_a_la_pantalla_de_emparejamiento(): void
    {
        $this->remoto()->get('/clientes')->assertRedirect(route('remoto.acceso'));
        $this->remoto()->get('/clientes', ['X-DV-Nav' => '1'])->assertUnauthorized();
    }

    public function test_el_encabezado_x_forwarded_for_no_hace_pasar_por_la_pc(): void
    {
        $this->remoto()->get('/clientes', ['X-Forwarded-For' => '127.0.0.1', 'X-Real-IP' => '127.0.0.1'])
            ->assertRedirect(route('remoto.acceso'));
    }

    // --- Emparejamiento por código ---

    public function test_pedir_codigo_crea_una_solicitud_con_codigo_de_6_digitos_y_avisa_a_la_pc(): void
    {
        $d = $this->pedirCodigo('iPad de Ana');

        $this->assertSame('pendiente', $d->estado);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $d->codigo);
        $this->assertSame('iPad de Ana', $d->nombre);
        $this->assertSame(self::IP_REMOTA, $d->ip);
        $this->assertTrue($d->codigo_expira_en->isFuture());
        $this->assertFileExists($this->control.'/alerta.json');
    }

    public function test_el_codigo_correcto_autoriza_y_entrega_cookie_para_usar_la_app(): void
    {
        $token = $this->emparejar();

        $this->assertSame(64, strlen($token));
        $d = DispositivoRemoto::firstOrFail();
        $this->assertSame('autorizado', $d->estado);
        $this->assertNull($d->codigo);
        $this->assertSame(hash('sha256', $token), $d->token_hash);
        $this->assertNotSame($token, $d->token_hash);

        $this->remoto()->withCookie(AccesoRemoto::COOKIE, $token)->get('/clientes')->assertOk();
    }

    public function test_un_codigo_incorrecto_descuenta_intentos_y_al_quinto_se_invalida(): void
    {
        $d = $this->pedirCodigo();
        $mal = $d->codigo === '000000' ? '111111' : '000000';

        for ($i = 1; $i <= 4; $i++) {
            $this->remoto()->post(route('remoto.verificar'), ['codigo' => $mal])->assertSessionHasErrors('remoto');
            $this->assertSame($i, $d->fresh()->intentos);
            $this->assertNotNull($d->fresh()->codigo, 'el código sigue vivo antes del 5º intento');
        }

        $this->remoto()->post(route('remoto.verificar'), ['codigo' => $mal])->assertSessionHasErrors('remoto');
        $this->assertNull($d->fresh()->codigo, 'al 5º fallo el código queda invalidado');

        // Ni siquiera el código ORIGINAL sirve ya.
        $this->remoto()->post(route('remoto.verificar'), ['codigo' => $d->codigo ?? '123456'])->assertSessionHasErrors('remoto');
        $this->assertSame('pendiente', $d->fresh()->estado);
        $this->assertCount(0, DispositivoRemoto::where('estado', 'autorizado')->get());
    }

    public function test_un_codigo_vencido_no_sirve(): void
    {
        $d = $this->pedirCodigo();
        $d->update(['codigo_expira_en' => now()->subSecond()]);

        $this->remoto()->post(route('remoto.verificar'), ['codigo' => $d->codigo])->assertSessionHasErrors('remoto');
        $this->assertSame('pendiente', $d->fresh()->estado);
    }

    public function test_generar_un_codigo_nuevo_reinicia_los_intentos_y_avisa_otra_vez(): void
    {
        $d = $this->pedirCodigo();
        $this->remoto()->post(route('remoto.verificar'), ['codigo' => $d->codigo === '000000' ? '111111' : '000000']);
        $this->assertSame(1, $d->fresh()->intentos);
        @unlink($this->control.'/alerta.json');

        $this->remoto()->post(route('remoto.regenerar'))->assertRedirect(route('remoto.acceso'));

        $this->assertSame(0, $d->fresh()->intentos);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $d->fresh()->codigo);
        $this->assertFileExists($this->control.'/alerta.json');
        $this->assertCount(1, DispositivoRemoto::all(), 'se regenera la misma solicitud, no se crea otra');
    }

    public function test_el_formato_del_codigo_se_valida(): void
    {
        $this->pedirCodigo();

        foreach (['', '12345', '1234567', 'abcdef'] as $invalido) {
            $this->remoto()->post(route('remoto.verificar'), ['codigo' => $invalido])->assertSessionHasErrors('codigo');
        }
    }

    public function test_demasiados_fallos_desde_una_ip_la_frenan_aunque_pida_codigos_nuevos(): void
    {
        $d = $this->pedirCodigo();
        $mal = $d->codigo === '000000' ? '111111' : '000000';

        // 15 fallos (3 códigos x 5 intentos) agotan el tope por IP...
        for ($i = 0; $i < 15; $i++) {
            if ($i > 0 && $i % 5 === 0) {
                $this->remoto()->post(route('remoto.regenerar'));
            }
            $this->remoto()->post(route('remoto.verificar'), ['codigo' => $mal === ($d->fresh()->codigo ?? '') ? '222222' : $mal]);
        }

        // ...y a partir de ahí ni el código correcto pasa.
        $d->refresh();
        $this->remoto()->post(route('remoto.verificar'), ['codigo' => $d->codigo ?? '123456'])->assertSessionHasErrors('remoto');
        $this->assertCount(0, DispositivoRemoto::where('estado', 'autorizado')->get());
    }

    public function test_pedir_codigo_tiene_tope_por_ip_y_no_inunda_la_pantalla_de_la_pc(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->remoto()->flushSession();
            $this->remoto()->post(route('remoto.solicitar'), ['nombre' => "d{$i}"]);
        }
        $this->assertCount(5, DispositivoRemoto::all());

        $this->remoto()->flushSession();
        $this->remoto()->post(route('remoto.solicitar'), ['nombre' => 'otro'])->assertSessionHasErrors('remoto');
        $this->assertCount(5, DispositivoRemoto::all());
    }

    // --- Duración y revocación ---

    public function test_la_autorizacion_muere_al_reiniciar_la_app(): void
    {
        $token = $this->emparejar();
        $this->remoto()->withCookie(AccesoRemoto::COOKIE, $token)->get('/clientes')->assertOk();

        file_put_contents($this->control.'/boot_id', 'boot-B'); // la app se cerró y se volvió a abrir

        $this->remoto()->withCookie(AccesoRemoto::COOKIE, $token)->get('/clientes')->assertRedirect(route('remoto.acceso'));
    }

    public function test_revocar_un_dispositivo_le_quita_el_acceso_de_inmediato(): void
    {
        $token = $this->emparejar();
        $d = DispositivoRemoto::firstOrFail();

        $this->pc()->post(route('acceso-remoto.revocar', $d))->assertOk(); // desde la PC
        $this->remoto()->withCookie(AccesoRemoto::COOKIE, $token)->get('/clientes')->assertRedirect(route('remoto.acceso'));
    }

    public function test_apagar_el_acceso_remoto_desautoriza_a_todos_y_pide_apagar_a_rust(): void
    {
        $this->emparejar();
        $this->pedirCodigo('otro');

        $this->pc()->postJson(route('acceso-remoto.desactivar'))->assertOk();

        $this->assertCount(0, DispositivoRemoto::whereIn('estado', ['autorizado', 'pendiente'])->get());
        $this->assertFalse(json_decode(file_get_contents($this->control.'/remoto.json'), true)['habilitado']);
    }

    public function test_la_pc_puede_rechazar_una_solicitud(): void
    {
        $d = $this->pedirCodigo();

        $this->pc()->post(route('acceso-remoto.rechazar', $d))->assertOk();

        $this->assertSame('rechazado', $d->fresh()->estado);
        $this->assertNull($d->fresh()->codigo);
        $this->remoto()->post(route('remoto.verificar'), ['codigo' => $d->codigo])->assertSessionHasErrors();
    }

    public function test_activar_pide_a_rust_encender_en_el_puerto_fijo(): void
    {
        $this->pc()->postJson(route('acceso-remoto.activar'))->assertOk();

        $pedido = json_decode(file_get_contents($this->control.'/remoto.json'), true);
        $this->assertTrue($pedido['habilitado']);
        $this->assertSame(8712, $pedido['puerto']);
    }

    // --- Lo que un dispositivo remoto NUNCA debe poder hacer, ni autorizado ---

    public function test_un_dispositivo_autorizado_no_puede_administrar_el_acceso_remoto(): void
    {
        $token = $this->emparejar();
        $r = fn () => $this->remoto()->withCookie(AccesoRemoto::COOKIE, $token)->withCredentials();

        $r()->get(route('acceso-remoto.index'))->assertForbidden();
        $r()->getJson(route('acceso-remoto.estado'))->assertForbidden();
        $r()->getJson(route('acceso-remoto.pendientes'))->assertForbidden(); // aquí viajan los códigos
        $r()->postJson(route('acceso-remoto.activar'))->assertForbidden();
        $r()->postJson(route('acceso-remoto.desactivar'))->assertForbidden();
        $r()->postJson(route('acceso-remoto.firewall'))->assertForbidden();
        $r()->postJson(route('acceso-remoto.rechazar', DispositivoRemoto::firstOrFail()))->assertForbidden();
        $r()->postJson(route('acceso-remoto.revocar', DispositivoRemoto::firstOrFail()))->assertForbidden();
    }

    public function test_un_equipo_sin_autorizar_no_puede_ver_los_codigos_pendientes(): void
    {
        $d = $this->pedirCodigo();

        $resp = $this->remoto()->getJson(route('acceso-remoto.pendientes'));

        $this->assertTrue(in_array($resp->status(), [401, 403], true));
        $this->assertStringNotContainsString($d->codigo, $resp->getContent());
    }

    public function test_la_pc_si_ve_los_codigos_pendientes(): void
    {
        $d = $this->pedirCodigo('iPad de Ana');

        $this->pc()->getJson(route('acceso-remoto.pendientes'))->assertOk()
            ->assertJsonPath('pendientes.0.codigo', $d->codigo)
            ->assertJsonPath('pendientes.0.nombre', 'iPad de Ana');
    }

    public function test_la_pc_se_entera_de_que_el_acceso_se_encendio_sin_recargar_la_ventana(): void
    {
        // La ventana de la PC sondea /pendientes: debe saber si está activo para pasar de modo lento a rápido.
        $this->estadoRust(false);
        $this->pc()->getJson(route('acceso-remoto.pendientes'))->assertOk()
            ->assertJsonPath('activo', false)->assertJsonPath('pendientes', []);

        $this->estadoRust(true);
        $d = $this->pedirCodigo();
        $this->pc()->getJson(route('acceso-remoto.pendientes'))->assertOk()
            ->assertJsonPath('activo', true)
            ->assertJsonPath('pendientes.0.codigo', $d->codigo);
    }

    public function test_el_modal_de_codigos_se_carga_con_version_para_que_no_quede_una_copia_vieja_en_cache(): void
    {
        $this->pc()->get('/')->assertOk()
            ->assertSee('js/dv-remoto.js?v=', false)
            ->assertSee('id="modalSolicitudRemota"', false);
    }

    public function test_desde_un_dispositivo_remoto_solo_se_puede_respaldar_a_drive(): void
    {
        $token = $this->emparejar();
        $r = fn () => $this->remoto()->withCookie(AccesoRemoto::COOKIE, $token);

        $r()->post(route('drive.restore'))->assertForbidden();
        $r()->post(route('drive.rollback'))->assertForbidden();
        $r()->post(route('drive.connect'))->assertForbidden();
        $r()->post(route('drive.disconnect'))->assertForbidden();
        // "Respaldar ahora" NO está restringido (solo sube una copia): sin cuenta conectada responde con su propio aviso, no con 403.
        $this->assertNotSame(403, $r()->post(route('drive.backup'))->status());
    }

    public function test_el_menu_de_acceso_remoto_y_el_modal_de_codigos_no_se_ofrecen_a_dispositivos_remotos(): void
    {
        $token = $this->emparejar();

        $remota = $this->remoto()->withCookie(AccesoRemoto::COOKIE, $token)->get('/clientes')->assertOk();
        $remota->assertDontSee('modalSolicitudRemota');
        $remota->assertDontSee(route('acceso-remoto.index'));
        $remota->assertSee('Desconectar');

        $local = $this->pc()->get('/clientes')->assertOk();
        $local->assertSee('modalSolicitudRemota');
        $local->assertSee(route('acceso-remoto.index'));
    }

    public function test_un_nombre_malicioso_no_se_cuela_como_html_en_la_pantalla_de_la_pc(): void
    {
        $this->pedirCodigo('<script>alert(1)</script>');

        // La pantalla de administración incrusta los datos como JSON con las etiquetas escapadas.
        $html = $this->pc()->get(route('acceso-remoto.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_desconectar_desde_el_propio_dispositivo_revoca_su_acceso(): void
    {
        $token = $this->emparejar();

        $this->remoto()->withCookie(AccesoRemoto::COOKIE, $token)->post(route('remoto.salir'))
            ->assertRedirect(route('remoto.acceso'));

        $this->assertSame('revocado', DispositivoRemoto::firstOrFail()->estado);
        $this->remoto()->withCookie(AccesoRemoto::COOKIE, $token)->get('/clientes')->assertRedirect(route('remoto.acceso'));
    }
}
