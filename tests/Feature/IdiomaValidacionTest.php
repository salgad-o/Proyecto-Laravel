<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NP-21 paso 1 (deuda 27): los mensajes estándar de Laravel salen en español,
 * con el nombre del campo traducido (lang/es/validation.php → attributes).
 */
class IdiomaValidacionTest extends TestCase
{
    use RefreshDatabase;

    private function datosRegistro(array $cambios = []): array
    {
        return array_merge([
            'name' => 'Persona de Prueba',
            'email' => 'persona@nexus.test',
            'password' => 'Clave-Segura-2026',
            'password_confirmation' => 'Clave-Segura-2026',
            'acepto_legal' => '1',
        ], $cambios);
    }

    public function test_el_idioma_de_la_aplicacion_es_espanol(): void
    {
        $this->assertSame('es', app()->getLocale());
        $this->assertSame('es', config('app.locale'));
    }

    public function test_un_correo_invalido_en_el_registro_sale_en_espanol(): void
    {
        $this->from(route('register'))
            ->post(route('register.store'), $this->datosRegistro(['email' => 'no-es-un-correo']))
            ->assertSessionHasErrors([
                // Mensaje estándar ("email") con el atributo traducido en lang/es.
                'email' => 'El campo correo electrónico debe ser un correo electrónico válido.',
            ]);
    }

    public function test_un_rol_inexistente_sale_en_espanol(): void
    {
        $admin = User::factory()->admin()->create();
        $usuario = User::factory()->lector()->create();

        $this->actingAs($admin)
            ->from(route('usuarios.index'))
            ->patch(route('usuarios.rol', $usuario), ['role_id' => 9999])
            ->assertSessionHasErrors(['role_id' => 'El valor seleccionado en rol no es válido.']);

        // Mostrado así en la página, sin rastro del mensaje en inglés.
        $this->actingAs($admin)
            ->get(route('usuarios.index'))
            ->assertSee('El valor seleccionado en rol no es válido.')
            ->assertDontSee('The selected');
    }

    public function test_un_campo_obligatorio_usa_el_nombre_traducido(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)
            ->from(route('noticias.create'))
            ->post(route('noticias.store'), ['title' => '', 'body' => 'Contenido', 'status' => 'borrador'])
            ->assertSessionHasErrors(['title' => 'El campo título es obligatorio.']);
    }

    public function test_las_reglas_de_password_salen_en_espanol(): void
    {
        // Mínimo de caracteres (Password::min(12)).
        $this->from(route('register'))
            ->post(route('register.store'), $this->datosRegistro(['password' => 'Ab1!', 'password_confirmation' => 'Ab1!']))
            ->assertSessionHasErrors(['password' => 'El campo contraseña debe tener al menos 12 caracteres.']);

        // Mayúsculas y minúsculas (mixedCase).
        $this->from(route('register'))
            ->post(route('register.store'), $this->datosRegistro(['password' => 'clave-segura-2026', 'password_confirmation' => 'clave-segura-2026']))
            ->assertSessionHasErrors(['password' => 'El campo contraseña debe contener al menos una letra mayúscula y una minúscula.']);
    }

    public function test_todas_las_reglas_de_password_estan_traducidas(): void
    {
        foreach (['letters', 'mixed', 'numbers', 'symbols', 'uncompromised'] as $regla) {
            $texto = __('validation.password.'.$regla, ['attribute' => 'contraseña']);

            $this->assertNotSame('validation.password.'.$regla, $texto);
            $this->assertStringContainsString('contraseña', $texto);
            $this->assertDoesNotMatchRegularExpression('/\b(The|field|must)\b/', $texto);
        }
    }

    public function test_el_espanol_traduce_todas_las_claves_del_ingles(): void
    {
        // Ninguna regla de lang/en se queda sin su versión en español.
        $en = require lang_path('en/validation.php');
        $es = require lang_path('es/validation.php');

        $faltan = array_diff_key(array_diff_key($en, ['custom' => 1, 'attributes' => 1]), $es);
        $this->assertSame([], array_keys($faltan));

        foreach (['between', 'gt', 'gte', 'lt', 'lte', 'max', 'min', 'size', 'password'] as $grupo) {
            $this->assertSame([], array_keys(array_diff_key($en[$grupo], $es[$grupo])), $grupo);
        }

        foreach (['auth', 'passwords', 'pagination'] as $archivo) {
            $this->assertSame(
                array_keys(require lang_path("en/{$archivo}.php")),
                array_keys(require lang_path("es/{$archivo}.php")),
                $archivo
            );
        }
    }

    public function test_el_correo_de_recuperacion_sale_en_espanol(): void
    {
        $usuario = User::factory()->lector()->create(['email' => 'lector@nexus.test']);

        $correo = (new ResetPassword('token-de-prueba'))->toMail($usuario);

        $this->assertSame('Restablece tu contraseña de Nexus Plays', $correo->subject);
        $this->assertSame('Restablecer contraseña', $correo->actionText);
        $this->assertStringContainsString('vence en', implode(' ', $correo->outroLines));

        // Textos de la plantilla de correo de Laravel (claves JSON en lang/es.json).
        // Se comprueban sin renderizar el HTML del correo, que es lento.
        $this->assertSame('¡Hola!', __('Hello!'));
        $this->assertSame('Saludos,', __('Regards,'));
        $this->assertStringStartsWith('Si no puedes pulsar el botón «Restablecer contraseña»', __(
            "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".'into your web browser:',
            ['actionText' => 'Restablecer contraseña']
        ));
    }

    public function test_los_mensajes_del_broker_de_contrasenas_estan_en_espanol(): void
    {
        $this->assertSame('No encontramos ningún usuario con ese correo electrónico.', __('passwords.user'));
        $this->assertSame('Estas credenciales no coinciden con nuestros registros.', __('auth.failed'));
    }
}
