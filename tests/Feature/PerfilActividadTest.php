<?php

namespace Tests\Feature;

use App\Models\Comentario;
use App\Models\Noticia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NP-36 paso 5: pestaña "Actividad" (contadores, mis noticias publicadas y mis comentarios).
 */
class PerfilActividadTest extends TestCase
{
    use RefreshDatabase;

    private function pagina(User $usuario, array $query = []): string
    {
        return $this->actingAs($usuario)
            ->get(route('perfil.actividad', $query))
            ->assertOk()
            ->getContent();
    }

    /** Devuelve una <section> por el id de su título. */
    private function tarjeta(string $html, string $idTitulo): ?string
    {
        return preg_match('#<section class="perfil-tarjeta" aria-labelledby="'.$idTitulo.'">.*?</section>#s', $html, $m) ? $m[0] : null;
    }

    private function comentar(User $usuario, ?Noticia $noticia = null, array $datos = []): Comentario
    {
        $comentario = new Comentario(['body' => $datos['body'] ?? 'Comentario de prueba']);
        $comentario->user_id = $usuario->id;
        $comentario->noticia_id = ($noticia ?? Noticia::factory()->create())->id;
        $comentario->save();

        if (isset($datos['created_at']) || isset($datos['edited_at'])) {
            $comentario->forceFill(array_intersect_key($datos, array_flip(['created_at', 'edited_at'])))->saveQuietly();
        }

        return $comentario;
    }

    /** Extractos de "Mis comentarios", en orden. */
    private function extractos(string $html): array
    {
        preg_match_all('#<p class="actividad-extracto">(.*?)</p>#s', $html, $m);

        return $m[1];
    }

