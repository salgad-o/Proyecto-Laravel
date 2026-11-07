<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Comentario;
use App\Models\Noticia;
use App\Models\Role;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ->assertSeeInOrder(['Sistema', 'Creó', 'Usuario #'.$usuario->id]);
    }

    public function test_un_rol_inexistente_se_muestra_como_rol_numero(): void
    {
        $admin = User::factory()->admin()->create();
        $usuario = User::factory()->create();

        AuditLog::registrar('cambiar_rol', $usuario, ['role_id' => 999], ['role_id' => Role::where('name', 'editor')->value('id')]);

        $this->actingAs($admin)
            ->get(route('auditoria.index', ['accion' => 'cambiar_rol']))
            ->assertOk()
            ->assertSeeInOrder(['role_id', 'rol #999', '→', 'editor']);
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

    // ---------- NP-20 paso 5: vista de la auditoría ----------

    private function xpath(string $html): DOMXPath
    {
        $anterior = libxml_use_internal_errors(true);
        $documento = new DOMDocument;
        $documento->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return new DOMXPath($documento);
    }

    private function paginaAuditoria(array $query = []): string
    {
        return $this->actingAs(User::factory()->admin()->create())
            ->get(route('auditoria.index', $query))
            ->assertOk()
            ->getContent();
    }

    /** Registro creado a mano (sin pasar por un modelo Auditable). */
    private function registro(string $accion, Model $modelo, array $extra = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'user_id' => null,
            'action' => $accion,
            'auditable_type' => $modelo->getMorphClass(),
            'auditable_id' => $modelo->getKey(),
            'old_values' => null,
            'new_values' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony',
        ], $extra));
    }

    // ----- Deuda 1: tipo de elemento en español -----

    public function test_el_tipo_de_elemento_se_muestra_en_espanol(): void
    {
        $esperados = [
            Noticia::class => 'Noticia',
            Comentario::class => 'Comentario',
            User::class => 'Usuario',
            Role::class => 'Rol',
            'App\\Models\\Desconocido' => 'Desconocido',
        ];

        foreach ($esperados as $tipo => $texto) {
            $this->assertSame($texto, (new AuditLog(['auditable_type' => $tipo]))->tipoElemento());
        }
    }

    public function test_la_tabla_muestra_usuario_rol_noticia_y_comentario_con_su_numero(): void
    {
        $usuario = User::factory()->create();
        $rol = Role::where('name', 'editor')->firstOrFail();
        $noticia = Noticia::factory()->create();
        $comentario = Comentario::factory()->create();

        $this->registro('editar', $rol, ['old_values' => ['display_name' => 'A'], 'new_values' => ['display_name' => 'B']]);

        $xpath = $this->xpath($this->paginaAuditoria());
        $elementos = array_map(
            fn ($celda) => trim($celda->textContent),
            iterator_to_array($xpath->query('//td[@data-columna="Elemento"]'))
        );

        foreach (['Usuario #'.$usuario->id, 'Rol #'.$rol->id, 'Noticia #'.$noticia->id, 'Comentario #'.$comentario->id] as $texto) {
            $this->assertContains($texto, $elementos);
        }
        $this->assertNotContains('User #'.$usuario->id, $elementos);
    }

    // ----- Importancia -----

    public function test_toda_accion_tiene_un_nivel_de_importancia_explicito(): void
    {
        // Si alguien agrega una acción a ACCIONES y olvida clasificarla, este test falla.
        foreach (array_keys(AuditLog::ACCIONES) as $accion) {
            $this->assertArrayHasKey($accion, AuditLog::IMPORTANCIA, "La acción {$accion} no tiene nivel de importancia.");
        }

        foreach (AuditLog::IMPORTANCIA as $accion => $nivel) {
            $this->assertArrayHasKey($accion, AuditLog::ACCIONES, "{$accion} no es una acción conocida.");
            $this->assertArrayHasKey($nivel, AuditLog::NIVELES);
        }
    }

    public function test_los_niveles_de_cada_accion(): void
    {
        $esperado = [
            'alta' => ['cambiar_rol', 'desactivar', 'activar', 'cambiar_password', 'cerrar_sesiones', 'eliminar'],
            'media' => ['publicar', 'editar', 'cambiar_avatar'],
            'baja' => ['crear'],
        ];

        foreach ($esperado as $nivel => $acciones) {
            $this->assertEqualsCanonicalizing($acciones, AuditLog::accionesDeImportancia($nivel));

            foreach ($acciones as $accion) {
                $this->assertSame($nivel, (new AuditLog(['action' => $accion]))->importancia());
            }
        }
    }

    public function test_una_accion_no_listada_es_de_importancia_baja_y_no_rompe_la_vista(): void
    {
        $this->assertSame('baja', (new AuditLog(['action' => 'accion_futura']))->importancia());
        $this->assertSame('Baja', (new AuditLog(['action' => 'accion_futura']))->etiquetaImportancia());

        $this->registro('accion_futura', User::factory()->create());

        $xpath = $this->xpath($this->paginaAuditoria(['importancia' => 'baja']));
        $fila = $xpath->query('//tbody/tr[th[normalize-space()="accion_futura"]]')->item(0);

        $this->assertNotNull($fila, 'La acción sin clasificar aparece en el filtro "baja".');
        $this->assertSame('chip chip-importancia chip-baja', $xpath->query('.//td[@data-columna="Importancia"]/span', $fila)->item(0)->getAttribute('class'));
    }

    public function test_cada_fila_muestra_el_chip_de_su_importancia(): void
    {
        $usuario = User::factory()->create();
        $this->registro('cambiar_rol', $usuario, ['old_values' => ['role_id' => 1], 'new_values' => ['role_id' => 2]]);
        $this->registro('editar', $usuario, ['old_values' => ['name' => 'A'], 'new_values' => ['name' => 'B']]);

        $xpath = $this->xpath($this->paginaAuditoria());

        foreach (['Cambió rol' => ['chip-alta', 'Alta'], 'Editó' => ['chip-media', 'Media'], 'Creó' => ['chip-baja', 'Baja']] as $accion => [$clase, $texto]) {
            $chip = $xpath->query('//tbody/tr[th[normalize-space()="'.$accion.'"]]/td[@data-columna="Importancia"]/span')->item(0);
            $this->assertNotNull($chip, "Sin chip para {$accion}");
            $this->assertSame('chip chip-importancia '.$clase, $chip->getAttribute('class'));
            $this->assertSame($texto, trim($chip->textContent));
        }
    }

    public function test_filtrar_por_importancia_alta_muestra_solo_acciones_de_alta(): void
    {
        $usuario = User::factory()->create();
        $this->registro('cambiar_rol', $usuario);
        $this->registro('desactivar', $usuario);
        $this->registro('editar', $usuario);
        $noticia = Noticia::factory()->create();
        $this->registro('eliminar', $noticia);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('auditoria.index', ['importancia' => 'alta']))
            ->assertOk()
            ->assertSee('<option value="alta" selected>Alta</option>', false)
            ->assertViewHas('registros', fn ($registros) => $registros->total() === 3
                && $registros->every(fn ($registro) => $registro->importancia() === 'alta'));
    }

    public function test_una_importancia_invalida_se_rechaza(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('auditoria.index', ['importancia' => 'critica']))
            ->assertSessionHasErrors('importancia');
    }

    public function test_los_filtros_vacios_equivalen_a_sin_filtro(): void
    {
        User::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('auditoria.index', ['usuario' => '', 'accion' => '', 'importancia' => '']))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertViewHas('filtros', [])
            ->assertViewHas('registros', fn ($registros) => $registros->total() === 2);
    }

    public function test_una_pagina_fuera_de_rango_responde_404(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('auditoria.index', ['page' => 999]))
            ->assertNotFound();
    }

    // ----- Detalles, IP y navegador -----

    public function test_la_ip_y_el_navegador_solo_aparecen_dentro_de_un_details(): void
    {
        $this->registro('cambiar_password', User::factory()->create(), [
            'ip_address' => '203.0.113.77',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
        ]);

        $html = $this->paginaAuditoria(['accion' => 'cambiar_password']);
        $xpath = $this->xpath($html);

        // No hay columna IP.
        $this->assertNotContains('IP', array_map(fn ($th) => trim($th->textContent), iterator_to_array($xpath->query('//thead//th'))));

        // Fuera de los <details> no hay ni IP ni navegador.
        $sinPlegables = (string) preg_replace('#<details\b.*?</details>#s', '', $html);
        $this->assertStringNotContainsString('203.0.113.77', $sinPlegables);
        $this->assertStringNotContainsString('Chrome en Windows', $sinPlegables);

        // Dentro, sí.
        $plegable = $xpath->query('//td[@data-columna="Detalles"]/details')->item(0);
        $this->assertNotNull($plegable);
        $this->assertSame('Ver origen', trim($xpath->query('./summary', $plegable)->item(0)->textContent));
        $this->assertStringContainsString('203.0.113.77', $plegable->textContent);
        $this->assertStringContainsString('Chrome en Windows', $plegable->textContent);
    }

    public function test_el_user_agent_crudo_no_aparece_en_la_pagina(): void
    {
        $registro = $this->registro('cerrar_sesiones', User::factory()->create(), [
            'user_agent' => '<script>alert("ua")</script>',
        ]);

        $html = $this->paginaAuditoria(['accion' => 'cerrar_sesiones']);

        // Ni crudo ni escapado: solo se muestra el navegador interpretado.
        $this->assertStringNotContainsString('<script>alert("ua")</script>', $html);
        $this->assertStringNotContainsString('alert(', $html);
        $this->assertStringNotContainsString('Agente', $html);
        $this->assertStringContainsString('<dd>Navegador no reconocido</dd>', $html);

        // El dato sigue guardado en la base de datos.
        $this->assertSame('<script>alert("ua")</script>', $registro->fresh()->user_agent);
    }

    public function test_el_navegador_se_muestra_interpretado(): void
    {
        $casos = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36' => 'Chrome en Windows',
            // Navegador reconocido, sistema no: solo el navegador.
            'Mozilla/5.0 (PlayStation; Unknown) Firefox/120.0' => 'Firefox',
            // Nada reconocido o vacío: sin "en Otro sistema".
            'curl/8.4.0' => 'Navegador no reconocido',
            '' => 'Navegador no reconocido',
        ];

        foreach ($casos as $agente => $esperado) {
            $this->assertSame($esperado, (new AuditLog(['user_agent' => $agente]))->navegadorLegible(), $agente);
        }

        $this->registro('cerrar_sesiones', User::factory()->create(), ['user_agent' => 'curl/8.4.0']);
        $html = $this->paginaAuditoria(['accion' => 'cerrar_sesiones']);

        $this->assertStringContainsString('<dd>Navegador no reconocido</dd>', $html);
        $this->assertStringNotContainsString('Otro sistema', $html);
        $this->assertStringNotContainsString('Otro navegador', $html);
    }

    public function test_texto_cuando_un_registro_no_guarda_valores(): void
    {
        $usuario = User::factory()->create();
        $this->registro('cambiar_password', $usuario);
        $this->registro('cerrar_sesiones', $usuario);

        $xpath = $this->xpath($this->paginaAuditoria());
        $texto = fn (string $accion) => trim($xpath->query('//tbody/tr[th[normalize-space()="'.$accion.'"]]/td[@data-columna="Detalles"]/span[@class="auditoria-sin-cambios"]')->item(0)?->textContent ?? '');

        $this->assertSame('Por seguridad, la contraseña nunca se registra.', $texto('Cambió contraseña'));
        $this->assertSame('Sin datos adicionales.', $texto('Cerró otras sesiones'));
    }

    public function test_pocos_cambios_cortos_se_ven_enteros_y_muchos_se_pliegan(): void
    {
        $admin = User::factory()->admin()->create();
        $usuario = User::factory()->lector()->create();

        // Un cambio corto: lista visible + "Ver origen".
        $this->actingAs($admin);
        $usuario->role_id = Role::where('name', 'editor')->value('id');
        $usuario->save();

        // Crear una noticia registra muchos campos: resumen + "Ver N cambios".
        $this->actingAs($admin)->post(route('noticias.store'), $this->datosNoticia([
            'body' => str_repeat('Texto largo del cuerpo. ', 20),
        ]));
        $creacion = AuditLog::where('action', 'crear')->where('auditable_type', Noticia::class)->firstOrFail();
        $total = count($creacion->cambios());

        $xpath = $this->xpath($this->actingAs($admin)->get(route('auditoria.index'))->assertOk()->getContent());

        $rol = $xpath->query('//tbody/tr[th[normalize-space()="Cambió rol"]]/td[@data-columna="Detalles"]')->item(0);
        $this->assertStringContainsString('lector → editor', preg_replace('/\s+/', ' ', $xpath->query('./ul', $rol)->item(0)->textContent));
        $this->assertSame('Ver origen', trim($xpath->query('./details/summary', $rol)->item(0)->textContent));

        $crear = $xpath->query('//tbody/tr[th[normalize-space()="Creó"]][td[normalize-space()="Noticia #'.$creacion->auditable_id.'"]]/td[@data-columna="Detalles"]')->item(0);
        $this->assertNotNull($crear);
        $this->assertGreaterThan(2, $total);
        $this->assertStringContainsString('y '.($total - 1).' más', $xpath->query('./p[@class="auditoria-resumen"]', $crear)->item(0)->textContent);
        $this->assertSame('Ver '.$total.' cambios', trim($xpath->query('./details/summary', $crear)->item(0)->textContent));
        $this->assertSame($total, $xpath->query('./details/ul/li', $crear)->length);
    }

    // ----- Tabla accesible, código en línea y consultas -----

    public function test_la_tabla_es_accesible_como_la_de_redaccion(): void
    {
        User::factory()->count(2)->create();

        $xpath = $this->xpath($this->paginaAuditoria());
        $tabla = '//table[contains(@class,"panel-tabla")][@role="table"]';

        $this->assertSame('Auditoría: registros, página 1 de 1', trim($xpath->query($tabla.'/caption[@class="sr-only"]')->item(0)->textContent));
        $this->assertSame(
            ['Importancia', 'Fecha', 'Usuario', 'Acción', 'Elemento', 'Detalles'],
            array_map(fn ($th) => trim($th->textContent), iterator_to_array($xpath->query($tabla.'/thead//th[@scope="col"][@role="columnheader"]')))
        );
        $this->assertSame(0, $xpath->query($tabla.'//tr[not(@role="row")]')->length);
        $this->assertSame(0, $xpath->query($tabla.'//td[not(@role="cell")]')->length);
        // Un encabezado de fila (la acción) por cada fila: 2 usuarios + el admin de la petición.
        $filas = $xpath->query($tabla.'/tbody/tr')->length;
        $this->assertSame(3, $filas);
        $this->assertSame($filas, $xpath->query($tabla.'/tbody//th[@scope="row"][@role="rowheader"]')->length);

        $time = $xpath->query('//td[@data-columna="Fecha"]/time')->item(0);
        $this->assertNotSame('', $time->getAttribute('datetime'));
        $this->assertNotSame('', $time->getAttribute('title'));
    }

    public function test_el_encabezado_y_el_aviso_de_solo_lectura(): void
    {
        $html = $this->paginaAuditoria();

        $this->assertStringContainsString('PANEL DE GESTIÓN', $html);
        $this->assertStringContainsString('<h1 id="auditoria-titulo" class="pagina-titulo">Auditoría</h1>', $html);
        $this->assertStringContainsString('cambios de contraseña, cierres de otras sesiones y cambios de foto de perfil', $html);
        $this->assertStringContainsString('Solo lectura: los registros no se pueden modificar ni eliminar.', $html);

        foreach (['filtro-importancia', 'usuario', 'accion'] as $id) {
            $this->assertMatchesRegularExpression('#<label for="'.$id.'">[^<]+</label>#', $html);
        }
    }

    public function test_estado_vacio_con_y_sin_filtros(): void
    {
        // Crear un usuario genera su propio registro: el admin se crea sin eventos para
        // que la tabla esté realmente vacía.
        $admin = User::withoutEvents(fn () => User::factory()->admin()->create());
        $this->actingAs($admin)
            ->get(route('auditoria.index'))
            ->assertOk()
            ->assertSee('Aún no hay registros de auditoría.')
            ->assertDontSee('<table', false);

        User::factory()->create();
        $html = $this->paginaAuditoria(['accion' => 'eliminar']);
        $this->assertStringContainsString('No hay registros que coincidan con el filtro.', $html);
        $this->assertStringContainsString('Limpiar filtros', $html);
    }

    public function test_la_vista_no_tiene_codigo_en_linea(): void
    {
        $this->registro('cerrar_sesiones', User::factory()->create(), ['user_agent' => 'x onload=alert(1)']);

        $html = $this->paginaAuditoria();

        $this->assertStringNotContainsString('style=', $html);
        $this->assertDoesNotMatchRegularExpression('#<[^>]+\son[a-z]+=#i', $html);
        $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#i', $html);
    }

    public function test_el_numero_de_consultas_no_crece_con_las_filas(): void
    {
        $admin = User::factory()->admin()->create();

        $crearConFoto = function (int $cuantos): void {
            foreach (range(1, $cuantos) as $i) {
                $usuario = User::factory()->editor()->create();
                $usuario->forceFill(['avatar_path' => str_repeat('a', 39).$i.'.webp'])->saveQuietly();
                $this->actingAs($usuario)->post(route('noticias.store'), $this->datosNoticia(['title' => "Noticia {$i}"]));
            }
        };

        $contar = function () use ($admin): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin->fresh())->get(route('auditoria.index'))->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $crearConFoto(2);
        $conPocas = $contar();

        $crearConFoto(6);
        $conMuchas = $contar();

        $this->assertSame($conPocas, $conMuchas);
    }
}
