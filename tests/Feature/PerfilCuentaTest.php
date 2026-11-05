<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\SesionesUsuario;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * NP-36 paso 6: pestaña "Cuenta" con "Desactivar mi cuenta".
 */
class PerfilCuentaTest extends TestCase
{
    use RefreshDatabase;

    /** Contraseña de UserFactory. */
    private const CLAVE = 'password';

    private const MENSAJE_EXITO = 'Tu cuenta fue desactivada. Si quieres reactivarla, escríbenos por Contacto.';

    private const MENSAJE_UNICO_ADMIN = 'Eres el único administrador activo. Asigna otro administrador antes de desactivar tu cuenta.';

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

    private function pagina(User $usuario): string
    {
        return $this->actingAs($usuario)->get(route('perfil.cuenta'))->assertOk()->getContent();
    }

    private function desactivar(User $usuario, array $datos = ['current_password' => self::CLAVE]): TestResponse
    {
        return $this->actingAs($usuario)
            ->from(route('perfil.cuenta'))
            ->post(route('perfil.desactivar'), $datos);
    }

    private function insertarSesion(string $id, ?int $userId): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'payload-'.$id,
            'last_activity' => now()->timestamp,
        ]);
    }

    // ----- Acceso y pestañas -----

    public function test_un_visitante_va_al_login(): void
    {
        $this->get(route('perfil.cuenta'))->assertRedirect(route('login'));
        $this->post(route('perfil.desactivar'), ['current_password' => self::CLAVE])->assertRedirect(route('login'));
    }

    public function test_cuatro_pestanas_en_orden_con_cuenta_activa(): void
    {
        $html = $this->pagina(User::factory()->lector()->create());

        $this->assertStringContainsString('<p class="pagina-etiqueta">MI CUENTA</p>', $html);
        $this->assertSame(1, preg_match('#<nav class="pestanas" aria-label="Secciones de mi perfil">(.*?)</nav>#s', $html, $nav));
        preg_match_all('#<a href="([^"]*)"[^>]*>([^<]*)</a>#', $nav[1], $enlaces);
        $this->assertSame(['Perfil', 'Seguridad', 'Actividad', 'Cuenta'], $enlaces[2]);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('perfil.cuenta'), '#').'" class="pestana pestana-activa"\s+aria-current="page"\s*>Cuenta</a>#', $nav[1]);
        $this->assertSame(1, substr_count($nav[1], 'aria-current'));
    }

    // ----- Vista -----

    public function test_lector_editor_y_admin_con_otro_admin_ven_el_formulario(): void
    {
        User::factory()->admin()->create(); // otro admin activo

        foreach ([User::factory()->lector()->create(), User::factory()->editor()->create(), User::factory()->admin()->create()] as $usuario) {
            $html = $this->pagina($usuario);

            $this->assertSame(1, preg_match('#<form method="POST" action="'.preg_quote(route('perfil.desactivar'), '#').'"[^>]*>(.*?)</form>#s', $html, $form));
            $this->assertStringContainsString('name="_token"', $form[1]);
            $this->assertStringContainsString('<label for="desactivar_current_password">Contraseña actual</label>', $form[1]);
            $this->assertSame(1, preg_match('#<input id="desactivar_current_password"[^>]*>#', $form[1], $input));
            $this->assertStringContainsString('name="current_password"', $input[0]);
            $this->assertStringContainsString('autocomplete="current-password"', $input[0]);
            $this->assertStringNotContainsString('value=', $input[0]);
            $this->assertStringContainsString(
                '<button type="submit" class="boton boton-peligro" data-confirm="¿Seguro que quieres desactivar tu cuenta? Se cerrarán todas tus sesiones.">Desactivar mi cuenta</button>',
                $form[1]
            );
            $this->assertStringNotContainsString('Eres el único administrador activo', $html);
        }
    }

    public function test_los_textos_son_honestos_y_enlazan_a_contacto_y_privacidad(): void
    {
        $html = $this->pagina(User::factory()->lector()->create());

        $this->assertStringContainsString('<section class="perfil-tarjeta perfil-peligro"', $html);
        $this->assertStringContainsString('<h2 id="perfil-desactivar-titulo" class="perfil-tarjeta-titulo">Desactivar mi cuenta</h2>', $html);
        $this->assertStringContainsString('No podrás iniciar sesión y se cerrarán todas tus sesiones.', $html);
        $this->assertStringContainsString('Tu cuenta no se borra: tus comentarios y noticias siguen visibles con tu nombre.', $html);
        $this->assertStringContainsString('Para reactivarla o solicitar la supresión de tus datos, escríbenos por <a href="'.route('contact').'">Contacto</a>.', $html);
        $this->assertStringContainsString('<a href="'.route('legal.privacidad').'">Política de privacidad</a>', $html);
    }

    // ----- Único admin activo -----

    public function test_el_unico_admin_activo_ve_el_aviso_y_no_puede_desactivarse(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->pagina($admin);
        $this->assertStringContainsString('Eres el único administrador activo, así que no puedes desactivar tu cuenta.', $html);
        $this->assertStringNotContainsString('action="'.route('perfil.desactivar').'"', $html);
        $this->assertStringNotContainsString('data-confirm', $html);

        // Aunque envíe el POST a mano.
        $ultimoAudit = AuditLog::max('id') ?? 0;
        $this->desactivar($admin)
            ->assertRedirect(route('perfil.cuenta'))
            ->assertSessionHasErrorsIn('desactivar', ['current_password' => self::MENSAJE_UNICO_ADMIN]);

        $this->assertTrue($admin->fresh()->is_active);
        $this->assertSame(0, AuditLog::where('id', '>', $ultimoAudit)->count());
        $this->assertAuthenticatedAs($admin);

        // El error se ve junto al aviso.
        $this->actingAs($admin)->get(route('perfil.cuenta'))
            ->assertSee('<p class="error" role="alert">'.e(self::MENSAJE_UNICO_ADMIN).'</p>', false);
    }

    public function test_un_admin_inactivo_no_cuenta_como_otro_admin_activo(): void
    {
        User::factory()->admin()->inactivo()->create();
        $admin = User::factory()->admin()->create();

        $this->assertStringContainsString('Eres el único administrador activo', $this->pagina($admin));

        $this->desactivar($admin)->assertSessionHasErrorsIn('desactivar', ['current_password' => self::MENSAJE_UNICO_ADMIN]);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_un_admin_con_otro_admin_activo_si_puede_desactivarse(): void
    {
        $otro = User::factory()->admin()->create();
        $admin = User::factory()->admin()->create();

        $this->desactivar($admin)->assertRedirect(route('login'));

        $this->assertFalse($admin->fresh()->is_active);
        $this->assertTrue($otro->fresh()->is_active);
        $this->assertGuest();

        // Ahora el otro es el único admin activo: no puede desactivarse.
        $this->desactivar($otro)->assertSessionHasErrorsIn('desactivar', ['current_password' => self::MENSAJE_UNICO_ADMIN]);
        $this->assertTrue($otro->fresh()->is_active);
    }

    // ----- Contraseña incorrecta -----

    public function test_contrasena_incorrecta_da_error_en_su_bolsa_y_no_cambia_nada(): void
    {
        $usuario = User::factory()->lector()->create();
        $ultimoAudit = AuditLog::max('id') ?? 0;

        $this->desactivar($usuario, ['current_password' => 'NoEsEsta#2026'])
            ->assertRedirect(route('perfil.cuenta'))
            ->assertSessionHasErrorsIn('desactivar', ['current_password' => 'La contraseña actual no es correcta.'])
            ->assertSessionDoesntHaveErrors('current_password');

        $this->assertTrue($usuario->fresh()->is_active);
        $this->assertSame(0, AuditLog::where('id', '>', $ultimoAudit)->count());
        $this->assertAuthenticatedAs($usuario);

        $html = $this->pagina($usuario);
        $this->assertStringContainsString('<p id="error-desactivar_current_password" class="error">La contraseña actual no es correcta.</p>', $html);
        $this->assertMatchesRegularExpression('#<input id="desactivar_current_password"[^>]*aria-invalid="true"#', $html);
    }

    // ----- Éxito -----

    public function test_desactiva_audita_cierra_la_sesion_y_cambia_el_token(): void
    {
        $usuario = User::factory()->lector()->create();
        $this->actingAs($usuario)->get(route('perfil.cuenta'))->assertOk();
        $tokenAntes = $this->app['session.store']->token();
        $ultimoAudit = AuditLog::max('id') ?? 0;

        $this->desactivar($usuario)
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', self::MENSAJE_EXITO);

        $this->assertFalse($usuario->fresh()->is_active);
        $this->assertGuest();
        $this->assertNotSame($tokenAntes, $this->app['session.store']->token());

        // Una sola fila: 'desactivar', hecha por el propio usuario, con antes/después.
        $nuevas = AuditLog::where('id', '>', $ultimoAudit)->get();
        $this->assertCount(1, $nuevas);
        $registro = $nuevas->first();
        $this->assertSame('desactivar', $registro->action);
        $this->assertSame($usuario->id, $registro->user_id);
        $this->assertSame($usuario->id, $registro->auditable_id);
        $this->assertSame(['is_active'], array_keys($registro->old_values));
        $this->assertTrue((bool) $registro->old_values['is_active']);
        $this->assertFalse((bool) $registro->new_values['is_active']);

        // El login muestra el mensaje.
        $this->get(route('login'))->assertOk()->assertSee(self::MENSAJE_EXITO);
    }

    public function test_borra_todas_las_sesiones_del_usuario_y_conserva_las_de_otros(): void
    {
        // Driver database ANTES de crear usuarios (ver PerfilSesionesTest::setUp).
        config(['session.driver' => 'database']);
        $usuario = User::factory()->lector()->create();
        $otro = User::factory()->create();

        $respuesta = $this->actingAs($usuario)->get(route('perfil.cuenta'))->assertOk();
        $idActual = CookieValuePrefix::remove(decrypt($respuesta->getCookie(config('session.cookie'), false)->getValue(), false));
        $this->insertarSesion('del-usuario-1', $usuario->id);
        $this->insertarSesion('del-usuario-2', $usuario->id);
        $this->insertarSesion('del-otro', $otro->id);
        $this->assertSame(3, DB::table('sessions')->where('user_id', $usuario->id)->count());

        $this->actingAs($usuario)
            ->withCookie(config('session.cookie'), $idActual)
            ->post(route('perfil.desactivar'), ['current_password' => self::CLAVE])
            ->assertRedirect(route('login'));

        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->id)->count());
        $this->assertDatabaseMissing('sessions', ['id' => $idActual]);
        $this->assertDatabaseHas('sessions', ['id' => 'del-otro', 'user_id' => $otro->id]);
        $this->assertGuest();
    }

    public function test_cerrar_todas_devuelve_cuantas_cerro(): void
    {
        $usuario = User::factory()->create();
        $otro = User::factory()->create();
        $this->insertarSesion('a', $usuario->id);
        $this->insertarSesion('b', $usuario->id);
        $this->insertarSesion('c', $otro->id);

        $this->assertSame(2, SesionesUsuario::cerrarTodas($usuario));
        $this->assertSame(0, SesionesUsuario::cerrarTodas($usuario));
        $this->assertDatabaseHas('sessions', ['id' => 'c']);
    }

    public function test_despues_no_puede_iniciar_sesion_y_recibe_el_error_generico(): void
    {
        $usuario = User::factory()->lector()->create();
        $this->desactivar($usuario)->assertRedirect(route('login'));

        $conSuClave = $this->from(route('login'))
            ->post(route('login.store'), ['email' => $usuario->email, 'password' => self::CLAVE]);
        $conClaveMala = $this->from(route('login'))
            ->post(route('login.store'), ['email' => $usuario->email, 'password' => 'Incorrecta#2026']);

        $conSuClave->assertRedirect(route('login'))->assertSessionHasErrors(['email' => 'Las credenciales no son válidas.']);
        $conClaveMala->assertSessionHasErrors(['email' => 'Las credenciales no son válidas.']);
        $this->assertGuest();
    }

    public function test_los_demas_campos_enviados_no_tienen_efecto(): void
    {
        $usuario = User::factory()->lector()->create(['name' => 'Nombre Fijo']);
        $antes = $usuario->fresh();

        $this->desactivar($usuario, [
            'current_password' => self::CLAVE,
            'is_active' => '1',
            'role_id' => Role::where('name', 'admin')->value('id'),
            'name' => 'Otro Nombre',
            'email' => 'otro@example.com',
            'legal_version' => 'falsa',
        ])->assertRedirect(route('login'));

        $despues = $usuario->fresh();
        $this->assertFalse($despues->is_active);
        $this->assertSame($antes->role_id, $despues->role_id);
        $this->assertSame('Nombre Fijo', $despues->name);
        $this->assertSame($antes->email, $despues->email);
        $this->assertSame($antes->legal_version, $despues->legal_version);
        $this->assertSame($antes->password, $despues->password);
        // Los usuarios nunca se borran.
        $this->assertDatabaseHas('users', ['id' => $usuario->id]);
    }

    public function test_un_admin_lo_reactiva_y_vuelve_a_entrar(): void
    {
        $admin = User::factory()->admin()->create();
        $usuario = User::factory()->lector()->create();
        $this->desactivar($usuario)->assertRedirect(route('login'));

        $this->actingAs($admin)
            ->patch(route('usuarios.estado', $usuario), ['is_active' => 1])
            ->assertSessionHas('status', 'Usuario activado.');
        $this->assertTrue($usuario->fresh()->is_active);

        $this->post(route('logout'));
        $this->assertGuest();

        $this->post(route('login.store'), ['email' => $usuario->email, 'password' => self::CLAVE])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($usuario);
    }

    // ----- Seguridad de la petición -----

    public function test_sin_token_csrf_responde_419(): void
    {
        $this->activarCsrfReal();
        $usuario = User::factory()->lector()->create();

        $this->actingAs($usuario)
            ->post(route('perfil.desactivar'), ['current_password' => self::CLAVE])
            ->assertStatus(419);

        $this->assertTrue($usuario->fresh()->is_active);
    }

    public function test_la_peticion_6_en_un_minuto_responde_429(): void
    {
        $usuario = User::factory()->lector()->create();

        for ($i = 1; $i <= 5; $i++) {
            $this->desactivar($usuario, ['current_password' => 'Adivinando#2026'])
                ->assertSessionHasErrorsIn('desactivar', 'current_password');
        }

        $this->desactivar($usuario, ['current_password' => self::CLAVE])->assertStatus(429);
        $this->assertTrue($usuario->fresh()->is_active);
    }

    // ----- Calidad de la página -----

    public function test_la_vista_no_tiene_codigo_en_linea(): void
    {
        $admin = User::factory()->admin()->create();
        foreach ([User::factory()->lector()->create(), $admin] as $usuario) {
            $html = $this->pagina($usuario);

            $this->assertStringNotContainsString('style=', $html);
            $this->assertDoesNotMatchRegularExpression('#\son[a-z]+=#i', $html);
            $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#i', $html);
        }
    }
}
