<?php

namespace Tests\Feature;

use App\Console\Commands\RecibirCorreos;
use App\Contracts\LectorBuzon;
use App\Models\ReceivedEmail;
use App\Services\Correo\GuardarCorreosRecibidos;
use App\Support\Correo\CorreoEntrante;
use App\Support\Correo\ResultadoLectura;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\LectorBuzonFalso;
use Tests\TestCase;

/**
 * NP-46 paso 6: mail:receive guarda los correos en received_emails de forma
 * idempotente. Siempre con el LectorBuzon falso (D8): nada se conecta a Gmail.
 */
class GuardarCorreosRecibidosTest extends TestCase
{
    use RefreshDatabase;

    private function correo(array $cambios = []): CorreoEntrante
    {
        return new CorreoEntrante(...array_merge([
            'messageId' => 'uno@nexus.test',
            'remitenteCorreo' => 'ana@nexus.test',
            'remitenteNombre' => 'Ana Lectora',
            'asunto' => 'Sugerencia',
            'texto' => 'Hola, me gusta la revista.',
            'recibidoEn' => CarbonImmutable::parse('2026-11-07 15:30:00', 'UTC'),
            'tieneAdjuntos' => false,
        ], $cambios));
    }

    /**
     * @return list<CorreoEntrante>
     */
    private function tresCorreos(): array
    {
        return [
            $this->correo(),
            $this->correo(['messageId' => 'dos@nexus.test', 'asunto' => 'Segundo']),
            $this->correo(['messageId' => 'tres@nexus.test', 'asunto' => 'Tercero', 'tieneAdjuntos' => true]),
        ];
    }

    /**
     * @param  list<CorreoEntrante>  $correos
     * @return array{0: int, 1: string}
     */
    private function recibir(array $correos, array $opciones = [], int $omitidos = 0): array
    {
        $this->app->instance(LectorBuzon::class, new LectorBuzonFalso($correos, omitidos: $omitidos));

        $codigo = Artisan::call('mail:receive', $opciones);

        return [$codigo, Artisan::output()];
    }

    private function guardarUno(CorreoEntrante $correo): ReceivedEmail
    {
        $this->recibir([$correo]);

        return ReceivedEmail::sole();
    }

    // ---------- Idempotencia ----------

    public function test_dos_ejecuciones_con_los_mismos_correos_no_duplican(): void
    {
        [$codigo, $salida] = $this->recibir($this->tresCorreos());

        $this->assertSame(Command::SUCCESS, $codigo);
        $this->assertStringContainsString('Nuevos: 3 · Ya guardados: 0 · Omitidos: 0', $salida);
        $this->assertSame(3, ReceivedEmail::count());

        [$codigo, $salida] = $this->recibir($this->tresCorreos());

        $this->assertSame(Command::SUCCESS, $codigo);
        $this->assertStringContainsString('Nuevos: 0 · Ya guardados: 3 · Omitidos: 0', $salida);
        $this->assertSame(3, ReceivedEmail::count());
    }

    public function test_un_correo_repetido_en_la_misma_lectura_se_guarda_una_vez(): void
    {
        [, $salida] = $this->recibir([$this->correo(), $this->correo(['asunto' => 'Otro asunto'])]);

        $this->assertStringContainsString('Nuevos: 1 · Ya guardados: 1', $salida);
        $this->assertSame('Sugerencia', ReceivedEmail::sole()->subject);
    }

    public function test_un_correo_existente_nunca_se_sobrescribe(): void
    {
        $existente = $this->guardarUno($this->correo());
        $existente->is_read = true;
        $existente->read_at = CarbonImmutable::parse('2026-11-08 09:00:00', 'UTC');
        $existente->save();
        $antes = $existente->fresh()->getAttributes();

        $this->travel(5)->minutes();

        [, $salida] = $this->recibir([$this->correo([
            'asunto' => 'Asunto cambiado en el buzón',
            'texto' => 'Cuerpo cambiado',
            'remitenteNombre' => 'Otro Nombre',
            'tieneAdjuntos' => true,
        ])]);

        $this->assertStringContainsString('Nuevos: 0 · Ya guardados: 1', $salida);
        $this->assertSame($antes, $existente->fresh()->getAttributes());
        $this->assertTrue($existente->fresh()->is_read);
        $this->assertSame('Sugerencia', $existente->fresh()->subject);
    }

