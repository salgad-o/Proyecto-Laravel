<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Listado de moderación de comentarios (NP-20 paso 6b). Solo valida la página:
 * así ?page=abc no se trata en silencio como la página 1.
 */
class IndexComentariosRequest extends FormRequest
{
    /**
     * La autorización la hace ComentarioPolicy::viewAny en el controlador.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return ['page' => 'página'];
    }
}
