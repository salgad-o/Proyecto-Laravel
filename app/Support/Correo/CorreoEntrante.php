<?php

namespace App\Support\Correo;

use Carbon\CarbonImmutable;

/**
 * Un correo leído del buzón (NP-46 paso 5). Inmutable y sin dependencias del
 * paquete IMAP: es lo único que el resto de la aplicación ve de un correo.
 *
 * TODO lo que contiene es entrada NO confiable (D9): lo escribió un remitente
 * cualquiera. El texto nunca se renderiza como HTML; quien lo muestre debe
 * escaparlo (Blade {{ }}) o sanearlo (SaneadorConsola).
 */
final readonly class CorreoEntrante
{
    /**
     * Máximo de caracteres del texto: un correo enorme no debe agotar la memoria
     * ni, más adelante, llenar la base de datos.
     */
    public const LIMITE_TEXTO = 100_000;

    public string $messageId;

    public ?string $texto;

    public function __construct(
        string $messageId,
        public string $remitenteCorreo,
        public ?string $remitenteNombre,
        public ?string $asunto,
        ?string $texto,
        public ?CarbonImmutable $recibidoEn,
        public bool $tieneAdjuntos,
    ) {
        // Sin los < > con que viaja en la cabecera.
        $this->messageId = trim($messageId, " \t\r\n<>");
        $this->texto = $texto === null ? null : self::limitar($texto);
    }

    /**
     * Elige el texto del correo: la parte text/plain si existe; si solo hay HTML,
     * lo convierte a texto. El HTML nunca se renderiza.
     */
    public static function textoDe(?string $plano, ?string $html): ?string
    {
        if ($plano !== null && trim($plano) !== '') {
            return self::limitar($plano);
        }

        if ($html !== null && trim($html) !== '') {
            return self::textoDesdeHtml($html);
        }

        return null;
    }

    /**
     * Convierte HTML en texto plano sin interpretarlo: quita <script> y <style> con
     * su contenido, el resto de etiquetas, y decodifica las entidades (&amp; → &).
     * El resultado es TEXTO: puede contener "<" literales y hay que escaparlo al
     * mostrarlo en HTML.
     */
    public static function textoDesdeHtml(string $html): string
    {
        // Recorte previo: las expresiones regulares no deben recorrer megas de HTML.
        $html = mb_substr(mb_scrub($html, 'UTF-8'), 0, self::LIMITE_TEXTO * 4);

        $sinCodigo = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;

        // Saltos de línea donde el HTML los mostraría, para que el texto sea legible.
        $conSaltos = preg_replace('#<br\s*/?>|</(p|div|li|tr|h[1-6])\s*>#i', "\n", $sinCodigo) ?? $sinCodigo;

        $texto = html_entity_decode(strip_tags($conSaltos), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Sin más de una línea en blanco seguida y sin espacios a los lados.
        $texto = preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", $texto)) ?? $texto;

        return self::limitar(trim($texto));
    }

    /**
     * Texto con UTF-8 válido y como máximo LIMITE_TEXTO caracteres.
     */
    public static function limitar(string $texto): string
    {
        return mb_substr(mb_scrub($texto, 'UTF-8'), 0, self::LIMITE_TEXTO);
    }
}
