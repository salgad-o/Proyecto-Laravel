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
     *
     * NP-46: el correo se normaliza igual que en el registro y el reset (sin
     * espacios y en minúsculas), así la constancia guarda siempre la misma forma.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('message'))) {
            $this->merge(['message' => str_replace("\r\n", "\n", $this->input('message'))]);
        }

        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // NP-46: el nombre va en la cabecera Reply-To del correo. Un salto de línea
            // (\r, \n) o cualquier carácter de control permitiría inyectar cabeceras
            // (por ejemplo "Bcc: ..."), así que se rechazan.
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'],
            'email' => ['required', 'email', 'max:150'],
            'message' => ['required', 'string', 'max:2000'],
            // NP-43: autorización de tratamiento de datos (Ley 1581 de 2012). NP-46: su
            // constancia (fecha y versión legal) la guarda ControllerContact en contact_consents.
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
            'name.not_regex' => 'El nombre no puede tener saltos de línea ni caracteres de control.',
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