    public function test_si_la_fila_ya_existe_antes_del_insert_no_falla_y_cuenta_como_ya_guardado(): void
    {
        // Simula la carrera: otra ejecución insertó la fila justo antes.
        DB::table('received_emails')->insert([
            'message_id' => 'uno@nexus.test',
            'from_email' => 'otra@ejecucion.test',
            'subject' => 'Insertado por la otra ejecución',
            'is_read' => false,
            'has_attachments' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // beforeExecuting (y no DB::listen): DB::listen solo ve las consultas que
        // terminan bien, y el INSERT rechazado por el UNIQUE no lo hace.
        $consultas = [];
        DB::beforeExecuting(function (string $sql) use (&$consultas) {
            if (str_contains($sql, 'received_emails')) {
                $consultas[] = strtolower($sql);
            }
        });

        $resumen = $this->app->make(GuardarCorreosRecibidos::class)->guardar(new ResultadoLectura([$this->correo()]));

        $this->assertSame(0, $resumen->nuevos);
        $this->assertSame(1, $resumen->existentes);
        $this->assertSame(1, ReceivedEmail::count());
        $this->assertSame('Insertado por la otra ejecución', ReceivedEmail::sole()->subject);

        // createOrFirst: lo primero es el INSERT (no un SELECT previo que dejaría
        // una ventana para que dos ejecuciones inserten a la vez).
        $this->assertStringStartsWith('insert', $consultas[0]);
        $this->assertStringStartsWith('select', $consultas[1]);
    }

    public function test_message_id_que_solo_difieren_en_mayusculas_son_dos_correos(): void
    {
        [, $salida] = $this->recibir([
            $this->correo(['messageId' => 'ABC123@nexus.test', 'asunto' => 'Legítimo']),
            $this->correo(['messageId' => 'abc123@nexus.test', 'asunto' => 'Otro correo']),
        ]);

        $this->assertStringContainsString('Nuevos: 2 · Ya guardados: 0', $salida);
        $this->assertSame(2, ReceivedEmail::count());
        $this->assertSame('Legítimo', ReceivedEmail::where('message_id', 'ABC123@nexus.test')->value('subject'));
        $this->assertSame('Otro correo', ReceivedEmail::where('message_id', 'abc123@nexus.test')->value('subject'));
    }

    // ---------- Message-ID ----------

    public static function messageIds(): array
    {
        $largo = str_repeat('a', 290).'@nexus.test';

        return [
            'con angulos' => ['<abc@nexus.test>', 'abc@nexus.test'],
            'con 300 caracteres' => [$largo, 'sha256:'.hash('sha256', $largo)],
            'vacio' => ['', 'sha256:'.hash('sha256', '')],
            'con salto de linea' => ["abc\n@nexus.test", 'sha256:'.hash('sha256', "abc\n@nexus.test")],
            'con espacio' => ['abc def@nexus.test', 'sha256:'.hash('sha256', 'abc def@nexus.test')],
        ];
    }

    #[DataProvider('messageIds')]
    public function test_el_message_id_se_normaliza_y_es_estable(string $original, string $esperado): void
    {
        $correo = $this->correo(['messageId' => $original]);

        $this->recibir([$correo]);
        [, $salida] = $this->recibir([$correo]);

        $guardado = ReceivedEmail::sole()->message_id;
        $this->assertSame($esperado, $guardado);
        $this->assertLessThanOrEqual(255, strlen($guardado));
        $this->assertStringContainsString('Nuevos: 0 · Ya guardados: 1', $salida);
    }

    // ---------- Normalización del contenido ----------

    public function test_el_asunto_y_el_nombre_se_guardan_limpios_y_truncados(): void
    {
        $email = $this->guardarUno($this->correo([
            'asunto' => "Hola\r\nBcc: x@example.com \e[31mROJO\e[0m factura\u{202E}fdp.exe",
            'remitenteNombre' => str_repeat('N', 200),
        ]));

        $this->assertSame('Hola Bcc: x@example.com ROJO facturafdp.exe', $email->subject);
        $this->assertStringNotContainsString("\n", $email->subject);
        $this->assertStringNotContainsString("\e", $email->subject);
        $this->assertStringNotContainsString("\u{202E}", $email->subject);
        $this->assertSame(str_repeat('N', 150), $email->from_name);
    }

    public function test_el_cuerpo_se_guarda_como_texto_literal(): void
    {
        $email = $this->guardarUno($this->correo([
            'texto' => "<script>alert(1)</script>\r\nLínea\0 dos\r\n\tcon tab",
        ]));

        $this->assertSame("<script>alert(1)</script>\nLínea dos\n\tcon tab", $email->body);
    }

    public function test_el_correo_del_remitente_va_en_minusculas_y_uno_invalido_se_guarda(): void
    {
        $this->recibir([
            $this->correo(['remitenteCorreo' => '  Ana@NEXUS.Test ']),
            $this->correo(['messageId' => 'dos@nexus.test', 'remitenteCorreo' => 'esto no es un correo']),
        ]);

        $this->assertSame('ana@nexus.test', ReceivedEmail::where('message_id', 'uno@nexus.test')->value('from_email'));
        $this->assertSame('esto no es un correo', ReceivedEmail::where('message_id', 'dos@nexus.test')->value('from_email'));
    }

    public function test_fecha_en_utc_y_adjuntos(): void
    {
        $email = $this->guardarUno($this->correo([
            'recibidoEn' => CarbonImmutable::parse('2026-11-07 10:30:00', 'America/Bogota'),
            'tieneAdjuntos' => true,
        ]));

        $this->assertSame('2026-11-07 15:30:00', DB::table('received_emails')->value('received_at'));
        $this->assertTrue($email->has_attachments);
    }

    public function test_un_correo_sin_fecha_ni_asunto_ni_texto_se_guarda(): void
    {
        $email = $this->guardarUno($this->correo(['recibidoEn' => null, 'asunto' => null, 'texto' => null, 'remitenteNombre' => null]));

        $this->assertNull($email->received_at);
        $this->assertNull($email->subject);
        $this->assertNull($email->body);
        $this->assertNull($email->from_name);
    }

    // ---------- is_read / asignación masiva ----------

    public function test_los_nuevos_quedan_sin_leer(): void
    {
        $email = $this->guardarUno($this->correo())->fresh();

        $this->assertFalse($email->is_read);
        $this->assertNull($email->read_at);
    }

    public function test_is_read_no_se_puede_asignar_en_masa(): void
    {
        try {
            ReceivedEmail::create([
                'message_id' => 'masivo@nexus.test',
                'from_email' => 'x@nexus.test',
                'is_read' => true,
                'read_at' => now(),
            ]);
            $this->fail('La asignación masiva debería rechazarse.');
        } catch (MassAssignmentException) {
            // Esperado: el modelo no tiene campos asignables.
        }

        $this->assertSame(0, ReceivedEmail::count());

        $email = $this->guardarUno($this->correo());
        $this->expectException(MassAssignmentException::class);
        $email->fill(['is_read' => true]);
    }

    // ---------- Comando ----------

    public function test_sin_guardar_no_crea_filas(): void
    {
        [$codigo, $salida] = $this->recibir($this->tresCorreos(), ['--sin-guardar' => true]);

        $this->assertSame(Command::SUCCESS, $codigo);
        $this->assertSame(0, ReceivedEmail::count());
        $this->assertStringContainsString('Leídos: 3 · Omitidos: 0 · No se guardó nada (--sin-guardar).', $salida);
        $this->assertStringContainsString('Sugerencia', $salida);
    }

    public function test_los_omitidos_salen_en_la_salida_y_en_el_log_sin_datos_personales(): void
    {
        Log::spy();

        [, $salida] = $this->recibir([$this->correo()], omitidos: 2);

        $this->assertStringContainsString('Nuevos: 1 · Ya guardados: 0 · Omitidos: 2', $salida);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $mensaje, array $contexto) {
                $todo = $mensaje.json_encode($contexto);

                return $mensaje === RecibirCorreos::AVISO_OMITIDOS
                    && $contexto === ['omitidos' => 2]
                    && ! str_contains($todo, 'Sugerencia')
                    && ! str_contains($todo, 'ana@nexus.test')
                    && ! str_contains($todo, 'uno@nexus.test');
            });
    }

