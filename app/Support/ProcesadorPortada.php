<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * NP-47: convierte la portada subida en un WebP que cabe dentro de 1600×900
 * conservando la proporción (sin recortar).
 *
 * Igual que ProcesadorAvatar, se RECODIFICA en vez de guardar el archivo tal cual:
 * - Se descarta todo lo que no sean píxeles: metadatos EXIF (GPS, modelo de la
 *   cámara, fecha...), comentarios y cualquier contenido extra pegado al archivo
 *   (por ejemplo un "políglota" PNG + <?php ... ?>).
 * - El archivo guardado siempre es un WebP generado por nosotros.
 *
 * Clase sin estado: un solo método estático.
 */
final class ProcesadorPortada
{
    /** Tamaño máximo de la portada final, en píxeles. */
    public const ANCHO_MAX = 1600;

    public const ALTO_MAX = 900;

    /** Calidad del WebP (0-100). */
    private const CALIDAD = 85;

    /** Tipos que se aceptan (los mismos que StoreNoticiaRequest/UpdateNoticiaRequest). */
    private const TIPOS = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * Devuelve los BYTES del WebP encajado en 1600×900.
     *
     * @throws ValidationException si la imagen no se puede leer o procesar.
     */
    public static function procesar(UploadedFile $archivo): string
    {
        $ruta = $archivo->getRealPath();

        // Defensa en profundidad: aunque el FormRequest ya validó el tipo, se vuelve a
        // comprobar con getimagesize(). La @ evita que un archivo dañado genere un
        // warning (Laravel lo convertiría en error 500): el fallo se trata abajo.
        $info = $ruta === false ? false : @getimagesize($ruta);

        if ($info === false || ! in_array($info[2], self::TIPOS, true)) {
            self::fallar();
        }

        $bytes = @file_get_contents($ruta);

        // false si el archivo está dañado o GD no lo soporta (por ejemplo un WebP animado).
        $imagen = $bytes === false || $bytes === '' ? false : @imagecreatefromstring($bytes);
        unset($bytes);

        if ($imagen === false) {
            self::fallar();
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $imagen = self::aplicarOrientacion($imagen, $ruta);
        }

        $resultado = self::encajarYCodificar($imagen);
        unset($imagen);

        return $resultado;
    }

    /**
     * Los móviles guardan la foto "acostada" y anotan en EXIF cómo girarla. Como el
     * EXIF se descarta, hay que aplicar el giro ANTES de escalar o la foto saldría de lado.
     * Si la extensión exif no está instalada, la foto se deja como viene.
     */
    private static function aplicarOrientacion(GdImage $imagen, string $ruta): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $imagen;
        }

        // Un EXIF dañado no debe impedir subir la portada: se ignora.
        $exif = @exif_read_data($ruta);
        $orientacion = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        // imagerotate() gira en sentido contrario a las agujas del reloj.
        $angulo = match ($orientacion) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angulo === 0) {
            return $imagen;
        }

        $girada = imagerotate($imagen, $angulo, 0);

        return $girada === false ? $imagen : $girada;
    }

    /**
     * Escala la imagen para que quepa dentro de 1600×900 sin deformarla ni recortarla.
     * Una imagen más pequeña se deja en su tamaño original (no se amplía) y se
     * recodifica igualmente a WebP.
     */
    private static function encajarYCodificar(GdImage $imagen): string
    {
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);

        $escala = min(1, self::ANCHO_MAX / $ancho, self::ALTO_MAX / $alto);
        $destinoAncho = max(1, (int) round($ancho * $escala));
        $destinoAlto = max(1, (int) round($alto * $escala));

        $destino = imagecreatetruecolor($destinoAncho, $destinoAlto);

        if ($destino === false) {
            self::fallar();
        }

        // Copiar el canal alfa tal cual (sin mezclarlo con el fondo) y guardarlo:
        // así un PNG transparente sigue siendo transparente en el WebP.
        imagealphablending($destino, false);
        imagesavealpha($destino, true);

        $copiado = imagecopyresampled(
            $destino,
            $imagen,
            0,
            0,
            0,
            0,
            $destinoAncho,
            $destinoAlto,
            $ancho,
            $alto,
        );

        ob_start();
        $codificado = $copiado && imagewebp($destino, null, self::CALIDAD);
        $bytes = ob_get_clean();

        // En PHP 8 la imagen es un objeto GdImage: se libera al perder la referencia.
        // No se usa imagedestroy(), que no hace nada desde PHP 8.0 y está obsoleta en 8.5.
        unset($destino);

        if (! $codificado || ! is_string($bytes) || $bytes === '') {
            self::fallar();
        }

        return $bytes;
    }

    /**
     * Error genérico para el usuario: nunca se muestra el mensaje interno de GD.
     */
    private static function fallar(): never
    {
        throw ValidationException::withMessages([
            'cover_image' => 'No pudimos procesar la imagen. Prueba con otra JPG, PNG o WebP.',
        ]);
    }
}
