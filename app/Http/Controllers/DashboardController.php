<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Comentario;
use App\Models\Noticia;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Portada del panel: "Lo que requiere tu atención" (NP-20 paso 7) con cifras y
 * panel lateral de actividad (NP-21 paso 2).
 *
 * Cada cifra o bloque restringido se decide AQUÍ con su Policy: si no está
 * permitido, la variable es null y su consulta ni siquiera se ejecuta. La vista
 * solo pinta lo que no es null (nunca se cargan datos para ocultarlos).
 */
class DashboardController extends Controller
{
    private const LIMITE = 5;

    private const LIMITE_LATERAL = 8;

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

        // "Tus publicaciones recientes": igual, SOLO las del usuario autenticado.
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

        [$actividad, $actividadGlobal] = $this->actividad($usuario);

        return view('pages.dashboard', [
            'usuario' => $usuario,
            'kpis' => $this->kpis($usuario),
            'borradores' => $borradores,
            'publicaciones' => $publicaciones,
            'comentarios' => $comentarios,
            'actividad' => $actividad,
            'actividadGlobal' => $actividadGlobal,
        ]);
    }

    /**
     * Tarjetas de cifras (NP-21 paso 2). Cada una es [etiqueta, número] y algunas
     * llevan un desglose. Ninguna consulta se ejecuta si su Policy no lo permite.
     *
     * @return list<array{clave: string, etiqueta: string, valor: int, desglose?: list<array{valor: int, vista: string}>}>
     */
    private function kpis(User $usuario): array
    {
        // Alcance de las noticias: el mismo que el panel de Redacción
        // (NoticiaPolicy::viewAllInPanel). El editor NUNCA recibe totales globales.
        $verTodas = $usuario->can('viewAllInPanel', Noticia::class);

        // Publicadas y borradores en UNA consulta agrupada por estado.
        $porEstado = Noticia::query()
            ->when(! $verTodas, fn ($consulta) => $consulta->where('user_id', $usuario->id))
            ->toBase()
            ->select('status')
            ->selectRaw('count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $kpis = [
            ['clave' => 'publicadas', 'etiqueta' => $verTodas ? 'Publicadas' : 'Mis publicadas', 'valor' => (int) ($porEstado['publicada'] ?? 0)],
            ['clave' => 'borradores', 'etiqueta' => $verTodas ? 'Borradores' : 'Mis borradores', 'valor' => (int) ($porEstado['borrador'] ?? 0)],
        ];

        // Comentarios: todos para quien modera (ComentarioPolicy::viewAny); si no,
        // solo los que recibieron SUS noticias. Esa cifra no usa ComentarioPolicy::viewAny
        // (que es moderar TODOS los comentarios): es solo un número sobre sus propias
        // noticias, acotado por noticias.user_id, y la portada ya exige viewPanel.
        $kpis[] = Gate::allows('viewAny', Comentario::class)
            ? ['clave' => 'comentarios', 'etiqueta' => 'Comentarios', 'valor' => Comentario::count()]
            : [
                'clave' => 'comentarios',
                'etiqueta' => 'Comentarios en mis noticias',
                'valor' => Comentario::whereHas('noticia', fn ($consulta) => $consulta->where('user_id', $usuario->id))->count(),
            ];

        // Usuarios por rol en UNA consulta agrupada por role_id (UserPolicy::viewAny).
        if (Gate::allows('viewAny', User::class)) {
            $porRol = User::query()
                ->toBase()
                ->select('role_id')
                ->selectRaw('count(*) as total')
                ->groupBy('role_id')
                ->pluck('total', 'role_id');

            $nombres = Role::query()->pluck('name', 'id');
            $desglose = [];

            // El texto ("1 administrador", "2 editores") lo pone la vista con trans_choice.
            foreach (['admin', 'editor', 'lector'] as $nombre) {
                $id = $nombres->search($nombre);
                $desglose[] = ['valor' => $id === false ? 0 : (int) ($porRol[$id] ?? 0), 'vista' => $nombre];
            }

            $kpis[] = ['clave' => 'usuarios', 'etiqueta' => 'Usuarios', 'valor' => (int) $porRol->sum(), 'desglose' => $desglose];
        }

        // Acciones auditadas en los últimos 7 días (AuditLogPolicy::viewAny). El
        // rango lo fija el servidor: el usuario no envía fechas.
        if (Gate::allows('viewAny', AuditLog::class)) {
            $kpis[] = [
                'clave' => 'acciones',
                'etiqueta' => 'Acciones en 7 días',
                'valor' => AuditLog::where('created_at', '>=', now()->subDays(7))->count(),
            ];
        }

        return $kpis;
    }

    /**
     * Panel lateral, decidido con AuditLogPolicy:
     * - viewAny: toda la actividad reciente (todos los niveles), con su autor;
     * - viewOwn: SOLO la del propio usuario y dentro del alcance de su rol actual
     *   (AuditLog::visiblesComoPropias, ajuste 2d);
     * - ninguna: null y sin consulta.
     * Sin ip_address ni user_agent (minimización: ni se consultan).
     *
     * @return array{0: Collection<int, AuditLog>|null, 1: bool}
     */
    private function actividad(User $usuario): array
    {
        if (Gate::allows('viewAny', AuditLog::class)) {
            $consulta = AuditLog::query()->with('user:id,name');
            $global = true;
        } elseif (Gate::allows('viewOwn', AuditLog::class)) {
            // Ajuste 2d: solo lo que hizo él (user_id) y dentro del alcance de su rol
            // actual (AuditLog::ACCIONES_PROPIAS); nunca acciones sobre otras cuentas.
            $consulta = AuditLog::query()->visiblesComoPropias($usuario);
            $global = false;
        } else {
            return [null, false];
        }

        $actividad = $consulta
            ->latest('created_at')
            ->latest('id')
            ->limit(self::LIMITE_LATERAL)
            ->get(['id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'created_at']);

        return [$actividad, $global];
    }
}
