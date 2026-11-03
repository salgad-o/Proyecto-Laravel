<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class RecuperarContrasenaTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE_NUEVA = 'Nueva#Clave2026';

    private const MENSAJE_ENVIADO = 'Si el correo está registrado, te enviamos un enlace para restablecer la contraseña.';

    // ---------- Pedir enlace ----------

    public function test_correo_existente_y_no_existente_reciben_la_misma_respuesta(): void
    {
        Notification::fake();

        $usuario = User::factory()->create();

        $respuestaExistente = $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $usuario->email]);

        $respuestaExistente->assertRedirect(route('password.request'));
        $respuestaExistente->assertSessionHas('status', self::MENSAJE_ENVIADO);
        Notification::assertSentTo($usuario, ResetPassword::class);

        $respuestaNoExistente = $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'noexiste@nexus.test']);

        $respuestaNoExistente->assertRedirect(route('password.request'));
        $respuestaNoExistente->assertSessionHas('status', self::MENSAJE_ENVIADO);

        // Solo al correo existente se le envió la notificación.
        Notification::assertCount(1);
    }

    public function test_pedir_enlace_con_correo_en_mayusculas_envia_al_usuario_registrado(): void
    {
        Notification::fake();

        $usuario = User::factory()->create(['email' => 'jugador@nexus.test']);

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => '  JUGADOR@NEXUS.TEST  '])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', self::MENSAJE_ENVIADO);

        Notification::assertSentTo($usuario, ResetPassword::class);
    }

    public function test_el_envio_numero_seis_en_un_minuto_responde_429(): void
    {
        Notification::fake();

        $usuario = User::factory()->create();

        for ($i = 1; $i <= 5; $i++) {
            $this->post(route('password.email'), ['email' => $usuario->email])
                ->assertStatus(302);
        }

        $this->post(route('password.email'), ['email' => $usuario->email])
            ->assertStatus(429);
    }

    // ---------- Restablecer ----------

    public function test_con_token_valido_cambia_la_contrasena_y_el_token_deja_de_servir(): void
    {
        $usuario = User::factory()->create(['password' => Hash::make('Vieja#Clave2025')]);
        $token = Password::broker()->createToken($usuario);
        $rememberAntes = $usuario->remember_token;

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $usuario->email,
            'password' => self::CLAVE_NUEVA,
            'password_confirmation' => self::CLAVE_NUEVA,
        ])->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Contraseña actualizada. Ya puedes iniciar sesión.');

        $usuario->refresh();

        $this->assertTrue(Hash::check(self::CLAVE_NUEVA, $usuario->password));
        $this->assertNotSame($rememberAntes, $usuario->remember_token);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $usuario->email]);

        // El token es de un solo uso: el mismo enlace ya no funciona.
        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $usuario->email,
            'password' => 'Otra#Clave2027',
            'password_confirmation' => 'Otra#Clave2027',
        ])->assertSessionHasErrors('email');
    }

    public function test_restablecer_contrasena_borra_solo_las_sesiones_del_usuario(): void
    {
        $usuario = User::factory()->create();
        $otro = User::factory()->create();
        $token = Password::broker()->createToken($usuario);

        DB::table('sessions')->insert([
            [
                'id' => 'sesion-del-usuario',
                'user_id' => $usuario->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'payload' => 'payload-usuario',
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => 'sesion-de-otro',
                'user_id' => $otro->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'payload' => 'payload-otro',
                'last_activity' => now()->timestamp,
            ],
        ]);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $usuario->email,
            'password' => self::CLAVE_NUEVA,
            'password_confirmation' => self::CLAVE_NUEVA,
        ])->assertRedirect(route('login'));

        // Se cierran las sesiones del usuario, pero no las de otros usuarios.
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-del-usuario']);
        $this->assertDatabaseHas('sessions', ['id' => 'sesion-de-otro']);
    }

    public function test_token_invalido_se_rechaza(): void
    {
        $usuario = User::factory()->create();

        $this->post(route('password.update'), [
            'token' => 'token-invalido',
            'email' => $usuario->email,
            'password' => self::CLAVE_NUEVA,
            'password_confirmation' => self::CLAVE_NUEVA,
        ])->assertSessionHasErrors('email');

        $this->assertFalse(Hash::check(self::CLAVE_NUEVA, $usuario->fresh()->password));
    }

    public function test_contrasena_debil_se_rechaza(): void
    {
        $usuario = User::factory()->create();
        $token = Password::broker()->createToken($usuario);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $usuario->email,
            'password' => 'debil123',
            'password_confirmation' => 'debil123',
        ])->assertSessionHasErrors('password');

        $this->assertFalse(Hash::check('debil123', $usuario->fresh()->password));
    }

    public function test_contrasena_sin_confirmar_se_rechaza(): void
    {
        $usuario = User::factory()->create();
        $token = Password::broker()->createToken($usuario);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $usuario->email,
            'password' => self::CLAVE_NUEVA,
        ])->assertSessionHasErrors('password');

        $this->assertFalse(Hash::check(self::CLAVE_NUEVA, $usuario->fresh()->password));
    }

    public function test_token_vencido_se_rechaza(): void
    {
        $usuario = User::factory()->create();
        $token = Password::broker()->createToken($usuario);

        $this->travel(61)->minutes();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $usuario->email,
            'password' => self::CLAVE_NUEVA,
            'password_confirmation' => self::CLAVE_NUEVA,
        ])->assertSessionHasErrors('email');

        $this->assertFalse(Hash::check(self::CLAVE_NUEVA, $usuario->fresh()->password));
    }

    // ---------- Auditoría ----------

    public function test_la_auditoria_registra_cambiar_password_sin_la_contrasena(): void
    {
        $usuario = User::factory()->create();
        $token = Password::broker()->createToken($usuario);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $usuario->email,
            'password' => self::CLAVE_NUEVA,
            'password_confirmation' => self::CLAVE_NUEVA,
        ]);

        $registro = AuditLog::where('action', 'cambiar_password')->firstOrFail();

        $this->assertSame($usuario->id, $registro->auditable_id);
        $this->assertSame('cambiar_password', $registro->action);

        $texto = json_encode([$registro->old_values, $registro->new_values]);

        $this->assertStringNotContainsString(self::CLAVE_NUEVA, $texto);
        $this->assertStringNotContainsString($usuario->fresh()->password, $texto);
    }

    // ---------- Cuenta desactivada ----------

    public function test_un_usuario_desactivado_puede_restablecer_pero_no_iniciar_sesion(): void
    {
        $usuario = User::factory()->inactivo()->create();
        $token = Password::broker()->createToken($usuario);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $usuario->email,
            'password' => self::CLAVE_NUEVA,
            'password_confirmation' => self::CLAVE_NUEVA,
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check(self::CLAVE_NUEVA, $usuario->fresh()->password));

        $this->post(route('login.store'), [
            'email' => $usuario->email,
            'password' => self::CLAVE_NUEVA,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // ---------- Vistas ----------

    public function test_las_paginas_de_recuperacion_cargan(): void
    {
        $this->get(route('password.request'))->assertOk();

        $this->get(route('password.reset', [
            'token' => 'token-de-prueba',
            'email' => 'usuario@nexus.test',
        ]))->assertOk();
    }

    public function test_el_login_enlaza_a_la_recuperacion_de_contrasena(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('password.request'), false);
    }

    public function test_las_vistas_nuevas_no_tienen_codigo_en_linea(): void
    {
        foreach (['pages/password/forgot.blade.php', 'pages/password/reset.blade.php'] as $vista) {
            $contenido = file_get_contents(resource_path('views/'.$vista));

            $this->assertStringNotContainsString('style=', $contenido, $vista);
            $this->assertStringNotContainsString('onclick', $contenido, $vista);
            $this->assertStringNotContainsString('<script', $contenido, $vista);
        }
    }
}
