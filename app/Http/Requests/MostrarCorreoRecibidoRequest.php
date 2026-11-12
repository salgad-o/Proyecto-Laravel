<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\VuelveALaBandeja;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Detalle de un correo recibido (NP-46 paso 7). No hay campos obligatorios: solo
 * lee el filtro y la página para el enlace "Volver" (ver VuelveALaBandeja), que
 * descarta los valores inválidos en lugar de fallar.
 */
class MostrarCorreoRecibidoRequest extends FormRequest
{
    use VuelveALaBandeja;

    /**
     * La autorización la hace ReceivedEmailPolicy::view en el controlador.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
