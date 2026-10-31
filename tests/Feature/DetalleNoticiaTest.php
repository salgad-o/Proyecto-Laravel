<?php

namespace Tests\Feature;

use App\Models\Comentario;
use App\Models\Noticia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DetalleNoticiaTest extends TestCase
{
    use RefreshDatabase;

    public function test_muestra_titulo_autor_tiempo_de_lectura_y_comentarios(): void
    {
        $autor = User::factory()->editor()->create(['name' => 'Ana Redactora']);
        $noticia = Noticia::factory()->create([
            'user_id' => $autor->id,
            'title' => 'Titular del detalle',
            'body' => trim(str_repeat('palabra ', 600)),
            'published_at' => now()->setDate(2026, 3, 5),
        ]);
        Comentario::factory()->count(3)->create(['noticia_id' => $noticia->id]);

        $this->get(route('noticias.show', $noticia))
            ->assertOk()
            ->assertSee('<h1 class="detalle-titulo">Titular del detalle</h1>', false)
            ->assertSee('Ana Redactora')
            ->assertSee('3 min de lectura')
            ->assertSee('5 de marzo de 2026')
            ->assertSeeText('3 comentarios')
            ->assertSee('Comentarios (3)');
    }

    public function test_el_cuerpo_se_divide_en_parrafos_por_lineas_en_blanco(): void
    {
        $noticia = Noticia::factory()->create([
            'body' => "Primer párrafo\n\nSegundo párrafo\r\n\r\nTercer párrafo\ncon salto simple",
        ]);

        $html = $this->get(route('noticias.show', $noticia))->getContent();

        $this->assertSame(3, substr_count($html, '<p class="detalle-parrafo">'));
        $this->assertStringContainsString('<p class="detalle-parrafo">Primer párrafo</p>', $html);
        $this->assertStringContainsString("<p class=\"detalle-parrafo\">Tercer párrafo\ncon salto simple</p>", $html);
    }

    public function test_un_cuerpo_con_html_se_muestra_escapado(): void
    {
        $noticia = Noticia::factory()->create([
            'body' => "<script>alert('xss')</script>\n\n<b>negrita</b>",
        ]);

        $this->get(route('noticias.show', $noticia))
            ->assertDontSee('<script>alert', false)
            ->assertDontSee('<b>negrita</b>', false)
            ->assertSee('&lt;script&gt;alert', false)
            ->assertSee('&lt;b&gt;negrita&lt;/b&gt;', false);
    }

    public function test_mas_noticias_muestra_tres_sin_la_actual_ni_borradores(): void
    {
        $actual = Noticia::factory()->create(['title' => 'Noticia actual XY', 'published_at' => now()]);
        Noticia::factory()->borrador()->create(['title' => 'Borrador oculto XY']);
        foreach (['Una', 'Dos', 'Tres', 'Cuatro', 'Cinco'] as $indice => $nombre) {
            Noticia::factory()->create(['title' => "Otra {$nombre}", 'published_at' => now()->subDays($indice + 1)]);
        }

        $html = $this->get(route('noticias.show', $actual))
            ->assertViewHas('masNoticias', fn ($otras) => $otras->count() === 3
                && $otras->pluck('title')->all() === ['Otra Una', 'Otra Dos', 'Otra Tres'])
            ->assertDontSee('Borrador oculto XY')
            ->getContent();

        $this->assertSame(1, preg_match('#<aside class="detalle-aside".*?</aside>#s', $html, $columna));
        $this->assertSame(3, substr_count($columna[0], 'class="mini-noticia"'));
        $this->assertStringNotContainsString('Noticia actual XY', $columna[0]);
    }

    public function test_sin_otras_noticias_no_aparece_la_columna(): void
    {
        $noticia = Noticia::factory()->create();

        $this->get(route('noticias.show', $noticia))
            ->assertDontSee('Más noticias')
            ->assertSee('class="detalle-rejilla detalle-rejilla-unica"', false);
    }

    public function test_visitante_y_lector_no_ven_editar_ni_eliminar_de_la_noticia(): void
    {
        $noticia = Noticia::factory()->create();

        $this->get(route('noticias.show', $noticia))
            ->assertDontSee(route('noticias.edit', $noticia))
            ->assertDontSee(route('noticias.destroy', $noticia).'"', false);

        $this->actingAs(User::factory()->lector()->create())
            ->get(route('noticias.show', $noticia))
            ->assertDontSee(route('noticias.edit', $noticia))
            ->assertDontSee('action="'.route('noticias.destroy', $noticia).'"', false);
    }

    public function test_el_autor_y_el_admin_ven_editar_y_eliminar_de_la_noticia(): void
    {
        $autor = User::factory()->editor()->create();
        $noticia = Noticia::factory()->create(['user_id' => $autor->id]);

        foreach ([$autor, User::factory()->admin()->create()] as $usuario) {
            $this->actingAs($usuario)
                ->get(route('noticias.show', $noticia))
                ->assertSee('href="'.route('noticias.edit', $noticia).'"', false)
                ->assertSee('action="'.route('noticias.destroy', $noticia).'"', false);
        }
    }

    public function test_el_autor_ve_el_aviso_de_borrador_y_un_visitante_sigue_recibiendo_403(): void
    {
        $autor = User::factory()->editor()->create();
        $borrador = Noticia::factory()->borrador()->create(['user_id' => $autor->id]);

        $this->get(route('noticias.show', $borrador))->assertForbidden();

        $this->actingAs($autor)
            ->get(route('noticias.show', $borrador))
            ->assertOk()
            ->assertSee('Borrador: solo tú y la administración pueden ver esta noticia');
    }

    public function test_el_visitante_ve_iniciar_sesion_para_comentar_y_no_el_formulario(): void
    {
        $noticia = Noticia::factory()->create();

        $this->get(route('noticias.show', $noticia))
            ->assertSee('Inicia sesión para comentar')
            ->assertDontSee('name="body"', false);
    }

    public function test_el_textarea_tiene_maxlength_y_contador(): void
    {
        $noticia = Noticia::factory()->create();
        $lector = User::factory()->lector()->create();

        $this->actingAs($lector)
            ->get(route('noticias.show', $noticia))
            ->assertSee('maxlength="1000"', false)
            ->assertSee('data-contador="contador-comentario"', false)
            ->assertSee('id="contador-comentario"', false)
            ->assertSee('aria-live="polite"', false);
    }

    public function test_el_formulario_de_edicion_conserva_su_logica(): void
    {
        $comentario = Comentario::factory()->create(['body' => 'Texto original']);

        $this->actingAs($comentario->user)
            ->get(route('comentarios.edit', $comentario))
            ->assertOk()
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('maxlength="1000"', false)
            ->assertSee('data-contador="contador-comentario"', false)
            ->assertSee('Texto original')
            ->assertSee('← Volver a la noticia');
    }

    public function test_un_nombre_de_autor_de_comentario_con_html_se_escapa(): void
    {
        $autor = User::factory()->lector()->create(['name' => '<script>alert("autor")</script>']);
        $comentario = Comentario::factory()->create(['user_id' => $autor->id]);

        $this->get(route('noticias.show', $comentario->noticia))
            ->assertDontSee('<script>alert', false)
            ->assertSee('&lt;script&gt;alert', false);
    }

    public function test_la_fecha_del_comentario_es_relativa_con_iso_y_fecha_absoluta(): void
    {
        $comentario = Comentario::factory()->create();
        $comentario->created_at = now()->subHours(2);
        $comentario->save();

        $this->get(route('noticias.show', $comentario->noticia))
            ->assertSee('hace 2 horas')
            ->assertSee('datetime="'.$comentario->created_at->toIso8601String().'"', false)
            ->assertSee('title="'.$comentario->created_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm').'"', false);
    }

    public function test_sin_n_mas_1_para_un_visitante(): void
    {
        $noticia = Noticia::factory()->create();

        // La fábrica crea un autor distinto para cada comentario.
        Comentario::factory()->count(2)->create(['noticia_id' => $noticia->id]);
        $conDos = $this->contarConsultasDeDetalle($noticia);

        Comentario::factory()->count(8)->create(['noticia_id' => $noticia->id]);
        $conDiez = $this->contarConsultasDeDetalle($noticia);

        $this->assertSame(10, $noticia->comentarios()->distinct()->count('user_id'));
        $this->assertSame($conDos, $conDiez);
    }

    public function test_sin_n_mas_1_para_un_admin_con_acciones(): void
    {
        $admin = User::factory()->admin()->create();
        $noticia = Noticia::factory()->create();

        // Instancia nueva en cada medición, como en una petición real.
        Comentario::factory()->count(2)->create(['noticia_id' => $noticia->id]);
        $this->actingAs($admin->fresh());
        $conDos = $this->contarConsultasDeDetalle($noticia);

        Comentario::factory()->count(8)->create(['noticia_id' => $noticia->id]);
        $this->actingAs($admin->fresh());
        $conDiez = $this->contarConsultasDeDetalle($noticia);

        $this->assertSame($conDos, $conDiez);
    }

    private function contarConsultasDeDetalle(Noticia $noticia): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('noticias.show', $noticia))->assertOk();

        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    }
}
