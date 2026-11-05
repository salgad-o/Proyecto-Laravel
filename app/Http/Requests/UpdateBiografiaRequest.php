<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * NP-36: biografía corta del autor. Se muestra bajo su nombre en las noticias,
 * por eso solo la tienen quienes publican (administradores y editores).
 * Solo valida 'bio': cualquier otro campo enviado no llega a validated().
 */
class UpdateBiografiaRequest extends FormRequest
{
    /**
     * Un lector no tiene biografía: recibe 403.
     */
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin', 'editor');
    }

    /**
     * Los navegadores envían los saltos de línea como \r\n pero cuentan un solo
     * carácter: se normaliza a \n (como ComentarioRequest) para que el servidor
     * cuente igual que el contador, y se quitan los espacios de los extremos.
     * Una biografía que queda vacía se guarda como null.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('bio'))) {
            $bio = trim(str_replace("\r\n", "\n", $this->input('bio')));
            $this->merge(['bio' => $bio === '' ? null : $bio]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bio' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bio.string' => 'La biografía debe ser texto.',
            'bio.max' => 'La biografía no puede tener más de 300 caracteres.',
        ];
    }
}
