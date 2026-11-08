<?php

namespace App\Console\Commands;

use App\Contracts\LectorBuzon;
use App\Support\Correo\CorreoEntrante;
use App\Support\Correo\SaneadorConsola;
use Illuminate\Console\Command;
use Throwable;

/**
 * Lista los correos recientes del buzón por IMAP (NP-46 paso 5). SOLO CONSOLA:
 * no hay ninguna ruta web que lo ejecute. Todavía no guarda nada (paso 6).
 *
 * Seguridad:
 * - Lo que llega por correo es entrada NO confiable: se sanea antes de imprimirlo
 *   y el cuerpo nunca se imprime (D9).
 * - Si falla la conexión se muestra un mensaje genérico: la excepción va al log con
 *   report() y nunca a la pantalla (podría incluir el usuario o la respuesta del servidor).
 */
class RecibirCorreos extends Command
{
    protected $signature = 'mail:receive
        {--limite=10 : Número máximo de correos (1 a 50)}
        {--dias=7 : Antigüedad máxima en días (1 a 30)}';

    protected $description = 'Lista los correos recientes del buzón IMAP sin marcarlos como leídos';

    public const LIMITE_MIN = 1;

    public const LIMITE_MAX = 50;

    public const DIAS_MIN = 1;

    public const DIAS_MAX = 30;

    public const MAX_REMITENTE = 150;

    public const MAX_ASUNTO = 150;

    public const ERROR_CONEXION = 'No se pudo conectar al buzón IMAP. Revisa IMAP_* en .env y la conexión.';

    public function handle(LectorBuzon $lector): int
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

        try {
            // Se recorre aquí dentro: si el lector fuera perezoso, sus errores
            // también caerían en este catch.
            $correos = [];
            foreach ($lector->recientes($limite, $dias) as $correo) {
                $correos[] = $correo;
            }
        } catch (Throwable $e) {
            report($e);
            $this->error(self::ERROR_CONEXION);

            return self::FAILURE;
        }

        if ($correos === []) {
            $this->info('No hay correos en el periodo indicado.');

            return self::SUCCESS;
        }

        $this->table(
            ['Fecha', 'Remitente', 'Asunto', '¿Adjuntos?'],
            array_map(fn (CorreoEntrante $correo) => $this->fila($correo), $correos),
        );

        $this->line(count($correos).' correo(s). No se marcó ninguno como leído.');

        return self::SUCCESS;
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
     * Fila de la tabla. El cuerpo (texto) no se imprime nunca.
     *
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
