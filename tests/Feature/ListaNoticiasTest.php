<?php

namespace Tests\Feature;

use App\Models\Noticia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListaNoticiasTest extends TestCase
{
    use RefreshDatabase;

    public function test_muestra_publicadas_y_nunca_borradores(): void
    {
        Noticia::factory()->create(['title' => 'Noticia publicada visible']);
        Noticia::factory()->borrador()->create(['title' => 'Borrador ultrasecreto QZ7']);

        $this->get(route('noticias.index'))
            ->assertOk()
            ->assertSee('Noticia publicada visible')
            ->assertDontSee('Borrador ultrasecreto QZ7');
    }

    public function test_con_la_misma_fecha_va_primero_la_de_id_mayor(): void
    {
        $fecha = now()->subHour();
        $menor = Noticia::factory()->create(['title' => 'Titular de id menor', 'published_at' => $fecha]);
        $mayor = Noticia::factory()->create(['title' => 'Titular de id mayor', 'published_at' => $fecha]);

        $this->assertGreaterThan($menor->id, $mayor->id);

        $this->get(route('noticias.index'))
            ->assertSeeInOrder(['Titular de id mayor', 'Titular de id menor']);
    }

    public function test_pagina_de_nueve_en_nueve_y_marca_la_pagina_actual(): void
    {
        Noticia::factory()->count(10)->create();

        $primera = $this->get(route('noticias.index'))
            ->assertViewHas('noticias', fn ($noticias) => $noticias->count() === 9)
            ->assertSee('aria-label="Paginación"', false)
            ->getContent();

        $this->assertMatchesRegularExpression('#<span[^>]*aria-current="page"[^>]*>1</span>#', $primera);
        $this->assertMatchesRegularExpression('#<span[^>]*aria-disabled="true"[^>]*>← Anterior</span>#', $primera);

        $segunda = $this->get(route('noticias.index', ['page' => 2]))
            ->assertViewHas('noticias', fn ($noticias) => $noticias->count() === 1)
            ->getContent();

        $this->assertMatchesRegularExpression('#<span[^>]*aria-current="page"[^>]*>2</span>#', $segunda);
        $this->assertMatchesRegularExpression('#<span[^>]*aria-disabled="true"[^>]*>Siguiente →</span>#', $segunda);
    }

    public function test_con_una_sola_pagina_no_hay_paginacion(): void
    {
        Noticia::factory()->count(9)->create();

        $this->get(route('noticias.index'))->assertDontSee('aria-label="Paginación"', false);
    }

    public function test_muchas_paginas_muestran_puntos_suspensivos(): void
    {
        Noticia::factory()->count(9 * 12)->create();

        $this->get(route('noticias.index', ['page' => 6]))->assertSee('…');
    }

    public function test_un_visitante_no_ve_acciones(): void
    {
        Noticia::factory()->create();

        $this->get(route('noticias.index'))
            ->assertDontSee('+ Nueva noticia')
            ->assertDontSee('Editar')
            ->assertDontSee('Eliminar');
    }

    public function test_un_lector_no_ve_acciones(): void
    {
        Noticia::factory()->create();

        $this->actingAs(User::factory()->lector()->create())
            ->get(route('noticias.index'))
            ->assertDontSee('+ Nueva noticia')
            ->assertDontSee('Editar')
            ->assertDontSee('Eliminar');
    }

    public function test_un_editor_ve_acciones_solo_en_sus_noticias(): void
    {
        $editor = User::factory()->editor()->create();
        $suya = Noticia::factory()->create(['user_id' => $editor->id]);
        $ajena = Noticia::factory()->create();

        $this->actingAs($editor)
            ->get(route('noticias.index'))
            ->assertSee('+ Nueva noticia')
            ->assertSee('action="'.route('noticias.destroy', $suya).'"', false)
            ->assertSee('href="'.route('noticias.edit', $suya).'"', false)
            ->assertDontSee('action="'.route('noticias.destroy', $ajena).'"', false)
            ->assertDontSee('href="'.route('noticias.edit', $ajena).'"', false);
    }

    public function test_el_admin_ve_acciones_en_todas(): void
    {
        $noticias = Noticia::factory()->count(2)->create();

        $respuesta = $this->actingAs(User::factory()->admin()->create())
            ->get(route('noticias.index'))
            ->assertSee('+ Nueva noticia');

        foreach ($noticias as $noticia) {
            $respuesta
                ->assertSee('action="'.route('noticias.destroy', $noticia).'"', false)
                ->assertSee('href="'.route('noticias.edit', $noticia).'"', false);
        }
    }

    public function test_el_formulario_de_eliminar_es_post_con_delete_y_csrf(): void
    {
        $noticia = Noticia::factory()->create();

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('noticias.index'))
            ->getContent();

        $accion = preg_quote(route('noticias.destroy', $noticia), '#');
        $this->assertSame(1, preg_match("#<form method=\"POST\" action=\"{$accion}\".*?</form>#s", $html, $formulario));
        $this->assertStringContainsString('name="_token"', $formulario[0]);
        $this->assertStringContainsString('name="_method" value="DELETE"', $formulario[0]);
        $this->assertStringContainsString('data-confirm="¿Seguro que quieres eliminar esta noticia?"', $formulario[0]);
    }

    public function test_titulo_y_resumen_con_html_se_muestran_escapados(): void
    {
        Noticia::factory()->create([
            'title' => '<script>alert("titulo")</script>',
            'excerpt' => '<img src=x onerror="alert(1)">',
        ]);

        $this->get(route('noticias.index'))
            ->assertDontSee('<script>alert', false)
            ->assertDontSee('<img src=x', false)
            ->assertSee('&lt;script&gt;alert', false)
            ->assertSee('&lt;img src=x', false);
    }

    public function test_el_titulo_de_la_tarjeta_es_h2_bajo_el_h1(): void
    {
        Noticia::factory()->create();

        $this->get(route('noticias.index'))
            ->assertSee('<h1 class="pagina-titulo">', false)
            ->assertSee('<h2 class="tarjeta-titulo">', false);
    }

    public function test_un_nivel_invalido_vuelve_a_h3(): void
    {
        $noticia = Noticia::factory()->create();
        $noticia->loadCount('comentarios');

        foreach ([1, 5, '2', 'script'] as $nivel) {
            $html = view('noticias._tarjeta', ['noticia' => $noticia, 'nivel' => $nivel])->render();

            $this->assertStringContainsString('<h3 class="tarjeta-titulo">', $html);
        }
    }

    public function test_el_mensaje_de_estado_se_muestra_como_aviso(): void
    {
        $this->withSession(['status' => 'Noticia guardada <b>ya</b>'])
            ->get(route('noticias.index'))
            ->assertSee('class="aviso-exito"', false)
            ->assertSee('Noticia guardada &lt;b&gt;ya&lt;/b&gt;', false);
    }

    public function test_estado_vacio_ofrece_crear_solo_a_quien_puede(): void
    {
        $this->get(route('noticias.index'))
            ->assertSee('No hay noticias publicadas todavía.')
            ->assertDontSee(route('noticias.create'));

        $this->actingAs(User::factory()->editor()->create())
            ->get(route('noticias.index'))
            ->assertSee('Crear noticia')
            ->assertSee(route('noticias.create'));
    }

    public function test_eliminar_una_noticia_redirige_y_muestra_el_aviso(): void
    {
        $noticia = Noticia::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('noticias.destroy', $noticia))
            ->assertRedirect(route('noticias.index'));

        $this->get(route('noticias.index'))
            ->assertSee('class="aviso-exito"', false)
            ->assertSee('Noticia eliminada.');
    }

    public function test_crear_una_noticia_muestra_el_aviso_una_sola_vez(): void
    {
        $html = $this->actingAs(User::factory()->editor()->create())
            ->followingRedirects()
            ->post(route('noticias.store'), $this->datosNoticia())
            ->assertOk()
            ->assertSee('class="aviso-exito"', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Noticia creada.'));
    }

    public function test_editar_una_noticia_muestra_el_aviso_una_sola_vez(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = Noticia::factory()->create(['user_id' => $editor->id]);

        $html = $this->actingAs($editor)
            ->followingRedirects()
            ->put(route('noticias.update', $noticia), $this->datosNoticia())
            ->assertOk()
            ->assertSee('class="aviso-exito"', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Noticia actualizada.'));
    }

    public function test_el_aviso_de_un_comentario_sigue_solo_en_su_seccion(): void
    {
        $noticia = Noticia::factory()->create();

        $html = $this->actingAs(User::factory()->lector()->create())
            ->followingRedirects()
            ->post(route('comentarios.store', $noticia), ['body' => 'Buen artículo'])
            ->assertOk()
            ->assertSee('<p class="status-ok">Comentario publicado.</p>', false)
            ->assertDontSee('class="aviso-exito"', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Comentario publicado.'));
    }

    public function test_una_pagina_inexistente_responde_404(): void
    {
        Noticia::factory()->count(2)->create();

        $this->get(route('noticias.index', ['page' => 99]))->assertNotFound();
    }

    public function test_sin_noticias_la_pagina_1_muestra_el_estado_vacio(): void
    {
        $this->get(route('noticias.index'))
            ->assertOk()
            ->assertSee('No hay noticias publicadas todavía.');
    }

    public function test_sin_n_mas_1_para_un_visitante(): void
    {
        // La fábrica crea un autor distinto para cada noticia.
        Noticia::factory()->count(2)->create();
        $conDos = $this->contarConsultasDeLista();

        Noticia::factory()->count(7)->create();
        $conNueve = $this->contarConsultasDeLista();

        $this->assertSame(9, User::whereHas('noticias')->count());
        $this->assertSame($conDos, $conNueve);
    }

    public function test_sin_n_mas_1_para_un_admin_con_acciones(): void
    {
        $admin = User::factory()->admin()->create();

        // Instancia nueva en cada medición, como en una petición real:
        // si no, la segunda reutilizaría el rol ya cargado en memoria.
        Noticia::factory()->count(2)->create();
        $this->actingAs($admin->fresh());
        $conDos = $this->contarConsultasDeLista();

        Noticia::factory()->count(7)->create();
        $this->actingAs($admin->fresh());
        $conNueve = $this->contarConsultasDeLista();

        $this->assertSame($conDos, $conNueve);
    }

    private function datosNoticia(): array
    {
        return [
            'title' => 'Titular del formulario',
            'excerpt' => 'Resumen del formulario',
            'body' => 'Cuerpo del formulario',
            'status' => 'publicada',
        ];
    }

    private function contarConsultasDeLista(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('noticias.index'))->assertOk();

        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    }
}
