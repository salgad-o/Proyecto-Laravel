<?php

namespace App\Http\Controllers;

use App\Http\Requests\PanelNoticiasRequest;
use App\Models\Noticia;
use Illuminate\Support\Facades\Gate;

class DashboardNoticiaController extends Controller
{
    public function index(PanelNoticiasRequest $request)
    {
        Gate::authorize('viewPanel', Noticia::class);

        $usuario = $request->user();
        $q = $request->validated('q');
        $estado = $request->validated('estado');

        $query = Noticia::query();

        // El editor solo ve las suyas. El filtro va en la consulta, antes de
        // paginar: así tampoco aparecen en el total ni en los enlaces.
        if (! $usuario->isAdmin()) {
            $query->where('user_id', $usuario->id);
        }

        if ($estado !== null) {
            $query->where('status', $estado);
        }

        if ($q !== null) {
            $query->buscar($q);
        }

        $noticias = $query
            ->with('user')
            ->withCount('comentarios')
            ->latest('updated_at')
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        if ($noticias->currentPage() > $noticias->lastPage()) {
            abort(404);
        }

        return view('dashboard.noticias.index', compact('noticias', 'q', 'estado', 'usuario'));
    }
}
