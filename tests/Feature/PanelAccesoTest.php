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

        $this->actingAs($editor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('GESTIÓN')
            ->assertDontSee('Administrar usuarios')
            ->assertDontSee('href="'.route('usuarios.index').'"', false)
            ->assertDontSee('href="'.route('roles.index').'"', false)
            ->assertDontSee('href="'.route('comentarios.index').'"', false)
            ->assertDontSee('href="'.route('auditoria.index').'"', false);
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
