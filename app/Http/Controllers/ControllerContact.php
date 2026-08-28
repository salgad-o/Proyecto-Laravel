<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ControllerContact extends Controller
{
    public function send(Request $request)
    {
        $validated = $request->validate([
            'name'    => ['required', 'string', 'max:100'],
            'email'   => ['required', 'email', 'max:150'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        // Por ahora no enviamos correo todavía.
        // Solo trabajamos con datos ya validados.

        return back()->with('status', 'Mensaje validado correctamente.');
    }
}