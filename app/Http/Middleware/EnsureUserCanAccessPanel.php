<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserCanAccessPanel
{
    /**
     * El panel de gestión es para administradores y editores. Un lector no
     * recibe un 403 seco después del login: se le lleva a su perfil con un
     * mensaje. Las secciones internas siguen protegidas con 403 + Policy.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('admin', 'editor')) {
            return redirect()
                ->route('perfil.edit')
                ->with('status', 'El panel de gestión es para administradores y editores. Te llevamos a tu perfil.');
        }

        return $next($request);
    }
}
