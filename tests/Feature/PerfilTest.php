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
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NP-36 pasos 2 y 2b: "Mi perfil" con pestañas, filas de solo lectura,
 * un formulario por campo (nombre y biografía) y el correo bloqueado.
 */
class PerfilTest extends TestCase
{
    use RefreshDatabase;

    /** Contraseña de UserFactory. */
    private const CLAVE = 'password';

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

    /** Devuelve el HTML de la primera etiqueta indicada (header, aside, main...). */
    private function seccion(string $html, string $etiqueta): string
    {
        $this->assertSame(1, preg_match("#<{$etiqueta}\b.*?</{$etiqueta}>#s", $html, $coincidencia));

        return $coincidencia[0];
    }

    /** Devuelve una <section> de la página por su clase. */
    private function tarjeta(string $html, string $clase): string
    {
        $this->assertSame(1, preg_match('#<section class="'.$clase.'".*?</section>#s', $html, $coincidencia));

        return $coincidencia[0];
    }

    /** Devuelve la etiqueta de apertura del <details> que contiene el formulario de una ruta. */
    private function detailsDe(string $html, string $ruta): string
    {
        $this->assertSame(
            1,
            preg_match('#(<details\b[^>]*>)(?:(?!</details>).)*action="'.preg_quote(route($ruta), '#').'"#s', $html, $coincidencia)
        );

        return $coincidencia[1];
    }

    private function paginaDe(User $usuario): string
    {
        return $this->actingAs($usuario)->get(route('perfil.edit'))->assertOk()->getContent();
    }

    // ----- Acceso y rutas -----

    public function test_un_visitante_va_al_login(): void
    {
        $this->get(route('perfil.edit'))->assertRedirect(route('login'));
        $this->patch(route('perfil.nombre'), ['name' => 'X'])->assertRedirect(route('login'));
        $this->patch(route('perfil.biografia'), ['bio' => 'X'])->assertRedirect(route('login'));
    }

    public function test_patch_a_perfil_ya_no_existe(): void
    {
        // GET /perfil sigue existiendo, así que Laravel responde 405 (método no permitido).
        $usuario = User::factory()->lector()->create(['name' => 'Sin Cambios']);

        $this->actingAs($usuario)
            ->patch('/perfil', ['name' => 'Cambiado'])
            ->assertStatus(405);

        $this->assertSame('Sin Cambios', $usuario->fresh()->name);
        $this->assertFalse(Route::has('perfil.update'));
    }

    // ----- Ver -----

    public function test_muestra_nombre_rol_descripcion_y_fecha_de_registro(): void
    {
        $usuario = User::factory()->editor()->create(['name' => 'Ana Editora']);
        $usuario->forceFill(['created_at' => Carbon::create(2026, 3, 7, 23, 30)])->saveQuietly();
        $descripcion = $usuario->role->description;
        $this->assertNotEmpty($descripcion);

        $html = $this->paginaDe($usuario);

        $this->assertStringContainsString('<title>Mi perfil · Nexus Plays</title>', $html);
        $this->assertStringContainsString('<p class="pagina-etiqueta">MI CUENTA</p>', $html);
        $this->assertStringContainsString('<h1 class="pagina-titulo">Mi perfil</h1>', $html);

        $cabecera = $this->tarjeta($html, 'perfil-cabecera');
        $this->assertStringContainsString('Ana Editora', $cabecera);
        $this->assertStringContainsString('<span class="perfil-avatar" aria-hidden="true">A</span>', $cabecera);
        $this->assertStringContainsString('<span class="chip">Editor</span>', $cabecera);
        $this->assertStringContainsString('Miembro desde', $cabecera);
        $this->assertStringContainsString('07/03/2026', $cabecera);
        $this->assertStringNotContainsString('23:30', $html);

        $this->assertStringContainsString(e($descripcion), $html);
        // NP-39: la tarjeta ya ofrece subir la foto (los detalles, en PerfilFotoVistaTest).
        $this->assertStringContainsString('Subir foto', $cabecera);
    }

