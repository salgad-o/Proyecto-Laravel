<?php

namespace App\Http\Requests;

use App\Models\AuditLog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros de /dashboard/auditoria (NP-20 paso 5, hallazgo 12 de NP-14: antes se
 * validaba con $request->validate() dentro del controlador).
 */
class AuditoriaRequest extends FormRequest
{
    /**
     * La autorización la hace AuditLogPolicy::viewAny en el controlador
     * (y la ruta exige además el rol admin).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Un <select> en "Todos" envía una cadena vacía: se trata como "sin filtro".
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'usuario' => $this->usuario === '' ? null : $this->usuario,
            'accion' => $this->accion === '' ? null : $this->accion,
            'importancia' => $this->importancia === '' ? null : $this->importancia,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'usuario' => ['nullable', 'integer', 'exists:users,id'],
            'accion' => ['nullable', Rule::in(array_keys(AuditLog::ACCIONES))],
            'importancia' => ['nullable', Rule::in(array_keys(AuditLog::NIVELES))],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'usuario' => 'usuario',
            'accion' => 'acción',
            'importancia' => 'importancia',
        ];
    }
}
