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
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * NP-21 paso 2: tarjetas de cifras y panel lateral de actividad en /dashboard.
 * Datos fijos: nombres, correos y títulos que se comparan no vienen de Faker.
 */
class DashboardEstadisticasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $eva;

    private User $otro;

    /**
     * Escenario fijo:
     * - Usuarios: 1 admin, 2 editores (Eva y Otro), 2 lectores → 5.
     * - Noticias: Eva 2 publicadas + 1 borrador; Otro 1 publicada + 3 borradores;
     *   admin 1 publicada → 4 publicadas y 4 borradores en total.
     * - Comentarios: 2 en noticias de Eva y 3 en una de Otro → 5.
     * - Auditoría: todo lo anterior se lleva a hace 10 días (no cuenta).
     */
    private function escenario(): void
    {
        $this->admin = User::factory()->admin()->create(['name' => 'Ana Admin', 'email' => 'ana@nexus.test']);
        $this->eva = User::factory()->editor()->create(['name' => 'Eva Editora', 'email' => 'eva@nexus.test']);
        $this->otro = User::factory()->editor()->create(['name' => 'Otro Editor', 'email' => 'otro@nexus.test']);
        $lola = User::factory()->lector()->create(['name' => 'Lola Lectora', 'email' => 'lola@nexus.test']);
        $leo = User::factory()->lector()->create(['name' => 'Leo Lector', 'email' => 'leo@nexus.test']);

        $deEva = Noticia::factory()->count(2)->for($this->eva)->create(['status' => 'publicada', 'published_at' => now()]);
        Noticia::factory()->for($this->eva)->borrador()->create(['title' => 'Borrador de Eva']);
        $deOtro = Noticia::factory()->for($this->otro)->create(['status' => 'publicada', 'published_at' => now()]);
        Noticia::factory()->count(3)->for($this->otro)->borrador()->create();
        Noticia::factory()->for($this->admin)->create(['status' => 'publicada', 'published_at' => now()]);

        Comentario::factory()->for($lola)->for($deEva[0])->create(['body' => 'Uno']);
        Comentario::factory()->for($leo)->for($deEva[1])->create(['body' => 'Dos']);
        Comentario::factory()->count(3)->for($lola)->for($deOtro)->create(['body' => 'En la de otro']);

        // Lo creado arriba también quedó auditado: se lleva fuera de la ventana de 7 días.
        // (Query Builder: el modelo AuditLog no permite modificar registros, a propósito.)
        DB::table('audit_logs')->update(['created_at' => now()->subDays(10)]);
    }

    private function registro(string $accion, User $sobre, ?User $autor = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => $autor?->id,
            'action' => $accion,
            'auditable_type' => $sobre->getMorphClass(),
            'auditable_id' => $sobre->id,
            'ip_address' => '203.0.113.99',
            'user_agent' => 'AgenteSecretoDePrueba/1.0',
        ]);
    }

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
     * Cifras de la portada: clave => [etiqueta, número tal como se muestra].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function kpis(string $html): array
    {
        $xpath = $this->xpath($html);
        $kpis = [];

        foreach ($xpath->query('//dl[@class="escritorio-kpis"]/div[@data-kpi]') as $tarjeta) {
            $kpis[$tarjeta->getAttribute('data-kpi')] = [
                trim($xpath->query('./dt', $tarjeta)->item(0)->textContent),
                trim($xpath->query('./dd[@class="escritorio-kpi-valor"]', $tarjeta)->item(0)->textContent),
            ];
        }

        return $kpis;
    }

    /** Texto de cada entrada del panel lateral. */
    private function lateral(string $html): array
    {
        $xpath = $this->xpath($html);

        return array_map(
            fn ($li) => trim((string) preg_replace('/\s+/u', ' ', $li->textContent)),
            iterator_to_array($xpath->query('//aside[@class="escritorio-lateral"]//ol/li'))
        );
    }

    private function consultas(User $usuario): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($usuario)->get(route('dashboard'))->assertOk();
        $consultas = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $consultas;
    }

    // ---------- Cifras del admin ----------

    public function test_el_admin_ve_las_cinco_cifras_correctas(): void
    {
        $this->escenario();

        // Dentro de los 7 días: 3 ahora y 1 hace 6 días. Fuera: 1 hace 8 días.
        $this->registro('cambiar_rol', $this->eva, $this->admin);
        $this->registro('editar', $this->eva, $this->eva);
        $this->registro('crear', $this->eva);
        $this->travel(-6)->days();
        $this->registro('desactivar', $this->otro, $this->admin);
        $this->travelBack();
        $this->travel(-8)->days();
        $this->registro('activar', $this->otro, $this->admin);
        $this->travelBack();

        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame([
            'publicadas' => ['Publicadas', '4'],
            'borradores' => ['Borradores', '4'],
            'comentarios' => ['Comentarios', '5'],
            'usuarios' => ['Usuarios', '5'],
            'acciones' => ['Acciones en 7 días', '4'],
        ], $this->kpis($html));

        // Desglose de usuarios con enlaces a la pestaña de cada rol.
        $xpath = $this->xpath($html);
        $desglose = $xpath->query('//div[@data-kpi="usuarios"]/dd[@class="escritorio-kpi-desglose"]/a');
        // Ajuste 2c: plural con trans_choice (lang/es/panel.php).
        $this->assertSame(['1 administrador', '2 editores', '2 lectores'], array_map(fn ($a) => trim($a->textContent), iterator_to_array($desglose)));
        $this->assertStringNotContainsString('Admins', $html);
        foreach (['admin', 'editor', 'lector'] as $i => $vista) {
            $this->assertSame(route('usuarios.index', ['vista' => $vista]), $desglose->item($i)->getAttribute('href'));
        }
    }

    public function test_los_registros_de_mas_de_siete_dias_no_cuentan(): void
    {
        $this->escenario();

        $this->travelTo(now()->subDays(7)->subMinute());
        $this->registro('cambiar_rol', $this->eva, $this->admin);
        $this->travelBack();
        $this->travel(-30)->days();
        $this->registro('desactivar', $this->eva, $this->admin);
        $this->travelBack();

        $kpis = $this->kpis($this->actingAs($this->admin)->get(route('dashboard'))->getContent());

        $this->assertSame(['Acciones en 7 días', '0'], $kpis['acciones']);
    }

    public function test_las_cifras_usan_separador_de_miles_en_espanol(): void
    {
        $this->escenario();

        $filas = array_fill(0, 1234, [
            'user_id' => null,
            'action' => 'crear',
            'auditable_type' => User::class,
            'auditable_id' => $this->eva->id,
            'created_at' => now(),
        ]);
        DB::table('audit_logs')->insert($filas);

        $kpis = $this->kpis($this->actingAs($this->admin)->get(route('dashboard'))->getContent());

        $this->assertSame('1.234', $kpis['acciones'][1]);
    }

    public function test_usuarios_por_rol_sale_de_una_sola_consulta_agrupada(): void
    {
        $this->escenario();

        $consultas = $this->consultas($this->admin);

        $agrupadas = array_filter($consultas, fn ($sql) => preg_match('/group by ["`]role_id["`]/i', $sql) === 1);
        $this->assertCount(1, $agrupadas);

        // Y publicadas + borradores, en una sola consulta agrupada por estado.
        $porEstado = array_filter($consultas, fn ($sql) => preg_match('/from ["`]noticias["`] group by ["`]status["`]/i', $sql) === 1);
        $this->assertCount(1, $porEstado);
    }

    // ---------- Cifras del editor ----------

    public function test_el_editor_ve_solo_sus_tres_cifras(): void
    {
        $this->escenario();

        $kpis = $this->kpis($this->actingAs($this->eva)->get(route('dashboard'))->assertOk()->getContent());

        // Lo de Otro y del admin no suma; los 3 comentarios en la noticia de Otro tampoco.
        $this->assertSame([
            'publicadas' => ['Mis publicadas', '2'],
            'borradores' => ['Mis borradores', '1'],
            'comentarios' => ['Comentarios en mis noticias', '2'],
        ], $kpis);
    }

    public function test_el_editor_no_recibe_totales_globales(): void
    {
        $this->escenario();

        $respuesta = $this->actingAs($this->eva)->get(route('dashboard'))->assertOk();

        // Ni en la vista ni en las variables que recibe la vista.
        $respuesta->assertViewHas('kpis', fn (array $kpis) => array_column($kpis, 'clave') === ['publicadas', 'borradores', 'comentarios']);
        $html = $respuesta->getContent();
        $this->assertStringNotContainsString('data-kpi="usuarios"', $html);
        $this->assertStringNotContainsString('data-kpi="acciones"', $html);
        $this->assertStringNotContainsString('Acciones en 7 días', $html);
    }

    public function test_para_el_editor_no_se_ejecutan_los_conteos_globales(): void
    {
        $this->escenario();

        $consultas = $this->consultas($this->eva);

        foreach ($consultas as $sql) {
            // Ni el conteo agrupado de usuarios…
            $this->assertDoesNotMatchRegularExpression('/group by ["`]role_id["`]/i', $sql, $sql);
            // …ni el conteo global de auditoría; y TODA consulta a audit_logs filtra por su user_id.
            if (preg_match('/["`]audit_logs["`]/i', $sql)) {
                $this->assertMatchesRegularExpression('/["`]user_id["`] = \?/i', $sql, $sql);
                $this->assertDoesNotMatchRegularExpression('/count\(\*\)/i', $sql, $sql);
            }
            // Las noticias agrupadas por estado, solo las suyas.
            if (preg_match('/group by ["`]status["`]/i', $sql)) {
                $this->assertMatchesRegularExpression('/["`]user_id["`] = \?/i', $sql, $sql);
            }
        }
    }

    // ---------- Panel lateral ----------

    public function test_el_panel_lateral_del_admin_muestra_todos_los_niveles_sin_ip_ni_navegador(): void
    {
        $this->escenario();

        $this->registro('crear', $this->eva);                       // baja
        $this->registro('editar', $this->eva, $this->eva);           // media
        $this->registro('cambiar_rol', $this->eva, $this->admin);    // alta

        $html = $this->actingAs($this->admin)->get(route('dashboard'))->getContent();
        $xpath = $this->xpath($html);

        $this->assertSame('Actividad reciente', trim($xpath->query('//aside[@aria-labelledby="lateral-titulo"]/h2')->item(0)->textContent));

        $entradas = $this->lateral($html);
        $this->assertStringStartsWith('Alta Cambió rol Usuario #'.$this->eva->id, $entradas[0]);
        $this->assertStringContainsString('Ana Admin', $entradas[0]);
        $this->assertStringStartsWith('Media Editó Usuario #'.$this->eva->id, $entradas[1]);
        $this->assertStringContainsString('Eva Editora', $entradas[1]);
        $this->assertStringStartsWith('Baja Creó Usuario #'.$this->eva->id, $entradas[2]);
        $this->assertStringContainsString('Sistema', $entradas[2]);

        $chips = array_map(fn ($c) => $c->getAttribute('class'), iterator_to_array($xpath->query('//aside//ol/li//span[contains(@class,"chip-importancia")]')));
        $this->assertSame('chip chip-importancia chip-alta', $chips[0]);
        $this->assertSame('chip chip-importancia chip-media', $chips[1]);
        $this->assertSame('chip chip-importancia chip-baja', $chips[2]);

        // Minimización: nunca la IP ni el user agent.
        $this->assertStringNotContainsString('203.0.113.99', $html);
        $this->assertStringNotContainsString('AgenteSecretoDePrueba', $html);
        $this->assertStringContainsString('href="'.route('auditoria.index').'"', $html);
    }

    public function test_el_panel_lateral_muestra_como_maximo_ocho(): void
    {
        $this->escenario();

        $this->assertGreaterThan(8, AuditLog::count());
        $this->assertCount(8, $this->lateral($this->actingAs($this->admin)->get(route('dashboard'))->getContent()));
    }

    public function test_el_panel_lateral_del_editor_solo_muestra_lo_suyo(): void
    {
        $this->escenario();

        $this->registro('editar', $this->eva, $this->eva);
        $this->registro('cambiar_avatar', $this->otro, $this->otro);
        $this->registro('cambiar_rol', $this->otro, $this->admin);

        $html = $this->actingAs($this->eva)->get(route('dashboard'))->getContent();
        $xpath = $this->xpath($html);

        $this->assertSame('Tu actividad', trim($xpath->query('//aside[@aria-labelledby="lateral-titulo"]/h2')->item(0)->textContent));

        $entradas = $this->lateral($html);
        $esperadas = AuditLog::where('user_id', $this->eva->id)->count();
        $this->assertCount(min(8, $esperadas), $entradas);
        $this->assertStringStartsWith('Media Editó Usuario #'.$this->eva->id, $entradas[0]);
        foreach ($entradas as $entrada) {
            $this->assertStringNotContainsString('Cambió foto de perfil', $entrada);
            $this->assertStringNotContainsString('Cambió rol', $entrada);
            // Sin "quién": es su propia actividad.
            $this->assertStringNotContainsString('Eva Editora', $entrada);
        }

        $this->assertStringNotContainsString('203.0.113.99', $html);
        $this->assertStringNotContainsString('AgenteSecretoDePrueba', $html);
        // Ajuste 2b: el editor no tiene a dónde ampliar (/perfil/actividad muestra otra cosa).
        $this->assertStringNotContainsString('href="'.route('perfil.actividad').'"', $html);
        $this->assertStringNotContainsString('Ver toda tu actividad', $html);
        $this->assertStringNotContainsString(route('auditoria.index'), $html);
    }

    public function test_la_estructura_es_accesible(): void
    {
        $this->escenario();

        $xpath = $this->xpath($this->actingAs($this->admin)->get(route('dashboard'))->getContent());

        // <dl> con dt/dd por tarjeta, y el <aside> DESPUÉS de la columna principal.
        $this->assertSame(5, $xpath->query('//dl[@class="escritorio-kpis"]/div/dt')->length);
        $this->assertSame(1, $xpath->query('//div[@class="escritorio-layout"]/div[contains(@class,"escritorio-principal")]/following-sibling::aside[@class="escritorio-lateral"]')->length);
        $this->assertSame(1, $xpath->query('//aside[@class="escritorio-lateral"][@aria-labelledby="lateral-titulo"]')->length);
    }

    // ---------- Ajuste 2b: AuditLogPolicy::viewOwn ----------

    public function test_view_own_lo_tienen_admin_y_editor_pero_no_el_lector(): void
    {
        $this->assertTrue(User::factory()->admin()->create()->can('viewOwn', AuditLog::class));
        $this->assertTrue(User::factory()->editor()->create()->can('viewOwn', AuditLog::class));
        $this->assertFalse(User::factory()->lector()->create()->can('viewOwn', AuditLog::class));
    }

    public function test_sin_view_own_el_editor_no_ve_su_actividad_ni_se_consulta_audit_logs(): void
    {
        $this->escenario();
        $this->registro('editar', $this->eva, $this->eva);

        // Simula que la Policy le niega viewOwn (el resto de habilidades sigue igual).
        Gate::before(fn (User $usuario, string $habilidad) => $habilidad === 'viewOwn' ? false : null);

        $consultas = $this->consultas($this->eva);
        foreach ($consultas as $sql) {
            $this->assertDoesNotMatchRegularExpression('/["`]audit_logs["`]/i', $sql, $sql);
        }

        $html = $this->actingAs($this->eva)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Tu actividad', $html);
        $this->assertStringNotContainsString('<aside class="escritorio-lateral"', $html);
        // La columna principal ocupa todo el ancho y las cifras del editor siguen ahí.
        $this->assertStringContainsString('class="escritorio-layout escritorio-layout-sin-lateral"', $html);
        $this->assertSame(['publicadas', 'borradores', 'comentarios'], array_keys($this->kpis($html)));
    }

    public function test_con_view_own_el_editor_si_ve_su_panel(): void
    {
        $this->escenario();
        $this->registro('editar', $this->eva, $this->eva);

        $html = $this->actingAs($this->eva)->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('<aside class="escritorio-lateral"', $html);
        $this->assertStringContainsString('class="escritorio-layout"', $html);
        $this->assertStringNotContainsString('escritorio-layout-sin-lateral', $html);
    }

    // ---------- Ajuste 2c: plurales y franja de cifras ----------

    public function test_el_desglose_usa_singular_y_plural_en_espanol(): void
    {
        // 2 administradores, 1 editor y 0 lectores.
        $admin = User::factory()->admin()->create(['email' => 'a1@nexus.test']);
        User::factory()->admin()->create(['email' => 'a2@nexus.test']);
        User::factory()->editor()->create(['email' => 'e1@nexus.test']);

        $xpath = $this->xpath($this->actingAs($admin)->get(route('dashboard'))->getContent());
        $textos = array_map(fn ($a) => trim($a->textContent), iterator_to_array($xpath->query('//div[@data-kpi="usuarios"]/dd[@class="escritorio-kpi-desglose"]/a')));

        $this->assertSame(['2 administradores', '1 editor', '0 lectores'], $textos);
    }

    public function test_el_plural_usa_separador_de_miles(): void
    {
        $this->assertSame('1.234 lectores', trans_choice('panel.roles.lector', 1234, ['count' => number_format(1234, 0, ',', '.')]));
        $this->assertSame('1 lector', trans_choice('panel.roles.lector', 1, ['count' => '1']));
    }

    public function test_la_franja_de_cifras_va_antes_del_layout_y_fuera_del_aside(): void
    {
        $this->escenario();

        foreach ([$this->admin, $this->eva] as $usuario) {
            $xpath = $this->xpath($this->actingAs($usuario)->get(route('dashboard'))->getContent());

            // Hermano anterior del layout, dentro de .escritorio-raiz.
            $this->assertSame(1, $xpath->query('//div[@class="escritorio-raiz"]/dl[@class="escritorio-kpis"][following-sibling::div[contains(@class,"escritorio-layout")]]')->length);
            // Ni dentro de la columna principal ni del panel lateral.
            $this->assertSame(0, $xpath->query('//div[contains(@class,"escritorio-principal")]//dl')->length);
            $this->assertSame(0, $xpath->query('//aside//dl')->length);
        }
    }

    // ---------- Ajuste 2d: "Tu actividad" limitada al rol actual ----------

    /** Registro con elemento y autor explícitos (cualquier modelo auditable). */
    private function registroSobre(string $accion, Model $elemento, User $autor): AuditLog
    {
        return AuditLog::create([
            'user_id' => $autor->id,
            'action' => $accion,
            'auditable_type' => $elemento->getMorphClass(),
            'auditable_id' => $elemento->getKey(),
        ]);
    }

    /**
     * Eva fue admin y hoy es editora: tiene registros de administración hechos por
     * ella, además de los de su trabajo como editora.
     *
     * @return array{noticia: Noticia, comentario: Comentario}
     */
    private function exadminConHistorial(): array
    {
        $this->escenario();
        DB::table('audit_logs')->delete();

        $noticia = Noticia::factory()->for($this->eva)->create(['title' => 'Noticia de Eva']);
        $comentario = Comentario::factory()->for($this->eva)->for($noticia)->create(['body' => 'Comentario de Eva']);
        $rol = Role::where('name', 'lector')->firstOrFail();
        DB::table('audit_logs')->delete();

        // De cuando era admin: NO debe verlos ya.
        $this->registroSobre('desactivar', $this->otro, $this->eva);
        $this->registroSobre('cambiar_rol', $this->otro, $this->eva);
        $this->registroSobre('activar', $this->otro, $this->eva);
        $this->registroSobre('editar', $this->otro, $this->eva);   // editó OTRA cuenta
        $this->registroSobre('editar', $rol, $this->eva);          // editó un rol

        // De su trabajo y su perfil: SÍ debe verlos.
        $this->registroSobre('publicar', $noticia, $this->eva);
        $this->registroSobre('editar', $comentario, $this->eva);
        $this->registroSobre('cambiar_avatar', $this->eva, $this->eva);
        $this->registroSobre('editar', $this->eva, $this->eva);    // su nombre o biografía

        return ['noticia' => $noticia, 'comentario' => $comentario];
    }

    public function test_un_exadmin_no_ve_sus_acciones_de_administracion(): void
    {
        $this->exadminConHistorial();

        $html = $this->actingAs($this->eva)->get(route('dashboard'))->getContent();
        $entradas = implode("\n", $this->lateral($html));

        foreach (['Desactivó usuario', 'Cambió rol', 'Activó usuario', 'Usuario #'.$this->otro->id, 'Rol #'] as $texto) {
            $this->assertStringNotContainsString($texto, $entradas, $texto);
        }
    }

    public function test_el_editor_si_ve_sus_acciones_de_noticias_comentarios_y_perfil(): void
    {
        ['noticia' => $noticia, 'comentario' => $comentario] = $this->exadminConHistorial();

        $entradas = $this->lateral($this->actingAs($this->eva)->get(route('dashboard'))->getContent());

        $this->assertCount(4, $entradas);
        $this->assertStringContainsString('Editó Usuario #'.$this->eva->id, $entradas[0]);
        $this->assertStringContainsString('Cambió foto de perfil Usuario #'.$this->eva->id, $entradas[1]);
        $this->assertStringContainsString('Editó Comentario #'.$comentario->id, $entradas[2]);
        $this->assertStringContainsString('Publicó Noticia #'.$noticia->id, $entradas[3]);
    }

    public function test_el_admin_sigue_viendo_toda_la_actividad(): void
    {
        $this->exadminConHistorial();

        // 9 registros: se ven los 8 más recientes, también los de administración,
        // porque la rama viewAny no se filtra.
        $lista = $this->lateral($this->actingAs($this->admin)->get(route('dashboard'))->getContent());
        $this->assertCount(8, $lista);
        $entradas = implode("\n", $lista);
        $this->assertStringContainsString('Editó Usuario #'.$this->eva->id, $entradas);
        $this->assertStringContainsString('Editó Rol #', $entradas);
        $this->assertStringContainsString('Activó usuario Usuario #'.$this->otro->id, $entradas);
        $this->assertStringContainsString('Cambió rol Usuario #'.$this->otro->id, $entradas);
    }

    public function test_el_alcance_propio_es_una_lista_cerrada_de_acciones_conocidas(): void
    {
        foreach (AuditLog::ACCIONES_PROPIAS as $tipo => $acciones) {
            $this->assertTrue(class_exists($tipo), $tipo);
            foreach ($acciones as $accion) {
                $this->assertArrayHasKey($accion, AuditLog::ACCIONES, $accion);
            }
        }

        // Nada de lo que solo hace la administración.
        $todas = array_merge(...array_values(AuditLog::ACCIONES_PROPIAS));
        foreach (['cambiar_rol', 'activar', 'desactivar'] as $accion) {
            $this->assertNotContains($accion, $todas);
        }
        $this->assertArrayNotHasKey(Role::class, AuditLog::ACCIONES_PROPIAS);
    }
}
