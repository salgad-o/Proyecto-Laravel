<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * NP-36 paso 3: el usuario cambia su propia contraseña desde "Mi perfil > Seguridad".
 */
class UpdateContrasenaRequest extends FormRequest
{
    /**
     * La ruta ya exige sesión y cada usuario cambia solo su propia contraseña.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Misma política que el registro y la recuperación: Password::defaults()
     * (12+ caracteres con mayúsculas, minúsculas, números y símbolos).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => [
                'required', 'string', 'confirmed',
                Password::defaults(),
                'different:current_password',
            ],
        ];
    }

    /**
     * La app no tiene traducciones de validación: los mensajes van aquí.
     * Los fallos de la regla Password se llaman "password.mixed", "password.numbers"...,
     * por eso sus claves llevan el nombre del campo delante ("password.password.mixed").
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Escribe tu contraseña actual.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'password.required' => 'Escribe la nueva contraseña.',
            'password.string' => 'Escribe la nueva contraseña.',
            'password.confirmed' => 'La confirmación no coincide.',
            'password.different' => 'La nueva contraseña debe ser distinta de la actual.',
            'password.min' => 'La nueva contraseña debe tener al menos 12 caracteres.',
            'password.password.mixed' => 'La nueva contraseña debe tener mayúsculas y minúsculas.',
            'password.password.letters' => 'La nueva contraseña debe tener al menos una letra.',
            'password.password.numbers' => 'La nueva contraseña debe tener al menos un número.',
            'password.password.symbols' => 'La nueva contraseña debe tener al menos un símbolo.',
            'password.password.uncompromised' => 'Esa contraseña apareció en una filtración de datos. Elige otra.',
        ];
    }
}
