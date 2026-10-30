<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Comentario;
use App\Models\Noticia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComentarioTest extends TestCase
{
    use RefreshDatabase;

    // ---------- NP-11: comentar ----------

    public function test_un_lector_comenta_una_noticia_publicada(): void
    {
        $lector = User::factory()->lector()->create();
        $noticia = Noticia::factory()->create();

        $this->actingAs($lector)
            ->post(route('comentarios.store', $noticia), ['body' => '¡Qué buena noticia!'])
            ->assertRedirect(route('noticias.show', $noticia).'#comentarios');

        $this->assertDatabaseHas('comentarios', [
            'noticia_id' => $noticia->id,
            'user_id' => $lector->id,
            'body' => '¡Qué buena noticia!',
        ]);
    }

    public function test_el_comentario_queda_en_la_auditoria(): void
    {
        $lector = User::factory()->lector()->create();
        $noticia = Noticia::factory()->create();

        $this->actingAs($lector)->post(route('comentarios.store', $noticia), ['body' => 'Comentario auditado']);

        $registro = AuditLog::where('auditable_type', Comentario::class)->latest('id')->firstOrFail();
        $this->assertSame('crear', $registro->action);
        $this->assertSame($lector->id, $registro->user_id);
    }

    public function test_no_se_puede_falsificar_el_autor_ni_la_noticia(): void
    {
        $lector = User::factory()->lector()->create();
        $otro = User::factory()->lector()->create();
        $noticia = Noticia::factory()->create();
        $otraNoticia = Noticia::factory()->create();

        $this->actingAs($lector)->post(route('comentarios.store', $noticia), [
            'body' => 'Intento de suplantación',
            'user_id' => $otro->id,
            'noticia_id' => $otraNoticia->id,
        ]);

        $comentario = Comentario::firstOrFail();
        $this->assertSame($lector->id, $comentario->user_id);
        $this->assertSame($noticia->id, $comentario->noticia_id);
    }

    public function test_un_visitante_no_puede_comentar(): void
    {
        $noticia = Noticia::factory()->create();

        $this->post(route('comentarios.store', $noticia), ['body' => 'Spam'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('comentarios', 0);
    }

    public function test_un_visitante_lee_los_comentarios_pero_no_ve_el_formulario(): void
    {
        $comentario = Comentario::factory()->create(['body' => 'Comentario visible para todos']);

        $this->get(route('noticias.show', $comentario->noticia))
            ->assertOk()
            ->assertSee('Comentario visible para todos')
            ->assertSee('para comentar')
            ->assertDontSee('name="body"', false);
    }

    public function test_no_se_puede_comentar_un_borrador(): void
    {
        $autor = User::factory()->editor()->create();
        $borrador = Noticia::factory()->for($autor)->borrador()->create();

        $this->actingAs($autor)
            ->post(route('comentarios.store', $borrador), ['body' => 'Comentario en borrador'])
            ->assertForbidden();

        $this->assertDatabaseCount('comentarios', 0);
    }

    public function test_el_comentario_es_obligatorio_y_tiene_maximo_1000_caracteres(): void
    {
        $lector = User::factory()->lector()->create();
        $noticia = Noticia::factory()->create();

        $this->actingAs($lector)
            ->post(route('comentarios.store', $noticia), ['body' => ''])
            ->assertSessionHasErrors('body');

        $this->actingAs($lector)
            ->post(route('comentarios.store', $noticia), ['body' => str_repeat('a', 1001)])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('comentarios', 0);
    }

    public function test_un_comentario_con_html_se_muestra_escapado(): void
    {
        $comentario = Comentario::factory()->create(['body' => '<script>alert("xss")</script>']);

        $this->get(route('noticias.show', $comentario->noticia))
            ->assertOk()
            ->assertDontSee('<script>alert("xss")</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_comentar_esta_limitado_a_10_por_minuto(): void
    {
        $lector = User::factory()->lector()->create();
        $noticia = Noticia::factory()->create();

        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($lector)
                ->post(route('comentarios.store', $noticia), ['body' => "Comentario {$i}"])
                ->assertStatus(302);
        }

        $this->actingAs($lector)
            ->post(route('comentarios.store', $noticia), ['body' => 'Uno de más'])
            ->assertStatus(429);
    }

    // ---------- NP-12: editar ----------

    public function test_el_autor_edita_su_comentario(): void
    {
        $comentario = Comentario::factory()->create(['body' => 'Texto original']);

        $this->actingAs($comentario->user)->get(route('comentarios.edit', $comentario))->assertOk();

        $this->actingAs($comentario->user)
            ->put(route('comentarios.update', $comentario), ['body' => 'Texto corregido'])
            ->assertRedirect();

        $comentario->refresh();
        $this->assertSame('Texto corregido', $comentario->body);
        $this->assertNotNull($comentario->edited_at);

        $registro = AuditLog::where('auditable_type', Comentario::class)->latest('id')->firstOrFail();
        $this->assertSame('editar', $registro->action);
        $this->assertSame('Texto original', $registro->old_values['body']);

        $this->get(route('noticias.show', $comentario->noticia))->assertSee('(editado)');
    }

    public function test_nadie_edita_el_comentario_de_otro_ni_siquiera_el_admin(): void
    {
        $comentario = Comentario::factory()->create(['body' => 'Original']);
        $otroLector = User::factory()->lector()->create();
        $admin = User::factory()->admin()->create();

        foreach ([$otroLector, $admin] as $usuario) {
            $this->actingAs($usuario)->get(route('comentarios.edit', $comentario))->assertForbidden();
            $this->actingAs($usuario)
                ->put(route('comentarios.update', $comentario), ['body' => 'Modificado'])
                ->assertForbidden();
        }

        $this->assertSame('Original', $comentario->fresh()->body);
    }

    // ---------- NP-13: eliminar y moderar ----------

    public function test_el_autor_elimina_su_comentario(): void
    {
        $comentario = Comentario::factory()->create();

        $this->actingAs($comentario->user)
            ->delete(route('comentarios.destroy', $comentario))
            ->assertRedirect();

        $this->assertDatabaseMissing('comentarios', ['id' => $comentario->id]);

        $registro = AuditLog::where('auditable_type', Comentario::class)->latest('id')->firstOrFail();
        $this->assertSame('eliminar', $registro->action);
    }

    public function test_el_admin_elimina_cualquier_comentario(): void
    {
        $admin = User::factory()->admin()->create();
        $comentario = Comentario::factory()->create();

        $this->actingAs($admin)
            ->delete(route('comentarios.destroy', $comentario))
            ->assertRedirect();

        $this->assertDatabaseMissing('comentarios', ['id' => $comentario->id]);

        $registro = AuditLog::where('auditable_type', Comentario::class)->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $registro->user_id, 'La auditoría registra que lo eliminó el administrador.');
    }

    public function test_un_lector_o_editor_no_elimina_comentarios_ajenos(): void
    {
        $comentario = Comentario::factory()->create();
        $lector = User::factory()->lector()->create();
        $editor = User::factory()->editor()->create();

        foreach ([$lector, $editor] as $usuario) {
            $this->actingAs($usuario)
                ->delete(route('comentarios.destroy', $comentario))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('comentarios', ['id' => $comentario->id]);
    }

    public function test_el_admin_ve_el_panel_de_moderacion(): void
    {
        $admin = User::factory()->admin()->create();
        Comentario::factory()->create(['body' => 'Comentario por moderar']);

        $this->actingAs($admin)
            ->get(route('comentarios.index'))
            ->assertOk()
            ->assertSee('Comentario por moderar');
    }

    public function test_un_editor_o_lector_no_ve_el_panel_de_moderacion(): void
    {
        foreach ([User::factory()->editor()->create(), User::factory()->lector()->create()] as $usuario) {
            $this->actingAs($usuario)->get(route('comentarios.index'))->assertForbidden();
        }
    }
}
