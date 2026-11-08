<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ContactConsent;
use App\Models\ReceivedEmail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NP-46 paso 7: bandeja "Correos recibidos" del panel (tabla + detalle), solo admin.
 * El contenido de los correos es ENTRADA NO CONFIABLE: todo sale escapado.
 */
class CorreosRecibidosPanelTest extends TestCase
{
    use RefreshDatabase;

    private const VISTAS = ['correos/index.blade.php', 'correos/show.blade.php'];

    private int $secuencia = 0;

    /**
     * Crea un correo recibido. El modelo no admite asignación masiva: aquí se
     * desbloquea solo para preparar los datos del test.
     */
    private function correo(array $datos = []): ReceivedEmail
    {
        $this->secuencia++;

        return ReceivedEmail::unguarded(fn () => ReceivedEmail::create(array_merge([
            'message_id' => "correo-{$this->secuencia}@nexus.test",
            'from_email' => "remitente{$this->secuencia}@nexus.test",
            'from_name' => "Remitente {$this->secuencia}",
            'subject' => "Asunto {$this->secuencia}",
            'body' => "Cuerpo {$this->secuencia}",
            'received_at' => CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC')->addMinutes($this->secuencia),
            'has_attachments' => false,
            'is_read' => false,
        ], $datos)));
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function comoAdmin(): static
    {
        return $this->actingAs($this->admin());
    }

    /**
     * Asuntos de las filas del listado, en orden.
     *
     * @return list<string>
     */
    private function asuntos(TestResponse $respuesta): array
    {
        preg_match_all('#<a href="[^"]*" class="panel-tabla-enlace correo-asunto-enlace">(.*?)</a>#s', $respuesta->getContent(), $coincidencias);

        return $coincidencias[1];
    }

    /**
     * Mismo truco que BannerCookiesTest: en los tests Laravel desactiva el CSRF;
     * así se comprueba de verdad.
     */
    private function activarCsrfReal(): void
    {
        $this->app->singleton(ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            };
        });
    }

    /**
     * @return list<string>
     */
    private function consultas(callable $accion): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $accion();
        $consultas = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $consultas;
    }

    // ---------- Acceso ----------

    public function test_un_visitante_va_al_login(): void
    {
        $correo = $this->correo();

        $this->get(route('correos.index'))->assertRedirect(route('login'));
        $this->get(route('correos.show', $correo))->assertRedirect(route('login'));
        $this->patch(route('correos.marcar', $correo), ['leido' => 1])->assertRedirect(route('login'));

        $this->assertFalse($correo->fresh()->is_read);
    }

    public static function rolesSinAcceso(): array
    {
        return [
            'lector' => ['lector'],
            'editor' => ['editor'],
        ];
    }

    #[DataProvider('rolesSinAcceso')]
    public function test_lector_y_editor_reciben_403_tambien_por_url_directa(string $rol): void
    {
        $usuario = User::factory()->{$rol}()->create();
        $correo = $this->correo();

        $this->actingAs($usuario)->get(route('correos.index'))->assertForbidden();
        $this->actingAs($usuario)->get(route('correos.show', $correo))->assertForbidden();
        $this->actingAs($usuario)->patch(route('correos.marcar', $correo), ['leido' => 1])->assertForbidden();
        $this->actingAs($usuario)->get('/dashboard/correos/'.$correo->id.'?estado=no-leidos')->assertForbidden();

        $this->assertFalse($correo->fresh()->is_read);
        $this->assertSame(0, AuditLog::where('action', 'marcar_correo')->count());
    }

    public function test_la_policy_solo_permite_ver_y_marcar_al_admin_y_nunca_crear_ni_borrar(): void
    {
        $correo = $this->correo();
        $admin = $this->admin();
        $editor = User::factory()->editor()->create();

        $this->assertTrue($admin->can('viewAny', ReceivedEmail::class));
        $this->assertTrue($admin->can('view', $correo));
        $this->assertTrue($admin->can('update', $correo));
        $this->assertFalse($admin->can('create', ReceivedEmail::class));
        $this->assertFalse($admin->can('delete', $correo));

        foreach (['viewAny' => ReceivedEmail::class, 'view' => $correo, 'update' => $correo] as $habilidad => $objetivo) {
            $this->assertFalse($editor->can($habilidad, $objetivo), $habilidad);
        }
    }

    public function test_el_admin_entra_y_el_enlace_de_la_barra_queda_activo(): void
    {
        $correo = $this->correo();

        foreach ([route('correos.index'), route('correos.show', $correo)] as $url) {
            $html = $this->comoAdmin()->get($url)->assertOk()->getContent();

            $this->assertSame(1, preg_match('#<ul class="menu-lista" aria-labelledby="menu-gestion">(.*?)</ul>#s', $html, $grupo));
            $this->assertMatchesRegularExpression(
                '#<a href="'.preg_quote(route('correos.index'), '#').'" class="menu-enlace menu-enlace-activo"\s+aria-current="page"\s+data-etiqueta="Correos recibidos">#',
                $grupo[1]
            );
            $this->assertStringContainsString('<span class="menu-texto">Correos recibidos</span>', $grupo[1]);
        }
    }

    public function test_el_editor_no_ve_el_enlace_y_el_admin_lo_ve_antes_de_auditoria(): void
    {
        $this->actingAs(User::factory()->editor()->create())
            ->get(route('home'))
            ->assertDontSee('href="'.route('correos.index').'"', false)
            ->assertDontSee('Correos recibidos');

        $html = $this->comoAdmin()->get(route('home'))->getContent();
        $this->assertSame(1, preg_match('#<ul class="menu-lista" aria-labelledby="menu-gestion">(.*?)</ul>#s', $html, $grupo));
        preg_match_all('#<a href="([^"]+)"#', $grupo[1], $enlaces);

        $posicion = array_search(route('correos.index'), $enlaces[1], true);
        $this->assertSame(4, $posicion);
        $this->assertSame(route('comentarios.index'), $enlaces[1][3]);
        $this->assertSame(route('auditoria.index'), $enlaces[1][5]);
    }

    // ---------- Listado ----------

    public function test_el_listado_ordena_por_fecha_desc_y_luego_por_id_desc(): void
    {
        $this->correo(['subject' => 'Viejo', 'received_at' => '2026-10-01 10:00:00']);
        $this->correo(['subject' => 'Empate A', 'received_at' => '2026-11-05 10:00:00']);
        $this->correo(['subject' => 'Empate B', 'received_at' => '2026-11-05 10:00:00']);
        $this->correo(['subject' => 'Nuevo', 'received_at' => '2026-11-07 10:00:00']);
        $this->correo(['subject' => 'Sin fecha', 'received_at' => null]);

        $respuesta = $this->comoAdmin()->get(route('correos.index'))->assertOk();

        $this->assertSame(['Nuevo', 'Empate B', 'Empate A', 'Viejo', 'Sin fecha'], $this->asuntos($respuesta));
    }

    public function test_pagina_de_15_y_fuera_de_rango_404(): void
    {
        foreach (range(1, 16) as $i) {
            $this->correo();
        }

        $admin = $this->admin();

        $this->assertCount(15, $this->asuntos($this->actingAs($admin)->get(route('correos.index'))->assertOk()));
        $this->assertCount(1, $this->asuntos($this->actingAs($admin)->get(route('correos.index', ['page' => 2]))->assertOk()));
        $this->actingAs($admin)->get(route('correos.index', ['page' => 3]))->assertNotFound();
        $this->actingAs($admin)
            ->get(route('correos.index', ['page' => 2]))
            ->assertSee('aria-label="Paginación de correos recibidos"', false);
    }

    public function test_el_filtro_no_leidos_y_los_contadores(): void
    {
        $this->correo(['subject' => 'Leído 1', 'is_read' => true]);
        $this->correo(['subject' => 'Leído 2', 'is_read' => true]);
        $this->correo(['subject' => 'Pendiente 1']);
        $this->correo(['subject' => 'Leído 3', 'is_read' => true]);
        $this->correo(['subject' => 'Pendiente 2']);

        $admin = $this->admin();

        $todos = $this->actingAs($admin)->get(route('correos.index'))->assertOk();
        $todos->assertSee('Todos <span class="panel-estado-total" aria-hidden="true">(5)</span>', false)
            ->assertSee('No leídos <span class="panel-estado-total" aria-hidden="true">(2)</span>', false)
            ->assertSee(', 2 correos', false);
        $this->assertCount(5, $this->asuntos($todos));
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('correos.index'), '#').'"\s+class="panel-estado-tab panel-estado-activo"\s+aria-current="page"\s*>\s*Todos#', $todos->getContent());

        $noLeidos = $this->actingAs($admin)->get(route('correos.index', ['estado' => 'no-leidos']))->assertOk();
        $this->assertSame(['Pendiente 2', 'Pendiente 1'], $this->asuntos($noLeidos));
        // Los contadores no dependen de la pestaña.
        $noLeidos->assertSee('(5)', false)->assertSee('(2)', false);
        $this->assertMatchesRegularExpression('#class="panel-estado-tab panel-estado-activo"\s+aria-current="page"\s*>\s*No leídos#', $noLeidos->getContent());
        $this->assertSame(1, substr_count($noLeidos->getContent(), 'class="panel-estado-tab panel-estado-activo"'));
    }

    public function test_las_filas_no_leidas_se_distinguen_por_texto(): void
    {
        $this->correo(['subject' => 'Pendiente']);
        $this->correo(['subject' => 'Visto', 'is_read' => true]);

        $this->comoAdmin()->get(route('correos.index'))
            ->assertSee('<span class="chip chip-estado chip-no-leido">No leído</span>', false)
            ->assertSee('<span class="chip chip-estado chip-leido">Leído</span>', false)
            ->assertSee('class="correo-fila-no-leida"', false);
    }

    public static function filtrosInvalidos(): array
    {
        return [
            'valor desconocido' => [['estado' => 'spam'], 'estado'],
            'arreglo' => [['estado' => ['no-leidos']], 'estado'],
            'pagina no numerica' => [['page' => 'abc'], 'page'],
            'pagina cero' => [['page' => '0'], 'page'],
        ];
    }

    #[DataProvider('filtrosInvalidos')]
    public function test_un_filtro_invalido_se_rechaza(array $parametros, string $campo): void
    {
        $this->comoAdmin()
            ->withHeader('Referer', 'https://sitio-malo.test/')
            ->get(route('correos.index', $parametros))
            ->assertRedirect(route('correos.index'))
            ->assertSessionHasErrors($campo);
    }

    public function test_el_error_de_filtro_se_muestra_en_la_bandeja(): void
    {
        $this->comoAdmin()
            ->followingRedirects()
            ->get(route('correos.index', ['estado' => 'spam']))
            ->assertOk()
            ->assertSee('class="buscador-error" role="alert"', false);
    }

    public function test_los_contadores_salen_de_una_sola_consulta_y_las_consultas_no_crecen(): void
    {
        $admin = $this->admin();
        $this->correo();
        $this->actingAs($admin)->get(route('correos.index'))->assertOk();

        $conUno = $this->consultas(fn () => $this->actingAs($admin)->get(route('correos.index'))->assertOk());

        foreach (range(1, 14) as $i) {
            $this->correo(['is_read' => $i % 2 === 0]);
        }
        $conQuince = $this->consultas(fn () => $this->actingAs($admin)->get(route('correos.index'))->assertOk());

        $this->assertCount(count($conUno), $conQuince);

        $sobreCorreos = array_values(array_filter($conQuince, fn ($sql) => str_contains($sql, 'received_emails')));
        $this->assertCount(1, array_filter($sobreCorreos, fn ($sql) => str_contains(strtolower($sql), 'sum(case when is_read')));
        // Contadores + total del paginador + página: 3 consultas sobre la tabla.
        $this->assertCount(3, $sobreCorreos);
    }

    public function test_el_listado_no_carga_el_cuerpo(): void
    {
        $this->correo(['body' => 'CUERPO-QUE-NO-DEBE-SALIR']);
        $admin = $this->admin();

        $consultas = $this->consultas(fn () => $this->actingAs($admin)->get(route('correos.index'))
            ->assertOk()
            ->assertDontSee('CUERPO-QUE-NO-DEBE-SALIR'));

        foreach ($consultas as $sql) {
            if (str_contains($sql, 'received_emails')) {
                $this->assertStringNotContainsString('body', $sql);
                $this->assertDoesNotMatchRegularExpression('/select \*/i', $sql);
            }
        }
    }

    public function test_estado_vacio_sin_y_con_filtro(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('correos.index'))
            ->assertOk()
            ->assertSee('Aún no hay correos recibidos.')
            ->assertDontSee('Ver todos los correos');

        $this->correo(['is_read' => true]);

        $this->actingAs($admin)->get(route('correos.index', ['estado' => 'no-leidos']))
            ->assertOk()
            ->assertSee('No hay correos sin leer.')
            ->assertSee('Ver todos los correos');
    }

    public function test_remitente_asunto_fecha_y_adjuntos_en_la_tabla(): void
    {
        $this->correo(['from_name' => 'Ana Lectora', 'from_email' => 'ana@nexus.test', 'subject' => null, 'has_attachments' => true, 'received_at' => '2026-11-07 15:30:00']);
        $this->correo(['from_name' => null, 'from_email' => 'solo-correo@nexus.test', 'received_at' => null]);

        $this->comoAdmin()->get(route('correos.index'))
            ->assertOk()
            ->assertSee('<span class="usuario-nombre" title="ana@nexus.test">Ana Lectora</span>', false)
            ->assertSee('<span class="usuario-correo" title="ana@nexus.test">ana@nexus.test</span>', false)
            ->assertSee('<span class="usuario-nombre" title="solo-correo@nexus.test">solo-correo@nexus.test</span>', false)
            ->assertSee('(sin asunto)')
            ->assertSee('datetime="2026-11-07T15:30:00+00:00"', false)
            ->assertSee('Sin fecha')
            ->assertSee('<td role="cell" data-columna="Adjuntos">Sí</td>', false)
            ->assertSee('<td role="cell" data-columna="Adjuntos">No</td>', false);
    }

    public function test_el_asunto_enlaza_al_detalle_conservando_filtro_y_pagina(): void
    {
        foreach (range(1, 16) as $i) {
            $this->correo();
        }
        $ultimo = ReceivedEmail::orderBy('received_at')->orderBy('id')->first();

        $this->comoAdmin()->get(route('correos.index', ['estado' => 'no-leidos', 'page' => 2]))
            ->assertOk()
            ->assertSee('href="'.e(route('correos.show', ['correo' => $ultimo, 'estado' => 'no-leidos', 'page' => 2])).'"', false);

        $this->comoAdmin()->get(route('correos.index'))
            ->assertSee('href="'.route('correos.show', ReceivedEmail::orderByDesc('received_at')->first()).'"', false);
    }

    // ---------- XSS ----------

    private function correoMalicioso(): ReceivedEmail
    {
        return $this->correo([
            'subject' => '<script>alert("asunto")</script> Tom & Jerry <img src=x onerror=alert(1)>',
            'from_name' => '<script>alert("nombre")</script> <img src=x onerror=alert(2)>',
            'from_email' => '"><script>alert(3)</script>@evil.example',
            'body' => "<script>alert('cuerpo')</script>\nTom & Jerry <img src=x onerror=alert(4)>\nVisita https://evil.example/login ya",
        ]);
    }

    public function test_el_listado_escapa_todo_lo_del_remitente(): void
    {
        $this->correoMalicioso();

        $html = $this->comoAdmin()->get(route('correos.index'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<main\b.*?</main>#s', $html, $main));

        $this->assertStringNotContainsString('<script>alert', $main[0]);
        $this->assertStringNotContainsString('<img src=x', $main[0]);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;asunto&quot;)&lt;/script&gt; Tom &amp; Jerry &lt;img src=x onerror=alert(1)&gt;', $main[0]);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;nombre&quot;)', $main[0]);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;alert(3)&lt;/script&gt;@evil.example', $main[0]);
    }

    public function test_el_detalle_escapa_todo_y_no_convierte_urls_en_enlaces(): void
    {
        $correo = $this->correoMalicioso();

        $html = $this->comoAdmin()->get(route('correos.show', $correo))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<main\b.*?</main>#s', $html, $main));

        $this->assertStringNotContainsString('<script>alert', $main[0]);
        $this->assertStringNotContainsString('<img src=x', $main[0]);
        $this->assertStringContainsString('&lt;script&gt;alert(&#039;cuerpo&#039;)&lt;/script&gt;', $main[0]);
        $this->assertStringContainsString('Tom &amp; Jerry', $main[0]);
        $this->assertStringContainsString('<div class="texto-preformateado correo-cuerpo">', $main[0]);
        $this->assertStringContainsString('https://evil.example/login', $main[0]);
        $this->assertStringNotContainsString('href="https://evil.example', $main[0]);
        $this->assertDoesNotMatchRegularExpression('#<a\b[^>]*evil\.example#', $main[0]);

        // El <title> es fijo: el asunto no llega a la pestaña ni al historial.
        $this->assertStringContainsString('<title>Correo recibido · Nexus Plays</title>', $html);
    }

    // ---------- Detalle ----------

    public function test_ver_un_correo_no_lo_marca_como_leido(): void
    {
        $correo = $this->correo();
        $antes = $correo->fresh()->getAttributes();

        $this->comoAdmin()->get(route('correos.show', $correo))
            ->assertOk()
            ->assertSee('<span class="chip chip-estado chip-no-leido">No leído</span>', false)
            ->assertSee('Marcar como leído');

        $this->assertSame($antes, $correo->fresh()->getAttributes());
        $this->assertSame(0, AuditLog::where('action', 'marcar_correo')->count());
    }

    public function test_el_detalle_muestra_asunto_remitente_fecha_y_adjuntos(): void
    {
        $con = $this->correo(['subject' => 'Consulta', 'from_name' => 'Ana', 'from_email' => 'ana@nexus.test', 'has_attachments' => true, 'received_at' => '2026-11-07 15:30:00']);
        $sin = $this->correo(['has_attachments' => false, 'from_name' => null, 'body' => null, 'is_read' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('correos.show', $con))
            ->assertOk()
            ->assertSee('<h1 id="correo-asunto" class="pagina-titulo correo-titulo">Consulta</h1>', false)
            ->assertSee('Ana &lt;ana@nexus.test&gt;', false)
            ->assertSee('datetime="2026-11-07T15:30:00+00:00"', false)
            ->assertSee('Tenía adjuntos; no se guardan ni se pueden descargar.');

        $this->actingAs($admin)->get(route('correos.show', $sin))
            ->assertOk()
            ->assertDontSee('Tenía adjuntos')
            ->assertSee('El correo no tiene texto.')
            ->assertSee('Marcar como no leído')
            ->assertSee('<input type="hidden" name="leido" value="0">', false);
    }

    public function test_un_correo_inexistente_da_404(): void
    {
        $this->comoAdmin()->get(route('correos.show', 9999))->assertNotFound();
    }

    public function test_la_constancia_aparece_solo_si_coincide_el_message_id_y_con_una_consulta(): void
    {
        $correo = $this->correo(['message_id' => 'uuid-123@nexusplays.test']);
        $otro = $this->correo(['message_id' => 'sin-constancia@nexus.test']);

        $constancia = new ContactConsent(['email' => 'visitante@nexus.test']);
        $constancia->privacy_accepted_at = CarbonImmutable::parse('2026-11-06 20:15:00', 'UTC');
        $constancia->legal_version = '0-prueba';
        $constancia->message_id = 'uuid-123@nexusplays.test';
        $constancia->save();

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('correos.show', $correo))->assertOk();

        $consultas = $this->consultas(fn () => $this->actingAs($admin)->get(route('correos.show', $correo))
            ->assertOk()
            ->assertSee('Constancia de autorización')
            ->assertSee('datetime="2026-11-06T20:15:00+00:00"', false)
            ->assertSee('con la política versión 0-prueba'));

        $this->assertCount(1, array_filter($consultas, fn ($sql) => str_contains($sql, 'contact_consents')));

        $this->actingAs($admin)->get(route('correos.show', $otro))
            ->assertOk()
            ->assertDontSee('Constancia de autorización');
    }

    public static function parametrosDeVuelta(): array
    {
        return [
            'validos' => [['estado' => 'no-leidos', 'page' => '3'], ['estado' => 'no-leidos', 'page' => 3]],
            'todos se omite' => [['estado' => 'todos', 'page' => '2'], ['page' => 2]],
            'estado invalido' => [['estado' => 'spam', 'page' => '2'], ['page' => 2]],
            'pagina invalida' => [['estado' => 'no-leidos', 'page' => 'abc'], ['estado' => 'no-leidos']],
            'pagina 1 se omite' => [['page' => '1'], []],
            'arreglos' => [['estado' => ['no-leidos'], 'page' => ['2']], []],
            'nada' => [[], []],
        ];
    }

    #[DataProvider('parametrosDeVuelta')]
    public function test_volver_conserva_solo_filtro_y_pagina_validos(array $consulta, array $esperado): void
    {
        $correo = $this->correo();

        $this->comoAdmin()->get(route('correos.show', ['correo' => $correo, ...$consulta]))
            ->assertOk()
            ->assertSee('<a href="'.e(route('correos.index', $esperado)).'" class="enlace-volver">← Volver a correos recibidos</a>', false);
    }

    // ---------- Marcar ----------

    public function test_marcar_como_leido_y_como_no_leido(): void
    {
        $correo = $this->correo();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch(route('correos.marcar', $correo), ['leido' => '1'])
            ->assertRedirect(route('correos.show', $correo))
            ->assertSessionHas('status', 'Correo marcado como leído.');

        $correo->refresh();
        $this->assertTrue($correo->is_read);
        $this->assertNotNull($correo->read_at);

        $this->actingAs($admin)
            ->patch(route('correos.marcar', $correo), ['leido' => '0'])
            ->assertRedirect(route('correos.show', $correo))
            ->assertSessionHas('status', 'Correo marcado como no leído.');

        $correo->refresh();
        $this->assertFalse($correo->is_read);
        $this->assertNull($correo->read_at);
    }

    public function test_marcar_conserva_filtro_y_pagina_validos_al_volver(): void
    {
        $correo = $this->correo();

        $this->comoAdmin()
            ->patch(route('correos.marcar', $correo), ['leido' => '1', 'estado' => 'no-leidos', 'page' => '2'])
            ->assertRedirect(route('correos.show', ['correo' => $correo, 'estado' => 'no-leidos', 'page' => 2]));

        $this->comoAdmin()
            ->patch(route('correos.marcar', $correo), ['leido' => '0', 'estado' => 'spam', 'page' => 'x'])
            ->assertRedirect(route('correos.show', $correo));
    }

    public function test_el_formulario_del_detalle_lleva_csrf_patch_y_los_parametros_de_vuelta(): void
    {
        $correo = $this->correo();

        $this->comoAdmin()->get(route('correos.show', ['correo' => $correo, 'estado' => 'no-leidos', 'page' => 2]))
            ->assertSee('action="'.route('correos.marcar', $correo).'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('<input type="hidden" name="_method" value="PATCH">', false)
            ->assertSee('<input type="hidden" name="leido" value="1">', false)
            ->assertSee('<input type="hidden" name="estado" value="no-leidos">', false)
            ->assertSee('<input type="hidden" name="page" value="2">', false);
    }

    public function test_sin_csrf_responde_419_y_no_cambia_nada(): void
    {
        $this->activarCsrfReal();
        $correo = $this->correo();

        $this->comoAdmin()->patch(route('correos.marcar', $correo), ['leido' => '1'])->assertStatus(419);

        $this->assertFalse($correo->fresh()->is_read);
    }

    public static function leidoInvalido(): array
    {
        return [
            'texto' => [['leido' => 'quizas']],
            'numero fuera' => [['leido' => '2']],
            'arreglo' => [['leido' => ['1']]],
            'ausente' => [[]],
        ];
    }

    #[DataProvider('leidoInvalido')]
    public function test_un_leido_invalido_se_rechaza(array $datos): void
    {
        $correo = $this->correo();

        $this->comoAdmin()
            ->withHeader('Referer', 'https://sitio-malo.test/')
            ->patch(route('correos.marcar', $correo), $datos)
            ->assertRedirect(route('correos.show', $correo))
            ->assertSessionHasErrors('leido');

        $this->assertFalse($correo->fresh()->is_read);
        $this->assertSame(0, AuditLog::where('action', 'marcar_correo')->count());
    }

    public function test_marcar_un_id_inexistente_da_404(): void
    {
        $this->comoAdmin()->patch(route('correos.marcar', 9999), ['leido' => '1'])->assertNotFound();
    }

    public function test_el_mismo_valor_no_audita(): void
    {
        $correo = $this->correo(['is_read' => true, 'read_at' => '2026-11-07 10:00:00']);

        $this->comoAdmin()
            ->patch(route('correos.marcar', $correo), ['leido' => '1'])
            ->assertRedirect(route('correos.show', $correo))
            ->assertSessionHas('status', 'El correo ya estaba marcado como leído.');

        $this->assertSame(0, AuditLog::where('action', 'marcar_correo')->count());
        // read_at no se reescribe.
        $this->assertSame('2026-11-07 10:00:00', $correo->fresh()->read_at->format('Y-m-d H:i:s'));
    }

    public function test_la_auditoria_guarda_solo_is_read_y_aparece_en_el_filtro(): void
    {
        $correo = $this->correo(['subject' => 'Asunto privado', 'from_email' => 'privado@nexus.test', 'body' => 'Cuerpo privado']);
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('correos.marcar', $correo), ['leido' => '1']);

        $registro = AuditLog::where('action', 'marcar_correo')->sole();
        $this->assertSame('marcar_correo', $registro->action);
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame(ReceivedEmail::class, $registro->auditable_type);
        $this->assertSame($correo->id, $registro->auditable_id);
        $this->assertSame(['is_read' => false], $registro->old_values);
        $this->assertSame(['is_read' => true], $registro->new_values);
        $this->assertSame('baja', $registro->importancia());
        $this->assertSame('Marcó correo', $registro->etiquetaAccion());
        $this->assertSame('Correo recibido', $registro->tipoElemento());

        $html = $this->actingAs($admin)->get(route('auditoria.index', ['accion' => 'marcar_correo']))->assertOk()->getContent();
        $this->assertStringContainsString('Marcó correo', $html);
        $this->assertStringContainsString('No leído', $html);
        $this->assertStringContainsString('Leído', $html);
        $this->assertStringNotContainsString('Asunto privado', $html);
        $this->assertStringNotContainsString('privado@nexus.test', $html);
        $this->assertStringNotContainsString('Cuerpo privado', $html);
    }

    public function test_la_peticion_31_en_un_minuto_responde_429(): void
    {
        $correo = $this->correo();
        $admin = $this->admin();

        foreach (range(1, 30) as $intento) {
            $this->actingAs($admin)->patch(route('correos.marcar', $correo), ['leido' => $intento % 2])->assertRedirect();
        }

        $this->actingAs($admin)->patch(route('correos.marcar', $correo), ['leido' => '1'])->assertStatus(429);
    }

    // ---------- Vistas ----------

    public function test_las_vistas_nuevas_no_tienen_codigo_en_linea(): void
    {
        foreach (self::VISTAS as $vista) {
            $codigo = file_get_contents(resource_path('views/'.$vista));

            $this->assertStringNotContainsString('style=', $codigo, $vista);
            $this->assertStringNotContainsString('onclick', $codigo, $vista);
            $this->assertStringNotContainsString('<script', $codigo, $vista);
            $this->assertStringNotContainsString('{!!', $codigo, $vista);
        }
    }

    public function test_toda_clase_de_las_vistas_nuevas_existe_en_app_css(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/app.css')));

        foreach (self::VISTAS as $vista) {
            $codigo = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(resource_path('views/'.$vista)));

            $clases = [];
            preg_match_all('/\bclass="([^"]*)"/', $codigo, $atributos);
            foreach ($atributos[1] as $valor) {
                $sinBlade = (string) preg_replace('/\{\{.*?\}\}/s', ' ', $valor);
                array_push($clases, ...(preg_split('/\s+/', $sinBlade, -1, PREG_SPLIT_NO_EMPTY) ?: []));
            }

            // Clases dentro de @class([...]): las claves de texto.
            preg_match_all('/@class\(\[(.*?)\]\)/s', $codigo, $bloques);
            foreach ($bloques[1] as $bloque) {
                preg_match_all("/'([a-z0-9-]+)'/", $bloque, $nombres);
                array_push($clases, ...$nombres[1]);
            }

            $this->assertNotEmpty($clases, $vista);

            foreach (array_unique($clases) as $clase) {
                $this->assertMatchesRegularExpression(
                    '/\.'.preg_quote($clase, '/').'(?![A-Za-z0-9_-])/',
                    $css,
                    "La clase .{$clase} de {$vista} no tiene ningún selector en app.css."
                );
            }
        }
    }
}
