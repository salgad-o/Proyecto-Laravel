<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * NP-39: foto de perfil. Cualquier usuario registrado puede tener foto (la ruta ya
 * exige sesión). Solo valida 'avatar': cualquier otro campo enviado no llega a validated().
 *
 * Esta validación es la PRIMERA barrera. La segunda es ProcesadorAvatar, que vuelve a
 * leer la imagen con GD y la recodifica a WebP: lo que se guarda nunca es el archivo subido.
 */
class UpdateAvatarRequest extends FormRequest
{
    /**
     * Bolsa de errores propia: la página del perfil tiene varios formularios y así
     * el error de la foto se muestra solo junto al formulario de la foto.
     *
     * @var string
     */
    protected $errorBag = 'avatar';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * - mimes: la extensión que DEDUCE Laravel del contenido (no la del nombre).
     * - mimetypes: el tipo MIME detectado por fileinfo con los bytes del archivo.
     * Se usan las dos con una lista cerrada en vez de 'image', que también acepta
     * gif, bmp y svg (un SVG puede llevar <script>).
     * - dimensions: además de la regla de tamaño, evita imágenes enormes en píxeles
     *   que agotarían la memoria al descomprimirlas con GD.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048',
                'dimensions:min_width=100,min_height=100,max_width=4000,max_height=4000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'Elige una imagen para tu foto de perfil.',
            // PHP rechazó el archivo antes de llegar a Laravel (upload_max_filesize / post_max_size).
            'avatar.uploaded' => 'No pudimos recibir la imagen. Debe pesar como máximo 2 MB.',
            'avatar.file' => 'La foto de perfil debe ser un archivo.',
            'avatar.mimes' => 'La foto de perfil debe ser una imagen JPG, PNG o WebP.',
            'avatar.mimetypes' => 'La foto de perfil debe ser una imagen JPG, PNG o WebP.',
            'avatar.max' => 'La foto de perfil debe pesar como máximo 2 MB.',
            'avatar.dimensions' => 'La foto de perfil debe medir entre 100 y 4000 píxeles de ancho y de alto.',
        ];
    }
}