    public function test_sin_omitidos_no_se_escribe_aviso(): void
    {
        Log::spy();

        $this->recibir([$this->correo()]);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_si_falla_la_conexion_no_se_crea_ninguna_fila(): void
    {
        Exceptions::fake();
        $this->app->instance(LectorBuzon::class, new LectorBuzonFalso($this->tresCorreos(), new RuntimeException('sin red')));

        $codigo = Artisan::call('mail:receive');

        $this->assertSame(Command::FAILURE, $codigo);
        $this->assertStringContainsString(RecibirCorreos::ERROR_CONEXION, Artisan::output());
        $this->assertSame(0, ReceivedEmail::count());
    }

    public function test_las_validaciones_de_opciones_siguen_igual(): void
    {
        $lector = new LectorBuzonFalso($this->tresCorreos());
        $this->app->instance(LectorBuzon::class, $lector);

        $this->assertSame(Command::FAILURE, Artisan::call('mail:receive', ['--limite' => '51']));
        $this->assertSame(Command::FAILURE, Artisan::call('mail:receive', ['--dias' => '0']));

        $this->assertSame([], $lector->llamadas);
        $this->assertSame(0, ReceivedEmail::count());
    }

    public function test_no_hay_rutas_web_para_los_correos_recibidos(): void
    {
        foreach (Route::getRoutes() as $ruta) {
            $uri = strtolower($ruta->uri());
            $nombre = strtolower((string) $ruta->getName());

            foreach (['mail', 'imap', 'correo-entrante', 'received', 'recibido'] as $palabra) {
                $this->assertStringNotContainsString($palabra, $uri, $uri);
            }

            // En los nombres no se busca "mail": password.email (recuperación) ya existía.
            foreach (['imap', 'received', 'recibido', 'correo-entrante'] as $palabra) {
                $this->assertStringNotContainsString($palabra, $nombre, $nombre);
            }
        }
    }
}
