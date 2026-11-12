<?php

namespace Tests\Feature;

use App\Console\Commands\RecibirCorreos;
use App\Contracts\LectorBuzon;
use App\Services\Correo\GuardarCorreosRecibidos;
use App\Services\Correo\LectorBuzonWebklex;
use App\Support\Correo\CorreoEntrante;
use App\Support\Correo\ResultadoLectura;
use App\Support\Correo\ResumenGuardado;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\LectorBuzonFalso;
use Tests\TestCase;
use Webklex\PHPIMAP\IMAP;

/**
 * NP-46 paso 5: comando mail:receive. Todos los tests usan un LectorBuzon falso
 * (D8): ninguno se conecta a Gmail. Desde el paso 6 el comando también guarda,
 * por eso usa la base de datos (el guardado se prueba en GuardarCorreosRecibidosTest).
 */
class RecibirCorreosTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRASENA = 'clave-de-aplicacion-superSecreta-9x';

    private LectorBuzonFalso $lector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usarLector(new LectorBuzonFalso);
    }

    private function usarLector(LectorBuzonFalso $lector): void
    {
        $this->lector = $lector;
        $this->app->instance(LectorBuzon::class, $lector);
    }

    private function correo(array $cambios = []): CorreoEntrante
    {
        $datos = array_merge([
            'messageId' => 'abc123@nexus.test',
            'remitenteCorreo' => 'ana@nexus.test',
            'remitenteNombre' => 'Ana Lectora',
            'asunto' => 'Sugerencia para la revista',
            'texto' => 'CUERPO-SECRETO-123 que no debe imprimirse',
            'recibidoEn' => CarbonImmutable::parse('2026-11-07 15:30:00', config('app.timezone')),
            'tieneAdjuntos' => false,
        ], $cambios);

        return new CorreoEntrante(...$datos);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function ejecutar(array $opciones = []): array
    {
        $codigo = Artisan::call('mail:receive', $opciones);

        return [$codigo, Artisan::output()];
    }

    // ---------- Listado ----------

    public function test_lista_los_correos_sin_imprimir_el_cuerpo(): void
    {
        $this->usarLector(new LectorBuzonFalso([
            $this->correo(),
            $this->correo([
                'messageId' => 'def456@nexus.test',
                'remitenteCorreo' => 'beto@nexus.test',
                'remitenteNombre' => null,
                'asunto' => 'Con adjunto',
                'tieneAdjuntos' => true,
            ]),
        ]));

        [$codigo, $salida] = $this->ejecutar();

        $this->assertSame(Command::SUCCESS, $codigo);
        $this->assertStringContainsString('2026-11-07 15:30', $salida);
        $this->assertStringContainsString('Ana Lectora <ana@nexus.test>', $salida);
        $this->assertStringContainsString('Sugerencia para la revista', $salida);
        $this->assertStringContainsString('beto@nexus.test', $salida);
        $this->assertStringContainsString('Con adjunto', $salida);
        $this->assertStringContainsString('Sí', $salida);
        $this->assertStringContainsString('2 correo(s). No se marcó ninguno como leído.', $salida);

        // El cuerpo nunca se imprime.
        $this->assertStringNotContainsString('CUERPO-SECRETO-123', $salida);
    }

    public function test_sin_correos_muestra_el_mensaje_y_termina_bien(): void
    {
        [$codigo, $salida] = $this->ejecutar();

        $this->assertSame(Command::SUCCESS, $codigo);
        $this->assertStringContainsString('No hay correos en el periodo indicado.', $salida);
    }

    public function test_un_correo_sin_fecha_ni_asunto_se_lista(): void
    {
        $this->usarLector(new LectorBuzonFalso([$this->correo(['recibidoEn' => null, 'asunto' => null])]));

        [$codigo, $salida] = $this->ejecutar();

        $this->assertSame(Command::SUCCESS, $codigo);
        $this->assertStringContainsString('(sin fecha)', $salida);
        $this->assertStringContainsString('(sin asunto)', $salida);
    }

    // ---------- D9: saneado ----------

    public function test_un_asunto_con_ansi_bel_y_saltos_sale_limpio_y_en_una_linea(): void
    {
        $this->usarLector(new LectorBuzonFalso([
            $this->correo(['asunto' => "\e[31mROJO\e[0m \x07Alerta\r\nsegunda línea \x1b]8;;http://malo.test\x07enlace\x1b]8;;\x07"]),
        ]));

        [, $salida] = $this->ejecutar();

        $this->assertStringNotContainsString("\e", $salida);
        $this->assertStringNotContainsString("\x07", $salida);
        $this->assertStringNotContainsString('[31m', $salida);
        $this->assertStringNotContainsString('malo.test', $salida);

        // Todo el asunto en la misma línea de la tabla.
        $this->assertMatchesRegularExpression('/^\|.*ROJO Alerta segunda línea enlace.*\|\r?$/mu', $salida);
    }

    public function test_un_asunto_de_300_caracteres_se_trunca(): void
    {
        $this->usarLector(new LectorBuzonFalso([$this->correo(['asunto' => str_repeat('A', 300)])]));

        [, $salida] = $this->ejecutar();

        $this->assertStringContainsString(str_repeat('A', 149).'…', $salida);
        $this->assertStringNotContainsString(str_repeat('A', 150), $salida);
    }

    public function test_un_remitente_largo_se_trunca(): void
    {
        $this->usarLector(new LectorBuzonFalso([
            $this->correo(['remitenteNombre' => str_repeat('N', 300), 'remitenteCorreo' => 'x@nexus.test']),
        ]));

        [, $salida] = $this->ejecutar();

        $this->assertStringContainsString(str_repeat('N', 149).'…', $salida);
        $this->assertStringNotContainsString(str_repeat('N', 150), $salida);
    }

    public function test_las_etiquetas_de_estilo_de_la_consola_salen_como_texto(): void
    {
        $this->usarLector(new LectorBuzonFalso([
            $this->correo(['asunto' => '<fg=red>ROJO</> <href=http://malo.test>clic</>']),
        ]));

        [, $salida] = $this->ejecutar();

        // Symfony Console no las interpreta: aparecen tal cual.
        $this->assertStringContainsString('<fg=red>ROJO</> <href=http://malo.test>clic</>', $salida);
    }

    // ---------- Errores de conexión ----------

    public function test_si_falla_la_conexion_no_muestra_la_excepcion_ni_la_contrasena(): void
    {
        Exceptions::fake();

        $this->usarLector(new LectorBuzonFalso([], new RuntimeException(
            'AUTHENTICATE failed for usuario@gmail.com with password '.self::CONTRASENA
        )));

        [$codigo, $salida] = $this->ejecutar();

        $this->assertSame(Command::FAILURE, $codigo);
        $this->assertStringContainsString(RecibirCorreos::ERROR_CONEXION, $salida);
        $this->assertStringNotContainsString(self::CONTRASENA, $salida);
        $this->assertStringNotContainsString('usuario@gmail.com', $salida);
        $this->assertStringNotContainsString('AUTHENTICATE', $salida);
        $this->assertStringNotContainsString('RuntimeException', $salida);

        // La excepción va al log con report().
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_si_falla_el_guardado_da_un_mensaje_generico_sin_detalles(): void
    {
        Exceptions::fake();

        $this->usarLector(new LectorBuzonFalso([$this->correo()]));
        $this->app->instance(GuardarCorreosRecibidos::class, new class extends GuardarCorreosRecibidos
        {
            public function guardar(ResultadoLectura $lectura): ResumenGuardado
            {
                throw new RuntimeException('SQLSTATE fallo con '.'clave-de-aplicacion-superSecreta-9x');
            }
        });

        [$codigo, $salida] = $this->ejecutar();

        $this->assertSame(Command::FAILURE, $codigo);
        $this->assertStringContainsString(RecibirCorreos::ERROR_GUARDAR, $salida);
        $this->assertStringNotContainsString(self::CONTRASENA, $salida);
        $this->assertStringNotContainsString('SQLSTATE', $salida);
        Exceptions::assertReported(RuntimeException::class);
    }

    // ---------- Opciones ----------

    public function test_por_defecto_pide_10_correos_de_7_dias(): void
    {
        $this->ejecutar();

        $this->assertSame([[10, 7]], $this->lector->llamadas);
    }

    public function test_pasa_las_opciones_validas_al_lector(): void
    {
        [$codigo] = $this->ejecutar(['--limite' => '50', '--dias' => '30']);
        $this->ejecutar(['--limite' => '1', '--dias' => '1']);

        $this->assertSame(Command::SUCCESS, $codigo);
        $this->assertSame([[50, 30], [1, 1]], $this->lector->llamadas);
    }

    public static function opcionesInvalidas(): array
    {
        return [
            'limite 0' => [['--limite' => '0'], '--limite'],
            'limite 51' => [['--limite' => '51'], '--limite'],
            'limite abc' => [['--limite' => 'abc'], '--limite'],
            'limite decimal' => [['--limite' => '1.5'], '--limite'],
            'limite negativo' => [['--limite' => '-3'], '--limite'],
            'limite vacio' => [['--limite' => ''], '--limite'],
            'dias 0' => [['--dias' => '0'], '--dias'],
            'dias 31' => [['--dias' => '31'], '--dias'],
            'dias abc' => [['--dias' => 'abc'], '--dias'],
        ];
    }

    #[DataProvider('opcionesInvalidas')]
    public function test_una_opcion_invalida_falla_sin_conectar(array $opciones, string $nombre): void
    {
        [$codigo, $salida] = $this->ejecutar($opciones);

        $this->assertSame(Command::FAILURE, $codigo);
        $this->assertStringContainsString($nombre.' debe ser un número entero entre', $salida);
        $this->assertSame([], $this->lector->llamadas);
    }

    // ---------- Configuración ----------

    public function test_el_contenedor_usa_el_lector_webklex(): void
    {
        $this->app->forgetInstance(LectorBuzon::class);

        // Resolverlo no conecta: solo crea el objeto.
        $this->assertInstanceOf(LectorBuzonWebklex::class, $this->app->make(LectorBuzon::class));
    }

    public function test_la_configuracion_imap_es_segura(): void
    {
        $this->assertFalse(config('imap.options.debug'));
        $this->assertSame(IMAP::FT_PEEK, config('imap.options.fetch'));
        $this->assertTrue(config('imap.options.fetch_flags'));
        $this->assertSame('iconv', config('imap.decoding.options.header'));

        // El debug es fijo: el archivo no lo lee de .env.
        $codigo = file_get_contents(config_path('imap.php'));
        $this->assertStringContainsString("'debug' => false,", $codigo);
        $this->assertDoesNotMatchRegularExpression("/'debug'\s*=>\s*env\(/", $codigo);
    }

    public static function valoresValidateCert(): array
    {
        return [
            'sin variable' => [null, true],
            'true' => ['true', true],
            'false' => ['false', false],
            '0' => ['0', false],
            'off' => ['off', false],
            'basura' => ['quizas', true],
        ];
    }

    #[DataProvider('valoresValidateCert')]
    public function test_validate_cert_es_un_bool_real_y_por_defecto_true(?string $valor, bool $esperado): void
    {
        $nombre = 'IMAP_VALIDATE_CERT';
        $antes = [$_ENV[$nombre] ?? null, $_SERVER[$nombre] ?? null, getenv($nombre)];

        try {
            putenv($valor === null ? $nombre : "{$nombre}={$valor}");
            if ($valor === null) {
                unset($_ENV[$nombre], $_SERVER[$nombre]);
            } else {
                $_ENV[$nombre] = $_SERVER[$nombre] = $valor;
            }

            $config = require config_path('imap.php');

            $this->assertSame($esperado, $config['accounts']['default']['validate_cert']);
        } finally {
            // Restaurar exactamente lo que había (puede venir del .env local).
            [$env, $server, $put] = $antes;
            unset($_ENV[$nombre], $_SERVER[$nombre]);
            if ($env !== null) {
                $_ENV[$nombre] = $env;
            }
            if ($server !== null) {
                $_SERVER[$nombre] = $server;
            }
            putenv($put === false ? $nombre : "{$nombre}={$put}");
        }
    }

    public function test_env_example_tiene_las_7_variables_imap_sin_contrasena(): void
    {
        $lineas = preg_split('/\R/', file_get_contents(base_path('.env.example')));
        $variables = [];
        foreach ($lineas as $linea) {
            if (preg_match('/^(IMAP_[A-Z_]+)=(.*)$/', $linea, $m)) {
                $variables[$m[1]] = $m[2];
            }
        }

        $this->assertSame(
            ['IMAP_HOST', 'IMAP_PORT', 'IMAP_ENCRYPTION', 'IMAP_VALIDATE_CERT', 'IMAP_PROTOCOL', 'IMAP_USERNAME', 'IMAP_PASSWORD'],
            array_keys($variables)
        );
        $this->assertSame('', $variables['IMAP_PASSWORD']);
        $this->assertSame('', $variables['IMAP_USERNAME']);
        $this->assertSame('imap.gmail.com', $variables['IMAP_HOST']);
        $this->assertSame('993', $variables['IMAP_PORT']);
        $this->assertSame('ssl', $variables['IMAP_ENCRYPTION']);
        $this->assertSame('true', $variables['IMAP_VALIDATE_CERT']);
        $this->assertSame('imap', $variables['IMAP_PROTOCOL']);
    }

    // ---------- Solo consola ----------

    public function test_ninguna_ruta_web_expone_la_lectura_del_buzon(): void
    {
        foreach (Route::getRoutes() as $ruta) {
            $uri = strtolower($ruta->uri());

            $this->assertStringNotContainsString('mail', $uri, $uri);
            $this->assertStringNotContainsString('imap', $uri, $uri);
            $this->assertStringNotContainsString('correo-entrante', $uri, $uri);
        }
    }
}
