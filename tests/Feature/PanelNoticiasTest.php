<?php

namespace Tests\Feature;

use App\Models\Noticia;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * NP-20 pasos 2 y 3: panel de noticias con borradores, búsqueda, filtro,
 * contadores por estado, tabla y acciones.
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
        $this->assertStringContainsString('+ Nuevo artículo', $html = $this->pagina(User::factory()->admin()->create()));

        $htmlEditor = $this->pagina(User::factory()->editor()->create());
        $this->assertStringContainsString('+ Nuevo artículo', $htmlEditor);
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
        $this->assertStringContainsString('+ Nuevo artículo', $html);
    }

    // ---------- NP-20 paso 3: contadores de las pestañas ----------

    private function xpath(string $html): DOMXPath
    {
        $anterior = libxml_use_internal_errors(true);
        $documento = new DOMDocument;
        $documento->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return new DOMXPath($documento);
    }

    /**
     * Lee "(N)" visible y ", N noticias" sr-only de cada pestaña y comprueba que coinciden.
     *
     * @return array{Todas: int, Publicadas: int, Borradores: int}
     */
    private function contadores(string $html): array
    {
        $contadores = [];

        foreach (['Todas', 'Publicadas', 'Borradores'] as $pestana) {
            $this->assertSame(1, preg_match(
                '#>\s*'.$pestana.' <span class="panel-estado-total" aria-hidden="true">\((\d+)\)</span><span class="sr-only">, (\d+) (noticias?)</span>#',
                $html,
                $m
            ), "No se encontró el contador de {$pestana}.");
            $this->assertSame($m[1], $m[2], 'El número visible y el del lector de pantalla deben coincidir.');
            $this->assertSame($m[1] === '1' ? 'noticia' : 'noticias', $m[3]);
            $contadores[$pestana] = (int) $m[1];
        }

        return $contadores;
    }

    /**
     * Editor A: 2 publicadas + 1 borrador. Editor B: 1 publicada + 3 borradores.
     *
     * @return array{0: User, 1: User}
     */
    private function dosEditoresConNoticias(): array
    {
        $a = User::factory()->editor()->create();
        $b = User::factory()->editor()->create();

        Noticia::factory()->count(2)->for($a)->create(['status' => 'publicada']);
        Noticia::factory()->for($a)->borrador()->create(['title' => 'Borrador de A sobre expansión']);
        Noticia::factory()->for($b)->create(['status' => 'publicada']);
        Noticia::factory()->count(3)->for($b)->borrador()->create();

        return [$a, $b];
    }

    public function test_el_editor_solo_ve_sus_propios_contadores(): void
    {
        [$a] = $this->dosEditoresConNoticias();

        $this->assertSame(
            ['Todas' => 3, 'Publicadas' => 2, 'Borradores' => 1],
            $this->contadores($this->pagina($a))
        );
    }

    public function test_el_admin_ve_los_contadores_totales(): void
    {
        $this->dosEditoresConNoticias();

        $this->assertSame(
            ['Todas' => 7, 'Publicadas' => 3, 'Borradores' => 4],
            $this->contadores($this->pagina(User::factory()->admin()->create()))
        );
    }

    public function test_los_contadores_no_cambian_al_filtrar_por_estado(): void
    {
        [$a] = $this->dosEditoresConNoticias();

        foreach (['publicada', 'borrador'] as $estado) {
            $this->assertSame(
                ['Todas' => 3, 'Publicadas' => 2, 'Borradores' => 1],
                $this->contadores($this->pagina($a, ['estado' => $estado]))
            );
        }
    }

    public function test_los_contadores_respetan_la_busqueda(): void
    {
        [$a] = $this->dosEditoresConNoticias();

        // Solo el borrador de A contiene "expansión": cada número es lo que mostrará su pestaña.
        $this->assertSame(
            ['Todas' => 1, 'Publicadas' => 0, 'Borradores' => 1],
            $this->contadores($this->pagina($a, ['q' => 'expansión']))
        );
    }

    public function test_los_contadores_salen_de_una_sola_consulta_agrupada(): void
    {
        [$a] = $this->dosEditoresConNoticias();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->pagina($a);
        $consultas = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $agrupadas = array_filter($consultas, fn (string $sql) => str_contains(strtolower($sql), 'group by "status"')
            || str_contains(strtolower($sql), 'group by `status`'));

        $this->assertCount(1, $agrupadas, 'Los tres contadores deben salir de un único GROUP BY status.');
    }

    public function test_el_numero_de_consultas_no_crece_con_las_filas(): void
    {
        $contar = function (User $usuario): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->pagina($usuario);
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $admin = User::factory()->admin()->create();
        Noticia::factory()->count(2)->create();
        // Calentamiento: la primera petición carga el rol del $admin del test y lo deja
        // en memoria; sin esto la primera medición tendría una consulta de más.
        $contar($admin);
        $conDos = $contar($admin);

        // Más filas y de más autores (avatar, nombre, conteo de comentarios): mismas consultas.
        Noticia::factory()->count(8)->create();
        $conDiez = $contar($admin);

        $this->assertSame($conDos, $conDiez);
    }

    // ---------- NP-20 paso 3: tabla ----------

    /**
     * @return list<string>
     */
    private function encabezados(string $html): array
    {
        $xpath = $this->xpath($html);
        $textos = [];

        foreach ($xpath->query('//table[contains(@class,"panel-tabla")]/thead//th') as $th) {
            $this->assertSame('col', $th->getAttribute('scope'));
            $textos[] = trim($th->textContent);
        }

        return $textos;
    }

    public function test_la_tabla_del_admin_tiene_columna_autor_y_la_del_editor_no(): void
    {
        [$a] = $this->dosEditoresConNoticias();

        $htmlAdmin = $this->pagina(User::factory()->admin()->create());
        $this->assertSame(['Título', 'Estado', 'Autor', 'Actualizada', 'Comentarios', 'Acciones'], $this->encabezados($htmlAdmin));
        $this->assertSame(7, $this->xpath($htmlAdmin)->query('//td[@data-columna="Autor"]')->length);

        $htmlEditor = $this->pagina($a);
        $this->assertSame(['Título', 'Estado', 'Actualizada', 'Comentarios', 'Acciones'], $this->encabezados($htmlEditor));
        $this->assertSame(0, $this->xpath($htmlEditor)->query('//td[@data-columna="Autor"]')->length);
    }

    public function test_la_tabla_tiene_caption_y_el_titulo_es_encabezado_de_fila(): void
    {
        $admin = User::factory()->admin()->create();
        $noticia = Noticia::factory()->for($admin)->create(['title' => 'Título de fila', 'excerpt' => 'Resumen visible']);

        $xpath = $this->xpath($this->pagina($admin));

        $caption = $xpath->query('//table[contains(@class,"panel-tabla")]/caption')->item(0);
        $this->assertNotNull($caption);
        $this->assertSame('sr-only', $caption->getAttribute('class'));
        $this->assertSame('Redacción: noticias del panel, página 1 de 1', trim($caption->textContent));

        $fila = $xpath->query('//tbody/tr/th[@scope="row"]')->item(0);
        $this->assertNotNull($fila);
        $enlace = $xpath->query('.//a', $fila)->item(0);
        $this->assertSame(route('noticias.show', $noticia), $enlace->getAttribute('href'));
        $this->assertSame('Título de fila', trim($enlace->textContent));
        $this->assertStringContainsString('Resumen visible', $fila->textContent);
    }

    public function test_cada_fila_tiene_el_chip_de_su_estado(): void
    {
        $editor = User::factory()->editor()->create();
        Noticia::factory()->for($editor)->create(['title' => 'Fila publicada', 'status' => 'publicada']);
        Noticia::factory()->for($editor)->borrador()->create(['title' => 'Fila borrador']);

        $xpath = $this->xpath($this->pagina($editor));

        foreach (['Fila publicada' => ['chip-publicada', 'Publicada'], 'Fila borrador' => ['chip-borrador', 'Borrador']] as $titulo => [$clase, $texto]) {
            $chip = $xpath->query('//tbody/tr[th//a[normalize-space()="'.$titulo.'"]]/td[@data-columna="Estado"]/span')->item(0);
            $this->assertNotNull($chip, "Sin chip en {$titulo}");
            $this->assertSame("chip chip-estado {$clase}", $chip->getAttribute('class'));
            $this->assertSame($texto, trim($chip->textContent));
        }
    }

    public function test_la_fecha_de_actualizacion_usa_time_con_iso_y_fecha_absoluta(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = Noticia::factory()->for($editor)->create();
        $noticia->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();

        $time = $this->xpath($this->pagina($editor))->query('//td[@data-columna="Actualizada"]/time')->item(0);

        $this->assertNotNull($time);
        $this->assertSame($noticia->fresh()->updated_at->toIso8601String(), $time->getAttribute('datetime'));
        $this->assertSame($noticia->fresh()->updated_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm'), $time->getAttribute('title'));
        $this->assertSame('hace 3 días', trim($time->textContent));
    }

    public function test_acciones_por_fila_segun_las_policies(): void
    {
        $admin = User::factory()->admin()->create();
        $editor = User::factory()->editor()->create();
        $propia = Noticia::factory()->for($editor)->create(['title' => 'Propia']);
        $ajena = Noticia::factory()->for($admin)->create(['title' => 'Del admin']);

        // Editor: acciones en la suya; la ajena ni aparece.
        $htmlEditor = $this->pagina($editor);
        $this->assertStringContainsString('href="'.route('noticias.edit', $propia).'"', $htmlEditor);
        $this->assertStringContainsString('action="'.route('noticias.destroy', $propia).'"', $htmlEditor);
        $this->assertStringNotContainsString(route('noticias.edit', $ajena), $htmlEditor);
        $this->assertStringNotContainsString(route('noticias.destroy', $ajena), $htmlEditor);

        // Admin: acciones en todas.
        $htmlAdmin = $this->pagina($admin);
        foreach ([$propia, $ajena] as $noticia) {
            $this->assertStringContainsString('href="'.route('noticias.edit', $noticia).'"', $htmlAdmin);
            $this->assertStringContainsString('action="'.route('noticias.destroy', $noticia).'"', $htmlAdmin);
        }
    }

    public function test_el_formulario_de_eliminar_lleva_csrf_delete_y_confirmacion(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = Noticia::factory()->for($editor)->create(['title' => 'Para borrar']);

        $xpath = $this->xpath($this->pagina($editor));
        $form = $xpath->query('//form[@action="'.route('noticias.destroy', $noticia).'"]')->item(0);

        $this->assertNotNull($form);
        $this->assertSame('POST', $form->getAttribute('method'));
        $this->assertSame(1, $xpath->query('.//input[@name="_token"][@type="hidden"]', $form)->length);
        $this->assertSame('DELETE', $xpath->query('.//input[@name="_method"]', $form)->item(0)?->getAttribute('value'));

        $boton = $xpath->query('.//button[@type="submit"]', $form)->item(0);
        $this->assertSame('¿Seguro que quieres eliminar «Para borrar»?', $boton->getAttribute('data-confirm'));
        $this->assertSame('Eliminar «Para borrar»', preg_replace('/\s+/', ' ', trim($boton->textContent)));
    }

    public function test_las_acciones_nombran_la_noticia_para_el_lector_de_pantalla(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = Noticia::factory()->for($editor)->create(['title' => 'Mi noticia']);

        $enlace = $this->xpath($this->pagina($editor))->query('//a[@href="'.route('noticias.edit', $noticia).'"]')->item(0);

        $this->assertNotNull($enlace);
        $this->assertSame('Editar «Mi noticia»', preg_replace('/\s+/', ' ', trim($enlace->textContent)));
        $this->assertSame('true', $enlace->getElementsByTagName('svg')->item(0)->getAttribute('aria-hidden'));
    }

    public function test_los_titulos_con_html_se_escapan_en_la_tabla(): void
    {
        $editor = User::factory()->editor()->create();
        Noticia::factory()->for($editor)->create(['title' => '<img src=x onerror=alert(1)> & "comillas"']);

        $html = $this->pagina($editor);
        $escapado = '&lt;img src=x onerror=alert(1)&gt; &amp; &quot;comillas&quot;';

        $this->assertStringNotContainsString('<img src=x', $html);
        // Enlace del título, texto sr-only de las acciones y data-confirm.
        $this->assertStringContainsString('class="panel-tabla-enlace">'.$escapado.'</a>', $html);
        $this->assertStringContainsString('<span class="sr-only"> «'.$escapado.'»</span>', $html);
        $this->assertStringContainsString('data-confirm="¿Seguro que quieres eliminar «'.$escapado.'»?"', $html);
    }

    public function test_el_panel_ya_no_usa_las_tarjetas_de_la_revista(): void
    {
        $editor = User::factory()->editor()->create();
        Noticia::factory()->for($editor)->create();

        $html = $this->pagina($editor);

        $this->assertStringContainsString('<table class="panel-tabla" role="table">', $html);
        $this->assertStringNotContainsString('class="tarjeta', $html);
    }

    public function test_la_tabla_declara_roles_aria_para_conservar_la_semantica_al_apilarse(): void
    {
        // En pantallas estrechas el CSS pone display: block a la tabla y algunos navegadores
        // dejan de exponerla como tabla; los roles explícitos la mantienen.
        $admin = User::factory()->admin()->create();
        Noticia::factory()->count(2)->for($admin)->create();

        $xpath = $this->xpath($this->pagina($admin));
        $tabla = '//table[contains(@class,"panel-tabla")]';

        $this->assertSame(1, $xpath->query($tabla.'[@role="table"]')->length);
        $this->assertSame(1, $xpath->query($tabla.'/caption[@class="sr-only"]')->length);
        $this->assertSame(2, $xpath->query($tabla.'/*[self::thead or self::tbody][@role="rowgroup"]')->length);
        $this->assertSame(3, $xpath->query($tabla.'//tr')->length);
        $this->assertSame(0, $xpath->query($tabla.'//tr[not(@role="row")]')->length);
        $this->assertSame(6, $xpath->query($tabla.'/thead//th[@scope="col"][@role="columnheader"]')->length);
        $this->assertSame(0, $xpath->query($tabla.'/thead//th[not(@role="columnheader")]')->length);
        $this->assertSame(2, $xpath->query($tabla.'/tbody//th[@scope="row"][@role="rowheader"]')->length);
        $this->assertSame(0, $xpath->query($tabla.'/tbody//th[not(@role="rowheader")]')->length);
        // 5 celdas por fila (Estado, Autor, Actualizada, Comentarios, Acciones), todas role="cell".
        $this->assertSame(10, $xpath->query($tabla.'//td[@role="cell"]')->length);
        $this->assertSame(0, $xpath->query($tabla.'//td[not(@role="cell")]')->length);
    }

    public function test_la_paginacion_tiene_etiqueta_propia(): void
    {
        $admin = User::factory()->admin()->create();
        Noticia::factory()->count(12)->for($admin)->create();

        $this->assertStringContainsString('Paginación de redacción', $this->pagina($admin));
    }
}
