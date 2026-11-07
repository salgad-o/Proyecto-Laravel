<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuditoriaRequest;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class AuditoriaController extends Controller
{
    public function index(AuditoriaRequest $request)
    {
        Gate::authorize('viewAny', AuditLog::class);

        $filtros = array_filter(
            $request->safe()->only(['usuario', 'accion', 'importancia']),
            fn ($valor) => $valor !== null
        );

        $registros = AuditLog::with('user')
            ->when($filtros['usuario'] ?? null, fn ($consulta, $id) => $consulta->where('user_id', $id))
            ->when($filtros['accion'] ?? null, fn ($consulta, $accion) => $consulta->where('action', $accion))
            ->when($filtros['importancia'] ?? null, function ($consulta, $nivel) {
                // "baja" también incluye las acciones sin clasificar, igual que
                // AuditLog::importancia(): se filtra por exclusión de las otras.
                if ($nivel === 'baja') {
                    return $consulta->whereNotIn('action', [
                        ...AuditLog::accionesDeImportancia('alta'),
                        ...AuditLog::accionesDeImportancia('media'),
                    ]);
                }

                return $consulta->whereIn('action', AuditLog::accionesDeImportancia($nivel));
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        if ($registros->currentPage() > $registros->lastPage()) {
            abort(404);
        }

        // Solo los usuarios que tienen registros, para el filtro.
        $usuarios = User::whereIn('id', AuditLog::query()->whereNotNull('user_id')->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('auditoria.index', [
            'registros' => $registros,
            'usuarios' => $usuarios,
            'acciones' => AuditLog::ACCIONES,
            'niveles' => AuditLog::NIVELES,
            'filtros' => $filtros,
            // Se cargan una sola vez para no consultar la tabla por cada fila.
            'roles' => Role::pluck('name', 'id'),
        ]);
    }
}
