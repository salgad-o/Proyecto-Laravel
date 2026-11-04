<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NP-43 paso 5: configuración de la sesión y textos que dependen de ella.
 *
 * Que el login regenera el ID de sesión ya lo cubre
 * AuthTest::test_el_login_regenera_el_id_de_sesion (no se duplica aquí).
 */
class SesionConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_cookie_de_sesion_es_http_only_y_same_site_lax(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_fuera_de_produccion_secure_es_falso_por_defecto(): void
    {
        // phpunit.xml usa APP_ENV=testing y no define SESSION_SECURE_COOKIE.
        $this->assertFalse(config('session.secure'));
    }

    public function test_con_secure_la_cookie_de_consentimiento_sale_con_secure(): void
    {
        config(['session.secure' => true]);

        $cookie = $this->post(route('cookies.consentimiento'), ['eleccion' => 'todas'])
            ->getCookie(config('legal.consentimiento.cookie'), false);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
    }

    public function test_sin_secure_la_cookie_de_consentimiento_sale_sin_secure(): void
    {
        config(['session.secure' => false]);

        $cookie = $this->post(route('cookies.consentimiento'), ['eleccion' => 'todas'])
            ->getCookie(config('legal.consentimiento.cookie'), false);

        $this->assertNotNull($cookie);
        $this->assertFalse($cookie->isSecure());
    }

    public function test_cookies_muestra_los_minutos_de_inactividad_si_no_expira_al_cerrar(): void
    {
        config(['session.expire_on_close' => false, 'session.lifetime' => 120]);

        $this->get(route('legal.cookies'))
            ->assertOk()
            ->assertSee('120 minutos de inactividad')
            ->assertDontSee('cierres el navegador');
    }

    public function test_cookies_dice_hasta_cerrar_el_navegador_si_expira_al_cerrar(): void
    {
        config(['session.expire_on_close' => true]);

        $this->get(route('legal.cookies'))
            ->assertOk()
            ->assertSee('Hasta que cierres el navegador')
            ->assertDontSee('minutos de inactividad');
    }

    public function test_cookies_explica_que_xsrf_token_no_es_http_only(): void
    {
        $this->get(route('legal.cookies'))
            ->assertOk()
            ->assertSee('No es HttpOnly a propósito')
            ->assertSee('No sirve para entrar a tu cuenta.');
    }

    public function test_el_logout_invalida_la_sesion_y_cambia_el_token(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->withSession(['dato_previo' => 'valor']);

        $this->get(route('dashboard'))->assertOk();
        $idAntes = session()->getId();
        $tokenAntes = session()->token();

        $this->post(route('logout'))->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull(session('dato_previo'), 'invalidate() debe vaciar los datos de la sesión.');
        $this->assertNotSame($idAntes, session()->getId(), 'invalidate() debe cambiar el ID de sesión.');
        $this->assertNotSame($tokenAntes, session()->token(), 'regenerateToken() debe cambiar el token CSRF.');
    }
}
