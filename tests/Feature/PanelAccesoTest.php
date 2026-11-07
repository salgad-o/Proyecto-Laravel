<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * NP-20: acceso al panel de gestión, navegación por rol y D11.
 */
class PanelAccesoTest extends TestCase
{
    use RefreshDatabase;

    private function seccion(TestResponse $respuesta, string $etiqueta): string
    {
        $this->assertSame(
            1,
            preg_match("#<{$etiqueta}\b.*?</{$etiqueta}>#s", $respuesta->getContent(), $coincidencia),
            "La página no tiene <{$etiqueta}>."
        );

        return $coincidencia[0];
    }

    public function test_un_visitante_es_enviado_al_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_un_lector_es_redirigido_a_su_perfil_con_mensaje(): void
    {
        $lector = User::factory()->lector()->create();

        $this->actingAs($lector)
            ->get(route('dashboard'))
            ->assertRedirect(route('perfil.edit'))
            ->assertSessionHas('status');
    }

    public function test_un_admin_entra_y_ve_las_secciones_de_gestion(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('GESTIÓN')
            ->assertSee('Usuarios')
            ->assertSee('Roles')
            ->assertSee('Comentarios')
            ->assertSee('Auditoría')
            ->assertSee('href="'.route('usuarios.index').'"', false)
            ->assertSee('href="'.route('roles.index').'"', false)
            ->assertSee('href="'.route('comentarios.index').'"', false)
            ->assertSee('href="'.route('auditoria.index').'"', false);
    }

    public function test_un_editor_entra_al_dashboard_sin_secciones_de_admin(): void
    {
        $editor = User::factory()->editor()->create();

        // NP-20 paso 1: el editor SÍ ve GESTIÓN, pero solo con el panel de noticias.
        $this->actingAs($editor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('GESTIÓN')
            ->assertSee('href="'.route('dashboard.noticias.index').'"', false)
            ->assertDontSee('Administrar usuarios')
            ->assertDontSee('href="'.route('usuarios.index').'"', false)
            ->assertDontSee('href="'.route('roles.index').'"', false)
            ->assertDontSee('href="'.route('comentarios.index').'"', false)
            ->assertDontSee('href="'.route('auditoria.index').'"', false);
    }

    /**
     * Devuelve los href de los enlaces del grupo GESTIÓN, en orden, o null si no hay grupo.
     *
     * @return list<string>|null
     */
    private function enlacesGestion(TestResponse $respuesta): ?array
    {
        $html = $respuesta->getContent();

        if (preg_match('#<ul class="menu-lista" aria-labelledby="menu-gestion">(.*?)</ul>#s', $html, $grupo) !== 1) {
            return null;
        }

        preg_match_all('#<a href="([^"]+)"#', $grupo[1], $enlaces);

        return $enlaces[1];
    }

    public function test_el_editor_ve_gestion_solo_con_el_panel_de_noticias(): void
    {
        $editor = User::factory()->editor()->create();

        $respuesta = $this->actingAs($editor)->get(route('home'))->assertOk();

        $this->assertStringContainsString('GESTIÓN', $this->seccion($respuesta, 'aside'));
        $this->assertSame([route('dashboard.noticias.index')], $this->enlacesGestion($respuesta));
    }

    public function test_el_admin_ve_los_cinco_enlaces_de_gestion_en_orden(): void
    {
        $admin = User::factory()->admin()->create();

        $respuesta = $this->actingAs($admin)->get(route('home'))->assertOk();

        $this->assertSame([
            route('dashboard.noticias.index'),
            route('usuarios.index'),
            route('roles.index'),
            route('comentarios.index'),
            route('auditoria.index'),
        ], $this->enlacesGestion($respuesta));
    }

    public function test_lector_y_visitante_no_ven_el_grupo_gestion(): void
    {
        $lector = User::factory()->lector()->create();

        foreach ([$this->actingAs($lector)->get(route('home')), $this->get(route('home'))] as $respuesta) {
            $barra = $this->seccion($respuesta->assertOk(), 'aside');

            $this->assertStringNotContainsString('GESTIÓN', $barra);
            $this->assertStringNotContainsString('aria-labelledby="menu-gestion"', $barra);
            $this->assertNull($this->enlacesGestion($respuesta));
        }
    }

    public function test_el_enlace_noticias_de_gestion_queda_activo_en_el_panel(): void
    {
        foreach ([User::factory()->editor()->create(), User::factory()->admin()->create()] as $usuario) {
            $respuesta = $this->actingAs($usuario)->get(route('dashboard.noticias.index'))->assertOk();

            $this->assertSame(1, preg_match('#<ul class="menu-lista" aria-labelledby="menu-gestion">(.*?)</ul>#s', $respuesta->getContent(), $grupo));
            $this->assertMatchesRegularExpression(
                '#<a href="'.preg_quote(route('dashboard.noticias.index'), '#').'" class="menu-enlace menu-enlace-activo"\s+aria-current="page"\s+data-etiqueta="Noticias">#',
                $grupo[1]
            );
            // Solo un enlace de toda la barra está marcado como página actual.
            $this->assertSame(1, substr_count($this->seccion($respuesta, 'aside'), 'aria-current="page"'));
        }
    }

    public function test_el_enlace_dashboard_de_mi_cuenta_sigue_la_policy_view_panel(): void
    {
        $dashboard = 'href="'.route('dashboard').'"';

        foreach (['admin' => true, 'editor' => true, 'lector' => false] as $rol => $loVe) {
            $barra = $this->seccion($this->actingAs(User::factory()->{$rol}()->create())->get(route('home')), 'aside');

            $this->assertSame($loVe, str_contains($barra, $dashboard), "Rol {$rol}");
        }
    }

    public function test_un_lector_no_ve_dashboard_ni_gestion_en_la_barra(): void
    {
        $lector = User::factory()->lector()->create();

        $barra = $this->seccion($this->actingAs($lector)->get(route('home')), 'aside');

        $this->assertStringNotContainsString('href="'.route('dashboard').'"', $barra);
        $this->assertStringNotContainsString('GESTIÓN', $barra);
        $this->assertStringNotContainsString('<span class="menu-texto">Dashboard</span>', $barra);
        $this->assertStringContainsString('data-etiqueta="Mi perfil"', $barra);
    }

    public function test_el_header_ya_no_duplica_el_dashboard(): void
    {
        $editor = User::factory()->editor()->create();

        $header = $this->seccion($this->actingAs($editor)->get(route('home')), 'header');

        $this->assertStringNotContainsString('href="'.route('dashboard').'"', $header);
        $this->assertStringContainsString('Cerrar sesión', $header);
    }

    public function test_el_dashboard_no_tiene_un_logout_duplicado(): void
    {
        $editor = User::factory()->editor()->create();

        $html = $this->actingAs($editor)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'action="'.route('logout').'"'));
        $this->assertSame(1, preg_match('#<main\b.*?</main>#s', $html, $main));
        $this->assertStringNotContainsString(route('logout'), $main[0]);
    }

    public function test_un_editor_no_puede_entrar_por_url_directa_a_las_secciones_admin(): void
    {
        $editor = User::factory()->editor()->create();

        foreach ([route('usuarios.index'), route('roles.index'), route('comentarios.index'), route('auditoria.index')] as $url) {
            $this->actingAs($editor)->get($url)->assertForbidden();
        }
    }
}
