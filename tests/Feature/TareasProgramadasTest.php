<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * NP-46 paso 8: tareas programadas de routes/console.php y su documentación.
 */
class TareasProgramadasTest extends TestCase
{
    /**
     * @return list<Event>
     */
    private function eventos(): array
    {
        return $this->app->make(Schedule::class)->events();
    }

    private function evento(string $comando): Event
    {
        $encontrados = array_values(array_filter(
            $this->eventos(),
            // Comillas de "artisan" según el sistema (simples en Linux, dobles en Windows).
            fn (Event $evento) => str_contains((string) $evento->command, 'artisan')
                && str_ends_with((string) $evento->command, ' '.$comando)
        ));

        $this->assertCount(1, $encontrados, "Debe haber exactamente una tarea «{$comando}».");

        return $encontrados[0];
    }

    public function test_mail_receive_cada_5_minutos_sin_solaparse_y_con_bloqueo_de_10_minutos(): void
    {
        $evento = $this->evento('mail:receive --limite=50 --dias=2');

        $this->assertSame('*/5 * * * *', $evento->expression);
        $this->assertTrue($evento->withoutOverlapping);
        $this->assertSame(10, $evento->expiresAt);
    }

    public function test_queue_prune_failed_diario_con_7_dias(): void
    {
        $evento = $this->evento('queue:prune-failed --hours=168');

        $this->assertSame('0 0 * * *', $evento->expression);
    }

    public function test_ninguna_tarea_guarda_ni_envia_su_salida(): void
    {
        foreach (['mail:receive --limite=50 --dias=2', 'queue:prune-failed --hours=168'] as $comando) {
            $evento = $this->evento($comando);

            // Sin sendOutputTo/appendOutputTo: la salida va a NUL (Windows) o /dev/null.
            $this->assertSame($evento->getDefaultOutput(), $evento->output, $comando);
            $this->assertFalse($evento->shouldAppendOutput, $comando);

            // emailOutputTo / emailOutputOnFailure se registran como callbacks "después".
            $despues = (new \ReflectionProperty(Event::class, 'afterCallbacks'))->getValue($evento);
            $this->assertSame([], $despues, $comando);
        }
    }

    public function test_solo_hay_las_dos_tareas_esperadas(): void
    {
        $this->assertCount(2, $this->eventos());
    }

    public function test_la_documentacion_existe_y_no_tiene_credenciales(): void
    {
        $ruta = base_path('docs/correo.md');
        $this->assertFileExists($ruta);
        $texto = (string) file_get_contents($ruta);

        $this->assertDoesNotMatchRegularExpression('/IMAP_PASSWORD=\S/', $texto);
        $this->assertDoesNotMatchRegularExpression('/MAIL_PASSWORD=\S/', $texto);
        $this->assertStringNotContainsStringIgnoringCase('@gmail.com', $texto);

        // Menciona lo esencial de la operación.
        foreach (['queue:work', 'schedule:work', 'schedule:run', 'Programador de tareas', 'QUEUE_CONNECTION=database', 'APP_URL', 'APP_KEY', 'queue:restart', 'storage/logs'] as $clave) {
            $this->assertStringContainsString($clave, $texto, $clave);
        }
    }
}
