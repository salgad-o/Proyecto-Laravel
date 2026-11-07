<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Comentario;
use App\Models\Noticia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Portada del panel (NP-20 paso 7): "Lo que requiere tu atención".
 *
 * Cada bloque restringido se decide AQUÍ con su Policy: si no está permitido, la
 * variable es null y su consulta ni siquiera se ejecuta. La vista solo pinta un
 * bloque cuando su variable no es null (nunca se cargan datos para ocultarlos).
 * Sin contadores ni métricas: eso es NP-21.
 */
class DashboardController extends Controller
{
    private const LIMITE = 5;

    public function index(Request $request)
    {
        Gate::authorize('viewPanel', Noticia::class);

        $usuario = $request->user();

        // "Tus borradores": SIEMPRE solo los del usuario autenticado, también para
        // el admin (que en Redacción ve todos). Columnas mínimas: user_id hace falta
        // para la Policy update del enlace "Editar".
        $borradores = Noticia::query()
            ->where('user_id', $usuario->id)
            ->where('status', 'borrador')
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::LIMITE)
            ->get(['id', 'user_id', 'title', 'updated_at']);

        // "Tus publicaciones recientes" (ajuste 7b): igual que los borradores, SOLO
        // las del usuario autenticado, con el scope público de "publicada".
        $publicaciones = Noticia::query()
            ->where('user_id', $usuario->id)
            ->publicadas()
            ->latest('published_at')
            ->latest('id')
            ->limit(self::LIMITE)
            ->get(['id', 'user_id', 'title', 'published_at']);

        // "Comentarios recientes": misma habilidad que la pantalla de moderación.
        $comentarios = Gate::allows('viewAny', Comentario::class)
            ? Comentario::query()
                ->with(['user:id,name,avatar_path', 'noticia:id,title'])
                ->latest()
                ->latest('id')
                ->limit(self::LIMITE)
                ->get(['id', 'user_id', 'noticia_id', 'body', 'created_at'])
            : null;

        // "Actividad importante": misma habilidad que /dashboard/auditoria y solo
        // acciones de importancia Alta. Sin ip_address ni user_agent (minimización:
        // ni se consultan).
        $actividad = Gate::allows('viewAny', AuditLog::class)
            ? AuditLog::query()
                ->with('user:id,name')
                ->whereIn('action', AuditLog::accionesDeImportancia('alta'))
                ->latest('created_at')
                ->latest('id')
                ->limit(self::LIMITE)
                ->get(['id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'created_at'])
            : null;

        return view('pages.dashboard', [
            'usuario' => $usuario,
            'borradores' => $borradores,
            'publicaciones' => $publicaciones,
            'comentarios' => $comentarios,
            'actividad' => $actividad,
        ]);
    }
}
