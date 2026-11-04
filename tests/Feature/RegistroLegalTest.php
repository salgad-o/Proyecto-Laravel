<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NP-43 paso 3: autorización legal en el registro y aviso en el login.
 */
class RegistroLegalTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE_VALIDA = 'Clave-Segura-2026!';

    private const MENSAJE = 'Debes aceptar los Términos de uso y la Política de privacidad para crear tu cuenta.';

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'name' => 'Jugadora Legal',
            'email' => 'legal@nexus.test',
            'password' => self::CLAVE_VALIDA,
            'password_confirmation' => self::CLAVE_VALIDA,
            'acepto_legal' => '1',
        ], $cambios);
    }

    private function usuarioNuevo(): User
    {
        return User::where('email', 'legal@nexus.test')->firstOrFail();
    }

    // ---------- Validación ----------

    public function test_sin_la_casilla_no_se_crea_el_usuario(): void
    {
        $datos = $this->datos();
        unset($datos['acepto_legal']);

        $this->from(route('register'))
            ->post(route('register.store'), $datos)
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['acepto_legal' => self::MENSAJE]);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'legal@nexus.test']);
    }

    public static function valoresNoAceptados(): array
    {
        return [
            'cero' => ['0'],
            'no' => ['no'],
            'vacio' => [''],
        ];
    }

    #[DataProvider('valoresNoAceptados')]
    public function test_un_valor_no_aceptado_da_error(string $valor): void
    {
        $this->post(route('register.store'), $this->datos(['acepto_legal' => $valor]))
            ->assertSessionHasErrors('acepto_legal');

        $this->assertDatabaseMissing('users', ['email' => 'legal@nexus.test']);
    }

    // ---------- Registro con aceptación ----------

    public function test_con_la_casilla_se_guardan_las_fechas_y_la_version(): void
    {
        $this->post(route('register.store'), $this->datos())
            ->assertRedirect(route('dashboard'));

        $usuario = $this->usuarioNuevo();

        $this->assertNotNull($usuario->terms_accepted_at);
        $this->assertNotNull($usuario->privacy_accepted_at);
        $this->assertTrue($usuario->terms_accepted_at->equalTo($usuario->privacy_accepted_at));
        $this->assertLessThan(5, abs(now()->diffInSeconds($usuario->terms_accepted_at)));
        $this->assertSame(config('legal.version'), $usuario->legal_version);
    }

    public function test_los_valores_enviados_en_el_formulario_se_ignoran(): void
    {
        $this->post(route('register.store'), $this->datos([
            'terms_accepted_at' => '2020-01-01',
            'privacy_accepted_at' => '2020-01-01',
            'legal_version' => '9.9',
        ]))->assertRedirect(route('dashboard'));

        $usuario = $this->usuarioNuevo();

        $this->assertSame(config('legal.version'), $usuario->legal_version);
        $this->assertNotSame('2020', $usuario->terms_accepted_at->format('Y'));
        $this->assertNotSame('2020', $usuario->privacy_accepted_at->format('Y'));
        $this->assertLessThan(5, abs(now()->diffInSeconds($usuario->terms_accepted_at)));
    }

    public function test_la_auditoria_tiene_una_sola_fila_crear_con_la_aceptacion(): void
    {
        $this->post(route('register.store'), $this->datos());

        $usuario = $this->usuarioNuevo();

        $filas = AuditLog::where('auditable_type', $usuario->getMorphClass())
            ->where('auditable_id', $usuario->id)
            ->get();

        $this->assertCount(1, $filas);
        $this->assertSame('crear', $filas[0]->action);

        $valores = $filas[0]->new_values;

        $this->assertArrayHasKey('terms_accepted_at', $valores);
        $this->assertArrayHasKey('privacy_accepted_at', $valores);
        $this->assertSame(config('legal.version'), $valores['legal_version']);
        $this->assertArrayNotHasKey('password', $valores);
        $this->assertArrayNotHasKey('remember_token', $valores);
    }

    // ---------- Vista del registro ----------

    public function test_la_pagina_de_registro_tiene_la_casilla_sin_marcar_y_los_enlaces(): void
    {
        $html = $this->get(route('register'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<input type="checkbox" id="acepto_legal"[^>]*>#', $html, $casilla));
        $this->assertStringContainsString('name="acepto_legal"', $casilla[0]);
        $this->assertStringContainsString('value="1"', $casilla[0]);
        $this->assertStringNotContainsString('checked', $casilla[0]);
        $this->assertStringNotContainsString('required', $casilla[0]);
        $this->assertStringNotContainsString('aria-invalid', $casilla[0]);

        $this->assertStringContainsString('<label for="acepto_legal"', $html);
        $this->assertEnlaceLegal($html, route('legal.terminos'), 'Términos de uso');
        $this->assertEnlaceLegal($html, route('legal.privacidad'), 'Política de privacidad');
    }

    public function test_con_error_la_casilla_lleva_aria_invalid_y_describedby(): void
    {
        $datos = $this->datos();
        unset($datos['acepto_legal']);

        $html = $this->from(route('register'))
            ->followingRedirects()
            ->post(route('register.store'), $datos)
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match('#<input type="checkbox" id="acepto_legal"[^>]*>#', $html, $casilla));
        $this->assertStringContainsString('aria-invalid="true"', $casilla[0]);
        $this->assertStringContainsString('aria-describedby="acepto_legal-error"', $casilla[0]);
        $this->assertStringContainsString('<p id="acepto_legal-error" class="auth-error">'.e(self::MENSAJE).'</p>', $html);
    }

    public function test_si_falla_otro_campo_la_casilla_sigue_marcada(): void
    {
        $html = $this->from(route('register'))
            ->followingRedirects()
            ->post(route('register.store'), $this->datos([
                'password' => 'debil',
                'password_confirmation' => 'debil',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match('#<input type="checkbox" id="acepto_legal"[^>]*>#', $html, $casilla));
        $this->assertStringContainsString('checked', $casilla[0]);
        $this->assertDatabaseMissing('users', ['email' => 'legal@nexus.test']);
    }

    // ---------- Login ----------

    public function test_el_login_muestra_el_aviso_sin_casilla(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('Al iniciar sesión aceptas los', $html);
        $this->assertEnlaceLegal($html, route('legal.terminos'), 'Términos de uso');
        $this->assertEnlaceLegal($html, route('legal.privacidad'), 'Política de privacidad');
        $this->assertStringNotContainsString('type="checkbox"', $html);
        $this->assertStringNotContainsString('acepto_legal', $html);
    }

    /** El enlace existe dentro del <main> (no el de la barra lateral) y abre en otra pestaña de forma segura. */
    private function assertEnlaceLegal(string $html, string $url, string $texto): void
    {
        $this->assertSame(1, preg_match('#<main\b.*?</main>#s', $html, $main));
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote($url, '#').'" target="_blank" rel="noopener noreferrer">'.preg_quote($texto, '#').'</a>#',
            $main[0]
        );
    }
}
