<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * NP-36 paso 4: cerrar la sesión en los demás dispositivos.
 * La contraseña actual es la confirmación (no hace falta un "¿Seguro?").
 */
class CerrarSesionesRequest extends FormRequest
{
    /**
     * Bolsa de errores propia: la pestaña Seguridad tiene otro formulario con
     * un campo current_password (el de cambiar contraseña) y sus errores no
     * deben mezclarse. En la vista: @error('current_password', 'sesiones').
     *
     * @var string
     */
    protected $errorBag = 'sesiones';

    /**
     * La ruta ya exige sesión y cada usuario cierra solo sus propias sesiones.
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
