<?php

namespace App\Support\Correo;

use Carbon\CarbonImmutable;

/**
 * Deja un CorreoEntrante listo para guardarlo en received_emails (NP-46 paso 6).
 *
 * El correo es ENTRADA NO CONFIABLE. Aquí no se escapa nada (eso se hace al
 * mostrarlo, con {{ }}): solo se quitan caracteres que no deben llegar a la base
 * de datos y se garantiza que cada valor cabe en su columna.
 */
final class NormalizadorCorreo
{
    public const MAX_MESSAGE_ID = 255;

    public const MAX_FROM_EMAIL = 255;

    public const MAX_FROM_NAME = 150;

    public const MAX_SUBJECT = 255;

    /**
     * C0 (incluidos \r \n \t y ESC), DEL, C1 (U+0080–U+009F) y marcas de dirección
     * de Unicode (LRM/RLM, U+202A–U+202E, U+2066–U+2069).
     */
    private const CONTROLES_Y_BIDI = '/[\x00-\x1F\x7F\x{80}-\x{9F}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /**
     * Columnas de received_emails (sin is_read ni read_at, que no se asignan aquí).
     *
     * @return array{message_id: string, from_email: string, from_name: ?string, subject: ?string, body: ?string, received_at: ?CarbonImmutable, has_attachments: bool}
     */
    public static function paraGuardar(CorreoEntrante $correo): array
    {
        $nombre = self::unaLinea($correo->remitenteNombre, self::MAX_FROM_NAME);
        $asunto = self::unaLinea($correo->asunto, self::MAX_SUBJECT);

        return [
            'message_id' => self::messageId($correo->messageId),
            'from_email' => self::correo($correo->remitenteCorreo),
            'from_name' => $nombre === '' ? null : $nombre,
            'subject' => $asunto === '' ? null : $asunto,
            'body' => self::cuerpo($correo->texto),
            // La aplicación trabaja en UTC (config/app.php); NP-40 decidirá cómo mostrarla.
            'received_at' => $correo->recibidoEn?->utc(),
            'has_attachments' => $correo->tieneAdjuntos,
        ];
    }

    /**
     * Message-ID listo para la columna UNIQUE. Lo controla el remitente: si viene
     * vacío, es demasiado largo o trae espacios o caracteres de control, se guarda
     * en su lugar 'sha256:' + hash del valor original (estable entre ejecuciones,
     * cabe en la columna y no rompe el índice).
     */
    public static function messageId(string $original): string
    {
        $limpio = trim($original);
        $limpio = trim($limpio, '<>');

        $invalido = $limpio === ''
            || mb_strlen($limpio) > self::MAX_MESSAGE_ID
            || ! mb_check_encoding($limpio, 'UTF-8')
            // Espacios (\s y Unicode \p{Z}), controles C0/C1 (\p{Cc}) y de formato
            // invisibles como las marcas bidi (\p{Cf}).
            || preg_match('/[\s\p{Z}\p{Cc}\p{Cf}]/u', $limpio) === 1;

        return $invalido ? 'sha256:'.hash('sha256', $original) : $limpio;
    }

    /**
     * Correo del remitente: sin espacios a los lados, en minúsculas y como máximo
     * 255 caracteres. NO se descarta aunque no sea un correo válido: solo se
     * mostrará escapado. Se quitan los caracteres de control.
     */
    public static function correo(string $valor): string
    {
        $valor = preg_replace(self::CONTROLES_Y_BIDI, '', mb_scrub($valor, 'UTF-8')) ?? '';

        return mb_substr(mb_strtolower(trim($valor)), 0, self::MAX_FROM_EMAIL);
    }

    /**
     * Texto de una sola línea (asunto, nombre): sin secuencias ANSI completas, sin
     * controles (\r \n \t pasan a espacio), sin C1 ni marcas bidi, con los espacios
     * colapsados y como máximo $maximo caracteres. '' si no queda nada.
     */
    public static function unaLinea(?string $valor, int $maximo): string
    {
        if ($valor === null) {
            return '';
        }

        $texto = mb_scrub($valor, 'UTF-8');

        // Secuencias de escape completas (CSI, OSC y de dos caracteres): primero, para
        // que no quede su "cola" visible (por ejemplo "[31m").
        $texto = preg_replace(
            ['/\x1B\[[0-?]*[ -\/]*[@-~]/u', '/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)?/u', '/\x1B[@-_]/u'],
            '',
            $texto
        ) ?? '';

        $texto = preg_replace('/[\t\n\r]+/u', ' ', $texto) ?? '';
        $texto = preg_replace(self::CONTROLES_Y_BIDI, '', $texto) ?? '';
        $texto = trim(preg_replace('/\s{2,}/u', ' ', $texto) ?? '');

        return mb_substr($texto, 0, $maximo);
    }

    /**
     * Cuerpo en texto plano: \r\n y \r → \n, sin \0 ni otros caracteres de control
     * (salvo \n y \t). No se toca nada más: "<script>" queda como texto literal.
     */
    public static function cuerpo(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }

        $texto = str_replace(["\r\n", "\r"], "\n", mb_scrub($texto, 'UTF-8'));
        $texto = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $texto) ?? '';

        // Defensa extra: CorreoEntrante ya lo limita, pero la columna no debe crecer sin límite.
        $texto = mb_substr($texto, 0, CorreoEntrante::LIMITE_TEXTO);

        return trim($texto) === '' ? null : $texto;
    }
}
