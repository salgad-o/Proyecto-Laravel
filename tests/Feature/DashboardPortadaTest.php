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

/**
 * NP-20 paso 7: portada de /dashboard por rol ("Lo que requiere tu atención").
 * Datos fijos: los títulos, nombres y textos que se comparan no vienen de Faker.
 */
class DashboardPortadaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['name' => 'Ana Admin', 'email' => 'ana@nexus.test']);
    }

    private function editor(string $nombre = 'Eva Editora', string $email = 'eva@nexus.test'): User
    {
        return User::factory()->editor()->create(['name' => $nombre, 'email' => $email]);
    }

    private function xpath(string $html): DOMXPath
    {
        $anterior = libxml_use_internal_errors(true);
        $documento = new DOMDocument;
        $documento->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return new DOMXPath($documento);
    }

    /** Texto de cada <li> de un bloque, o null si el bloque no existe. */
    private function items(string $html, string $idBloque): ?array
    {
        $xpath = $this->xpath($html);
        $bloque = $xpath->query('//section[@aria-labelledby="'.$idBloque.'"]')->item(0);

        if ($bloque === null) {
            return null;
        }

        return array_map(
            fn ($li) => trim((string) preg_replace('/\s+/u', ' ', $li->textContent)),
            iterator_to_array($xpath->query('.//ul[@class="escritorio-lista"]/li', $bloque))
        );
    }

    private function registro(string $accion, User $sobre, array $extra = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'user_id' => null,
            'action' => $accion,
            'auditable_type' => $sobre->getMorphClass(),
            'auditable_id' => $sobre->id,
            'ip_address' => '203.0.113.99',
            'user_agent' => 'AgenteSecretoDePrueba/1.0',
        ], $extra));
    }

    // ---------- Acceso ----------

    public function test_un_visitante_va_al_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_un_lector_va_a_su_perfil(): void
    {
        $this->actingAs(User::factory()->lector()->create())
            ->get(route('dashboard'))
            ->assertRedirect(route('perfil.edit'));
    }

    // ---------- Bloques por rol ----------

    public function test_el_admin_ve_sus_bloques_el_panel_lateral_y_el_saludo(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $this->assertSame('Hola, Ana Admin', trim($xpath->query('//h1')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//a[@href="'.route('noticias.create').'"][normalize-space()="+ Nuevo artículo"]')->length);

        foreach (['Tus borradores', 'Tus publicaciones recientes', 'Comentarios recientes'] as $titulo) {
            $this->assertSame(1, $xpath->query('//section/h2[normalize-space()="'.$titulo.'"]')->length, $titulo);
        }
        // NP-21 paso 2: "Actividad importante" pasó al panel lateral ("Actividad reciente").
        $this->assertSame(0, $xpath->query('//section[@aria-labelledby="bloque-actividad"]')->length);
        $this->assertSame('Actividad reciente', trim($xpath->query('//aside[@aria-labelledby="lateral-titulo"]/h2')->item(0)->textContent));

        // "Ver todo" de cada bloque.
        $this->assertStringContainsString('href="'.route('dashboard.noticias.index', ['estado' => 'borrador']).'"', $html);
        $this->assertStringContainsString('href="'.route('comentarios.index').'"', $html);
        $this->assertStringContainsString('href="'.route('auditoria.index').'"', $html);
    }

    public function test_el_editor_solo_ve_sus_bloques_de_noticias(): void
    {
        $html = $this->actingAs($this->editor())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Tus borradores', $html);
        $this->assertStringContainsString('Tus publicaciones recientes', $html);
        $this->assertStringContainsString('+ Nuevo artículo', $html);
        foreach (['Comentarios recientes', 'Actividad importante', 'bloque-comentarios', 'bloque-actividad'] as $texto) {
            $this->assertStringNotContainsString($texto, $html);
        }
        $this->assertStringNotContainsString(route('auditoria.index'), $html);
        $this->assertStringNotContainsString(route('comentarios.index'), $html);
        $this->assertStringContainsString('Tu actividad', $html);
        // Ajuste 2b: sin "Ver toda tu actividad" (/perfil/actividad muestra otra cosa).
        $this->assertStringNotContainsString('href="'.route('perfil.actividad').'"', $html);
    }

    public function test_para_el_editor_comentarios_y_auditoria_quedan_acotados_a_lo_suyo(): void
    {
        $editor = $this->editor();
        Comentario::factory()->create(['body' => 'Comentario cualquiera']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($editor)->get(route('dashboard'))->assertOk();
        $consultas = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        // Regla 2: lo que no está permitido ni se consulta. Desde NP-21 paso 2 el editor
        // consulta comentarios SOLO para contar los de sus noticias (subconsulta a noticias)
        // y audit_logs SOLO con su user_id (panel "Tu actividad"). Nunca la lista de
        // comentarios recientes ni la auditoría global.
        foreach ($consultas as $sql) {
            if (preg_match('/from ["`]comentarios["`]/i', $sql)) {
                // Solo la cifra: count con subconsulta a SUS noticias (… "user_id" = ? dentro del exists).
                $this->assertMatchesRegularExpression('/^select count\(\*\) as aggregate from ["`]comentarios["`] where exists \(select \* from ["`]noticias["`] .*["`]user_id["`] = \?\)$/i', $sql, $sql);
            }
            if (preg_match('/["`]audit_logs["`]/i', $sql)) {
                $this->assertMatchesRegularExpression('/["`]user_id["`] = \?/i', $sql, $sql);
            }
        }

        // Control: para el admin, las dos tablas sí se consultan.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin())->get(route('dashboard'))->assertOk();
        $sqlAdmin = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();

        $this->assertMatchesRegularExpression('/audit_logs/', $sqlAdmin);
        $this->assertMatchesRegularExpression('/comentarios/', $sqlAdmin);
    }

    // ---------- Tus borradores ----------

    public function test_cada_uno_ve_solo_sus_borradores(): void
    {
        $admin = $this->admin();
        $editor = $this->editor();
        $otro = $this->editor('Otro Editor', 'otro@nexus.test');

        Noticia::factory()->for($admin)->borrador()->create(['title' => 'Borrador del admin']);
        Noticia::factory()->for($editor)->borrador()->create(['title' => 'Borrador de Eva']);
        Noticia::factory()->for($otro)->borrador()->create(['title' => 'Borrador de otro']);

        $deAdmin = $this->items($this->actingAs($admin)->get(route('dashboard'))->getContent(), 'bloque-borradores');
        $this->assertCount(1, $deAdmin);
        $this->assertStringStartsWith('Borrador del admin', $deAdmin[0]);

        $htmlEditor = $this->actingAs($editor)->get(route('dashboard'))->getContent();
        $deEditor = $this->items($htmlEditor, 'bloque-borradores');
        $this->assertCount(1, $deEditor);
        $this->assertStringStartsWith('Borrador de Eva', $deEditor[0]);
        // Ni en cualquier otra parte de la página.
        $this->assertStringNotContainsString('Borrador del admin', $htmlEditor);
        $this->assertStringNotContainsString('Borrador de otro', $htmlEditor);
    }

    public function test_las_publicadas_no_son_borradores_y_se_muestran_como_maximo_cinco(): void
    {
        $editor = $this->editor();

        Noticia::factory()->for($editor)->create(['title' => 'Ya publicada', 'status' => 'publicada']);
        foreach (range(1, 7) as $i) {
            Noticia::factory()->for($editor)->borrador()->create(['title' => "Borrador número {$i}"]);
        }

        $html = $this->actingAs($editor)->get(route('dashboard'))->getContent();
        $items = $this->items($html, 'bloque-borradores');

        $this->assertCount(5, $items);
        // La publicada no está entre los borradores (sí en "Tus publicaciones recientes", ajuste 7b).
        $this->assertStringNotContainsString('Ya publicada', implode(' ', $items));

        // Cada borrador enlaza a su edición.
        $xpath = $this->xpath($html);
        $this->assertSame(5, $xpath->query('//section[@aria-labelledby="bloque-borradores"]//a[contains(@href,"/edit")]')->length);
    }

    // ---------- Comentarios recientes ----------

    public function test_los_comentarios_salen_escapados_recortados_y_con_su_noticia(): void
    {
        $admin = $this->admin();
        $autora = User::factory()->lector()->create(['name' => 'Lola Lectora', 'email' => 'lola@nexus.test']);
        $noticia = Noticia::factory()->create(['title' => 'Noticia comentada']);
        Comentario::factory()->for($autora)->for($noticia)->create(['body' => '<script>alert(1)</script>']);
        Comentario::factory()->for($autora)->for($noticia)->create(['body' => str_repeat('ñ', 90)]);

        $html = $this->actingAs($admin)->get(route('dashboard'))->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString(str_repeat('ñ', 80).'…', $html);
        $this->assertStringNotContainsString(str_repeat('ñ', 81), $html);

        $items = $this->items($html, 'bloque-comentarios');
        $this->assertCount(2, $items);
        $this->assertStringContainsString('Lola Lectora en Noticia comentada', $items[0]);
        $this->assertStringContainsString('href="'.route('noticias.show', $noticia).'#comentarios"', $html);
    }

    public function test_como_maximo_cinco_comentarios(): void
    {
        $noticia = Noticia::factory()->create(['title' => 'Muy comentada']);
        Comentario::factory()->count(7)->for($noticia)->create(['body' => 'Texto fijo']);

        $items = $this->items($this->actingAs($this->admin())->get(route('dashboard'))->getContent(), 'bloque-comentarios');

        $this->assertCount(5, $items);
    }

    // ---------- Estados vacíos y calidad ----------

    public function test_estados_vacios(): void
    {
        // Admin creado sin eventos: así tampoco hay registros de auditoría.
        $admin = User::withoutEvents(fn () => $this->admin());

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('No tienes borradores. ¡Empieza uno nuevo!', $html);
        $this->assertStringContainsString('Todavía no hay comentarios.', $html);
        $this->assertStringContainsString('Todavía no hay actividad registrada.', $html);
        $this->assertStringNotContainsString('class="escritorio-lista"', $html);

        $this->assertStringContainsString(
            'No tienes borradores. ¡Empieza uno nuevo!',
            $this->actingAs($this->editor())->get(route('dashboard'))->getContent()
        );
    }

    public function test_las_consultas_no_crecen_con_los_datos(): void
    {
        $admin = $this->admin();

        $contar = function () use ($admin): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin->fresh())->get(route('dashboard'))->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        Comentario::factory()->count(1)->create(['body' => 'Uno']);
        $this->registro('cerrar_sesiones', $admin, ['user_id' => $admin->id]);
        $pocos = $contar();

        foreach (range(1, 6) as $i) {
            $autor = User::factory()->lector()->create(['email' => "autor{$i}@nexus.test"]);
            $autor->forceFill(['avatar_path' => str_repeat('e', 39).$i.'.webp'])->saveQuietly();
            Comentario::factory()->for($autor)->create(['body' => "Comentario {$i}"]);
            Noticia::factory()->for($admin)->borrador()->create(['title' => "Borrador {$i}"]);
        }

        $this->assertSame($pocos, $contar());
    }

    public function test_la_vista_no_tiene_codigo_en_linea(): void
    {
        $html = $this->actingAs($this->admin())->get(route('dashboard'))->getContent();

        $this->assertStringNotContainsString('style=', $html);
        $this->assertDoesNotMatchRegularExpression('#<[^>]+\son[a-z]+=#i', $html);
        $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#i', $html);
    }

    // ---------- Tus publicaciones recientes (ajuste 7b) ----------

    public function test_el_bloque_de_publicaciones_aparece_para_admin_y_editor_en_su_orden(): void
    {
        $admin = $this->admin();

        foreach ([$admin, $this->editor()] as $usuario) {
            $xpath = $this->xpath($this->actingAs($usuario)->get(route('dashboard'))->getContent());

            $titulos = array_map(fn ($h2) => trim($h2->textContent), iterator_to_array($xpath->query('//section[contains(@class,"escritorio-bloque")]/h2')));
            $this->assertSame('Tus borradores', $titulos[0]);
            $this->assertSame('Tus publicaciones recientes', $titulos[1]);
            $this->assertStringContainsString('Aún no has publicado noticias.', $xpath->query('//section[@aria-labelledby="bloque-publicaciones"]')->item(0)->textContent);
            $this->assertSame(1, $xpath->query('//section[@aria-labelledby="bloque-publicaciones"]//a[@href="'.route('dashboard.noticias.index', ['estado' => 'publicada']).'"]')->length);
        }

        // Admin: borradores, publicaciones y comentarios, en ese orden; después, el panel lateral.
        $xpath = $this->xpath($this->actingAs($admin)->get(route('dashboard'))->getContent());
        $this->assertSame(
            ['bloque-borradores', 'bloque-publicaciones', 'bloque-comentarios', 'lateral-titulo'],
            array_map(fn ($s) => $s->getAttribute('aria-labelledby'), iterator_to_array($xpath->query('//section[contains(@class,"escritorio-bloque")]|//aside[@class="escritorio-lateral"]')))
        );
    }

    public function test_las_publicaciones_son_solo_del_usuario_y_nunca_borradores(): void
    {
        $editor = $this->editor();
        $otro = $this->editor('Otro Editor', 'otro@nexus.test');

        $mia = Noticia::factory()->for($editor)->create(['title' => 'Publicada por Eva', 'status' => 'publicada', 'published_at' => now()->subDay()]);
        Noticia::factory()->for($editor)->borrador()->create(['title' => 'Borrador de Eva']);
        Noticia::factory()->for($otro)->create(['title' => 'Publicada por otro', 'status' => 'publicada', 'published_at' => now()]);

        $html = $this->actingAs($editor)->get(route('dashboard'))->getContent();
        $items = $this->items($html, 'bloque-publicaciones');

        $this->assertCount(1, $items);
        $this->assertStringStartsWith('Publicada por Eva', $items[0]);
        $this->assertStringNotContainsString('Publicada por otro', $html);
        $this->assertStringNotContainsString('Borrador de Eva', implode(' ', $items));

        // Título a la noticia pública, fecha y "Editar" (la Policy lo permite: es suya).
        $xpath = $this->xpath($html);
        $bloque = $xpath->query('//section[@aria-labelledby="bloque-publicaciones"]')->item(0);
        $this->assertSame(1, $xpath->query('.//a[@href="'.route('noticias.show', $mia).'"]', $bloque)->length);
        $this->assertSame(1, $xpath->query('.//time[@datetime="'.$mia->published_at->toIso8601String().'"]', $bloque)->length);
        $editar = $xpath->query('.//a[@href="'.route('noticias.edit', $mia).'"]', $bloque)->item(0);
        $this->assertSame('Editar «Publicada por Eva»', preg_replace('/\s+/', ' ', trim($editar->textContent)));
    }

    public function test_el_admin_tampoco_ve_publicaciones_ajenas(): void
    {
        $admin = $this->admin();
        Noticia::factory()->for($this->editor())->create(['title' => 'Publicada por Eva', 'status' => 'publicada', 'published_at' => now()]);

        $html = $this->actingAs($admin)->get(route('dashboard'))->getContent();

        $this->assertSame([], $this->items($html, 'bloque-publicaciones'));
        $this->assertStringNotContainsString('Publicada por Eva', $html);
    }

    public function test_como_maximo_cinco_publicaciones_las_mas_recientes_primero(): void
    {
        $editor = $this->editor();

        foreach (range(1, 7) as $i) {
            Noticia::factory()->for($editor)->create([
                'title' => "Publicación {$i}",
                'status' => 'publicada',
                'published_at' => now()->subDays(10 - $i),
            ]);
        }

        $items = $this->items($this->actingAs($editor)->get(route('dashboard'))->getContent(), 'bloque-publicaciones');

        $this->assertCount(5, $items);
        // La 7 es la más reciente; la 1 y la 2, las más antiguas, quedan fuera.
        $this->assertStringStartsWith('Publicación 7', $items[0]);
        $this->assertStringStartsWith('Publicación 3', $items[4]);
    }
}
