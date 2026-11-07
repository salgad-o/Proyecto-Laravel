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
            // NP-20 paso 6a: la paginación es pagination.nexus; el caption dice la página.
            ->assertSee('Usuarios (Todos): página 1 de 2');
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
        // NP-20 paso 6a: el nombre va en la celda, en el texto sr-only de "Gestionar" y en data-confirm.
        $this->assertStringContainsString('<span class="usuario-nombre" title="&lt;script&gt;alert(1)&lt;/script&gt;">&lt;script&gt;alert(1)&lt;/script&gt;</span>', $html);
        $this->assertStringContainsString('Gestionar<span class="sr-only"> a &lt;script&gt;alert(1)&lt;/script&gt;</span>', $html);
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

    // ---------- NP-20 paso 6a: pestañas, tabla y "Gestionar" ----------

    private function xpath(string $html): DOMXPath
    {
        $anterior = libxml_use_internal_errors(true);
        $documento = new DOMDocument;
        $documento->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return new DOMXPath($documento);
    }

    /**
     * Admin autenticado + 2 editores (1 inactivo), 3 lectores (1 inactivo) y otro admin.
     * Total 7: 2 admins, 2 editores, 3 lectores, 2 inactivos. "Ana" aparece en 3 nombres.
     */
    private function poblar(): User
    {
        $admin = User::factory()->admin()->create(['name' => 'Ana Admin']);
        User::factory()->admin()->create(['name' => 'Otro Admin']);
        User::factory()->editor()->create(['name' => 'Ana Editora']);
        User::factory()->editor()->create(['name' => 'Edu Editor', 'is_active' => false]);
        User::factory()->lector()->create(['name' => 'Ana Lectora', 'is_active' => false]);
        User::factory()->lector()->count(2)->create(['name' => 'Luis Lector']);

        $this->actingAs($admin);

        return $admin;
    }

    /**
     * @return array<string, int>
     */
    private function contadores(string $html): array
    {
        $contadores = [];

        foreach (['Todos', 'Admins', 'Editores', 'Lectores', 'Inactivos'] as $pestana) {
            $this->assertSame(1, preg_match(
                '#>\s*'.$pestana.' <span class="panel-estado-total" aria-hidden="true">\((\d+)\)</span><span class="sr-only">, (\d+) (usuarios?)</span>#',
                $html,
                $m
            ), "Sin contador para {$pestana}");
            $this->assertSame($m[1], $m[2]);
            $this->assertSame($m[1] === '1' ? 'usuario' : 'usuarios', $m[3]);
            $contadores[$pestana] = (int) $m[1];
        }

        return $contadores;
    }

    public function test_cada_pestana_filtra_lo_que_dice(): void
    {
        $this->poblar();

        $casos = [
            'todos' => fn (User $u) => true,
            'admin' => fn (User $u) => $u->hasRole('admin'),
            'editor' => fn (User $u) => $u->hasRole('editor'),
            'lector' => fn (User $u) => $u->hasRole('lector'),
            'inactivos' => fn (User $u) => ! $u->is_active,
        ];
        $totales = ['todos' => 7, 'admin' => 2, 'editor' => 2, 'lector' => 3, 'inactivos' => 2];

        foreach ($casos as $vista => $condicion) {
            $this->get(route('usuarios.index', ['vista' => $vista]))
                ->assertOk()
                ->assertViewHas('vista', $vista)
                ->assertViewHas('usuarios', fn ($usuarios) => $usuarios->total() === $totales[$vista]
                    && $usuarios->every($condicion));
        }
    }

    public function test_los_roles_incluyen_activos_e_inactivos(): void
    {
        $this->poblar();

        $this->get(route('usuarios.index', ['vista' => 'editor']))
            ->assertViewHas('usuarios', fn ($usuarios) => $usuarios->pluck('name')->sort()->values()->all() === ['Ana Editora', 'Edu Editor']);
    }

    public function test_la_pestana_activa_lleva_aria_current(): void
    {
        $this->poblar();

        $xpath = $this->xpath($this->get(route('usuarios.index', ['vista' => 'lector']))->getContent());
        $activa = $xpath->query('//nav[@class="panel-estado-tabs"]/a[@aria-current="page"]');

        $this->assertSame(1, $activa->length);
        $this->assertStringContainsString('Lectores', $activa->item(0)->textContent);
        $this->assertStringContainsString('panel-estado-activo', $activa->item(0)->getAttribute('class'));
    }

    public function test_los_contadores_son_correctos_y_no_dependen_de_la_pestana(): void
    {
        $this->poblar();
        $esperado = ['Todos' => 7, 'Admins' => 2, 'Editores' => 2, 'Lectores' => 3, 'Inactivos' => 2];

        foreach (['todos', 'admin', 'inactivos'] as $vista) {
            $this->assertSame($esperado, $this->contadores($this->get(route('usuarios.index', ['vista' => $vista]))->getContent()));
        }
    }

    public function test_los_contadores_respetan_la_busqueda(): void
    {
        $this->poblar();

        // "Ana": Ana Admin, Ana Editora y Ana Lectora (inactiva).
        $this->assertSame(
            ['Todos' => 3, 'Admins' => 1, 'Editores' => 1, 'Lectores' => 1, 'Inactivos' => 1],
            $this->contadores($this->get(route('usuarios.index', ['buscar' => 'Ana']))->getContent())
        );
    }

    public function test_los_contadores_salen_de_una_sola_consulta_agrupada(): void
    {
        $this->poblar();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('usuarios.index'))->assertOk();
        $consultas = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $agrupadas = array_filter($consultas, fn (string $sql) => preg_match('/group by [`"]role_id[`"]/i', $sql) === 1);
        $this->assertCount(1, $agrupadas);
    }

    public function test_una_pestana_invalida_o_un_arreglo_se_rechazan(): void
    {
        $this->poblar();

        foreach (['superadmin', 'Admin', 'admins'] as $valor) {
            $this->from(route('usuarios.index'))
                ->get(route('usuarios.index', ['vista' => $valor]))
                ->assertRedirect(route('usuarios.index'))
                ->assertSessionHasErrors('vista');
        }

        $this->from(route('usuarios.index'))
            ->get(route('usuarios.index', ['vista' => ['admin']]))
            ->assertSessionHasErrors('vista');

        // Vacía equivale a "todos".
        $this->get(route('usuarios.index', ['vista' => '']))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertViewHas('vista', 'todos');
    }

    public function test_las_pestanas_conservan_la_busqueda_y_la_paginacion_la_vista(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->lector()->count(20)->create(['name' => 'Repetido']);
        $this->actingAs($admin);

        $html = $this->get(route('usuarios.index', ['vista' => 'lector', 'buscar' => 'Repetido']))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        // Pestañas: conservan buscar.
        foreach ($xpath->query('//nav[@class="panel-estado-tabs"]/a') as $pestana) {
            $this->assertStringContainsString('buscar=Repetido', $pestana->getAttribute('href'));
        }

        // Paginación: conserva vista y buscar.
        $siguiente = $xpath->query('//nav[@class="paginacion"]//a[@rel="next"]')->item(0);
        $this->assertNotNull($siguiente);
        $this->assertStringContainsString('vista=lector', $siguiente->getAttribute('href'));
        $this->assertStringContainsString('buscar=Repetido', $siguiente->getAttribute('href'));

        // El buscador conserva la pestaña.
        $this->assertSame('lector', $xpath->query('//form[@role="search"]//input[@name="vista"]')->item(0)?->getAttribute('value'));
    }

    public function test_una_pagina_fuera_de_rango_responde_404(): void
    {
        $this->poblar();

        $this->get(route('usuarios.index', ['page' => 99]))->assertNotFound();
    }

    public function test_las_consultas_no_crecen_con_usuarios_con_foto(): void
    {
        $admin = User::factory()->admin()->create();

        $crear = function (int $cuantos): void {
            foreach (range(1, $cuantos) as $i) {
                $usuario = User::factory()->editor()->create();
                $usuario->forceFill(['avatar_path' => str_repeat('c', 39).$i.'.webp'])->saveQuietly();
            }
        };

        $contar = function (array $query) use ($admin): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin->fresh())->get(route('usuarios.index', $query))->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $crear(2);
        $pocos = [$contar([]), $contar(['vista' => 'editor'])];

        $crear(9);
        $this->assertSame($pocos, [$contar([]), $contar(['vista' => 'editor'])]);
    }

    private function filaDe(DOMXPath $xpath, string $nombre): ?DOMElement
    {
        $fila = $xpath->query('//tbody/tr[th//span[@class="usuario-nombre"][normalize-space()="'.$nombre.'"]]')->item(0);

        return $fila instanceof DOMElement ? $fila : null;
    }

    /**
     * Botón "Gestionar" de un usuario y la fila de panel a la que apunta (aria-controls),
     * comprobando que es la fila inmediatamente siguiente a la de sus datos.
     *
     * @return array{0: DOMElement, 1: DOMElement}
     */
    private function botonYPanel(DOMXPath $xpath, string $nombre): array
    {
        $fila = $this->filaDe($xpath, $nombre);
        $this->assertNotNull($fila, "Sin fila para {$nombre}");

        $boton = $xpath->query('.//button[@type="button"][@data-gestionar]', $fila)->item(0);
        $this->assertInstanceOf(DOMElement::class, $boton, "Sin botón Gestionar para {$nombre}");

        $id = $boton->getAttribute('aria-controls');
        $this->assertNotSame('', $id);
        $panel = $xpath->query('//tbody/tr[@id="'.$id.'"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $panel, "aria-controls={$id} no apunta a ninguna fila");
        $this->assertSame($panel, $xpath->query('following-sibling::tr[1]', $fila)->item(0), 'El panel va justo debajo de su usuario.');

        return [$boton, $panel];
    }

    public function test_la_fila_propia_dice_tu_cuenta_sin_gestionar(): void
    {
        $admin = $this->poblar();

        $xpath = $this->xpath($this->get(route('usuarios.index'))->getContent());
        $propia = $this->filaDe($xpath, 'Ana Admin');

        $this->assertNotNull($propia);
        $this->assertSame('Tu cuenta', trim($xpath->query('.//span[@class="usuario-sin-acciones"]', $propia)->item(0)->textContent));
        $this->assertSame(0, $xpath->query('.//button|.//form', $propia)->length);
        // Sin fila de panel: la siguiente fila (si la hay) es de otro usuario.
        $this->assertSame(0, $xpath->query('//tbody/tr[@id="gestion-usuario-'.$admin->id.'"]')->length);
        $this->assertNotSame('usuario-panel-fila', $xpath->query('following-sibling::tr[1]', $propia)->item(0)?->getAttribute('class'));

        // Las demás: un botón y una fila de panel cada una (6 usuarios).
        $this->assertSame(6, $xpath->query('//tbody//button[@data-gestionar]')->length);
        $this->assertSame(6, $xpath->query('//tbody/tr[@class="usuario-panel-fila"]')->length);
    }

    public function test_gestionar_es_un_boton_disclosure_con_su_fila_de_panel(): void
    {
        $this->poblar();
        $otro = User::where('name', 'Ana Editora')->firstOrFail();

        $xpath = $this->xpath($this->get(route('usuarios.index'))->getContent());
        [$boton, $panel] = $this->botonYPanel($xpath, 'Ana Editora');

        // Botón: nombre accesible con el usuario, plegado, flecha decorativa.
        $this->assertSame('Gestionar a Ana Editora', preg_replace('/\s+/', ' ', trim($boton->textContent)));
        $this->assertSame('false', $boton->getAttribute('aria-expanded'));
        $this->assertSame('gestion-usuario-'.$otro->id, $boton->getAttribute('aria-controls'));
        $this->assertSame('true', $boton->getElementsByTagName('svg')->item(0)->getAttribute('aria-hidden'));

        // Panel: SIN hidden en el HTML (sin JS se ve); una sola celda a todo el ancho.
        $this->assertFalse($panel->hasAttribute('hidden'));
        $this->assertSame('row', $panel->getAttribute('role'));
        $celdas = $xpath->query('./td', $panel);
        $this->assertSame(1, $celdas->length);
        $this->assertSame('5', $celdas->item(0)->getAttribute('colspan'));
        $this->assertSame('cell', $celdas->item(0)->getAttribute('role'));

        $rol = $xpath->query('.//form[@action="'.route('usuarios.rol', $otro).'"]', $panel)->item(0);
        $this->assertSame('POST', $rol->getAttribute('method'));
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $rol)->length);
        $this->assertSame('PATCH', $xpath->query('.//input[@name="_method"]', $rol)->item(0)->getAttribute('value'));
        $this->assertSame((string) $otro->id, $xpath->query('.//input[@name="form_usuario"]', $rol)->item(0)->getAttribute('value'));
        $this->assertSame(1, $xpath->query('.//label[@for="role_id_'.$otro->id.'"]', $rol)->length);
        $this->assertSame(1, $xpath->query('.//select[@id="role_id_'.$otro->id.'"][@name="role_id"]', $rol)->length);
        $this->assertSame('Guardar rol', trim($xpath->query('.//button[@type="submit"]', $rol)->item(0)->textContent));

        $estado = $xpath->query('.//form[@action="'.route('usuarios.estado', $otro).'"]', $panel)->item(0);
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $estado)->length);
        $this->assertSame('PATCH', $xpath->query('.//input[@name="_method"]', $estado)->item(0)->getAttribute('value'));
        $this->assertSame('0', $xpath->query('.//input[@name="is_active"]', $estado)->item(0)->getAttribute('value'));
        $enviar = $xpath->query('.//button[@type="submit"]', $estado)->item(0);
        $this->assertSame('Desactivar cuenta', trim($enviar->textContent));
        $this->assertStringContainsString('boton-peligro', $enviar->getAttribute('class'));
        $this->assertSame('¿Seguro que deseas desactivar a Ana Editora?', $enviar->getAttribute('data-confirm'));
    }

    public function test_sin_js_ningun_panel_viene_oculto(): void
    {
        $this->poblar();

        $html = $this->get(route('usuarios.index'))->getContent();
        $xpath = $this->xpath($html);

        // El servidor nunca oculta un panel: lo hace app.js (mejora progresiva).
        $this->assertSame(0, $xpath->query('//tr[@hidden]|//*[@class="usuario-gestion"][@hidden]')->length);
        $this->assertSame(6, $xpath->query('//tbody/tr[@class="usuario-panel-fila"]//form[contains(@action,"/rol")]')->length);
    }

    public function test_activar_una_cuenta_inactiva_no_usa_estilo_de_peligro(): void
    {
        $this->poblar();

        $xpath = $this->xpath($this->get(route('usuarios.index', ['vista' => 'inactivos']))->getContent());
        [, $panel] = $this->botonYPanel($xpath, 'Edu Editor');
        $boton = $xpath->query('.//form[contains(@action,"/estado")]//button', $panel)->item(0);

        $this->assertSame('Activar cuenta', trim($boton->textContent));
        $this->assertStringNotContainsString('boton-peligro', $boton->getAttribute('class'));
        $this->assertSame('¿Seguro que deseas activar a Edu Editor?', $boton->getAttribute('data-confirm'));

        $chip = $xpath->query('./td[@data-columna="Estado"]/span', $this->filaDe($xpath, 'Edu Editor'))->item(0);
        $this->assertSame('chip chip-estado chip-inactivo', $chip->getAttribute('class'));
        $this->assertSame('Inactivo', trim($chip->textContent));
    }

    public function test_un_error_de_rol_abre_la_fila_de_ese_usuario(): void
    {
        $this->poblar();
        $objetivo = User::where('name', 'Ana Editora')->firstOrFail();

        $this->from(route('usuarios.index'))
            ->patch(route('usuarios.rol', $objetivo), ['role_id' => 9999, 'form_usuario' => (string) $objetivo->id])
            ->assertRedirect(route('usuarios.index'))
            ->assertSessionHasErrors('role_id');

        $xpath = $this->xpath($this->get(route('usuarios.index'))->assertOk()->getContent());

        // Solo el botón de esa fila viene expandido; las demás no traen nada que las abra.
        $expandidos = $xpath->query('//tbody//button[@data-gestionar][@aria-expanded="true"]');
        $this->assertSame(1, $expandidos->length);
        [$boton, $panel] = $this->botonYPanel($xpath, 'Ana Editora');
        $this->assertSame('true', $boton->getAttribute('aria-expanded'));
        $this->assertFalse($panel->hasAttribute('hidden'));

        $error = $xpath->query('.//p[@id="error-rol-'.$objetivo->id.'"][@role="alert"]', $panel)->item(0);
        $this->assertNotNull($error);
        $this->assertNotSame('', trim($error->textContent));
        $select = $xpath->query('.//select[@id="role_id_'.$objetivo->id.'"]', $panel)->item(0);
        $this->assertSame('error-rol-'.$objetivo->id, $select->getAttribute('aria-describedby'));
        $this->assertSame('true', $select->getAttribute('aria-invalid'));

        // Sin aviso global duplicado ni errores en otras filas.
        $this->assertSame(0, $xpath->query('//div[@class="buscador-error"][@role="alert"]')->length);
        $this->assertSame(1, $xpath->query('//tbody//p[@role="alert"]')->length);
        $this->assertTrue($objetivo->fresh()->hasRole('editor'));
    }

    public function test_el_campo_oculto_no_decide_a_quien_se_aplica_el_cambio(): void
    {
        $this->poblar();
        $objetivo = User::where('name', 'Ana Editora')->firstOrFail();
        $otro = User::where('name', 'Edu Editor')->firstOrFail();

        // form_usuario apunta a otro usuario: el cambio se aplica al de la ruta.
        $this->from(route('usuarios.index'))
            ->patch(route('usuarios.rol', $objetivo), ['role_id' => $this->idRol('lector'), 'form_usuario' => (string) $otro->id])
            ->assertSessionHasNoErrors();

        $this->assertTrue($objetivo->fresh()->hasRole('lector'));
        $this->assertTrue($otro->fresh()->hasRole('editor'));

        $this->from(route('usuarios.index'))
            ->patch(route('usuarios.estado', $objetivo), ['is_active' => 0, 'form_usuario' => (string) $otro->id]);

        $this->assertFalse($objetivo->fresh()->is_active);
        $this->assertFalse($otro->fresh()->is_active);
        $this->assertSame(1, AuditLog::where('action', 'desactivar')->where('auditable_id', $objetivo->id)->count());
        $this->assertSame(0, AuditLog::where('action', 'desactivar')->where('auditable_id', $otro->id)->count());
    }

    public function test_un_campo_oculto_raro_no_rompe_la_vista_y_el_error_no_se_pierde(): void
    {
        $admin = $this->poblar();
        $objetivo = User::where('name', 'Ana Editora')->firstOrFail();

        foreach (['<script>', '1 OR 1=1', '', (string) $admin->id, '99999'] as $raro) {
            $this->from(route('usuarios.index'))
                ->patch(route('usuarios.rol', $objetivo), ['role_id' => 9999, 'form_usuario' => $raro])
                ->assertSessionHasErrors('role_id');

            $xpath = $this->xpath($this->get(route('usuarios.index'))->assertOk()->getContent());

            // Ninguna fila se entrega abierta (ni la propia, que no tiene panel)…
            $this->assertSame(0, $xpath->query('//tbody//button[@data-gestionar][@aria-expanded="true"]')->length, "form_usuario={$raro}");
            // …y el error aparece arriba, en un aviso global.
            $this->assertSame(1, $xpath->query('//div[@class="buscador-error"][@role="alert"]')->length, "form_usuario={$raro}");
        }

        $this->assertTrue($objetivo->fresh()->hasRole('editor'));
    }

    public function test_el_aviso_de_estado_usa_role_status(): void
    {
        $this->poblar();
        $objetivo = User::where('name', 'Ana Editora')->firstOrFail();

        $this->from(route('usuarios.index'))
            ->followingRedirects()
            ->patch(route('usuarios.rol', $objetivo), ['role_id' => $this->idRol('lector'), 'form_usuario' => (string) $objetivo->id])
            ->assertOk()
            ->assertSee('<p class="aviso-exito" role="status">Rol actualizado.</p>', false);
    }

    public function test_la_tabla_es_accesible_como_la_de_redaccion(): void
    {
        $this->poblar();

        $xpath = $this->xpath($this->get(route('usuarios.index', ['vista' => 'editor']))->getContent());
        $tabla = '//table[@class="panel-tabla"][@role="table"]';

        $this->assertSame('Usuarios (Editores): página 1 de 1', trim($xpath->query($tabla.'/caption[@class="sr-only"]')->item(0)->textContent));
        $this->assertSame(
            ['Usuario', 'Rol', 'Estado', 'Registro', 'Acciones'],
            array_map(fn ($th) => trim($th->textContent), iterator_to_array($xpath->query($tabla.'/thead//th[@scope="col"][@role="columnheader"]')))
        );
        $this->assertSame(2, $xpath->query($tabla.'/tbody//th[@scope="row"][@role="rowheader"]')->length);
        $this->assertSame(0, $xpath->query($tabla.'//tr[not(@role="row")]')->length);
        $this->assertSame(0, $xpath->query($tabla.'//td[not(@role="cell")]')->length);

        // Encabezado.
        $this->assertSame('Usuarios', trim($xpath->query('//h1[@id="usuarios-titulo"]')->item(0)->textContent));
    }

    public function test_estado_vacio_con_y_sin_filtros(): void
    {
        $this->poblar();

        $this->get(route('usuarios.index', ['buscar' => 'nadie']))
            ->assertSee('No se encontraron usuarios.')
            ->assertSee('Limpiar filtros');

        // Sin filtros nunca está vacío (el admin se ve a sí mismo); el texto existe en la vista.
        $this->assertStringContainsString('Aún no hay usuarios registrados.', file_get_contents(resource_path('views/usuarios/index.blade.php')));
    }

    public function test_la_vista_no_tiene_codigo_en_linea(): void
    {
        $this->poblar();

        $html = $this->get(route('usuarios.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('style=', $html);
        $this->assertDoesNotMatchRegularExpression('#<[^>]+\son[a-z]+=#i', $html);
        $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#i', $html);
    }
}
