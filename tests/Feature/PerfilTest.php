<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Noticia;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * NP-36 paso 2: "Mi perfil" (ver y editar nombre, correo y biografía).
 */
class PerfilTest extends TestCase
{
    use RefreshDatabase;

    /** Contraseña de UserFactory. */
    private const CLAVE = 'password';

    /** Datos válidos que no cambian nada del usuario. */
    private function datosDe(User $usuario, array $cambios = []): array
    {
        return array_merge([
            'name' => $usuario->name,
            'email' => $usuario->email,
        ], $cambios);
    }

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

    /** Devuelve solo el HTML de una etiqueta del layout (header o aside). */
    private function seccion(string $html, string $etiqueta): string
    {
        $this->assertSame(1, preg_match("#<{$etiqueta}\b.*?</{$etiqueta}>#s", $html, $coincidencia));

        return $coincidencia[0];
    }

    private function crearToken(string $email): void
    {
        DB::table('password_reset_tokens')->insert([
            'email' => $email,
            'token' => Hash::make('token-de-prueba'),
            'created_at' => now(),
        ]);
    }

    // ----- Acceso -----

    public function test_un_visitante_no_puede_ver_ni_editar_el_perfil(): void
    {
        $this->get(route('perfil.edit'))->assertRedirect(route('login'));
        $this->patch(route('perfil.update'), ['name' => 'X', 'email' => 'x@x.com'])->assertRedirect(route('login'));
    }

    // ----- Ver -----

    public function test_muestra_nombre_correo_rol_y_fecha_de_registro(): void
    {
        $usuario = User::factory()->editor()->create([
            'name' => 'Ana Editora',
            'email' => 'ana@example.com',
        ]);
        $usuario->forceFill(['created_at' => Carbon::create(2026, 3, 7, 23, 30)])->saveQuietly();

        $this->actingAs($usuario)->get(route('perfil.edit'))
            ->assertOk()
            ->assertSee('<title>Mi perfil · Nexus Plays</title>', false)
            ->assertSee('Ana Editora')
            ->assertSee('ana@example.com')
            ->assertSee('<span class="chip">Editor</span>', false)
            ->assertSee('Miembro desde')
            ->assertSee('07/03/2026')
            ->assertDontSee('23:30');
    }

