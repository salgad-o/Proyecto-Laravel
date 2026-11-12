<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
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

    // ---------- NP-20 paso 6b: tarjetas ----------

    private function xpath(string $html): DOMXPath
    {
        $anterior = libxml_use_internal_errors(true);
        $documento = new DOMDocument;
        $documento->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return new DOMXPath($documento);
    }

    private function tarjeta(DOMXPath $xpath, Role $role): ?DOMElement
    {
        $tarjeta = $xpath->query('//article[@class="rol-tarjeta"][@aria-labelledby="rol-'.$role->id.'"]')->item(0);

        return $tarjeta instanceof DOMElement ? $tarjeta : null;
    }

    public function test_tres_tarjetas_con_chip_interno_contador_enlazado_y_permisos(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(2)->editor()->create();

        $xpath = $this->xpath($this->actingAs($admin)->get(route('roles.index'))->assertOk()->getContent());

        $this->assertSame(3, $xpath->query('//article[@class="rol-tarjeta"]')->length);

        foreach (['admin' => '1 usuario', 'editor' => '2 usuarios', 'lector' => '0 usuarios'] as $nombre => $contador) {
            $role = $this->rol($nombre);
            $tarjeta = $this->tarjeta($xpath, $role);
            $this->assertNotNull($tarjeta, "Sin tarjeta para {$nombre}");

            $this->assertSame($role->label, trim($xpath->query('.//h2', $tarjeta)->item(0)->textContent));
            $this->assertSame($nombre, trim($xpath->query('.//span[@class="chip rol-chip"]', $tarjeta)->item(0)->textContent));

            $enlace = $xpath->query('.//p[@class="rol-contador"]/a', $tarjeta)->item(0);
            $this->assertSame($contador, trim($enlace->textContent));
            $this->assertSame(route('usuarios.index', ['vista' => $nombre]), $enlace->getAttribute('href'));

            $permisos = array_map(fn ($li) => trim($li->textContent), iterator_to_array($xpath->query('.//ul[@class="rol-permisos"]/li', $tarjeta)));
            $this->assertSame(config('roles.'.$nombre), $permisos);
        }
    }

    public function test_un_rol_que_no_es_una_pestana_de_usuarios_no_lleva_enlace(): void
    {
        $admin = User::factory()->admin()->create();
        $raro = Role::forceCreate(['name' => 'moderador', 'label' => 'Moderador']);

        $xpath = $this->xpath($this->actingAs($admin)->get(route('roles.index'))->assertOk()->getContent());
        $tarjeta = $this->tarjeta($xpath, $raro);

        $this->assertSame('0 usuarios', trim($xpath->query('.//p[@class="rol-contador"]', $tarjeta)->item(0)->textContent));
        $this->assertSame(0, $xpath->query('.//p[@class="rol-contador"]/a', $tarjeta)->length);
        $this->assertSame('Sin descripción.', trim($xpath->query('.//p[contains(@class,"rol-descripcion")]', $tarjeta)->item(0)->textContent));
        $this->assertSame('Sin permisos definidos.', trim($xpath->query('.//ul[@class="rol-permisos"]/li', $tarjeta)->item(0)->textContent));
    }

    public function test_editar_es_un_details_cerrado_con_el_formulario(): void
    {
        $admin = User::factory()->admin()->create();
        $role = $this->rol('editor');

        $xpath = $this->xpath($this->actingAs($admin)->get(route('roles.index'))->getContent());

        $this->assertSame(3, $xpath->query('//details[@class="rol-editar"]')->length);
        $this->assertSame(0, $xpath->query('//details[@open]')->length);
        $this->assertSame(0, $xpath->query('//*[@role="alert"]')->length);

        $details = $xpath->query('.//details[@class="rol-editar"]', $this->tarjeta($xpath, $role))->item(0);
        $this->assertSame('Editar rol '.$role->label, preg_replace('/\s+/', ' ', trim($xpath->query('./summary', $details)->item(0)->textContent)));

        $form = $xpath->query('.//form[@action="'.route('roles.update', $role).'"]', $details)->item(0);
        $this->assertSame('POST', $form->getAttribute('method'));
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
        $this->assertSame('PATCH', $xpath->query('.//input[@name="_method"]', $form)->item(0)->getAttribute('value'));
        $this->assertSame((string) $role->id, $xpath->query('.//input[@name="form_role"]', $form)->item(0)->getAttribute('value'));
        $this->assertSame('50', $xpath->query('.//input[@id="label_'.$role->id.'"]', $form)->item(0)->getAttribute('maxlength'));
        $this->assertSame('255', $xpath->query('.//textarea[@id="description_'.$role->id.'"]', $form)->item(0)->getAttribute('maxlength'));
        $this->assertSame(1, $xpath->query('.//label[@for="label_'.$role->id.'"]', $form)->length);
        $this->assertSame(1, $xpath->query('.//label[@for="description_'.$role->id.'"]', $form)->length);
        $this->assertSame('Guardar cambios', trim($xpath->query('.//button[@type="submit"]', $form)->item(0)->textContent));
    }

    public function test_un_error_abre_la_tarjeta_del_rol_y_lo_muestra_junto_al_campo(): void
    {
        $admin = User::factory()->admin()->create();
        $role = $this->rol('editor');

        $this->actingAs($admin)
            ->from(route('roles.index'))
            ->patch(route('roles.update', $role), ['form_role' => (string) $role->id, 'label' => '', 'description' => str_repeat('d', 256)])
            ->assertSessionHasErrors(['label', 'description']);

        $xpath = $this->xpath($this->actingAs($admin)->get(route('roles.index'))->getContent());

        $this->assertSame(1, $xpath->query('//details[@open]')->length);
        $tarjeta = $this->tarjeta($xpath, $role);
        $this->assertTrue($xpath->query('.//details', $tarjeta)->item(0)->hasAttribute('open'));

        foreach (['label' => 'input', 'description' => 'textarea'] as $campo => $etiqueta) {
            $control = $xpath->query('.//'.$etiqueta.'[@id="'.$campo.'_'.$role->id.'"]', $tarjeta)->item(0);
            $this->assertSame('true', $control->getAttribute('aria-invalid'));
            $this->assertSame('error-'.$campo.'-'.$role->id, $control->getAttribute('aria-describedby'));
            $error = $xpath->query('.//p[@id="error-'.$campo.'-'.$role->id.'"][@role="alert"]', $tarjeta)->item(0);
            $this->assertNotNull($error);
            $this->assertNotSame('', trim($error->textContent));
        }

        // El texto escrito vuelve al campo, y no hay un aviso general duplicado.
        $this->assertSame(str_repeat('d', 256), $xpath->query('.//textarea[@id="description_'.$role->id.'"]', $tarjeta)->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//div[@class="buscador-error"][@role="alert"]')->length);
        $this->assertSame('Editor', $role->fresh()->label);
    }

    public function test_un_label_enviado_vacio_vuelve_vacio_con_su_error(): void
    {
        $admin = User::factory()->admin()->create();
        $role = $this->rol('editor');
        $role->update(['description' => 'Descripción guardada']);
        $lector = $this->rol('lector');

        $this->actingAs($admin)
            ->from(route('roles.index'))
            ->patch(route('roles.update', $role), ['form_role' => (string) $role->id, 'label' => '', 'description' => 'Nueva descripción'])
            // El mensaje de la regla "required", sin depender del idioma de la app.
            ->assertSessionHasErrors(['label' => __('validation.required', ['attribute' => 'nombre visible'])]);

        $xpath = $this->xpath($this->actingAs($admin)->get(route('roles.index'))->getContent());
        $tarjeta = $this->tarjeta($xpath, $role);

        // El input muestra lo enviado (vacío), no el nombre guardado; la descripción, lo escrito.
        $input = $xpath->query('.//input[@id="label_'.$role->id.'"]', $tarjeta)->item(0);
        $this->assertTrue($input->hasAttribute('value'));
        $this->assertSame('', $input->getAttribute('value'));
        $this->assertSame('Nueva descripción', $xpath->query('.//textarea[@id="description_'.$role->id.'"]', $tarjeta)->item(0)->textContent);
        $this->assertNotSame('', trim($xpath->query('.//p[@id="error-label-'.$role->id.'"]', $tarjeta)->item(0)->textContent));

        // Las demás tarjetas muestran sus valores guardados.
        $this->assertSame($lector->label, $xpath->query('//input[@id="label_'.$lector->id.'"]')->item(0)->getAttribute('value'));
        $this->assertSame('Editor', $role->fresh()->label);
    }

    public function test_una_description_enviada_vacia_vuelve_vacia(): void
    {
        $admin = User::factory()->admin()->create();
        $role = $this->rol('editor');
        $role->update(['description' => 'Descripción guardada']);

        // label de 51 caracteres provoca el error; description va vacía a propósito.
        $largo = str_repeat('L', 51);
        $this->actingAs($admin)
            ->from(route('roles.index'))
            ->patch(route('roles.update', $role), ['form_role' => (string) $role->id, 'label' => $largo, 'description' => ''])
            ->assertSessionHasErrors('label');

        $xpath = $this->xpath($this->actingAs($admin)->get(route('roles.index'))->getContent());
        $tarjeta = $this->tarjeta($xpath, $role);

        $this->assertSame('', $xpath->query('.//textarea[@id="description_'.$role->id.'"]', $tarjeta)->item(0)->textContent);
        $this->assertSame($largo, $xpath->query('.//input[@id="label_'.$role->id.'"]', $tarjeta)->item(0)->getAttribute('value'));
        $this->assertSame('Descripción guardada', $role->fresh()->description);
    }

    public function test_form_role_manipulado_no_rompe_la_vista_ni_cambia_el_rol_actualizado(): void
    {
        $admin = User::factory()->admin()->create();
        $editor = $this->rol('editor');
        $lector = $this->rol('lector');

        // Datos válidos con form_role de otro rol: se actualiza el de la ruta.
        $this->actingAs($admin)
            ->from(route('roles.index'))
            ->patch(route('roles.update', $editor), ['form_role' => (string) $lector->id, 'label' => 'Redactor', 'description' => 'x'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Redactor', $editor->fresh()->label);
        $this->assertSame('Lector', $lector->fresh()->label);

        // Con error y form_role raro: ninguna tarjeta se abre y el aviso va arriba.
        foreach (['abc', '999', '<script>', ''] as $raro) {
            $this->actingAs($admin)
                ->from(route('roles.index'))
                ->patch(route('roles.update', $editor), ['form_role' => $raro, 'label' => ''])
                ->assertSessionHasErrors('label');

            $xpath = $this->xpath($this->actingAs($admin)->get(route('roles.index'))->assertOk()->getContent());

            $this->assertSame(0, $xpath->query('//details[@open]')->length, "form_role={$raro}");
            $this->assertSame(1, $xpath->query('//div[@class="buscador-error"][@role="alert"]')->length, "form_role={$raro}");
        }

        $this->assertSame('Redactor', $editor->fresh()->label);
    }

    public function test_el_label_con_script_va_escapado_tambien_en_el_summary(): void
    {
        $admin = User::factory()->admin()->create();
        $role = $this->rol('editor');
        $role->update(['label' => '<script>alert(1)</script>']);

        $html = $this->actingAs($admin)->get(route('roles.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('<h2 id="rol-'.$role->id.'" class="rol-titulo">&lt;script&gt;alert(1)&lt;/script&gt;</h2>', $html);
        $this->assertStringContainsString('Editar<span class="sr-only"> rol &lt;script&gt;alert(1)&lt;/script&gt;</span>', $html);
    }

    public function test_el_aviso_de_estado_usa_role_status(): void
    {
        $admin = User::factory()->admin()->create();
        $role = $this->rol('editor');

        $this->actingAs($admin)
            ->from(route('roles.index'))
            ->followingRedirects()
            ->patch(route('roles.update', $role), ['form_role' => (string) $role->id, 'label' => 'Redactor', 'description' => ''])
            ->assertOk()
            ->assertSee('<p class="aviso-exito" role="status">Rol actualizado.</p>', false)
            ->assertSee('PANEL DE GESTIÓN');
    }

    public function test_la_vista_no_tiene_codigo_en_linea(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())->get(route('roles.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('style=', $html);
        $this->assertDoesNotMatchRegularExpression('#<[^>]+\son[a-z]+=#i', $html);
        $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#i', $html);
    }
}
