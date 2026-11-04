<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuardarConsentimientoCookiesRequest;
use App\Support\UrlAnterior;
use Illuminate\Support\Facades\Cookie;

/**
 * Guarda la elección del banner de cookies (NP-43 paso 2).
 *
 * Solo se escribe una cookie: no se guarda nada en la base de datos ni en la
 * auditoría, porque no es un dato personal ni una acción de un usuario
 * identificado (los visitantes también eligen).
 */
class ConsentimientoCookiesController extends Controller
{
    public function store(GuardarConsentimientoCookiesRequest $request)
    {
        $eleccion = $request->validated('eleccion');

        $cookie = Cookie::make(
            config('legal.consentimiento.cookie'),
            $eleccion,
            config('legal.consentimiento.minutos'),
            '/',
            null,
            secure: (bool) config('session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );

        // Petición del JS (fetch con Accept: application/json).
        if ($request->expectsJson()) {
            return response()->json(['eleccion' => $eleccion])->withCookie($cookie);
        }

        // Envío normal del formulario (sin JS): volver a la página anterior,
        // pero solo si es de esta aplicación; así se evita una redirección abierta.
        return redirect()->to(UrlAnterior::segura(route('home')))->withCookie($cookie);
    }
}
