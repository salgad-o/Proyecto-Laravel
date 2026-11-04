<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * NP-36 paso 2: el usuario edita su propio perfil (nombre, correo y biografía).
 *
 * Solo valida: la ruta ya exige sesión y cada usuario edita únicamente su
 * propia cuenta ($request->user()), así que no hay a quién más autorizar.
 */
class UpdatePerfilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * - email: minúsculas y sin espacios alrededor, igual que el registro y la
     *   recuperación de contraseña (así "Ana@X.com " cuenta como el mismo correo).
     * - bio: los navegadores envían los saltos como \r\n pero cuentan un solo
     *   carácter; se normaliza a \n (como ComentarioRequest) y se quitan los espacios
     *   de los extremos. Una biografía que queda vacía se guarda como null.
     */
    protected function prepareForValidation(): void
    {
        $datos = [];

        if (is_string($this->input('email'))) {
            $datos['email'] = mb_strtolower(trim($this->input('email')));
        }

        if (is_string($this->input('bio'))) {
            $bio = trim(str_replace("\r\n", "\n", $this->input('bio')));
            $datos['bio'] = $bio === '' ? null : $bio;
        }

        $this->merge($datos);
    }

    /**
     * ¿El correo enviado (ya normalizado) es distinto del actual?
     */
    public function cambiaEmail(): bool
    {
        return $this->input('email') !== $this->user()->email;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => [
                'required', 'email', 'max:150',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
            'bio' => ['nullable', 'string', 'max:300'],
            // Cambiar el correo cambia la forma de recuperar la cuenta: se pide la
            // contraseña actual (regla current_password de Laravel). Si el correo no
            // cambia, el campo se excluye: no se exige ni se valida aunque llegue.
            'current_password' => $this->cambiaEmail()
                ? ['required', 'string', 'current_password']
                : ['exclude'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'email' => 'correo',
            'bio' => 'biografía',
            'current_password' => 'contraseña actual',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe tu nombre.',
            'name.max' => 'El nombre no puede tener más de 100 caracteres.',
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'Escribe un correo válido.',
            'email.max' => 'El correo no puede tener más de 150 caracteres.',
            'email.unique' => 'Ese correo ya está registrado en otra cuenta.',
            'bio.max' => 'La biografía no puede tener más de 300 caracteres.',
            'current_password.required' => 'Escribe tu contraseña actual para cambiar el correo.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
        ];
    }
}
