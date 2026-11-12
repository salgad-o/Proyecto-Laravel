<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\VuelveALaBandeja;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Marcar un correo recibido como leído (leido=1) o no leído (leido=0) (NP-46 paso 7).
 * El filtro y la página de la bandeja viajan como campos ocultos solo para
 * conservar el enlace "Volver"; si no son válidos se descartan (VuelveALaBandeja).
 */
class MarcarCorreoRecibidoRequest extends FormRequest
{
    use VuelveALaBandeja;

    /**
     * La autorización la hace ReceivedEmailPolicy::update en el controlador.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Si falla, vuelve al detalle de ESE correo (nunca a url()->previous(), que
     * sale de la cabecera Referer).
     */
    protected function getRedirectUrl(): string
    {
        return route('correos.show', ['correo' => $this->route('correo'), ...$this->parametrosDeVuelta()]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'leido' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'leido.required' => 'Indica si el correo queda leído o no leído.',
            'leido.boolean' => 'El estado del correo no es válido.',
        ];
    }

    /**
     * true = leído, false = no leído (ya validado como boolean).
     */
    public function leido(): bool
    {
        return filter_var($this->validated('leido'), FILTER_VALIDATE_BOOLEAN);
    }
}
