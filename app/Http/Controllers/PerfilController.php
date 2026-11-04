<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePerfilRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * NP-36: "Mi perfil". Cada usuario ve y edita SOLO su propia cuenta
 * ($request->user()): no hay id en la URL que se pueda cambiar.
 */
class PerfilController extends Controller
{
    public function edit(Request $request)
    {
        $usuario = $request->user()->load('role');

        return view('perfil.edit', compact('usuario'));
    }

    public function update(UpdatePerfilRequest $request)
    {
        $usuario = $request->user();
        $datos = $request->validated();
        $emailAnterior = $usuario->email;

        // Asignación explícita campo por campo (nada de fill() con todo el request):
        // role_id, is_active, password y los campos legales nunca se tocan aquí.
        $usuario->name = $datos['name'];
        $usuario->email = $datos['email'];

        // La biografía se muestra en las noticias: solo la tienen quienes publican.
        // Si un lector la envía, se ignora en silencio.
        if ($usuario->hasRole('admin', 'editor')) {
            $usuario->bio = $datos['bio'] ?? null;
        }

        // Un solo save(): Auditable registra 'editar' con antes/después, y si
        // nada cambió no se escribe en la base ni en la auditoría.
        $usuario->save();

        // Un enlace de recuperación pedido para el correo anterior ya no debe servir.
        if ($usuario->wasChanged('email')) {
            DB::table(config('auth.passwords.users.table'))
                ->where('email', $emailAnterior)
                ->delete();
        }

        return redirect()
            ->route('perfil.edit')
            ->with('status', 'Tu perfil se actualizó.');
    }
}
