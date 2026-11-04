<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NP-43 paso 2b: barra lateral plegable en escritorio.
 *
 * Los Feature tests no ejecutan JS: aquí se cubre el marcado y los textos
 * honestos. El comportamiento en el navegador se prueba a mano.
 */
class BarraPlegableTest extends TestCase
{
    use RefreshDatabase;

    public function test_cookies_lista_la_preferencia_de_la_barra_y_cuando_se_usa(): void
    {
        $html = $this->get(route('legal.cookies'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<tr>\s*<td><code>np_barra_plegada</code></td>(.*?)</tr>#s', $html, $fila));
        $this->assertStringContainsString('Almacenamiento local del navegador (preferencia)', $fila[1]);
        $this->assertStringContainsString('Recuerda si ocultaste la barra lateral', $fila[1]);
        $this->assertStringContainsString('Solo se usa si eliges "Aceptar todas"', $fila[1]);
        $this->assertStringContainsString('Hasta que la borres o elijas "Solo necesarias"', $fila[1]);
    }

    public function test_cookies_ya_no_dice_que_solo_hay_cookies_necesarias(): void
    {
        $this->get(route('legal.cookies'))
            ->assertOk()
            ->assertDontSee('solo usa cookies propias y necesarias')
            ->assertSee('preferencia opcional');
    }

    public function test_el_banner_explica_que_aceptar_todas_recuerda_preferencias(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<p class="banner-cookies-texto">(.*?)</p>#s', $html, $texto));
        $this->assertStringContainsString('Las cookies necesarias se usan siempre.', $texto[1]);
        $this->assertStringContainsString('"Aceptar todas" además recuerda preferencias, como la barra lateral.', $texto[1]);
    }

    public function test_el_boton_del_menu_controla_la_barra_lateral(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<button\b[^>]*id="menu-boton"[^>]*>#', $html, $boton));
        $this->assertStringContainsString('aria-controls="menu-lateral"', $boton[0]);
        $this->assertMatchesRegularExpression('#aria-expanded="(true|false)"#', $boton[0]);
        $this->assertStringContainsString('<aside id="menu-lateral"', $html);
    }

    public function test_el_html_no_trae_las_clases_que_pone_el_js(): void
    {
        // 'js', 'barra-plegada' y 'barra-transicion' solo las agrega app.js:
        // sin JS la página se ve como siempre (barra visible, sin botón en escritorio).
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<html\b[^>]*>#', $html, $etiqueta));
        $this->assertStringNotContainsString('class=', $etiqueta[0]);
    }

    public static function paginas(): array
    {
        return [
            'inicio' => ['home'],
            'noticias' => ['noticias.index'],
            'cookies' => ['legal.cookies'],
            'login' => ['login'],
        ];
    }

    #[DataProvider('paginas')]
    public function test_el_layout_no_tiene_scripts_ni_estilos_en_linea(string $ruta): void
    {
        $html = $this->get(route($ruta))->assertOk()->getContent();

        $this->assertStringNotContainsString('style=', $html);
        $this->assertDoesNotMatchRegularExpression('#\son[a-z]+=#i', $html);
        $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#i', $html);
    }
}
