<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\SesionActiva;
use App\Support\SesionesUsuario;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NP-36 paso 4: sesiones activas y "Cerrar sesión en los demás dispositivos".
 *
 * Con el driver database (el de .env.example), la sesión del test también se
 * guarda en la tabla: se hace una primera visita para conocer su ID y luego se
 * envía esa cookie, como haría el navegador.
 */
class PerfilSesionesTest extends TestCase
{
    use RefreshDatabase;

    /** Contraseña de UserFactory. */
    private const CLAVE = 'password';

    private const UA_CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private const UA_FIREFOX_LINUX = 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0';

    private ?string $idSesion = null;

    /**
     * El driver database va ANTES de crear usuarios: al crear uno, Auditable llama
     * a auth()->id(), que construye el guard y fija el singleton 'session.store'.
     * Si en ese momento el driver fuera 'array' (el de phpunit.xml), la redirección
     * guardaría los mensajes flash en ese store y no en la tabla de sesiones.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    /** Para los tests que comprueban qué pasa con otro driver (llamar antes de crear usuarios). */
    private function usarDriverArray(): void
    {
        config(['session.driver' => 'array']);
    }

    /** Hace cumplir el CSRF en los tests (Laravel lo omite por defecto). */
    private function activarCsrfReal(): void
    {
        $this->app->singleton(ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            };
        });
    }

    private function insertarSesion(string $id, ?int $userId, ?string $agente = self::UA_FIREFOX_LINUX, int $haceMinutos = 30, string $ip = '203.0.113.7'): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => $ip,
            'user_agent' => $agente,
            'payload' => 'PAYLOAD-SECRETO-'.$id,
            'last_activity' => now()->subMinutes($haceMinutos)->timestamp,
        ]);
    }

    private function idDeSesionDe(TestResponse $respuesta): string
    {
        $cookie = $respuesta->getCookie(config('session.cookie'), false);
        $this->assertNotNull($cookie, 'La respuesta no trae la cookie de sesión.');

        return CookieValuePrefix::remove(decrypt($cookie->getValue(), false));
    }

    /**
     * Primera visita: con el driver database la sesión actual queda guardada en la tabla.
     */
    private function iniciarSesionEnBase(User $usuario): string
    {
        $respuesta = $this->actingAs($usuario)
            ->withHeader('User-Agent', self::UA_CHROME_WINDOWS)
            ->get(route('perfil.seguridad'))
            ->assertOk();

        $this->idSesion = $this->idDeSesionDe($respuesta);
        $this->assertDatabaseHas('sessions', ['id' => $this->idSesion, 'user_id' => $usuario->id]);

        return $this->idSesion;
    }

    /** Petición con la cookie de la sesión actual. */
    private function conSesion(User $usuario): static
    {
        return $this->actingAs($usuario)
            ->withHeader('User-Agent', self::UA_CHROME_WINDOWS)
            ->withCookie(config('session.cookie'), $this->idSesion);
    }

    private function cerrar(User $usuario, string $clave = self::CLAVE): TestResponse
    {
        return $this->conSesion($usuario)
            ->from(route('perfil.seguridad'))
            ->delete(route('perfil.sesiones'), ['current_password' => $clave]);
    }

    // ----- Acceso -----

    public function test_un_visitante_va_al_login(): void
    {
        $this->delete(route('perfil.sesiones'), ['current_password' => self::CLAVE])
            ->assertRedirect(route('login'));
    }

    // ----- Lista -----

    public function test_lista_solo_las_sesiones_del_usuario_y_marca_la_actual(): void
    {
        $usuario = User::factory()->lector()->create();
        $otro = User::factory()->create();
        $actual = $this->iniciarSesionEnBase($usuario);
        $this->insertarSesion('sesion-propia-firefox', $usuario->id, self::UA_FIREFOX_LINUX, 30, '198.51.100.20');
        $this->insertarSesion('sesion-del-otro', $otro->id, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1', 5, '192.0.2.99');
        $this->insertarSesion('sesion-de-visitante', null, self::UA_FIREFOX_LINUX, 1, '192.0.2.100');

        $html = $this->conSesion($usuario)->get(route('perfil.seguridad'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<ul class="dispositivos-lista">(.*?)</ul>#s', $html, $lista));
        $this->assertSame(2, substr_count($lista[1], '<li class="dispositivo">'));

        // La actual primero (actividad más reciente), con su chip y sin "hace X".
        $this->assertMatchesRegularExpression('#<span>Chrome en Windows</span>\s*<span class="chip">Esta sesión</span>#', $lista[1]);
        $this->assertStringContainsString('Activa ahora', $lista[1]);
        $this->assertSame(1, substr_count($lista[1], 'Esta sesión'));

        // La otra sesión propia, con IP y "hace 30 minutos" en un <time>.
        $this->assertStringContainsString('<span>Firefox en Linux</span>', $lista[1]);
        $this->assertStringContainsString('IP 198.51.100.20', $lista[1]);
        $this->assertMatchesRegularExpression('#Última actividad: <time datetime="[^"]+" title="\d{2}/\d{2}/\d{4} \d{2}:\d{2} [A-Z]+">hace 30 minutos</time>#', $lista[1]);

        // Nada de otros usuarios ni de visitantes.
        $this->assertStringNotContainsString('iOS', $lista[1]);
        $this->assertStringNotContainsString('192.0.2.99', $html);
        $this->assertStringNotContainsString('192.0.2.100', $html);

        // Nunca ids de sesión ni payloads.
        foreach ([$actual, 'sesion-propia-firefox', 'sesion-del-otro', 'sesion-de-visitante', 'PAYLOAD-SECRETO'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $html);
        }
    }

    public function test_un_user_agent_malicioso_no_aparece_crudo(): void
    {
        $usuario = User::factory()->lector()->create();
        $this->iniciarSesionEnBase($usuario);
        $this->insertarSesion('sesion-rara', $usuario->id, 'Mozilla/5.0 "><script>alert(1)</script> \'onerror=x Chrome/120 (Windows NT 10.0)');
        $this->insertarSesion('sesion-sin-agente', $usuario->id, null);

        $html = $this->conSesion($usuario)->get(route('perfil.seguridad'))->assertOk()->getContent();

        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('Mozilla/5.0', $html);
        $this->assertStringContainsString('<span>Chrome en Windows</span>', $html);
        $this->assertStringContainsString('<span>Navegador desconocido</span>', $html);
    }

    public function test_sin_otras_sesiones_no_hay_formulario(): void
    {
        $usuario = User::factory()->lector()->create();
        $this->iniciarSesionEnBase($usuario);

        $html = $this->conSesion($usuario)->get(route('perfil.seguridad'))->assertOk()->getContent();

        $this->assertStringContainsString('No tienes otras sesiones abiertas.', $html);
        $this->assertStringContainsString('Esta sesión', $html);
        $this->assertStringNotContainsString('action="'.route('perfil.sesiones').'"', $html);
        $this->assertStringNotContainsString('Cerrar sesión en los demás dispositivos', $html);
    }

    public function test_con_otro_driver_lo_dice_y_no_hay_formulario(): void
    {
        $this->usarDriverArray();
        $usuario = User::factory()->lector()->create();
        $this->insertarSesion('sesion-guardada', $usuario->id);

        $html = $this->actingAs($usuario)->get(route('perfil.seguridad'))->assertOk()->getContent();

        $this->assertStringContainsString('No se pueden mostrar las sesiones con la configuración actual.', $html);
        $this->assertStringNotContainsString('dispositivos-lista', $html);
        $this->assertStringNotContainsString('action="'.route('perfil.sesiones').'"', $html);
        $this->assertStringNotContainsString('No tienes otras sesiones abiertas.', $html);
    }

    public function test_el_formulario_tiene_ids_propios_y_sin_value(): void
    {
        $usuario = User::factory()->lector()->create();
        $this->iniciarSesionEnBase($usuario);
        $this->insertarSesion('otra', $usuario->id);

        $html = $this->conSesion($usuario)->get(route('perfil.seguridad'))->getContent();

        $this->assertSame(1, preg_match('#<form method="POST" action="'.preg_quote(route('perfil.sesiones'), '#').'"[^>]*>(.*?)</form>#s', $html, $form));
        $this->assertStringContainsString('name="_method" value="DELETE"', $form[1]);
        $this->assertStringContainsString('<label for="sesiones_current_password">Contraseña actual</label>', $form[1]);
        $this->assertSame(1, preg_match('#<input id="sesiones_current_password"[^>]*>#', $form[1], $input));
        $this->assertStringContainsString('name="current_password"', $input[0]);
        $this->assertStringContainsString('autocomplete="current-password"', $input[0]);
        $this->assertStringNotContainsString('value=', $input[0]);
        $this->assertStringNotContainsString('data-confirm', $form[1]);
        $this->assertStringContainsString('Cerrar sesión en los demás dispositivos</button>', $form[1]);

        // Cada id aparece una sola vez en la página (hay dos campos current_password).
        $this->assertSame(1, substr_count($html, 'id="current_password"'));
        $this->assertSame(1, substr_count($html, 'id="sesiones_current_password"'));
    }

    // ----- Contraseña incorrecta y bolsas de errores -----

    public function test_contrasena_incorrecta_no_cierra_nada_y_el_error_va_en_su_bolsa(): void
    {
        $usuario = User::factory()->lector()->create();
        $actual = $this->iniciarSesionEnBase($usuario);
        $this->insertarSesion('otra-1', $usuario->id);
        $auditoria = AuditLog::count();

        $this->cerrar($usuario, 'NoEsEsta#2026')
            ->assertRedirect(route('perfil.seguridad'))
            ->assertSessionHasErrorsIn('sesiones', ['current_password' => 'La contraseña actual no es correcta.'])
            ->assertSessionDoesntHaveErrors('current_password');

        $this->assertDatabaseHas('sessions', ['id' => 'otra-1']);
        $this->assertDatabaseHas('sessions', ['id' => $actual]);
        $this->assertSame($auditoria, AuditLog::count());

        // El error se ve en el formulario de sesiones y NO en el de cambiar contraseña.
        $html = $this->conSesion($usuario)->get(route('perfil.seguridad'))->getContent();
        $this->assertStringContainsString('<p id="error-sesiones_current_password" class="error">La contraseña actual no es correcta.</p>', $html);
        $this->assertMatchesRegularExpression('#<input id="sesiones_current_password"[^>]*aria-invalid="true"#', $html);
        $this->assertStringNotContainsString('id="error-current_password"', $html);
        $this->assertDoesNotMatchRegularExpression('#<input id="current_password"[^>]*aria-invalid#', $html);
    }

    public function test_los_errores_del_cambio_de_contrasena_no_aparecen_en_sesiones(): void
    {
        $usuario = User::factory()->lector()->create();
        $this->iniciarSesionEnBase($usuario);
        $this->insertarSesion('otra-1', $usuario->id);

        $this->conSesion($usuario)
            ->from(route('perfil.seguridad'))
            ->put(route('perfil.contrasena'), [
                'current_password' => 'NoEsEsta#2026',
                'password' => 'NuevaClave#Segura2026',
                'password_confirmation' => 'NuevaClave#Segura2026',
            ])
            ->assertSessionHasErrors('current_password');

        $html = $this->conSesion($usuario)->get(route('perfil.seguridad'))->getContent();
        $this->assertStringContainsString('<p id="error-current_password" class="error">La contraseña actual no es correcta.</p>', $html);
        $this->assertStringNotContainsString('id="error-sesiones_current_password"', $html);
        $this->assertDoesNotMatchRegularExpression('#<input id="sesiones_current_password"[^>]*aria-invalid#', $html);
    }

    // ----- Éxito -----

    public static function otrasSesiones(): array
    {
        return [
            'una' => [1, 'Cerramos tu sesión en 1 dispositivo.'],
            'varias' => [3, 'Cerramos tu sesión en 3 dispositivos.'],
        ];
    }

    #[DataProvider('otrasSesiones')]
    public function test_cierra_las_demas_y_conserva_la_actual_y_las_de_otros(int $cantidad, string $mensaje): void
    {
        $usuario = User::factory()->lector()->create();
        $otro = User::factory()->create();
        $idAntes = $this->iniciarSesionEnBase($usuario);
        for ($i = 1; $i <= $cantidad; $i++) {
            $this->insertarSesion("otra-{$i}", $usuario->id);
        }
        $this->insertarSesion('sesion-del-otro', $otro->id);
        $ultimoAudit = AuditLog::max('id') ?? 0;

        $respuesta = $this->cerrar($usuario)
            ->assertRedirect(route('perfil.seguridad'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', $mensaje);

        // ID nuevo; la sesión anterior y las otras del usuario ya no existen.
        $idNuevo = $this->idDeSesionDe($respuesta);
        $this->assertNotSame($idAntes, $idNuevo);
        $this->assertSame([$idNuevo], DB::table('sessions')->where('user_id', $usuario->id)->pluck('id')->all());
        $this->assertDatabaseHas('sessions', ['id' => 'sesion-del-otro', 'user_id' => $otro->id]);
        $this->assertAuthenticatedAs($usuario);

        // Exactamente una fila de auditoría, sin valores.
        $nuevas = AuditLog::where('id', '>', $ultimoAudit)->get();
        $this->assertCount(1, $nuevas);
        $this->assertSame('cerrar_sesiones', $nuevas->first()->action);
        $this->assertSame($usuario->id, $nuevas->first()->auditable_id);
        $this->assertSame($usuario->id, $nuevas->first()->user_id);
        $this->assertNull($nuevas->first()->old_values);
        $this->assertNull($nuevas->first()->new_values);

        // La página sigue funcionando con la sesión nueva y ya no ofrece el formulario.
        $this->idSesion = $idNuevo;
        $this->conSesion($usuario)->get(route('perfil.seguridad'))
            ->assertOk()
            ->assertSee('No tienes otras sesiones abiertas.')
            ->assertSee($mensaje);
    }

    public function test_sin_otras_sesiones_responde_que_no_habia(): void
    {
        $usuario = User::factory()->lector()->create();
        $this->iniciarSesionEnBase($usuario);

        $this->cerrar($usuario)
            ->assertRedirect(route('perfil.seguridad'))
            ->assertSessionHas('status', 'No había otras sesiones abiertas.');

        $this->assertSame(1, AuditLog::where('action', 'cerrar_sesiones')->count());
    }

    // ----- Seguridad de la petición -----

    public function test_sin_token_csrf_responde_419(): void
    {
        $this->activarCsrfReal();
        $usuario = User::factory()->lector()->create();
        $this->insertarSesion('otra-1', $usuario->id);

        $this->actingAs($usuario)
            ->delete(route('perfil.sesiones'), ['current_password' => self::CLAVE])
            ->assertStatus(419);

        $this->assertDatabaseHas('sessions', ['id' => 'otra-1']);
    }

    public function test_la_peticion_6_en_un_minuto_responde_429(): void
    {
        $usuario = User::factory()->lector()->create();

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($usuario)
                ->delete(route('perfil.sesiones'), ['current_password' => 'Adivinando#2026'])
                ->assertSessionHasErrorsIn('sesiones', 'current_password');
        }

        $this->actingAs($usuario)
            ->delete(route('perfil.sesiones'), ['current_password' => 'Adivinando#2026'])
            ->assertStatus(429);
    }

    // ----- Auditoría -----

    public function test_la_auditoria_muestra_y_filtra_cerrar_sesiones(): void
    {
        $usuario = User::factory()->lector()->create();
        $this->actingAs($usuario)->delete(route('perfil.sesiones'), ['current_password' => self::CLAVE]);
        $this->actingAs($usuario)->patch(route('perfil.nombre'), ['name' => 'Otro Nombre']);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->get(route('auditoria.index', ['accion' => 'cerrar_sesiones']))
            ->assertOk()
            ->assertViewHas('registros', fn ($registros) => $registros->total() === 1
                && $registros->first()->action === 'cerrar_sesiones'
                && $registros->first()->auditable_id === $usuario->id)
            ->assertSee('<option value="cerrar_sesiones" selected>Cerró otras sesiones</option>', false)
            ->assertSee('<th scope="row" role="rowheader" class="auditoria-accion">Cerró otras sesiones</th>', false);
    }

    // ----- SesionesUsuario -----

    public function test_listar_devuelve_solo_datos_seguros_del_usuario(): void
    {
        $usuario = User::factory()->create();
        $otro = User::factory()->create();
        $this->insertarSesion('reciente', $usuario->id, self::UA_CHROME_WINDOWS, 1, '198.51.100.1');
        $this->insertarSesion('antigua', $usuario->id, self::UA_FIREFOX_LINUX, 90, '198.51.100.2');
        $this->insertarSesion('ajena', $otro->id);

        $sesiones = SesionesUsuario::listar($usuario, 'antigua');

        $this->assertCount(2, $sesiones);
        $this->assertContainsOnlyInstancesOf(SesionActiva::class, $sesiones);
        $this->assertSame(['Chrome en Windows', 'Firefox en Linux'], $sesiones->map->descripcion()->all());
        $this->assertSame([false, true], $sesiones->pluck('esActual')->all());
        $this->assertSame(['198.51.100.1', '198.51.100.2'], $sesiones->pluck('ip')->all());
        $this->assertInstanceOf(Carbon::class, $sesiones->first()->ultimaActividad);
        $this->assertSame(now()->subMinutes(1)->timestamp, $sesiones->first()->ultimaActividad->timestamp);

        foreach (['id', 'payload', 'user_agent', 'user_id'] as $prohibido) {
            $this->assertFalse(property_exists(SesionActiva::class, $prohibido), "SesionActiva no debe tener {$prohibido}.");
        }
    }

    public function test_listar_con_otro_driver_devuelve_vacio(): void
    {
        $this->usarDriverArray();
        $usuario = User::factory()->create();
        $this->insertarSesion('guardada', $usuario->id);

        $this->assertTrue(SesionesUsuario::listar($usuario, 'guardada')->isEmpty());
        $this->assertFalse(SesionesUsuario::disponible());
    }

    public static function agentes(): array
    {
        return [
            'Edge en Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0', 'Edge', 'Windows'],
            'Chrome en Windows' => [self::UA_CHROME_WINDOWS, 'Chrome', 'Windows'],
            'Chrome en macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', 'Chrome', 'macOS'],
            'Firefox en Linux' => [self::UA_FIREFOX_LINUX, 'Firefox', 'Linux'],
            'Safari en iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1', 'Safari', 'iOS'],
            'Safari en macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15', 'Safari', 'macOS'],
            'Chrome en iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0.6099.119 Mobile/15E148 Safari/604.1', 'Chrome', 'iOS'],
            'Chrome en Android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36', 'Chrome', 'Android'],
            'Firefox en Android' => ['Mozilla/5.0 (Android 14; Mobile; rv:121.0) Gecko/121.0 Firefox/121.0', 'Firefox', 'Android'],
            'Opera en Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OPR/106.0.0.0', 'Opera', 'Windows'],
            'herramienta desconocida' => ['curl/8.4.0', 'Otro navegador', 'Otro sistema'],
            'null' => [null, 'Navegador desconocido', null],
            'vacío' => ['   ', 'Navegador desconocido', null],
        ];
    }

    #[DataProvider('agentes')]
    public function test_interpreta_el_user_agent(?string $agente, string $navegador, ?string $sistema): void
    {
        $this->assertSame([$navegador, $sistema], SesionesUsuario::interpretarAgente($agente));
    }

    public function test_la_descripcion_omite_el_sistema_desconocido(): void
    {
        $this->assertSame('Navegador desconocido', (new SesionActiva('Navegador desconocido', null, null, now(), false))->descripcion());
        $this->assertSame('Edge en Windows', (new SesionActiva('Edge', 'Windows', null, now(), false))->descripcion());
    }

    // ----- Calidad de la página -----

    public function test_la_vista_no_tiene_codigo_en_linea(): void
    {
        $usuario = User::factory()->lector()->create();
        $this->iniciarSesionEnBase($usuario);
        $this->insertarSesion('otra', $usuario->id);

        $html = $this->conSesion($usuario)->get(route('perfil.seguridad'))->getContent();

        $this->assertStringContainsString('dispositivos-lista', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertDoesNotMatchRegularExpression('#\son[a-z]+=#i', $html);
        $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#i', $html);
    }
}
