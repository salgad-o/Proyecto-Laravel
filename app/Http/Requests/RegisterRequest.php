<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * El registro es público (la ruta ya está bajo el middleware 'guest').
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Las reglas de name, email y password son las que estaban en AuthController@register.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => [
                'required', 'confirmed',
                Password::defaults(),
            ],
            // NP-43: una sola casilla obligatoria para los Términos de uso y la Política de privacidad.
            'acepto_legal' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'acepto_legal.accepted' => 'Debes aceptar los Términos de uso y la Política de privacidad para crear tu cuenta.',
        ];
    }
}