    /** Títulos de "Mis noticias publicadas", en orden. */
    private function titulosDeNoticias(string $html): array
    {
        preg_match_all('#<a href="[^"]*" class="actividad-titulo">(.*?)</a>#s', $html, $m);

        return $m[1];
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

    // ----- Acceso y pestañas -----

    public function test_un_visitante_va_al_login(): void
    {
        $this->get(route('perfil.actividad'))->assertRedirect(route('login'));
    }

    public function test_las_pestanas_marcan_actividad(): void
    {
        $html = $this->pagina(User::factory()->lector()->create());

        $this->assertStringContainsString('<p class="pagina-etiqueta">MI CUENTA</p>', $html);
        $this->assertSame(1, preg_match('#<nav class="pestanas" aria-label="Secciones de mi perfil">(.*?)</nav>#s', $html, $nav));
        preg_match_all('#<a href="([^"]*)"[^>]*>([^<]*)</a>#', $nav[1], $enlaces);
        $this->assertSame(['Perfil', 'Seguridad', 'Actividad'], $enlaces[2]);
        $this->assertSame([route('perfil.edit'), route('perfil.seguridad'), route('perfil.actividad')], $enlaces[1]);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('perfil.actividad'), '#').'" class="pestana pestana-activa"\s+aria-current="page"\s*>Actividad</a>#', $nav[1]);
        $this->assertSame(1, substr_count($nav[1], 'aria-current'));
    }

    // ----- Solo datos propios -----

    public function test_solo_muestra_lo_del_usuario_y_user_id_en_la_url_no_cambia_nada(): void
    {
        $editor = User::factory()->editor()->create();
        $otro = User::factory()->editor()->create();
        Noticia::factory()->for($editor)->create(['title' => 'Noticia MIA']);
        Noticia::factory()->for($otro)->create(['title' => 'Noticia AJENA']);
        $this->comentar($editor, null, ['body' => 'Comentario MIO']);
        $this->comentar($otro, null, ['body' => 'Comentario AJENO']);

        foreach ([[], ['user_id' => $otro->id], ['user' => $otro->id, 'usuario' => $otro->id]] as $query) {
            $html = $this->pagina($editor, $query);

            $this->assertSame(['Noticia MIA'], $this->titulosDeNoticias($html));
            $this->assertSame(['Comentario MIO'], $this->extractos($html));
            $this->assertStringNotContainsString('AJENA', $html);
            $this->assertStringNotContainsString('AJENO', $html);
            $this->assertStringNotContainsString('user_id', $html);
        }
    }

    // ----- Roles -----

    public function test_un_lector_solo_ve_el_contador_de_comentarios_y_sus_comentarios(): void
    {
        $lector = User::factory()->lector()->create();
        $this->comentar($lector);

        $html = $this->pagina($lector);

        $this->assertMatchesRegularExpression('#<dt>Comentarios</dt>\s*<dd>1</dd>#', $html);
        $this->assertStringNotContainsString('Noticias publicadas', $html);
        $this->assertNull($this->tarjeta($html, 'actividad-noticias-titulo'));
        $this->assertNotNull($this->tarjeta($html, 'actividad-comentarios-titulo'));
        $this->assertStringNotContainsString('Tus borradores', $html);
    }

    public function test_editor_y_admin_ven_contadores_y_tarjeta_de_noticias(): void
    {
        foreach ([User::factory()->editor()->create(), User::factory()->admin()->create()] as $usuario) {
            Noticia::factory()->count(2)->for($usuario)->create();
            $this->comentar($usuario);

            $html = $this->pagina($usuario);

            $this->assertMatchesRegularExpression('#<dt>Comentarios</dt>\s*<dd>1</dd>#', $html);
            // 2 propias + 1 creada por el comentario (de otro autor): cuentan solo las propias.
            $this->assertMatchesRegularExpression('#<dt>Noticias publicadas</dt>\s*<dd>2</dd>#', $html);
            $this->assertNotNull($this->tarjeta($html, 'actividad-noticias-titulo'));
            $this->assertStringContainsString('Tus borradores estarán en el panel de administración.', $html);
        }
    }

    // ----- Noticias -----

    public function test_noticias_solo_publicadas_en_orden_y_con_su_numero_de_comentarios(): void
    {
        $editor = User::factory()->editor()->create();
        $mismaFecha = now()->subDays(2)->startOfMinute();
        $vieja = Noticia::factory()->for($editor)->create(['title' => 'Vieja', 'published_at' => now()->subDays(10)]);
        $empateA = Noticia::factory()->for($editor)->create(['title' => 'Empate A', 'published_at' => $mismaFecha]);
        $empateB = Noticia::factory()->for($editor)->create(['title' => 'Empate B', 'published_at' => $mismaFecha]);
        $reciente = Noticia::factory()->for($editor)->create(['title' => 'Reciente', 'published_at' => now()->subHour()]);
        Noticia::factory()->for($editor)->borrador()->create(['title' => 'Mi BORRADOR']);

        $lector = User::factory()->lector()->create();
        $this->comentar($lector, $empateA);
        $this->comentar($lector, $empateA);
        $this->comentar($lector, $reciente);

        $html = $this->pagina($editor);

        // published_at desc y, en empate, id desc.
        $this->assertSame(['Reciente', 'Empate B', 'Empate A', 'Vieja'], $this->titulosDeNoticias($html));
        $this->assertStringNotContainsString('Mi BORRADOR', $html);
        $this->assertMatchesRegularExpression('#<dt>Noticias publicadas</dt>\s*<dd>4</dd>#', $html);

        $noticias = $this->tarjeta($html, 'actividad-noticias-titulo');
        $this->assertStringContainsString('href="'.route('noticias.show', $reciente).'"', $noticias);
        $this->assertMatchesRegularExpression('#>Empate A</a>\s*<p class="actividad-meta">\s*<time datetime="[^"]+" title="[^"]+">[^<]+</time>\s*<span>2 comentarios</span>#', $noticias);
        $this->assertMatchesRegularExpression('#>Reciente</a>\s*<p class="actividad-meta">\s*<time[^>]*>[^<]+</time>\s*<span>1 comentario</span>#', $noticias);
        $this->assertMatchesRegularExpression('#>Vieja</a>\s*<p class="actividad-meta">\s*<time[^>]*>hace 1 semana</time>\s*<span>0 comentarios</span>#', $noticias);
    }

    public function test_sin_noticias_ni_comentarios_muestra_los_estados_vacios(): void
    {
        $html = $this->pagina(User::factory()->editor()->create(), ['noticias' => 1, 'comentarios' => 1]);

        $this->assertStringContainsString('Aún no has publicado noticias.', $html);
        $this->assertStringContainsString('Aún no has comentado ninguna noticia. <a href="'.route('noticias.index').'">Ver noticias</a>', $html);
        $this->assertMatchesRegularExpression('#<dt>Comentarios</dt>\s*<dd>0</dd>#', $html);
        $this->assertStringNotContainsString('class="paginacion"', $html);
    }

    // ----- Comentarios -----

    public function test_comentarios_del_mas_reciente_con_extracto_escapado_y_editado(): void
    {
        $lector = User::factory()->lector()->create();
        $largo = str_repeat('palabra ', 40); // 320 caracteres
        $this->comentar($lector, null, ['body' => 'El más viejo', 'created_at' => now()->subDays(3)]);
        $this->comentar($lector, null, ['body' => '<script>alert("x")</script> con <b>HTML</b>', 'created_at' => now()->subDays(2)]);
        $this->comentar($lector, null, ['body' => $largo, 'created_at' => now()->subDay(), 'edited_at' => now()->subHours(20)]);

        $html = $this->pagina($lector);
        $extractos = $this->extractos($html);

        $this->assertCount(3, $extractos);
        $this->assertSame(Str::limit(trim($largo), 160), $extractos[0]);
        // 160 caracteres como máximo más "..." (Str::limit quita el espacio final antes de los puntos).
        $this->assertLessThanOrEqual(163, mb_strlen(html_entity_decode($extractos[0])));
        $this->assertStringEndsWith('...', $extractos[0]);
        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; con &lt;b&gt;HTML&lt;/b&gt;', $extractos[1]);
        $this->assertSame('El más viejo', $extractos[2]);
        $this->assertStringNotContainsString('<script>alert', $html);

        // "(editado)" solo en el comentario editado.
        $this->assertSame(1, substr_count($html, '<em>(editado)</em>'));
        $this->assertMatchesRegularExpression('#'.preg_quote($extractos[0], '#').'</p>\s*<p class="actividad-meta">\s*<time[^>]*>[^<]+</time>\s*<em>\(editado\)</em>#', $html);
    }

    public function test_cada_comentario_enlaza_a_su_noticia_y_a_editar(): void
    {
        $lector = User::factory()->lector()->create();
        $noticia = Noticia::factory()->create(['title' => 'Título <i>visible</i>']);
        $comentario = $this->comentar($lector, $noticia);

        $html = $this->pagina($lector);

        $this->assertStringContainsString(
            'En: <a href="'.route('noticias.show', $noticia).'#comentarios">Título &lt;i&gt;visible&lt;/i&gt;</a>',
            $html
        );
        $this->assertStringContainsString('<a href="'.route('comentarios.edit', $comentario).'" class="boton boton-secundario boton-sm">Editar</a>', $html);
    }

    public function test_el_enlace_editar_depende_de_la_policy(): void
    {
        // Si ComentarioPolicy@update negara el permiso, el enlace no debe aparecer (@can).
        $lector = User::factory()->lector()->create();
        $comentario = $this->comentar($lector);
        Gate::before(fn ($usuario, string $habilidad) => $habilidad === 'update' ? false : null);

        $html = $this->pagina($lector);

        $this->assertStringNotContainsString(route('comentarios.edit', $comentario), $html);
        $this->assertSame(1, count($this->extractos($html)));
    }

    public function test_un_comentario_en_un_borrador_ajeno_no_filtra_el_titulo(): void
    {
        $autor = User::factory()->editor()->create();
        $admin = User::factory()->admin()->create();
        $lector = User::factory()->lector()->create();
        $noticia = Noticia::factory()->for($autor)->create(['title' => 'Título SECRETO del borrador']);

        $this->comentar($lector, $noticia, ['body' => 'Comentario del lector']);
        $this->comentar($autor, $noticia, ['body' => 'Comentario del autor']);
        $this->comentar($admin, $noticia, ['body' => 'Comentario del admin']);

        // La noticia vuelve a borrador.
        $noticia->forceFill(['status' => 'borrador', 'published_at' => null])->saveQuietly();

        $html = $this->pagina($lector);
        $this->assertStringContainsString('<span class="actividad-no-disponible">Noticia no disponible</span>', $html);
        $this->assertStringNotContainsString('SECRETO', $html);
        $this->assertStringNotContainsString(route('noticias.show', $noticia).'"', $html);
        $this->assertStringNotContainsString(route('noticias.show', $noticia).'#', $html);
        $this->assertSame(['Comentario del lector'], $this->extractos($html));

        // El autor de la noticia y un admin sí pueden verla: ven el título.
        foreach ([$autor, $admin] as $puedeVerla) {
            $html = $this->pagina($puedeVerla);
            $this->assertStringContainsString('En: <a href="'.route('noticias.show', $noticia).'#comentarios">Título SECRETO del borrador</a>', $html);
            $this->assertStringNotContainsString('Noticia no disponible', $html);
        }
    }

    // ----- Paginación -----

    public function test_diez_comentarios_por_pagina_y_404_fuera_de_rango(): void
    {
        $lector = User::factory()->lector()->create();
        for ($i = 1; $i <= 12; $i++) {
            $this->comentar($lector, null, ['body' => "Comentario {$i}", 'created_at' => now()->subMinutes(100 - $i)]);
        }

        $this->assertCount(10, $this->extractos($this->pagina($lector)));
        $this->assertSame(['Comentario 2', 'Comentario 1'], $this->extractos($this->pagina($lector, ['comentarios' => 2])));
        $this->assertMatchesRegularExpression('#<dt>Comentarios</dt>\s*<dd>12</dd>#', $this->pagina($lector, ['comentarios' => 2]));

        $this->actingAs($lector)->get(route('perfil.actividad', ['comentarios' => 3]))->assertNotFound();
    }

    public function test_cinco_noticias_por_pagina_y_404_fuera_de_rango(): void
    {
        $editor = User::factory()->editor()->create();
        for ($i = 1; $i <= 6; $i++) {
            Noticia::factory()->for($editor)->create(['title' => "Noticia {$i}", 'published_at' => now()->subDays(10 - $i)]);
        }

        $this->assertCount(5, $this->titulosDeNoticias($this->pagina($editor)));
        $this->assertSame(['Noticia 1'], $this->titulosDeNoticias($this->pagina($editor, ['noticias' => 2])));

        $this->actingAs($editor)->get(route('perfil.actividad', ['noticias' => 3]))->assertNotFound();
    }

    public function test_paginar_una_lista_conserva_la_pagina_de_la_otra(): void
    {
        $editor = User::factory()->editor()->create();
        for ($i = 1; $i <= 6; $i++) {
            Noticia::factory()->for($editor)->create(['published_at' => now()->subDays($i)]);
        }
        for ($i = 1; $i <= 11; $i++) {
            $this->comentar($editor);
        }

        // En la página 2 de comentarios, los enlaces de noticias llevan comentarios=2.
        $html = $this->pagina($editor, ['comentarios' => 2]);
        $noticias = $this->tarjeta($html, 'actividad-noticias-titulo');
        $this->assertStringContainsString('noticias=2', $noticias);
        $this->assertSame(1, preg_match('#href="([^"]*noticias=2[^"]*)"#', $noticias, $enlace));
        $this->assertStringContainsString('comentarios=2', html_entity_decode($enlace[1]));

        // En la página 2 de noticias, los enlaces de comentarios llevan noticias=2.
        $html = $this->pagina($editor, ['noticias' => 2]);
        $comentarios = $this->tarjeta($html, 'actividad-comentarios-titulo');
        $this->assertSame(1, preg_match('#href="([^"]*comentarios=2[^"]*)"#', $comentarios, $enlace));
        $this->assertStringContainsString('noticias=2', html_entity_decode($enlace[1]));

        // Lo que no es una página no viaja en los enlaces.
        $html = $this->pagina($editor, ['user_id' => 99, 'comentarios' => 2]);
        $this->assertStringNotContainsString('user_id', $html);
    }

    public function test_las_dos_paginaciones_tienen_nombres_accesibles_distintos(): void
    {
        $editor = User::factory()->editor()->create();
        Noticia::factory()->count(6)->for($editor)->create();
        for ($i = 1; $i <= 11; $i++) {
            $this->comentar($editor);
        }

        $html = $this->pagina($editor);

        preg_match_all('#<nav aria-label="([^"]*)" class="paginacion">#', $html, $navs);
        $this->assertSame(['Paginación de mis noticias', 'Paginación de mis comentarios'], $navs[1]);
        $this->assertStringNotContainsString('<nav aria-label="Paginación" class="paginacion">', $html);

        // Cada nombre queda dentro de su tarjeta.
        $this->assertStringContainsString('aria-label="Paginación de mis noticias"', $this->tarjeta($html, 'actividad-noticias-titulo'));
        $this->assertStringContainsString('aria-label="Paginación de mis comentarios"', $this->tarjeta($html, 'actividad-comentarios-titulo'));
    }

    public function test_un_lector_ignora_el_parametro_de_noticias(): void
    {
        $lector = User::factory()->lector()->create();

        $this->actingAs($lector)->get(route('perfil.actividad', ['noticias' => 99]))->assertOk();
    }

    // ----- Rendimiento y calidad -----

    public function test_el_numero_de_consultas_no_crece_con_los_datos(): void
    {
        $medir = function (int $cantidad): int {
            $editor = User::factory()->editor()->create();
            $otroAutor = User::factory()->editor()->create();
            for ($i = 1; $i <= $cantidad; $i++) {
                $propia = Noticia::factory()->for($editor)->create();
                $this->comentar($editor, $propia);
                // Comentarios en noticias publicadas y en borradores de otro autor.
                $ajena = Noticia::factory()->for($otroAutor)->create();
                $this->comentar($editor, $ajena);
                if ($i % 2 === 0) {
                    $ajena->forceFill(['status' => 'borrador', 'published_at' => null])->saveQuietly();
                }
            }

            return $this->contarConsultas(fn () => $this->actingAs($editor)->get(route('perfil.actividad'))->assertOk());
        };

        $this->assertSame($medir(2), $medir(12));
    }

    public function test_la_vista_no_tiene_codigo_en_linea(): void
    {
        foreach ([User::factory()->lector()->create(), User::factory()->editor()->create()] as $usuario) {
            Noticia::factory()->for($usuario)->create();
            $this->comentar($usuario);

            $html = $this->pagina($usuario);

            $this->assertStringNotContainsString('style=', $html);
            $this->assertDoesNotMatchRegularExpression('#\son[a-z]+=#i', $html);
            $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#i', $html);
        }
    }
}
