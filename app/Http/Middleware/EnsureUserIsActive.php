<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Cierra la sesión de un usuario desactivado en su siguiente petición.
     * Aquí sí se da un mensaje específico porque el usuario ya había demostrado su
     * identidad al iniciar sesión; en el login sería revelar que la contraseña es
     * correcta a quien tal vez no es el dueño de la cuenta.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('status', 'Tu cuenta fue desactivada. Si crees que es un error, contacta al administrador.');
        }

        return $next($request);
    }
}
