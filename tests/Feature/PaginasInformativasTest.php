<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaginasInformativasTest extends TestCase
{
    use RefreshDatabase;

    private const MENSAJE_HONESTO = 'Tu mensaje pasó la validación. El envío por correo aún no está activo.';

    private function datosContacto(array $cambios = []): array
    {
        return array_merge([
            'name' => 'Ana Lectora',
            'email' => 'ana@nexus.test',
            'message' => 'Hola, me gusta la revista.',
        ], $cambios);
    }

    // ---------- Acerca ----------

    public function test_acerca_responde_sin_cifras_ni_certificaciones_inventadas(): void
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertSee('<title>Acerca · Nexus Plays</title>', false)
            ->assertSee('ACERCA DE NEXUS PLAYS')
            ->assertDontSee('99.98')
            ->assertDontSee('OWASP');
    }

    // ---------- Contacto: página ----------

    public function test_contacto_es_un_solo_documento(): void
    {
        $html = $this->get(route('contact'))
            ->assertOk()
            ->assertSee('<title>Contacto · Nexus Plays</title>', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, '<html'));
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertSame(1, substr_count(strtolower($html), '<!doctype'));
        $this->assertStringNotContainsString('<br', $html);
    }

    public function test_el_formulario_tiene_csrf_y_cada_label_apunta_a_su_campo(): void
    {
        $html = $this->get(route('contact'))
            ->assertSee('name="_token"', false)
            ->getContent();

        foreach (['name', 'email', 'message'] as $campo) {
            $this->assertStringContainsString('<label for="'.$campo.'">', $html);
            $this->assertMatchesRegularExpression('#<(input|textarea) id="'.$campo.'" [^>]*name="'.$campo.'"#', $html);
        }
    }

    public function test_el_formulario_tiene_los_limites_y_el_contador(): void
    {
        $this->get(route('contact'))
            ->assertSee('maxlength="100"', false)
            ->assertSee('maxlength="150"', false)
            ->assertSee('maxlength="2000"', false)
            ->assertSee('autocomplete="name"', false)
            ->assertSee('autocomplete="email"', false)
            ->assertSee('data-contador="contador-mensaje"', false)
            ->assertSee('data-contador-aviso="aviso-mensaje"', false)
            ->assertSee('Por ahora este formulario solo valida tu mensaje: no lo guarda ni lo envía.');
    }

    // ---------- Contacto: envío ----------

    public function test_un_envio_valido_redirige_con_el_mensaje_honesto(): void
    {
        $this->from(route('contact'))
            ->post(route('contact.send'), $this->datosContacto())
            ->assertRedirect(route('contact'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', self::MENSAJE_HONESTO);

        $this->get(route('contact'))
            ->assertSee('class="aviso-exito" role="status"', false)
            ->assertSee(self::MENSAJE_HONESTO);
    }

    public function test_los_campos_vacios_se_rechazan_por_campo(): void
    {
        $this->from(route('contact'))
            ->post(route('contact.send'), ['name' => '', 'email' => '', 'message' => ''])
            ->assertRedirect(route('contact'))
            ->assertSessionHasErrors([
                'name' => 'Escribe tu nombre.',
                'email' => 'Escribe tu correo electrónico.',
                'message' => 'Escribe tu mensaje.',
            ]);
    }

    public function test_un_correo_invalido_se_rechaza(): void
    {
        $this->from(route('contact'))
            ->post(route('contact.send'), $this->datosContacto(['email' => 'no-es-correo']))
            ->assertSessionHasErrors(['email' => 'Escribe un correo electrónico válido.'])
            ->assertSessionDoesntHaveErrors(['name', 'message']);
    }

    public function test_un_mensaje_de_2001_caracteres_se_rechaza(): void
    {
        $this->from(route('contact'))
            ->post(route('contact.send'), $this->datosContacto(['message' => str_repeat('a', 2001)]))
            ->assertSessionHasErrors('message');
    }

    public function test_un_mensaje_de_2000_caracteres_con_saltos_crlf_se_acepta(): void
    {
        // 2000 caracteres con saltos \n; con \r\n serían 2399 y sin normalizar se rechazaría.
        $mensaje = str_repeat("abcd\r\n", 399).'abcde';

        $this->from(route('contact'))
            ->post(route('contact.send'), $this->datosContacto(['message' => $mensaje]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', self::MENSAJE_HONESTO);
    }

    public function test_los_errores_quedan_asociados_a_su_campo(): void
    {
        $this->from(route('contact'))
            ->followingRedirects()
            ->post(route('contact.send'), $this->datosContacto(['email' => 'no-es-correo']))
            ->assertOk()
            ->assertSee('aria-invalid="true" aria-describedby="error-email"', false)
            ->assertSee('<p id="error-email" class="error">Escribe un correo electrónico válido.</p>', false)
            ->assertDontSee('aria-describedby="error-name"', false);
    }

    public function test_lo_enviado_vuelve_escapado_en_old(): void
    {
        $this->from(route('contact'))
            ->followingRedirects()
            ->post(route('contact.send'), $this->datosContacto([
                'name' => '<script>alert("nombre")</script>',
                'email' => 'no-es-correo',
                'message' => '</textarea><script>alert("mensaje")</script>',
            ]))
            ->assertOk()
            ->assertDontSee('<script>alert', false)
            ->assertSee('&lt;script&gt;alert(&quot;nombre&quot;)', false)
            ->assertSee('&lt;/textarea&gt;&lt;script&gt;alert(&quot;mensaje&quot;)', false);
    }

    public function test_el_envio_numero_11_en_un_minuto_responde_429(): void
    {
        foreach (range(1, 10) as $intento) {
            $this->post(route('contact.send'), $this->datosContacto())->assertStatus(302);
        }

        $this->post(route('contact.send'), $this->datosContacto())->assertStatus(429);
    }
}
