<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * NP-39: convierte la foto subida en un WebP cuadrado de 256×256.
 *
 * Por qué se RECODIFICA en vez de guardar el archivo tal cual:
 * - Se descarta todo lo que no sean píxeles: metadatos EXIF (GPS, modelo de la
 *   cámara, fecha...), comentarios y cualquier contenido extra pegado al archivo
 *   (por ejemplo un "políglota" PNG + <?php ... ?>).
 * - El archivo guardado siempre es un WebP generado por nosotros, con tamaño fijo.
 *
 * Clase sin estado: un solo método estático.
 */
final class ProcesadorAvatar
{
    /** Lado del cuadrado final, en píxeles. */
    public const LADO = 256;

    /** Calidad del WebP (0-100). */
    private const CALIDAD = 85;

    /** Tipos que se aceptan (los mismos que UpdateAvatarRequest). */
    private const TIPOS = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * Devuelve los BYTES del WebP de 256×256.
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

        $resultado = self::recortarYCodificar($imagen);
        unset($imagen);

        return $resultado;
    }

    /**
     * Los móviles guardan la foto "acostada" y anotan en EXIF cómo girarla. Como el
     * EXIF se descarta, hay que aplicar el giro ANTES de recortar o la foto saldría de lado.
     * Si la extensión exif no está instalada, la foto se deja como viene.
     */
    private static function aplicarOrientacion(GdImage $imagen, string $ruta): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $imagen;
        }

        // Un EXIF dañado no debe impedir subir la foto: se ignora.
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
     * Recorte cuadrado centrado, reducción a 256×256 y codificación WebP.
     */
    private static function recortarYCodificar(GdImage $imagen): string
    {
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $lado = min($ancho, $alto);
        $x = intdiv($ancho - $lado, 2);
        $y = intdiv($alto - $lado, 2);

        $destino = imagecreatetruecolor(self::LADO, self::LADO);

        if ($destino === false) {
            self::fallar();
        }

        // Copiar el canal alfa tal cual (sin mezclarlo con el fondo) y guardarlo:
        // así un PNG transparente sigue siendo transparente en el WebP.
        imagealphablending($destino, false);
        imagesavealpha($destino, true);

        $copiado = imagecopyresampled($destino, $imagen, 0, 0, $x, $y, self::LADO, self::LADO, $lado, $lado);

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
            'avatar' => 'No pudimos procesar la imagen. Prueba con otra JPG, PNG o WebP.',
        ])->errorBag('avatar');
    }
}
