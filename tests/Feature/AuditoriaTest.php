<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Noticia;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private function datosNoticia(array $cambios = []): array
    {
        return array_merge([
            'title' => 'Anuncian nueva expansión',
            'excerpt' => 'Resumen',
            'body' => 'Contenido de la noticia.',
            'status' => 'publicada',
        ], $cambios);
    }

    private function ultimoRegistro(): AuditLog
    {
        return AuditLog::latest('id')->firstOrFail();
    }

    // ---------- Se registra cada acción ----------

    public function test_crear_una_noticia_queda_auditado(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)->post(route('noticias.store'), $this->datosNoticia());

        $noticia = Noticia::firstOrFail();
        $registro = $this->ultimoRegistro();

        $this->assertSame('crear', $registro->action);
        $this->assertSame($editor->id, $registro->user_id);
        $this->assertSame(Noticia::class, $registro->auditable_type);
        $this->assertSame($noticia->id, $registro->auditable_id);
        $this->assertSame('Anuncian nueva expansión', $registro->new_values['title']);
        $this->assertNull($registro->old_values);
        $this->assertSame('127.0.0.1', $registro->ip_address);
        $this->assertNotNull($registro->created_at);
    }

    public function test_editar_una_noticia_guarda_el_valor_anterior_y_el_nuevo(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = Noticia::factory()->for($editor)->create(['title' => 'Título original']);

        $this->actingAs($editor)->put(
            route('noticias.update', $noticia),
            $this->datosNoticia(['title' => 'Título corregido'])
        );

        $registro = $this->ultimoRegistro();

        $this->assertSame('editar', $registro->action);
        $this->assertSame($editor->id, $registro->user_id);
        $this->assertSame('Título original', $registro->old_values['title']);
        $this->assertSame('Título corregido', $registro->new_values['title']);
        $this->assertArrayNotHasKey('updated_at', $registro->new_values);
    }

    public function test_publicar_un_borrador_se_audita_como_publicar(): void
    {
        $editor = User::factory()->editor()->create();
        $borrador = Noticia::factory()->for($editor)->borrador()->create();

        $this->actingAs($editor)->put(
            route('noticias.update', $borrador),
            $this->datosNoticia(['status' => 'publicada'])
        );

        $registro = $this->ultimoRegistro();

        $this->assertSame('publicar', $registro->action);
        $this->assertSame('borrador', $registro->old_values['status']);
        $this->assertSame('publicada', $registro->new_values['status']);
    }

    public function test_eliminar_una_noticia_conserva_sus_datos_en_la_auditoria(): void
    {
        $admin = User::factory()->admin()->create();
        $noticia = Noticia::factory()->create(['title' => 'Noticia que se borra']);

        $this->actingAs($admin)->delete(route('noticias.destroy', $noticia));

        $registro = $this->ultimoRegistro();

        $this->assertSame('eliminar', $registro->action);
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame($noticia->id, $registro->auditable_id);
        $this->assertSame('Noticia que se borra', $registro->old_values['title']);
        $this->assertNull($registro->new_values);
    }

    // ---------- Solo lectura ----------

    public function test_un_registro_de_auditoria_no_se_puede_modificar(): void
    {
        Noticia::factory()->create();
        $registro = $this->ultimoRegistro();

        $this->expectException(LogicException::class);

        $registro->update(['action' => 'eliminar']);
    }

    public function test_un_registro_de_auditoria_no_se_puede_borrar(): void
    {
        Noticia::factory()->create();
        $registro = $this->ultimoRegistro();

        $this->expectException(LogicException::class);

        $registro->delete();
    }

    // ---------- Quién puede ver la auditoría ----------

    public function test_el_admin_ve_la_auditoria(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Ana Admin']);
        $this->actingAs($admin)->post(route('noticias.store'), $this->datosNoticia());

        $this->actingAs($admin)
            ->get(route('auditoria.index'))
            ->assertOk()
            ->assertSee('Ana Admin')
            ->assertSee('Creó')
            ->assertSee('Anuncian nueva expansión');
    }

    public function test_un_editor_no_puede_ver_la_auditoria(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)->get(route('auditoria.index'))->assertForbidden();
    }

    public function test_un_lector_no_puede_ver_la_auditoria(): void
    {
        $lector = User::factory()->lector()->create();

        $this->actingAs($lector)->get(route('auditoria.index'))->assertForbidden();
    }

    public function test_un_visitante_es_enviado_al_login(): void
    {
        $this->get(route('auditoria.index'))->assertRedirect(route('login'));
    }

    // ---------- Filtros ----------

    public function test_el_admin_filtra_por_accion(): void
    {
        $admin = User::factory()->admin()->create();
        $noticia = Noticia::factory()->create();
        $this->actingAs($admin)->delete(route('noticias.destroy', $noticia));

        $this->actingAs($admin)
            ->get(route('auditoria.index', ['accion' => 'eliminar']))
            ->assertOk()
            ->assertViewHas('registros', fn ($registros) => $registros->total() === 1
                && $registros->first()->action === 'eliminar');
    }

    public function test_el_admin_filtra_por_usuario(): void
    {
        $admin = User::factory()->admin()->create();
        $editor = User::factory()->editor()->create();
        $this->actingAs($editor)->post(route('noticias.store'), $this->datosNoticia());
        $this->actingAs($admin)->post(route('noticias.store'), $this->datosNoticia(['title' => 'Otra']));

        $this->actingAs($admin)
            ->get(route('auditoria.index', ['usuario' => $editor->id]))
            ->assertOk()
            ->assertViewHas('registros', fn ($registros) => $registros->total() === 1
                && $registros->first()->user_id === $editor->id);
    }

    public function test_un_filtro_invalido_se_rechaza(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('auditoria.index', ['accion' => 'hackear']))
            ->assertSessionHasErrors('accion');
    }

    // ---------- Acciones sobre usuarios ----------

    public function test_el_admin_filtra_por_cambiar_rol(): void
    {
        $admin = User::factory()->admin()->create();
        $usuario = User::factory()->lector()->create();

        $this->actingAs($admin);
        $usuario->role_id = Role::where('name', 'editor')->value('id');
        $usuario->save();

        $this->get(route('auditoria.index', ['accion' => 'cambiar_rol']))
            ->assertOk()
            ->assertViewHas('registros', fn ($registros) => $registros->total() === 1
                && $registros->first()->action === 'cambiar_rol'
                && $registros->first()->auditable_id === $usuario->id)
            ->assertSeeInOrder(['role_id', 'lector', '→', 'editor']);
    }

    public function test_desactivar_un_usuario_se_muestra_como_inactivo_en_la_auditoria(): void
    {
        $admin = User::factory()->admin()->create();
        $usuario = User::factory()->create();

        $this->actingAs($admin);
        $usuario->is_active = false;
        $usuario->save();

        $this->get(route('auditoria.index', ['accion' => 'desactivar']))
            ->assertOk()
            ->assertViewHas('registros', fn ($registros) => $registros->total() === 1
                && $registros->first()->auditable_id === $usuario->id)
            ->assertSeeInOrder(['is_active', 'Activo', '→', 'Inactivo']);
    }

    public function test_un_registro_sin_autor_se_muestra_como_sistema(): void
    {
        $usuario = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('auditoria.index', ['accion' => 'crear']))
            ->assertOk()
            ->assertViewHas('registros', fn ($registros) => $registros->firstWhere('auditable_id', $usuario->id)->user_id === null)
            ->assertSeeInOrder(['Sistema', 'Creó', 'User #'.$usuario->id]);
    }

    // ---------- Valores vacíos ----------

    public function test_volver_a_borrador_muestra_la_fecha_de_publicacion_como_vacia(): void
    {
        $admin = User::factory()->admin()->create();
        $editor = User::factory()->editor()->create();
        $noticia = Noticia::factory()->for($editor)->borrador()->create();

        $this->actingAs($editor)->put(route('noticias.update', $noticia), $this->datosNoticia(['status' => 'publicada']));
        $fecha = $noticia->fresh()->published_at->format('Y-m-d H:i:s');

        $this->actingAs($editor)->put(route('noticias.update', $noticia), $this->datosNoticia(['status' => 'borrador']));

        $this->assertNull($this->ultimoRegistro()->new_values['published_at']);

        $this->actingAs($admin)
            ->get(route('auditoria.index', ['accion' => 'editar']))
            ->assertOk()
            ->assertViewHas('registros', fn ($registros) => $registros->first()->auditable_id === $noticia->id)
            ->assertSeeInOrder(['published_at', $fecha, '→', '(vacío)']);
    }
}
