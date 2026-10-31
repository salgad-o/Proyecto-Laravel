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

        return back()->with('status', 'Tu mensaje pasó la validación. El envío por correo aún no está activo.');
    }
}
