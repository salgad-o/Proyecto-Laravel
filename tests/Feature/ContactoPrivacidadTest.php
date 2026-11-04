<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NP-43 paso 4: autorización de tratamiento de datos en el formulario de contacto.
 * El formulario no guarda ni envía nada, así que la aceptación tampoco se guarda.
 */
class ContactoPrivacidadTest extends TestCase
{
    use RefreshDatabase;

    private const MENSAJE_HONESTO = 'Tu mensaje pasó la validación. El envío por correo aún no está activo.';

    private const ERROR = 'Debes autorizar el tratamiento de tus datos para enviar el mensaje.';

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'name' => 'Ana Lectora',
            'email' => 'ana@nexus.test',
            'message' => 'Hola, me gusta la revista.',
            'acepto_privacidad' => '1',
        ], $cambios);
    }

    private function sinCasilla(): array
    {
        $datos = $this->datos();
        unset($datos['acepto_privacidad']);

        return $datos;
    }

    private function casilla(string $html): string
    {
        $this->assertSame(1, preg_match('#<input type="checkbox" id="acepto_privacidad"[^>]*>#', $html, $casilla));

        return $casilla[0];
    }

    // ---------- Validación ----------

    public function test_sin_la_casilla_hay_error_y_no_aparece_el_mensaje_de_estado(): void
    {
        $this->from(route('contact'))
            ->post(route('contact.send'), $this->sinCasilla())
            ->assertRedirect(route('contact'))
            ->assertSessionHasErrors(['acepto_privacidad' => self::ERROR])
            ->assertSessionMissing('status');

        $this->get(route('contact'))
            ->assertDontSee(self::MENSAJE_HONESTO)
            ->assertDontSee('class="aviso-exito"', false);
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
        $this->post(route('contact.send'), $this->datos(['acepto_privacidad' => $valor]))
            ->assertSessionHasErrors(['acepto_privacidad' => self::ERROR])
            ->assertSessionMissing('status');
    }

    public function test_con_la_casilla_redirige_a_contacto_con_el_mensaje_honesto(): void
    {
        $this->post(route('contact.send'), $this->datos())
            ->assertRedirect(route('contact'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', self::MENSAJE_HONESTO);
    }

    // ---------- Redirección abierta ----------

    public function test_con_referer_externo_el_exito_vuelve_a_contacto(): void
    {
        $this->withHeader('Referer', 'https://sitio-malo.test/')
            ->post(route('contact.send'), $this->datos())
            ->assertRedirect(route('contact'))
            ->assertSessionHas('status', self::MENSAJE_HONESTO);
    }

    public function test_con_referer_externo_el_error_vuelve_a_contacto(): void
    {
        $this->withHeader('Referer', 'https://sitio-malo.test/')
            ->post(route('contact.send'), $this->sinCasilla())
            ->assertRedirect(route('contact'))
            ->assertSessionHasErrors('acepto_privacidad');
    }

    // ---------- Vista ----------

    public function test_la_casilla_sale_sin_marcar_y_con_el_enlace_seguro(): void
    {
        $html = $this->get(route('contact'))->assertOk()->getContent();
        $casilla = $this->casilla($html);

        $this->assertStringContainsString('name="acepto_privacidad"', $casilla);
        $this->assertStringContainsString('value="1"', $casilla);
        $this->assertStringNotContainsString('checked', $casilla);
        $this->assertStringNotContainsString('required', $casilla);
        $this->assertStringNotContainsString('aria-invalid', $casilla);

        $this->assertStringContainsString('<label for="acepto_privacidad"', $html);
        $this->assertSame(1, preg_match('#<main\b.*?</main>#s', $html, $main));
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('legal.privacidad'), '#').'" target="_blank" rel="noopener noreferrer">Política de privacidad</a>#',
            $main[0]
        );
    }

    public function test_con_error_la_casilla_lleva_aria_invalid_y_describedby(): void
    {
        $html = $this->from(route('contact'))
            ->followingRedirects()
            ->post(route('contact.send'), $this->sinCasilla())
            ->assertOk()
            ->getContent();

        $casilla = $this->casilla($html);

        $this->assertStringContainsString('aria-invalid="true"', $casilla);
        $this->assertStringContainsString('aria-describedby="error-acepto_privacidad"', $casilla);
        $this->assertStringContainsString('<p id="error-acepto_privacidad" class="error">'.e(self::ERROR).'</p>', $html);
    }

    public function test_si_falla_otro_campo_la_casilla_vuelve_marcada(): void
    {
        $html = $this->from(route('contact'))
            ->followingRedirects()
            ->post(route('contact.send'), $this->datos(['email' => 'no-es-correo']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('checked', $this->casilla($html));
        $this->assertStringNotContainsString(self::MENSAJE_HONESTO, $html);
    }

    // ---------- Throttle ----------

    public function test_el_envio_numero_11_en_un_minuto_responde_429(): void
    {
        foreach (range(1, 10) as $intento) {
            $this->post(route('contact.send'), $this->datos())->assertRedirect(route('contact'));
        }

        $this->post(route('contact.send'), $this->datos())->assertStatus(429);
    }

    public function test_los_envios_sin_casilla_tambien_cuentan_para_el_limite(): void
    {
        foreach (range(1, 10) as $intento) {
            $this->post(route('contact.send'), $this->sinCasilla())->assertRedirect(route('contact'));
        }

        $this->post(route('contact.send'), $this->datos())->assertStatus(429);
    }
}
