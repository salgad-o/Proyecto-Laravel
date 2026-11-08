<?php

namespace App\Support\Correo;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Prepara un dato que llegó por correo para imprimirlo en la terminal (D9).
 *
 * Un asunto o un remitente son entrada NO confiable. Sin sanear podrían:
 * - mover el cursor, borrar la pantalla o cambiar colores (secuencias ANSI/ESC),
 * - crear enlaces ocultos en la terminal (OSC 8),
 * - partir la tabla con saltos de línea o hacer sonar la campana (BEL),
 * - invertir el sentido del texto para disfrazarlo (caracteres bidi de Unicode),
 * - usar etiquetas de estilo de Symfony Console (<fg=red>, <href=...>).
 */
final class SaneadorConsola
{
    /**
     * Una sola línea, sin secuencias de control y como máximo $maximo caracteres.
     */
    public static function linea(?string $valor, int $maximo): string
    {
        if ($valor === null) {
            return '';
        }

        $texto = mb_scrub($valor, 'UTF-8');

        // 1) Secuencias de escape completas: CSI (ESC [ ... letra), OSC (ESC ] ... BEL o
        //    ESC \) y escapes de dos caracteres (ESC + uno). Primero las secuencias, para
        //    que no quede su "cola" visible (por ejemplo "[31m").
        $texto = preg_replace(
            [
                '/\x1B\[[0-?]*[ -\/]*[@-~]/u',
                '/\x1B\][^\x07\x1B]*(?:\x07|\x1B\\\\)?/u',
                '/\x1B[@-_]/u',
            ],
            '',
            $texto
        ) ?? '';

        // 2) Saltos de línea y tabuladores → espacio (todo en una línea).
        $texto = preg_replace('/[\t\n\r]+/u', ' ', $texto) ?? '';

        // 3) Resto de controles: C0 (incluido ESC suelto), DEL, C1 (U+0080–U+009F,
        //    donde U+009B también es un CSI) y los de dirección bidi de Unicode.
        $texto = preg_replace('/[\x00-\x1F\x7F\x{80}-\x{9F}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $texto) ?? '';

        $texto = trim(preg_replace('/ {2,}/', ' ', $texto) ?? '');

        if (mb_strlen($texto) > $maximo) {
            $texto = rtrim(mb_substr($texto, 0, $maximo - 1)).'…';
        }

        // 4) Al final: que Symfony Console no interprete "<...>" como estilos.
        return OutputFormatter::escape($texto);
    }
}
