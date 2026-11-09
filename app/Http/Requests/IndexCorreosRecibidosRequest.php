<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bandeja de correos recibidos (NP-46 paso 7): filtro por estado y página.
 */
class IndexCorreosRecibidosRequest extends FormRequest
{
    /**
     * Pestañas: todos (por defecto) o solo los no leídos.
     */
    public const ESTADOS = ['todos', 'no-leidos'];

    /**
     * Si la validación falla se vuelve a la bandeja, nunca a url()->previous():
     * esa URL sale de la cabecera Referer, que controla el cliente.
     */
    protected $redirectRoute = 'correos.index';

    /**
     * La autorización la hace ReceivedEmailPolicy en el controlador.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Un estado vacío (?estado=) equivale a "todos".
     */
    protected function prepareForValidation(): void
    {
        if ($this->estado === '') {
            $this->merge(['estado' => null]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Lista cerrada: un arreglo o un valor desconocido no pasa.
            'estado' => ['nullable', 'string', Rule::in(self::ESTADOS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'estado' => 'filtro',
            'page' => 'página',
        ];
    }
}
