<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GestionarRolesTest extends TestCase
{
    use RefreshDatabase;

    private function rol(string $nombre): Role
    {
        return Role::where('name', $nombre)->firstOrFail();
    }

    private function ultimoRegistro(): AuditLog
    {
        return AuditLog::latest('id')->firstOrFail();
    }

    private function consultasDelListado(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('roles.index'))->assertOk();

        return count(DB::getQueryLog());
    }

    // ---------- Acceso ----------

    public function test_el_admin_ve_los_tres_roles_con_su_conteo_de_usuarios(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(2)->editor()->create();
        User::factory()->count(3)->lector()->create();

        $this->actingAs($admin)
            ->get(route('roles.index'))
            ->assertOk()
            ->assertSeeInOrder(['Administrador', 'Editor', 'Lector'])
            ->assertSee('1 usuario')
            ->assertSee('2 usuarios')
            ->assertSee('3 usuarios')
            ->assertViewHas('roles', function ($roles) {
                return $roles->pluck('users_count', 'name')->all() === [
                    'admin' => 1,
                    'editor' => 2,
                    'lector' => 3,
                ];
            });
    }

    public function test_el_listado_muestra_los_permisos_de_la_configuracion(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('roles.index'))
            ->assertOk()
            ->assertSee(config('roles.admin')[0])
            ->assertSee(config('roles.editor')[0])
            ->assertSee(config('roles.lector')[0]);
    }

    public function test_un_editor_no_puede_ver_ni_editar_roles(): void
    {
        $editor = User::factory()->editor()->create();
        $role = $this->rol('lector');

        $this->actingAs($editor)->get(route('roles.index'))->assertForbidden();
        $this->actingAs($editor)
            ->patch(route('roles.update', $role), ['label' => 'Lectores'])
            ->assertForbidden();

        $this->assertSame('Lector', $role->fresh()->label);
    }

    public function test_un_lector_no_puede_ver_ni_editar_roles(): void
    {
        $lector = User::factory()->lector()->create();
        $role = $this->rol('editor');

        $this->actingAs($lector)->get(route('roles.index'))->assertForbidden();
        $this->actingAs($lector)
            ->patch(route('roles.update', $role), ['label' => 'Editores'])
            ->assertForbidden();

        $this->assertSame('Editor', $role->fresh()->label);
    }

    public function test_un_visitante_es_enviado_al_login(): void
    {
        $role = $this->rol('editor');

        $this->get(route('roles.index'))->assertRedirect(route('login'));
        $this->patch(route('roles.update', $role), ['label' => 'Editores'])
            ->assertRedirect(route('login'));
    }

    public function test_el_enlace_del_dashboard_solo_lo_ve_el_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertSee('Roles');

        $this->actingAs(User::factory()->editor()->create())
            ->get(route('dashboard'))
            ->assertDontSee('Roles');

        $this->actingAs(User::factory()->lector()->create())
            ->get(route('dashboard'))
            ->assertDontSee('Roles');
    }

    // ---------- Edición ----------

    public function test_el_admin_edita_label_y_description_y_queda_auditado(): void
    {
        $admin = User::factory()->admin()->create();
        $role = $this->rol('editor');
        $antes = [
            'label' => $role->label,
            'description' => $role->description,
        ];

        $this->actingAs($admin)
            ->from(route('roles.index'))
            ->patch(route('roles.update', $role), [
                'label' => 'Editor de noticias',
                'description' => 'Escribe y publica sus propias noticias.',
            ])
            ->assertRedirect(route('roles.index'))
            ->assertSessionHas('status', 'Rol actualizado.');

        $role->refresh();
        $this->assertSame('Editor de noticias', $role->label);
        $this->assertSame('Escribe y publica sus propias noticias.', $role->description);

        $registro = $this->ultimoRegistro();

        $this->assertSame('editar', $registro->action);
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame(Role::class, $registro->auditable_type);
        $this->assertSame($role->id, $registro->auditable_id);
        $this->assertSame($antes['label'], $registro->old_values['label']);
        $this->assertSame($antes['description'], $registro->old_values['description']);
        $this->assertSame('Editor de noticias', $registro->new_values['label']);
        $this->assertSame('Escribe y publica sus propias noticias.', $registro->new_values['description']);
    }

    public function test_enviar_name_o_id_no_cambia_el_nombre_interno(): void
    {
        $admin = User::factory()->admin()->create();
        $role = $this->rol('editor');
        $idOriginal = $role->id;

        $this->actingAs($admin)
            ->patch(route('roles.update', $role), [
                'id' => 9999,
                'name' => 'superadmin',
                'label' => 'Editor',
                'description' => $role->description,
            ])
            ->assertSessionHasNoErrors();

        $role->refresh();
        $this->assertSame('editor', $role->name);
        $this->assertSame($idOriginal, $role->id);
    }

    public function test_name_no_es_asignable_masivamente(): void
    {
        $role = $this->rol('editor');

        $role->fill([
            'name' => 'superadmin',
            'label' => 'Editor',
        ]);

        $this->assertSame('editor', $role->name);
    }

    public function test_label_vacio_da_error_de_validacion(): void
    {
        $admin = User::factory()->admin()->create();
        $role = $this->rol('editor');

        $this->actingAs($admin)
            ->from(route('roles.index'))
            ->patch(route('roles.update', $role), ['label' => ''])
            ->assertRedirect(route('roles.index'))
            ->assertSessionHasErrors('label');

        $this->assertSame('Editor', $role->fresh()->label);
    }

    public function test_label_de_51_caracteres_da_error_de_validacion(): void
    {
        $admin = User::factory()->admin()->create();
        $role = $this->rol('editor');

        $this->actingAs($admin)
            ->from(route('roles.index'))
            ->patch(route('roles.update', $role), ['label' => str_repeat('a', 51)])
            ->assertRedirect(route('roles.index'))
            ->assertSessionHasErrors('label');

        $this->assertSame('Editor', $role->fresh()->label);
    }

    public function test_description_de_256_caracteres_da_error_de_validacion(): void
    {
        $admin = User::factory()->admin()->create();
        $role = $this->rol('editor');

        $this->actingAs($admin)
            ->from(route('roles.index'))
            ->patch(route('roles.update', $role), [
                'label' => 'Editor',
                'description' => str_repeat('a', 256),
            ])
            ->assertRedirect(route('roles.index'))
            ->assertSessionHasErrors('description');

        $this->assertSame('Editor', $role->fresh()->label);
    }

    public function test_un_label_con_script_se_muestra_escapado(): void
    {
        $admin = User::factory()->admin()->create();
        $role = $this->rol('editor');
        $label = '<script>alert(1)</script>';

        $this->actingAs($admin)
            ->patch(route('roles.update', $role), [
                'label' => $label,
                'description' => 'Prueba de escape.',
            ])
            ->assertSessionHasNoErrors();

        $html = $this->actingAs($admin)
            ->get(route('roles.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($label, $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_label_como_arreglo_no_rompe_la_vista_al_redirigir(): void
    {
        $admin = User::factory()->admin()->create();
        $role = $this->rol('editor');

        $this->actingAs($admin)
            ->from(route('roles.index'))
            ->followingRedirects()
            ->patch(route('roles.update', $role), [
                'form_role' => (string) $role->id,
                'label' => ['x'],
            ])
            ->assertOk()
            ->assertSee('id="label_'.$role->id.'"', false);
    }

    public function test_enviar_los_mismos_valores_no_genera_auditoria(): void
    {
        $admin = User::factory()->admin()->create();
        $role = $this->rol('editor');
        $total = AuditLog::count();

        $this->actingAs($admin)
            ->patch(route('roles.update', $role), [
                'label' => $role->label,
                'description' => $role->description,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($total, AuditLog::count());
    }

    // ---------- Rutas y policy ----------

    public function test_no_existe_ruta_para_eliminar_roles(): void
    {
        $admin = User::factory()->admin()->create();
        $role = $this->rol('editor');

        $this->assertFalse(Route::has('roles.destroy'));

        $this->actingAs($admin)
            ->delete('/dashboard/roles/'.$role->id)
            ->assertStatus(405);
    }

    public function test_ni_un_admin_puede_borrar_roles(): void
    {
        $admin = User::factory()->admin()->create();
        $role = $this->rol('editor');

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $role));
    }

    // ---------- Rendimiento ----------

    public function test_el_listado_no_hace_consultas_n_mas_1(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->get(route('roles.index'))->assertOk();
        $pocasFilas = $this->consultasDelListado();

        User::factory()->count(5)->editor()->create();
        User::factory()->count(5)->lector()->create();

        $this->assertSame($pocasFilas, $this->consultasDelListado());
    }
}