    public function test_el_correo_aparece_una_vez_bloqueado_y_sin_formulario(): void
    {
        $usuario = User::factory()->lector()->create(['email' => 'lectora.con.correo.largo@example.com']);

        $html = $this->paginaDe($usuario);

        $this->assertSame(1, substr_count($html, 'lectora.con.correo.largo@example.com'));
        $this->assertStringNotContainsString('lectora.con.correo.largo@example.com', $this->seccion($html, 'header'));
        $this->assertStringNotContainsString('lectora.con.correo.largo@example.com', $this->tarjeta($html, 'perfil-cabecera'));

        $this->assertStringContainsString(
            'Con este correo inicias sesión y recuperas tu contraseña. Para cambiarlo, escríbenos por <a href="'.route('contact').'">Contacto</a>.',
            $html
        );
        $this->assertMatchesRegularExpression('#<svg class="perfil-candado"[^>]*aria-hidden="true"#', $html);
        $this->assertStringNotContainsString('name="email"', $html);
        $this->assertStringNotContainsString('name="current_password"', $html);
    }

    public function test_un_nombre_con_html_se_muestra_escapado(): void
    {
        $usuario = User::factory()->lector()->create(['name' => '<script>alert("x")</script>']);

        $html = $this->paginaDe($usuario);

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
    }

    public function test_el_formulario_del_nombre_es_accesible_y_esta_cerrado(): void
    {
        $html = $this->paginaDe(User::factory()->lector()->create());

        $this->assertStringNotContainsString('open', $this->detailsDe($html, 'perfil.nombre'));
        $this->assertMatchesRegularExpression('#<summary class="boton boton-secundario boton-sm">Editar<span class="sr-only"> nombre</span></summary>#', $html);
        $this->assertStringContainsString('name="_method" value="PATCH"', $html);
        $this->assertStringContainsString('<label for="name">Nombre</label>', $html);
        $this->assertMatchesRegularExpression('#<input id="name" type="text" name="name"[^>]*required maxlength="100" autocomplete="name"#', $html);
        $this->assertStringContainsString('<a href="'.route('perfil.edit').'" class="boton boton-secundario boton-sm">Cancelar</a>', $html);
    }

    // ----- Pestañas -----

