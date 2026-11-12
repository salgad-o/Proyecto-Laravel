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
 *
 * La limpieza es la misma que al guardar (NormalizadorCorreo::unaLinea); aquí se
 * añade el truncado con "…" y el escapado propio de la consola.
 */
final class SaneadorConsola
{
    /**
     * Una sola línea, sin secuencias de control y como máximo $maximo caracteres.
     */
    public static function linea(?string $valor, int $maximo): string
    {
        $texto = NormalizadorCorreo::unaLinea($valor, PHP_INT_MAX);

        if (mb_strlen($texto) > $maximo) {
            $texto = rtrim(mb_substr($texto, 0, $maximo - 1)).'…';
        }

        // Al final: que Symfony Console no interprete "<...>" como estilos.
        return OutputFormatter::escape($texto);
    }
}
