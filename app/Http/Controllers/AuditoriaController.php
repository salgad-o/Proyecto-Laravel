<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AuditoriaController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', AuditLog::class);

        $filtros = $request->validate([
            'usuario' => ['nullable', 'integer', 'exists:users,id'],
            'accion' => ['nullable', Rule::in(array_keys(AuditLog::ACCIONES))],
        ]);

        $registros = AuditLog::with('user')
            ->when($filtros['usuario'] ?? null, fn ($consulta, $id) => $consulta->where('user_id', $id))
            ->when($filtros['accion'] ?? null, fn ($consulta, $accion) => $consulta->where('action', $accion))
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // Solo los usuarios que tienen registros, para el filtro.
        $usuarios = User::whereIn('id', AuditLog::query()->whereNotNull('user_id')->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('auditoria.index', [
            'registros' => $registros,
            'usuarios' => $usuarios,
            'acciones' => AuditLog::ACCIONES,
            'filtros' => $filtros,
            // Se cargan una sola vez para no consultar la tabla por cada fila.
            'roles' => Role::pluck('name', 'id'),
        ]);
    }
}
