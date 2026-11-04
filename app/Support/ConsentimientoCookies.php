<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Única fuente de verdad para leer la elección del banner de cookies (NP-43).
 *
 * La cookie va CIFRADA: no está en las excepciones de EncryptCookies. Si alguien
 * la edita a mano en el navegador, Laravel no puede descifrarla, llega como null
 * y el banner vuelve a aparecer. Aun así se compara con la lista de valores
 * permitidos, por si llegara un valor cifrado válido pero inesperado.
 */
class ConsentimientoCookies
{
    /**
     * Valor elegido ('todas' o 'necesarias') o null si no hay una elección válida.
     */
    public static function actual(Request $request): ?string
    {
        $valor = $request->cookie(config('legal.consentimiento.cookie'));

        return in_array($valor, config('legal.consentimiento.valores'), true) ? $valor : null;
    }

    public static function haElegido(Request $request): bool
    {
        return self::actual($request) !== null;
    }

    /**
     * Solo con 'todas' se pueden guardar cookies de preferencias (paso 2b).
     */
    public static function permitePreferencias(Request $request): bool
    {
        return self::actual($request) === 'todas';
    }
}
