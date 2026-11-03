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
            'name'    => ['required', 'string', 'max:100'],
            'email'   => ['required', 'email', 'max:150'],
            'message' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required'    => 'Escribe tu nombre.',
            'name.string'      => 'El nombre debe ser texto.',
            'name.max'         => 'El nombre no puede tener más de 100 caracteres.',
            'email.required'   => 'Escribe tu correo electrónico.',
            'email.email'      => 'Escribe un correo electrónico válido.',
            'email.max'        => 'El correo no puede tener más de 150 caracteres.',
            'message.required' => 'Escribe tu mensaje.',
            'message.string'   => 'El mensaje debe ser texto.',
            'message.max'      => 'El mensaje no puede tener más de 2000 caracteres.',
        ];
    }
}
