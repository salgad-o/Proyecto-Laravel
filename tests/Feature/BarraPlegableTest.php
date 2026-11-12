<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NP-43 paso 2b y NP-36 paso 1: barra lateral con modo de solo iconos en escritorio.
 *
 * Los Feature tests no ejecutan JS ni CSS: aquí se cubre el marcado y los textos
 * honestos. El comportamiento en el navegador se prueba a mano.
 */
class BarraPlegableTest extends TestCase
{
    use RefreshDatabase;

    /** Devuelve solo el <aside id="menu-lateral">...</aside>. */
    private function barra(string $html): string
    {
        $this->assertSame(1, preg_match('#<aside id="menu-lateral".*?</aside>#s', $html, $barra));

        return $barra[0];
    }

    /**
     * Devuelve los .menu-enlace de una lista (por su aria-labelledby) como
     * [data-etiqueta, texto visible sin etiquetas].
     */
    private function enlacesDeGrupo(string $barra, string $idGrupo): array
    {
        $this->assertSame(
            1,
            preg_match('#<ul class="menu-lista" aria-labelledby="'.$idGrupo.'">(.*?)</ul>#s', $barra, $lista)
        );

        preg_match_all('#<a\b([^>]*)>(.*?)</a>#s', $lista[1], $enlaces, PREG_SET_ORDER);
        $this->assertNotEmpty($enlaces);

        return array_map(function (array $enlace) {
            $this->assertStringContainsString('menu-enlace', $enlace[1]);
            $this->assertSame(1, preg_match('#data-etiqueta="([^"]*)"#', $enlace[1], $etiqueta), 'Falta data-etiqueta.');

            return [$etiqueta[1], trim(strip_tags($enlace[2]))];
        }, $enlaces);
    }

