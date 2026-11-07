<?php

namespace Tests\Feature;

use App\Models\Noticia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * NP-20 paso 2: panel de noticias con borradores, búsqueda, filtro y acciones.
 */
class PanelNoticiasTest extends TestCase
{
    use RefreshDatabase;

    private function datosNoticia(array $cambios = []): array
    {
        return array_merge([
            'title' => 'Nuevo tráiler de un juego',
            'excerpt' => 'Resumen corto de la noticia',
            'body' => 'Contenido completo de la noticia.',
            'status' => 'borrador',
        ], $cambios);
    }

    private function pagina(User $usuario, array $query = [])
    {
        return $this->actingAs($usuario)
            ->get(route('dashboard.noticias.index', $query))
            ->assertOk()
            ->getContent();
    }

    // ---------- Acceso ----------

    public function test_un_visitante_no_puede_ver_el_panel_de_noticias(): void
    {
        $this->get(route('dashboard.noticias.index'))->assertRedirect(route('login'));
    }

    public function test_un_lector_no_puede_ver_el_panel_de_noticias(): void
    {
        $lector = User::factory()->lector()->create();

        $this->actingAs($lector)->get(route('dashboard.noticias.index'))->assertForbidden();
    }

    // ---------- Qué ve cada rol ----------

    public function test_un_admin_ve_todas_las_noticias_del_panel(): void
    {
        $admin = User::factory()->admin()->create();
        $editor = User::factory()->editor()->create();

        Noticia::factory()->for($admin)->create(['title' => 'P1 admin']);
        Noticia::factory()->for($editor)->create(['title' => 'P2 editor']);

        $html = $this->pagina($admin);

        $this->assertStringContainsString('P1 admin', $html);
        $this->assertStringContainsString('P2 editor', $html);
    }

    public function test_un_editor_solo_ve_las_suyas(): void
    {
        $editor = User::factory()->editor()->create();
        $otro = User::factory()->editor()->create();

        Noticia::factory()->for($editor)->create(['title' => 'Mía']);
        Noticia::factory()->for($otro)->create(['title' => 'Ajena']);

        $html = $this->pagina($editor);

        $this->assertStringContainsString('Mía', $html);
        $this->assertStringNotContainsString('Ajena', $html);
    }

    public function test_el_panel_incluye_borradores(): void
    {
        $editor = User::factory()->editor()->create();

        Noticia::factory()->for($editor)->borrador()->create(['title' => 'Mi BORRADOR']);

        $html = $this->pagina($editor);

        $this->assertStringContainsString('Mi BORRADOR', $html);
        $this->assertStringContainsString('Borrador', $html);
    }

    // ---------- Búsqueda y filtro ----------

    public function test_busca_por_titulo_y_resumen(): void
    {
        $editor = User::factory()->editor()->create();

        Noticia::factory()->for($editor)->create(['title' => 'Anuncian nueva expansión']);
        Noticia::factory()->for($editor)->create(['title' => 'Nuevo tráiler de un juego']);

        $html = $this->pagina($editor, ['q' => 'expansión']);

        $this->assertStringContainsString('Anuncian nueva expansión', $html);
        $this->assertStringNotContainsString('Nuevo tráiler de un juego', $html);
    }

    public function test_filtra_por_estado_borrador(): void
    {
        $editor = User::factory()->editor()->create();

        Noticia::factory()->for($editor)->create(['title' => 'Noticia publicada', 'status' => 'publicada']);
        Noticia::factory()->for($editor)->borrador()->create(['title' => 'Borrador oculto']);

        $html = $this->pagina($editor, ['estado' => 'borrador']);

        $this->assertStringContainsString('Borrador oculto', $html);
        $this->assertStringNotContainsString('Noticia publicada', $html);
    }

