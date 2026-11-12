<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Arranca la sesión SOLO si la petición trae la cookie de sesión, y nunca deja
 * que la respuesta fije cookies.
 *
 * Lo usa la ruta pública /portadas/{archivo}: una portada publicada se sirve sin
 * crear una sesión nueva (y con Cache-Control público), pero un borrador necesita
 * saber quién pide la imagen para aplicar NoticiaPolicy::view. Así no se crean
 * sesiones para visitantes anónimos ni se mezcla un Set-Cookie con una respuesta
 * cacheable.
 */
class StartSessionIfCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasCookie(config('session.cookie'))) {
            return $next($request);
        }

        $response = app(StartSession::class)->handle($request, $next);

        // La imagen puede llevar Cache-Control: public. Un Set-Cookie en una respuesta
        // compartida podría entregar la sesión de una persona a otra.
        $response->headers->remove('Set-Cookie');

        return $response;
    }
}
