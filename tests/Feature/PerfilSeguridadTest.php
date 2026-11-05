<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\SesionesUsuario;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NP-36 paso 3: pestaña "Seguridad" con cambio de contraseña.
 */
class PerfilSeguridadTest extends TestCase
{
    use RefreshDatabase;

    /** Contraseña de UserFactory. */
    private const CLAVE_ACTUAL = 'password';

    private const CLAVE_NUEVA = 'NuevaClave#Segura2026';

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

    private function datosValidos(array $cambios = []): array
    {
        return array_merge([
            'current_password' => self::CLAVE_ACTUAL,
            'password' => self::CLAVE_NUEVA,
            'password_confirmation' => self::CLAVE_NUEVA,
        ], $cambios);
    }

    private function cambiar(User $usuario, array $datos)
    {
        return $this->actingAs($usuario)
            ->from(route('perfil.seguridad'))
            ->put(route('perfil.contrasena'), $datos);
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

    private function idDeSesionDe($respuesta): string
    {
        $cookie = $respuesta->getCookie(config('session.cookie'), false);
        $this->assertNotNull($cookie, 'La respuesta no trae la cookie de sesión.');

        return CookieValuePrefix::remove(decrypt($cookie->getValue(), false));
    }

    // ----- Acceso -----

    public function test_un_visitante_va_al_login(): void
    {
        $this->get(route('perfil.seguridad'))->assertRedirect(route('login'));
        $this->put(route('perfil.contrasena'), $this->datosValidos())->assertRedirect(route('login'));
    }

    // ----- Vista -----

    public function test_la_pagina_muestra_las_pestanas_con_seguridad_activa(): void
    {
        $html = $this->actingAs(User::factory()->lector()->create())
            ->get(route('perfil.seguridad'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<p class="pagina-etiqueta">MI CUENTA</p>', $html);
        $this->assertStringContainsString('<h1 class="pagina-titulo">Mi perfil</h1>', $html);

        $this->assertSame(1, preg_match('#<nav class="pestanas" aria-label="Secciones de mi perfil">(.*?)</nav>#s', $html, $nav));
        // Perfil, Seguridad, Actividad (paso 5) y Cuenta (paso 6).
        $this->assertSame(4, substr_count($nav[1], '<a '));
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('perfil.edit'), '#').'" class="pestana"\s*>Perfil</a>#', $nav[1]);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('perfil.seguridad'), '#').'" class="pestana pestana-activa"\s+aria-current="page"\s*>Seguridad</a>#', $nav[1]);
        $this->assertSame(1, substr_count($nav[1], 'aria-current'));
    }

    public function test_el_formulario_tiene_los_tres_campos_sin_value(): void
    {
        $html = $this->actingAs(User::factory()->lector()->create())
            ->get(route('perfil.seguridad'))
            ->getContent();

        $this->assertMatchesRegularExpression('#<form method="POST" action="'.preg_quote(route('perfil.contrasena'), '#').'"#', $html);
        $this->assertStringContainsString('name="_method" value="PUT"', $html);
        $this->assertStringContainsString('Al cambiarla se cerrará tu sesión en los demás dispositivos.', $html);
        $this->assertStringContainsString('12 o más caracteres con mayúsculas, minúsculas, números y símbolos', $html);

        foreach ([
            'current_password' => 'current-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ] as $campo => $autocomplete) {
            $this->assertStringContainsString('<label for="'.$campo.'">', $html);
            $this->assertSame(1, preg_match('#<input id="'.$campo.'" type="password"[^>]*>#', $html, $input));
            $this->assertStringContainsString('autocomplete="'.$autocomplete.'"', $input[0]);
            $this->assertStringNotContainsString('value=', $input[0]);
        }
    }

    public function test_tras_un_error_los_campos_de_contrasena_siguen_vacios(): void
    {
        $usuario = User::factory()->lector()->create();

        $html = $this->actingAs($usuario)
            ->from(route('perfil.seguridad'))
            ->followingRedirects()
            ->put(route('perfil.contrasena'), $this->datosValidos(['current_password' => 'Incorrecta#123456']))
            ->assertOk()
            ->getContent();

        preg_match_all('#<input id="(current_password|password|password_confirmation)"[^>]*>#', $html, $inputs);
        $this->assertCount(3, $inputs[0]);
        foreach ($inputs[0] as $input) {
            $this->assertStringNotContainsString('value=', $input);
        }
        $this->assertStringNotContainsString('Incorrecta#123456', $html);
        $this->assertStringNotContainsString(self::CLAVE_NUEVA, $html);
        $this->assertMatchesRegularExpression('#<input id="current_password"[^>]*aria-invalid="true" aria-describedby="error-current_password"#', $html);
        $this->assertStringContainsString('<p id="error-current_password" class="error">La contraseña actual no es correcta.</p>', $html);
    }

    // ----- Errores -----

    public function test_contrasena_actual_incorrecta_no_cambia_nada(): void
    {
        $usuario = User::factory()->lector()->create();
        $hashAntes = $usuario->password;
        $auditoriaAntes = AuditLog::count();

        $this->cambiar($usuario, $this->datosValidos(['current_password' => 'NoEsEsta#2026']))
            ->assertRedirect(route('perfil.seguridad'))
            ->assertSessionHasErrors(['current_password' => 'La contraseña actual no es correcta.']);

        $this->assertSame($hashAntes, $usuario->fresh()->password);
        $this->assertSame($auditoriaAntes, AuditLog::count());
    }

    public function test_la_nueva_igual_a_la_actual_da_error(): void
    {
        $usuario = User::factory()->lector()->create(['password' => self::CLAVE_NUEVA]);
        $hashAntes = $usuario->password;

        $this->cambiar($usuario, $this->datosValidos(['current_password' => self::CLAVE_NUEVA]))
            ->assertSessionHasErrors(['password' => 'La nueva contraseña debe ser distinta de la actual.']);

        $this->assertSame($hashAntes, $usuario->fresh()->password);
    }

    public static function contrasenasDebiles(): array
    {
        return [
            'corta' => ['Ab1#corta', 'La nueva contraseña debe tener al menos 12 caracteres.'],
            'sin mayúsculas' => ['minusculas#2026', 'La nueva contraseña debe tener mayúsculas y minúsculas.'],
            'sin minúsculas' => ['MAYUSCULAS#2026', 'La nueva contraseña debe tener mayúsculas y minúsculas.'],
            'sin números' => ['SinNumeros#Clave', 'La nueva contraseña debe tener al menos un número.'],
            'sin símbolos' => ['SinSimbolos2026', 'La nueva contraseña debe tener al menos un símbolo.'],
        ];
    }

    #[DataProvider('contrasenasDebiles')]
    public function test_una_contrasena_debil_da_error(string $clave, string $mensaje): void
    {
        $usuario = User::factory()->lector()->create();
        $hashAntes = $usuario->password;

        $this->cambiar($usuario, $this->datosValidos(['password' => $clave, 'password_confirmation' => $clave]))
            ->assertSessionHasErrors('password');

        $this->assertContains($mensaje, session('errors')->get('password'));
        $this->assertSame($hashAntes, $usuario->fresh()->password);
    }

    public function test_confirmacion_distinta_da_error(): void
    {
        $usuario = User::factory()->lector()->create();
        $hashAntes = $usuario->password;

        $this->cambiar($usuario, $this->datosValidos(['password_confirmation' => 'OtraClave#Segura2026']))
            ->assertSessionHasErrors(['password' => 'La confirmación no coincide.']);

        $this->assertSame($hashAntes, $usuario->fresh()->password);
    }

    // ----- Éxito -----

    public function test_cambia_la_contrasena_y_el_remember_token(): void
    {
        $usuario = User::factory()->lector()->create();
        $tokenAntes = $usuario->getRememberToken();

        $this->cambiar($usuario, $this->datosValidos())
            ->assertRedirect(route('perfil.seguridad'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Contraseña actualizada. Cerramos tu sesión en los demás dispositivos.');

        $despues = $usuario->fresh();
        $this->assertTrue(Hash::check(self::CLAVE_NUEVA, $despues->password));
        $this->assertFalse(Hash::check(self::CLAVE_ACTUAL, $despues->password));
        // Un solo hash (el cast 'hashed' no la hashea dos veces).
        $this->assertFalse(Hash::check(Hash::make(self::CLAVE_NUEVA), $despues->password));
        $this->assertNotSame($tokenAntes, $despues->getRememberToken());
        $this->assertSame(60, strlen($despues->getRememberToken()));
    }

    public function test_audita_solo_la_accion_sin_contrasena_ni_hash(): void
    {
        $usuario = User::factory()->lector()->create();
        $ultimoId = AuditLog::max('id') ?? 0;

        $this->cambiar($usuario, $this->datosValidos())->assertSessionHasNoErrors();

        // Filas creadas por el cambio de contraseña: exactamente una.
        $nuevas = AuditLog::where('id', '>', $ultimoId)->get();
        $this->assertCount(1, $nuevas);
        $this->assertSame(1, AuditLog::where('action', 'cambiar_password')->count());
        $this->assertSame(0, AuditLog::where('action', 'editar')->where('auditable_id', $usuario->id)->count());

        $registro = $nuevas->first();
        $this->assertSame('cambiar_password', $registro->action);
        $this->assertSame($usuario->id, $registro->auditable_id);
        $this->assertSame($usuario->id, $registro->user_id);
        $this->assertNull($registro->old_values);
        $this->assertNull($registro->new_values);

        $hash = $usuario->fresh()->password;
        foreach ((array) DB::table('audit_logs')->where('id', $registro->id)->first() as $valor) {
            $this->assertStringNotContainsString(self::CLAVE_NUEVA, (string) $valor);
            $this->assertStringNotContainsString($hash, (string) $valor);
        }
    }

    public function test_regenera_el_id_de_sesion_y_sigue_autenticado(): void
    {
        $usuario = User::factory()->lector()->create();

        // Primera petición: se obtiene el ID de sesión que el navegador enviaría.
        $idAntes = $this->idDeSesionDe($this->actingAs($usuario)->get(route('perfil.seguridad'))->assertOk());

        $respuesta = $this->withCookie(config('session.cookie'), $idAntes)
            ->put(route('perfil.contrasena'), $this->datosValidos())
            ->assertSessionHasNoErrors();

        $idDespues = $this->idDeSesionDe($respuesta);
        $this->assertNotSame($idAntes, $idDespues);
        // La sesión anterior se destruyó (regenerate(true)).
        $this->assertSame('', $this->app['session']->driver()->getHandler()->read($idAntes));
        $this->assertAuthenticatedAs($usuario);

        $this->get(route('perfil.seguridad'))->assertOk();
    }

    public function test_cierra_las_otras_sesiones_del_usuario_y_conserva_la_actual_y_las_de_otros(): void
    {
        // Con el driver database (el de .env.example) la sesión actual también queda en la tabla.
        config(['session.driver' => 'database']);
        $usuario = User::factory()->lector()->create();
        $otro = User::factory()->create();
        $this->insertarSesion('sesion-del-usuario-1', $usuario->id);
        $this->insertarSesion('sesion-del-usuario-2', $usuario->id);
        $this->insertarSesion('sesion-de-otro', $otro->id);
        $this->insertarSesion('sesion-de-visitante', null);

        $respuesta = $this->cambiar($usuario, $this->datosValidos())->assertSessionHasNoErrors();
        $idActual = $this->idDeSesionDe($respuesta);

        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-del-usuario-1']);
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-del-usuario-2']);
        $this->assertDatabaseHas('sessions', ['id' => 'sesion-de-otro']);
        $this->assertDatabaseHas('sessions', ['id' => 'sesion-de-visitante']);
        // Solo queda la sesión nueva (la actual) del usuario.
        $this->assertSame([$idActual], DB::table('sessions')->where('user_id', $usuario->id)->pluck('id')->all());
    }

    public function test_borra_los_tokens_de_recuperacion_del_usuario_y_no_los_de_otros(): void
    {
        $usuario = User::factory()->lector()->create(['email' => 'yo@example.com']);
        User::factory()->create(['email' => 'otro@example.com']);
        foreach (['yo@example.com', 'otro@example.com'] as $email) {
            DB::table('password_reset_tokens')->insert([
                'email' => $email,
                'token' => Hash::make('token'),
                'created_at' => now(),
            ]);
        }

        $this->cambiar($usuario, $this->datosValidos())->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'yo@example.com']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'otro@example.com']);
    }

    public function test_despues_del_cambio_la_vieja_no_sirve_y_la_nueva_si(): void
    {
        $usuario = User::factory()->lector()->create();

        $this->cambiar($usuario, $this->datosValidos())->assertSessionHasNoErrors();
        $this->post(route('logout'));
        $this->assertGuest();

        $this->post(route('login.store'), ['email' => $usuario->email, 'password' => self::CLAVE_ACTUAL])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('login.store'), ['email' => $usuario->email, 'password' => self::CLAVE_NUEVA])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($usuario);
    }

    // ----- Seguridad de la petición -----

    public function test_sin_token_csrf_responde_419(): void
    {
        $this->activarCsrfReal();
        $usuario = User::factory()->lector()->create();
        $hashAntes = $usuario->password;

        $this->actingAs($usuario)
            ->put(route('perfil.contrasena'), $this->datosValidos())
            ->assertStatus(419);

        $this->assertSame($hashAntes, $usuario->fresh()->password);
    }

    public function test_la_peticion_6_en_un_minuto_responde_429(): void
    {
        $usuario = User::factory()->lector()->create();
        $intento = $this->datosValidos(['current_password' => 'Adivinando#2026']);

        for ($i = 1; $i <= 5; $i++) {
            $this->cambiar($usuario, $intento)->assertSessionHasErrors('current_password');
        }

        $this->cambiar($usuario, $intento)->assertStatus(429);
    }

    // ----- SesionesUsuario -----

    public function test_cerrar_otras_devuelve_cuantas_cerro(): void
    {
        $usuario = User::factory()->create();
        $otro = User::factory()->create();
        $this->insertarSesion('actual', $usuario->id);
        $this->insertarSesion('vieja-1', $usuario->id);
        $this->insertarSesion('vieja-2', $usuario->id);
        $this->insertarSesion('de-otro', $otro->id);

        $this->assertSame(2, SesionesUsuario::cerrarOtras($usuario, 'actual'));
        $this->assertSame(['actual'], DB::table('sessions')->where('user_id', $usuario->id)->pluck('id')->all());
        $this->assertDatabaseHas('sessions', ['id' => 'de-otro']);

        // Sin nada más que cerrar devuelve 0.
        $this->assertSame(0, SesionesUsuario::cerrarOtras($usuario, 'actual'));
    }

    // ----- Calidad de la página -----

    public function test_la_vista_no_tiene_codigo_en_linea(): void
    {
        $html = $this->actingAs(User::factory()->lector()->create())
            ->get(route('perfil.seguridad'))
            ->getContent();

        $this->assertStringNotContainsString('style=', $html);
        $this->assertDoesNotMatchRegularExpression('#\son[a-z]+=#i', $html);
        $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#i', $html);
    }
}
