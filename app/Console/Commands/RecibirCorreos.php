<?php

namespace App\Console\Commands;

use App\Contracts\LectorBuzon;
use App\Services\Correo\GuardarCorreosRecibidos;
use App\Support\Correo\CorreoEntrante;
use App\Support\Correo\ResultadoLectura;
use App\Support\Correo\SaneadorConsola;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lee los correos recientes del buzón por IMAP y los guarda en received_emails
 * (NP-46 pasos 5 y 6). SOLO CONSOLA: no hay ninguna ruta web que lo ejecute.
 *
 * Seguridad:
 * - Lo que llega por correo es entrada NO confiable: se sanea antes de imprimirlo
 *   y el cuerpo nunca se imprime (D9).
 * - Si falla la conexión se muestra un mensaje genérico: la excepción va al log con
 *   report() y nunca a la pantalla (podría incluir el usuario o la respuesta del servidor).
 * - Guardar es idempotente (GuardarCorreosRecibidos): ejecutarlo varias veces no duplica.
 */
class RecibirCorreos extends Command
{
    protected $signature = 'mail:receive
        {--limite=10 : Número máximo de correos (1 a 50)}
        {--dias=7 : Antigüedad máxima en días (1 a 30)}
        {--sin-guardar : Solo lista los correos, sin guardarlos en la base de datos}';

    protected $description = 'Lee los correos recientes del buzón IMAP (sin marcarlos como leídos) y los guarda';

    public const LIMITE_MIN = 1;

    public const LIMITE_MAX = 50;

    public const DIAS_MIN = 1;

    public const DIAS_MAX = 30;

    public const MAX_REMITENTE = 150;

    public const MAX_ASUNTO = 150;

    public const ERROR_CONEXION = 'No se pudo conectar al buzón IMAP. Revisa IMAP_* en .env y la conexión.';

    public const ERROR_GUARDAR = 'No se pudieron guardar los correos en la base de datos. Revisa el log.';

    public const AVISO_OMITIDOS = 'mail:receive omitió mensajes que no se pudieron leer.';

    public function handle(LectorBuzon $lector, GuardarCorreosRecibidos $guardar): int
    {
        // Validar ANTES de conectar: con opciones inválidas no se toca el buzón.
        $limite = $this->entero('limite', self::LIMITE_MIN, self::LIMITE_MAX);
        $dias = $this->entero('dias', self::DIAS_MIN, self::DIAS_MAX);

        if ($limite === null) {
            $this->error('--limite debe ser un número entero entre '.self::LIMITE_MIN.' y '.self::LIMITE_MAX.'.');

            return self::FAILURE;
        }

        if ($dias === null) {
            $this->error('--dias debe ser un número entero entre '.self::DIAS_MIN.' y '.self::DIAS_MAX.'.');

            return self::FAILURE;
        }

        // 1) Leer. Si falla, no se guarda nada: el guardado va después.
        try {
            $lectura = $lector->recientes($limite, $dias);
        } catch (Throwable $e) {
            report($e);
            $this->error(self::ERROR_CONEXION);

            return self::FAILURE;
        }

        $this->mostrar($lectura);

        if ($lectura->omitidos > 0) {
            // Solo el número: nada de asuntos, remitentes ni Message-ID (datos personales).
            Log::warning(self::AVISO_OMITIDOS, ['omitidos' => $lectura->omitidos]);
        }

        if ($this->option('sin-guardar')) {
            $this->line('Leídos: '.count($lectura->correos).' · Omitidos: '.$lectura->omitidos.' · No se guardó nada (--sin-guardar).');

            return self::SUCCESS;
        }

        // 2) Guardar (idempotente).
        try {
            $resumen = $guardar->guardar($lectura);
        } catch (Throwable $e) {
            report($e);
            $this->error(self::ERROR_GUARDAR);

            return self::FAILURE;
        }

        $this->line("Nuevos: {$resumen->nuevos} · Ya guardados: {$resumen->existentes} · Omitidos: {$resumen->omitidos}");

        return self::SUCCESS;
    }

    /**
     * Tabla saneada (D9). El cuerpo (texto) no se imprime nunca.
     */
    private function mostrar(ResultadoLectura $lectura): void
    {
        if ($lectura->correos === []) {
            $this->info('No hay correos en el periodo indicado.');

            return;
        }

        $this->table(
            ['Fecha', 'Remitente', 'Asunto', '¿Adjuntos?'],
            array_map(fn (CorreoEntrante $correo) => $this->fila($correo), $lectura->correos),
        );

        $this->line(count($lectura->correos).' correo(s). No se marcó ninguno como leído.');
    }

    /**
     * Opción entera dentro de un rango, o null si no es válida ("abc", "1.5", "0"...).
     */
    private function entero(string $opcion, int $min, int $max): ?int
    {
        $valor = $this->option($opcion);

        if (! is_string($valor) || preg_match('/^\d+$/', $valor) !== 1) {
            return null;
        }

        $numero = (int) $valor;

        return $numero >= $min && $numero <= $max ? $numero : null;
    }

    /**
     * @return list<string>
     */
    private function fila(CorreoEntrante $correo): array
    {
        $remitente = $correo->remitenteNombre !== null
            ? $correo->remitenteNombre.' <'.$correo->remitenteCorreo.'>'
            : $correo->remitenteCorreo;

        return [
            $correo->recibidoEn?->setTimezone(config('app.timezone'))->format('Y-m-d H:i') ?? '(sin fecha)',
            SaneadorConsola::linea($remitente, self::MAX_REMITENTE),
            SaneadorConsola::linea($correo->asunto ?? '(sin asunto)', self::MAX_ASUNTO),
            $correo->tieneAdjuntos ? 'Sí' : 'No',
        ];
    }
}