    public function test_cookies_lista_la_preferencia_de_la_barra_y_cuando_se_usa(): void
    {
        $html = $this->get(route('legal.cookies'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<tr>\s*<td><code>np_barra_plegada</code></td>(.*?)</tr>#s', $html, $fila));
        $this->assertStringContainsString('Almacenamiento local del navegador (preferencia)', $fila[1]);
        $this->assertStringContainsString('Recuerda si contrajiste la barra lateral a solo iconos', $fila[1]);
        $this->assertStringNotContainsString('ocultaste', $fila[1]);
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

    public function test_el_boton_movil_del_header_controla_la_barra_lateral(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<header\b.*?</header>#s', $html, $header));
        $this->assertSame(1, preg_match('#<button\b[^>]*id="menu-boton"[^>]*>(.*?)</button>#s', $header[0], $boton));
        $this->assertStringContainsString('aria-controls="menu-lateral"', $boton[0]);
        $this->assertMatchesRegularExpression('#aria-expanded="(true|false)"#', $boton[0]);
        $this->assertStringContainsString('<span class="sr-only">Abrir menú</span>', $boton[1]);
        // El icono es decorativo: el nombre lo da el texto sr-only.
        $this->assertMatchesRegularExpression('#<svg\b[^>]*aria-hidden="true"#', $boton[1]);
    }

    public function test_la_pestana_de_escritorio_esta_dentro_de_la_barra(): void
    {
        $barra = $this->barra($this->get(route('home'))->assertOk()->getContent());

        $this->assertSame(1, preg_match('#<button\b[^>]*id="barra-plegar"[^>]*>(.*?)</button>#s', $barra, $pestana));
        $this->assertStringContainsString('type="button"', $pestana[0]);
        $this->assertStringContainsString('class="barra-pestana"', $pestana[0]);
        $this->assertStringContainsString('aria-controls="menu-lateral"', $pestana[0]);
        $this->assertMatchesRegularExpression('#aria-expanded="(true|false)"#', $pestana[0]);
        $this->assertStringContainsString('<span class="sr-only">Contraer menú</span>', $pestana[1]);
        $this->assertMatchesRegularExpression('#<svg\b[^>]*aria-hidden="true"#', $pestana[1]);
    }

    public function test_cada_boton_aparece_una_sola_vez(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="menu-boton"'));
        $this->assertSame(1, substr_count($html, 'id="barra-plegar"'));
    }

    public function test_el_html_no_trae_las_clases_que_pone_el_js(): void
    {
        // 'js', 'barra-plegada', 'barra-temporal' y 'barra-transicion' solo las agrega
        // app.js: sin JS la página se ve como siempre (barra expandida, sin pestaña).
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<html\b[^>]*>#', $html, $etiqueta));
        $this->assertStringNotContainsString('class=', $etiqueta[0]);
    }

    public function test_explorar_tiene_data_etiqueta_igual_al_texto_para_un_visitante(): void
    {
        $barra = $this->barra($this->get(route('home'))->assertOk()->getContent());

        $enlaces = $this->enlacesDeGrupo($barra, 'menu-explorar');

        $this->assertSame([['Inicio', 'Inicio'], ['Noticias', 'Noticias']], $enlaces);
        $this->assertStringNotContainsString('MI CUENTA', $barra);
    }

    public function test_con_sesion_mi_cuenta_tambien_lleva_data_etiqueta(): void
    {
        $usuario = User::factory()->editor()->create();

        $barra = $this->barra($this->actingAs($usuario)->get(route('home'))->assertOk()->getContent());

        $this->assertSame([['Inicio', 'Inicio'], ['Noticias', 'Noticias']], $this->enlacesDeGrupo($barra, 'menu-explorar'));
        // NP-36 paso 2: MI CUENTA suma "Mi perfil" después de Dashboard.
        $this->assertSame([['Dashboard', 'Dashboard'], ['Mi perfil', 'Mi perfil']], $this->enlacesDeGrupo($barra, 'menu-cuenta'));
    }

    public function test_el_enlace_activo_sigue_marcado_con_data_etiqueta(): void
    {
        $barra = $this->barra($this->get(route('noticias.index'))->assertOk()->getContent());

        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('noticias.index'), '#').'"[^>]*menu-enlace-activo[^>]*aria-current="page"[^>]*data-etiqueta="Noticias"#',
            $barra
        );
    }

    #[DataProvider('sesiones')]
    public function test_los_titulos_y_textos_siguen_en_el_html(bool $conSesion): void
    {
        // El modo iconos los oculta solo visualmente (CSS); no se borran del HTML,
        // así aria-labelledby y los nombres accesibles siguen funcionando.
        $peticion = $conSesion ? $this->actingAs(User::factory()->editor()->create()) : $this;
        $barra = $this->barra($peticion->get(route('home'))->assertOk()->getContent());

        $this->assertStringContainsString('<p id="menu-explorar" class="menu-grupo">EXPLORAR</p>', $barra);
        $this->assertStringContainsString('<span class="menu-texto">Inicio</span>', $barra);
        $this->assertStringContainsString('<span class="menu-texto">Noticias</span>', $barra);
        $this->assertStringContainsString('<span class="menu-texto">Más</span>', $barra);
        $this->assertMatchesRegularExpression('#<img class="marca-logo"[^>]*alt="Nexus Plays"#', $barra);
        $this->assertStringContainsString('images/nexusplays-logo-dark.png', $barra);
        $this->assertStringContainsString('<span class="marca-subtitulo">Revista de videojuegos</span>', $barra);
        $this->assertMatchesRegularExpression('#<img class="marca-monograma"[^>]*aria-hidden="true"#', $barra);
        $this->assertStringContainsString('favicon.svg', $barra);
        $this->assertMatchesRegularExpression('#<summary class="menu-enlace menu-mas-titulo" data-etiqueta="Más">#', $barra);
        $this->assertMatchesRegularExpression('#<button type="submit" class="barra-buscar-boton" data-etiqueta="Buscar">#', $barra);

        if ($conSesion) {
            $this->assertStringContainsString('<p id="menu-cuenta" class="menu-grupo">MI CUENTA</p>', $barra);
            $this->assertStringContainsString('<span class="menu-texto">Dashboard</span>', $barra);
        }
    }

    public static function sesiones(): array
    {
        return [
            'visitante' => [false],
            'con sesión' => [true],
        ];
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

    public static function paginasConSesion(): array
    {
        // Sin login: con sesión redirige (middleware guest) y no habría HTML que revisar.
        return [
            'inicio' => ['home'],
            'noticias' => ['noticias.index'],
            'cookies' => ['legal.cookies'],
            'dashboard' => ['dashboard'],
        ];
    }

    #[DataProvider('paginasConSesion')]
    public function test_el_layout_con_sesion_no_tiene_scripts_ni_estilos_en_linea(string $ruta): void
    {
        $usuario = User::factory()->editor()->create();

        $html = $this->actingAs($usuario)->get(route($ruta))->assertOk()->getContent();

        $this->assertStringNotContainsString('style=', $html);
        $this->assertDoesNotMatchRegularExpression('#\son[a-z]+=#i', $html);
        $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#i', $html);
    }
}