    public function test_las_pestanas_muestran_las_que_existen_y_marcan_la_activa(): void
    {
        $html = $this->paginaDe(User::factory()->lector()->create());

        // Cada paso agrega su ruta y la pestaña aparece sola: paso 3 Seguridad, paso 5 Actividad, paso 6 Cuenta.
        $this->assertSame(1, preg_match('#<nav class="pestanas" aria-label="Secciones de mi perfil">(.*?)</nav>#s', $html, $nav));
        $this->assertSame(4, substr_count($nav[1], '<a '));
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('perfil.edit'), '#').'" class="pestana pestana-activa"\s+aria-current="page"\s*>Perfil</a>#',
            $nav[1]
        );
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('perfil.seguridad'), '#').'" class="pestana"\s*>Seguridad</a>#', $nav[1]);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('perfil.actividad'), '#').'" class="pestana"\s*>Actividad</a>#', $nav[1]);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('perfil.cuenta'), '#').'" class="pestana"\s*>Cuenta</a>#', $nav[1]);
        $this->assertSame(1, substr_count($nav[1], 'aria-current'));
    }

    public function test_el_parcial_de_pestanas_omite_rutas_que_no_existen(): void
    {
        $html = view('perfil._pestanas', ['pestanas' => [
            ['ruta.que.no.existe', 'Fantasma'],
            ['home', 'Inicio'],
        ]])->render();

        $this->assertStringNotContainsString('Fantasma', $html);
        $this->assertStringContainsString('href="'.route('home').'"', $html);
        $this->assertStringNotContainsString('aria-current', $html);
    }

    // ----- Nombre -----

    public function test_guarda_el_nombre_lo_muestra_y_lo_audita(): void
    {
        $usuario = User::factory()->lector()->create(['name' => 'Nombre Viejo']);

        $this->actingAs($usuario)
            ->patch(route('perfil.nombre'), ['name' => '  Nombre Nuevo  '])
            ->assertRedirect(route('perfil.edit'))
            ->assertSessionHas('status', 'Nombre actualizado.')
            ->assertSessionHasNoErrors();

        $this->assertSame('Nombre Nuevo', $usuario->fresh()->name);

        $registro = AuditLog::latest('id')->firstOrFail();
        $this->assertSame('editar', $registro->action);
        $this->assertSame($usuario->id, $registro->auditable_id);
        $this->assertSame($usuario->id, $registro->user_id);
        $this->assertSame(['name' => 'Nombre Viejo'], $registro->old_values);
        $this->assertSame(['name' => 'Nombre Nuevo'], $registro->new_values);

        $this->actingAs($usuario->fresh())
            ->get(route('perfil.edit'))
            ->assertSee('<p class="aviso-exito" role="status">Nombre actualizado.</p>', false);
    }

    public static function nombresInvalidos(): array
    {
        return [
            'vacío' => ['', 'Escribe tu nombre.'],
            'solo espacios' => ['   ', 'Escribe tu nombre.'],
            '101 caracteres' => [str_repeat('n', 101), 'El nombre no puede tener más de 100 caracteres.'],
        ];
    }

    #[DataProvider('nombresInvalidos')]
    public function test_un_nombre_invalido_da_error_y_abre_su_details(string $nombre, string $mensaje): void
    {
        $usuario = User::factory()->editor()->create(['name' => 'Sin Cambios']);

        $html = $this->actingAs($usuario)
            ->from(route('perfil.edit'))
            ->followingRedirects()
            ->patch(route('perfil.nombre'), ['name' => $nombre])
            ->assertOk()
            ->getContent();

        $this->assertSame('Sin Cambios', $usuario->fresh()->name);
        $this->assertMatchesRegularExpression('#<details class="perfil-editar"\s+open\s*>#', $this->detailsDe($html, 'perfil.nombre'));
        $this->assertStringNotContainsString('open', $this->detailsDe($html, 'perfil.biografia'));
        $this->assertMatchesRegularExpression('#<input id="name"[^>]*aria-invalid="true" aria-describedby="error-name"#', $html);
        $this->assertStringContainsString('<p id="error-name" class="error">'.e($mensaje).'</p>', $html);
    }

    public function test_nombre_ignora_los_demas_campos(): void
    {
        $usuario = User::factory()->editor()->create(['bio' => 'Bio original', 'email' => 'editor@example.com']);
        $antes = $usuario->fresh();
        $admin = User::factory()->admin()->create();

        $this->actingAs($usuario)
            ->patch(route('perfil.nombre'), [
                'name' => 'Nombre Nuevo',
                'email' => 'otro@example.com',
                'role_id' => $admin->role_id,
                'is_active' => '0',
                'bio' => 'Bio cambiada',
                'legal_version' => 'falsa',
                'terms_accepted_at' => '2000-01-01 00:00:00',
                'privacy_accepted_at' => '2000-01-01 00:00:00',
                'password' => 'OtraClave#Segura123',
                'password_confirmation' => 'OtraClave#Segura123',
            ])
            ->assertRedirect(route('perfil.edit'));

        $despues = $usuario->fresh();
        $this->assertSame('Nombre Nuevo', $despues->name);
        $this->assertSame('editor@example.com', $despues->email);
        $this->assertSame($antes->role_id, $despues->role_id);
        $this->assertTrue($despues->is_active);
        $this->assertSame('Bio original', $despues->bio);
        $this->assertSame($antes->legal_version, $despues->legal_version);
        $this->assertEquals($antes->terms_accepted_at, $despues->terms_accepted_at);
        $this->assertEquals($antes->privacy_accepted_at, $despues->privacy_accepted_at);
        $this->assertSame($antes->password, $despues->password);
        $this->assertTrue(Hash::check(self::CLAVE, $despues->password));
    }

    public function test_nombre_sin_cambios_no_crea_fila_de_auditoria(): void
    {
        $usuario = User::factory()->lector()->create(['name' => 'Igual']);
        $total = AuditLog::count();

        $this->actingAs($usuario)
            ->patch(route('perfil.nombre'), ['name' => 'Igual'])
            ->assertRedirect(route('perfil.edit'))
            ->assertSessionHas('status', 'Nombre actualizado.');

        $this->assertSame($total, AuditLog::count());
    }

    // ----- Biografía -----

    public function test_editor_y_admin_guardan_su_biografia(): void
    {
        foreach ([User::factory()->editor()->create(), User::factory()->admin()->create()] as $usuario) {
            $this->actingAs($usuario)
                ->patch(route('perfil.biografia'), ['bio' => "  Escribo sobre RPG.\r\nY estrategia.  "])
                ->assertRedirect(route('perfil.edit'))
                ->assertSessionHas('status', 'Biografía actualizada.')
                ->assertSessionHasNoErrors();

            $this->assertSame("Escribo sobre RPG.\nY estrategia.", $usuario->fresh()->bio);

            $registro = AuditLog::latest('id')->firstOrFail();
            $this->assertSame('editar', $registro->action);
            $this->assertSame(['bio' => "Escribo sobre RPG.\nY estrategia."], $registro->new_values);
        }
    }

    public function test_la_fila_de_biografia_muestra_el_valor_o_el_texto_vacio(): void
    {
        $sinBio = $this->paginaDe(User::factory()->editor()->create(['bio' => null]));
        $this->assertStringContainsString('<p class="perfil-fila-valor perfil-fila-vacia">Aún no has escrito tu biografía.</p>', $sinBio);
        $this->assertStringContainsString('Se muestra bajo tu nombre en las noticias que publicas.', $sinBio);
        $this->assertMatchesRegularExpression('#<textarea id="bio" name="bio"[^>]*maxlength="300"[^>]*data-contador="contador-bio"#', $sinBio);

        $conBio = $this->paginaDe(User::factory()->editor()->create(['bio' => "Línea <b>uno</b>\nLínea dos"]));
        $this->assertStringContainsString("<p class=\"perfil-fila-valor perfil-bio\">Línea &lt;b&gt;uno&lt;/b&gt;\nLínea dos</p>", $conBio);
        $this->assertStringNotContainsString('Aún no has escrito tu biografía.', $conBio);
    }

    public function test_un_lector_recibe_403_y_no_ve_la_fila_de_biografia(): void
    {
        $usuario = User::factory()->lector()->create();

        $html = $this->paginaDe($usuario);
        $this->assertStringNotContainsString('Biografía', $html);
        $this->assertStringNotContainsString('name="bio"', $html);
        $this->assertStringNotContainsString('action="'.route('perfil.biografia').'"', $html);

        $this->actingAs($usuario)
            ->patch(route('perfil.biografia'), ['bio' => 'Intento de bio'])
            ->assertForbidden();

        $this->assertNull($usuario->fresh()->bio);
    }

    public function test_biografia_ignora_los_demas_campos(): void
    {
        $usuario = User::factory()->editor()->create(['name' => 'Nombre Fijo', 'email' => 'fijo@example.com']);
        $rolAntes = $usuario->role_id;
        $admin = User::factory()->admin()->create();

        $this->actingAs($usuario)
            ->patch(route('perfil.biografia'), [
                'bio' => 'Nueva bio',
                'name' => 'Otro Nombre',
                'email' => 'otro@example.com',
                'role_id' => $admin->role_id,
            ])
            ->assertRedirect(route('perfil.edit'));

        $despues = $usuario->fresh();
        $this->assertSame('Nueva bio', $despues->bio);
        $this->assertSame('Nombre Fijo', $despues->name);
        $this->assertSame('fijo@example.com', $despues->email);
        $this->assertSame($rolAntes, $despues->role_id);
    }

    public function test_biografia_de_300_se_acepta(): void
    {
        $usuario = User::factory()->editor()->create();

        $this->actingAs($usuario)
            ->patch(route('perfil.biografia'), ['bio' => str_repeat('á', 300)])
            ->assertSessionHasNoErrors();

        $this->assertSame(str_repeat('á', 300), $usuario->fresh()->bio);
    }

    public function test_biografia_de_301_da_error_y_abre_su_details(): void
    {
        $usuario = User::factory()->editor()->create(['bio' => 'Original']);

        $html = $this->actingAs($usuario)
            ->from(route('perfil.edit'))
            ->followingRedirects()
            ->patch(route('perfil.biografia'), ['bio' => str_repeat('b', 301)])
            ->assertOk()
            ->getContent();

        $this->assertSame('Original', $usuario->fresh()->bio);
        $this->assertMatchesRegularExpression('#<details class="perfil-editar"\s+open\s*>#', $this->detailsDe($html, 'perfil.biografia'));
        $this->assertStringNotContainsString('open', $this->detailsDe($html, 'perfil.nombre'));
        $this->assertMatchesRegularExpression('#<textarea id="bio"[^>]*aria-invalid="true" aria-describedby="error-bio"#', $html);
        $this->assertStringContainsString('<p id="error-bio" class="error">La biografía no puede tener más de 300 caracteres.</p>', $html);
    }

    public function test_biografia_de_300_con_saltos_crlf_se_acepta(): void
    {
        $usuario = User::factory()->editor()->create();
        // 100 líneas "ab" unidas con \r\n + "c": 300 caracteres con \n (399 con \r\n).
        $bio = implode("\r\n", array_fill(0, 100, 'ab')).'c';
        $this->assertSame(300, mb_strlen(str_replace("\r\n", "\n", $bio)));

        $this->actingAs($usuario)
            ->patch(route('perfil.biografia'), ['bio' => $bio])
            ->assertSessionHasNoErrors();

        $this->assertSame(str_replace("\r\n", "\n", $bio), $usuario->fresh()->bio);
    }

    public function test_biografia_vacia_se_guarda_como_null(): void
    {
        $usuario = User::factory()->editor()->create(['bio' => 'Algo']);

        $this->actingAs($usuario)
            ->patch(route('perfil.biografia'), ['bio' => "  \r\n  "])
            ->assertSessionHasNoErrors();

        $this->assertNull($usuario->fresh()->bio);
    }

    public function test_biografia_sin_cambios_no_crea_fila_de_auditoria(): void
    {
        $usuario = User::factory()->editor()->create(['bio' => null]);
        $total = AuditLog::count();

        $this->actingAs($usuario)
            ->patch(route('perfil.biografia'), ['bio' => '   '])
            ->assertRedirect(route('perfil.edit'))
            ->assertSessionHas('status', 'Biografía actualizada.');

        $this->assertSame($total, AuditLog::count());
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

        $this->assertSame(
            $this->contarConsultas(fn () => $this->get(route('noticias.show', $sinBio))->assertOk()),
            $this->contarConsultas(fn () => $this->get(route('noticias.show', $conBio))->assertOk())
        );
    }

    // ----- Privacidad -----

    public function test_privacidad_muestra_version_y_fecha_de_aceptacion(): void
    {
        $usuario = User::factory()->lector()->create();
        $usuario->forceFill([
            'terms_accepted_at' => Carbon::create(2026, 11, 3, 22, 15),
            'privacy_accepted_at' => Carbon::create(2026, 11, 3, 22, 15),
            'legal_version' => '0-prueba',
        ])->saveQuietly();

        $privacidad = $this->tarjeta($this->paginaDe($usuario), 'perfil-tarjeta" aria-labelledby="perfil-privacidad-titulo');

        $this->assertStringContainsString(
            'Aceptaste los <a href="'.route('legal.terminos').'">Términos de uso</a> y la <a href="'.route('legal.privacidad').'">Política de privacidad</a> (versión 0-prueba) el <time datetime="2026-11-03">03/11/2026</time>.',
            $privacidad
        );
        $this->assertStringNotContainsString('22:15', $privacidad);
    }

    public function test_privacidad_sin_aceptacion_registrada_lo_dice_sin_inventar_fecha(): void
    {
        $usuario = User::factory()->lector()->create();
        $this->assertNull($usuario->fresh()->terms_accepted_at);

        $privacidad = $this->tarjeta($this->paginaDe($usuario), 'perfil-tarjeta" aria-labelledby="perfil-privacidad-titulo');

        $this->assertStringContainsString('Tu cuenta se creó antes de que existiera el registro de aceptación.', $privacidad);
        $this->assertStringNotContainsString('Aceptaste', $privacidad);
        $this->assertStringNotContainsString('<time', $privacidad);
    }

    // ----- Seguridad de la petición -----

    public static function rutasDeEdicion(): array
    {
        return [
            'nombre' => ['perfil.nombre', ['name' => 'Cambiado']],
            'biografía' => ['perfil.biografia', ['bio' => 'Cambiada']],
        ];
    }

    #[DataProvider('rutasDeEdicion')]
    public function test_sin_token_csrf_responde_419(string $ruta, array $datos): void
    {
        $this->activarCsrfReal();
        $usuario = User::factory()->editor()->create(['name' => 'Sin Cambios', 'bio' => null]);

        $this->actingAs($usuario)
            ->patch(route($ruta), $datos)
            ->assertStatus(419);

        $this->assertSame('Sin Cambios', $usuario->fresh()->name);
        $this->assertNull($usuario->fresh()->bio);
    }

    #[DataProvider('rutasDeEdicion')]
    public function test_la_peticion_11_en_un_minuto_responde_429(string $ruta, array $datos): void
    {
        $usuario = User::factory()->editor()->create();

        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($usuario)
                ->patch(route($ruta), $datos)
                ->assertRedirect(route('perfil.edit'));
        }

        $this->actingAs($usuario)
            ->patch(route($ruta), $datos)
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
        $this->assertStringContainsString('href="'.route('dashboard').'"', $header);
        $this->assertStringContainsString('Cerrar sesión', $header);

        $enPerfil = $this->seccion($this->paginaDe($usuario), 'header');
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

        $barra = $this->seccion($this->paginaDe($usuario), 'aside');
        $this->assertSame(1, preg_match($patron, $barra, $cuenta));
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('perfil.edit'), '#').'" class="menu-enlace menu-enlace-activo"\s+aria-current="page"\s+data-etiqueta="Mi perfil">#',
            $cuenta[1]
        );
        $this->assertSame(1, substr_count($barra, 'aria-current="page"'));
    }

    // ----- Calidad de la página -----

    public function test_la_vista_no_tiene_codigo_en_linea(): void
    {
        foreach ([User::factory()->lector()->create(), User::factory()->editor()->create()] as $usuario) {
            $html = $this->paginaDe($usuario);

            $this->assertStringNotContainsString('style=', $html);
            $this->assertDoesNotMatchRegularExpression('#\son[a-z]+=#i', $html);
            $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#i', $html);
        }
    }

    public function test_la_pagina_hace_un_numero_fijo_de_consultas(): void
    {
        $lector = User::factory()->lector()->create();
        $editor = User::factory()->editor()->create(['bio' => 'Bio']);
        $admin = User::factory()->admin()->create();
        // Datos que no deben cambiar nada: noticias y otros usuarios.
        Noticia::factory()->count(3)->for($editor)->create();
        User::factory()->count(5)->create();

        $consultas = array_map(
            fn (User $usuario) => $this->contarConsultas(fn () => $this->actingAs($usuario)->get(route('perfil.edit'))->assertOk()),
            [$lector, $editor, $admin]
        );

        $this->assertCount(1, array_unique($consultas), 'Consultas por usuario: '.implode(', ', $consultas));
        $this->assertLessThanOrEqual(4, $consultas[0]);
    }

    private function contarConsultas(callable $peticion): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $peticion();
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    }
}