    public function test_un_nombre_con_html_se_muestra_escapado(): void
    {
        $usuario = User::factory()->lector()->create(['name' => '<script>alert("x")</script>']);

        $html = $this->actingAs($usuario)->get(route('perfil.edit'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
    }

    public function test_el_formulario_usa_patch_csrf_y_campos_accesibles(): void
    {
        $usuario = User::factory()->editor()->create();

        $html = $this->actingAs($usuario)->get(route('perfil.edit'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<form method="POST" action="'.preg_quote(route('perfil.update'), '#').'"#', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('name="_method" value="PATCH"', $html);

        foreach (['name', 'email', 'bio', 'current_password'] as $campo) {
            $this->assertStringContainsString('<label for="'.$campo.'">', $html);
            $this->assertStringContainsString('id="'.$campo.'"', $html);
        }

        $this->assertMatchesRegularExpression('#<input id="current_password" type="password"[^>]*autocomplete="current-password"#', $html);
        $this->assertStringContainsString('Solo hace falta si cambias el correo.', $html);
        $this->assertMatchesRegularExpression('#<textarea id="bio"[^>]*maxlength="300"[^>]*data-contador="contador-bio"#', $html);
    }

    public function test_los_errores_marcan_el_campo_con_aria(): void
    {
        $usuario = User::factory()->lector()->create();

        $html = $this->actingAs($usuario)
            ->from(route('perfil.edit'))
            ->followingRedirects()
            ->patch(route('perfil.update'), ['name' => '', 'email' => $usuario->email])
            ->getContent();

        $this->assertMatchesRegularExpression('#<input id="name"[^>]*aria-invalid="true" aria-describedby="error-name"#', $html);
        $this->assertStringContainsString('<p id="error-name" class="error">Escribe tu nombre.</p>', $html);
    }

    // ----- Nombre -----

    public function test_actualiza_el_nombre_sin_contrasena_y_lo_audita(): void
    {
        $usuario = User::factory()->lector()->create(['name' => 'Nombre Viejo']);

        $this->actingAs($usuario)
            ->patch(route('perfil.update'), $this->datosDe($usuario, ['name' => 'Nombre Nuevo']))
            ->assertRedirect(route('perfil.edit'))
            ->assertSessionHas('status', 'Tu perfil se actualizó.')
            ->assertSessionHasNoErrors();

        $this->assertSame('Nombre Nuevo', $usuario->fresh()->name);

        $registro = AuditLog::latest('id')->firstOrFail();
        $this->assertSame('editar', $registro->action);
        $this->assertSame($usuario->id, $registro->auditable_id);
        $this->assertSame($usuario->id, $registro->user_id);
        $this->assertSame(['name' => 'Nombre Viejo'], $registro->old_values);
        $this->assertSame(['name' => 'Nombre Nuevo'], $registro->new_values);
    }

    public function test_el_mensaje_de_estado_se_muestra_en_la_pagina(): void
    {
        $usuario = User::factory()->lector()->create();

        $this->actingAs($usuario)
            ->followingRedirects()
            ->patch(route('perfil.update'), $this->datosDe($usuario, ['name' => 'Otro Nombre']))
            ->assertOk()
            ->assertSee('<p class="aviso-exito" role="status">Tu perfil se actualizó.</p>', false);
    }

    public function test_sin_cambios_no_crea_fila_de_auditoria(): void
    {
        $usuario = User::factory()->editor()->create(['bio' => null]);
        $total = AuditLog::count();

        $this->actingAs($usuario)
            ->patch(route('perfil.update'), $this->datosDe($usuario, ['bio' => '   ']))
            ->assertRedirect(route('perfil.edit'))
            ->assertSessionHas('status', 'Tu perfil se actualizó.');

        $this->assertSame($total, AuditLog::count());
    }

    // ----- Correo -----

    public function test_cambiar_el_correo_sin_contrasena_da_error(): void
    {
        $usuario = User::factory()->lector()->create(['email' => 'actual@example.com']);

        $this->actingAs($usuario)
            ->from(route('perfil.edit'))
            ->patch(route('perfil.update'), $this->datosDe($usuario, ['email' => 'nuevo@example.com']))
            ->assertRedirect(route('perfil.edit'))
            ->assertSessionHasErrors(['current_password' => 'Escribe tu contraseña actual para cambiar el correo.']);

        $this->assertSame('actual@example.com', $usuario->fresh()->email);
    }

    public function test_cambiar_el_correo_con_contrasena_incorrecta_da_error(): void
    {
        $usuario = User::factory()->lector()->create(['email' => 'actual@example.com']);

        $this->actingAs($usuario)
            ->patch(route('perfil.update'), $this->datosDe($usuario, [
                'email' => 'nuevo@example.com',
                'current_password' => 'no-es-esta',
            ]))
            ->assertSessionHasErrors(['current_password' => 'La contraseña actual no es correcta.']);

        $this->assertSame('actual@example.com', $usuario->fresh()->email);
    }

    public function test_cambiar_el_correo_con_la_contrasena_correcta_lo_guarda_en_minusculas(): void
    {
        $usuario = User::factory()->lector()->create(['email' => 'actual@example.com']);

        $this->actingAs($usuario)
            ->patch(route('perfil.update'), $this->datosDe($usuario, [
                'email' => '  Nuevo.Correo@Example.COM ',
                'current_password' => self::CLAVE,
            ]))
            ->assertRedirect(route('perfil.edit'))
            ->assertSessionHasNoErrors();

        $this->assertSame('nuevo.correo@example.com', $usuario->fresh()->email);

        $registro = AuditLog::latest('id')->firstOrFail();
        $this->assertSame('editar', $registro->action);
        $this->assertSame(['email' => 'actual@example.com'], $registro->old_values);
        $this->assertSame(['email' => 'nuevo.correo@example.com'], $registro->new_values);
    }

    public function test_un_correo_de_otra_cuenta_da_error(): void
    {
        User::factory()->create(['email' => 'ocupado@example.com']);
        $usuario = User::factory()->lector()->create(['email' => 'actual@example.com']);

        $this->actingAs($usuario)
            ->patch(route('perfil.update'), $this->datosDe($usuario, [
                'email' => 'OCUPADO@example.com',
                'current_password' => self::CLAVE,
            ]))
            ->assertSessionHasErrors(['email' => 'Ese correo ya está registrado en otra cuenta.']);

        $this->assertSame('actual@example.com', $usuario->fresh()->email);
    }

    public function test_su_propio_correo_en_mayusculas_no_da_error_ni_pide_contrasena(): void
    {
        $usuario = User::factory()->lector()->create(['email' => 'actual@example.com']);

        $this->actingAs($usuario)
            ->patch(route('perfil.update'), $this->datosDe($usuario, ['email' => ' ACTUAL@Example.com ']))
            ->assertRedirect(route('perfil.edit'))
            ->assertSessionHasNoErrors();

        $this->assertSame('actual@example.com', $usuario->fresh()->email);
    }

    public function test_si_el_correo_no_cambia_la_contrasena_enviada_se_ignora(): void
    {
        $usuario = User::factory()->lector()->create();

        $this->actingAs($usuario)
            ->patch(route('perfil.update'), $this->datosDe($usuario, [
                'name' => 'Nombre Nuevo',
                'current_password' => 'incorrecta',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Nombre Nuevo', $usuario->fresh()->name);
    }

    public function test_al_cambiar_el_correo_se_borran_los_tokens_del_correo_anterior(): void
    {
        $usuario = User::factory()->lector()->create(['email' => 'actual@example.com']);
        $otro = User::factory()->create(['email' => 'otro@example.com']);
        $this->crearToken('actual@example.com');
        $this->crearToken($otro->email);

        $this->actingAs($usuario)
            ->patch(route('perfil.update'), $this->datosDe($usuario, [
                'email' => 'nuevo@example.com',
                'current_password' => self::CLAVE,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'actual@example.com']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'otro@example.com']);
    }

    public function test_si_el_correo_no_cambia_no_se_borran_sus_tokens(): void
    {
        $usuario = User::factory()->lector()->create(['email' => 'actual@example.com']);
        $this->crearToken('actual@example.com');

        $this->actingAs($usuario)
            ->patch(route('perfil.update'), $this->datosDe($usuario, ['name' => 'Otro Nombre']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'actual@example.com']);
    }

    // ----- Campos protegidos -----

    public function test_los_campos_protegidos_se_ignoran(): void
    {
        $usuario = User::factory()->lector()->create();
        $antes = $usuario->fresh();
        $admin = User::factory()->admin()->create();

        $this->actingAs($usuario)
            ->patch(route('perfil.update'), $this->datosDe($usuario, [
                'role_id' => $admin->role_id,
                'is_active' => '0',
                'legal_version' => 'falsa',
                'terms_accepted_at' => '2000-01-01 00:00:00',
                'privacy_accepted_at' => '2000-01-01 00:00:00',
                'password' => 'OtraClave#Segura123',
                'password_confirmation' => 'OtraClave#Segura123',
            ]))
            ->assertRedirect(route('perfil.edit'));

        $despues = $usuario->fresh();
        $this->assertSame($antes->role_id, $despues->role_id);
        $this->assertTrue($despues->is_active);
        $this->assertSame($antes->legal_version, $despues->legal_version);
        $this->assertEquals($antes->terms_accepted_at, $despues->terms_accepted_at);
        $this->assertEquals($antes->privacy_accepted_at, $despues->privacy_accepted_at);
        $this->assertSame($antes->password, $despues->password);
        $this->assertTrue(Hash::check(self::CLAVE, $despues->password));
    }

    // ----- Biografía -----

    public function test_editor_y_admin_guardan_su_biografia(): void
    {
        foreach ([User::factory()->editor()->create(), User::factory()->admin()->create()] as $usuario) {
            $this->actingAs($usuario)
                ->patch(route('perfil.update'), $this->datosDe($usuario, ['bio' => "  Escribo sobre RPG.\r\nY estrategia.  "]))
                ->assertSessionHasNoErrors();

            $this->assertSame("Escribo sobre RPG.\nY estrategia.", $usuario->fresh()->bio);
        }
    }

    public function test_un_lector_no_ve_la_biografia_y_si_la_envia_se_ignora(): void
    {
        $usuario = User::factory()->lector()->create();

        $html = $this->actingAs($usuario)->get(route('perfil.edit'))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="bio"', $html);
        $this->assertStringNotContainsString('Biografía', $html);

        $this->actingAs($usuario)
            ->patch(route('perfil.update'), $this->datosDe($usuario, ['bio' => 'Intento de bio']))
            ->assertRedirect(route('perfil.edit'))
            ->assertSessionHasNoErrors();

        $this->assertNull($usuario->fresh()->bio);
    }

    public function test_biografia_de_300_se_acepta_y_de_301_se_rechaza(): void
    {
        $usuario = User::factory()->editor()->create();

        $this->actingAs($usuario)
            ->patch(route('perfil.update'), $this->datosDe($usuario, ['bio' => str_repeat('á', 300)]))
            ->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('á', 300), $usuario->fresh()->bio);

        $this->actingAs($usuario)
            ->patch(route('perfil.update'), $this->datosDe($usuario, ['bio' => str_repeat('b', 301)]))
            ->assertSessionHasErrors(['bio' => 'La biografía no puede tener más de 300 caracteres.']);
        $this->assertSame(str_repeat('á', 300), $usuario->fresh()->bio);
    }

    public function test_biografia_de_300_con_saltos_crlf_se_acepta(): void
    {
        $usuario = User::factory()->editor()->create();
        // 100 líneas de 2 letras + 99 saltos = 299 caracteres con \n (398 con \r\n). Más 1 = 300.
        $lineas = array_fill(0, 100, 'ab');
        $bio = implode("\r\n", $lineas).'c';
        $this->assertSame(300, mb_strlen(str_replace("\r\n", "\n", $bio)));

        $this->actingAs($usuario)
            ->patch(route('perfil.update'), $this->datosDe($usuario, ['bio' => $bio]))
            ->assertSessionHasNoErrors();

        $this->assertSame(str_replace("\r\n", "\n", $bio), $usuario->fresh()->bio);
    }

    public function test_la_biografia_se_muestra_escapada_en_el_detalle_de_la_noticia(): void
    {
        $editor = User::factory()->editor()->create(['bio' => '<script>alert(1)</script> Fan de los RPG']);
        $noticia = Noticia::factory()->for($editor)->create();

        $html = $this->get(route('noticias.show', $noticia))->assertOk()->getContent();

        $this->assertStringContainsString('<p class="detalle-autor-bio">&lt;script&gt;alert(1)&lt;/script&gt; Fan de los RPG</p>', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_sin_biografia_no_aparece_el_parrafo(): void
    {
        $noticia = Noticia::factory()->for(User::factory()->editor()->create(['bio' => null]))->create();

        $this->get(route('noticias.show', $noticia))
            ->assertOk()
            ->assertDontSee('detalle-autor-bio');
    }

    public function test_la_biografia_no_agrega_consultas_al_detalle(): void
    {
        $conBio = Noticia::factory()->for(User::factory()->editor()->create(['bio' => 'Tengo bio']))->create();
        $sinBio = Noticia::factory()->for(User::factory()->editor()->create(['bio' => null]))->create();

        $contar = function (Noticia $noticia): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('noticias.show', $noticia))->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $this->assertSame($contar($sinBio), $contar($conBio));
    }

    // ----- Seguridad de la petición -----

    public function test_sin_token_csrf_responde_419(): void
    {
        $this->activarCsrfReal();
        $usuario = User::factory()->lector()->create(['name' => 'Sin Cambios']);

        $this->actingAs($usuario)
            ->patch(route('perfil.update'), $this->datosDe($usuario, ['name' => 'Cambiado']))
            ->assertStatus(419);

        $this->assertSame('Sin Cambios', $usuario->fresh()->name);
    }

    public function test_la_peticion_11_en_un_minuto_responde_429(): void
    {
        $usuario = User::factory()->lector()->create();

        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($usuario)
                ->patch(route('perfil.update'), $this->datosDe($usuario))
                ->assertRedirect(route('perfil.edit'));
        }

        $this->actingAs($usuario)
            ->patch(route('perfil.update'), $this->datosDe($usuario))
            ->assertStatus(429);
    }

    // ----- Navegación -----

    public function test_el_nombre_del_header_enlaza_al_perfil(): void
    {
        $usuario = User::factory()->lector()->create(['name' => 'Ana Lectora']);

        $header = $this->seccion($this->actingAs($usuario)->get(route('home'))->getContent(), 'header');
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('perfil.edit'), '#').'" class="sesion-perfil"\s*>\s*<span class="sesion-avatar" aria-hidden="true">A</span>\s*<span class="sesion-nombre">Ana Lectora</span>\s*</a>#',
            $header
        );
        // Dashboard y Cerrar sesión siguen ahí.
        $this->assertStringContainsString('href="'.route('dashboard').'"', $header);
        $this->assertStringContainsString('Cerrar sesión', $header);

        $enPerfil = $this->seccion($this->actingAs($usuario)->get(route('perfil.edit'))->getContent(), 'header');
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('perfil.edit'), '#').'" class="sesion-perfil"\s+aria-current="page"\s*>#', $enPerfil);
    }

    public function test_mi_cuenta_tiene_mi_perfil_con_etiqueta_y_marca_la_pagina_actual(): void
    {
        $usuario = User::factory()->lector()->create();
        $patron = '#<ul class="menu-lista" aria-labelledby="menu-cuenta">(.*?)</ul>#s';

        $barra = $this->seccion($this->actingAs($usuario)->get(route('home'))->getContent(), 'aside');
        $this->assertSame(1, preg_match($patron, $barra, $cuenta));
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('dashboard'), '#').'".*?<a href="'.preg_quote(route('perfil.edit'), '#').'"#s', $cuenta[1]);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('perfil.edit'), '#').'" class="menu-enlace"\s+data-etiqueta="Mi perfil">#', $cuenta[1]);
        $this->assertStringContainsString('<span class="menu-texto">Mi perfil</span>', $cuenta[1]);

        $barra = $this->seccion($this->actingAs($usuario)->get(route('perfil.edit'))->getContent(), 'aside');
        $this->assertSame(1, preg_match($patron, $barra, $cuenta));
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('perfil.edit'), '#').'" class="menu-enlace menu-enlace-activo"\s+aria-current="page"\s+data-etiqueta="Mi perfil">#',
            $cuenta[1]
        );
        $this->assertSame(1, substr_count($barra, 'aria-current="page"'));
    }

    public function test_la_vista_no_tiene_codigo_en_linea(): void
    {
        foreach ([User::factory()->lector()->create(), User::factory()->editor()->create()] as $usuario) {
            $html = $this->actingAs($usuario)->get(route('perfil.edit'))->assertOk()->getContent();

            $this->assertStringNotContainsString('style=', $html);
            $this->assertDoesNotMatchRegularExpression('#\son[a-z]+=#i', $html);
            $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#i', $html);
        }
    }
}
