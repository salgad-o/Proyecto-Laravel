<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBiografiaRequest;
use App\Http\Requests\UpdateNombreRequest;
use Illuminate\Http\Request;

/**
 * NP-36: "Mi perfil". Cada usuario ve y edita SOLO su propia cuenta
 * ($request->user()): no hay id en la URL que se pueda cambiar.
 *
 * Un formulario y una ruta por campo. El correo no se edita aquí: con él se
 * inicia sesión y se recupera la contraseña, así que el cambio se pide por Contacto.
 */
class PerfilController extends Controller
{
    public function edit(Request $request)
    {
        // Una sola consulta extra (el rol, con label y description).
        $usuario = $request->user()->load('role');

        return view('perfil.edit', [
            'usuario' => $usuario,
            'editaBio' => $usuario->hasRole('admin', 'editor'),
            'versionLegal' => $usuario->legal_version,
            'aceptoTerminosEl' => $usuario->terms_accepted_at,
        ]);
    }

    public function updateNombre(UpdateNombreRequest $request)
    {
        $usuario = $request->user();

        // Asignación explícita de un solo campo. Un solo save(): Auditable registra
        // 'editar' con antes/después, y si nada cambió no escribe nada.
        $usuario->name = $request->validated('name');
        $usuario->save();

        return redirect()
            ->route('perfil.edit')
            ->with('status', 'Nombre actualizado.');
    }

    public function updateBiografia(UpdateBiografiaRequest $request)
    {
        // authorize() del FormRequest ya respondió 403 a un lector.
        $usuario = $request->user();

        $usuario->bio = $request->validated('bio');
        $usuario->save();

        return redirect()
            ->route('perfil.edit')
            ->with('status', 'Biografía actualizada.');
    }
}
