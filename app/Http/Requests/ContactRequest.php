<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ContactRequest extends FormRequest
{
    /**
     * El formulario de contacto es público; la ruta lo limita con throttle.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Si la validación falla, volver siempre a /contact. Por defecto Laravel vuelve
     * a url()->previous(), que sale de la cabecera Referer (la controla el cliente):
     * con un Referer manipulado sería una redirección abierta a otro sitio.
     */
    protected $redirectRoute = 'contact';

    /**
     * Los navegadores envían los saltos de línea como \r\n pero cuentan un solo
     * carácter: se normaliza para que el servidor cuente igual que el contador.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('message'))) {
            $this->merge(['message' => str_replace("\r\n", "\n", $this->input('message'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'message' => ['required', 'string', 'max:2000'],
            // NP-43: autorización de tratamiento de datos (Ley 1581 de 2012). No se guarda:
            // el formulario todavía no almacena ni envía nada.
            'acepto_privacidad' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe tu nombre.',
            'name.string' => 'El nombre debe ser texto.',
            'name.max' => 'El nombre no puede tener más de 100 caracteres.',
            'email.required' => 'Escribe tu correo electrónico.',
            'email.email' => 'Escribe un correo electrónico válido.',
            'email.max' => 'El correo no puede tener más de 150 caracteres.',
            'message.required' => 'Escribe tu mensaje.',
            'message.string' => 'El mensaje debe ser texto.',
            'message.max' => 'El mensaje no puede tener más de 2000 caracteres.',
            'acepto_privacidad.accepted' => 'Debes autorizar el tratamiento de tus datos para enviar el mensaje.',
        ];
    }
}
