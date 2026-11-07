<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexUsuariosRequest extends FormRequest
{
    /**
     * Pestañas del listado (NP-20 paso 6a). Los roles coinciden con roles.name;
     * "inactivos" filtra por is_active = false.
     */
    public const VISTAS = ['todos', 'admin', 'editor', 'lector', 'inactivos'];

    /**
     * La autorización la hace UserPolicy en el controlador.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Una pestaña vacía (?vista=) equivale a "todos".
     */
    protected function prepareForValidation(): void
    {
        if ($this->vista === '') {
            $this->merge(['vista' => null]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'buscar' => ['nullable', 'string', 'max:100'],
            // Lista cerrada: un arreglo o un valor desconocido no pasa.
            'vista' => ['nullable', 'string', Rule::in(self::VISTAS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'buscar' => 'búsqueda',
            'vista' => 'pestaña',
        ];
    }
}
