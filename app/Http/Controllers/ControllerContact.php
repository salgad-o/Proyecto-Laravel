<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;

class ControllerContact extends Controller
{
    public function send(ContactRequest $request)
    {
        $datos = $request->validated();

        // Por ahora no enviamos correo todavía.
        // Solo trabajamos con datos ya validados.

        // Ruta con nombre en vez de back(): no depende del Referer, que el cliente
        // puede manipular para provocar una redirección abierta.
        return redirect()->route('contact')->with('status', 'Tu mensaje pasó la validación. El envío por correo aún no está activo.');
    }
}
