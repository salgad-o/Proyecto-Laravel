<?php

namespace Tests\Feature;

use App\Models\Noticia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuscarNoticiasTest extends TestCase
{
    use RefreshDatabase;

    public function test_busca_por_titulo_y_por_excerpt_sin_distinguir_mayusculas(): void
    {
        Noticia::factory()->create([
            'title' => 'Aventura Galáctica',
            'excerpt' => 'Un viaje por las estrellas',
        ]);

        Noticia::factory()->create([
            'title' => 'Otra noticia',
            'excerpt' => 'Hablamos de AVENTURA y exploración',
        ]);

        Noticia::factory()->create([
            'title' => 'Sin relación',
            'excerpt' => 'Nada que ver',
        ]);

        $this->get(route('noticias.index', ['q' => 'aventura']))
            ->assertOk()
            ->assertSee('value="aventura"', false)
            ->assertSee('Resultados para', false)
            ->assertSee('"aventura" (2)', false)
            ->assertSee('Aventura Galáctica')
            ->assertSee('Otra noticia')
            ->assertDontSee('Sin relación');
    }

    public function test_un_borrador_que_coincide_por_excerpt_no_aparece(): void
    {
        Noticia::factory()->create([
            'title' => 'Noticia publicada sobre dragones',
            'excerpt' => 'Dragones y mazmorras',
        ]);

        Noticia::factory()->borrador()->create([
            'title' => 'Borrador secreto',
            'excerpt' => 'dragones por todas partes',
        ]);

        $this->get(route('noticias.index', ['q' => 'dragones']))
            ->assertOk()
            ->assertSee('Noticia publicada sobre dragones')
            ->assertDontSee('Borrador secreto')
            ->assertDontSee('dragones por todas partes');
    }

    public function test_una_inyeccion_sql_no_devuelve_todas_las_noticias(): void
    {
        Noticia::factory()->create([
            'title' => "' OR 1=1 --",
            'excerpt' => 'Texto literal de inyección',
        ]);

        Noticia::factory()->create([
            'title' => 'Noticia normal',
            'excerpt' => 'Sin coincidencias',
        ]);

        $this->get(route('noticias.index', ['q' => "' OR 1=1 --"]))
            ->assertOk()
            ->assertSee('&#039; OR 1=1 --', false)
            ->assertDontSee('Noticia normal');
    }

    public function test_el_porcentaje_se_busca_literalmente(): void
    {
        Noticia::factory()->create([
            'title' => 'Logros al 100% completados',
            'excerpt' => 'Guía de logros',
        ]);

        Noticia::factory()->create([
            'title' => '100 juegos imprescindibles',
            'excerpt' => 'Guía de juegos',
        ]);

        $this->get(route('noticias.index', ['q' => '100%']))
            ->assertOk()
            ->assertSee('Logros al 100% completados')
            ->assertDontSee('100 juegos imprescindibles');
    }

    public function test_el_guion_bajo_se_busca_literalmente(): void
    {
        Noticia::factory()->create([
            'title' => 'Guía a_b de prueba',
            'excerpt' => 'Guía de prueba',
        ]);

        Noticia::factory()->create([
            'title' => 'Guía axb de prueba',
            'excerpt' => 'Guía de prueba',
        ]);

        $this->get(route('noticias.index', ['q' => '_']))
            ->assertOk()
            ->assertSee('Guía a_b de prueba')
            ->assertDontSee('Guía axb de prueba');
    }

    public function test_q_de_101_caracteres_da_error_y_de_100_se_acepta(): void
    {
        $this->get(route('noticias.index', ['q' => str_repeat('a', 101)]))
            ->assertRedirect(route('noticias.index'))
            ->assertSessionHasErrors('q');

        $this->get(route('noticias.index', ['q' => str_repeat('a', 100)]))
            ->assertOk();
    }

    public function test_el_termino_con_script_se_muestra_escapado(): void
    {
        $this->get(route('noticias.index', ['q' => '<script>alert(1)</script>']))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_la_paginacion_conserva_q(): void
    {
        Noticia::factory()->count(10)->create(['title' => 'Buscar aventura']);

        $html = $this->get(route('noticias.index', ['q' => 'buscar']))
            ->assertOk()
            ->assertSee('aria-label="Paginación"', false)
            ->getContent();

        $this->assertStringContainsString('q=buscar', $html);
        $this->assertStringContainsString('page=2', $html);
    }

    public function test_sin_resultados_responde_200_con_el_mensaje(): void
    {
        Noticia::factory()->create(['title' => 'Noticia existente']);

        $this->get(route('noticias.index', ['q' => 'zzzz-no-existe']))
            ->assertOk()
            ->assertSee('No encontramos noticias para "zzzz-no-existe".', false)
            ->assertSee('Limpiar búsqueda');
    }

    public function test_sin_q_el_listado_funciona_igual_que_antes(): void
    {
        Noticia::factory()->create(['title' => 'Noticia visible sin búsqueda']);

        $this->get(route('noticias.index'))
            ->assertOk()
            ->assertSee('Noticia visible sin búsqueda')
            ->assertDontSee('Resultados para')
            ->assertDontSee('Limpiar');
    }

    public function test_q_cero_se_trata_como_busqueda(): void
    {
        Noticia::factory()->create(['title' => 'Nivel 0']);

        $this->get(route('noticias.index', ['q' => '0']))
            ->assertOk()
            ->assertSee('Resultados para', false)
            ->assertSee('Nivel 0')
            ->assertDontSee('No hay noticias publicadas todavía.');
    }

    public function test_q_enviado_como_arreglo_da_error_de_validacion(): void
    {
        $this->get(route('noticias.index', ['q' => ['x']]))
            ->assertRedirect(route('noticias.index'))
            ->assertSessionHasErrors('q');
    }

    public function test_el_formulario_de_busqueda_es_get_y_no_lleva_csrf(): void
    {
        $html = $this->get(route('noticias.index'))->getContent();

        $this->assertSame(1, preg_match(
            '#<form method="GET" action="[^"]*" class="buscador-noticias"[^>]*>(.*?)</form>#s',
            $html,
            $formulario
        ));

        $this->assertStringNotContainsString('_token', $formulario[1]);
    }
}
