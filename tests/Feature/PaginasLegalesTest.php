<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NP-43 paso 1: páginas legales públicas con textos de prueba.
 */
class PaginasLegalesTest extends TestCase
{
    use RefreshDatabase;

    /** Ruta con nombre => texto esperado en <title>. */
    public static function paginasLegales(): array
    {
        return [
            'cookies' => ['legal.cookies', 'Política de cookies'],
            'privacidad' => ['legal.privacidad', 'Política de privacidad'],
            'terminos' => ['legal.terminos', 'Términos de uso'],
        ];
    }

    #[DataProvider('paginasLegales')]
    public function test_un_visitante_ve_la_pagina_legal(string $ruta, string $titulo): void
    {
        $this->get(route($ruta))
            ->assertOk()
            ->assertSee("<title>{$titulo} · Nexus Plays</title>", false);
    }

    #[DataProvider('paginasLegales')]
    public function test_un_lector_con_sesion_ve_la_pagina_legal(string $ruta, string $titulo): void
    {
        $lector = User::factory()->lector()->create();

        $this->actingAs($lector)
            ->get(route($ruta))
            ->assertOk()
            ->assertSee("<title>{$titulo} · Nexus Plays</title>", false);
    }

    #[DataProvider('paginasLegales')]
    public function test_muestra_la_version_y_el_aviso_de_prueba(string $ruta): void
    {
        $this->get(route($ruta))
            ->assertOk()
            ->assertSee('Versión '.config('legal.version'))
            ->assertSee('Vigente desde')
            ->assertSee('Texto de prueba: este documento todavía no es la versión oficial.');
    }

    #[DataProvider('paginasLegales')]
    public function test_sin_modo_prueba_no_aparece_el_aviso(string $ruta): void
    {
        config(['legal.es_prueba' => false]);

        $this->get(route($ruta))
            ->assertOk()
            ->assertDontSee('Texto de prueba: este documento todavía no es la versión oficial.')
            ->assertDontSee('aviso-legal-prueba', false);
    }

    public function test_la_fecha_de_vigencia_se_muestra_en_formato_legible(): void
    {
        config(['legal.vigente_desde' => '2026-11-03']);

        $this->get(route('legal.cookies'))
            ->assertOk()
            ->assertSee('3 de noviembre de 2026');
    }

    // NP-43 paso 1c: el pie se quitó (decisión del PO) y los enlaces legales
    // pasaron al bloque "Más" de la barra lateral.
    public function test_la_barra_lateral_tiene_los_tres_enlaces_legales(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<aside id="menu-lateral".*?</aside>#s', $html, $barra));
        $this->assertStringContainsString('href="'.route('legal.cookies').'"', $barra[0]);
        $this->assertStringContainsString('href="'.route('legal.privacidad').'"', $barra[0]);
        $this->assertStringContainsString('href="'.route('legal.terminos').'"', $barra[0]);
    }

    public function test_privacidad_aclara_que_es_la_politica_de_tratamiento_de_datos(): void
    {
        $this->get(route('legal.privacidad'))
            ->assertOk()
            ->assertSee('<h1 class="pagina-titulo">Política de privacidad</h1>', false)
            ->assertSee('Política de tratamiento de datos personales según la Ley 1581 de 2012.');
    }

    public function test_terminos_se_titula_terminos_de_uso(): void
    {
        $this->get(route('legal.terminos'))
            ->assertOk()
            ->assertSee('<h1 class="pagina-titulo">Términos de uso</h1>', false)
            ->assertDontSee('Términos y condiciones');
    }

    public function test_cookies_lista_las_cookies_reales_y_no_anuncia_la_de_recordarme(): void
    {
        $html = $this->get(route('legal.cookies'))
            ->assertOk()
            ->assertSee('XSRF-TOKEN')
            ->assertSee('np_consentimiento_cookies')
            ->assertSee(config('session.cookie'))
            ->assertSee(config('session.lifetime').' minutos')
            ->getContent();

        // Solo se prohíbe anunciar la cookie de "recordarme" (remember_web_* en
        // Laravel), que el sitio no usa. La palabra "recordar" sí puede aparecer,
        // por ejemplo para explicar que np_consentimiento_cookies recuerda la elección.
        foreach (['remember_web', 'recordarme', 'Recordar sesión'] as $prohibido) {
            $this->assertStringNotContainsStringIgnoringCase($prohibido, $html);
        }
    }

    public function test_privacidad_no_contiene_correos_inventados(): void
    {
        $html = $this->get(route('legal.privacidad'))->assertOk()->getContent();

        $this->assertStringNotContainsString('@', $html);
        $this->assertStringContainsString('[Canal de contacto por definir]', $html);
    }

    public function test_el_login_ya_no_tiene_la_casilla_recordar_sesion(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('name="remember"', false)
            ->assertDontSee('Recordar sesión');
    }
}
