<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * NP-43 paso 2: banner de consentimiento de cookies.
 */
class BannerCookiesTest extends TestCase
{
    use RefreshDatabase;

    private function nombreCookie(): string
    {
        return config('legal.consentimiento.cookie');
    }

    /** Devuelve el objeto Cookie de la respuesta sin descifrar (para leer sus atributos). */
    private function cookieDeRespuesta($respuesta): ?Cookie
    {
        return $respuesta->getCookie($this->nombreCookie(), false);
    }

    public static function paginasConBanner(): array
    {
        return [
            'inicio' => ['home'],
            'noticias' => ['noticias.index'],
            'cookies' => ['legal.cookies'],
            'login' => ['login'],
        ];
    }

    public static function eleccionesValidas(): array
    {
        return [
            'todas' => ['todas'],
            'necesarias' => ['necesarias'],
        ];
    }

    public static function eleccionesInvalidas(): array
    {
        return [
            'vacia' => [''],
            'otra' => ['otra'],
            'arreglo' => [['todas']],
        ];
    }

    // ---------- Mostrar u ocultar el banner ----------

    #[DataProvider('paginasConBanner')]
    public function test_sin_cookie_el_banner_aparece_con_su_formulario(string $ruta): void
    {
        $html = $this->get(route($ruta))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<section class="banner-cookies" role="region" aria-label="Aviso de cookies">(.*?)</section>#s', $html, $banner));
        $this->assertSame(1, preg_match(
            '#<form method="POST" action="'.preg_quote(route('cookies.consentimiento'), '#').'" class="banner-cookies-form">(.*?)</form>#s',
            $banner[1],
            $form
        ));
        $this->assertStringContainsString('name="_token"', $form[1]);
        $this->assertStringContainsString('name="eleccion" value="necesarias"', $form[1]);
        $this->assertStringContainsString('name="eleccion" value="todas"', $form[1]);
        $this->assertStringContainsString('href="'.route('legal.cookies').'"', $banner[1]);
        $this->assertStringNotContainsString('data-consentimiento', $html);
    }

    public function test_los_dos_botones_tienen_la_misma_clase(): void
    {
        $html = $this->get(route('home'))->getContent();

        preg_match_all('#<button type="submit" name="eleccion" value="[a-z]+" class="([^"]+)">#', $html, $clases);

        $this->assertCount(2, $clases[1]);
        $this->assertSame($clases[1][0], $clases[1][1]);
    }

    #[DataProvider('eleccionesValidas')]
    public function test_con_cookie_valida_no_hay_banner_y_html_lleva_la_eleccion(string $valor): void
    {
        $html = $this->withCookie($this->nombreCookie(), $valor)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('banner-cookies', false)
            ->getContent();

        $this->assertMatchesRegularExpression('#<html lang="es"\s+data-consentimiento="'.$valor.'"\s*>#', $html);
    }

    public function test_un_valor_cifrado_pero_no_permitido_muestra_el_banner(): void
    {
        $this->withCookie($this->nombreCookie(), 'hackeado')
            ->get(route('home'))
            ->assertOk()
            ->assertSee('class="banner-cookies"', false)
            ->assertDontSee('data-consentimiento', false);
    }

    public function test_una_cookie_sin_cifrar_no_descifra_y_muestra_el_banner(): void
    {
        $this->withUnencryptedCookie($this->nombreCookie(), 'todas')
            ->get(route('home'))
            ->assertOk()
            ->assertSee('class="banner-cookies"', false)
            ->assertDontSee('data-consentimiento', false);
    }

    // ---------- Guardar la elección ----------

    #[DataProvider('eleccionesValidas')]
    public function test_guardar_la_eleccion_crea_la_cookie_segura(string $valor): void
    {
        $this->freezeTime();

        $respuesta = $this->from(route('noticias.index'))
            ->post(route('cookies.consentimiento'), ['eleccion' => $valor])
            ->assertRedirect(route('noticias.index'))
            ->assertCookie($this->nombreCookie(), $valor);

        $cookie = $this->cookieDeRespuesta($respuesta);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame(now()->addMinutes(259200)->getTimestamp(), $cookie->getExpiresTime());
        $this->assertEqualsWithDelta(180 * 24 * 60 * 60, $cookie->getExpiresTime() - now()->getTimestamp(), 5);
    }

