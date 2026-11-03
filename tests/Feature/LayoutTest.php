<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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
            '#<form method="POST" action="' . preg_quote(route('logout'), '#') . '"[^>]*>\s*<input type="hidden" name="_token"#',
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
            '#<a href="' . preg_quote(route('about'), '#') . '"[^>]*aria-current="page"#',
            $barra
        );
    }

    public function test_la_ruta_app_ya_no_existe(): void
    {
        $this->get('/app')->assertNotFound();
    }

    public function test_login_y_registro_siguen_mostrando_su_formulario(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('action="' . route('login.store') . '"', false);

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('action="' . route('register.store') . '"', false);
    }
}
