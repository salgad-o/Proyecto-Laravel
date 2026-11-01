<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class UsuarioAdminTest extends TestCase
{
    use RefreshDatabase;

    private function idRol(string $nombre): int
    {
        return Role::where('name', $nombre)->value('id');
    }

    private function ultimoRegistro(): AuditLog
    {
        return AuditLog::latest('id')->firstOrFail();
    }

    /**
     * Nombres de los usuarios que devuelve una búsqueda, ordenados.
     */
    private function nombresEncontrados(string $buscar): array
    {
        return $this->get(route('usuarios.index', ['buscar' => $buscar]))
            ->assertOk()
            ->viewData('usuarios')
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }

    private function consultasDelListado(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('usuarios.index'))->assertOk();

        return count(DB::getQueryLog());
    }

    // ---------- Acceso ----------

    public function test_el_admin_ve_el_listado_con_los_datos_del_usuario(): void
    {
        $admin = User::factory()->admin()->create();
        $usuario = User::factory()->editor()->create([
            'name' => 'Carla Editora',
            'email' => 'carla@nexus.test',
            'created_at' => '2026-03-15 10:00:00',
        ]);

        $this->actingAs($admin)
            ->get(route('usuarios.index'))
            ->assertOk()
            ->assertSeeInOrder(['Carla Editora', 'carla@nexus.test', $usuario->role->label, 'Activo', '15/03/2026']);
    }

    public function test_un_editor_no_puede_ver_el_listado(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)->get(route('usuarios.index'))->assertForbidden();
    }

    public function test_un_lector_no_puede_ver_el_listado(): void
    {
        $lector = User::factory()->lector()->create();

        $this->actingAs($lector)->get(route('usuarios.index'))->assertForbidden();
    }

    public function test_un_visitante_es_enviado_al_login(): void
    {
        $this->get(route('usuarios.index'))->assertRedirect(route('login'));
    }

    public function test_un_editor_o_lector_no_puede_cambiar_rol_ni_estado(): void
    {
        $usuario = User::factory()->lector()->create();

        foreach ([User::factory()->editor()->create(), User::factory()->lector()->create()] as $actor) {
            $this->actingAs($actor)
                ->patch(route('usuarios.rol', $usuario), ['role_id' => $this->idRol('admin')])
                ->assertForbidden();

            $this->actingAs($actor)
                ->patch(route('usuarios.estado', $usuario), ['is_active' => 0])
                ->assertForbidden();
        }

        $usuario->refresh();
        $this->assertTrue($usuario->hasRole('lector'));
        $this->assertTrue($usuario->is_active);
    }

    public function test_el_enlace_del_dashboard_solo_lo_ve_el_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertSee('Administrar usuarios');

        $this->actingAs(User::factory()->editor()->create())
            ->get(route('dashboard'))
            ->assertDontSee('Administrar usuarios');

        $this->actingAs(User::factory()->lector()->create())
            ->get(route('dashboard'))
            ->assertDontSee('Administrar usuarios');
    }

    // ---------- Búsqueda ----------

    public function test_busca_por_parte_del_nombre_y_del_correo(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin', 'email' => 'admin@nexus.test']);
        User::factory()->create(['name' => 'Laura Gómez', 'email' => 'lgomez@nexus.test']);
        User::factory()->create(['name' => 'Pedro Ruiz', 'email' => 'pruiz@otro.test']);

        $this->actingAs($admin);

        $this->assertSame(['Laura Gómez'], $this->nombresEncontrados('Laura'));
        $this->assertSame(['Pedro Ruiz'], $this->nombresEncontrados('otro.test'));
        $this->get(route('usuarios.index', ['buscar' => 'nadie']))->assertSee('No se encontraron usuarios.');
    }

    public function test_los_comodines_se_buscan_literalmente(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin', 'email' => 'admin@nexus.test']);
        User::factory()->create(['name' => 'Ana_Luz', 'email' => 'ana@nexus.test']);
        User::factory()->create(['name' => 'AnaXLuz', 'email' => 'anax@nexus.test']);
        User::factory()->create(['name' => '100% real', 'email' => 'real@nexus.test']);
        User::factory()->create(['name' => 'Hola!', 'email' => 'hola@nexus.test']);

        $this->actingAs($admin);

        $this->assertSame(['Ana_Luz'], $this->nombresEncontrados('_'));
        $this->assertSame(['100% real'], $this->nombresEncontrados('%'));
        $this->assertSame(['Hola!'], $this->nombresEncontrados('!'));
    }

    public function test_una_inyeccion_sql_en_la_busqueda_no_devuelve_todos(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin', 'email' => 'admin@nexus.test']);
        User::factory()->count(3)->create();

        $this->actingAs($admin);

        $this->assertSame([], $this->nombresEncontrados("' OR 1=1 --"));
        $this->assertSame(4, User::count());
    }

    public function test_una_busqueda_de_mas_de_100_caracteres_se_rechaza(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('usuarios.index', ['buscar' => str_repeat('a', 100)]))
            ->assertOk();

        $this->actingAs($admin)
            ->from(route('usuarios.index'))
            ->get(route('usuarios.index', ['buscar' => str_repeat('a', 101)]))
            ->assertRedirect(route('usuarios.index'))
            ->assertSessionHasErrors('buscar');
    }

    public function test_una_busqueda_que_no_es_texto_se_rechaza(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('usuarios.index'))
            ->get(route('usuarios.index', ['buscar' => ['x']]))
            ->assertRedirect(route('usuarios.index'))
            ->assertSessionHasErrors('buscar');
    }

    public function test_la_lista_se_pagina_de_15_en_15(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(16)->create();

        $this->actingAs($admin)
            ->get(route('usuarios.index'))
            ->assertOk()
            ->assertViewHas('usuarios', fn ($usuarios) => $usuarios->perPage() === 15
                && $usuarios->count() === 15
                && $usuarios->total() === 17);

        $this->actingAs($admin)
            ->get(route('usuarios.index', ['page' => 2]))
            ->assertOk()
            ->assertViewHas('usuarios', fn ($usuarios) => $usuarios->count() === 2);
    }

    public function test_la_paginacion_conserva_la_busqueda(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(20)->create(['name' => 'Repetido']);

        $this->actingAs($admin)
            ->get(route('usuarios.index', ['buscar' => 'Repetido']))
            ->assertOk()
            ->assertSee('buscar=Repetido', false)
            ->assertSee('Página 1 de 2');
    }

    // ---------- Cambio de rol ----------

    public function test_el_admin_cambia_el_rol_de_otro_usuario_y_queda_auditado(): void
    {
        $admin = User::factory()->admin()->create();
        $usuario = User::factory()->lector()->create();

        $this->actingAs($admin)
            ->from(route('usuarios.index'))
            ->patch(route('usuarios.rol', $usuario), ['role_id' => $this->idRol('editor')])
            ->assertRedirect(route('usuarios.index'))
            ->assertSessionHas('status', 'Rol actualizado.');

        $this->assertTrue($usuario->fresh()->hasRole('editor'));

        $registro = $this->ultimoRegistro();

        $this->assertSame('cambiar_rol', $registro->action);
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame($usuario->id, $registro->auditable_id);
        $this->assertSame($this->idRol('lector'), $registro->old_values['role_id']);
        $this->assertSame($this->idRol('editor'), $registro->new_values['role_id']);

        foreach ([$registro->old_values, $registro->new_values] as $valores) {
            $this->assertArrayNotHasKey('password', $valores);
            $this->assertArrayNotHasKey('remember_token', $valores);
        }
    }

    public function test_un_rol_inexistente_se_rechaza(): void
    {
        $admin = User::factory()->admin()->create();
        $usuario = User::factory()->lector()->create();

        $this->actingAs($admin)
            ->patch(route('usuarios.rol', $usuario), ['role_id' => 9999])
            ->assertSessionHasErrors('role_id');

        $this->assertTrue($usuario->fresh()->hasRole('lector'));
    }

    public function test_el_admin_no_puede_cambiar_su_propio_rol(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('usuarios.rol', $admin), ['role_id' => $this->idRol('lector')])
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_el_formulario_de_rol_ignora_is_active_y_otros_campos(): void
    {
        $admin = User::factory()->admin()->create();
        $usuario = User::factory()->lector()->create(['name' => 'Nombre Fijo']);

        $this->actingAs($admin)->patch(route('usuarios.rol', $usuario), [
            'role_id' => $this->idRol('editor'),
            'is_active' => 0,
            'name' => 'Hackeado',
        ])->assertSessionHasNoErrors();

        $usuario->refresh();
        $this->assertTrue($usuario->hasRole('editor'));
        $this->assertTrue($usuario->is_active);
        $this->assertSame('Nombre Fijo', $usuario->name);
    }

    // ---------- Activar y desactivar ----------

    public function test_el_admin_desactiva_a_otro_usuario_y_queda_auditado(): void
    {
        $admin = User::factory()->admin()->create();
        $usuario = User::factory()->create();

        $this->actingAs($admin)
            ->patch(route('usuarios.estado', $usuario), ['is_active' => 0])
            ->assertSessionHas('status', 'Usuario desactivado.');

        $this->assertFalse($usuario->fresh()->is_active);

        $registro = $this->ultimoRegistro();

        $this->assertSame('desactivar', $registro->action);
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame($usuario->id, $registro->auditable_id);
    }

    public function test_el_admin_activa_a_un_usuario_inactivo_y_queda_auditado(): void
    {
        $admin = User::factory()->admin()->create();
        $usuario = User::factory()->inactivo()->create();

        $this->actingAs($admin)
            ->patch(route('usuarios.estado', $usuario), ['is_active' => 1])
            ->assertSessionHas('status', 'Usuario activado.');

        $this->assertTrue($usuario->fresh()->is_active);

        $registro = $this->ultimoRegistro();

        $this->assertSame('activar', $registro->action);
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame($usuario->id, $registro->auditable_id);
    }

    public function test_el_admin_no_puede_desactivarse_a_si_mismo(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('usuarios.estado', $admin), ['is_active' => 0])
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_un_estado_invalido_se_rechaza(): void
    {
        $admin = User::factory()->admin()->create();
        $usuario = User::factory()->create();

        $this->actingAs($admin)
            ->patch(route('usuarios.estado', $usuario), ['is_active' => 'quizas'])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($usuario->fresh()->is_active);
    }

    public function test_el_formulario_de_estado_ignora_role_id(): void
    {
        $admin = User::factory()->admin()->create();
        $usuario = User::factory()->lector()->create();

        $this->actingAs($admin)->patch(route('usuarios.estado', $usuario), [
            'is_active' => 0,
            'role_id' => $this->idRol('admin'),
        ])->assertSessionHasNoErrors();

        $usuario->refresh();
        $this->assertFalse($usuario->is_active);
        $this->assertTrue($usuario->hasRole('lector'));
    }

    public function test_enviar_el_mismo_valor_no_genera_auditoria(): void
    {
        $admin = User::factory()->admin()->create();
        $usuario = User::factory()->editor()->create();
        $total = AuditLog::count();

        $this->actingAs($admin);

        $this->patch(route('usuarios.rol', $usuario), ['role_id' => $this->idRol('editor')])->assertSessionHasNoErrors();
        $this->patch(route('usuarios.estado', $usuario), ['is_active' => 1])->assertSessionHasNoErrors();

        $this->assertSame($total, AuditLog::count());
    }

    public function test_un_usuario_que_no_existe_responde_404(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        $this->patch(route('usuarios.rol', 9999), ['role_id' => $this->idRol('editor')])->assertNotFound();
        $this->patch(route('usuarios.estado', 9999), ['is_active' => 0])->assertNotFound();
    }

    // ---------- Vista ----------

    public function test_la_fila_del_admin_dice_tu_cuenta_y_no_tiene_formularios(): void
    {
        $admin = User::factory()->admin()->create();
        $otro = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('usuarios.index'))
            ->assertOk()
            ->assertSee('Tu cuenta')
            ->assertDontSee(route('usuarios.rol', $admin))
            ->assertDontSee(route('usuarios.estado', $admin))
            ->assertSee(route('usuarios.rol', $otro))
            ->assertSee(route('usuarios.estado', $otro));
    }

    public function test_un_nombre_con_html_se_muestra_escapado(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => '<script>alert(1)</script>']);

        $html = $this->actingAs($admin)->get(route('usuarios.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('<td>&lt;script&gt;alert(1)&lt;/script&gt;</td>', $html);
        $this->assertStringContainsString('data-confirm="¿Seguro que deseas desactivar a &lt;script&gt;alert(1)&lt;/script&gt;?"', $html);
    }

    public function test_el_listado_no_hace_consultas_n_mas_1(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create();

        $this->actingAs($admin);

        $this->get(route('usuarios.index'))->assertOk();
        $pocasFilas = $this->consultasDelListado();

        User::factory()->count(5)->editor()->create();
        User::factory()->count(5)->lector()->create();
        User::factory()->count(2)->admin()->create();

        $this->assertSame($pocasFilas, $this->consultasDelListado());
    }

    // ---------- Policy ----------

    public function test_ni_un_admin_puede_borrar_usuarios(): void
    {
        $admin = User::factory()->admin()->create();
        $otro = User::factory()->create();

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $otro));
    }
}
