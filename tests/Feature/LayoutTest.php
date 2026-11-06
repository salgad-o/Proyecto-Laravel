<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    use RefreshDatabase;

    /** Devuelve solo el HTML de una etiqueta del layout (header o aside). */
    private function seccion(TestResponse $respuesta, string $etiqueta): string
    {
        $this->assertSame(
            1,
            preg_match("#<{$etiqueta}\b.*?</{$etiqueta}>#s", $respuesta->getContent(), $coincidencia),
            "La página no tiene <{$etiqueta}>."
        );

        return $coincidencia[0];
    }

    public function test_el_titulo_dice_nexus_plays(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<title>Inicio · Nexus Plays</title>', false);
    }

    public function test_un_visitante_ve_iniciar_sesion_y_registrarse_en_el_header(): void
    {
        $header = $this->seccion($this->get(route('home')), 'header');

        $this->assertStringContainsString('Iniciar sesión', $header);
        $this->assertStringContainsString('Registrarse', $header);
        $this->assertStringNotContainsString('Cerrar sesión', $header);
    }

    public function test_un_usuario_autenticado_ve_su_nombre_dashboard_y_logout_en_el_header(): void
    {
        $usuario = User::factory()->create(['name' => 'Ana Lectora']);

        $header = $this->seccion($this->actingAs($usuario)->get(route('home')), 'header');

        $this->assertStringContainsString('Ana Lectora', $header);
        $this->assertStringContainsString('Dashboard', $header);
        $this->assertStringContainsString('Cerrar sesión', $header);
        $this->assertStringNotContainsString('Iniciar sesión', $header);
        $this->assertMatchesRegularExpression(
            '#<form method="POST" action="'.preg_quote(route('logout'), '#').'"[^>]*>\s*<input type="hidden" name="_token"#',
            $header
        );
    }

    public function test_la_barra_lateral_no_tiene_enlaces_de_sesion(): void
    {
        $usuario = User::factory()->create();

        $paraVisitante = $this->seccion($this->get(route('home')), 'aside');
        $paraUsuario = $this->seccion($this->actingAs($usuario)->get(route('home')), 'aside');

        foreach ([$paraVisitante, $paraUsuario] as $barra) {
            $this->assertStringNotContainsString('Iniciar sesión', $barra);
            $this->assertStringNotContainsString('Cerrar sesión', $barra);
        }
    }

    public function test_un_nombre_con_html_se_escapa_en_el_header(): void
    {
        $usuario = User::factory()->create(['name' => '<script>alert("xss")</script>']);

        $header = $this->seccion($this->actingAs($usuario)->get(route('home')), 'header');

        $this->assertStringNotContainsString('<script>', $header);
        $this->assertStringContainsString('&lt;script&gt;', $header);
    }

    public function test_el_enlace_de_la_pagina_actual_tiene_aria_current(): void
    {
        $barra = $this->seccion($this->get(route('about')), 'aside');

        $this->assertSame(1, substr_count($barra, 'aria-current="page"'));
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('about'), '#').'"[^>]*aria-current="page"#',
            $barra
        );
    }

    // ---------- NP-43 paso 1c: sin pie y barra lateral tipo revista ----------

    /** Páginas públicas representativas (nombre de ruta). */
    public static function paginasPublicas(): array
    {
        return [
            'inicio' => ['home'],
            'noticias' => ['noticias.index'],
            'acerca' => ['about'],
            'contacto' => ['contact'],
            'cookies' => ['legal.cookies'],
            'privacidad' => ['legal.privacidad'],
            'terminos' => ['legal.terminos'],
            'login' => ['login'],
            'registro' => ['register'],
        ];
    }

    /** Páginas cuyo enlace vive dentro de "Más". */
    public static function paginasDeMas(): array
    {
        return [
            'acerca' => ['about'],
            'contacto' => ['contact'],
            'cookies' => ['legal.cookies'],
            'privacidad' => ['legal.privacidad'],
            'terminos' => ['legal.terminos'],
        ];
    }

    #[DataProvider('paginasPublicas')]
    public function test_ninguna_pagina_tiene_pie(string $ruta): void
    {
        $this->get(route($ruta))
            ->assertOk()
            ->assertDontSee('<footer', false);
    }

    public function test_un_usuario_con_sesion_tampoco_ve_pie(): void
    {
        $this->actingAs(User::factory()->lector()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('<footer', false);
    }

    public function test_la_barra_tiene_el_buscador_por_get_sin_csrf(): void
    {
        $barra = $this->seccion($this->get(route('home')), 'aside');

        $this->assertSame(1, preg_match('#<form role="search"([^>]*)>(.*?)</form>#s', $barra, $form));
        $this->assertStringContainsString('method="GET"', $form[1]);
        $this->assertStringContainsString('action="'.route('noticias.index').'"', $form[1]);
        $this->assertStringContainsString('id="barra-buscar"', $form[2]);
        $this->assertStringContainsString('name="q"', $form[2]);
        $this->assertStringContainsString('maxlength="100"', $form[2]);
        $this->assertStringContainsString('<label class="sr-only" for="barra-buscar">Buscar noticias</label>', $form[2]);
        $this->assertStringNotContainsString('_token', $form[2]);
    }

    public function test_en_noticias_no_hay_ids_duplicados_por_el_buscador(): void
    {
        $html = $this->get(route('noticias.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="q"'));
        $this->assertSame(1, substr_count($html, 'id="barra-buscar"'));
    }

    public function test_el_buscador_de_la_barra_escapa_el_texto_buscado(): void
    {
        $barra = $this->seccion(
            $this->get(route('noticias.index', ['q' => '<script>alert(1)</script>'])),
            'aside'
        );

        $this->assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;"', $barra);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $barra);
    }

    public function test_el_buscador_de_la_barra_ignora_q_que_no_es_texto(): void
    {
        $barra = $this->seccion($this->get('/?q[]=hola'), 'aside');

        $this->assertMatchesRegularExpression('#<input id="barra-buscar"[^>]*>#', $barra);
        $this->assertDoesNotMatchRegularExpression('#<input id="barra-buscar"[^>]*value=#', $barra);
    }

    public function test_un_visitante_no_ve_mi_cuenta_en_la_barra(): void
    {
        $barra = $this->seccion($this->get(route('home')), 'aside');

        $this->assertStringNotContainsString('MI CUENTA', $barra);
        $this->assertStringNotContainsString('href="'.route('dashboard').'"', $barra);
    }

    public function test_un_usuario_con_sesion_ve_mi_cuenta_con_dashboard(): void
    {
        $usuario = User::factory()->lector()->create();

        $barra = $this->seccion($this->actingAs($usuario)->get(route('home')), 'aside');

        $this->assertStringContainsString('MI CUENTA', $barra);
        $this->assertStringContainsString('href="'.route('dashboard').'"', $barra);

        $enDashboard = $this->seccion($this->actingAs($usuario)->get(route('dashboard')), 'aside');
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('dashboard'), '#').'"[^>]*aria-current="page"#',
            $enDashboard
        );
    }

    #[DataProvider('paginasDeMas')]
    public function test_mas_se_abre_en_sus_paginas_y_marca_la_actual(string $ruta): void
    {
        $barra = $this->seccion($this->get(route($ruta)), 'aside');

        $this->assertMatchesRegularExpression('#<details class="menu-mas"\s+open\s*>#', $barra);
        $this->assertSame(1, substr_count($barra, 'aria-current="page"'));
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route($ruta), '#').'"[^>]*aria-current="page"#',
            $barra
        );
    }

    public function test_mas_esta_cerrado_en_inicio(): void
    {
        $barra = $this->seccion($this->get(route('home')), 'aside');

        $this->assertMatchesRegularExpression('#<details class="menu-mas"\s*>#', $barra);
        $this->assertStringNotContainsString('open', $this->etiquetaDetails($barra));
    }

    #[DataProvider('paginasPublicas')]
    public function test_mas_tiene_los_cinco_enlaces_con_los_nombres_nuevos(string $ruta): void
    {
        $barra = $this->seccion($this->get(route($ruta)), 'aside');

        $this->assertSame(1, preg_match('#<details class="menu-mas".*?</details>#s', $barra, $mas));

        $enlaces = [
            'about' => 'Acerca',
            'contact' => 'Contacto',
            'legal.cookies' => 'Política de cookies',
            'legal.privacidad' => 'Política de privacidad',
            'legal.terminos' => 'Términos de uso',
        ];

        foreach ($enlaces as $nombreRuta => $texto) {
            $this->assertMatchesRegularExpression(
                '#<a href="'.preg_quote(route($nombreRuta), '#').'"[^>]*>'.preg_quote($texto, '#').'</a>#',
                $mas[0]
            );
        }

        $this->assertStringNotContainsString('Tratamiento de datos', $barra);
        $this->assertStringNotContainsString('Términos y condiciones', $barra);
        $this->assertStringContainsString('© '.now()->year.' Nexus Plays', $barra);
    }

    public function test_explorar_solo_tiene_inicio_y_noticias(): void
    {
        $barra = $this->seccion($this->get(route('home')), 'aside');

        $this->assertSame(1, preg_match('#<ul class="menu-lista" aria-labelledby="menu-explorar">(.*?)</ul>#s', $barra, $explorar));
        $this->assertSame(2, substr_count($explorar[1], '<a '));
        $this->assertStringContainsString('href="'.route('home').'"', $explorar[1]);
        $this->assertStringContainsString('href="'.route('noticias.index').'"', $explorar[1]);
    }

    /** Devuelve solo la etiqueta de apertura <details ...>. */
    private function etiquetaDetails(string $barra): string
    {
        $this->assertSame(1, preg_match('#<details\b[^>]*>#', $barra, $etiqueta));

        return $etiqueta[0];
    }

    public function test_la_ruta_app_ya_no_existe(): void
    {
        $this->get('/app')->assertNotFound();
    }

    public function test_login_y_registro_siguen_mostrando_su_formulario(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('action="'.route('login.store').'"', false);

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('action="'.route('register.store').'"', false);
    }
}