    #[DataProvider('eleccionesInvalidas')]
    public function test_una_eleccion_invalida_no_crea_cookie(string|array $valor): void
    {
        $respuesta = $this->from(route('home'))
            ->post(route('cookies.consentimiento'), ['eleccion' => $valor])
            ->assertSessionHasErrors('eleccion');

        $this->assertNull($this->cookieDeRespuesta($respuesta));
    }

    public function test_sin_eleccion_no_crea_cookie(): void
    {
        $respuesta = $this->post(route('cookies.consentimiento'))
            ->assertSessionHasErrors('eleccion');

        $this->assertNull($this->cookieDeRespuesta($respuesta));
    }

    public function test_por_json_responde_la_eleccion_y_la_cookie(): void
    {
        $this->postJson(route('cookies.consentimiento'), ['eleccion' => 'todas'])
            ->assertOk()
            ->assertExactJson(['eleccion' => 'todas'])
            ->assertCookie($this->nombreCookie(), 'todas');
    }

    public function test_por_json_una_eleccion_invalida_responde_422_sin_cookie(): void
    {
        $respuesta = $this->postJson(route('cookies.consentimiento'), ['eleccion' => 'otra'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('eleccion');

        $this->assertNull($this->cookieDeRespuesta($respuesta));
    }

    // ---------- Redirección abierta ----------

    public function test_un_referer_externo_redirige_a_inicio(): void
    {
        $this->withHeader('Referer', 'https://sitio-malo.test/')
            ->post(route('cookies.consentimiento'), ['eleccion' => 'todas'])
            ->assertRedirect(route('home'));
    }

    public function test_un_referer_que_solo_empieza_como_la_app_redirige_a_inicio(): void
    {
        $this->withHeader('Referer', url('/').'.sitio-malo.test/')
            ->post(route('cookies.consentimiento'), ['eleccion' => 'todas'])
            ->assertRedirect(route('home'));
    }

    public function test_un_referer_externo_con_eleccion_invalida_tampoco_sale_de_la_app(): void
    {
        $this->withHeader('Referer', 'https://sitio-malo.test/')
            ->post(route('cookies.consentimiento'), ['eleccion' => 'otra'])
            ->assertRedirect(route('home'));
    }

    public function test_un_referer_de_la_app_vuelve_a_esa_pagina(): void
    {
        $this->withHeader('Referer', route('legal.privacidad'))
            ->post(route('cookies.consentimiento'), ['eleccion' => 'necesarias'])
            ->assertRedirect(route('legal.privacidad'));
    }

    // ---------- Método, límite y CSRF ----------

    public function test_get_no_esta_permitido(): void
    {
        $this->get('/cookies/consentimiento')->assertMethodNotAllowed();
    }

    public function test_la_peticion_11_en_un_minuto_recibe_429(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('cookies.consentimiento'), ['eleccion' => 'todas'])->assertRedirect();
        }

        $this->post(route('cookies.consentimiento'), ['eleccion' => 'todas'])->assertStatus(429);
    }

    /**
     * En las pruebas Laravel se salta la verificación CSRF (runningUnitTests()).
     * Solo en este test se registra en el contenedor una subclase que NO se la
     * salta, para comprobar de verdad el 419. El código de producción no cambia.
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

    public function test_sin_token_csrf_responde_419_y_no_crea_cookie(): void
    {
        $this->activarCsrfReal();

        $respuesta = $this->post(route('cookies.consentimiento'), ['eleccion' => 'todas'])
            ->assertStatus(419);

        $this->assertNull($this->cookieDeRespuesta($respuesta));
    }

    public function test_con_token_csrf_valido_si_guarda_la_eleccion(): void
    {
        // Control positivo: confirma que el 419 de arriba viene del CSRF y no de otra cosa.
        $this->activarCsrfReal();

        $this->withSession(['_token' => 'token-de-prueba'])
            ->post(route('cookies.consentimiento'), ['eleccion' => 'todas', '_token' => 'token-de-prueba'])
            ->assertRedirect()
            ->assertCookie($this->nombreCookie(), 'todas');
    }

    // ---------- CSP ----------

    public function test_el_layout_con_banner_no_tiene_estilos_ni_scripts_en_linea(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('class="banner-cookies"', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertDoesNotMatchRegularExpression('#\son[a-z]+=#i', $html);
        $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#i', $html);
    }
}
