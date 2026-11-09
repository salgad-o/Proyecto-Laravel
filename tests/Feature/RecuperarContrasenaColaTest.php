<?php

namespace Tests\Feature;

use App\Jobs\EnviarEnlaceRecuperacion;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * NP-46 paso 4 (deuda 5): la petición de "olvidé mi contraseña" solo encola un job,
 * exista o no el correo. Buscar al usuario, crear el token y enviar el correo pasa
 * dentro del job, así que la duración de la petición no revela qué cuentas existen.
 */
class RecuperarContrasenaColaTest extends TestCase
{
    use RefreshDatabase;

    private const MENSAJE_ENVIADO = 'Si el correo está registrado, te enviamos un enlace para restablecer la contraseña.';

    private function pedirEnlace(string $email)
    {
        return $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $email]);
    }

    // ---------- La petición solo encola ----------

    public function test_correo_existente_e_inexistente_encolan_un_job_cada_uno_con_la_misma_respuesta(): void
    {
        Queue::fake();

        User::factory()->create(['email' => 'jugador@nexus.test']);

        $existente = $this->pedirEnlace('jugador@nexus.test');
        Queue::assertPushed(EnviarEnlaceRecuperacion::class, 1);
        Queue::assertPushed(EnviarEnlaceRecuperacion::class, fn (EnviarEnlaceRecuperacion $job) => $job->email === 'jugador@nexus.test');

        $inexistente = $this->pedirEnlace('noexiste@nexus.test');
        Queue::assertPushed(EnviarEnlaceRecuperacion::class, 2);
        Queue::assertPushed(EnviarEnlaceRecuperacion::class, fn (EnviarEnlaceRecuperacion $job) => $job->email === 'noexiste@nexus.test');

        // Respuesta idéntica: mismo código, misma redirección, mismo mensaje.
        $this->assertSame($existente->getStatusCode(), $inexistente->getStatusCode());
        $this->assertSame($existente->headers->get('Location'), $inexistente->headers->get('Location'));
        $existente->assertRedirect(route('password.request'))->assertSessionHas('status', self::MENSAJE_ENVIADO);
        $inexistente->assertRedirect(route('password.request'))->assertSessionHas('status', self::MENSAJE_ENVIADO);
    }

    public function test_el_job_recibe_el_correo_normalizado(): void
    {
        Queue::fake();

        $this->pedirEnlace('  JUGADOR@Nexus.TEST  ')->assertSessionHas('status', self::MENSAJE_ENVIADO);

        Queue::assertPushed(EnviarEnlaceRecuperacion::class, fn (EnviarEnlaceRecuperacion $job) => $job->email === 'jugador@nexus.test');
    }

    public function test_con_un_correo_existente_la_peticion_no_crea_token_ni_envia_nada(): void
    {
        Queue::fake();
        Notification::fake();

        $usuario = User::factory()->create();

        $this->pedirEnlace($usuario->email)->assertSessionHas('status', self::MENSAJE_ENVIADO);

        // El trabajo que depende de que la cuenta exista no ocurre en la petición.
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
        Queue::assertPushed(EnviarEnlaceRecuperacion::class, 1);
    }

    public function test_un_correo_invalido_no_encola_nada(): void
    {
        Queue::fake();

        $this->pedirEnlace('no-es-correo')->assertSessionHasErrors('email');
        $this->pedirEnlace('')->assertSessionHasErrors('email');

        Queue::assertNothingPushed();
    }

    public function test_el_throttle_de_la_ruta_sigue_en_5_por_minuto(): void
    {
        Queue::fake();

        for ($i = 1; $i <= 5; $i++) {
            $this->pedirEnlace('jugador@nexus.test')->assertStatus(302);
        }

        $this->pedirEnlace('jugador@nexus.test')->assertStatus(429);

        Queue::assertPushed(EnviarEnlaceRecuperacion::class, 5);
    }

    // ---------- El job ----------

    public function test_el_job_con_un_correo_existente_crea_el_token_y_envia_la_notificacion(): void
    {
        Notification::fake();

        $usuario = User::factory()->create();

        (new EnviarEnlaceRecuperacion($usuario->email))->handle();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $usuario->email]);
        Notification::assertSentTo($usuario, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_el_job_con_un_correo_inexistente_no_falla_no_envia_y_no_registra_nada_en_el_log(): void
    {
        Notification::fake();

        $registros = [];
        Event::listen(MessageLogged::class, function (MessageLogged $evento) use (&$registros) {
            $registros[] = $evento->message;
        });

        // No debe lanzar excepción (si lo hiciera, el test fallaría aquí).
        (new EnviarEnlaceRecuperacion('noexiste@nexus.test'))->handle();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertSame([], $registros);
    }

    public function test_el_job_ignora_el_limite_del_broker_sin_fallar(): void
    {
        Notification::fake();

        $usuario = User::factory()->create();

        // El broker limita a un enlace por minuto (auth.passwords.users.throttle):
        // el segundo job recibe RESET_THROTTLED y termina en silencio.
        (new EnviarEnlaceRecuperacion($usuario->email))->handle();
        (new EnviarEnlaceRecuperacion($usuario->email))->handle();

        Notification::assertSentToTimes($usuario, ResetPassword::class, 1);
    }

    public function test_con_la_cola_sync_el_flujo_completo_envia_el_enlace(): void
    {
        // phpunit.xml usa QUEUE_CONNECTION=sync: el job se ejecuta al despacharlo.
        config(['queue.default' => 'sync']);
        Notification::fake();

        $usuario = User::factory()->create();

        $this->pedirEnlace($usuario->email)->assertSessionHas('status', self::MENSAJE_ENVIADO);

        Notification::assertSentTo($usuario, ResetPassword::class);
    }

    public function test_el_job_va_en_cola_cifrado_y_solo_recibe_el_correo(): void
    {
        $job = new EnviarEnlaceRecuperacion('jugador@nexus.test');

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $this->assertSame(3, $job->tries);
        $this->assertNotEmpty($job->backoff);

        $parametros = (new \ReflectionClass(EnviarEnlaceRecuperacion::class))->getConstructor()->getParameters();
        $this->assertCount(1, $parametros);
        $this->assertSame('email', $parametros[0]->getName());
        $this->assertSame('string', (string) $parametros[0]->getType());
    }

    public function test_el_job_en_la_tabla_jobs_no_guarda_el_correo_en_claro(): void
    {
        config(['queue.default' => 'database']);

        $this->pedirEnlace('secreto.jugador@nexus.test')->assertSessionHas('status', self::MENSAJE_ENVIADO);

        $this->assertSame(1, DB::table('jobs')->count());
        $payload = DB::table('jobs')->value('payload');
        $datosJob = json_decode($payload, true);

        $this->assertSame(EnviarEnlaceRecuperacion::class, $datosJob['data']['commandName']);
        $this->assertStringNotContainsString('secreto', $payload);
        $this->assertStringNotContainsString('nexus.test', $payload);

        // Cifrado con APP_KEY: al descifrar, el correo sigue intacto.
        $this->assertStringContainsString('secreto.jugador@nexus.test', Crypt::decryptString($datosJob['data']['command']));
    }

    // ---------- Reset: deuda 17 ----------

    public function test_el_reset_cierra_las_sesiones_con_sesiones_usuario(): void
    {
        $codigo = file_get_contents(app_path('Http/Controllers/PasswordResetController.php'));

        $this->assertStringContainsString('SesionesUsuario::cerrarTodas($user)', $codigo);
        $this->assertStringNotContainsString('DB::table', $codigo);
        $this->assertStringNotContainsString('use Illuminate\Support\Facades\DB;', $codigo);
        $this->assertStringNotContainsString('Password::sendResetLink', $codigo);
    }
}
