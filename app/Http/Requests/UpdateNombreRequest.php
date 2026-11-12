<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * NP-36: el usuario cambia su propio nombre desde "Mi perfil".
 * Solo valida 'name': cualquier otro campo enviado no llega a validated().
 */
class UpdateNombreRequest extends FormRequest
{
    /**
     * La ruta ya exige sesión y cada usuario edita solo su propia cuenta.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Sin espacios en los extremos (el middleware TrimStrings ya lo hace; aquí
     * queda explícito para que la regla no dependa de esa configuración).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe tu nombre.',
            'name.string' => 'Escribe tu nombre.',
            'name.max' => 'El nombre no puede tener más de 100 caracteres.',
        ];
    }
}
