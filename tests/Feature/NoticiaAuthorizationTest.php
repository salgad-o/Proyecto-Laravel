<?php

namespace Tests\Feature;

use App\Models\Noticia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoticiaAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function datosNoticia(array $cambios = []): array
    {
        return array_merge([
            'title' => 'Nuevo tráiler de un juego',
            'excerpt' => 'Resumen corto de la noticia',
            'body' => 'Contenido completo de la noticia.',
            'status' => 'publicada',
        ], $cambios);
    }

    // ---------- Crear ----------

    public function test_un_visitante_es_enviado_al_login_al_intentar_crear(): void
    {
        $this->get(route('noticias.create'))->assertRedirect(route('login'));
    }

    public function test_un_lector_no_puede_ver_el_formulario_de_crear(): void
    {
        $lector = User::factory()->lector()->create();

        $this->actingAs($lector)->get(route('noticias.create'))->assertForbidden();
    }

    public function test_un_lector_no_puede_guardar_noticias(): void
    {
        $lector = User::factory()->lector()->create();

        $this->actingAs($lector)
            ->post(route('noticias.store'), $this->datosNoticia())
            ->assertForbidden();

        $this->assertDatabaseCount('noticias', 0);
    }

    public function test_un_editor_crea_una_noticia_a_su_nombre(): void
    {
        $editor = User::factory()->editor()->create();
        $otro = User::factory()->editor()->create();

        // Se intenta inyectar user_id: debe ignorarse y quedar a nombre del editor autenticado.
        $this->actingAs($editor)
            ->post(route('noticias.store'), $this->datosNoticia(['user_id' => $otro->id]))
            ->assertRedirect();

        $noticia = Noticia::firstOrFail();
        $this->assertSame($editor->id, $noticia->user_id);
        $this->assertNotNull($noticia->published_at);
    }

    // ---------- Editar ----------

    public function test_un_editor_edita_su_propia_noticia(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = Noticia::factory()->for($editor)->create();

        $this->actingAs($editor)->get(route('noticias.edit', $noticia))->assertOk();

        $this->actingAs($editor)
            ->put(route('noticias.update', $noticia), $this->datosNoticia(['title' => 'Título corregido']))
            ->assertRedirect(route('noticias.show', $noticia));

        $this->assertSame('Título corregido', $noticia->fresh()->title);
    }

    public function test_un_editor_no_puede_editar_la_noticia_de_otro(): void
    {
        $editor = User::factory()->editor()->create();
        $ajena = Noticia::factory()->create(['title' => 'Original']);

        $this->actingAs($editor)->get(route('noticias.edit', $ajena))->assertForbidden();

        $this->actingAs($editor)
            ->put(route('noticias.update', $ajena), $this->datosNoticia(['title' => 'Modificada']))
            ->assertForbidden();

        $this->assertSame('Original', $ajena->fresh()->title);
    }

    public function test_un_admin_puede_editar_cualquier_noticia(): void
    {
        $admin = User::factory()->admin()->create();
        $noticia = Noticia::factory()->create();

        $this->actingAs($admin)
            ->put(route('noticias.update', $noticia), $this->datosNoticia(['title' => 'Editada por admin']))
            ->assertRedirect();

        $this->assertSame('Editada por admin', $noticia->fresh()->title);
    }

    // ---------- Eliminar ----------

    public function test_un_editor_no_puede_eliminar_la_noticia_de_otro(): void
    {
        $editor = User::factory()->editor()->create();
        $ajena = Noticia::factory()->create();

        $this->actingAs($editor)->delete(route('noticias.destroy', $ajena))->assertForbidden();

        $this->assertDatabaseHas('noticias', ['id' => $ajena->id]);
    }

    public function test_un_admin_puede_eliminar_cualquier_noticia(): void
    {
        $admin = User::factory()->admin()->create();
        $noticia = Noticia::factory()->create();

        $this->actingAs($admin)
            ->delete(route('noticias.destroy', $noticia))
            ->assertRedirect(route('noticias.index'));

        $this->assertDatabaseMissing('noticias', ['id' => $noticia->id]);
    }

    // ---------- Borradores ----------

    public function test_un_visitante_no_puede_ver_un_borrador(): void
    {
        $borrador = Noticia::factory()->borrador()->create();

        $this->get(route('noticias.show', $borrador))->assertForbidden();
    }

    public function test_un_lector_no_puede_ver_un_borrador(): void
    {
        $lector = User::factory()->lector()->create();
        $borrador = Noticia::factory()->borrador()->create();

        $this->actingAs($lector)->get(route('noticias.show', $borrador))->assertForbidden();
    }

    public function test_el_autor_y_el_admin_si_ven_el_borrador(): void
    {
        $autor = User::factory()->editor()->create();
        $admin = User::factory()->admin()->create();
        $borrador = Noticia::factory()->for($autor)->borrador()->create();

        $this->actingAs($autor)->get(route('noticias.show', $borrador))->assertOk();
        $this->actingAs($admin)->get(route('noticias.show', $borrador))->assertOk();
    }

    public function test_la_lista_publica_no_muestra_borradores(): void
    {
        Noticia::factory()->create(['title' => 'Noticia publicada visible']);
        Noticia::factory()->borrador()->create(['title' => 'Borrador secreto']);

        $this->get(route('noticias.index'))
            ->assertOk()
            ->assertSee('Noticia publicada visible')
            ->assertDontSee('Borrador secreto');
    }

    // ---------- XSS ----------

    public function test_el_contenido_de_una_noticia_se_muestra_escapado(): void
    {
        $noticia = Noticia::factory()->create([
            'title' => '<script>alert("xss")</script>',
        ]);

        $this->get(route('noticias.show', $noticia))
            ->assertOk()
            ->assertDontSee('<script>alert("xss")</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }
}
