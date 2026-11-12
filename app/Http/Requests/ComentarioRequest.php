<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ComentarioRequest extends FormRequest
{
    /**
     * La autorización la hace ComentarioPolicy en el controlador.
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
        if (is_string($this->input('body'))) {
            $this->merge(['body' => str_replace("\r\n", "\n", $this->input('body'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return ['body' => 'comentario'];
    }
}
