<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Comentario;
use App\Models\Noticia;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ComentarioTest extends TestCase
{
    use RefreshDatabase;

    // ---------- NP-11: comentar ----------

    public function test_un_lector_comenta_una_noticia_publicada(): void
    {
        $lector = User::factory()->lector()->create();
        $noticia = Noticia::factory()->create();

        $this->actingAs($lector)
            ->post(route('comentarios.store', $noticia), ['body' => '¡Qué buena noticia!'])
            ->assertRedirect(route('noticias.show', $noticia).'#comentarios');

        $this->assertDatabaseHas('comentarios', [
            'noticia_id' => $noticia->id,
            'user_id' => $lector->id,
            'body' => '¡Qué buena noticia!',
        ]);
    }

    public function test_el_comentario_queda_en_la_auditoria(): void
    {
        $lector = User::factory()->lector()->create();
        $noticia = Noticia::factory()->create();

        $this->actingAs($lector)->post(route('comentarios.store', $noticia), ['body' => 'Comentario auditado']);

        $registro = AuditLog::where('auditable_type', Comentario::class)->latest('id')->firstOrFail();
        $this->assertSame('crear', $registro->action);
        $this->assertSame($lector->id, $registro->user_id);
    }

    public function test_no_se_puede_falsificar_el_autor_ni_la_noticia(): void
    {
        $lector = User::factory()->lector()->create();
        $otro = User::factory()->lector()->create();
        $noticia = Noticia::factory()->create();
        $otraNoticia = Noticia::factory()->create();

        $this->actingAs($lector)->post(route('comentarios.store', $noticia), [
            'body' => 'Intento de suplantación',
            'user_id' => $otro->id,
            'noticia_id' => $otraNoticia->id,
        ]);

        $comentario = Comentario::firstOrFail();
        $this->assertSame($lector->id, $comentario->user_id);
        $this->assertSame($noticia->id, $comentario->noticia_id);
    }

    public function test_un_visitante_no_puede_comentar(): void
    {
        $noticia = Noticia::factory()->create();

        $this->post(route('comentarios.store', $noticia), ['body' => 'Spam'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('comentarios', 0);
    }

    public function test_un_visitante_lee_los_comentarios_pero_no_ve_el_formulario(): void
    {
        $comentario = Comentario::factory()->create(['body' => 'Comentario visible para todos']);

        $this->get(route('noticias.show', $comentario->noticia))
            ->assertOk()
            ->assertSee('Comentario visible para todos')
            ->assertSee('para comentar')
            ->assertDontSee('name="body"', false);
    }

    public function test_no_se_puede_comentar_un_borrador(): void
    {
        $autor = User::factory()->editor()->create();
        $borrador = Noticia::factory()->for($autor)->borrador()->create();

        $this->actingAs($autor)
            ->post(route('comentarios.store', $borrador), ['body' => 'Comentario en borrador'])
            ->assertForbidden();

        $this->assertDatabaseCount('comentarios', 0);
    }

    public function test_el_comentario_es_obligatorio_y_tiene_maximo_1000_caracteres(): void
    {
        $lector = User::factory()->lector()->create();
        $noticia = Noticia::factory()->create();

        $this->actingAs($lector)
            ->post(route('comentarios.store', $noticia), ['body' => ''])
            ->assertSessionHasErrors('body');

        $this->actingAs($lector)
            ->post(route('comentarios.store', $noticia), ['body' => str_repeat('a', 1001)])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('comentarios', 0);
    }

    public function test_un_comentario_con_html_se_muestra_escapado(): void
    {
        $comentario = Comentario::factory()->create(['body' => '<script>alert("xss")</script>']);

        $this->get(route('noticias.show', $comentario->noticia))
            ->assertOk()
            ->assertDontSee('<script>alert("xss")</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_comentar_esta_limitado_a_10_por_minuto(): void
    {
        $lector = User::factory()->lector()->create();
        $noticia = Noticia::factory()->create();

        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($lector)
                ->post(route('comentarios.store', $noticia), ['body' => "Comentario {$i}"])
                ->assertStatus(302);
        }

        $this->actingAs($lector)
            ->post(route('comentarios.store', $noticia), ['body' => 'Uno de más'])
            ->assertStatus(429);
    }

    // ---------- NP-12: editar ----------

    public function test_el_autor_edita_su_comentario(): void
    {
        $comentario = Comentario::factory()->create(['body' => 'Texto original']);

        $this->actingAs($comentario->user)->get(route('comentarios.edit', $comentario))->assertOk();

        $this->actingAs($comentario->user)
            ->put(route('comentarios.update', $comentario), ['body' => 'Texto corregido'])
            ->assertRedirect();

        $comentario->refresh();
        $this->assertSame('Texto corregido', $comentario->body);
        $this->assertNotNull($comentario->edited_at);

        $registro = AuditLog::where('auditable_type', Comentario::class)->latest('id')->firstOrFail();
        $this->assertSame('editar', $registro->action);
        $this->assertSame('Texto original', $registro->old_values['body']);

        $this->get(route('noticias.show', $comentario->noticia))->assertSee('(editado)');
    }

    public function test_nadie_edita_el_comentario_de_otro_ni_siquiera_el_admin(): void
    {
        $comentario = Comentario::factory()->create(['body' => 'Original']);
        $otroLector = User::factory()->lector()->create();
        $admin = User::factory()->admin()->create();

        foreach ([$otroLector, $admin] as $usuario) {
            $this->actingAs($usuario)->get(route('comentarios.edit', $comentario))->assertForbidden();
            $this->actingAs($usuario)
                ->put(route('comentarios.update', $comentario), ['body' => 'Modificado'])
                ->assertForbidden();
        }

        $this->assertSame('Original', $comentario->fresh()->body);
    }

    // ---------- NP-13: eliminar y moderar ----------

    public function test_el_autor_elimina_su_comentario(): void
    {
        $comentario = Comentario::factory()->create();

        $this->actingAs($comentario->user)
            ->delete(route('comentarios.destroy', $comentario))
            ->assertRedirect();

        $this->assertDatabaseMissing('comentarios', ['id' => $comentario->id]);

        $registro = AuditLog::where('auditable_type', Comentario::class)->latest('id')->firstOrFail();
        $this->assertSame('eliminar', $registro->action);
    }

    public function test_el_admin_elimina_cualquier_comentario(): void
    {
        $admin = User::factory()->admin()->create();
        $comentario = Comentario::factory()->create();

        $this->actingAs($admin)
            ->delete(route('comentarios.destroy', $comentario))
            ->assertRedirect();

        $this->assertDatabaseMissing('comentarios', ['id' => $comentario->id]);

        $registro = AuditLog::where('auditable_type', Comentario::class)->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $registro->user_id, 'La auditoría registra que lo eliminó el administrador.');
    }

    public function test_un_lector_o_editor_no_elimina_comentarios_ajenos(): void
    {
        $comentario = Comentario::factory()->create();
        $lector = User::factory()->lector()->create();
        $editor = User::factory()->editor()->create();

        foreach ([$lector, $editor] as $usuario) {
            $this->actingAs($usuario)
                ->delete(route('comentarios.destroy', $comentario))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('comentarios', ['id' => $comentario->id]);
    }

    public function test_el_admin_ve_el_panel_de_moderacion(): void
    {
        $admin = User::factory()->admin()->create();
        Comentario::factory()->create(['body' => 'Comentario por moderar']);

        $this->actingAs($admin)
            ->get(route('comentarios.index'))
            ->assertOk()
            ->assertSee('Comentario por moderar');
    }

    public function test_un_editor_o_lector_no_ve_el_panel_de_moderacion(): void
    {
        foreach ([User::factory()->editor()->create(), User::factory()->lector()->create()] as $usuario) {
            $this->actingAs($usuario)->get(route('comentarios.index'))->assertForbidden();
        }
    }

    // ---------- NP-20 paso 6b: moderación con la tabla del panel ----------

    private function xpath(string $html): DOMXPath
    {
        $anterior = libxml_use_internal_errors(true);
        $documento = new DOMDocument;
        $documento->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return new DOMXPath($documento);
    }

    private function moderacion(array $query = []): string
    {
        return $this->actingAs(User::factory()->admin()->create())
            ->get(route('comentarios.index', $query))
            ->assertOk()
            ->getContent();
    }

    public function test_la_tabla_tiene_las_columnas_y_es_accesible(): void
    {
        Comentario::factory()->count(2)->create();

        $xpath = $this->xpath($this->moderacion());
        $tabla = '//table[@class="panel-tabla"][@role="table"]';

        $this->assertSame('Comentarios: página 1 de 1', trim($xpath->query($tabla.'/caption[@class="sr-only"]')->item(0)->textContent));
        $this->assertSame(
            ['Comentario', 'Autor', 'Noticia', 'Fecha', 'Acción'],
            array_map(fn ($th) => trim($th->textContent), iterator_to_array($xpath->query($tabla.'/thead//th[@scope="col"][@role="columnheader"]')))
        );
        $this->assertSame(2, $xpath->query($tabla.'/tbody//th[@scope="row"][@role="rowheader"]')->length);
        $this->assertSame(0, $xpath->query($tabla.'//tr[not(@role="row")]')->length);
        $this->assertSame(0, $xpath->query($tabla.'//td[not(@role="cell")]')->length);

        $time = $xpath->query('//td[@data-columna="Fecha"]/time')->item(0);
        $this->assertNotSame('', $time->getAttribute('datetime'));
        $this->assertNotSame('', $time->getAttribute('title'));
        $this->assertSame('PANEL DE GESTIÓN', trim($xpath->query('//p[@class="pagina-etiqueta"]')->item(0)->textContent));
    }

    public function test_el_extracto_se_recorta_y_ver_completo_solo_si_hace_falta(): void
    {
        $largo = str_repeat('ñ', 100)."\n\nSegunda línea con 😀 emoji ".str_repeat('x', 40);
        $comentarioLargo = Comentario::factory()->create(['body' => $largo]);
        $comentarioCorto = Comentario::factory()->create(['body' => "Línea uno\nLínea dos"]);

        $xpath = $this->xpath($this->moderacion());

        $fila = fn (Comentario $c) => $xpath->query('//tbody/tr[.//form[@action="'.route('comentarios.destroy', $c).'"]]')->item(0);

        // Largo: extracto de 120 caracteres (multibyte) + "...", y el texto completo plegado.
        $celdaLarga = $xpath->query('./th', $fila($comentarioLargo))->item(0);
        $esperado = rtrim(mb_substr(trim(preg_replace('/\s+/u', ' ', $largo)), 0, 120)).'…';
        $this->assertSame($esperado, $xpath->query('./span[@class="comentario-extracto"]', $celdaLarga)->item(0)->textContent);
        $this->assertSame('Ver completo', trim($xpath->query('./details/summary', $celdaLarga)->item(0)->textContent));
        $this->assertSame($largo, $xpath->query('./details/p', $celdaLarga)->item(0)->textContent);

        // Corto: los saltos de línea se ven como espacios y no hay "Ver completo".
        $celdaCorta = $xpath->query('./th', $fila($comentarioCorto))->item(0);
        $this->assertSame('Línea uno Línea dos', $xpath->query('./span[@class="comentario-extracto"]', $celdaCorta)->item(0)->textContent);
        $this->assertSame(0, $xpath->query('./details', $celdaCorta)->length);
    }

    public function test_autor_noticia_y_chip_de_borrador(): void
    {
        $autor = User::factory()->lector()->create(['name' => 'Lola Lectora']);
        $publicada = Noticia::factory()->create(['title' => 'Publicada']);
        $borrador = Noticia::factory()->borrador()->create(['title' => 'Vuelta a borrador']);
        Comentario::factory()->for($autor)->for($publicada)->create();
        Comentario::factory()->for($autor)->for($borrador)->create();

        $xpath = $this->xpath($this->moderacion());

        $celda = fn (Noticia $n) => $xpath->query('//td[@data-columna="Noticia"][a[@href="'.route('noticias.show', $n).'#comentarios"]]')->item(0);

        $this->assertSame('Publicada', trim($xpath->query('./a', $celda($publicada))->item(0)->textContent));
        $this->assertSame(0, $xpath->query('.//span[contains(@class,"chip-borrador")]', $celda($publicada))->length);
        $chip = $xpath->query('./span', $celda($borrador))->item(0);
        $this->assertSame('chip chip-estado chip-borrador', $chip->getAttribute('class'));
        $this->assertSame('Borrador', trim($chip->textContent));

        $this->assertSame(2, $xpath->query('//td[@data-columna="Autor"][.//span[normalize-space()="Lola Lectora"]]')->length);
    }

    public function test_el_boton_eliminar_es_un_delete_protegido_y_accesible(): void
    {
        $autor = User::factory()->lector()->create(['name' => 'Lola Lectora']);
        $noticia = Noticia::factory()->create(['title' => 'Gran noticia']);
        $comentario = Comentario::factory()->for($autor)->for($noticia)->create();

        $xpath = $this->xpath($this->moderacion());
        $form = $xpath->query('//form[@action="'.route('comentarios.destroy', $comentario).'"]')->item(0);

        $this->assertSame('POST', $form->getAttribute('method'));
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
        $this->assertSame('DELETE', $xpath->query('.//input[@name="_method"]', $form)->item(0)->getAttribute('value'));

        $boton = $xpath->query('.//button[@type="submit"]', $form)->item(0);
        $this->assertStringContainsString('boton-peligro', $boton->getAttribute('class'));
        $this->assertStringContainsString('panel-tabla-accion', $boton->getAttribute('class'));
        $this->assertSame('¿Eliminar el comentario de Lola Lectora? No se puede deshacer.', $boton->getAttribute('data-confirm'));
        $this->assertSame('Eliminar comentario de Lola Lectora en «Gran noticia»', preg_replace('/\s+/', ' ', trim($boton->textContent)));
    }

    public function test_el_html_del_comentario_autor_y_titulo_sale_escapado(): void
    {
        $autor = User::factory()->lector()->create(['name' => '<b>Mal</b>']);
        $noticia = Noticia::factory()->create(['title' => '<i>Título</i>']);
        Comentario::factory()->for($autor)->for($noticia)->create(['body' => '<script>alert(1)</script> '.str_repeat('a', 130)]);

        $html = $this->moderacion();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>Mal</b>', $html);
        $this->assertStringNotContainsString('<i>Título</i>', $html);
        // Extracto y texto completo.
        $this->assertSame(2, substr_count($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));
        // sr-only del botón y data-confirm.
        $this->assertStringContainsString('<span class="sr-only"> comentario de &lt;b&gt;Mal&lt;/b&gt; en «&lt;i&gt;Título&lt;/i&gt;»</span>', $html);
        $this->assertStringContainsString('data-confirm="¿Eliminar el comentario de &lt;b&gt;Mal&lt;/b&gt;? No se puede deshacer."', $html);
    }

    public function test_borrar_el_ultimo_de_la_ultima_pagina_lleva_a_la_ultima_valida(): void
    {
        $admin = User::factory()->admin()->create();
        Comentario::factory()->count(21)->create();
        // El más antiguo es el único de la página 2.
        $ultimo = Comentario::oldest()->oldest('id')->firstOrFail();
        $pagina2 = route('comentarios.index', ['page' => 2]);

        $this->actingAs($admin)
            ->from($pagina2)
            ->delete(route('comentarios.destroy', $ultimo))
            ->assertRedirect($pagina2);

        // La página 2 ya no existe: vuelve a la 1 (sin ?page) conservando el aviso.
        $this->actingAs($admin)->get($pagina2)->assertRedirect(route('comentarios.index'));
        $this->actingAs($admin)
            ->get(route('comentarios.index'))
            ->assertOk()
            ->assertSee('<p class="aviso-exito" role="status">Comentario eliminado.</p>', false);
    }

    public function test_una_pagina_fuera_de_rango_va_a_la_ultima_pagina_valida(): void
    {
        $admin = User::factory()->admin()->create();
        Comentario::factory()->count(41)->create();

        $this->actingAs($admin)
            ->get(route('comentarios.index', ['page' => 9]))
            ->assertRedirect(route('comentarios.index', ['page' => 3]));
    }

    public function test_sin_comentarios_la_pagina_1_muestra_el_estado_vacio(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('comentarios.index', ['page' => 4]))->assertRedirect(route('comentarios.index'));
        $this->actingAs($admin)
            ->get(route('comentarios.index'))
            ->assertOk()
            ->assertSee('No hay comentarios que moderar.')
            ->assertDontSee('<table', false);
    }

    public function test_una_pagina_que_no_es_un_numero_se_rechaza(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['abc', '0', '-1', '1.5'] as $pagina) {
            $this->actingAs($admin)
                ->from(route('comentarios.index'))
                ->get(route('comentarios.index', ['page' => $pagina]))
                ->assertSessionHasErrors('page');
        }
    }

    public function test_la_paginacion_usa_la_etiqueta_propia(): void
    {
        Comentario::factory()->count(21)->create();

        $this->assertStringContainsString('aria-label="Paginación de comentarios"', $this->moderacion());
    }

    public function test_las_consultas_no_crecen_con_autores_con_foto(): void
    {
        $admin = User::factory()->admin()->create();

        $crear = function (int $cuantos): void {
            foreach (range(1, $cuantos) as $i) {
                $autor = User::factory()->lector()->create();
                $autor->forceFill(['avatar_path' => str_repeat('d', 39).$i.'.webp'])->saveQuietly();
                Comentario::factory()->for($autor)->create();
            }
        };

        $contar = function () use ($admin): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin->fresh())->get(route('comentarios.index'))->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $crear(2);
        $pocos = $contar();
        $crear(9);

        $this->assertSame($pocos, $contar());
    }

    public function test_un_visitante_va_al_login(): void
    {
        $this->get(route('comentarios.index'))->assertRedirect(route('login'));
    }

    public function test_la_vista_de_moderacion_no_tiene_codigo_en_linea(): void
    {
        Comentario::factory()->create(['body' => str_repeat('largo ', 40)]);

        $html = $this->moderacion();

        $this->assertStringNotContainsString('style=', $html);
        $this->assertDoesNotMatchRegularExpression('#<[^>]+\son[a-z]+=#i', $html);
        $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#i', $html);
    }
}