    public function test_una_busqueda_de_mas_de_100_caracteres_se_rechaza(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)
            ->get(route('dashboard.noticias.index', ['q' => str_repeat('a', 101)]))
            ->assertSessionHasErrors('q');
    }

    public function test_una_busqueda_que_no_es_texto_se_rechaza(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)
            ->get(route('dashboard.noticias.index', ['q[]' => 'hola']))
            ->assertSessionHasErrors('q');
    }

    public function test_el_estado_invalido_se_rechaza(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)
            ->get(route('dashboard.noticias.index', ['estado' => 'archivada']))
            ->assertSessionHasErrors('estado');
    }

    public function test_un_valor_de_busqueda_simbolos_se_busca_como_texto_literal(): void
    {
        $editor = User::factory()->editor()->create();

        Noticia::factory()->for($editor)->create(['title' => 'Precio 100% real']);
        Noticia::factory()->for($editor)->create(['title' => 'Precios bajos']);

        $html = $this->pagina($editor, ['q' => '100%']);

        $this->assertStringContainsString('Precio 100% real', $html);
        $this->assertStringNotContainsString('Precios bajos', $html);
    }

    public function test_el_texto_buscado_se_muestra_escapado_en_el_input(): void
    {
        $editor = User::factory()->editor()->create();

        $html = $this->pagina($editor, ['q' => '<script>alert(1)</script>']);

        $this->assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    // ---------- Acciones ----------

    public function test_el_admin_ve_editar_y_eliminar_de_una_noticia_ajena(): void
    {
        $admin = User::factory()->admin()->create();
        $editor = User::factory()->editor()->create();

        $noticia = Noticia::factory()->for($editor)->create();

        $html = $this->pagina($admin);

        $this->assertStringContainsString(route('noticias.edit', $noticia), $html);
        $this->assertStringContainsString(route('noticias.destroy', $noticia), $html);
    }

    public function test_un_editor_no_ve_acciones_de_una_noticia_ajena(): void
    {
        $editor = User::factory()->editor()->create();
        $otro = User::factory()->editor()->create();

        $noticia = Noticia::factory()->for($otro)->create();

        $html = $this->pagina($editor);

        $this->assertStringNotContainsString(route('noticias.edit', $noticia), $html);
        $this->assertStringNotContainsString(route('noticias.destroy', $noticia), $html);
    }

    public function test_el_boton_nuevo_articulo_solo_lo_ve_admin_y_editor(): void
    {
        $this->pagina(User::factory()->admin()->create());
        $this->assertStringContainsString('+ Nuevo Artículo', $html = $this->pagina(User::factory()->admin()->create()));

        $htmlEditor = $this->pagina(User::factory()->editor()->create());
        $this->assertStringContainsString('+ Nuevo Artículo', $htmlEditor);
    }

    // ---------- Paginación ----------

    public function test_la_lista_se_pagina(): void
    {
        $admin = User::factory()->admin()->create();

        Noticia::factory()->count(15)->for($admin)->create();

        $html = $this->pagina($admin);

        $this->assertStringContainsString('href="'.route('dashboard.noticias.index', ['page' => 2]).'"', $html);
    }

    public function test_una_pagina_fuera_de_rango_responde_404(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('dashboard.noticias.index', ['page' => 9999]))
            ->assertNotFound();
    }

    // ---------- Calidad ----------

    public function test_la_lista_no_hace_consultas_n_mas_1(): void
    {
        $editor = User::factory()->editor()->create();

        foreach (range(1, 6) as $i) {
            Noticia::factory()->for($editor)->create(['title' => "Noticia $i"]);
        }

        $editor = User::factory()->editor()->create();

        foreach (range(1, 6) as $i) {
            Noticia::factory()->for($editor)->create(['title' => "Noticia $i"]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($editor)->get(route('dashboard.noticias.index'))->assertOk();

        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(10, $total, 'El listado del panel no debe hacer N+1 consultas.');
    }

    public function test_la_lista_no_tiene_codigo_en_linea(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->pagina($admin);

        $this->assertStringNotContainsString('style=', $html);
        $this->assertDoesNotMatchRegularExpression('#\son[a-z]+=#i', $html);
        $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#i', $html);
    }

    // ---------- Estado vacío ----------

    public function test_cuando_no_hay_noticias_muestra_estado_vacio(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->pagina($admin);

        $this->assertStringContainsString('Aún no hay noticias en el panel.', $html);
        $this->assertStringContainsString('+ Nuevo Artículo', $html);
    }
}
