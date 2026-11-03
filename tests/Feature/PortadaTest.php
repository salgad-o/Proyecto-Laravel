<?php

namespace Tests\Feature;

use App\Models\Comentario;
use App\Models\Noticia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortadaTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_portada_muestra_publicadas_y_nunca_borradores(): void
    {
        Noticia::factory()->create(['title' => 'Noticia publicada visible']);
        Noticia::factory()->borrador()->create(['title' => 'Borrador ultrasecreto XK9']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Noticia publicada visible')
            ->assertDontSee('Borrador ultrasecreto XK9');
    }

    public function test_la_destacada_es_la_publicada_mas_reciente(): void
    {
        Noticia::factory()->create(['title' => 'Noticia antigua', 'published_at' => now()->subDays(3)]);
        $reciente = Noticia::factory()->create(['title' => 'Noticia reciente', 'published_at' => now()->subHour()]);
        Noticia::factory()->create(['title' => 'Noticia intermedia', 'published_at' => now()->subDay()]);

        $this->get(route('home'))
            ->assertViewHas('destacada', fn (Noticia $destacada) => $destacada->is($reciente))
            ->assertSeeInOrder(['NOTICIA DESTACADA', 'Noticia reciente', 'ÚLTIMAS NOTICIAS', 'Noticia intermedia', 'Noticia antigua']);
    }

    public function test_con_la_misma_fecha_la_destacada_es_la_de_id_mayor(): void
    {
        $fecha = now()->subHour();
        Noticia::factory()->create(['published_at' => $fecha]);
        $mayor = Noticia::factory()->create(['published_at' => $fecha]);

        $this->get(route('home'))
            ->assertViewHas('destacada', fn (Noticia $destacada) => $destacada->is($mayor));
    }

    public function test_muestra_como_maximo_siete_noticias(): void
    {
        foreach (range('A', 'H') as $indice => $letra) {
            Noticia::factory()->create([
                'title' => "Titular {$letra}",
                'published_at' => now()->subDays($indice),
            ]);
        }

        $respuesta = $this->get(route('home'))
            ->assertViewHas('ultimas', fn ($ultimas) => $ultimas->count() === 6)
            ->assertDontSee('Titular H');

        foreach (range('A', 'G') as $letra) {
            $respuesta->assertSee("Titular {$letra}");
        }
    }

    public function test_con_una_sola_noticia_no_aparece_la_seccion_de_ultimas(): void
    {
        Noticia::factory()->create();

        $this->get(route('home'))
            ->assertSee('NOTICIA DESTACADA')
            ->assertDontSee('ÚLTIMAS NOTICIAS');
    }

    public function test_estado_vacio_cuando_no_hay_publicadas(): void
    {
        Noticia::factory()->borrador()->create();

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Todavía no hay noticias publicadas.')
            ->assertDontSee('NOTICIA DESTACADA')
            ->assertDontSee(route('noticias.create'));
    }

    public function test_el_estado_vacio_ofrece_crear_solo_a_quien_puede(): void
    {
        $this->actingAs(User::factory()->editor()->create())
            ->get(route('home'))
            ->assertSee(route('noticias.create'));

        $this->actingAs(User::factory()->lector()->create())
            ->get(route('home'))
            ->assertDontSee(route('noticias.create'));
    }

    public function test_titulo_y_resumen_con_html_se_muestran_escapados(): void
    {
        $conHtml = [
            'title' => '<script>alert("titulo")</script>',
            'excerpt' => '<img src=x onerror="alert(1)">',
        ];
        Noticia::factory()->create($conHtml + ['published_at' => now()]);
        Noticia::factory()->create($conHtml + ['published_at' => now()->subDay()]);

        $this->get(route('home'))
            ->assertDontSee('<script>alert', false)
            ->assertDontSee('<img src=x', false)
            ->assertSee('&lt;script&gt;alert', false)
            ->assertSee('&lt;img src=x', false);
    }

    public function test_sin_resumen_usa_el_cuerpo_recortado(): void
    {
        Noticia::factory()->create([
            'excerpt' => null,
            'body' => 'Inicio del cuerpo '.str_repeat('relleno ', 40).'FINAL-OCULTO',
        ]);

        $this->get(route('home'))
            ->assertSee('Inicio del cuerpo')
            ->assertDontSee('FINAL-OCULTO');
    }

    public function test_muestra_el_numero_de_comentarios(): void
    {
        $destacada = Noticia::factory()->create(['published_at' => now()]);
        $otra = Noticia::factory()->create(['published_at' => now()->subDay()]);
        Comentario::factory()->count(3)->create(['noticia_id' => $destacada->id]);
        Comentario::factory()->count(5)->create(['noticia_id' => $otra->id]);

        $this->get(route('home'))
            ->assertSeeText('3 comentarios')
            ->assertSeeText('5 comentarios');
    }

    public function test_las_fechas_relativas_estan_en_espanol(): void
    {
        Noticia::factory()->create(['published_at' => now()]);
        Noticia::factory()->create(['published_at' => now()->subHours(2)]);

        $this->get(route('home'))->assertSee('hace 2 horas');
    }

    public function test_la_tarjeta_tiene_un_solo_enlace_accesible(): void
    {
        Noticia::factory()->create(['published_at' => now()]);
        $tarjeta = Noticia::factory()->create(['published_at' => now()->subDay()]);

        $html = $this->get(route('home'))->getContent();
        preg_match('#<article class="tarjeta">.*?</article>#s', $html, $coincidencia);
        $enlaces = substr_count($coincidencia[0], 'href="'.route('noticias.show', $tarjeta).'"');

        $this->assertSame(2, $enlaces);
        $this->assertStringContainsString('tabindex="-1" aria-hidden="true"', $coincidencia[0]);
    }

    public function test_sin_n_mas_1_las_consultas_no_crecen_con_las_filas(): void
    {
        // La fábrica crea un autor distinto para cada noticia.
        Noticia::factory()->count(2)->create();
        $conDos = $this->contarConsultasDePortada();

        Noticia::factory()->count(5)->create();
        $conSiete = $this->contarConsultasDePortada();

        $this->assertSame(7, User::whereHas('noticias')->count());
        $this->assertSame($conDos, $conSiete);
    }

    public function test_tiempo_de_lectura_de_un_texto_corto_es_un_minuto(): void
    {
        $this->assertSame(1, (new Noticia(['body' => 'Pocas palabras aquí.']))->minutosLectura());
        $this->assertSame(1, (new Noticia(['body' => '']))->minutosLectura());
    }

    public function test_tiempo_de_lectura_cuenta_palabras_con_tildes(): void
    {
        // str_word_count partiría "música" en dos: aquí cada una debe contar como una sola.
        $seiscientas = trim(str_repeat("música  día\nacción ", 200));

        $this->assertSame(3, (new Noticia(['body' => $seiscientas]))->minutosLectura());
        $this->assertSame(4, (new Noticia(['body' => $seiscientas.' más']))->minutosLectura());
    }

    private function contarConsultasDePortada(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('home'))->assertOk();

        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    }
}
