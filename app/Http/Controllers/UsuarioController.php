<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexUsuariosRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Http\Requests\UpdateUserStatusRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class UsuarioController extends Controller
{
    public function index(IndexUsuariosRequest $request)
    {
        Gate::authorize('viewAny', User::class);

        $buscar = $request->validated('buscar');

        $usuarios = User::with('role')
            ->when($request->filled('buscar'), function ($consulta) use ($buscar) {
                // "!" como carácter de escape: funciona igual en MySQL y SQLite
                // (SQLite no reconoce "\" como escape por defecto).
                $patron = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $buscar).'%';

                $consulta->where(function ($grupo) use ($patron) {
                    $grupo->whereRaw("name LIKE ? ESCAPE '!'", [$patron])
                        ->orWhereRaw("email LIKE ? ESCAPE '!'", [$patron]);
                });
            })
            ->latest()
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('usuarios.index', [
            'usuarios' => $usuarios,
            'buscar' => $buscar,
            'roles' => Role::orderBy('id')->pluck('label', 'id'),
        ]);
    }

    public function updateRol(UpdateUserRoleRequest $request, User $usuario)
    {
        Gate::authorize('updateRole', $usuario);

        // Asignación explícita: role_id está fuera de $fillable a propósito.
        $usuario->role_id = (int) $request->validated('role_id');
        $usuario->save();

        return back()->with('status', 'Rol actualizado.');
    }

    public function updateEstado(UpdateUserStatusRequest $request, User $usuario)
    {
        Gate::authorize('updateStatus', $usuario);

        // Asignación explícita: is_active está fuera de $fillable a propósito.
        $usuario->is_active = $request->boolean('is_active');
        $usuario->save();

        return back()->with('status', $usuario->is_active ? 'Usuario activado.' : 'Usuario desactivado.');
    }
}
