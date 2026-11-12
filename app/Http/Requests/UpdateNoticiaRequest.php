<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNoticiaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'excerpt' => ['nullable', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:10000'],
            'status' => ['required', Rule::in(['borrador', 'publicada'])],

            // Portada: lista cerrada de tipos (sin GIF ni SVG), tamaño y dimensiones.
            // mimes deduce la extensión del CONTENIDO; mimetypes lo confirma con fileinfo.
            'cover_image' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048',
                'dimensions:min_width=400,min_height=200,max_width=4000,max_height=4000',
            ],
            'quitar_portada' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cover_image.file' => 'La portada debe ser un archivo.',
            'cover_image.mimes' => 'La portada debe ser una imagen JPG, PNG o WebP.',
            'cover_image.mimetypes' => 'La portada debe ser una imagen JPG, PNG o WebP.',
            'cover_image.max' => 'La portada debe pesar como máximo 2 MB.',
            'cover_image.dimensions' => 'La portada debe medir entre 400 y 4000 píxeles de ancho y entre 200 y 4000 de alto.',
            'cover_image.uploaded' => 'No pudimos recibir la portada. Debe pesar como máximo 2 MB.',
            'quitar_portada.boolean' => 'El valor para quitar la portada no es válido.',
        ];
    }
}
