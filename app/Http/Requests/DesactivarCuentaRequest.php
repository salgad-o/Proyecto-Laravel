<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * NP-36 paso 6: el usuario desactiva su propia cuenta.
 * La contraseña actual confirma que es el dueño de la cuenta quien lo pide.
 */
class DesactivarCuentaRequest extends FormRequest
{
    /**
     * Bolsa de errores propia, como en el paso 4. En la vista se lee con la
     * directiva error de Blade indicando la bolsa 'desactivar'.
     *
     * @var string
     */
    protected $errorBag = 'desactivar';

    /**
     * La ruta ya exige sesión y cada usuario desactiva solo su propia cuenta.
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
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Escribe tu contraseña actual.',
            'current_password.string' => 'Escribe tu contraseña actual.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
        ];
    }
}
