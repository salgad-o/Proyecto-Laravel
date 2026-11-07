<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexUsuariosRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Http\Requests\UpdateUserStatusRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class UsuarioController extends Controller
{
    public function index(IndexUsuariosRequest $request)
    {
        Gate::authorize('viewAny', User::class);

        $buscar = $request->validated('buscar');
        $vista = $request->validated('vista') ?? 'todos';

        // Una sola consulta de roles: da las etiquetas del <select> y traduce
        // los contadores (agrupados por role_id) a los nombres de las pestañas.
        $roles = Role::orderBy('id')->get(['id', 'name', 'label']);

        // Consulta base: solo la búsqueda. La comparten la lista y los contadores.
        $base = User::query()
            ->when($request->filled('buscar'), function ($consulta) use ($buscar) {
                // "!" como carácter de escape: funciona igual en MySQL y SQLite
                // (SQLite no reconoce "\" como escape por defecto).
                $patron = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $buscar).'%';

                $consulta->where(function ($grupo) use ($patron) {
                    $grupo->whereRaw("name LIKE ? ESCAPE '!'", [$patron])
                        ->orWhereRaw("email LIKE ? ESCAPE '!'", [$patron]);
                });
            });

        $contadores = $this->contadores($base, $roles);

        $usuarios = (clone $base)
            ->with('role')
            ->when($vista === 'inactivos', fn ($consulta) => $consulta->where('is_active', false))
            ->when(in_array($vista, ['admin', 'editor', 'lector'], true), function ($consulta) use ($vista, $roles) {
                // Por role_id con el rol ya cargado. Si ese rol no existiera, la
                // pestaña queda vacía (nunca muestra a todos por error).
                $consulta->where('role_id', $roles->firstWhere('name', $vista)->id ?? 0);
            })
            ->latest()
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        if ($usuarios->currentPage() > $usuarios->lastPage()) {
            abort(404);
        }

        return view('usuarios.index', [
            'usuarios' => $usuarios,
            'buscar' => $buscar,
            'vista' => $vista,
            'contadores' => $contadores,
            'roles' => $roles->pluck('label', 'id'),
        ]);
    }

    /**
     * Contadores de las pestañas en UNA consulta: GROUP BY role_id con el total y
     * los inactivos de cada rol. Respetan la búsqueda, no la pestaña elegida.
     *
     * @param  Builder<User>  $base
     * @param  Collection<int, Role>  $roles
     * @return array<string, int>
     */
    private function contadores(Builder $base, Collection $roles): array
    {
        $filas = (clone $base)
            ->toBase()
            ->select('role_id')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when is_active = 0 then 1 else 0 end) as inactivos')
            ->groupBy('role_id')
            ->get();

        $contadores = [
            'todos' => (int) $filas->sum('total'),
            'inactivos' => (int) $filas->sum('inactivos'),
        ];

        foreach (['admin', 'editor', 'lector'] as $nombre) {
            $id = $roles->firstWhere('name', $nombre)?->id;
            $contadores[$nombre] = $id === null ? 0 : (int) ($filas->firstWhere('role_id', $id)->total ?? 0);
        }

        return $contadores;
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
