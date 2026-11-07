<?php

namespace Tests\Feature;

use App\Models\Noticia;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pruebas de la "Guía de pruebas estáticas y dinámicas" (secciones 8.2 a 8.5),
 * adaptadas a las rutas reales de Nexus Plays, más las verificaciones de
 * CSRF y XSS. Las cabeceras (H-04, H-05) se prueban en SecurityHeadersTest.
 */
class PruebasSeguridadGuiaTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE_VALIDA = 'Segura#Clave2026';

    /**
     * En los tests Laravel desactiva el CSRF. Esta subclase lo vuelve a activar
     * solo en la prueba que la llama (misma técnica que BannerCookiesTest).
     */
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

    // ---------- 8.2 Login ----------

    public function test_la_pagina_de_login_esta_disponible(): void
    {
        $this->get('/login')->assertOk();
    }

    // ---------- 8.3 Registro ----------

    public function test_un_usuario_se_puede_registrar_y_su_clave_queda_con_hash(): void
    {
        $this->post('/registro', [
            'name' => 'Usuario Prueba',
            'email' => 'prueba@example.com',
            'password' => self::CLAVE_VALIDA,
            'password_confirmation' => self::CLAVE_VALIDA,
            'acepto_legal' => '1',
        ])->assertRedirect('/perfil');

        $this->assertDatabaseHas('users', ['email' => 'prueba@example.com']);

        $usuario = User::where('email', 'prueba@example.com')->firstOrFail();
        $this->assertNotSame(self::CLAVE_VALIDA, $usuario->password);
        $this->assertTrue(password_verify(self::CLAVE_VALIDA, $usuario->password));
    }

    // ---------- 8.4 Ruta privada ----------

    public function test_el_dashboard_exige_autenticacion(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    // ---------- 8.5 Autorización ----------

    public function test_un_lector_recibe_403_en_la_gestion_de_usuarios(): void
    {
        $lector = User::factory()->lector()->create();

        $this->actingAs($lector)->get('/dashboard/usuarios')->assertForbidden();
    }

    public function test_un_editor_recibe_403_en_la_gestion_de_usuarios(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)->get('/dashboard/usuarios')->assertForbidden();
    }

    public function test_un_admin_si_entra_a_la_gestion_de_usuarios(): void
    {
        // Control positivo: el 403 de arriba viene del rol, no de un error de la ruta.
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/dashboard/usuarios')->assertOk();
    }

    // ---------- CSRF ----------

    public function test_el_login_sin_token_csrf_responde_419(): void
    {
        $this->activarCsrfReal();

        $this->post('/login', [
            'email' => 'alguien@example.com',
            'password' => self::CLAVE_VALIDA,
        ])->assertStatus(419);

        $this->assertGuest();
    }

    // ---------- XSS ----------

    public function test_un_comentario_con_script_se_muestra_escapado(): void
    {
        $lector = User::factory()->lector()->create();
        $noticia = Noticia::factory()->create();
        $ataque = '<script>alert("xss")</script>';

        $this->actingAs($lector)
            ->post(route('comentarios.store', $noticia), ['body' => $ataque])
            ->assertRedirect();

        $html = $this->get(route('noticias.show', $noticia))->assertOk()->getContent();

        $this->assertStringNotContainsString($ataque, $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
