<?php

namespace App\Http\Requests;

use App\Support\UrlAnterior;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarConsentimientoCookiesRequest extends FormRequest
{
    /**
     * Los visitantes también eligen: no hace falta sesión.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'eleccion' => ['required', 'string', Rule::in(config('legal.consentimiento.valores'))],
        ];
    }

    /**
     * Si la validación falla, Laravel vuelve a url()->previous() (el Referer).
     * Se aplica el mismo filtro que en el controlador para no abrir una
     * redirección a otro sitio por este camino.
     */
    protected function getRedirectUrl(): string
    {
        return UrlAnterior::segura(route('home'));
    }
}
