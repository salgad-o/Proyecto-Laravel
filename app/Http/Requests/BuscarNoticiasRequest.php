<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BuscarNoticiasRequest extends FormRequest
{
    /**
     * Es una página pública: cualquier visitante puede buscar.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Si la validación falla, volver al listado (no a una URL anterior rara).
     */
    protected $redirectRoute = 'noticias.index';

    /**
     * Quita espacios al término y convierte el vacío en null.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->q)) {
            $q = trim($this->q);

            $this->merge([
                'q' => $q === '' ? null : $q,
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
