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

        // Alcance del panel: lo decide NoticiaPolicy::viewAllInPanel (admin: todas;
        // editor: solo las suyas). La misma habilidad nombra la página y el menú.
        $verTodas = $usuario->can('viewAllInPanel', Noticia::class);

        // Consulta base: alcance del usuario + búsqueda. La comparten la lista y los
        // contadores, así un editor nunca ve cuántas noticias tienen otros.
        $base = Noticia::query()
            ->when(! $verTodas, fn ($query) => $query->where('user_id', $usuario->id))
            ->buscar($q);

        // Contadores de las pestañas en UNA consulta (GROUP BY status). Respetan la
        // búsqueda pero NO el filtro de estado: así "Todas" no cambia al elegir una
        // pestaña y cada número es lo que mostrará esa pestaña con la búsqueda actual.
        $porEstado = (clone $base)
            ->toBase()
            ->select('status')
            ->selectRaw('count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $contadores = [
            'todas' => (int) $porEstado->sum(),
            'publicada' => (int) ($porEstado['publicada'] ?? 0),
            'borrador' => (int) ($porEstado['borrador'] ?? 0),
        ];

        $noticias = $base
            ->when($estado !== null, fn ($query) => $query->where('status', $estado))
            ->with('user')
            ->withCount('comentarios')
            ->latest('updated_at')
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        if ($noticias->currentPage() > $noticias->lastPage()) {
            abort(404);
        }

        return view('dashboard.noticias.index', compact('noticias', 'q', 'estado', 'verTodas', 'contadores'));
    }
}
