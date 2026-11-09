<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Password;

/**
 * Envía el enlace de recuperación de contraseña fuera de la petición (NP-46, deuda 5).
 *
 * Motivo: antes, con un correo registrado la petición buscaba al usuario, creaba el
 * token (bcrypt) y hablaba con el SMTP antes de responder; con uno inexistente
 * respondía al instante. Esa diferencia de tiempo permitía enumerar cuentas.
 * Ahora la petición hace siempre lo mismo (encolar este job) y todo el trabajo
 * que depende de si la cuenta existe ocurre aquí, sin que el visitante lo vea.
 *
 * Va cifrado (ShouldBeEncrypted) porque el payload contiene un correo: así no
 * queda en claro en las tablas jobs ni failed_jobs (D6, minimización de datos).
 */
class EnviarEnlaceRecuperacion implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Espera entre reintentos (segundos): 1 min, luego 5 min.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300];

    /**
     * @param  string  $email  Ya normalizado por SendPasswordResetLinkRequest (minúsculas, sin espacios).
     */
    public function __construct(public readonly string $email) {}

    public function handle(): void
    {
        // El estado (RESET_LINK_SENT, INVALID_USER, RESET_THROTTLED...) se ignora a
        // propósito: no se lanza excepción, no se registra el correo en el log y no
        // se hace nada distinto que pueda observarse desde fuera.
        Password::sendResetLink(['email' => $this->email]);
    }
}
