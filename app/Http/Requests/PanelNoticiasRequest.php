<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PanelNoticiasRequest extends FormRequest
{
    /**
     * La autorización la hace NoticiaPolicy::viewPanel en el controlador.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'q' => is_string($this->q) ? trim($this->q) : $this->q,
            'estado' => $this->estado === '' ? null : $this->estado,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(['publicada', 'borrador'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'q' => 'búsqueda',
            'estado' => 'estado',
        ];
    }
}
